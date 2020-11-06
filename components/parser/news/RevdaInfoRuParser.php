<?php


namespace app\components\parser\news;

use app\components\helper\aayaami\DOMNodeRecursiveIterator;
use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Symfony\Component\DomCrawler\Crawler;

class RevdaInfoRuParser implements ParserInterface
{
    /*run*/
    const USER_ID = 2;
    const FEED_ID = 2;


    public static function run(): array
    {
        $articleListLink = 'https://www.revda-info.ru/feed/';

        $articleSelector = '//item';
        $titleSelector = '//title';
        $descriptionSelector = '//description';
        $dateSelector = '//pubDate';
        $articleLinkSelector = '//link';
        $articleContentSelector = '//content:encoded';

        $curl = Helper::getCurl();

        $articleList = $curl->get($articleListLink);

        $articleListCrawler = new Crawler($articleList);

        $articles = $articleListCrawler->filterXPath($articleSelector);

        $posts = [];

        foreach ($articles as $article) {
            $articleRssCrawler = new Crawler($article);

            $title = $articleRssCrawler->filterXPath($titleSelector)->text();
            $description = $articleRssCrawler->filterXPath($descriptionSelector)->text();
            $date = $articleRssCrawler->filterXPath($dateSelector)->text();
            $articleLink = $articleRssCrawler->filterXPath($articleLinkSelector)->text();
            
            $articleRssContentCrawler = (new Crawler($articleRssCrawler->filterXPath($articleContentSelector)->html()))->filterXPath('//body')->children();

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $articleLink,
                null
            );

            $lastText = '';
            foreach ($articleRssContentCrawler as $item) {
                $nodeIterator = new DOMNodeRecursiveIterator($item->childNodes);
                foreach ($nodeIterator->getRecursiveIterator() as $node) {
                    $trimmedNodeValue = trim($node->nodeValue);
                    $trimmedNodeValue = strlen($trimmedNodeValue) > 2 ? $trimmedNodeValue : '';
                    $targetNode = $articleRssContentCrawler->filterXPath('//*[text()="' . $node->nodeValue . '"]');
                    if (get_class($node) === 'DOMElement' && ($node->tagName === 'img' || $node->tagName === 'iframe')) {
                        $targetNode = new Crawler($node);
                    }
                    if ($targetNode->count() === 0) {
                        continue;
                    }
                    $targetNodeText = $targetNode->text();
                    $nodeName = $targetNode->nodeName();
                    $count = 0;
                    $quoteChars = ['‐', '−', '–', '—'];
                    str_replace($quoteChars, '', substr($targetNodeText, 0, 3), $count);

                    $isQuote = $count !== 0;
                    $isExternalLink = $nodeName === 'a' && strpos($targetNode->attr('href'), 'http') !== false;
                    $isImageLink = $nodeName === 'img';
                    $isHeader = in_array($nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']);
                    $isVideo = $nodeName === 'iframe';
                    $isText = !$isQuote && !$isExternalLink && !$isImageLink && !$isHeader && !$isVideo;

                    if ($isImageLink) {
                        $src = $targetNode->attr('src');
                        if(!$post->image) {
                            $post->image = $src;
                        }
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_IMAGE,
                                $targetNode->attr('alt'),
                                $targetNode->attr('src'),
                                null,
                                null,
                                null
                            ));
                    }

                    if (!Helper::prepareString($trimmedNodeValue)) {
                        continue;
                    }

                    if ($isText) {
                        $lastText = $lastText . $trimmedNodeValue;
                    }

                    if (!$isText && $lastText !== '') {
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_TEXT,
                                $lastText,
                                null,
                                null,
                                null,
                                null
                            ));
                        $lastText = '';
                    }

                    if ($isHeader) {
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_HEADER,
                                $trimmedNodeValue,
                                null,
                                null,
                                (int) filter_var($targetNode->nodeName(), FILTER_SANITIZE_NUMBER_INT),
                                null
                            ));
                    }

                    if ($isVideo) {
                        $src = $targetNode->attr('src');
                        if (strpos($src, 'youtube') !== false) {
                            $post->addItem(
                                new NewsPostItem(
                                    NewsPostItem::TYPE_VIDEO,
                                    null,
                                    null,
                                    null,
                                    null,
                                    basename(parse_url($targetNode->attr('src'), PHP_URL_PATH))
                                ));
                        }
                    }

                    if ($isQuote) {
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_QUOTE,
                                $trimmedNodeValue,
                                null,
                                null,
                                null,
                                null
                            )
                        );
                    }

                    if ($isExternalLink) {
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_LINK,
                                $trimmedNodeValue,
                                null,
                                $targetNode->attr('href'),
                                null,
                                null
                            ));
                    }

                }
            }

            $posts[] = $post;
        }
        
        return $posts;
    }
}