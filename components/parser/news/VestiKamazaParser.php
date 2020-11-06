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
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class VestiKamazaParser implements ParserInterface
{

    /*run*/
    public const USER_ID = 2;
    public const FEED_ID = 2;
    public const SITE_URL = 'https://vestikamaza.ru';

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

    public function parseHumanDateTime(string $dateTime, DateTimeZone $timeZone): DateTimeInterface
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

    private function getPreviewList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $pageNumber = 0;
        $previewList = [];
        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = "{$this->getSiteUri()}/udata/custom/newslist/{$pageNumber}/all/0/0/0/0/1/0.json?expire=3600";

            try {
                $previewNewsPagination = $this->getJsonContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsPagination['cont']);
            } catch (Throwable $exception) {
                if (count($previewList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//div[contains(@class,"text_hot")]');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $titleCrawler = $newsPreview->filterXPath('//div[contains(@class, "zag")]/parent::a');
                $uri = UriResolver::resolve($titleCrawler->attr('href'), "{$this->getSiteUri()}/posts/");
                $publishedAtString = $newsPreview->filterXPath('//div[contains(@class, "time")]/a')->text();


                $originTimezone = new DateTimeZone('Europe/Moscow');
                $publishedAt = DateTimeImmutable::createFromFormat('H:i \/ d.m.Y', $publishedAtString, $originTimezone);
                if (!$publishedAt) {
                    $publishedAt = $this->parseHumanDateTime($publishedAtString, $originTimezone);
                }

                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $titleCrawler->text());
            });

            $pageNumber++;
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        usort($previewList, function (PreviewNewsDTO $firstNews, PreviewNewsDTO $secondNews) {
            $firstNewsTimestamp = $firstNews->getDateTime()->getTimestamp();
            $secondNewsTimestamp = $secondNews->getDateTime()->getTimestamp();

            if ($firstNewsTimestamp === $secondNewsTimestamp) {
                return 0;
            }

            return ($firstNewsTimestamp > $secondNewsTimestamp) ? -1 : 1;
        });

        return $previewList;
    }

    private function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();
        $title = $previewNewsItem->getTitle();
        $publishedAt = $previewNewsItem->getDateTime();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class, "news_cont")]/div[contains(@class, "cont")]');

        try {
            $image = $newsPageCrawler->filterXPath('//meta[@property="og:image"]')->attr('content');
        } catch (InvalidArgumentException $exception) {
            $firstNewsImageCrawler = $newsPostCrawler->filterXPath('//img')->first();
            $image = $firstNewsImageCrawler->count() >= 1 ? $firstNewsImageCrawler->attr('src') : null;
        }

        if ($image !== null) {
            $image = UriResolver::resolve($image, $uri);
        }

        $description = $newsPageCrawler->filterXPath('//meta[@property="og:description"]')->attr('content');

        $newsPost = new NewsPost(self::class, $title, $description, $publishedAt->format('Y-m-d H:i:s'), $uri, $image);

        $contentCrawler = $newsPostCrawler->children()->reduce(function (Crawler $crawler, $i) {
            $class = $crawler->attr('class') ?? '';
            return $crawler->nodeName() !== 'style' && !str_contains($class, 'teg') && !str_contains($class,
                    'ikon_stat');
        });

        foreach ($contentCrawler as $item) {
            $nodeIterator = new DOMNodeRecursiveIterator($item->childNodes);

            foreach ($nodeIterator as $node) {
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


        if ($node instanceof DOMElement && ($node->nodeName === 'a' || $node->nodeName === '#text')) {
            $linkNode = $node;
            if ($node->nodeName === '#text' && $node->parentNode->nodeName === 'a') {
                $linkNode = $node->parentNode;
            }

            $link = $linkNode->getAttribute('href');
            if (empty($link)) {
                return null;
            }

            $linkText = $this->hasText($linkNode) ? $linkNode->textContent : null;

            return new NewsPostItem(NewsPostItem::TYPE_LINK, $linkText, null, $link);
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

                if($imgCrawler->count()){
                    $imageLink = $imgCrawler->first()->attr('src');
                }
            }

            if ($imageLink === '') {
                return null;
            }

            $imageLink = UriResolver::resolve($imageLink, $previewNewsItem->getUri());
            return new NewsPostItem(NewsPostItem::TYPE_IMAGE, $node->getAttribute('alt'), $imageLink);
        }

        if ($this->hasText($node)) {
            return new NewsPostItem(NewsPostItem::TYPE_TEXT, $node->textContent);
        }

        return null;
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
