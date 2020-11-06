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
 * Парсер новостей с сайта http://vnashemdvore.ru/
 */
class VnashemDvoreRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'http://vnashemdvore.ru';

    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
    }

    public function parse(): array
    {
        $posts = [];

        $urlNews = self::SITE_URL . '/news';
        $newsPage = $this->getPageContent($urlNews);
        $newsList = $this->getListNews($newsPage);
        foreach ($newsList as $newsUrl) {
            $url = self::SITE_URL . $newsUrl;
            $contentPage = $this->getPageContent($url);
            if (!$contentPage) {
                continue;
            }

            $itemCrawler = new Crawler($contentPage);
            $title = $itemCrawler->filterXPath("//h2[@class='title']")->text();

            $date = $this->getDate($itemCrawler->filterXPath("//div[@class='submitted']")->text());
            $content = $itemCrawler->filterXPath("//div[@class='clear-block']/*/div[@class='content']");
            $image = null;
            $imgSrc = $content->filterXPath("//div[@class='all-attached-images']//*//img");
            if ($imgSrc->getNode(0)) {
                $image = $this->getHeadUrl($imgSrc->attr('src'));
            }

            $description = $content->filterXPath('//p')->text();

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            //$newContentCrawler = (new Crawler($content->html()))->filterXPath('//body')->children();
            $newContentCrawler = $content->children();
            foreach ($newContentCrawler as $key=>$content) {
                if ($key < 2) {
                    continue;
                }
                foreach ($content->childNodes as $childNode) {
                    $nodeValue = $this->clearText($childNode->nodeValue, [$post->description]);
                    if (!$nodeValue) {
                        continue;
                    }
                    foreach ($childNode->childNodes as $itemChildNode) {
                        $childNodeValue = $this->clearText($itemChildNode->nodeValue, [$post->description]);
                        if ($itemChildNode->nodeName == 'a' && strpos($href = $this->getHeadUrl($itemChildNode->getAttribute('href')), 'http') !== false) {

                            $this->addItemPost($post, NewsPostItem::TYPE_LINK, $childNodeValue, null, $href);

                        } elseif ($childNodeValue && $childNodeValue != $post->description) {

                            $this->addItemPost($post, NewsPostItem::TYPE_TEXT, $childNodeValue);
                        }
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
        $str = explode(',', $date);
        $newDate = ArrayHelper::getValue($str, 1, '');
        $newDate = str_ireplace(['-', '/'], ['','.'], $newDate);
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
        if (preg_match('/[А-Яа-яЁё\s]/iu', $url)) {
            preg_match_all('/[А-Яа-яЁё\s]/iu', $url, $result);
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
        $list = $crawler->filterXPath("//div[@class='item-list']")->filterXPath("//span[@class='field-content']/a");
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
}