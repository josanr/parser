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
 * Парсер новостей с сайта http://www.v-life.ru/
 */
class VLifeRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'http://www.v-life.ru/';

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
            $title = $itemCrawler->filterXPath("//div[@id='content_main']/table//*//h1")->text();
            $date = $this->getDate($itemCrawler->filterXPath("//td[@class='small']")->text());
            $image = null;
            $imgSrc = $itemCrawler->filterXPath("//a[@class='highslide']/img");
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

            $table = $itemCrawler->filterXPath("//*[@id='view_line']")->siblings()->children();
            foreach ($table as $key => $item) {
                if ($key <= 2 || strpos($item->nodeValue, 'function') !== false) {
                    continue;
                }

                $nodeValue = $this->clearText($item->nodeValue);
                if (in_array($item->nodeValue, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {

                    $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $nodeValue, null, null, (int) substr($item->nodeName, 1));

                } elseif ($item->nodeName == 'a' && strpos($item->getAttribute('href'), 'http') !== false) {

                    $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $item->getAttribute('href'));

                } elseif ($item->nodeName == 'img') {
                    $src = $item->getAttribute('src');
                    if ($src === $image) {
                        continue;
                    }

                    $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, null, $src);

                } elseif ($nodeValue) {

                    if ($post->description == $post->title) {
                        $post->description = $nodeValue;
                        continue;
                    }

                    $this->addItemPost($post, NewsPostItem::TYPE_TEXT, $nodeValue);

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
        $str = explode(':', $date);
        $time = (new DateTime())->setTimezone(new DateTimeZone("UTC"))->format("H:i:s");
        $newDate = ArrayHelper::getValue($str, 1, '') ? new DateTime($time . ' '. $str[1]) : '';
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
        $main = $crawler->filterXPath("//div[@class='example_text']/a");
        if($main->getNode(0)) {
            $records[] = $main->attr('href');
        }

        $list = $crawler->filterXPath("//div[@id='list_news_main']//*//span[@class='header1']/b/a");
        foreach ($list as $item) {
            $href = $item->getAttribute("href");
            if (!array_search($href, $records)) {
                $records[] = $href;
            }
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
            return iconv('windows-1251', 'utf-8', $result);
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