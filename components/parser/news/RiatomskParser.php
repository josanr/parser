<?php
/**
 *
 * @author MediaSfera <info@media-sfera.com>
 * @author FingliGroup <info@fingli.ru>
 * @author Vitaliy Moskalyuk <flanker@bk.ru>
 *
 * @note Данный код предоставлен в рамках оказания услуг, для выполнения поставленных задач по сбору и обработке данных. Переработка, адаптация и модификация ПО без разрешения правообладателя является нарушением исключительных прав.
 *
 */

namespace app\components\parser\news;

use app\components\mediasfera\MediasferaNewsParser;
use app\components\mediasfera\NewsPostWrapper;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Symfony\Component\DomCrawler\Crawler;


/**
 * @rss_html
 */
class RiatomskParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://www.riatomsk.ru/';
    public const NEWSLIST_URL = 'https://www.riatomsk.ru/rss.xml';

    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_DESC = '//description';
    public const NEWSLIST_IMAGE = '//enclosure';

    public const ARTICLE_TEXT = '.stat-info > .up';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'injDiv' => false,
            'superInjDiv' => false,
            'fotoSign' => false,
            'stat-author' => false,
            'storyNewDiv' => false,
            'statInfoName' => false,
            'stat-mainImg' => false,
        ],
        'id' => [
            'ctl00_InfoPlaceHolder_TimeLabel' => false,
            'ctl00_InfoPlaceHolder_DateLabel' => false,
            'ctl00_InfoPlaceHolder_StatAboutControl_StoryDiv' => false,
        ],
    ];

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        //затесался невидимый символ
        $listContent = str_replace('﻿', '', self::getPage(self::NEWSLIST_URL));

        $listCrawler = new Crawler($listContent);

        $listCrawler->filterXPath(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeLink('text', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);
            self::$post->image = self::getNodeData('url', $node, self::NEWSLIST_IMAGE);

            $articleContent = self::getPage(self::$post->original);
            $articleContent = str_replace("\n", ' ', $articleContent);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
            }

            $posts[] = self::$post->getNewsPost();
        });

        return $posts;
    }


    protected static function parseNode(Crawler $node, ?string $filter = null) : void
    {
        $node = static::filterNode($node, $filter);

        if($node->nodeName() == 'p') {
            $strong = $node->filter('strong');

            if($strong->count() && strpos($strong->text(), '– РИА Томск')) {
                $node->getNode(0)->removeChild($strong->getNode(0));
            }
        }

        $qouteClasses = [
            'quote',
            'quotePlus'
        ];

        $NodeClasses = array_filter(explode(' ', $node->attr('class')));

        $diff = array_diff($qouteClasses , $NodeClasses);

        if($diff != $qouteClasses) {
            static::$post->itemQuote = $node->text();
            return;
        }

        parent::parseNode($node);
    }
}
