<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use yii\helpers\ArrayHelper;

/**
 * Парсер новостей с сайта http://www.trudu-slava.ru/
 */
class TruduSlavaParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'http://www.trudu-slava.ru';

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
        $newsList = $this->getListNews($newsPage, $urlNews);
        foreach ($newsList as $newsUrl) {
            $url = self::SITE_URL . $newsUrl;
            $contentPage = $this->getPageContent($url);
            if (!$contentPage) {
                continue;
            }

            $itemCrawler = new Crawler(null, $url);
            $itemCrawler->addHtmlContent($contentPage, 'UTF-8');

            $title = $itemCrawler->filterXPath("//*[@class='contentpagetitle']")->text();
            $date = $this->getDate($itemCrawler->filterXPath("//*[@class='createdate']")->text());
            $image = null;
            $imageSrc = $itemCrawler->filterXPath("//*[@id='page']/p/img");
            if ($imageSrc->getNode(0)) {
                $image = $this->getHeadUrl($imageSrc->attr('src'));
            }elseif ($imageSrc = $itemCrawler->filterXPath("//*[@id='page']/p/*/img")){
                $image = $this->getHeadUrl($imageSrc->attr('src'));
            }

            $description = '';
            $p = [];
            $paragraph = $itemCrawler->filterXPath("//*[@id='page']/p");
            foreach ($paragraph as $key => $item) {
                $text = $this->clearText($item->nodeValue);
                if (!$text) {
                    continue;
                }
                if (strpos($text, '№') !== false) {
                    continue;
                }
                if (!$description) {
                    $description = $text;
                } else {
                    $p[] = $text;
                }
            }

            $post = new NewsPost(
                self::class,
                $title,
                trim($description),
                $date,
                $url,
                $image
            );

            foreach ($p as $text) {
                $this->addItemPost($post, NewsPostItem::TYPE_TEXT, $text);
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
     *
     * @param string $date
     *
     * @return string
     */
    protected function getDate(string $date): string
    {
        $str = explode(' ', $date);
        $date = new DateTime(ArrayHelper::getValue($str, 1, ''));
        $date->setTimezone(new DateTimeZone("UTC"));
        return $date->format("Y-m-d H:i:s");
    }

    /**
     * Получение списка ссылок на страницы новостей
     *
     * @param string $page
     *
     * @return array
     */
    protected function getListNews(string $page, string $link): array
    {
        $records = [];

        $crawler = new Crawler(null, $link);
        $crawler->addHtmlContent($page, 'UTF-8');
        $list = $crawler->filterXPath('//div[@class="leading"]/h2/a');
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
        $text = str_replace("&nbsp;",' ',$text);
        $text = html_entity_decode($text);
        return trim($text);
    }

}