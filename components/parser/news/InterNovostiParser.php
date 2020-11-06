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


class InterNovostiParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "http://www.internovosti.ru";
    const FEED_SRC = "/xmlnews.asp";
    const LIMIT = 100;
    const EMPTY_DESCRIPTION = "empty";

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

        if (empty($listSourceData)) {
            throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
        }

        $crawler = new Crawler($listSourceData);
        $items = $crawler->filter("item");
        if ($items->count() === 0) {
            throw new Exception("Пустой список новостей в ленте: " . $listSourcePath);
        }
        $counter = 0;
        foreach ($items as $item) {
            try {
                $node = new Crawler($item);
                $newsPost = self::inflatePost($node);
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
     * @param Crawler $entityData
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(Crawler $entityData): NewsPost
    {
        $title = $entityData->filter("title")->text();

        $description = self::EMPTY_DESCRIPTION;
        $original = $entityData->filter("link")->text();
        $createDate = new DateTime($entityData->filterXPath("item/pubDate")->text());

        $createDate->setTimezone(new DateTimeZone("UTC"));
        $imageUrl = $entityData->filter("enclosure")->attr("url");

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
     * @param Curl     $curl
     *
     * @throws Exception
     */
    private static function inflatePostContent(NewsPost $post, Curl $curl)
    {
        $url = $post->original;
        if($post->description === self::EMPTY_DESCRIPTION){
            $post->description = "";
        }
        $pageData = iconv('Windows-1251', 'UTF-8', $curl->get($url));
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }


        $page = new Crawler($pageData);

        $body = $page->filter("span.mytime")->parents()->first();
        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        $photoText = $body->filter("span.mysmall")->text();
        if (!empty($photoText)) {
            self::addText($post, $photoText);
        }

        $text = "";
        /** @var DOMNode $node */
        foreach ($body->getNode(0)->childNodes as $node) {
            if (strpos(trim($node->textContent), "Для добавления комментария ") === 0) {
                if (!empty($text)) {
                    self::addText($post, $text);
                }
                break;
            }

            if ($node->nodeName === '#text' && trim($node->textContent) !== "") {
                $text .= trim($node->textContent);
            }
            if ($node->nodeName === 'a' && trim($node->textContent) !== "") {
                $text .= " " . trim($node->textContent) . " ";
            }
            if ($node->nodeName === "br" && !empty($text)) {
                if(empty($post->description)){
                    $post->description = Helper::prepareString($text);
                }else{
                    self::addText($post, $text);
                }
                $text = "";
            }
        }
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

