<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use DOMElement;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

/**
 * News parser from site http://p1spb.ru/
 * @author jcshow
 */
class P1SpbParser implements ParserInterface
{
    /*run*/
    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const SITE_URL = 'http://p1spb.ru';

    /** @var array */
    protected static $parsedEntities = ['a', 'img', 'blockquote'];

    /**
     * @inheritDoc
     */
    public static function run(): array
    {
        return self::getNewsData();
    }

    /**
     * Function get fixed news count data
     * 
     * @return array
     * @throws Exception
     */
    public static function getNewsData(): array
    {
        /** Get RSS news list */
        $curl = Helper::getCurl();
        $newsList = $curl->get(static::SITE_URL . "/rss.xml");
        if (! $newsList) {
            throw new Exception('Can not get news data');
        }

        /** Parse news from RSS */
        $newsListCrawler = new Crawler($newsList);
        $news = $newsListCrawler->filterXPath('//item');

        $result = [];
        foreach ($news as $item) {
            try {
                $post = self::getPostDetail($item);
            } catch (Exception $e) {
                continue;
            }

            $result[] = $post;
        }

        return $result;
    }

    /**
     * Function get post detail data
     * 
     * @param DOMElement $item
     * 
     * @return NewPost
     */
    public static function getPostDetail(DOMElement $item): NewsPost
    {
        $itemCrawler = new Crawler($item);

        /** Get item detail link */
        $link = self::cleanUrl($itemCrawler->filterXPath('//link')->text());

        /** Get title */
        $title = self::cleanText($itemCrawler->filterXPath('//title')->text());

        /** Get item datetime */
        $createdAt = new DateTime($itemCrawler->filterXPath('//pubDate')->text());
        $createdAt->setTimezone(new DateTimeZone('UTC'));
        $createdAt = $createdAt->format('c');

        /** Get title */
        $description = self::cleanText($itemCrawler->filterXPath('//yandex:full-text')->text());

        $picture = '';
        $imageBlock = $itemCrawler->filterXPath('//enclosure')->getNode(0);
        if (! empty($imageBlock) === true) {
            $picture = self::cleanUrl($imageBlock->getAttribute('url'));
            $imageBlock->parentNode->removeChild($imageBlock);
        }

        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, $description, $createdAt, $link, $picture);

        $additionalImages = $itemCrawler->filterXPath('//enclosure');

        // parse additional images
        if (! empty($additionalImages) === true) {
            foreach ($additionalImages as $node) {
                $url = self::cleanUrl($node->getAttribute('url'));
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, null, $url));
            }
        }

        return $post;
    }

    /**
     * Function cleans text from bad symbols
     * 
     * @param string $text
     * 
     * @return string|null
     */
    protected static function cleanText(string $text): ?string
    {
        $transformedText = preg_replace('/\r\n/', '', $text);
        $transformedText = preg_replace('/\<script.*\<\/script>/', '', $transformedText);
        $transformedText = html_entity_decode($transformedText);
        return preg_replace('/^\p{Z}+|\p{Z}+$/u', '', htmlspecialchars_decode($transformedText));
    }

    /**
     * Function clean dangerous urls
     * 
     * @param string $url
     * 
     * @return string
     */
    protected static function cleanUrl(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_ENCODED|FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    }
} 