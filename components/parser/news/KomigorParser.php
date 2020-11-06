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
use lanfix\parser\Parser;
use lanfix\parser\src\Element;
use Symfony\Component\DomCrawler\Crawler;

/**
 * News parser from site https://komigor.com/
 * @author jcshow
 */
class KomigorParser implements ParserInterface
{

    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const SITE_URL = 'https://komigor.com';

    public const BASE_YOUTUBE_VIDEO_URL = 'https://www.youtube.com/watch?v=';

    /** @var array */
    protected static $projects = [
        1, 3, 2, 27
    ];

    /** @var array */
    protected static $posts = [];

    /**
     * @inheritDoc
     */
    public static function run(): array
    {
        return self::getNewsData();
    }

    /**
     * Function get news data
     * 
     * @return array
     * @throws Exception
     */
    public static function getNewsData(): array
    {
        /** Вырубаем нотисы */
        error_reporting(E_ALL & ~E_NOTICE);

        //Parse last page of each news project
        foreach (self::$projects as $project) {
            /** Get page */
            $curl = Helper::getCurl();
            $page = $curl->get(static::SITE_URL . "/novosti?project_id=$project");
            if (! $page) {
                continue;
            }

            /** Parse news block from page */
            $pageCrawler = new Parser($page, true);
            $pageBody = $pageCrawler->document->getBody();
            $pageBlock = $pageBody->find('.content-items-container .item');

            foreach ($pageBlock as $item) {
                self::createPost($item);
            }
        }

        usort(self::$posts, function($a, $b) {
            $ad = $a->createDate;
            $bd = $b->createDate;
        
            if ($ad == $bd) {
                return 0;
            }
        
            return $ad > $bd ? -1 : 1;
        });

        return self::$posts;
    }

    /**
     * Function create post from item
     * 
     * @param Element $content
     * 
     * @return void
     */
    public static function createPost(Element $content): void
    {
        //Get youtube container
        $ytContainer = $content->findOne('.youtube-play');
        $videoId = $ytContainer->getAttribute('data-id');

        /** Get title */
        $title = self::cleanText($content->findOne('.item-name h3')->asText());

        /** Get picture */
        $imageUrl = '';
        $imageNode = $ytContainer->findOne('img');
        if (! empty($imageNode) === true) {
            $imageUrl = self::cleanUrl($imageNode->getAttribute('src') ?: '');
        }

        //Get item description
        $description = '';
        $subtitleNode = $content->findOne('.item-sub-name');
        if (! empty($subtitleNode) === true) {
            $description = self::cleanText($subtitleNode->asText());
            $title .= ' ' . $description;

            //parse string for date published
            $stringToDate = preg_replace('/ года/', '', $description);
            $stringToDate = self::convertDateToEng($stringToDate);
            $createdAt = DateTime::createFromFormat('d F Y H:i:s', $stringToDate . ' ' . date('H:i:s'));
            $createdAt->setTimezone(new DateTimeZone('UTC'));
            $createdAt = $createdAt->format('c');
        } else {
            $ytLink = static::BASE_YOUTUBE_VIDEO_URL . $videoId;
            $description = $title;

            /** Detail page parser creation */
            $curl = Helper::getCurl();
            $curlResult = $curl->get($ytLink);

            /** Parse detail video page */
            $pageCrawler = new Crawler($curlResult);
            $dateBlock = $pageCrawler->filterXPath('//meta[contains(@itemprop, "datePublished")]')->getNode(0);
            
            //If video unaccessable
            if (empty($dateBlock) == true) {
                return;
            }
            $createdAt = new DateTime($dateBlock->getAttribute('content') . date('H:i:s'));
            $createdAt->setTimezone(new DateTimeZone('UTC'));
            $createdAt = $createdAt->format('c');
        }

        /** Get item fictive detail link https://i.imgur.com/R8T0fp9.png */
        $link = self::cleanUrl(static::SITE_URL . "/novosti?title=" . urlencode($title));

        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, $description, $createdAt, $link, $imageUrl);

        $post->addItem(new NewsPostItem(NewsPostItem::TYPE_VIDEO, null,
                null, null, null, $videoId));

        self::$posts[] = $post;
    }

    /**
     * Function converts russian months to eng
     * 
     * @param string $date
     * 
     * @return string
     */
    protected static function convertDateToEng(string $date): string
    {
        $date = mb_strtolower($date);

        $ruMonth = [
            'января',
            'февраля',
            'марта',
            'апреля',
            'мая',
            'июня',
            'июля',
            'августа',
            'сентября',
            'октября',
            'ноября',
            'декабря'
        ];

        $enMonth = [
            'January',
            'February',
            'March',
            'April',
            'May',
            'June',
            'July',
            'August',
            'September',
            'October',
            'November',
            'December'
        ];

        $date = str_replace($ruMonth, $enMonth, $date);

        return $date;
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

    /**
     * Function check if node text content not empty
     * 
     * @param DOMNode $node
     * 
     * @return bool
     */
    protected static function hasActualText(DOMNode $node): bool
    {
        return trim($node->textContent) !== '';
    }

    /**
     * Function check if node text content not empty
     * 
     * @param DOMNode $node
     * 
     * @return bool
     */
    protected static function hasText(DOMNode $node): bool
    {
        return trim($node->textContent) !== '';
    }

    /**
     * Function check if node is <p></p>
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isParagraphType(DOMNode $node): bool
    {
        return isset($node->tagName) === true && $node->tagName === 'p';
    }

    /**
     * Function check if node is quote
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isQuoteType(DOMNode $node): bool
    {
        return isset($node->tagName) === true && in_array($node->tagName, ['blockquote']);
    }

    /**
     * Function check if node is <a></a>
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isLinkType(DOMNode $node): bool
    {
        return isset($node->tagName) === true && $node->tagName === 'a';
    }

    /**
     * Function check if node is image
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isImageType(DOMNode $node): bool
    {
        return isset($node->tagName) === true && $node->tagName === 'img';
    }

    /**
     * Function check if node is #text
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isText(DOMNode $node): bool
    {
        return $node->nodeName === '#text';
    }

    /**
     * Function remove useless specified nodes
     * 
     * @param Crawler $crawler
     * @param string $xpath
     * 
     * @return void
     */
    protected static function removeNodes(Crawler $crawler, string $xpath): void
    {
        $crawler->filterXPath($xpath)->each(function (Crawler $crawler) {
            $domNode = $crawler->getNode(0);
            if ($domNode) {
                $domNode->parentNode->removeChild($domNode);
            }
        });
    }

    /**
     * Function returns heading level
     * 
     * @param DOMNode
     * @return int|null
     */
    protected static function getHeadingLevel(DOMNode $node): ?int
    {
        $headingTags = ['h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6];

        return $headingTags[$node->nodeName] ?? null;
    }
} 