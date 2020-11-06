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

/**
 * Парсер новостей с сайта https://ryazannews.ru/
 */
class RyazanNewsRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'https://ryazannews.ru';

    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
    }

    public function parse(): array
    {
        $posts = [];

        $urlNews = self::SITE_URL . '/feed/all';
        $newsPage = $this->getPageContent($urlNews);
        $crawler = new Crawler($newsPage);
        $newsList = $crawler->filterXPath("//item");
        foreach ($newsList as $newsItem) {
            $itemCrawler = new Crawler($newsItem);
            $title = $itemCrawler->filterXPath('//title')->text();
            $date = $this->getDate($itemCrawler->filterXPath('//pubDate')->text());
            $description = $this->clearText($itemCrawler->filterXPath('//description')->text());
            $url = $itemCrawler->filterXPath('//link')->text();

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                null
            );

            $contentPage = $this->getPageContent($url);
            if (!$contentPage) {
                continue;
            }

            $newsCrawler = new Crawler(null, $url);
            $newsCrawler->addHtmlContent($contentPage, 'UTF-8');

            $img = $newsCrawler->filterXPath('//img[@class="img-responsive post-image"]');
            if ($img->getNode(0)) {
                $post->image = $img->attr('src');
            }

            $newContentCrawler = $newsCrawler->filterXPath("//div[@class='post-content']")->children();

            foreach ($newContentCrawler as $content) {
                foreach ($content->childNodes as $childNode) {
                     $nodeValue = $this->clearText($childNode->nodeValue, [$post->description]);
                     if ($childNode->nodeName == 'a' && strpos($href = $this->getHeadUrl($childNode->getAttribute('href')), 'http') !== false) {

                         $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $href);

                     } elseif ($childNode->nodeName == 'img' && $post->image != ($src = $this->getHeadUrl($childNode->getAttribute('src')))) {

                         $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, null, $src);

                    } elseif ($nodeValue) {
                        $this->addItemPost($post, NewsPostItem::TYPE_TEXT, $nodeValue);
                    }
                }
            }

            $imageContentCrawler = $newsCrawler->filterXPath("//a[@data-lightbox='image-set']");
            foreach ($imageContentCrawler as $imageContent) {
                if ($href = $this->getHeadUrl($imageContent->getAttribute('href'))) {

                    $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, null, $href);

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
     * @param string $uri
     *
     * @return string
     * @throws RuntimeException|\Exception
     */
    private function getPageContent(string $uri): string
    {
        $curl = Helper::getCurl();
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, 0);

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
     * @param array $search
     *
     * @return string
     */
    protected function clearText(string $text, array $search = []): string
    {
        $text = html_entity_decode($text);
        $text = strip_tags($text);
        $text = htmlentities($text);
        $search = array_merge(["&nbsp;"], $search);
        $text = str_replace($search, ' ', $text);
        $text = html_entity_decode($text);
        return trim($text);
    }

    /**
     *
     * @param string $date
     *
     * @return string
     */
    protected function getDate(string $date): string
    {
        $date = new DateTime($date);
        $date->setTimezone(new DateTimeZone("UTC"));
        return $date->format("Y-m-d H:i:s");
    }

    /**
     *
     * @param string $url
     * @param string $det
     *
     * @return string
     */
    protected function getHeadUrl($url, $det = ''): string
    {
        $url = strpos($url, 'http') === false || strpos($url, 'http') > 0
            ? self::SITE_URL . $det . $url
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
        $partsUrl = parse_url($url);
        if (preg_match('/[А-Яа-яЁё]/iu', $partsUrl['host'])) {
            $host = idn_to_ascii($partsUrl['host']);
            $url = str_replace($partsUrl['host'], $host, $url);
        }
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
}