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


class KirsanOnlineParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://tvkirsanov.ru";

    const FEED_SRC = "/news/";
    const LIMIT = 100;

    const NEWS_PER_PAGE = 11;

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

            $listSourcePath = $listSourcePath = self::ROOT_SRC . self::FEED_SRC . "page/" . $pageId . "/";

            $listSourceData = $curl->get("$listSourcePath");
            if(empty($listSourceData)){
                throw new Exception("Получен пустой ответ от источника списка новостей: ". $listSourcePath);
            }

            $crawler = new Crawler($listSourceData);
            $items = $crawler->filter("div.news_index_1");
            if($items->count() === 0){
                throw new Exception("Пустой список новостей в ленте: ". $listSourcePath);
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
        $title = $postData->filter("div.news_title a")->text();
        $original = self::ROOT_SRC . $postData->filter("div.news_title a")->attr("href");

        $description = $postData->filter("div.news_text_index")->text();


        $imageUrl = null;
        $imageSrc = $postData->filter("div.news_title_img")->attr("style");
        $imageArr = explode("(", $imageSrc);

        if (!isset($imageArr[1])) {
            throw new InvalidArgumentException("Image url could not be parsed");
        }
        $imageUrl = self::ROOT_SRC . trim(explode(")", $imageArr[1])[0]);

        $timeContainer = $postData->filter("div.news-soc-index")->text();

        $timeString = trim(explode("/", $timeContainer)[0]);
        $timeString .= " " . date("H:i:s");

        $createDate = new DateTime($timeString);
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

        $content = $crawler->filter("div.centertable");

        $videoContainer = $content->filter("iframe");

        if ($videoContainer->count() !== 0) {
            self::addVideo($post, $videoContainer->attr("src"));
        }

        $body = $content->filter("div.news-text");

        if($body->count() === 0){
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }
        /** @var DOMNode $bodyNode */
        foreach ($body->getNode(0)->childNodes as $bodyNode) {
            $node = new Crawler($bodyNode);

            if ($bodyNode->nodeName === "#text" && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addText($post, $node->text());
                continue;
            }
            if ($node->matches("div.news-pic")) {
                $image = $node->filter("img");
                if ($image->count() !== 0) {
                    self::addImage($post, self::ROOT_SRC . $image->attr("src"));
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
        $content = self::normalizeUrl($content);
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
        return preg_replace_callback('/[^\x20-\x7f]/', function ($match) {
            return urlencode($match[0]);
        }, $content);
    }
}

