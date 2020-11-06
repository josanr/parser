<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use linslin\yii2\curl\Curl;
use Symfony\Component\DomCrawler\Crawler;


class MediusParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "http://mediusinfo.ru";

    const FEED_SRC = "";
    const LIMIT = 1;
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
        $items = $crawler->filter("div.t3-module.module div.brick-article");
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

        $original = self::ROOT_SRC . self::normalizeUrl($postData->filter("div.inner > a")->attr("href"));

        $title = $postData->filter("div.inner div.article-content h4")->text();

        $createDate = new DateTime();
        $createDate->setTimezone(new DateTimeZone("UTC"));


        $imageUrl = null;
        $imageContainer = $postData->filter("div.inner > a")->attr("style");

        $imageArr = explode("(", $imageContainer);
        if (!isset($imageArr[1])) {
            throw new Exception("Could not parse imgage string");
        }
        $imageUrl = self::ROOT_SRC . self::normalizeUrl(explode(")", $imageArr[1])[0]);

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

        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }


        $crawler = new Crawler($pageData);

        $content = $crawler->filter("article");
        if ($content->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        $image = $content->filter("div.article-image.article-image-full img");
        if ($image->count() !== 0) {
            self::addImage($post, self::ROOT_SRC . $image->attr("src"));
        }

        $dateStr = $content->filter("aside dl dd time")->attr("datetime");

        $createDate = new DateTime($dateStr);
        $createDate->setTimezone(new DateTimeZone("UTC"));
        $post->createDate = $createDate;

        $body = $content->filter("section.article-content");

        foreach ($body->children("p") as $bodyNode) {
            $node = new Crawler($bodyNode);

            if ($node->matches("p") && $node->filter("cite")->count() !== 0 && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addQuote($post, $node->text());
                continue;
            }

            if ($node->matches("p") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                self::addImage($post, self::ROOT_SRC . $image->attr("src"));
                continue;
            }

            if (!empty(trim($node->text(), "\xC2\xA0"))) {
                if ($post->description === self::EMPTY_DESCRIPTION) {
                    $post->description = Helper::prepareString($node->text());
                } else {
                    self::addText($post, $node->text());
                }
                continue;
            }
        }

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

