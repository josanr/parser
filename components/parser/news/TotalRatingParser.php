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


class TotalRatingParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://total-rating.ru";

    const FEED_SRC = "/page/";
    const LIMIT = 40;
    const EMPTY_DESCRIPTION = "empty";
    const NEWS_PER_PAGE = 30;

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

            $listSourcePath = self::ROOT_SRC . self::FEED_SRC . $pageId . "/";

            $listSourceData = $curl->get($listSourcePath);
            if (empty($listSourceData)) {
                throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
            }
            $crawler = new Crawler($listSourceData);
            $items = $crawler->filter("article.card");

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
        $title = $postData->filter("a.post-url")->text();

        $original = $postData->filter("a.post-url")->attr("href");

        $dateString = $postData->filter("div.post-date div.count")->text();

        $dateArr = explode(" ", $dateString);
        if (count($dateArr) !== 2) {
            throw new Exception("Date format error");
        }

        $dateString = $dateArr[1] . "-" . $dateArr[0] . "-" . date("d H:i:s") . "+03:00";
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
        if ($post->description === self::EMPTY_DESCRIPTION) {
            $post->description = "";
        }
        $pageData = $curl->get($url);
        if (empty($pageData)) {
            throw new Exception("Получен пустой ответ от страницы новости: " . $url);
        }

        $crawler = new Crawler($pageData);

        $body = $crawler->filter("article.full-story div.full-text");

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body->children() as $bodyNode) {
            $node = new Crawler($bodyNode);

            if ($node->matches("ul#toc-elem, style, script, center, div.article-slud")) {
                continue;
            }
            if ($node->children("table")->count() !== 0) {
                continue;
            }
            if ($node->matches("div.ya-share2")) {
                break;
            }


            if ($bodyNode->nodeName === "#text" && !empty(trim($bodyNode->nodeValue, " \n\r\xC2\xA0"))) {
                if (empty($post->description)) {
                    $post->description = Helper::prepareString(trim($bodyNode->nodeValue));
                } else {
                    self::addText($post, trim($bodyNode->nodeValue));
                }
                continue;
            }


            if ($node->matches("div, p") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                $src = self::normalizeUrl($image->attr("src"));
                if ($post->image === null) {
                    $post->image = $src;
                } else {
                    self::addImage($post, $src);
                }
            }

            if ($node->matches("ul, ol") && !empty(trim($node->text(), "\xC2\xA0"))) {
                $node->children("li")->each(function (Crawler $liNode) use ($post) {
                    self::addText($post, $liNode->text());
                });
                continue;
            }

            if ($node->matches("blockquote") && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addQuote($post, $node->text());
                continue;
            }

            if ($node->matches("h3") && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addHeader($post, $node->text(), 3);
                continue;
            }
            if ($node->matches("h2") && !empty(trim($node->text(), "\xC2\xA0"))) {
                if (empty($post->description)) {
                    $post->description = Helper::prepareString($node->text());
                } else {
                    self::addHeader($post, $node->text(), 2);
                }

                continue;
            }


            if ($node->matches("div") && $node->filter("iframe")->count() !== 0) {
                $videoContainer = $node->filter("iframe");
                if ($videoContainer->count() !== 0) {
                    self::addVideo($post, $videoContainer->attr("src"));
                }
            }


            if (
                $node->matches("div, p")
                && !empty(trim($node->text(), "\xC2\xA0"))) {

                foreach ($node->getNode(0)->childNodes as $domDNode) {
                    $dNode = new Crawler($domDNode);
                    if ($domDNode->nodeName === "#text" && !empty(trim($domDNode->nodeValue, " \n\r\xC2\xA0"))) {
                        if (empty($post->description)) {
                            $post->description = Helper::prepareString(trim($domDNode->nodeValue));
                        } else {
                            self::addText($post, trim($domDNode->nodeValue));
                        }
                        continue;
                    }
                    $image = $dNode->filter("img");
                    if ($image->count() !== 0) {
                        $src = self::normalizeUrl($image->attr("src"));
                        if ($post->image === null) {
                            $post->image = $src;
                        } else {
                            self::addImage($post, $src);
                        }
                    }

                    if (!empty(trim($dNode->text(), "\xC2\xA0"))) {
                        if (empty($post->description)) {
                            $post->description = Helper::prepareString($dNode->text());
                        } else {
                            self::addText($post, $dNode->text());
                        }
                    }
                }


                continue;
            }
        }


        if (empty($post->description)) {
            throw new Exception("No text parsed: " . $url);
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
        $src = trim($content);
        if (mb_stripos($src, "//") === 0) {
            $src = "http:" . $src;
        } elseif ($src[0] === "/") {
            $src = self::ROOT_SRC . $src;
        }
        return preg_replace_callback('/[^\x21-\x7f]/', function ($match) {
            return rawurlencode($match[0]);
        }, $src);
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

