<?php declare(strict_types=1);


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;


class RodKray31Parser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://rodkray31.ru";
    const API_PATH = "/edw/api/data-marts/";
    const ALL_NEWS_ID = 33;
    const ENDPOINT_NAME = "/entities.json?";

    const LIMIT = 100;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {

        $curl = Helper::getCurl();

        $listSourcePath = self::ROOT_SRC . self::API_PATH . self::ALL_NEWS_ID . self::ENDPOINT_NAME . "limit=" . self::LIMIT;
        $payloadData = $curl->get($listSourcePath, false);

        if (!isset($payloadData["results"]) || !isset($payloadData["results"]["objects"])) {
            throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
        }

        $newsList = $payloadData["results"]["objects"];


        $posts = [];
        foreach ($newsList as $newsItem) {
            $entityUrl = $newsItem["entity_url"];
            try {
                $entityData = $curl->get($entityUrl, false);
                if (empty($entityData)) {
                    throw new Exception("No data received");
                }
                $post = self::inflatePost($entityData);


                $newsUrl = $newsItem["extra"]["url"];

                $newsData = $curl->get(self::ROOT_SRC . $newsUrl);
                if (empty($newsData)) {
                    throw new Exception("Получен пустой ответ от страницы новости: " . $newsUrl);
                }
                $crawler = new Crawler($newsData);


                $image = $crawler->filter("div.topic_image img");
                if ($image->count() and !empty($image->attr("src"))) {
                    $src = self::ROOT_SRC . self::normalizeUrl($image->attr("src"));
                    if ($post->image === null) {
                        $post->image = $src;
                    } else {
                        if(mb_stripos($src, $post->image) === false) {
                            self::addImage($post, self::ROOT_SRC . $image->attr("src"));
                        }
                    }
                }


                $imageText = $crawler->filter("div.topic_image span.title");
                if ($imageText->count() && !empty($imageText->text())) {
                    self::addText($post, $imageText->text());
                }

                $imageAuth = $crawler->filter("div.topic_image span.author");
                if ($imageAuth->count() && !empty($imageAuth->text())) {
                    self::addText($post, $imageAuth->text());
                }

                $content = $crawler->filter("div.theme-default");

                $content->each(function (Crawler $item) use ($post) {
                    $item->children()->each(function (Crawler $node) use ($post) {
                        if (!empty($node->text())) {
                            if ($node->nodeName() === "p") {
                                self::addText($post, $node->text());
                            }
                            if ($node->nodeName() === "blockquote") {
                                self::addQuote($post, $node->text());
                            }
                        }

                        if ($node->nodeName() === "div") {
                            $innerImage = $node->filter("img");
                            if ($innerImage->count() && !empty($innerImage->attr("src"))) {
                                self::addImage($post, self::ROOT_SRC . $innerImage->attr("src"));
                            }
                            $photoText = $node->filter("small");
                            if ($photoText->count() && !empty($photoText->text())) {
                                self::addText($post, $photoText->text());
                            }
                        }
                    });
                });

                $posts[] = $post;
            } catch (Exception $e) {
                error_log("error on:" . $entityUrl . " | " . $e->getMessage());
                continue;
            }
        }

        return $posts;
    }

    /**
     * Собираем исходные данные из ответа API
     *
     * @param array $entityData
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(array $entityData): NewsPost
    {
        $keys = ["title", "created_at", "lead", "detail_url", "gallery",];
        foreach ($keys as $key) {
            if (!isset($entityData[$key])) {
                throw new InvalidArgumentException("Entity has no key {$key} set.");
            }
        }

        $title = $entityData["title"];
        $dateStr = str_ireplace("Z", " +03:00", $entityData["created_at"]);
        $createDate = new DateTime($dateStr);
        $createDate->setTimezone(new DateTimeZone("UTC"));
        $description = $entityData["lead"];
        $original = $entityData["detail_url"];
        $galleryItem = array_shift($entityData["gallery"]);
        $imageUrl = "";
        if ($galleryItem !== null) {
            $imageUrl = $galleryItem["image"];
        }


        return new NewsPost(
            self::class,
            $title,
            $description,
            $createDate->format("Y-m-d H:i:s"),
            self::ROOT_SRC . $original,
            $imageUrl
        );
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