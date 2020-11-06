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


class SixtySixRuParser implements ParserInterface
{
    /*run*/
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://66.ru";

    const FEED_SRC = "/main/";
    const LIMIT = 100;

    const EMPTY_DESCRIPTION = "empty";
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

        $listSourcePath = self::ROOT_SRC . self::FEED_SRC;

        $listSourceData = $curl->get($listSourcePath);
        if (empty($listSourceData)) {
            throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
        }
        $crawler = new Crawler($listSourceData);
        $items = $crawler->filter("div#frame div.col.t-left-column");
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
        $title = $postData->filter("a.new-news-piece__link")->text();
        $original = self::ROOT_SRC . self::normalizeUrl($postData->filter("a")->attr("href"));

        $createDate = new DateTime();
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

        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }

        $crawler = new Crawler($pageData);

        $image = $crawler->filter("div#frame div.newsContent div.news_single_content__photo img");
        if ($image->count() !== 0) {
            $post->image = self::normalizeUrl($image->attr("src"));
        }

        $description = $crawler->filter("div#frame div.newsContent div.text__annotation");
        if ($description->count() !== 0) {
            $post->description = Helper::prepareString($description->text());
        }

        $date = $crawler->filter("div#frame div.newsContentHead div.news-piece-layout__caption-date");

        if ($date->count() !== 0) {
            $dateString = $date->text();
            $dateArr = explode(",", $dateString);
            if (count($dateArr) !== 2) {
                throw new Exception("Could not parse dateTime string");
            }

            if (mb_stripos("Сегодня", trim($dateArr[0])) !== false) {
                $dateCompose = date("Y-m-d");
            } else {
                $dateParts = explode(" ", $dateArr[0]);
                if (count($dateParts) !== 3 || !isset(self::MONTHS[$dateParts[1]])) {
                    throw new Exception("Could not parse date string");
                }

                $dateCompose = $dateParts[2] . "-" . self::MONTHS[$dateParts[1]] . "-" . $dateParts[0] . " " . $dateArr[1] . " +05:00";
            }
            $createDate = new DateTime($dateCompose);
            $createDate->setTimezone(new DateTimeZone("UTC"));


            $post->createDate = $createDate;
        }

        $body = $crawler->filter("div#frame div.newsContent div.news-content");

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body->children() as $bodyNode) {
            $node = new Crawler($bodyNode);

            if (
                $node->matches("p")
                && $node->children("ins")->count() !== 0
                && !empty(trim($node->text(), "\xC2\xA0"))
            ) {
                self::addQuote($post, $node->text());
                continue;
            }

            if ($node->matches("p") && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addText($post, $node->text());
                continue;
            }

            if ($node->matches("table") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                self::addImage($post, $image->attr("src"));
                $sign = $node->filter("div.news_single_content__photo-sign");
                if ($sign->count() !== 0) {
                    self::addText($post, $sign->text());
                }
            }
            if ($node->matches("ul") && !empty(trim($node->text(), "\xC2\xA0"))) {
                $node->children("li")->each(function (Crawler $liNode) use ($post) {
                    self::addText($post, $liNode->text());
                });
                continue;
            }

            if ($node->matches("h3") && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addHeader($post, $node->text(), 3);
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

