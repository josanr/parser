<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use yii\helpers\ArrayHelper;

/**
 * Парсер новостей с сайта https://bs-magazine.ru/
 */
class BsMagazineRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'https://bs-magazine.ru';

    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
    }

    public function parse(): array
    {
        $posts = [];

        $urlNews = self::SITE_URL . '/new-posts/';
        $newsPage = $this->getPageContent($urlNews);
        $newsList = $this->getListNews($newsPage);
        foreach ($newsList as $newsUrl) {
            $url = $newsUrl;
            $contentPage = $this->getPageContent($url);
            if (!$contentPage) {
                continue;
            }

            $itemCrawler = new Crawler($contentPage);
            $content = $itemCrawler->filterXPath('//div[@class="new-detail"]');
            $title = $content->filterXPath("//h1")->text();
            $date = $this->getDate($itemCrawler->filterXPath('//meta[@property="article:published_time"]')->attr('content'));
            $image = null;
            $imgSrc = $content->filterXPath("//div[@class='new-detail__title']");
            if ($imgSrc->getNode(0)) {
                $src = $imgSrc->attr('style');
                $start = stripos($src,"url(")+strlen("url(");
                $end = strrpos($src,")");
                $backgroundUrl = substr($src, $start, $end-$start);
                $image = $this->getHeadUrl($backgroundUrl);
            }

            $description = $content->filterXPath('//div[@class="new-detail__txt"]')->children();
            if ($description->getNode(0)) {
                $description = $description->getNode(0)->textContent;
            }

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $newContentCrawler = $content->filterXPath('//div[@class="new-detail__txt"]');
            foreach ($newContentCrawler as $contentNew) {
                foreach ($contentNew->childNodes as $childNode) {
                    if ($childNode->nodeName == 'div' && $childNode->attributes && $childNode->getAttribute('class')) {
                        continue;
                    }
                    if ($childNode->nodeValue == $description) {
                        continue;
                    }

                    $nodeValue = $this->clearText($childNode->nodeValue);
                    if (in_array($childNode->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {

                        $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $nodeValue, null, null, (int)substr($childNode->nodeName, 1));

                    } elseif ($childNode->nodeName == 'a' && strpos($childNode->getAttribute('href'), 'http') !== false) {

                        $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $childNode->getAttribute('href'));

                    } elseif ($childNode->nodeName == 'img' && $this->getHeadUrl($childNode->getAttribute('src')) != $image) {

                        $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, null, $this->getHeadUrl($childNode->getAttribute('src')));

                    } elseif ($childNode->nodeName == 'iframe') {
                        $src = $this->getHeadUrl($childNode->getAttribute('src'));
                        if (strpos($src, 'youtube') !== false) {
                            $youId = basename(parse_url($src, PHP_URL_PATH));
                            $titleYou = $childNode->getAttribute('title');

                            $this->addItemPost($post, NewsPostItem::TYPE_VIDEO, $titleYou, null, null, null, $youId);

                        }

                    } elseif ($childNode->nodeName == 'figure') {
                        $figureCrawler = new Crawler($childNode);
                        $imgUrl = $figureCrawler->filterXPath('//img');
                        if ($imgUrl && $this->getHeadUrl($imgUrl->attr('src')) != $image) {

                            $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, $imgUrl->attr('alt'), $this->getHeadUrl($imgUrl->attr('src')));

                        }
                    } elseif ($nodeValue) {

                        $this->addItemPost($post, NewsPostItem::TYPE_TEXT, $nodeValue);

                    }
                }
            }

            $authorCrawler = $content->filterXPath('//div[@class="new-detail__author__left flex -justify"]')->children();
            if ($authorCrawler->getNode(0)) {
                $this->addItemPost($post, NewsPostItem::TYPE_TEXT, 'Автор:');
            }
            foreach ($authorCrawler as $contentAuthor) {
                foreach ($contentAuthor->childNodes as $childNode) {
                    $nodeValue = $this->clearText($childNode->nodeValue);
                    if (in_array($childNode->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {

                        $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $nodeValue, null, null, (int)substr($childNode->nodeName, 1));

                    } elseif ($childNode->nodeName == 'a' && strpos($childNode->getAttribute('href'), 'http') !== false) {

                        $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $childNode->getAttribute('href'));

                    } elseif ($childNode->nodeName == 'img' && $this->getHeadUrl($childNode->getAttribute('src')) != $image) {

                        $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, null, $this->getHeadUrl($childNode->getAttribute('src')));

                    } elseif ($childNode->nodeName == 'div') {
                        $divCrawler = new Crawler($childNode);
                        $authorUrl = $divCrawler->filterXPath('//a');
                        if ($authorUrl && strpos($authorUrl->attr('href'), 'http') !== false) {

                            $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $authorUrl->attr('href'));

                        }
                    } elseif ($nodeValue) {

                        $this->addItemPost($post, NewsPostItem::TYPE_TEXT, $nodeValue);

                    }
                }
            }

            $posts[] = $post;
        }

        return $posts;
    }

    /**
     * @param NewsPost $post
     * @param int $type
     * @param string|null $text
     * @param string|null $image
     * @param string|null $link
     * @param int|null $headerLevel
     * @param string|null $youtubeId
     */
    protected function addItemPost(NewsPost $post, int $type, string $text = null, string $image = null,
                                   string $link = null, int $headerLevel = null, string $youtubeId = null): void
    {
        $post->addItem(
            new NewsPostItem(
                $type,
                $text,
                $image,
                $link,
                $headerLevel,
                $youtubeId
            ));
    }

    /**
     *
     * @param string $date
     *
     * @return string
     */
    protected function getDate(string $date): string
    {
        $date = mb_strtolower($date);
        $date = htmlentities($date);
        $date = explode('&nbsp;', $date);

        $ruMonths = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        $enMonths = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
        $newDate = new \DateTime(str_ireplace($ruMonths, $enMonths, ArrayHelper::getValue($date, 0, '')));
        $newDate->setTimezone(new \DateTimeZone("UTC"));
        return $newDate->format("Y-m-d H:i:s");
    }


    /**
     *
     * @param string $url
     *
     * @return string
     */
    protected function getHeadUrl($url): string
    {
        $url = strpos($url, 'http') === false
            ? self::SITE_URL . $url
            : $url;
        return $this->encodeUrl($url);
    }

    /**
     * Русские буквы в ссылке
     *
     * @param string $url
     *
     * @return string
     */
    protected function encodeUrl(string $url): string
    {
        if (preg_match('/[А-Яа-яЁё]/iu', $url)) {
            preg_match_all('/[А-Яа-яЁё]/iu', $url, $result);
            $search = [];
            $replace = [];
            foreach ($result as $item) {
                foreach ($item as $key=>$value) {
                    $search[$key] = $value;
                    $replace[$key] = urlencode($value);
                }
            }
            $url = str_replace($search, $replace, $url);
        }
        return $url;
    }

    /**
     * Получение списка ссылок на страницы новостей
     *
     * @param string $page
     *
     * @return array
     */
    protected function getListNews(string $page): array
    {
        $records = [];

        $crawler = new Crawler($page);
        $list = $crawler->filterXPath("//main[@id='main']")->filterXPath('//h2[@class="entry-title"]/a');
        foreach ($list as $item) {
            $records[] = $item->getAttribute("href");
        }

        return $records;
    }

    /**
     *
     * @param string $uri
     *
     * @return string
     * @throws RuntimeException|\Exception
     */
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

    /**
     *
     * @param string $text
     *
     * @return string
     */
    protected function clearText(string $text): string
    {
        $text = htmlentities($text);
        $text = str_replace("&nbsp;", ' ', $text);
        $text = html_entity_decode($text);
        return trim($text);
    }
}