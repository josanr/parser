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
use Symfony\Component\DomCrawler\Crawler;


class GazetaHotParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "http://www.gazetahot.ru";

    const FEED_SRC = "/novosti/rss.xml";
    const LIMIT = 100;


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
     * @param Crawler $entityData
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(Crawler $entityData): NewsPost
    {

        $title = $entityData->filter("title")->text();
        $createDate = new DateTime($entityData->filterXPath("item/pubDate")->text());
        $createDate->setTimezone(new DateTimeZone("UTC"));
        $original = $entityData->filter("link")->text();

        $image = $entityData->filter("enclosure");
        $imageUrl = null;
        if ($image->count() !== 0) {
            $imageUrl = $image->attr("url");
        }

        $description = $entityData->filter("description")->text();
        if (empty($description)) {
            $text = $entityData->filterXPath("item/yandex:full-text");

            $sentences = preg_split('/(?<=[.?!])\s+(?=[а-я])/i', $text->text());
            $description = implode(" ", array_slice($sentences, 0, 3));
        }

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
    private static function inflatePostContent(NewsPost $post, $curl)
    {
        $url = $post->original;

        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }
        $crawler = new Crawler($pageData);

        $content = $crawler->filter(".full-news");

        $image = $content->filter("img");

        if ($image->count() !== 0) {
            $image->each(function (Crawler $imageNode) use ($post) {
                if ($imageNode->attr("src") != $post->image) {
                    self::addImage($post, $imageNode->attr("src"));
                }
            });
        }

        $body = $content->filter("div.full-news-content");

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $node */
        foreach ($body->getNode(0)->childNodes as $node) {
            if (!in_array($node->nodeName, ["#text", "b"])) {
                continue;
            }
            $text = new Crawler($node);
            if (!empty(trim($text->text(), "\xC2\xA0"))) {
                $cleanText = str_ireplace($post->description, "", $text->text());
                if (!empty($cleanText)) {
                    self::addText($post, $cleanText);
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
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_IMAGE,
                null,
                $content,
                null,
                1,
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
}

