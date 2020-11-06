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


class KapitalNewsParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://www.kapital-news.com";

    const FEED_SRC = "/feed.xml";
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

        if(empty($listSourceData)){
            throw new Exception("Получен пустой ответ от источника списка новостей: ". $listSourcePath);
        }

        $crawler = new Crawler($listSourceData);
        $items = $crawler->filter("item");
        if($items->count() === 0){
            throw new Exception("Пустой список новостей в ленте: ". $listSourcePath);
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

        $original = self::normalizeUrl($postData->getNode(0)->childNodes->item(3)->textContent);
        $createDate = new DateTime($postData->getNode(0)->childNodes->item(5)->textContent);
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
        $warmUp = trim(explode("</script>", explode("warmupData", $pageData)[1])[0], " =");
        $warmUp = mb_substr($warmUp, 0, -2);

        $baseArr = json_decode($warmUp, true);
        $key = array_keys($baseArr["wixappsCoreWarmup"]["blog"]["items"]["Posts"])[0];
        $pageData = $baseArr["wixappsCoreWarmup"]["blog"]["items"]["Posts"][$key]["mediaText"]["text"];


        $crawler = new Crawler("<article>" . $pageData . "</article>");

        $body = $crawler->filter("article hatul");

        if ($body->count() === 0) {
            throw new Exception("Не найден блок новости в полученой странице: " . $url);
        }

        /** @var DOMNode $bodyNode */
        foreach ($body as $hatulNode) {
            foreach ($hatulNode->childNodes as $bodyNode) {
                $node = new Crawler($bodyNode);

                if(
                    mb_stripos(trim($node->text(), " \xC2\xA0"), "..") === 0
                    && mb_strlen(trim($node->text(), " \xC2\xA0")) < 3
                ){
                    break;
                }

                if ($bodyNode->nodeName === "#text" && !empty(trim($bodyNode->nodeValue, " .\n\r\xC2\xA0"))) {
                    if (empty($post->description)) {
                        $post->description = Helper::prepareString(trim($bodyNode->nodeValue));
                    } else {
                        self::addText($post, trim($bodyNode->nodeValue));
                    }
                    continue;
                }

                if ($node->matches("p, span") && !empty(trim($node->text(), " .\xC2\xA0"))) {

                    if (empty($post->description)) {
                        $post->description = Helper::prepareString($node->text());
                    } else {
                        self::addText($post, $node->text());
                    }
                    continue;
                }


                if ($node->matches("img") && $node->filter("img")->count() !== 0) {
                    $image = $node->filter("img");
                    $src = self::normalizeUrl("https:" . $image->attr("src"));
                    if ($post->image === null) {
                        $post->image = $src;
                    } else {
                        self::addImage($post, $src);
                    }
                    continue;
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


                if ($node->matches("div") && $node->filter("iframe")->count() !== 0) {
                    $videoContainer = $node->filter("iframe");
                    if ($videoContainer->count() !== 0) {
                        self::addVideo($post, $videoContainer->attr("src"));
                    }
                }
                if ( !empty(trim($node->text(), " .\xC2\xA0"))) {
                    if (empty($post->description)) {
                        $post->description = Helper::prepareString($node->text());
                    } else {
                        self::addText($post, $node->text());
                    }
                    continue;
                }
            }
        }
        if (empty($post->description)) {
            $post->description = $post->title;
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

