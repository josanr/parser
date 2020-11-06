<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Symfony\Component\DomCrawler\Crawler;

class VestiKaliningradRuParser implements ParserInterface
{
    /*run*/
    const USER_ID = 2;
    const FEED_ID = 2;


    public static function run(): array
    {
        $articleListLink = 'http://vesti-kaliningrad.ru/feed/';

        $articleSelector = '//item';
        $titleSelector = '//title';
        $descriptionSelector = '//description';
        $dateSelector = '//pubDate';
        $articleLinkSelector = '//link';
        $imageLinkSelector = '//enclosure';
        $textSelector = '//content:encoded';

        $curl = Helper::getCurl();

        $articleList = $curl->get($articleListLink);

        $articleListCrawler = new Crawler($articleList);

        $articles = $articleListCrawler->filterXPath($articleSelector);

        $posts = [];

        foreach ($articles as $article) {
            $articleRssCrawler = new Crawler($article);

            $title = $articleRssCrawler->filterXPath($titleSelector)->text();
            $description = $articleRssCrawler->filterXPath($descriptionSelector)->text();
            $text = $articleRssCrawler->filterXPath($textSelector)->text();
            $date = $articleRssCrawler->filterXPath($dateSelector)->text();
            $articleLink = $articleRssCrawler->filterXPath($articleLinkSelector)->text();
            
            $unprocessedUrl = $articleRssCrawler->filterXPath($imageLinkSelector)->attr('url');
            $lastUrlPart = basename($unprocessedUrl);
            $encodedLastUrlPart = urlencode($lastUrlPart);
            $imageLink = str_replace($lastUrlPart, $encodedLastUrlPart, $unprocessedUrl);

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $articleLink,
                $imageLink
            );

            $articleHtml = $curl->get($articleLink);
            $articleHtmlCrawler = new Crawler($articleHtml);
            $iframeForYoutubeVideo = $articleHtmlCrawler->filterXPath('//iframe');
            if ($iframeForYoutubeVideo->count() !== 0) {
                $youtubeVideoId = basename($iframeForYoutubeVideo->attr('src'));
                $post->addItem(
                    new NewsPostItem(
                        NewsPostItem::TYPE_VIDEO,
                        null,
                        null,
                        null,
                        null,
                        $youtubeVideoId
                    )
                );
            }

            $post->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_HEADER,
                    $title,
                    null,
                    null,
                    3,
                    null
                ));

            $textCrawler = new Crawler($text);
            // If text has quotes
            if ($textCrawler->filterXPath('//em')->count() !== 0) {
                $textCrawler->filterXPath('//p')->each(function (Crawler $node, $i) use ($post) {
                    $isQuoteExistsInParagraph = $node->filterXPath('//em')->count() !== 0;
                    $text = $node->html();
                    $text = '<p>' . $text . '</p>';
                    if ($isQuoteExistsInParagraph) {
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_QUOTE,
                                $text,
                                null,
                                null,
                                null,
                                null
                            )
                        );
                    } else {
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_TEXT,
                                $text,
                                null,
                                null,
                                null,
                                null
                            )
                        );
                    }
                });
            } else {         
                $post->addItem(
                    new NewsPostItem(
                        NewsPostItem::TYPE_TEXT,
                        $text,
                        null,
                        null,
                        null,
                        null
                    )
                );
            }
            
            $post->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_IMAGE,
                    'Картинка в статье',
                    $imageLink,
                    null,
                    null,
                    null
                ));

            $posts[] = $post;
        }
        
        return $posts;
    }


}