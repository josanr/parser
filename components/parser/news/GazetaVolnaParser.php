<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use linslin\yii2\curl\Curl;
use Symfony\Component\DomCrawler\Crawler;


class GazetaVolnaParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://volnanews.online";

    const FEED_SRC = "/rubrics/obshchestvo";
    const LIMIT = 5;
    const EMPTY_DESCRIPTION = "empty";
    const MONTHS = [
        "января" => "01",
        "февраля" => "02",
        "марта" => "03",
        "апреля" => "04",
        "мая" => "05",
        "июня" => "06",
        "июля" => "07",
        "августа" => "08",
        "сентября" => "09",
        "октября" => "10",
        "ноября" => "11",
        "декабря" => "12",
    ];

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

        $filterNode = $crawler->filter("h4.module-title");

        if ($filterNode->count() === 0) {
            throw new Exception("Пустой список новостей в ленте: " . $listSourcePath);
        }

        $feedNodes = $filterNode->nextAll()->children("div")->children("div");


        $counter = 0;
        foreach ($feedNodes as $item) {
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
     * @param Crawler $postData
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(Crawler $postData): NewsPost
    {
        $title = $postData->filter("span.title")->text();
        $original = self::ROOT_SRC . $postData->filter("span.title a")->attr("href");
        $dateString = $postData->filter("span.create")->text();

        $dateArr = explode(" ", $dateString);
        if (count($dateArr) !== 4) {
            throw new Exception("Date format error");
        }
        if (!isset($dateArr[1]) || !isset(self::MONTHS[$dateArr[1]])) {
            throw new Exception("Could not parse date string");
        }

        $dateString = $dateArr[2] . "-" . self::MONTHS[$dateArr[1]] . "-" . $dateArr[0] . " " . $dateArr[3] . "+03:00";
        $createDate = new DateTime($dateString);
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

        $content = $crawler->filter("div.item-page");

        $body = $content->filter('div [itemprop="articleBody"]');

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        foreach ($body->children() as $bodyNode) {

            $node = new Crawler($bodyNode);
            if ($node->matches("h2") && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addHeader($post, $node->text(), 2);
                continue;
            }
            if ($node->matches("p") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                if ($post->image === null) {
                    $post->image = self::normalizeUrl(self::ROOT_SRC . $image->attr("src"));
                } else {
                    self::addImage($post, self::ROOT_SRC . $image->attr("src"));
                }
                continue;
            }

            if ($node->matches("p") && !empty(trim($node->text(), "\xC2\xA0"))) {
                if (empty($post->description)) {
                    $post->description = Helper::prepareString($node->text());
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

