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


class GnkkParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://gnkk.ru";

    const FEED_SRC = "/news/";
    const LIMIT = 100;
    const EMPTY_DESCRIPTION = "empty";
    const NEWS_PER_PAGE = 16;
    const MONTHS = [
        "Янв" => "01",
        "Фев" => "02",
        "Мар" => "03",
        "Апр" => "04",
        "Мая" => "05",
        "Июн" => "06",
        "Июл" => "07",
        "Авг" => "08",
        "Сен" => "09",
        "Окт" => "10",
        "Ноя" => "11",
        "Дек" => "12",
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
            $items = $crawler->filter("div.news-list-item");
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
        $title = $postData->filter("h3 a")->text();

        $original = self::normalizeUrl($postData->filter("h3 a")->attr("href"));


        $date = $postData->filter("span.news-list-item-date span");

        if ($date->count() === 0) {
            throw new Exception("Не найден блок с датой");
        }

        $dateString = $date->text();

        if (mb_stripos(trim($dateString), "Сегодня") !== false) {
            $dateCompose = date("Y-m-d");
            $dateArr = explode(" ", $dateString);
            $dateCompose .= " " . $dateArr[count($dateArr) - 1] . " +07:00";
        } else {
            $dateArr = explode(" ", $dateString);
            if (count($dateArr) !== 3) {
                throw new Exception("Could not parse dateTime string");
            }

            if (count($dateArr) !== 3 || !isset(self::MONTHS[$dateArr[1]])) {
                throw new Exception("Could not parse date string");
            }

            $dateCompose = trim($dateArr[2], "'") . "-" . self::MONTHS[$dateArr[1]] . "-" . $dateArr[0] . " " . date("H:i:s") . " +07:00";
        }


        $createDate = new DateTime($dateCompose);
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
        if ($post->description === self::EMPTY_DESCRIPTION) {
            $post->description = "";
        }
        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }

        $crawler = new Crawler($pageData);

        $body = $crawler->filter("div.article-body");

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body->getNode(0)->childNodes as $bodyNode) {
            $node = new Crawler($bodyNode);
            if ($node->matches("div.zen-link, div.comments")) {
                break;
            }
            if ($bodyNode->nodeName === "#text" && !empty(trim($node->text(), "\xC2\xA0"))) {
                if (empty($post->description)) {
                    $post->description = Helper::prepareString($node->text());
                } else {
                    self::addText($post, $node->text());
                }
                continue;
            }
            if ($node->matches("img")) {

                $src = self::normalizeUrl(self::ROOT_SRC . $node->attr("src"));
                if ($post->image === null) {
                    $post->image = $src;
                } else {
                    self::addImage($post, $src);
                }
                continue;
            }

            if ($node->matches("span.photo-info") && !empty(trim($node->text(), "\xC2\xA0"))) {

                self::addText($post, $node->text());
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
                continue;
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

