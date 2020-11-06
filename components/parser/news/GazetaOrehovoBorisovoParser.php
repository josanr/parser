<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use DOMDocument;
use DOMNode;
use Exception;
use InvalidArgumentException;
use linslin\yii2\curl\Curl;
use Symfony\Component\DomCrawler\Crawler;


class GazetaOrehovoBorisovoParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "http://gazeta-orehovo-borisovo-juzhnoe.ru";

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
        $document = new DOMDocument();
        $document->loadXML($listSourceData);


        $items = $document->getElementsByTagName("item");
        if ($items->count() === 0) {
            throw new Exception("Пустой список новостей в ленте: " . $listSourcePath);
        }
        $counter = 0;
        foreach ($items as $item) {
            try {

                $newsPost = self::inflatePost($item);
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
     * @param DOMNode $entityData
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(DOMNode $entityData): NewsPost
    {
        $title = null;
        $original = null;
        $dateStr = null;
        $description = self::EMPTY_DESCRIPTION;
        /** @var DOMNode $node */
        foreach ($entityData->childNodes as $node) {

            switch ($node->nodeName) {
                case "title":
                    $title = $node->nodeValue;
                    break;
                case "link":
                    $original = $node->nodeValue;
                    break;
                case "pubDate":
                    $dateStr = $node->nodeValue;
                    break;
            }
        }

        if (
            is_null($title)
            || is_null($original)
            || is_null($dateStr)

        ) {
            throw new InvalidArgumentException("Post data not found in feed");
        }

        $createDate = new DateTime($dateStr);
        $createDate->setTimezone(new DateTimeZone("UTC"));
        $imageUrl = null;
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
     * @param Curl     $curl
     *
     * @throws Exception
     */
    private static function inflatePostContent(NewsPost $post, Curl $curl)
    {
        $url = $post->original;

        $post->description = "";

        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }
        $crawler = new Crawler($pageData);

        $content = $crawler->filter("article");

        $body = $content->filter("div.entry-content");
        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body->children() as $bodyNode) {

            $node = new Crawler($bodyNode);

            if ($node->matches("p") && !empty(trim($node->text(), "\xC2\xA0"))) {
                if (mb_strpos($node->text(), "Метки:") === 0) {
                    continue;
                }
                if (empty($post->description)) {
                    $post->description = Helper::prepareString($node->text());
                } else {
                    self::addText($post, $node->text());
                }
                continue;
            }

            if ($node->matches("figure") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                if ($post->image === null) {
                    $post->image = self::normalizeUrl(self::ROOT_SRC . $image->attr("src"));
                } else {
                    self::addImage($post, self::ROOT_SRC . $image->attr("src"));
                }
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
}

