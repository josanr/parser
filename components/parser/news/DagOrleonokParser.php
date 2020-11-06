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


class DagOrleonokParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "http://dagorlenok.ru";

    const FEED_SRC = "/news/";
    const LIMIT = 100;
    const EMPTY_DESCRIPTION = "empty";
    const NEWS_PER_PAGE = 20;
    const MONTHS = [
        "Января" => "01",
        "Февраля" => "02",
        "Марта" => "03",
        "Апреля" => "04",
        "Мая" => "05",
        "Июня" => "06",
        "Июля" => "07",
        "Августа" => "08",
        "Сентября" => "09",
        "Октября" => "10",
        "Ноября" => "11",
        "Декабря" => "12",
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

            $listSourcePath = self::ROOT_SRC . self::FEED_SRC . "?PAGEN_1=" . $pageId;

            $listSourceData = $curl->get($listSourcePath);
            if (empty($listSourceData)) {
                throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
            }
            $crawler = new Crawler($listSourceData);
            $items = $crawler->filter("div.articles_list > div.theme_item");
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
        $title = $postData->filter("a.theme_item_name")->text();

        $original = self::ROOT_SRC . $postData->filter("a.theme_item_name")->attr("href");

        $createDate = new DateTime();

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
        if ($post->description === self::EMPTY_DESCRIPTION) {
            $post->description = "";
        }
        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }

        $crawler = new Crawler($pageData);
        $dateHolder = $crawler->filter("div.article_wrap div.article_date");
        if ($dateHolder->count() === 0) {
            throw new Exception("Не найден блок с датой новости");
        }
        $dateString = $dateHolder->text();
        $dateArr = explode(" ", $dateString);
        if (count($dateArr) !== 3) {
            throw new Exception("Date format error");
        }

        if (!isset($dateArr[1]) || !isset(self::MONTHS[$dateArr[1]])) {
            throw new Exception("Could not parse date string");
        }

        $dateString = $dateArr[2] . "-" . self::MONTHS[$dateArr[1]] . "-" . $dateArr[0] . " " . date("H:i:s") . " +03:00";
        $createDate = new DateTime($dateString);
        $createDate->setTimezone(new DateTimeZone("UTC"));
        $post->createDate = $createDate;

        $picHolder = $crawler->filter("div.article_wrap > a > img");
        if ($picHolder->count() !== 0) {
            $post->image = self::normalizeUrl(self::ROOT_SRC . $picHolder->attr("src"));
        }

        $body = $crawler->filter("div.article_wrap div.article_text");

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body->getNode(0)->childNodes as $bodyNode) {
            $node = new Crawler($bodyNode);

            if ($bodyNode->nodeName === "#text" && !empty(trim($bodyNode->textContent))) {
                if (empty($post->description)) {
                    $post->description = Helper::prepareString($bodyNode->textContent);
                } else {
                    self::addText($post, $bodyNode->textContent);
                }
                continue;
            }

            if ($node->matches("div") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                $src = self::normalizeUrl(self::ROOT_SRC . $image->attr("src"));
                if ($post->image === null) {
                    $post->image = $src;
                } else {
                    self::addImage($post, $src);
                }
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


            if ($node->matches("div") && $node->filter("iframe")->count() !== 0) {
                $videoContainer = $node->filter("iframe");
                if ($videoContainer->count() !== 0) {
                    self::addVideo($post, $videoContainer->attr("src"));
                }
            }

            if (!empty(trim($node->text(), "\xC2\xA0"))) {
                if (empty($post->description)) {
                    $post->description = Helper::prepareString($node->text());
                } else {
                    self::addText($post, $node->text());
                }
                continue;
            }
        }

        try {
            $post->validate();
        } catch (Exception $e) {
            print_r($post);
            exit;
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

