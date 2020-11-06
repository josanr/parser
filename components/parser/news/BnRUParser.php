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


class BnRUParser implements ParserInterface
{
    /*run*/
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://www.bn.ru";

    const FEED_SRC = "/gazeta/news/";
    const LIMIT = 100;
    const NEWS_PER_PAGE = 10;
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

            $listSourcePath = self::ROOT_SRC . self::FEED_SRC . "page/" . $pageId . "/";

            $listSourceData = $curl->get($listSourcePath);
            if (empty($listSourceData)) {
                throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
            }
            $crawler = new Crawler($listSourceData);
            $items = $crawler->filter("ul.page--index-new_center--content_gazeta--list_links li");
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
        $original = self::ROOT_SRC . self::normalizeUrl($postData->filter("a")->attr("href"));
        $title = "";
        /** @var DOMNode $node */
        foreach ($postData->filter("a")->first()->getNode(0)->childNodes as $node) {
            if ($node->nodeName === "#text") {
                $title = Helper::prepareString($node->textContent);
            }
        }

        $dateString = $postData->filter("a span")->text();
        $dateArr = explode("|", $dateString);
        if (count($dateArr) !== 2) {
            throw new Exception("Could not parse date time string");
        }
        
        if (trim($dateArr[0]) === "сегодня") {
            $dateCompose = (new DateTime())->format("Y-m-d");
        } elseif (trim($dateArr[0]) === "вчера") {
            $dateCompose = (new DateTime())->modify("-1 day")->format("Y-m-d");
        } else {
            $dateCompose = (new DateTime($dateArr[0]))->format("Y-m-d");
        }

        $dateCompose .= " " . trim($dateArr[1]) . ":00 +03:00";
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

        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }

        $crawler = new Crawler($pageData);
        $description = $crawler->filter("div.page--index-new_center--content_gazeta--description");
        if ($description->count() !== 0) {
            $post->description = Helper::prepareString($description->text());
        }

        $image = $crawler->filter("div.page--index-new_center--content_gazeta--main_image img");
        if ($image->count() !== 0) {
            $post->image = self::PREFIX . self::normalizeUrl($image->attr("src"));
        }


        $body = $crawler->filter("div.page--index-new_center--content_gazeta--text");

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body->children() as $bodyNode) {
            $node = new Crawler($bodyNode);

            if ($node->matches("p") && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addText($post, $node->text());
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

