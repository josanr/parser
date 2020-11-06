<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class PobedaAksayRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://pobeda-aksay.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $uriPreviewPage = UriResolver::resolve('/feed/', $this->getSiteUrl());
        $this->getPageContent($uriPreviewPage);
        $this->getCurl()->setHeader('Cookie', 'beget=begetok');
        $previewNewsDTOList = [];

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            if (count($previewNewsDTOList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//item');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList, $maxNewsCount) {
            if (count($previewNewsDTOList) >= $maxNewsCount) {
                return;
            }

            $title = $newsPreview->filterXPath('//title')->text();
            $uri = $newsPreview->filterXPath('//link')->text();

            $publishedAtString = $newsPreview->filterXPath('//pubDate')->text();
            $publishedAt = DateTimeImmutable::createFromFormat(DATE_RFC1123, $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $image = null;
            $imageCrawler = $newsPreview->filterXPath('//enclosure');
            if ($this->crawlerHasNodes($imageCrawler)) {
                $image = $imageCrawler->attr('url') ?: null;
            }

            $previewNewsDTOList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, null, $image);
        });

        $previewNewsDTOList = array_slice($previewNewsDTOList, 0, $maxNewsCount);

        return $previewNewsDTOList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);

        $contentCrawler = $newsPageCrawler->filter('.post .prev-post');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"h5ab-print-button-container")]');

        $image = null;

        $mainImageCrawler = $contentCrawler->filterXPath('//img[1]/parent::a[contains(@class,"highslide-image")]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('href');
            $this->removeDomNodes($contentCrawler, '//img[1]/parent::a[contains(@class,"highslide-image")]');
        }

        if (!$image) {
            $mainImageCrawler = $contentCrawler->filterXPath('//img[1]');
            if ($this->crawlerHasNodes($mainImageCrawler)) {
                $image = $mainImageCrawler->attr('src');
                $this->removeDomNodes($contentCrawler, '//img[1]');
            }
        }

        if ($image !== null && $image !== '') {
            $image = $this->encodeUri(UriResolver::resolve($image, $this->getSiteUrl()));
            $previewNewsDTO->setImage($image);
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    protected function getImageLinkFromNode(DOMElement $node): string
    {
        $nodeSrcSet = $node->getAttribute('srcset');
        if ($nodeSrcSet) {
            $images = array_map('trim', explode(',', $nodeSrcSet));
            $regex = "/\s\d+([wh])$/";
            usort($images, static function (string $a, string $b) use ($regex) {
                $clearVar = static function (string $var) use ($regex): int {
                    preg_match($regex, $var, $var);
                    return (int)trim($var[0], ' wh');
                };
                $aInt = $clearVar($a);
                $bInt = $clearVar($b);

                if ($aInt === $bInt) {
                    return 0;
                }

                return $bInt > $aInt ? 1 : -1;
            });

            return preg_replace($regex, '', $images[0]);
        }

        return $node->getAttribute('src');
    }

    protected function purifyNewsPostContent(Crawler $contentCrawler): void
    {
        $this->removeDomNodes($contentCrawler, '//a[starts-with(@href, "javascript")]');
        $this->removeDomNodes($contentCrawler, '//script | //video | //style | //form');
    }
}
