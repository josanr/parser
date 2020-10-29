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


class FinoUgorskaiaParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "http://fugazeta.ru";

    const FEED_SRC = "/feed/";
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
     * @param Crawler $postData
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(Crawler $postData): NewsPost
    {
        $title = $postData->filter("title")->text();
        $original = $postData->filter("link")->text();

        $createDate = new DateTime($postData->filterXPath("item/pubDate")->text());
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

        $content = $crawler->filter("article.post");


        $image = $content->filter("div.entry-content img");

        if ($image->count() !== 0) {
            $post->image = self::normalizeUrl($image->attr("src"));
        }

        $body = $content->filter("div.entry-content ");
        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }


        /** @var DOMNode $bodyNode */
        foreach ($body->getNode(0)->childNodes as $bodyNode) {
            $node = new Crawler($bodyNode);

            if ($node->matches("div.img-detail") && !empty(trim($node->text(), "\xC2\xA0"))) {
                $post->description = Helper::prepareString($node->text());
                continue;
            }
            if ($node->matches("div.enlarge") && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addText($post, $node->text());
                continue;
            }
            if ($node->matches("p") && $node->filter("iframe")->count() !== 0) {
                $videoContainer = $node->filter("iframe");
                if ($videoContainer->count() !== 0) {
                    self::addVideo($post, $videoContainer->attr("src"));
                }
            }

            if ($node->matches("div, article") && !empty(trim($node->text(), "\xC2\xA0"))) {

                $node->children("p")->each(function (Crawler $pNode) use ($post) {

                    if ($pNode->filter("iframe")->count() !== 0) {
                        $videoContainer = $pNode->filter("iframe");
                        if ($videoContainer->count() !== 0) {
                            self::addVideo($post, $videoContainer->attr("src"));
                        }
                    }


                    if (!empty(trim($pNode->text(), "\xC2\xA0"))) {
                        if ($post->description === "") {
                            $post->description = Helper::prepareString($pNode->text());
                        } else {
                            self::addText($post, $pNode->text());
                        }
                    }
                });

                $node->children("div")->each(function (Crawler $pNode) use ($post) {
                    if (!empty(trim($pNode->text(), "\xC2\xA0"))) {
                        if ($post->description === "") {
                            $post->description = Helper::prepareString($pNode->text());
                        } else {
                            self::addText($post, $pNode->text());
                        }
                    }
                });
                continue;
            }

            if ($node->matches("p") && !empty(trim($node->text(), "\xC2\xA0"))) {
                if ($post->description === "") {
                    $post->description = Helper::prepareString($node->text());
                } else {
                    self::addText($post, $node->text());
                }
                continue;
            }

            if ($bodyNode->nodeName === "#text" && !empty(trim($node->text(), "\xC2\xA0"))) {
                if ($post->description === "") {
                    $post->description = Helper::prepareString($node->text());
                } else {
                    self::addText($post, $node->text());
                }
                continue;
            }
        }


        if(empty($post->description)){
            throw new Exception("No text parsed: " . $url);
        }
    }


    private static function addVideo(NewsPost $post, string $url)
    {

        $host = parse_url($url, PHP_URL_HOST);
        if (mb_stripos($host, "youtu") === false) {
            return;
        }

        $parsedUrl = explode("/", parse_url($url, PHP_URL_PATH));


        if (!isset($parsedUrl[2])) {
            throw new Exception("Could not parse Youtube ID");
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

