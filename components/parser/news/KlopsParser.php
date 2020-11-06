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


class KlopsParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://klops.ru";

    const FEED_SRC = "https://rss.klops.ru/rss";
    const LIMIT = 100;


    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $curl = Helper::getCurl();
        $posts = [];

        $listSourcePath = self::FEED_SRC;

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
        $createDate = new DateTime($postData->filterXPath("item/pubDate")->text());
        $createDate->setTimezone(new DateTimeZone("UTC"));
        $original = $postData->filter("link")->text();


        $imageUrl = null;
        $image = $postData->filter("enclosure");
        if ($image->count() !== 0) {
            $imageUrl = self::normalizeUrl($image->attr("url"));
        }

        $description = $postData->filter("description")->text();
        if (empty($description)) {
            $text = $postData->filterXPath("item/yandex:full-text");

            $sentences = preg_split('/(?<=[.?!])\s+(?=[а-я])/i', $text->text());
            $description = implode(" ", array_slice($sentences, 0, 3));
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

        $image = $crawler->filter("article div.content img");
        if ($image->count() !== 0) {
            $post->image = self::normalizeUrl($image->attr("src"));
        }


        $body = $crawler->filter("article div[itemprop='articleBody']");

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }


        foreach ($body->children() as $bodyPart) {
            $bodyContainer = new Crawler($bodyPart);

            if ($bodyContainer->matches("figure") && $bodyContainer->filter("img")->count() !== 0) {
                $image = $bodyContainer->filter("img");
                $src = $image->attr("src");
                if($post->image !== $src) {
                    self::addImage($post, $image->attr("src"));
                }

                $caption = $bodyContainer->filter("figcaption");
                if($caption->count() !== 0){
                    self::addText($post, $caption->text());
                }
            }
            if ($bodyContainer->matches("div.text")) {
                /** @var DOMNode $bodyNode */
                foreach ($bodyContainer->children() as $bodyNode) {
                    $node = new Crawler($bodyNode);
                    if ($node->matches("div") && !empty(trim($node->text(), "\xC2\xA0"))) {
                        $node->children("p")->each(function (Crawler $pnode) use ($post){
                            if (!empty(trim($pnode->text(), "\xC2\xA0"))) {
                                self::addText($post, $pnode->text());
                            }
                        });
                    }
                    if ($node->matches("p") && !empty(trim($node->text(), "\xC2\xA0"))) {
                        self::addText($post, $node->text());
                        continue;
                    }

                    if ($node->matches("blockquote") && !empty(trim($node->text(), "\xC2\xA0"))) {
                        self::addQuote($post, $node->text());
                        continue;
                    }

                    if ($node->matches("ol, ul") && !empty(trim($node->text(), "\xC2\xA0"))) {
                        $node->children("li")->each(function (Crawler $liNode) use ($post) {
                            if(!empty(trim($liNode->text(), "\xC2\xA0"))) {
                                self::addText($post, $liNode->text());
                            }
                        });
                        continue;
                    }
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

