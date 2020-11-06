<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\TyRunBaseParser;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер новостей из RSS ленты uralpress.ru
 *
 * На страницах новостей может быть галерея
 * Описание может пересекаться с содержимым статьи
 * (используем дял парсинга тегов <p> метод parseDescriptionIntersectParagraph)
 */
class UralPress extends TyRunBaseParser implements ParserInterface
{
    /*run*/
    const USER_ID = 2;
    const FEED_ID = 2;

    const MAIN_PAGE_URI = 'https://uralpress.ru';

    /**
     * CSS класс, где хранится содержимое новости
     */
    const BODY_CONTAINER_CSS_SELECTOR = '.news-wrap';

    /**
     * CSS  класс для параграфов - цитат
     */
    const QUOTE_TAG = 'em';

    /**
     * Классы эоементов, которые не нужно парсить, например блоки с рекламой и т.п.
     * в формате RegExp
     */
    const EXCLUDE_CSS_CLASSES_PATTERN = '';

    /**
     * Класс элемента после которого парсить страницу не имеет смысла (контент статьи закончился)
     */
    const CUT_CSS_CLASS = '';


    /**
     * Ссылка на RSS фид (XML)
     */
    const FEED_URL = 'https://uralpress.ru/rss.xml';

    /**
     *  Максимальная глубина для парсинга <div> тегов
     */
    const MAX_PARSE_DEPTH = 3;

    /**
     * Префикс для элементов списков (ul, ol и т.п.)
     * при преобразовании в текст
     * @see parseUl()
     */
    const UL_PREFIX = '-';

    /**
     * Кол-во новостей, которое необходимо парсить
     */
    const MAX_NEWS_COUNT = 10;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $rss = $curl->get(self::FEED_URL);

        $crawler = new Crawler($rss);
        $crawler->filter('rss channel item')->slice(0, self::MAX_NEWS_COUNT)->each(function (Crawler $node) use (&$curl, &$posts) {

            $newPost = new NewsPost(
                self::class,
                $node->filter('title')->text(),
                '-',
                self::stringToDateTime($node->filter('pubDate')->text()),
                $node->filter('link')->text(),
                null
            );

            /**
             * Получаем полный html новости
             */
            $newsContent = $curl->get($newPost->original);

            if (!empty($newsContent)) {
                $newsContent = (new Crawler($newsContent))->filter(self::BODY_CONTAINER_CSS_SELECTOR);

                $newPost->description = $newsContent->filter('.news-text .field-item p:first-child')->text();

                /**
                 * Предложения содержащиеся в описании (для последующей проверки при парсинга тела новости)
                 */
                $descriptionSentences = explode('. ', html_entity_decode($newPost->description));

                /**
                 * Основное фото ( всегда одно в начале статьи)
                 */
                $mainImage = $newsContent->filter('.news-top img');
                if ($mainImage->count()) {
                    if ($mainImage->attr('src')) {
                        $newPost->image = $mainImage->attr('src');
                    }
                }

                /**
                 * Текст статьи, может содержать цитаты ( все полезное содержимое в тегах <p> )
                 * Не знаю нужно или нет, но сделал более универсально, с рекурсией
                 */
                $articleContent = $newsContent->filter('.news-text .field-item')->children();
                $stopParsing = false;
                if ($articleContent->count()) {
                    $articleContent->each(function ($node) use ($newPost, &$stopParsing, $descriptionSentences) {
                        if ($stopParsing) {
                            return;
                        }
                        self::parseNode($node, $newPost, self::MAX_PARSE_DEPTH, $stopParsing, $descriptionSentences);
                    });
                }
            }

            $posts[] = $newPost;
        });

        return $posts;
    }

    protected static function parseNode(Crawler $node, NewsPost $newPost, int $maxDepth, bool &$stopParsing, $descriptionSentences = []): void
    {
        /**
         * Пропускаем элемент, если элемент имеет определенный класс
         * @see EXCLUDE_CSS_CLASSES_PATTERN
         */
        if (self::EXCLUDE_CSS_CLASSES_PATTERN
            && preg_match(self::EXCLUDE_CSS_CLASSES_PATTERN, $node->attr('class'))) {
            return;
        }

        /**
         * Прекращаем парсить страницу, если дошли до конца статьи
         * (до определенного элемента с классом указанным в @see CUT_CSS_CLASS )
         *
         */
        if (self::CUT_CSS_CLASS && stristr($node->attr('class'), self::CUT_CSS_CLASS)) {
            $maxDepth = 0;
            $stopParsing = true;
        }

        /**
         * Ограничение максимальной глубины парсинга
         * @see MAX_PARSE_DEPTH
         */
        if (!$maxDepth) {
            return;
        }
        $maxDepth--;

        switch ($node->nodeName()) {
            case 'div': //запускаем рекурсивно на дочерние ноды, если есть, если нет то там обычно ненужный шлак
                /**
                 * Новость может содержать галерею (блок с классом field-type-image),
                 * парсим только изображения из таких блоков
                 */
                if (stristr($node->attr('class'), 'field-type-image')) {
                    $images = $node->filter('.gallery-slides img');
                    if ($images->count()) {
                        $images->each(function (Crawler $node) use (&$newPost){
                            self::parseImage($node, $newPost);
                        });
                    }
                } else {
                    $nodes = $node->children();
                    if ($nodes->count()) {
                        $nodes->each(function ($node) use ($newPost, $maxDepth, &$stopParsing) {
                            self::parseNode($node, $newPost, $maxDepth, $stopParsing);
                        });
                    }
                }
                break;
            case 'p':
                self::parseDescriptionIntersectParagraph($node, $newPost, $descriptionSentences);
                if ($nodes = $node->children()) {
                    $nodes->each(function ($node) use ($newPost, $maxDepth, &$stopParsing) {
                        self::parseNode($node, $newPost, $maxDepth, $stopParsing);
                    });
                }
                break;
            case 'img':
                self::parseImage($node, $newPost);
                break;
            case 'video':
                $videoId = self::extractYouTubeId($node->filter('source')->first()->attr('src'));
                self::addVideo($videoId, $newPost);
                break;
            case 'a':
                self::parseLink($node, $newPost);
                if ($nodes = $node->children()) {
                    $nodes->each(function ($node) use ($newPost, $maxDepth, &$stopParsing) {
                        self::parseNode($node, $newPost, $maxDepth, $stopParsing);
                    });
                }
                break;
            case 'iframe':
                $videoId = self::extractYouTubeId($node->attr('src'));
                self::addVideo($videoId, $newPost);
                break;
            case 'ul':
            case 'ol':
                self::parseUl($node, $newPost);
                break;
        }


    }

}