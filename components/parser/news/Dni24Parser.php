<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use DOMNode;
use Exception;
use linslin\yii2\curl\Curl;
use Symfony\Component\DomCrawler\Crawler;


class Dni24Parser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://dni24.com";

    const FEED_SRC = "/lastnews/";
    const LIMIT = 100;
    const NEWS_PER_PAGE = 15;
    const EMPTY_DESCRIPTION = "empty";

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $curl = Helper::getCurl();
        $posts = [];

        $urlList = [];

        $counter = 0;
        for ($pageId = 1; $pageId <= ceil(self::LIMIT / self::NEWS_PER_PAGE); $pageId++) {

            $listSourcePath = self::ROOT_SRC . self::FEED_SRC . "page/" . $pageId . "/";

            $listSourceData = $curl->get($listSourcePath);
            if (empty($listSourceData)) {
                throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
            }
            $crawler = new Crawler($listSourceData);
            $items = $crawler->filter("div#dle-content div.short1");
            if ($items->count() === 0) {
                throw new Exception("Пустой список новостей в ленте: " . $listSourcePath);
            }
            $urlList[] = $items->filter("a")->attr("href");


            $items = $crawler->filter("div#dle-content div.short2 p > a");

            $items->each(function (Crawler $node) use (&$urlList) {

                $urlList[] = $node->attr("href");
            });

            $items = $crawler->filter("div#dle-content div.short3 div.img.mobbx > a");

            $items->each(function (Crawler $node) use (&$urlList) {

                $urlList[] = $node->attr("href");
            });

            $counter++;
            if ($counter >= self::LIMIT) {
                break;
            }
        }

        if (count($urlList) === 0) {
            throw new Exception("Не получилось собрать список новостей ");
        }
        foreach ($urlList as $url) {
            try {
                $posts[] = self::inflatePostContent($url, $curl);
            } catch (Exception $e) {
                error_log($e->getMessage());
                continue;
            }
        }
        return $posts;
    }

    /**
     * @param string $url
     * @param Curl   $curl
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePostContent(string $url, Curl $curl)
    {
        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }

        $crawler = new Crawler($pageData);

        $title = $crawler->filter("article h1");
        if ($title->count() === 0) {
            throw new Exception("Could not find post header");
        }

        $date = $crawler->filter("article meta[itemprop=\"datePublished\"]");
        if ($date->count() === 0) {
            throw new Exception("Could not find post publish date");
        }

        $createDate = new DateTime($date->attr("content"));
        $createDate->setTimezone(new DateTimeZone("UTC"));

        $post = new NewsPost(
            self::class,
            $title->text(),
            self::EMPTY_DESCRIPTION,
            $createDate->format("Y-m-d H:i:s"),
            $url,
            null
        );


        $body = $crawler->filter("article div[class='{morpher_fake_class}']")->eq(1);

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body->getNode(0)->childNodes as $bodyNode) {
            $node = new Crawler($bodyNode);
            if (
                $node->matches("b")
                && $post->description === self::EMPTY_DESCRIPTION
                && !empty(trim($node->text(), "\xC2\xA0"))
            ) {
                $post->description = Helper::prepareString($bodyNode->textContent);
                continue;
            }


            if ($bodyNode->nodeName === "#text" && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addText($post, Helper::prepareString($bodyNode->textContent));
                continue;
            }


            if ($node->matches("div") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                $src = self::ROOT_SRC . $image->attr("src");
                if ($post->image === null) {
                    $post->image = self::normalizeUrl($src);
                } else {
                    self::addImage($post, $src);
                }
            }

            if ($node->matches("div.imgavtordiv")) {
                /** @var DOMNode $childNode */
                foreach ($node->children("div.imgavtor")->getNode(0)->childNodes as $childNode) {
                    if ($childNode->nodeName === "#text") {
                        self::addText($post, Helper::prepareString($childNode->textContent));
                    }
                }
                continue;
            }

            if ($node->matches("div.quote")) {
                self::addQuote($post, $node->text());
                continue;
            }
        }

        $body = $crawler->filter("article > div.row > div.inform > div.vto1");

        if ($body->count() !== 0) {

            /** @var DOMNode $bodyNode */
            foreach ($body->getNode(0)->childNodes as $bodyNode) {
                if ($bodyNode->nodeName === "#text" && !empty(trim($bodyNode->textContent))) {
                    self::addText($post, Helper::prepareString($bodyNode->textContent));
                    continue;
                }
            }
        }

        return $post;
    }

    /**
     * @param NewsPost $post
     * @param string   $content
     * @param int      $level
     */
    protected static function addHeader(NewsPost $post, string $content, int $level): void
    {
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_HEADER,
                $content,
                null,
                null,
                $level,
                null
            ));
    }

    /**
     * @param NewsPost $post
     * @param string   $content
     */
    protected static function addImage(NewsPost $post, string $content): void
    {
        $content = self::normalizeUrl($content);
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_IMAGE,
                null,
                $content,
                null,
                null,
                null
            ));
    }

    /**
     * @param NewsPost $post
     * @param string   $content
     */
    protected static function addText(NewsPost $post, string $content): void
    {
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_TEXT,
                $content,
                null,
                null,
                null,
                null
            ));
    }

    /**
     * @param NewsPost $post
     * @param string   $content
     */
    protected static function addQuote(NewsPost $post, string $content): void
    {
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_QUOTE,
                $content,
                null,
                null,
                null,
                null
            ));
    }

    /**
     * @param string $content
     *
     * @return string
     */
    protected static function normalizeUrl(string $content)
    {
        return preg_replace_callback('/[^\x21-\x7f]/', function ($match) {
            return rawurlencode($match[0]);
        }, $content);
    }
}

