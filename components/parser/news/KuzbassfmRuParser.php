<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

class KuzbassfmRuParser implements ParserInterface
{
    const USER_ID    = 2;
    const FEED_ID    = 2;
    const SITE_URL   = 'http://kuzbassfm.ru/';
    const SKIP_FIRST = false; // Пропускать первое предложение, оно совпадает с описанием
    const ROUND_TIME = false; // Если нужно округлять время до начала часа, как на сайте

    protected static $posts = [];

    public static function run(): array
    {
        /*run*/
        $curl = Helper::getCurl();
        $xml  = $curl->get(self::SITE_URL.'rss/news.xml');

        if (!$xml) {
            throw new Exception('Не удалось загрузить rss ленту.');
        }

        $feed = new Crawler(html_entity_decode($xml));

        $feed->filter('item')->each(function ($node) {
            $tz = new DateTimeZone('Asia/Krasnoyarsk');
            $dt = new DateTime($node->filter('pubDate')->text(), $tz);

            if (self::ROUND_TIME) {
                $dt->setTime($dt->format('H'), 0, 0);
            }

            $description = array_map('trim', explode('.', $node->filter('description')->text()));

            $post = new NewsPost(
                self::class,
                $node->filter('title')->text(),
                $description[0],
                $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
                $node->filter('link')->text(),
                null
            );

            if ($content = implode('. ', array_slice($description, intval(self::SKIP_FIRST)))) {
                $post->addItem(
                    new NewsPostItem(
                        NewsPostItem::TYPE_TEXT,
                        $content,
                        null,
                        null,
                        null,
                        null
                    )
                );
            }

            self::$posts[] = $post;
        });

        return self::$posts;
    }
}
