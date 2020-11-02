<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use DOMElement;
use DOMNode;
use Exception;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * News parser from site https://octagon.media/
 * @author jcshow
 */
class OctagonParser implements ParserInterface
{
    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const SITE_URL = 'https://octagon.media';

    /** @var array */
    protected static $parsedEntities = ['a', 'img', 'blockquote', 'iframe', 'video'];

    /** @var int */
    protected static $parsedCount = 0;

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
     * @param int $limit
     * 
     * @return array
     * @throws Exception
     */
    public static function getNewsData(int $limit = 100): array
    {
        /** Вырубаем нотисы */
        error_reporting(E_ALL & ~E_NOTICE);

        $lastFeaturesOid = '';
        $posts = [];
        while (self::$parsedCount < $limit) {
            /** Get API news list */
            $curl = Helper::getCurl();
            $parameters = [
                'type' => 'section_news', 'catcher' => '_load_prev', 'link' => '/novosti/'
            ];
            if (! empty($lastFeaturesOid)) {
                $parameters['last_features_oid'] = $lastFeaturesOid;
            }
            $curl->setPostParams($parameters);
            $newsList = $curl->post(static::SITE_URL . "/ajax.php");
            if (! $newsList) {
                throw new Exception('Can not get news data');
            }

            /** Parse news from API */
            $response = json_decode($newsList, true);
            $response = $response[0];
            $newsListCrawler = new Crawler($response['tpl']);
            $news = $newsListCrawler->filterXPath('//div[contains(@class,"card")]');

            foreach ($news as $item) {
                $post = self::getPostDetail($item);

                $posts[] = $post;
                self::$parsedCount++;
            }

            $lastFeaturesOid = $response['last_features_oid'];
        }

        usort($posts, function($a, $b) {
            $ad = $a->createDate;
            $bd = $b->createDate;
        
            if ($ad == $bd) {
                return 0;
            }
        
            return $ad > $bd ? -1 : 1;
        });

        return $posts;
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

        //Get title block
        $titleBlock = $itemCrawler->filterXPath('//div[contains(@class,"subscription")]//h2//a')->getNode(0);

        /** Get item detail link */
        $link = self::cleanUrl($titleBlock->getAttribute('href'));

        /** Get title */
        $title = self::cleanText($titleBlock->textContent);

        /** Detail page parser creation */
        $curl = Helper::getCurl();
        $curlResult = $curl->get($link);
        $crawler = new Crawler($curlResult);

        self::removeNodes($crawler, '
            //comment() | //br | //hr | //script | //style | //head | //header | //link | //meta | //noscript
        ');

        /** Get item datetime */
        $dateTimeBlock = $crawler->filterXPath('//div[contains(@class,"content")]//article//span[contains(@class,"date")]')->getNode(0);
        $dateTimeString = $dateTimeBlock->textContent;
        $dateTimeString = self::convertDateToEng($dateTimeString);
        $dateTimeString = preg_replace('/([\d]+\:[\d]+)/', date('Y') . " $1", $dateTimeString);
        $createdAt = new DateTime($dateTimeString, new DateTimeZone('Europe/Moscow'));
        $createdAt->setTimezone(new DateTimeZone('UTC'));
        $createdAt = $createdAt->format('c');
        
        self::removeNodes($crawler, '//div[contains(@class,"content")]//article//div[contains(@class,"share_rails")]');
        self::removeNodes($crawler, '//div[contains(@class,"content")]//article//span[contains(@class,"date")]');
        self::removeNodes($crawler, '//div[contains(@class,"content")]//article//span[contains(@class,"author")]');
        self::removeNodes($crawler, '//div[contains(@class,"content")]//article//h1');

        //Get image if exists
        $picture = null;
        $imageBlock = $crawler->filterXPath('//*[contains(@class,"image")]//img[1]')->getNode(0);
        if (! empty($imageBlock) === true) {
            $picture = self::cleanUrl($imageBlock->getAttribute('src'));
            if (! preg_match('/http[s]?/', $picture)) {
                $picture = UriResolver::resolve($picture, static::SITE_URL);
            }
            $imageBlock->parentNode->removeChild($imageBlock);
        }

        /** Get description */
        $descriptionBlock = $crawler->filterXpath('
            //div[contains(@class,"content")]
            //article
            //*[normalize-space(.//text())]
            //text()[not(.="")]
        ')->getNode(0);
        $description = self::cleanText($descriptionBlock->textContent);
        $descriptionBlock->parentNode->removeChild($descriptionBlock);

        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, $description, $createdAt, $link, $picture);

        $detailPage = $crawler->filterXPath('//div[contains(@class,"content")]//article')->getNode(0);

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

            if (! preg_match('/http[s]?/', $imageLink)) {
                $imageLink = UriResolver::resolve($imageLink, static::SITE_URL);
            }

            $imageLink = UriResolver::resolve($imageLink, static::SITE_URL);

            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, $node->getAttribute('alt'), $imageLink));
            return;
        }

        //Get videos from text
        if (self::isVideoType($node)) {
            $link = self::cleanUrl($node->getAttribute('src'));
            if ($link && $link !== '') {
                if ($ytVideoId = self::getYoutubeVideoId($link)) {
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_VIDEO, null, null, null, null, $ytVideoId));
                    return;
                }
                if (! preg_match('/http[s]?/', $link)) {
                    $link = UriResolver::resolve($link, static::SITE_URL);
                }
                if (preg_match('/vk\.com/', $link)) {
                    $link = preg_replace('/^(\/\/)(.*)/', 'https://$2', html_entity_decode($link));
                }
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_LINK, null, null, $link));
            }
            return;
        }

        //Get non-empty links from nodes
        if (self::isLinkType($node) && self::hasText($node)) {
            $link = self::cleanUrl($node->getAttribute('href'));
            if ($link && $link !== '') {
                if ($ytVideoId = self::getYoutubeVideoId($link)) {
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_VIDEO, null, null, null, null, $ytVideoId));
                    return;
                }
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
                if (strlen($post->description) >= strlen($textContent)) {
                    if (preg_match('/' . preg_quote($textContent, '/') . '/', $post->description)) {
                        return;
                    }
                }

                if (self::hasActualText($textContent) === true) {
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $textContent));
                }
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
            if (strlen($post->description) >= strlen($textContent)) {
                if (preg_match('/' . preg_quote($textContent, '/') . '/', $post->description)) {
                    return;
                }
            }

            if (self::hasActualText($textContent) === true) {
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $textContent));
            }
        } else {
            foreach($node->childNodes as $child) {
                self::parseNode($post, $child, $skipText);
            }
        }
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
        $transformedText = preg_replace('/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/m', '', $text);
        $transformedText = preg_replace('/\<script.*\<\/script>/m', '', $transformedText);
        $transformedText = mb_convert_encoding($transformedText, 'UTF-8', mb_detect_encoding($transformedText));
        $transformedText = html_entity_decode($transformedText);
        $transformedText = preg_replace('/^\p{Z}+|\p{Z}+$/u', '', htmlspecialchars_decode($transformedText));
        $transformedText = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/m', '', $transformedText);
        $transformedText = preg_replace('/\xe2\xa0\x80/m', '', $transformedText);
        return $transformedText;
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
        return preg_replace_callback('/[^\x21-\x7f]/', function ($match) {
            return rawurlencode($match[0]);
        }, $url);
    }

    /**
     * Function check if string has actual text
     * 
     * @param string|null $text
     * 
     * @return bool
     */
    protected static function hasActualText(?string $text): bool
    {
        return trim($text, "⠀ \t\n\r\0\x0B\xC2\xA0") !== '';
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
     * Function check if node is video
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isVideoType(DOMNode $node): bool
    {
        return 
            isset($node->tagName) === true && 
            in_array(
                $node->tagName,
                ['iframe', 'video']
            );
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
     * Function parse's out youtube video id from link
     * 
     * @param string $link
     * 
     * @return string|null
     */
    protected static function getYoutubeVideoId(string $link): ?string
    {
        preg_match(
            '/(youtu\.be\/|youtube\.com\/(watch\?(.*&)?v=|(embed|v)\/))([\w-]{11})/iu',
            $link,
            $matches
        );

        return $matches[5] ?? null;
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