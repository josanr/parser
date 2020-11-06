<?php

namespace app\components\parser\news;

use app\components\parser\ParserInterface;
use app\components\parser\NewsPostItem;
use app\components\parser\NewsPost;
use lanfix\parser\src\Element;
use app\components\Helper;
use lanfix\parser\Parser;
use yii\base\Exception;

/**
 * Парсер новостей с сайта http://1743.ru/
 * На данном ресурсе UTC+0
 * @author lanfix
 */
class News1743Parser implements ParserInterface
{
    /*run*/

    const USER_ID = 2;
    const FEED_ID = 2;

    const SRC = 'http://1743.ru/';

    /**
     * @inheritDoc
     */
    public static function run(): array
    {
        /** Вырубаем нотисы */
        error_reporting(E_ALL & ~E_NOTICE);
        /**
         * ### Стадия #1 - Получение ссылок на статьи
         * Получаем содержимое страницы новостей
         */
        $curl = Helper::getCurl();
        $curlResult = $curl->get(static::SRC . 'news');
        if (!$curlResult) {
            throw new Exception('Can not get hypertext');
        }
        $newsPageParser = new Parser($curlResult, true);
        $newsPageBody = $newsPageParser->document->getBody();
        $newsContainer = $newsPageBody->findOne('.blocks');
        /** Если проблемы с получением, то выбрасываем ошибку */
        if (!$newsContainer) {
            throw new Exception('Can not get news list');
        }
        /** Ищем новостные плашки на странице */
        $newsCardsOnPage = $newsContainer->find('.news-item');
        /** Получаем список ссылок на статьи */
        $newsLinks = array_map(function (Element $card) {
            /** Получение ссылки */
            if ($cardLinkNode = $card->findOne('.news-preview')) {
                if ($link = trim($cardLinkNode->getAttribute('href'))) {
                    static::handleUrl($link, static::SRC);
                    $link = preg_replace('/[^\w\-\/:]/iu', '', $link);
                }
            }
            if ($timeNode = $card->findOne('.f-gray')) {
                $time = trim($timeNode->asText() ?: '');
            }
            return [
                'link' => $link ?? '',
                'time' => static::getTimestampFromDateString($time ?? ''),
            ];
        }, $newsCardsOnPage);
        /** Удаляем пустые ссылки */
        $pageDataList = array_filter($newsLinks, function ($data) {
            return $data['link'] && $data['time'];
        });
        /**
         * ### Стадия #2 - Получение данных со страниц статей
         * Получаем страницы новостей по отдельности
         */
        foreach ($pageDataList as $pageData) {
            /**
             * Создание парсера и получение данных со страницы
             * конкретной новости
             */
            $curl = Helper::getCurl();
            $curlResult = $curl->get($pageData['link']);
            $newPageParser = new Parser($curlResult, true);
            $newPageBody = $newPageParser->document->getBody();
            $newPageHead = $newPageParser->document->getHead();
            /**
             * Пропускаем статью если контент отсутствует
             */
            if (!$newContain = $newPageBody->findOne('.newsview')) {
                continue;
            }
            /**
             * Получаем заголовок
             */
            $titleHtmlNode = $newContain->findOne('.newsview-title');
            if (!$header = ($titleHtmlNode ? $titleHtmlNode->asText() : '')) {
                continue;
            }
            /** Пристыковываем фразу "интро" к заголовку */
            if ($newIntroNode = $newPageBody->findOne('.newsview-intro')) {
                if ($newIntro = ($newIntroNode ? $newIntroNode->asText() : '')) {
                    $header = $header . '. ' . $newIntro . '.';
                }
            }
            /**
             * Получаем описание
             */
            $description = '';
            foreach ($newPageHead->find('meta') ?? [] as $meta) {
                if ($meta->getAttribute('name') === 'description') {
                    $description = $meta->getAttribute('content') ?: '';
                    break;
                }
            }
            if (!$description) {
                continue;
            }
            /**
             * Получаем URL до главной фотки
             */
            $photoUrl = '';
            if ($photoHtmlNode = $newContain->findOne('.newsview-preview')) {
                $photoUrl = trim($photoHtmlNode->getAttribute('src') ?: '');
                /** Заметил, что у фоток с водяным знаком в урле добавляется /r */
                $photoUrl = str_replace('/r/', '/', $photoUrl);
            }
            static::handleUrl($photoUrl, static::SRC);
            /**
             * Получаем время создания статьи
             */
            $newTime = date('Y-m-d H:i:s', $pageData['time'] ?: 0);
            /**
             * Генерируем новостной пост
             */
            $post = new NewsPost(static::class, $header, $description, $newTime, $pageData['link'], $photoUrl);
            /**
             * Получаем текстовое содержимое
             * @var Element $containBlock
             */
            if (!$textOfCurrentNew = $newContain->findOne('#text')) {
                continue;
            }
            foreach ($textOfCurrentNew->getChildren() ?: [] as $containBlock) {
                if ($containBlock->tag === 'p' && $img = $containBlock->findOne('img')) {
                    if ($pathToImage = $img->getAttribute('src') ?: '') {
                        static::handleUrl($pathToImage, static::SRC);
                        /** Пропускаем фотографию, если она стоит на обложке */
                        if ($pathToImage === $photoUrl) {
                            continue;
                        }
                        $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, null, $pathToImage));
                    }
                } elseif ($containBlock->tag === 'p') {
                    $content = $containBlock->asText();
                    static::clearSpaces($content);
                    if (!$content) continue;
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $content));
                } elseif (preg_match('/h[1-7]/ui', $containBlock->tag)) {
                    $level = (int)substr($containBlock->tag, 1, 1);
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_HEADER, $containBlock->asText(),
                        null, null, ($level > 0 ? $level : null)));
                }
            }
            /** Докидываем пост в список */
            $posts[] = $post;
        }
        return $posts ?? [];
    }

    /**
     * Обработать Url и превратить его в абсолютный, если он таковым не является.
     * @param string $url Урл, который обработать
     * @param string $baseUrl Бозовый урл страницы
     */
    public static function handleUrl(string &$url, string $baseUrl)
    {
        if (substr($baseUrl, strlen($baseUrl) - 1, 1) !== '/') {
            $baseUrl .= '/';
        }
        if ($url && substr($url, 0, 1) === '/') {
            $url = $baseUrl . substr($url, 1);
        }
    }

    /**
     * Очистить от пробелов
     */
    public static function clearSpaces(string &$string)
    {
        $string = preg_replace('/\s+/ui', ' ', $string);
    }

    /**
     * Перевод строки со временем в UNIX TIMESTAMP
     * - Входящие данные: "Сегодня в 22:35", "30 сен 2020 в 18:45"
     * @param string $time
     * @return int
     */
    public static function getTimestampFromDateString(string $time)
    {
        static::clearSpaces($time);
        if (preg_match('/^(\d+) (\w+) (\d+) \w (\d+):(\d+)$/ui', $time, $matches)) {
            /** 1 окт 2020 в 16:35 => [1, окт, 2020, 16, 35] */
            if (count($matches) != 6) {
                return time();
            }
            switch ($matches[2]) {
                case 'янв': case 'январь': case 'января':       $month = '01'; break;
                case 'фев': case 'февраль': case 'февраля':     $month = '02'; break;
                case 'мар': case 'март': case 'марта':          $month = '03'; break;
                case 'апр': case 'апрель': case 'апреля':       $month = '04'; break;
                case 'май': case 'мая':                         $month = '05'; break;
                case 'июн': case 'июнь': case 'июня':           $month = '06'; break;
                case 'июл': case 'июль': case 'июля':           $month = '07'; break;
                case 'авг': case 'август': case 'августа':      $month = '08'; break;
                case 'сен': case 'сентябрь': case 'сентября':   $month = '09'; break;
                case 'окт': case 'октябрь': case 'октября':     $month = '10'; break;
                case 'ноя': case 'ноябрь': case 'ноября':       $month = '11'; break;
                case 'дек': case 'декабрь': case 'декабря':     $month = '12'; break;
                default:                                        $month = '01';
            }
            return (int)strtotime("{$matches[1]}:{$month}:{$matches[3]} {$matches[4]}:{$matches[5]}");
        } elseif (preg_match('/^(\w+).* (\d+):(\d+)$/ui', $time, $matches)) {
            if (isset($matches[1]) && $matches[1] == 'вчера') {
                /** Начало вчерашнего дня (00:00) */
                $dayStartTimestamp = strtotime(date('d.m.Y', time() - 3600 * 24));
            } else {
                /** Начало сегодняшнего дня (00:00) */
                $dayStartTimestamp = strtotime(date('d.m.Y', time()));
            }
            $hours = $matches[2] ?? 0;
            $minutes = $matches[3] ?? 0;
            return (int)($dayStartTimestamp + $hours * 3600 + $minutes * 60);
        }
        return 0;
    }

}