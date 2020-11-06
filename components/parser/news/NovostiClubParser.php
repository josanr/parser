<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use DOMDocument;
use DOMNode;
use Exception;
use linslin\yii2\curl\Curl;
use Symfony\Component\DomCrawler\Crawler;


class NovostiClubParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://xn--90aoeeg3am.xn--p1ai";

    const FEED_SRC = "/?rss=rss";
    const LIMIT = 100;


    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $curl = Helper::getCurl();
        $posts = [];

        $listSourcePath = self::ROOT_SRC . self::FEED_SRC;

        $listSourceData = $curl->get($listSourcePath);
        if(empty($listSourceData)){
            throw new Exception("Получен пустой ответ от источника списка новостей: ". $listSourcePath);
        }

        $DOMDocument = new DOMDocument();
        $DOMDocument->loadXML($listSourceData);


        $DOMNodeList = $DOMDocument->getElementsByTagName("item");
        if($DOMNodeList->count() === 0){
            throw new Exception("Пустой список новостей в ленте: ". $listSourcePath);
        }
        $counter = 0;
        /** @var DOMNode $itemNode */
        foreach ($DOMNodeList as $item) {
            try {
                $newsPost = self::inflatePost($item);
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
     * @param DOMNode $node
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(DOMNode $node): NewsPost
    {

        $postData = new Crawler($node);

        $title = $postData->filter("title")->text();

        $createDate = new DateTime($postData->filterXPath("item/pubDate")->text());
        $createDate->setTimezone(new DateTimeZone("UTC"));

        $description = $postData->filter("description")->text();
        if (empty($description)) {
            $text = $postData->filterXPath("item/yandex:full-text");

            $sentences = preg_split('/(?<=[.?!])\s+(?=[а-я])/i', $text->text());
            $description = implode(" ", array_slice($sentences, 0, 3));
        }

        $image = $postData->filter("enclosure");
        $imageUrl = null;
        if ($image->count() !== 0) {
            $imageUrl = $image->attr("url");
        }


        $original = null;
        /** @var DOMNode $node */
        foreach ($node->childNodes as $node) {
            if ($node->nodeName === "link") {
                $original = $node->nodeValue;
            }
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

        $body = $crawler->filter("div.newscontent > p");
        if($body->count() === 0){
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        foreach ($body as $bodyNode) {
            $node = new Crawler($bodyNode);

            if ($node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                self::addImage($post, $image->attr("src"));
                continue;
            }

            if (!empty(trim($node->text(), "\xC2\xA0")) && $node->filter("span.big")->count() !== 0) {
                self::addQuote($post, $node->text());
                continue;
            }

            if (!empty(trim($node->text(), "\xC2\xA0"))) {
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
        return preg_replace_callback('/[^\x20-\x7f]/', function ($match) {
            return urlencode($match[0]);
        }, $content);
    }
}

