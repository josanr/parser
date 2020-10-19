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


class PetrogazetaParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://petrogazeta.ru";

    const FEED_SRC = "/news";
    const LIMIT = 100;
    const NEWS_PER_PAGE = 20;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $curl = Helper::getCurl();
        $posts = [];

        $counter = 0;
        for ($pageId = 0; $pageId < ceil(self::LIMIT / self::NEWS_PER_PAGE); $pageId++) {

            $listSourcePath = self::ROOT_SRC . self::FEED_SRC . "?page=" . $pageId;

            $listSourceData = $curl->get($listSourcePath);
            if (empty($listSourceData)) {
                throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
            }
            $crawler = new Crawler($listSourceData);
            $items = $crawler->filter("div.front-preview");
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
        $title = $postData->filter("h3")->text();
        $original = self::ROOT_SRC . self::normalizeUrl($postData->filter("h3 a")->attr("href"));
        $imageUrl = null;
        $image = $postData->filter("div.field-type-image img");
        if ($image->count() !== 0) {
            $imageUrl = $image->attr("src");
        }
        $description = $postData->filter("a.simple-link")->text();
        $dateString = $postData->filter("h3 a")->attr("href");
        $dateArr = explode("/", $dateString);
        if (count($dateArr) < 4) {
            throw new Exception("Could not parse date string");
        }
        $now = new DateTime();
        $dateCompose = $dateArr[1] . "-" . $dateArr[2] . "-" . $dateArr[3] . " " . $now->format("H:i:s") . " +03:00";
        $createDate = new DateTime($dateCompose);
        $createDate->setTimezone(new DateTimeZone("UTC"));

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
        $submited = $crawler->filter("div#content-wrapper article.node-material header");
        if ($submited->count() !== 0) {
            $createDate = new DateTime($submited->text() . " +03:00");
            $createDate->setTimezone(new DateTimeZone("UTC"));
            $post->createDate = $createDate;
        }


        $body = $crawler->filter("div#content-wrapper article.node-material > div > div.field-name-body > div > div");
        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }
        /** @var DOMNode $bodyNode */
        foreach ($body->children() as $bodyNode) {
            $node = new Crawler($bodyNode);

            if ($node->matches("p") && mb_stripos($node->text(), "Читайте также") === 0) {
                break;
            }

            if ($node->matches("p") && !empty(trim($node->text(), "\xC2\xA0"))) {
                $cleanText = str_ireplace($post->description, "", $node->text());
                if (!empty($cleanText)) {
                    self::addText($post, $cleanText);
                }
                continue;
            }

            if ($node->matches("p") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                self::addImage($post, self::ROOT_SRC . $image->attr("src"));
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
