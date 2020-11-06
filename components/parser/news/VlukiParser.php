<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\nai4rus\DOMNodeRecursiveIterator;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use DOMNode;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class VlukiParser implements ParserInterface
{
    /*run*/
    public const USER_ID = 2;
    public const FEED_ID = 2;
    public const SITE_URL = 'https://www.vluki.ru';

    private int $microsecondsDelay;
    private int $pageCountBetweenDelay;

    public function __construct(int $microsecondsDelay = 1000000, int $pageCountBetweenDelay = 2)
    {
        $this->microsecondsDelay = $microsecondsDelay;
        $this->pageCountBetweenDelay = $pageCountBetweenDelay;
    }

    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
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
        $pageNumber = 1;
        $previewList = [];
        while (count($previewList) < $maxNewsCount) {
            $siteUrl = self::SITE_URL;
            $uri = "{$siteUrl}/news/page{$pageNumber}.html";

            try {
                $previewNewsPage = $this->getPageContent($uri);
            } catch (Throwable $exception) {
                if (count($previewList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsCrawler = new Crawler($previewNewsPage);

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//li/div[contains(@class, "news-obj")]/parent::li');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList, $uri) {
                $titleCrawler = $newsPreview->filterXPath('//h3/a');
                $uri = UriResolver::resolve($titleCrawler->attr('href'), $uri);

                $publishedAtString = $newsPreview->attr('data-pubdate');
                $timezone = new DateTimeZone('Europe/Moscow');
                $publishedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $publishedAtString, $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $previewCrawler = $newsPreview->filterXPath('//div[contains(@class, "news-obj__desc")]');
                $preview = $previewCrawler->count() >= 1 ? $previewCrawler->text() : null;

                $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $titleCrawler->text(), $preview);
            });

            $pageNumber++;
        }

        return array_slice($previewList, 0, $maxNewsCount);
    }


    private function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();
        $title = $previewNewsItem->getTitle();
        $description = $previewNewsItem->getPreview();
        $publishedAt = $previewNewsItem->getDateTime();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[@itemtype="http://schema.org/NewsArticle"]');

        try {
            $image = $newsPostCrawler->filterXPath('//div[contains(@class, "fancybox")]/a')->attr('href');
        } catch (InvalidArgumentException $exception) {
            $firstNewsImageCrawler = $newsPostCrawler->filterXPath('//img')->first();
            $image = $firstNewsImageCrawler->count() >= 1 ? $firstNewsImageCrawler->attr('src') : null;
        }

        if ($image !== null) {
            $image = UriResolver::resolve($image, $uri);
        }

        $newsPost = new NewsPost(self::class, $title, $description, $publishedAt->format('Y-m-d H:i:s'), $uri, $image);

        $sliderImageLinks = $newsPostCrawler->filterXPath('//div[contains(@class,"fotorama")]/a')->links();
        foreach ($sliderImageLinks as $imageLink) {
            $newsPost->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, null, $imageLink->getUri()));
        }

        foreach ($newsPostCrawler->filterXPath('//div[@itemprop="articleBody"]/*') as $item) {
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

    private function getPageContent(string $uri): string
    {
        $curl = Helper::getCurl();

        $result = $curl->get($uri);
        $responseInfo = $curl->getInfo();

        $httpCode = $responseInfo['http_code'] ?? null;

        if ($httpCode >= 200 && $httpCode < 400) {
            return $result;
        }

        throw new RuntimeException("Не удалось скачать страницу {$uri}, код ответа {$httpCode}");
    }

    private function parseDOMNode(DOMNode $node, PreviewNewsDTO $previewNewsItem): ?NewsPostItem
    {
        if (!empty($node->textContent) && $this->isQuoteType($node->parentNode)) {
            return new NewsPostItem(NewsPostItem::TYPE_QUOTE, $node->textContent);
        }

        $headingLevel = $this->getHeadingLevel($node->parentNode);
        if (!empty($node->textContent) && $headingLevel) {
            return new NewsPostItem(NewsPostItem::TYPE_HEADER, $node->textContent, null, null, $headingLevel);
        }

        if ($node instanceof DOMElement && $node->nodeName === 'a') {
            $link = $node->getAttribute('href');
            if (empty($link)) {
                return null;
            }

            return new NewsPostItem(NewsPostItem::TYPE_LINK, $node->textContent, null, $link);
        }

        if ($node instanceof DOMElement && $node->nodeName === 'iframe') {
            $iframeLink = $node->getAttribute('src');
            if (!str_contains($iframeLink, 'youtube')) {
                return null;
            }

            return new NewsPostItem(NewsPostItem::TYPE_VIDEO, null, null, null, null, basename($iframeLink));
        }

        if (!empty($node->textContent) && $this->isTextType($node)) {
            return new NewsPostItem(NewsPostItem::TYPE_TEXT, $node->textContent);
        }

        if ($node instanceof DOMElement && $this->isImageType($node)) {
            $imageLink = $node->getAttribute('src');
            if (empty($imageLink)) {
                return null;
            }
            $imageLink = UriResolver::resolve($imageLink, $previewNewsItem->getUri());

            return new NewsPostItem(NewsPostItem::TYPE_IMAGE, $node->getAttribute('alt'), $imageLink);
        }

        if (!empty($node->textContent)) {
            return new NewsPostItem(NewsPostItem::TYPE_TEXT, $node->textContent);
        }

        return null;
    }

    private function isImageType(DOMNode $node): bool
    {
        return $node->nodeName === 'img';
    }

    private function isTextType(DOMNode $node): bool
    {
        $isTextNode = $node->nodeName === '#text';
        $isLink = $node->nodeName === 'a';

        return $isTextNode
            && !$isLink
            && !$this->isQuoteType($node->parentNode)
            && !$this->getHeadingLevel($node->parentNode);
    }

    private function isQuoteType(DOMNode $node): bool
    {
        $quoteTags = [
            'q' => true,
            'blockquote' => true
        ];

        return $quoteTags[$node->nodeName] ?? false;
    }

    private function getHeadingLevel(DOMNode $node): ?int
    {
        $headingTags = ['h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6];

        return $headingTags[$node->nodeName] ?? null;
    }
}
