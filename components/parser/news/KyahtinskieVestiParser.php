<?php


namespace app\components\parser\news;


use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use PhpQuery\PhpQuery;

class KyahtinskieVestiParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://khtvesti.com';

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];

        $curl = Helper::getCurl();
        try {
            $content = $curl->get(self::LINK);
        } catch (Exception $e) {
            return [];
        }
        $parser = PhpQuery::newDocument($content);
        $items = $parser->find('.uil-layout-any-columns .uil-layout-any-columns__item.uil-layout-any-columns__item_empty_false');
        foreach ($items as $item) {
            try {
                $it = PhpQuery::pq($item);
            } catch (Exception $e) {
                continue;
            }
            $title = $it->find('.uil-mo-media-item-list-3__title')->text() ?? '';
            $description = $it->find('.uil-mo-media-item-list-3__annotation')->text() ?? '';
            $createDate = $it->find('.uil-mo-stat-info__created__date')->text();
            if (empty($createDate)) {
                $createDate = date('d.m.Y H:i:s');
            } else {
                $createDate = sprintf('%s %s', trim($createDate), date('H:i:s'));
            }
            $original = $it->find('.uil-mo-media-item-list-3__title a')->attr('href') ?? '';
            $original = sprintf('%s%s', self::LINK, $original);
            $image = $it->find('.uil-image-caption__image a img')->attr('src') ?? null;
            if (strpos($image, '//') == 0) {
                $image = 'http:' . $image;
            }

            try {
                $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
            } catch (Exception $e) {
                continue;
            }

            if (!empty($original)) {
                $content = $curl->get($original);
                $itemParser = PhpQuery::newDocument($content);
                $paragraphs = $itemParser->find('.uil-block-text__text p');
                if (count($paragraphs)) {
                    foreach ($paragraphs as $paragraph) {
                        $text = $paragraph->textContent;
                        if (!empty($text)) {
                            $post->addItem(
                                new NewsPostItem(
                                    NewsPostItem::TYPE_TEXT,
                                    trim($text),
                                )
                            );
                        }
                    }
                }
            }

            $posts[] = $post;
        }

        return $posts;
    }
}
