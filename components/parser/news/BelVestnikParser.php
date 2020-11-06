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


class BelVestnikParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "http://bel-vest.ru";

    const FEED_SRC = "/feed/";
    const LIMIT = 100;
    const EMPTY_DESCRIPTION = "empty";

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $curl = Helper::getCurl();
        $posts = [];

        $listSourcePath = self::ROOT_SRC . self::FEED_SRC;

        $listSourceData = $curl->get($listSourcePath);

        if (empty($listSourceData)) {
            throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
        }

        $crawler = new Crawler($listSourceData);
        $items = $crawler->filter("item");
        if ($items->count() === 0) {
            throw new Exception("Пустой список новостей в ленте: " . $listSourcePath);
        }
        $counter = 0;
        foreach ($items as $item) {
            try {
                $node = new Crawler($item);
                $newsPost = self::inflatePost($node);
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
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(Crawler $postData): NewsPost
    {
        $title = $postData->filter("title")->text();

        $original = $postData->filter("link")->text();


        $createDate = new DateTime($postData->filterXPath("item/pubDate")->text());
        $createDate->setTimezone(new DateTimeZone("UTC"));

        $imageUrl = null;
        $image = $postData->filter("enclosure");
        if ($image->count() !== 0) {
            $imageUrl = self::normalizeUrl($image->attr("url"));
        }

        $description = self::EMPTY_DESCRIPTION;

        return new NewsPost(
            self::class,
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

        $imgHolder = $crawler->filter("div.post-content div.post-image img");
        if ($imgHolder->count() !== 0) {
            $imgHolder->each(function (Crawler $imgNode) use ($post) {
                if ($post->image === null) {
                    $post->image = self::normalizeUrl($imgNode->attr("data-src"));
                } else {
                    self::addImage($post, self::normalizeUrl($imgNode->attr("data-src")));
                }
            });
        }

        $body = $crawler->filter("div.post-content div.entry-content");

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body->children() as $bodyNode) {
            $node = new Crawler($bodyNode);

            if ($node->matches("div.pimp") && !empty(trim($node->text(), "\xC2\xA0"))) {
                $post->description = Helper::prepareString($node->text());
                continue;
            }

            if ($node->matches("p") && !empty(trim($node->text(), "\xC2\xA0"))) {
                if (empty($post->description)) {
                    $post->description = Helper::prepareString($node->text());
                } else {
                    self::addText($post, $node->text());
                }
                continue;
            }


            if ($node->matches("div") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                $src = self::normalizeUrl(self::ROOT_SRC . $image->attr("src"));
                self::addImage($post, $src);
            }

            if ($node->matches("ul") && !empty(trim($node->text(), "\xC2\xA0"))) {
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
        return preg_replace_callback('/[^\x21-\x7f]/', function ($match) {
            return rawurlencode($match[0]);
        }, $content);
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

}

