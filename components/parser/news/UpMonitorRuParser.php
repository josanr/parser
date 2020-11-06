<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер новостей с сайта http://www.upmonitor.ru/
 */
class UpMonitorRuParser implements ParserInterface
{
    /*run*/
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'http://www.upmonitor.ru';

    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
    }

    public function parse(): array
    {
        $posts = [];

        $urlNews = self::SITE_URL . '/export/news.xml';
        $newsPage = $this->getPageContent($urlNews);
        $crawler = new Crawler($newsPage);
        $newsList = $crawler->filterXPath("//item");
        foreach ($newsList as $newsItem) {
            $itemCrawler = new Crawler($newsItem);
            $title = $itemCrawler->filterXPath('//title')->text();
            $date = $this->getDate($itemCrawler->filterXPath('//pubDate')->text());
            $description = $itemCrawler->filterXPath('//description')->text();
            $url = $itemCrawler->filterXPath('//link')->text();
            $image = null;
            $imgSrc = $itemCrawler->filterXPath('//enclosure');
            if ($imgSrc->getNode(0)) {
                $image = $imgSrc->getNode(0)->getAttribute('url');
            }

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $contentPage = $this->getPageContent($url);
            $newsCrawler = new Crawler($contentPage);

            $newImgCrawler = $newsCrawler->filterXPath("//*//table[2]//*//div/img");
            foreach ($newImgCrawler as $img) {
                $src = $this->getHeadUrl($img->getAttribute('src'));
                if ($image && $image != $src) {
                    $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, null, $src);
                }
            }

            $newContentCrawler = $newsCrawler->filterXPath("//*//table[2]//*//td/p");

            foreach ($newContentCrawler as $content) {
                foreach ($content->childNodes as $childNode) {
                    $nodeValue = $this->clearText($childNode->nodeValue);
                    if ($childNode->nodeName == 'a' && strpos($childNode->getAttribute('href'), 'http') !== false) {

                        $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $childNode->getAttribute('href'));

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
        return $text;
    }

    /**
     *
     * @param string $date
     *
     * @return string
     */
    protected function getDate(string $date): string
    {
        $newDate = new \DateTime($date);
        $newDate->setTimezone(new \DateTimeZone("UTC"));
        return $newDate->format("Y-m-d H:i:s");
    }
}
