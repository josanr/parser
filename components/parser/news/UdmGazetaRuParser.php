<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateInterval;
use DateTime;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use yii\helpers\ArrayHelper;

/**
 * Парсер новостей с сайта https://www.udmgazeta.ru/
 */
class UdmGazetaRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'https://www.udmgazeta.ru';

    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
    }

    public function parse(): array
    {
        $posts = [];

        $urlNews = self::SITE_URL;
        $newsPage = $this->getPageContent($urlNews);
        $newsList = $this->getListNews($newsPage);
        foreach ($newsList as $newsUrl) {
            $url = self::SITE_URL . $newsUrl;
            $contentPage = $this->getPageContent($url);
            if (!$contentPage) {
                continue;
            }
            $itemCrawler = new Crawler($contentPage);
            $title = $itemCrawler->filterXPath("//div[@class='news-full-title']")->text();
            $date = $this->getDate($itemCrawler->filterXPath("//div[@class='news-full-pubdate']")->text());
            $image = null;
            $imgSrc = $itemCrawler->filterXPath("//div[@class='big-coll news-full']//*//img");
            if ($imgSrc->getNode(0)) {
                $image = $this->getHeadUrl($imgSrc->attr('src'));
            }

            $description = $title;

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );
            $newContentCrawler = (new Crawler($itemCrawler->filterXPath("//div[@class='big-coll news-full']")->html()))->filterXPath('//body')->children();
            
            foreach ($newContentCrawler as $item => $content) {
                if($item < 3) {
                    continue;
                }

                if(in_array($content->nodeName, ['div', 'script'])){
                    continue;
                }
                foreach ($content->childNodes as $key => $childNode) {
                    $nodeValue = $this->clearText($childNode->nodeValue);
                    if(in_array($childNode->nodeName, ['div', '#comment'])){
                        continue;
                    }

                    if (in_array($childNode->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {

                        $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $nodeValue, null, null, (int) substr($childNode->nodeName, 1));

                    } if ($childNode->nodeName == 'a' && strpos($childNode->getAttribute('href'), 'http') !== false) {

                        $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $childNode->getAttribute('href'));

                    } elseif ($nodeValue) {

                        if ($post->description == $post->title) {
                            $post->description = $nodeValue;
                            continue;
                        }

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
        $now = new DateTime();
        $time = $now->setTimezone(new DateTimeZone("UTC"))->format("H:i:s");
        $today = $now->format('Y-m-d');
        $date = mb_strtolower($date);
        $str = explode(':', $date);
        $yesterday = $now->sub(new DateInterval('P1D'))->format('Y-m-d');
        $ruMonths = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря', 'сегодня', 'вчера'];
        $enMonths = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', $today, $yesterday];
        $newDate = ArrayHelper::getValue($str, 1) ? $time . ' '. $str[1] : '';
        if ($newDate) {
            $newDate = str_ireplace($ruMonths, $enMonths, $newDate);
        }
        $newDate = new DateTime($newDate);
        $newDate->setTimezone(new DateTimeZone("UTC"));
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
        return strpos($url, 'http') === false
            ? self::SITE_URL . $url
            : $url;
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
        $list = $crawler->filterXPath("//div[@class='interview']//*//a");
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
        $text = trim($text);
        $text = htmlentities($text);
        $text = str_replace("&nbsp;",'',$text);
        $text = html_entity_decode($text);
        if (strpos($text, 'Количество просмотров') !== false) {
            $text = '';
        }
        return $text;
    }

    /**
     *
     * @param string $text
     *
     * @return string
     */
    protected function getDescription($text): string
    {
        if (($f = strpos($text, 'Поделиться в соц. сетях')) !== false ) {
            $text = substr($text, 0, $f);
        }
        return $text;
    }
}
