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


class VibiraiIzhevskParser implements ParserInterface
{
    /*run*/
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://izhevsk.vibirai.ru";

    const FEED_SRC = "/articles?category=новости";
    const LIMIT = 100;
    const NEWS_PER_PAGE = 16;
    const EMPTY_DESCRIPTION = "empty";
    const PREFIX = "https:";

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

            $listSourcePath = self::ROOT_SRC . self::FEED_SRC . "&page=" . $pageId;

            $listSourceData = $curl->get($listSourcePath);
            if (empty($listSourceData)) {
                throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
            }
            $crawler = new Crawler($listSourceData);
            $content = $crawler->filter("div.feed div.feed__item");
            if($content->count() === 0){
                throw new Exception("Пустой список ленты новостей: " . $listSourcePath);
            }
            foreach ($content as $newsItem) {
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
        $title = $postData->filter("article h3")->text();

        $original = self::PREFIX . self::normalizeUrl($postData->filter("article h3 a")->attr("href"));

        $image = $postData->filter("picture img");
        $imageUrl = null;
        if ($image->count() !== 0) {
            $imageUrl = self::PREFIX . self::normalizeUrl($image->attr("src"));
        }
        $createDate = new DateTime($postData->filter("article time")->attr("datetime"));
        $createDate->setTimezone(new DateTimeZone("UTC"));

        $description = $postData->filter("p")->text();
        if (empty($description)) {
            $description = self::EMPTY_DESCRIPTION;
        }
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

        $body = $crawler->filter("div.article__just_text");
        if ($body->count() === 0) {
            $body = $crawler->filter("div.article__text");
        }
        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }
        /** @var DOMNode $bodyNode */
        foreach ($body->children() as $bodyNode) {
            $node = new Crawler($bodyNode);
            if ($node->matches("div.squares")) {
                continue;
            }

            if ($node->matches("p") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                self::addImage($post, self::PREFIX . $image->attr("src"));
                continue;
            }

            if ($node->matches("div.article_gallery_wrap") && $node->filter("img")->count() !== 0) {
                $node->filter("img")->each(function (Crawler $imgNode) use ($post) {
                    self::addImage($post, self::PREFIX . $imgNode->attr("src"));
                });

            }
            if ($node->matches("p") && $node->filter("iframe")->count() !== 0) {
                $videoContainer = $node->filter("iframe");
                if ($videoContainer->count() !== 0) {
                    self::addVideo($post, $videoContainer->attr("src"));
                }
                continue;
            }
            if ($node->matches("h2") && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addHeader($post, $node->text(), 2);
                continue;
            }
            if ($node->matches("h3") && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addHeader($post, $node->text(), 3);
                continue;
            }

            if (
                $node->matches("div")
                && !empty($node->attr("style"))
                && mb_stripos($node->attr("style"), "border-left: 1px dotted") !== false
            ) {
                self::addQuote($post, $node->text());
                continue;
            }

            if (in_array($node->nodeName(), ["p", "div"]) && !empty(trim($node->text(), "\xC2\xA0"))) {

                if ($post->description === self::EMPTY_DESCRIPTION) {
                    $post->description = helper::prepareString($node->text());
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

