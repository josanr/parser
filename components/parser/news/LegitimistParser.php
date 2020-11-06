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


class LegitimistParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://www.legitimist.ru";
    const FEED_SRC = "/news/";
    const LIMIT = 100;
    const NEWS_PER_PAGE = 27;
    const EMPTY_DESCRIPTION = "empty";

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

            $listSourcePath = self::ROOT_SRC . self::FEED_SRC . "?page=" . $pageId;

            $listSourceData = $curl->get($listSourcePath);
            if (empty($listSourceData)) {
                throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
            }
            $crawler = new Crawler($listSourceData);
            $items = $crawler->filter("table.main-list tr");
            if ($items->count() === 0) {
                throw new Exception("Пустой список новостей в ленте: " . $listSourcePath);
            }
            foreach ($items as $newsItem) {
                try {

                    $node = new Crawler($newsItem);
                    if (empty($node->filter("td")->text())) {
                        continue;
                    }
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
     * @param Crawler $entityData
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(Crawler $entityData): NewsPost
    {

        $timeString = $entityData->filter("td span.time")->html();
        $timeString = str_replace("\xc2\xa0", '', $timeString);
        $timeArr = explode(" ", $timeString);
        if (count($timeArr) <= 1) {
            throw new Exception("Could not divide date string");
        }
        $date = explode(".", $timeArr[0]);
        if (count($date) <= 1) {
            throw new Exception("Could not parse date string");
        }
        $newDateString = "";
        $newDateString .= $date[2] ?? date("Y");
        $newDateString .= "-" . $date[1];
        $newDateString .= "-" . $date[0];
        $newDateString .= " " . $timeArr[1];


        $createDate = new DateTime($newDateString, new DateTimeZone("Europe/Moscow"));
        $createDate->setTimezone(new DateTimeZone("UTC"));
        $title = $entityData->filter("td h3 a")->text();
        $original = $entityData->filter("td h3 a")->attr("href");

        $description = self::EMPTY_DESCRIPTION;
        $descrStr = $entityData->filter("td p a");
        if ($descrStr->count() !== 0) {
            $description = rtrim($descrStr->text(), "→");
        }
        return new NewsPost(
            self::class,
            $title,
            $description,
            $createDate->format("Y-m-d H:i:s"),
            self::ROOT_SRC . "/" . $original,
            null
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
        if (empty($post->description)) {
            $post->description = "";
        }

        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }

        $crawler = new Crawler($pageData);

        $body = $crawler->filter("div.content");

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }
        $image = $crawler->filter("div#imgn img");
        if ($image->count() !== 0) {
            $imgUrl = $image->attr("src");
            $post->image = self::normalizeUrl(self::ROOT_SRC . $imgUrl);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body->children() as $bodyNode) {
            $node = new Crawler($bodyNode);
            if ($node->matches("p") && !empty(trim($node->text(), "\xC2\xA0"))) {
                if ($node->children()->count() === 0) {
                    if (empty($post->description)) {
                        $post->description = Helper::prepareString($node->text());
                    } else {
                        self::addText($post, $node->text());
                    }
                    continue;
                }
                if ($node->filter("br")->count() !== 0) {
                    if (empty($post->description)) {
                        $post->description = Helper::prepareString($node->text());
                    } else {
                        self::addText($post, $node->text());
                    }
                    continue;
                }
                if ($node->filter("span")->count() !== 0) {
                    if (empty($post->description)) {
                        $post->description = Helper::prepareString($node->text());
                    } else {
                        self::addText($post, $node->text());
                    }
                    continue;
                }
                if ($node->filter("a")->count() !== 0) {
                    if (empty($post->description)) {
                        $post->description = Helper::prepareString($node->text());
                    } else {
                        self::addText($post, $node->text());
                    }
                    continue;
                }
                if ($node->filter("em")->count() !== 0) {
                    if (empty($post->description)) {
                        $post->description = Helper::prepareString($node->text());
                    } else {
                        self::addText($post, $node->text());
                    }
                    continue;
                }
            }


            if (
                $node->matches("div")
                && $node->filter("strong")->count() !== 0
                && !empty(trim($node->text(), "\xC2\xA0"))
            ) {
                self::addHeader($post, $node->text(), 6);
                continue;
            }
            if (
                $node->matches("div.details")
                && !empty(trim($node->text(), "\xC2\xA0"))
            ) {
                $node->children()->each(function (Crawler $detNode) use ($post) {
                    self::addText($post, str_ireplace('­', "", $detNode->text()));
                });
                continue;
            }

            if ($node->nodeName() === "h3") {
                self::addHeader($post, $node->text(), 3);
                continue;
            }
            if ($node->filter("img")->count() !== 0) {
                self::addImage($post, self::normalizeUrl(self::ROOT_SRC . "/" . $node->filter("img")->attr("src")));
                continue;
            }
        }
    }


    /**
     * @param NewsPost $post
     * @param string   $content
     * @param int      $level
     */
    private static function addHeader(NewsPost $post, string $content, int $level): void
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

