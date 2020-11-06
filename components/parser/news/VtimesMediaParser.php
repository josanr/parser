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


class VtimesMediaParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://vtimes.media";

    const FEED_SRC = "/wp-json/wp/v2/posts/";
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

        $listSourcePath = self::ROOT_SRC . self::FEED_SRC . "?per_page=" . self::LIMIT;

        $listSourceData = $curl->get($listSourcePath, false);

        if (empty($listSourceData)) {
            throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
        }


        if (count($listSourceData) === 0) {
            throw new Exception("Пустой список новостей в ленте: " . $listSourcePath);
        }


        $counter = 0;
        foreach ($listSourceData as $item) {
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
     * @param array $postData
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(array $postData): NewsPost
    {
        $title = $postData["title"]["rendered"];
        $createDate = new DateTime($postData["modified_gmt"], new DateTimeZone("GMT"));
        $createDate->setTimezone(new DateTimeZone("UTC"));
        $original = $postData["link"];
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

        $image = $crawler->filter("div.post-content > div.thumbnail > img");
        if ($image->count() !== 0) {
            $post->image = self::normalizeUrl($image->attr("data-src"));
        }

        $body = $crawler->filter("div.post-content > div.post-innercont > div.editor > div.article-body");
        if ($body->count() === 0) {
            $body = $crawler->filter("div.post-content > div.post-innercont > div.editor");
        }
        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body->children() as $bodyNode) {
            $node = new Crawler($bodyNode);
            if ($node->matches("h3") && !empty(trim($node->text(), "\xC2\xA0"))) {
                if(empty($post->description)){
                    $post->description = Helper::prepareString($node->text());
                }else{
                    self::addText($post, $node->text());
                }
                continue;
            }
            if ($node->matches("h2") && !empty(trim($node->text(), "\xC2\xA0"))) {
                if(empty($post->description)){
                    $post->description = Helper::prepareString($node->text());
                }else{
                    self::addText($post, $node->text());
                }
                continue;
            }

            if ($node->matches("ul") && !empty(trim($node->text(), "\xC2\xA0"))) {
                $node->children("li")->each(function (Crawler $liNode) use ($post) {
                    self::addText($post, $liNode->text());
                });
                continue;
            }

            if ($node->matches("p") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                self::addImage($post, $image->attr("data-src"));
                continue;
            }
            if ($node->matches("figure") && $node->filter("img")->count() !== 0) {
                $image = $node->filter("img");
                self::addImage($post, $image->attr("data-src"));
                continue;
            }

            if ($node->matches("p") && !empty(trim($node->text(), "\xC2\xA0"))) {
                if(empty($post->description)){
                    $post->description = Helper::prepareString($node->text());
                }else{
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
        return preg_replace_callback('/[^\x21-\x7f]/', function ($match) {
            return rawurlencode($match[0]);
        }, $content);
    }
}

