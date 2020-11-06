<?php


namespace app\components\helper;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use DateTime;
use DateTimeZone;
use DOMNode;
use Exception;
use InvalidArgumentException;
use linslin\yii2\curl\Curl;
use Symfony\Component\DomCrawler\Crawler;


class SevpoiskParser
{

    const ROOT_SRC = "https://sevpoisk.ru";

    const LIMIT = 100;
    const EMPTY_DESCRIPTION = "empty";

    /**
     * @param string $path
     * @param string $parentClass
     *
     * @return array
     * @throws Exception
     */
    public static function parse(string $path, string $parentClass): array
    {
        $curl = Helper::getCurl();
        $posts = [];

        $counter = 0;

        $listSourcePath = self::ROOT_SRC . $path . "/news/";

        $listSourceData = $curl->get($listSourcePath);
        if (empty($listSourceData)) {
            throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
        }

        $crawler = new Crawler($listSourceData);
        $items = $crawler->filter("div.r24_article");
        if ($items->count() === 0) {
            throw new Exception("Пустой список новостей в ленте: " . $listSourcePath);
        }
        foreach ($items as $newsItem) {
            try {
                $node = new Crawler($newsItem);
                $newsPost = self::inflatePost($node, $parentClass);
                $posts[] = $newsPost;
                $counter++;
                if ($counter >= self::LIMIT) {
                    break;
                }
            } catch (Exception $e) {
                error_log($e->getMessage());
                continue;
            }
        }

        foreach ($posts as $key => $post) {
            try {
                self::inflatePostContent($post, $curl);
            } catch (Exception $e) {
                error_log($e->getMessage());
                unset($posts[$key]);
                continue;
            }
        }
        return $posts;
    }

    /**
     * Собираем исходные данные из ответа API
     *
     * @param Crawler $postData
     * @param string  $parentClass
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(Crawler $postData, string $parentClass): NewsPost
    {
        $title = $postData->filter("div.r24_body a h3")->text();

        $original = self::normalizeUrl($postData->filter("div.r24_body a")->attr("href"));


        $createDate = new DateTime($postData->filter("div.r24_info time")->attr("datetime"));
        $createDate->setTimezone(new DateTimeZone("UTC"));

        $imageUrl = null;

        $description = self::EMPTY_DESCRIPTION;

        return new NewsPost(
            $parentClass,
            $title,
            $description,
            $createDate->format("Y-m-d H:i:s"),
            $original,
            $imageUrl
        );

    }

    /**
     * @param NewsPost $post
     * @param          $curl
     *
     * @throws Exception
     */
    private static function inflatePostContent(NewsPost $post, Curl $curl)
    {
        $url = $post->original;
        if ($post->description === self::EMPTY_DESCRIPTION) {
            $post->description = "";
        }
        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }

        $crawler = new Crawler($pageData);

        $picHolder = $crawler->filter("article div.r24_body > img");
        if ($picHolder->count() !== 0) {
            $src = trim($picHolder->attr("src"));

            $post->image = self::normalizeUrl($src);
        }

        $body = $crawler->filter("article div.r24_body div.r24_text");

        if ($body->children("div.ui-rss-text")->count() !== 0) {
            $picHolder = $crawler->filter("article div.r24_body div.ui-rss-img-first img");
            if ($picHolder->count() !== 0) {
                $src = trim($picHolder->attr("src"));
                $post->image = self::normalizeUrl($src);
            }


            $body = $crawler->filter("article div.r24_body div.r24_text div.ui-rss-text");
        }

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body->getNode(0)->childNodes as $bodyNode) {
            $node = new Crawler($bodyNode);

            if ($node->matches("p, div") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                $src = self::normalizeUrl($image->attr("src"));
                if ($post->image === null) {
                    $post->image = $src;
                } else {
                    self::addImage($post, $src);
                }
            }

            if ($node->matches("div") && $node->filter("iframe")->count() !== 0) {
                $videoContainer = $node->filter("iframe");
                if ($videoContainer->count() !== 0) {
                    self::addVideo($post, $videoContainer->attr("src"));
                }
            }


            if ($bodyNode->nodeName === "#text" && !empty(trim($bodyNode->nodeValue, " \r\n\xC2\xA0\x0D\x0A\x09"))) {
                if (empty($post->description)) {
                    $post->description = Helper::prepareString(trim($bodyNode->nodeValue));
                } else {
                    self::addText($post, trim($bodyNode->nodeValue));
                }
                continue;
            }

            if ($node->matches("p,div") && !empty(trim($node->text(), "\xC2\xA0"))) {
                if (empty($post->description)) {
                    $post->description = Helper::prepareString($node->text());
                } else {
                    self::addText($post, $node->text());
                }
                continue;
            }


            if ($node->matches("ul, ol") && !empty(trim($node->text(), "\xC2\xA0"))) {
                $node->children("li")->each(function (Crawler $liNode) use ($post) {
                    self::addText($post, $liNode->text());
                });
                continue;
            }

            if ($node->matches("blockquote") && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addQuote($post, $node->text());
                continue;
            }


        }


        if (empty($post->description)) {
            throw new Exception("No text parsed: " . $url);
        }
    }


    /**
     * @param NewsPost $post
     * @param string   $content
     */
    private static function addImage(NewsPost $post, string $content): void
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
    private static function addText(NewsPost $post, string $content): void
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
     * @param string $content
     *
     * @return string
     */
    protected static function normalizeUrl(string $content)
    {
        $src = trim($content);
        if(mb_stripos($src, "//") === 0){
            $src = "http:" . $src;
        }elseif($src[0] === "/"){
            $src = self::ROOT_SRC . $src;
        }
        return preg_replace_callback('/[^\x21-\x7f]/', function ($match) {
            return rawurlencode($match[0]);
        }, $src);
    }

    /**
     * @param NewsPost $post
     * @param string   $content
     */
    private static function addQuote(NewsPost $post, string $content): void
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

    private static function addVideo(NewsPost $post, string $url)
    {

        $host = parse_url($url, PHP_URL_HOST);
        if (mb_stripos($host, "youtu") === false) {
            return;
        }

        $parsedUrl = explode("/", parse_url($url, PHP_URL_PATH));


        if (!isset($parsedUrl[2])) {
            throw new InvalidArgumentException("Could not parse Youtube ID");
        }

        $id = $parsedUrl[2];
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_VIDEO,
                null,
                null,
                null,
                null,
                $id
            ));
    }

}

