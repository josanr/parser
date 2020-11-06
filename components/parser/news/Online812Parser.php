<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\nai4rus\DOMNodeRecursiveIterator;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMElement;
use DOMNode;
use InvalidArgumentException;
use linslin\yii2\curl\Curl;
use Rowbot\Punycode\Punycode;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class Online812Parser implements ParserInterface
{

    /*run*/
    public const USER_ID = 2;
    public const FEED_ID = 2;
    public const SITE_URL = 'https://online812.ru';

    private int $microsecondsDelay;
    private int $pageCountBetweenDelay;

    public function __construct(int $microsecondsDelay = 1000000, int $pageCountBetweenDelay = 3)
    {
        $this->microsecondsDelay = $microsecondsDelay;
        $this->pageCountBetweenDelay = $pageCountBetweenDelay;
    }

    public static function run(): array
    {
        $parser = new self(200000, 10);

        return $parser->parse(10, 100);
    }

    public function parse(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = $this->getPreviewList($minNewsCount, $maxNewsCount);

        $newsList = [];

        foreach ($previewList as $key => $previewNewsItem) {
            $newsList[] = $this->parseNewsPage($previewNewsItem);

            if ($key % $this->pageCountBetweenDelay === 0) {
                usleep($this->microsecondsDelay);
            }
        }

        return $newsList;
    }

    private function getPreviewList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $uriPreviewPage = "{$this->getSiteUri()}/gorod812/last/rss.xml";

        try {
            $previewNewsRSS = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsRSS);
        } catch (Throwable $exception) {
            if (count($previewList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//channel/item');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
            $title = $newsPreview->filterXPath('//title')->text();
            $uri = $newsPreview->filterXPath('//link')->text();
            $publishedAtString = $newsPreview->filterXPath('//pubDate')->text();
            $preview = $newsPreview->filterXPath('//description')->text();

            $publishedAt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s O', $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
        });

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    private function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();
        $title = $previewNewsItem->getTitle();
        $publishedAt = $previewNewsItem->getDateTime();
        $description = $previewNewsItem->getPreview();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class, "statya")]');

        try {
            $image = $newsPageCrawler->filterXPath('//div[contains(@class, "titlefoto")]/a/img')->attr('src');
        } catch (InvalidArgumentException $exception) {
            $firstNewsImageCrawler = $newsPostCrawler->filterXPath('//div[contains(@class, "maintext")]//img')->first();
            $image = $firstNewsImageCrawler->count() >= 1 ? $firstNewsImageCrawler->attr('src') : null;
        }

        if ($image !== null) {
            $image = UriResolver::resolve($image, $uri);
            $image = $this->punycodeEncode($image);
        }

        $publishedAtString = $publishedAt->format('Y-m-d H:i:s');

        $newsPost = new NewsPost(self::class, $title, $description, $publishedAtString, $uri, $image);

        $relatedNewsHeadingXPath = '//*[contains(text(),"Ранее по теме:")]/parent::p';
        $relatedNewsXPath = "{$relatedNewsHeadingXPath}/following-sibling::* | {$relatedNewsHeadingXPath}";

        $newsPostCrawler->filterXPath($relatedNewsXPath)->each(function (Crawler $crawler) {
            $domNode = $crawler->getNode(0);
            if ($domNode) {
                $domNode->parentNode->removeChild($domNode);
            }
        });

        foreach ($newsPostCrawler->filterXPath('//div[contains(@class, "maintext")]') as $item) {
            $nodeIterator = new DOMNodeRecursiveIterator($item->childNodes);

            foreach ($nodeIterator->getRecursiveIterator() as $node) {
                $newsPostItem = $this->parseDOMNode($node, $previewNewsItem);

                if ($newsPostItem) {
                    $newsPost->addItem($newsPostItem);
                }
            }
        }

        return $newsPost;
    }

    private function parseDOMNode(DOMNode $node, PreviewNewsDTO $previewNewsItem): ?NewsPostItem
    {
        if ($this->isQuoteType($node) && $this->hasText($node)) {
            return new NewsPostItem(NewsPostItem::TYPE_QUOTE, $node->textContent);
        }


        $headingLevel = $this->getHeadingLevel($node);
        if ($headingLevel && $this->hasText($node)) {
            return new NewsPostItem(NewsPostItem::TYPE_HEADER, $node->textContent, null, null, $headingLevel);
        }


        if ($this->isLink($node)) {
            $linkNode = $node;
            if ($node->nodeName === '#text' && $node->parentNode->nodeName === 'a') {
                $linkNode = $node->parentNode;
            }

            if (!$linkNode instanceof DOMElement) {
                return null;
            }

            $link = $this->punycodeEncode($linkNode->getAttribute('href'));

            if ($link && $link !== '' && filter_var($link, FILTER_VALIDATE_URL)) {
                $linkText = $this->hasText($linkNode) ? $linkNode->textContent : null;

                return new NewsPostItem(NewsPostItem::TYPE_LINK, $linkText, null, $link);
            }
        }


        if ($node instanceof DOMElement && $node->nodeName === 'iframe') {
            $iframeLink = $node->getAttribute('src');
            if (!str_contains($iframeLink, 'youtube')) {
                return null;
            }

            return new NewsPostItem(NewsPostItem::TYPE_VIDEO, null, null, null, null, basename($iframeLink));
        }


        $isImage = $this->isImageType($node);
        $isPicture = $this->isPictureType($node);
        if ($node instanceof DOMElement && ($isImage || $isPicture)) {
            $imageLink = $node->getAttribute('src');

            if ($isPicture) {
                $pictureCrawler = new Crawler($node->parentNode);
                $imgCrawler = $pictureCrawler->filterXPath('//img');

                if ($imgCrawler->count()) {
                    $imageLink = $imgCrawler->first()->attr('src');
                }
            }

            if ($imageLink === '') {
                return null;
            }

            $imageLink = UriResolver::resolve($imageLink, $previewNewsItem->getUri());
            $imageLink = $this->punycodeEncode($imageLink);

            return new NewsPostItem(NewsPostItem::TYPE_IMAGE, $node->getAttribute('alt'), $imageLink);
        }

        if ($this->hasText($node)) {
            return new NewsPostItem(NewsPostItem::TYPE_TEXT, $node->textContent);
        }

        return null;
    }

    private function parseHumanDateTime(string $dateTime, DateTimeZone $timeZone): DateTimeInterface
    {
        $formattedDateTime = mb_strtolower(trim($dateTime));
        $now = new DateTimeImmutable('now', $timeZone);

        if ($formattedDateTime === 'только что') {
            return $now;
        }

        if (str_contains($formattedDateTime, 'час') && str_contains($formattedDateTime, 'назад')) {
            $numericTime = preg_replace('/\bчас\b/u', '1', $formattedDateTime);
            $hours = preg_replace('/[^0-9]/u', '', $numericTime);
            return $now->sub(new DateInterval("PT{$hours}H"));
        }

        if (str_contains($formattedDateTime, 'вчера в ')) {
            $time = preg_replace('/[^0-9:]/u', '', $formattedDateTime);
            return DateTimeImmutable::createFromFormat('H:i', $time, $timeZone)->sub(new DateInterval("P1D"));
        }

        throw new RuntimeException("Не удалось распознать дату: {$dateTime}");
    }

    private function punycodeEncode(string $string): string
    {
        $encodedString = Punycode::encode($string);
        if(mb_substr($encodedString,-1) === '-'){
            $encodedString = mb_substr($encodedString,0, -1);
        }

        return $encodedString;
    }

    private function getJsonContent(string $uri): array
    {
        $curl = Helper::getCurl();

        $result = $curl->get($uri, false);
        $this->checkResponseCode($curl);

        return $result;
    }

    private function getPageContent(string $uri): string
    {
        $curl = Helper::getCurl();

        $result = $curl->get($uri);
        $this->checkResponseCode($curl);

        return $result;
    }

    private function checkResponseCode(Curl $curl): void
    {
        $responseInfo = $curl->getInfo();

        $httpCode = $responseInfo['http_code'] ?? null;
        $uri = $responseInfo['url'] ?? null;

        if ($httpCode < 200 || $httpCode >= 400) {
            throw new RuntimeException("Не удалось скачать страницу {$uri}, код ответа {$httpCode}");
        }
    }

    private function isPictureType(DOMNode $node): bool
    {
        return $node->nodeName === 'source' && $node->parentNode->nodeName === 'picture';
    }

    private function isImageType(DOMNode $node): bool
    {
        return $node->nodeName === 'img';
    }

    private function isLink(DOMNode $node): bool
    {
        return $node->nodeName === 'a' || ($node->nodeName === '#text' && $node->parentNode->nodeName === 'a');
    }

    private function hasText(DOMNode $node): bool
    {
        return trim($node->textContent) !== '';
    }

    private function isQuoteType(DOMNode $node): bool
    {
        if ($node->nodeName === '#text') {
            $node = $node->parentNode;
        }

        $quoteTags = [
            'q' => true,
            'blockquote' => true
        ];

        return $quoteTags[$node->nodeName] ?? false;
    }

    private function getHeadingLevel(DOMNode $node): ?int
    {
        if ($node->nodeName === '#text') {
            $node = $node->parentNode;
        }

        $headingTags = ['h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6];

        return $headingTags[$node->nodeName] ?? null;
    }

    private function getSiteUri(): string
    {
        return self::SITE_URL;
    }
}
