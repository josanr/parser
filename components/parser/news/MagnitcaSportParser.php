<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use linslin\yii2\curl\Curl;
use Symfony\Component\DomCrawler\Crawler;


class MagnitcaSportParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "http://magnitka-sport.ru";

    const FEED_SRC = "/sportnews/";
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
        for ($pageId = 0; $pageId < self::LIMIT; $pageId += self::NEWS_PER_PAGE) {

            $listSourcePath = $listSourcePath = self::ROOT_SRC . self::FEED_SRC . "?start=" . $pageId;
            $listSourceData = $curl->get("$listSourcePath");

            $crawler = new Crawler($listSourceData);
            $content = $crawler->filter("article");

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
     * @param Crawler $entityData
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(Crawler $entityData): NewsPost
    {

        $title = $entityData->filter("h2")->text();
        $description = $entityData->filter("article > p")->text();
        $original = self::ROOT_SRC . $entityData->filter("h2 a")->attr("href");

        $createDate = new DateTime($entityData->filter("time")->attr("datetime"));
        $createDate->setTimezone(new DateTimeZone("UTC"));
        $imageUrl = null;

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
        if ($pageData === false) {
            throw new Exception("Url is wrong? nothing received: " . $url);
        }
        $crawler = new Crawler($pageData);

        $content = $crawler->filter("article");

        $header = $content->filter("h1");

        if ($header->count() !== 0) {
            self::addHeader($post, $header->text(), 1);
        }

        $body = $content->filter("div.full_news");
        if ($body->count() === 0) {
            $body = $content->filter('[itemprop="articleBody"]');
        }

        $bodyNodes = $body->filter("div, h2, h3, p");
        foreach ($bodyNodes as $bodyNode) {
            $node = new Crawler($bodyNode);

            if (!empty(trim($node->text(), "\xC2\xA0"))) {
                if ($node->matches("h2")) {
                    self::addHeader($post, $node->text(), 2);
                    continue;
                }
                if ($node->matches("h3")) {
                    self::addHeader($post, $node->text(), 3);
                    continue;
                }
                if ($node->matches("div.quote")) {
                    self::addQuote($post, $node->text());
                    continue;
                }
                if ($node->matches("div.copyright")) {
                    self::addText($post, $node->text());
                    continue;
                }
                if ($node->children()->count() === 0) {
                    self::addText($post, $node->text());
                    continue;
                }
            }

            if ($node->matches(".yendifplayer")) {
                $video = $node->filter("video source");
                if ($video->count() !== 0) {
                    self::addVideo($post, $video->attr("src"));
                }
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
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_IMAGE,
                null,
                $content,
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

    private static function addVideo(NewsPost $post, string $url)
    {

        $host = parse_url($url, PHP_URL_HOST);
        if (mb_stripos($host, "youtu") === false) {
            return;
        }

        $parsedUrl = [];
        parse_str(parse_url($url, PHP_URL_QUERY), $parsedUrl);

        if (!isset($parsedUrl["v"])) {
            throw new InvalidArgumentException("Could not parse Youtube ID");
        }
        $id = mb_strcut($parsedUrl["v"], 0, 11);

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

