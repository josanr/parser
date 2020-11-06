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
use InvalidArgumentException;
use linslin\yii2\curl\Curl;
use Symfony\Component\DomCrawler\Crawler;


class VestnikParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "http://vectnik.ru";

    const FEED_SRC = "/news/";
    const LIMIT = 100;
    const EMPTY_DESCRIPTION = "empty";
    const NEWS_PER_PAGE = 10;

    const MONTHS = [
        "января" => "01",
        "февраля" => "02",
        "марта" => "03",
        "апреля" => "04",
        "мая" => "05",
        "июня" => "06",
        "июля" => "07",
        "августа" => "08",
        "сентября" => "09",
        "октября" => "10",
        "ноября" => "11",
        "декабря" => "12",
    ];

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $curl = Helper::getCurl();
        $posts = [];

        $counter = 0;
        for ($pageId = 1; $pageId <= ceil(self::LIMIT / self::NEWS_PER_PAGE); $pageId++) {

            $listSourcePath = self::ROOT_SRC . self::FEED_SRC . "page/" . $pageId . "/";

            $listSourceData = $curl->get($listSourcePath);
            if (empty($listSourceData)) {
                throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
            }
            $crawler = new Crawler($listSourceData);
            $items = $crawler->filter("div.remainder-news div.main-new");

            if ($items->count() === 0) {
                throw new Exception("Пустой список новостей в ленте: " . $listSourcePath);
            }
            foreach ($items as $newsItem) {
                try {
                    $node = new Crawler($newsItem);
                    $newsPost = self::inflatePost($node);
                    $posts[] = $newsPost;
                    $counter++;
                    if ($counter >= self::LIMIT) {
                        break 2;
                    }
                } catch (Exception $e) {
                    error_log($e->getMessage());
                    continue;
                }
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
        $title = $postData->filter("h1 a")->text();

        $original = $postData->filter("h1 a")->attr("href");

        $dateString = $postData->filter("div.new-info span.date")->text();
        $dateArr = explode(" ", $dateString);
        if (count($dateArr) !== 4) {
            throw new Exception("Date format error");
        }
        if (!isset($dateArr[1]) || !isset(self::MONTHS[$dateArr[1]])) {
            throw new Exception("Could not parse date string");
        }

        $dateString = $dateArr[0] . "-" . self::MONTHS[$dateArr[1]] . "-" . $dateArr[2] . " " . $dateArr[3] . " +04:00";
        $createDate = new DateTime($dateString);
        $createDate->setTimezone(new DateTimeZone("UTC"));

        $imageUrl = null;


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
        $post->description = "";
        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }

        $crawler = new Crawler($pageData);

        $body = $crawler->filter("div.main-new");

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body->children() as $bodyNode) {
            $node = new Crawler($bodyNode);

            if ($node->matches("p") && !empty(trim($node->text(), "\xC2\xA0"))) {
                if (empty($post->description)) {
                    $post->description = Helper::prepareString($node->text());
                } else {
                    self::addText($post, $node->text());
                }
                continue;
            }
            if ($node->matches("ul")) {
                $node->children("li")->each(function (Crawler $liNode) use ($post) {
                    self::addText($post, $liNode->text());
                });
                continue;
            }
            if ($node->matches("figure") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                $src = self::normalizeUrl($image->attr("src"));
                self::addImage($post, $src);
            }
            if ($node->matches("div.picture")) {
                $imageContainer = $node->attr("style");

                $imageArr = explode("(", $imageContainer);
                if (!isset($imageArr[1])) {
                    throw new InvalidArgumentException("Could not parse imgage string");
                }
                $imageUrl = self::normalizeUrl(explode(")", $imageArr[1])[0]);
                if ($post->image === null) {
                    $post->image = $imageUrl;
                } else {
                    self::addImage($post, $imageUrl);
                }

            }

            if ($node->matches("blockquote")) {
                self::addQuote($post, $node->text());
                continue;
            }
        }

        if ($post->description === "") {
            throw new Exception("В новости нет ни одного текстового блока");
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

