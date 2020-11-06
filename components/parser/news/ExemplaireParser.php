<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DomCrawler\Crawler;


class ExemplaireParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://exemplaire.ru";

    const FEED_SRC = "/news";
    const LIMIT = 100;

    const NEWS_PER_PAGE = 12;
    const EMPTY_DESCRIPTION = "empty";

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

            $listSourceData = $curl->get("$listSourcePath");
            if (empty($listSourceData)) {
                throw new Exception("Получен пустой ответ от источника списка новостей: " . $listSourcePath);
            }
            $crawler = new Crawler($listSourceData);
            $content = $crawler->filter(".view-content div.row div.grid_col");
            if ($content->count() === 0) {
                throw new Exception("Пустой список новостей в ленте: " . $listSourcePath);
            }
            foreach ($content as $newsItem) {
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
     * @param Crawler $entityData
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(Crawler $entityData): NewsPost
    {
        $imageUrl = null;
        $image = $entityData->filter("img");
        if ($image->count() != 0) {
            $imageUrl = $image->attr("src");
        }

        $title = $entityData->filter("h3")->text();
        $description = $entityData->filter(".views-field-field-anons")->text();

        $dateContainer = $entityData->filter("span.date-display-single");
        $createDate = new DateTime($dateContainer->attr("content"));
        $createDate->setTimezone(new DateTimeZone("UTC"));
        $original = self::ROOT_SRC . $entityData->filter("h3 a")->attr("href");

        if (empty($description)) {
            $description = self::EMPTY_DESCRIPTION;
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
    private static function inflatePostContent(NewsPost $post, $curl)
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

        $content = $crawler->filter("div.main-container section");

        $picHolder = $crawler->filter("div.field-type-image div.field-item div.tgf-slides img");
        if($picHolder->count() !== 0){
            $picHolder->each(function (Crawler $picNode) use ($post){
                if($post->image === null){
                    $post->image = self::normalizeUrl($picNode->attr("src"));
                }else{
                    self::addImage($post, self::normalizeUrl($picNode->attr("src")));
                }
            });

        }


        $body = $content->filter("div.field-name-body div.field-item")->children("p, blockquote");
        if($body->count() === 0){
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }
        $body->each(function (Crawler $node) use ($post) {


            if ($node->matches("blockquote") && !empty(trim($node->text(), "\xC2\xA0"))) {
                self::addQuote($post, $node->text());
                return;
            }
            if($node->matches("p")){
                if ($node->children("img")->count() != 0) {
                    self::addImage($post, self::ROOT_SRC . $node->children("img")->attr("src"));
                } elseif(!empty(trim($node->text(), "\xC2\xA0"))){
                    if (empty($post->description)) {
                        $post->description = Helper::prepareString($node->text());
                    }else{
                        self::addText($post, $node->text());
                    }
                }
            }


        });

        $body = $content->filter("div.field-name-field-autor-foto div.field-item");
        if($body->count() !== 0){
            self::addText($post, $body->text());
        }
        $body = $content->filter("div.field-name-field-autors div.field-item");
        if($body->count() !== 0){
            self::addText($post, $body->text());
        }
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
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_IMAGE,
                null,
                $content,
                null,
                1,
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
}

