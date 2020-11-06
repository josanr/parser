<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Parser;
use app\components\helper\metallizzer\Url;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

class KsRegion69ComParser implements ParserInterface
{
    use \app\components\helper\metallizzer\Cacheable;

    /*run*/
    const USER_ID  = 2;
    const FEED_ID  = 2;
    const SITE_URL = 'https://ks-region69.com/';

    protected static $posts = [];

    public static function run(): array
    {
        $url  = self::SITE_URL.'/news/feed';
        $html = self::request($url);

        if (!$html) {
            throw new Exception('Не удалось загрузить сайт.');
        }

        $crawler = new Crawler($html, $url);

        $crawler->filter('item')->each(function ($node) {
            self::loadPost($node);
        });

        return static::$posts;
    }

    protected static function loadPost($node)
    {
        $url = $node->filter('link')->text();

        if (!$html = self::request($url)) {
            throw new Exception("Не удалось загрузить страницу '{$url}'.");
        }

        $crawler = new Crawler($html, $url);

        $image = $crawler->filter('a[data-rel="penci-gallery-bground-content"]')->first();

        $tz = new DateTimeZone('UTC');
        $dt = new DateTime($node->filter('pubDate')->text(), $tz);

        $path = '//div[@class="inner-post-entry entry-content"]/node()[not(contains(@class, "ya-share2")) and not(starts-with(., "Поделиться:"))]';
        $post = new NewsPost(
            self::class,
            html_entity_decode($node->filter('title')->text()),
            '~',
            $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
            Url::encode($url),
            $image->count() ? Url::encode($image->link()->getUri()) : null,
        );

        $items = (new Parser())->parseMany($crawler->filterXpath($path));

        foreach ($items as $item) {
            if ($item['type'] === NewsPostItem::TYPE_IMAGE) {
                if (!$post->image) {
                    $post->image = Url::encode($item['image']);

                    continue;
                } elseif ($post->image == $item['image']) {
                    continue;
                }
            }

            if ($post->description == '~' && $item['type'] === NewsPostItem::TYPE_TEXT) {
                $post->description = $item['text'];

                continue;
            }

            $post->addItem(new NewsPostItem(...array_values($item)));
        }

        if ($post->description == '~') {
            $post->description = $post->title;
        }

        self::$posts[] = $post;
    }

    protected static function parseDate($string)
    {
        $re = '/^(?<day>\d{1,2}) (?<month>[^ ]+) (?<year>\d{4}) г\., (?<hours>\d{1,2}):(?<minutes>\d{1,2})$/';

        if (!preg_match($re, trim($string), $m)) {
            throw new Exception("Не удалось разобрать дату '{$string}'");
        }

        $months = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

        foreach ($months as $key => $name) {
            if (strpos($m['month'], $name) === 0) {
                $month = $key + 1;

                break;
            }
        }

        return sprintf('%d-%02d-%02d %02d:%02d:00',
            $m['year'], $month, $m['day'], $m['hours'], $m['minutes']
        );
    }
}
