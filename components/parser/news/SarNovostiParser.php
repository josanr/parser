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


class SarNovostiParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://sarnovosti.ru";

    const FEED_SRC = "/news/";
    const LIMIT = 100;
    const EMPTY_DESCRIPTION = "empty";
    const NEWS_PER_PAGE = 30;

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
            $items = $crawler->filter("div.main-column div.news-block");
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
        $title = $postData->filter("div.news-block__body a")->text();

        $original = self::ROOT_SRC . self::normalizeUrl($postData->filter("div.news-block__body a")->attr("href"));

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
        $post->description = "";

        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }

        $crawler = new Crawler($pageData);

        $imgHolder = $crawler->filter("div#news article img");
        if ($imgHolder->count() !== 0) {
            $post->image = self::normalizeUrl(self::ROOT_SRC . $imgHolder->attr("src"));
        }

        $dateHolder = $crawler->filter("div.meta-group time[itemprop=\"dateModified\"]");
        $createDate = new DateTime($dateHolder->attr("datetime"));
        $createDate->setTimezone(new DateTimeZone("UTC"));

        $post->createDate = $createDate;

        $body = $crawler->filter("div#news article div[itemprop=\"articleBody\"]");

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body->getNode(0)->childNodes as $bodyNode) {
            $node = new Crawler($bodyNode);

            if ($bodyNode->nodeName === "#text" && !empty(trim($node->text(), "\xC2\xA0"))) {
                if (empty($post->description)) {
                    $post->description = Helper::prepareString($node->text());
                } else {
                    self::addText($post, $node->text());
                }
                continue;
            }
            if ($node->matches("p, div") && !empty(trim($node->text(), "\xC2\xA0"))) {
                if (empty($post->description)) {
                    $post->description = Helper::prepareString($node->text());
                } else {
                    self::addText($post, $node->text());
                }
                continue;
            }

            if ($node->matches("div") && $node->filter("iframe")->count() !== 0) {
                $videoContainer = $node->filter("iframe");
                if ($videoContainer->count() !== 0) {
                    self::addVideo($post, $videoContainer->attr("src"));
                }
            }
        }

        $imgHolder = $crawler->filter("div#news article div.gallery-wrapper div.gallery-top img");
        if ($imgHolder->count() !== 0) {
            $imgHolder->each(function (Crawler $imgNode) use ($post) {
                self::addImage($post, self::ROOT_SRC . $imgNode->attr("src"));
            });
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

