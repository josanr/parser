<?php declare(strict_types=1);


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
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

        $path = self::ROOT_SRC . self::API_PATH . self::ALL_NEWS_ID . self::ENDPOINT_NAME . "limit=" . self::LIMIT;
        $payloadData = $curl->get($path, false);

        if (!isset($payloadData["results"]) || !isset($payloadData["results"]["objects"])) {
            error_log(self::class . "| payload does not contain the necessary data");
            throw new Exception(self::class . " - No data received");
        }

        $newsList = $payloadData["results"]["objects"];


        $posts = [];
        foreach ($newsList as $newsItem) {
            $entityUrl = $newsItem["entity_url"];
            try {
                $entityData = $curl->get($entityUrl, false);
                if (is_null($entityData)) {
                    throw new InvalidArgumentException("No data received");
                }
                $post = self::inflatePost($entityData);


                $newsUrl = $newsItem["extra"]["url"];

                $newsData = $curl->get(self::ROOT_SRC . $newsUrl);

                $crawler = new Crawler($newsData);


                $header = $crawler->filter("h1");
                if ($header->count() && !empty($header->text())) {
                    self::addHeader($post, $header->text(), 1);
                }


                $image = $crawler->filter("div.topic_image img");
                if ($image->count() and !empty($image->attr("src"))) {
                    self::addImage($post, $image->attr("src"));
                }


                $imageText = $crawler->filter("div.topic_image span.title");
                if ($imageText->count() && !empty($imageText->text())) {
                    self::addText($post, $imageText->text());
                }

                $imageAuth = $crawler->filter("div.topic_image span.author");
                if ($imageAuth->count() && !empty($imageAuth->text())) {
                    self::addText($post, $imageAuth->text());
                }

                $lead = $crawler->filter("p.lead");
                if ($lead->count() && !empty($lead->text())) {
                    self::addHeader($post, $lead->text(), 4);
                }

                $content = $crawler->filter("div.theme-default");

                $content->each(function (Crawler $item, $idx) use ($post) {
                    $item->children()->each(function (Crawler $node, $i) use ($post) {
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
                                self::addImage($post, $innerImage->attr("src"));
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
        $createDate = new DateTime($entityData["created_at"]);
        $createDate->setTimezone(new \DateTimeZone("UTC"));
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
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_IMAGE,
                null,
                self::ROOT_SRC . $content,
                null,
                1,
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


}