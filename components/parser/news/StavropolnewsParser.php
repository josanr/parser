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
use Symfony\Component\DomCrawler\UriResolver;

/**
 * News parser from site https://stavropolnews.ru/
 * @author jcshow
 */
class StavropolnewsParser implements ParserInterface
{
    /*run*/
    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const SITE_URL = 'https://stavropolnews.ru';

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
     * Function get news data
     * 
     * @return array
     * @throws Exception
     */
    public static function getNewsData(): array
    {
        /** Вырубаем нотисы */
        error_reporting(E_ALL & ~E_NOTICE);

        $posts = [];

        /** Get page */
        $curl = Helper::getCurl();
        $page = $curl->get(static::SITE_URL . "/news");
        if (! $page) {
            throw new Exception('Can not get news data');
        }

        $pageParser = new Crawler($page);
        $newsList = $pageParser->filter('.view-news .views-table tbody')->getNode(0);

        //Somewhy, parser can't get html directly from page
        $parser = new Parser($newsList->ownerDocument->saveHTML($newsList), true);
        $pageBody = $parser->document->getBody();

        foreach ($pageBody->find('tr') as $item) {
            $posts[] = self::getPostDetail($item);
        }

        return $posts;
    }

    /**
     * Function get post detail data
     * 
     * @param Element $item
     * 
     * @return NewPost
     */
    public static function getPostDetail(Element $item): NewsPost
    {
        //Get block with title and link
        $titleBlock = $item->findOne('h3');

        /** Get item detail link */
        $link = self::cleanUrl(UriResolver::resolve($titleBlock->findOne('a')->getAttribute('href'), static::SITE_URL));

        /** Get title */
        $title = self::cleanText($titleBlock->asText());

        /** Detail page parser creation */
        $curl = Helper::getCurl();
        $curlResult = $curl->get($link);
        $crawler = new Crawler($curlResult);

        /** Get item datetime */
        $dateBlock = $crawler->filterXPath('//time[contains(@class, "article_header_date")]')->getNode(0);
        $createdAt = DateTime::createFromFormat('d-m-y H:i:s', $dateBlock->textContent, new DateTimeZone('Europe/Moscow'));
        $createdAt->setTimezone(new DateTimeZone('UTC'));
        $createdAt = $createdAt->format('c');

        /** Get description */
        $descriptionBlock = $crawler->filterXPath('//meta[@name="description"]')->getNode(0);
        $description = self::cleanText($descriptionBlock->getAttribute('content'));

        //Get image if exists
        $picture = null;
        $imageBlock = $crawler->filterXpath('//div[contains(@class, "article_illustration")]//img')->getNode(0);
        if (! empty($imageBlock) === true) {
            $src = self::cleanUrl($imageBlock->getAttribute('src'));
            $picture = UriResolver::resolve($src, static::SITE_URL);
        }

        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, $description, $createdAt, $link, $picture);

        $articleLeadBlock = $crawler->filterXpath('//div[contains(@class, "article_lead")]')->getNode(0);
        if (! empty($articleLeadBlock->textContent) === true) {
            $lead = self::cleanText($articleLeadBlock->textContent);
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_HEADER, $lead, null, null, 2));
        }

        $detailPage = $crawler->filterXPath('//div[@id="article_full_text"]')->getNode(0);

        self::removeNodes($crawler, '//div[contains(@class, "article_illustration")]');
        self::removeNodes($crawler, '//div[contains(@class, "full_news")]');
        self::removeNodes($crawler, '//div[contains(@class, "field-type-taxonomy-term-reference")]', 1);
        self::removeNodes($crawler, '//center');
        self::removeNodes($crawler, '//script');
        self::removeNodes($crawler, '//div[contains(@class, "clear")]');
        self::removeNodes($crawler, '//div[contains(@class, "yashare-auto-init")]');

        // parse detail page for texts
        foreach ($detailPage->childNodes as $node) {
            self::parseNode($post, $node);
        }

        return $post;
    }
    
    /**
     * Function parse single children of full text block and appends NewsPostItems founded
     * 
     * @param NewsPost $post
     * @param DOMNode $node
     * @param bool $skipText
     * 
     * @return void
     */
    public static function parseNode(NewsPost $post, DOMNode $node, bool $skipText = false): void
    {
        //Get non-empty quotes from nodes
        if (self::isQuoteType($node) && self::hasText($node)) {
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_QUOTE, $node->textContent));
            return;
        }

        //Get non-empty images from nodes
        if (self::isImageType($node)) {
            $imageLink = self::cleanUrl($node->getAttribute('src'));

            if ($imageLink === '') {
                return;
            }

            $imageLink = UriResolver::resolve($imageLink, static::SITE_URL);
            
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, $node->getAttribute('alt'), $imageLink));
            return;
        }

        //Get non-empty links from nodes
        if (self::isLinkType($node) && self::hasText($node)) {
            $link = self::cleanUrl($node->getAttribute('href'));
            if ($link && $link !== '') {
                if (! preg_match('/http[s]?/', $link)) {
                    $link = UriResolver::resolve($link, static::SITE_URL);
                }
                if (filter_var($link, FILTER_VALIDATE_URL)) {
                    $linkText = self::hasText($node) ? $node->textContent : null;
                    $linkText = self::cleanText($linkText);
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_LINK, $linkText, null, $link));
                }
            }
            return;
        }

        //Get direct text nodes
        if (self::isText($node)) {
            if ($skipText === false && self::hasText($node)) {
                $textContent = self::cleanText($node->textContent);
                if (empty(trim($textContent)) === true) {
                    return;
                }
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $textContent));
            }
            return;
        }

        //Check if some required to parse entities exists inside node
        $needRecursive = false;
        foreach (self::$parsedEntities as $entity) {
            if ($node->getElementsByTagName("$entity")->length > 0) {
                $needRecursive = true;
                break;
            }
        }

        //Get entire node text if we not need to parse any special entities, go recursive otherwise
        if ($skipText === false && $needRecursive === false) {
            $textContent = self::cleanText($node->textContent);
            if (empty(trim($textContent)) === true) {
                return;
            }
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $textContent));
        } else {
            foreach($node->childNodes as $child) {
                self::parseNode($post, $child, $skipText);
            }
        }
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
     * @param int|null $count
     * 
     * @return void
     */
    protected static function removeNodes(Crawler $crawler, string $xpath, ?int $count = null): void
    {
        $crawler->filterXPath($xpath)->each(function (Crawler $crawler, int $key) use ($count) {
            if ($count !== null && $key === $count) {
                return;
            }
            $domNode = $crawler->getNode(0);
            if ($domNode) {
                $domNode->parentNode->removeChild($domNode);
            }
        });
    }
} 