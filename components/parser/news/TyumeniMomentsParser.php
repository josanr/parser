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


class TyumeniMomentsParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://tyumen.momenty.org/";

    const FEED_SRC = "";
    const LIMIT = 100;
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
        if(empty($listSourceData)){
            throw new Exception("Получен пустой ответ от источника списка новостей: ". $listSourcePath);
        }
        $crawler = new Crawler($listSourceData);
        $items = $crawler->filter("div.publication");
        if($items->count() === 0){
            throw new Exception("Пустой список новостей в ленте: ". $listSourcePath);
        }
        $counter = 0;
        foreach ($items as $newsItem) {
            try {
                $node = new Crawler($newsItem);
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
        $title = $postData->filter("a.title")->text();

        $original = self::normalizeUrl(self::ROOT_SRC . $postData->filter("a.title")->attr("href"));


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
        if($post->description === self::EMPTY_DESCRIPTION){
            $post->description = "";
        }
        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }

        $crawler = new Crawler($pageData);
        $picHolder = $crawler->filter("article div.announce_image img");//author
        if($picHolder->count() !== 0){
            $post->image = self::normalizeUrl($picHolder->attr("src"));

        }
        $authHolder = $crawler->filter("article div.author");//author
        if($authHolder->count() !== 0){
            self::addText($post, $authHolder->text());
        }
        $dateHolder = $crawler->filter("article div.publication-footer span.date");
        $timeHolder = $crawler->filter("article div.publication-footer span.time");
        if($dateHolder->count() === 0 || $timeHolder->count() === 0){
            throw new Exception("Не найден блок с датой публикации");
        }
        $dateArr = explode(" ", $dateHolder->text());
        if (count($dateArr) !== 3) {
            throw new Exception("Date format error");
        }
        if (!isset($dateArr[1]) || !isset(self::MONTHS[$dateArr[1]])) {
            throw new Exception("Could not parse date string");
        }

        $dateString = $dateArr[2] . "-" . self::MONTHS[$dateArr[1]] . "-" . $dateArr[0] . " " . $timeHolder->text() . "+05:00";
        $createDate = new DateTime($dateString);
        $createDate->setTimezone(new DateTimeZone("UTC"));
        $post->createDate = $createDate;

        $body = $crawler->filter("article div.content p, article div.content div");

        if($body->count() === 0){
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body as $bodyNode) {
            $node = new Crawler($bodyNode);

            if ($node->matches("p") && !empty(trim($node->text(), "\xC2\xA0"))) {
                if(empty($post->description)){
                    $post->description = Helper::prepareString($node->text());
                }else{
                    self::addText($post, $node->text());
                }
                continue;
            }


            if ($node->matches("div.image-wrap") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                $src = self::normalizeUrl($image->attr("src"));
                if($post->image === null){
                    $post->image = $src;
                }else{
                    self::addImage($post, $src);
                }
                if($node->filter("span.caption")->count() !== 0){
                    self::addText($post, $node->filter("span.caption")->text());
                }
            }


            if ($node->matches("ul, ol") && !empty(trim($node->text(), "\xC2\xA0"))) {
                $node->children("li")->each(function (Crawler $liNode) use ($post){
                    self::addText($post, $liNode->text());
                });
                continue;
            }

            if ($node->matches("blockquote") && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addQuote($post, $node->text());
                continue;
            }


            if ($node->matches("div") && $node->filter("iframe")->count() !== 0) {
                $videoContainer = $node->filter("iframe");
                if ($videoContainer->count() !== 0) {
                    self::addVideo($post, $videoContainer->attr("src"));
                }
            }
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

}

