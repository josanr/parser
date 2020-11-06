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


class ObzorPressParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://obzor.press";

    const FEED_SRC = "/russian/";
    const LIMIT = 100;
    const EMPTY_DESCRIPTION = "empty";
    const NEWS_PER_PAGE = 27;

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
            $items = $crawler->filter("div#topic-thumbs article.item1");

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
        $title = $postData->filter("div.caption h3")->text();

        $original = self::ROOT_SRC . "/" . $postData->filter("div.thumbnail a")->attr("href");

        $createDate = new DateTime();
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
        $post->description = "";
        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }

        $crawler = new Crawler($pageData);

        $imgHolder = $crawler->filter("section#first article div.itemcap img");
        if ($imgHolder->count() !== 0) {
            $post->image = self::normalizeUrl(self::ROOT_SRC . "/" . $imgHolder->attr("src"));
        }

        $descrHolder = $crawler->filter("section#first article div.intro p");
        if ($descrHolder->count() === 0) {
            throw new Exception("Не найден блок описания новости: " . $url);
        }
        $post->description = Helper::prepareString($descrHolder->text());

        $dateHolder = $crawler->filter("section#first article div.apublishedon");
        if ($dateHolder->count() === 0) {
            throw new Exception("Не найден блок с датой новости: " . $url);
        }

        $createDate = DateTime::createFromFormat("d m Y H:i:s P", $dateHolder->text() . ":00 +03:00");
        if ($createDate === false) {
            throw new Exception("Не распознана дата новости: " . $url);
        }
        $createDate->setTimezone(new DateTimeZone("UTC"));
        $post->createDate = $createDate;

        $body = $crawler->filter("section#first article div.items_obzor");

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
            if ($node->matches("blockquote")) {
                self::addQuote($post, $node->text());
                continue;
            }
            if ($node->matches("h2")) {
                self::addHeader($post, $node->text(), 2);
                continue;
            }
            if ($node->matches("h3")) {
                self::addHeader($post, $node->text(), 3);
                continue;
            }
            if ($node->matches("ul, ol") && !empty(trim($node->text(), "\xC2\xA0"))) {
                $node->children("li")->each(function (Crawler $liNode) use ($post) {
                    self::addText($post, $liNode->text());
                });
                continue;
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
}

