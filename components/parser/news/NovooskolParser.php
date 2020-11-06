<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use Exception;
use InvalidArgumentException;
use linslin\yii2\curl\Curl;
use Symfony\Component\DomCrawler\Crawler;


class NovooskolParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://no-vpered.ru/";

    const FEED_SRC = "/edw/api/data-marts/35/entities.json";
    const LIMIT = 100;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $curl = Helper::getCurl();
        $posts = [];

        $listSourcePath = $listSourcePath = self::ROOT_SRC . self::FEED_SRC . "?limit=" . self::LIMIT;
        $listSourceData = $curl->get("$listSourcePath", false);
        if (empty($listSourceData)) {
            throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
        }
        if (!isset($listSourceData["results"]) && !isset($listSourceData["results"]["objects"])) {
            throw new Exception("Пустой список новостей в ленте: " . $listSourcePath);
        }

        $content = $listSourceData["results"]["objects"];

        foreach ($content as $item) {
            try {
                $entityUrl = $item["entity_url"];
                $entityData = $curl->get($entityUrl, false);

                $post = self::inflatePost($entityData);
                $posts[] = $post;

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
        $description = $entityData["lead"];
        $original = $entityData["detail_url"];
        $galleryItem = array_shift($entityData["gallery"]);
        $imageUrl = null;
        if ($galleryItem !== null) {
            $imageUrl = $galleryItem["image"];
        }


        return new NewsPost(
            self::class,
            $title,
            $description,
            $createDate->format("Y-m-d H:i:s"),
            self::ROOT_SRC . $original,
            self::normalizeUrl($imageUrl)
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

        $content = $crawler->filter("div.content");


        $headerRow = $content->filter("div.container")->eq(1);


        $imageCarousel = $headerRow->filter("div.publication-carousel.photoreport-carousel img");
        if ($imageCarousel->count() !== 0) {
            $imageCarousel->each(function (Crawler $image) use ($post) {
                self::addImage($post, self::ROOT_SRC . $image->attr("src"));
            });

        }

        $body = $content->filter("div.theme-default > p, div.theme-default > blockquote, div.theme-default > div");
        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }
        foreach ($body as $bodyNode) {
            $node = new Crawler($bodyNode);
            if (!empty(trim($node->text(), "\xC2\xA0"))) {

                if ($node->matches("blockquote")) {
                    self::addQuote($post, $node->text());
                    continue;
                }
                if ($node->matches("p")) {
                    self::addText($post, $node->text());
                    continue;
                }
            }

            if ($node->matches("p")) {
                $videoContainer = $node->filter("iframe");
                if ($videoContainer->count() !== 0) {
                    self::addVideo($post, $videoContainer->attr("src"));
                }
            }


            if ($node->matches("div.publication-image")) {
                $image = $node->filter("img");
                if ($image->count() !== 0) {
                    self::addImage($post, self::ROOT_SRC . $image->attr("src"));
                }

                $title = $node->filter("small");
                if ($title->count() !== 0) {
                    self::addText($post, $title->text());
                }

                continue;
            }
            if ($node->matches("div.publication-carousel")) {
                $imageCarousel = $node->filter("img");
                if ($imageCarousel->count() !== 0) {
                    $imageCarousel->each(function (Crawler $image) use ($post) {
                        self::addImage($post, self::ROOT_SRC . $image->attr("src"));
                    });

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

