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
 * Парсер новостей с сайта http://nowcrimea.ru/
 */
class NowCrimeaRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'http://nowcrimea.ru';

    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
    }

    public function parse(): array
    {
        $posts = [];

        $urlNews = self::SITE_URL . '/novosti-kryma/';
        $newsPage = $this->getPageContent($urlNews);
        $newsList = $this->getListNews($newsPage);
        foreach ($newsList as $newsUrl) {
            $url = self::SITE_URL . $newsUrl;
            $contentPage = $this->getPageContent($url);
            $itemCrawler = new Crawler($contentPage);
            $title = $itemCrawler->filterXPath("//*[@class='detail__title']")->text();
            $date = $this->getDate($itemCrawler->filterXPath("//*[@class='detail__date']")->text());
            $description = '';
            $descriptionSrc = $itemCrawler->filterXPath("//*[@class='detail__main-content']");
            foreach ($descriptionSrc as $item) {
                foreach ($item->childNodes as $value) {
                    if (!$description && $text = $this->clearText($value->nodeValue)) {
                        $description = $text;
                    }
                }
            }
            $image = self::SITE_URL . $itemCrawler->filterXPath("//*[@class='detail__img']")->attr('src');

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $newContentCrawler = (new Crawler($itemCrawler->filterXPath("//*[@class='detail__main-content']")->html()))->filterXPath('//body')->children();

            foreach ($newContentCrawler as $content) {
                foreach ($content->childNodes as $childNode) {
                    $nodeValue = $this->clearText($childNode->nodeValue, [$post->description]);
                    if (in_array($childNode->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {

                        $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $nodeValue, null, null, (int) substr($childNode->nodeName, 1));

                    } if ($childNode->nodeName == 'a' && strpos($childNode->getAttribute('href'), 'http') !== false) {

                        $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $childNode->getAttribute('href'));

                    } elseif ($childNode->nodeName == 'iframe') {
                        $src = $childNode->getAttribute('src');
                        if (strpos($src, 'youtube') !== false) {

                            $this->addItemPost($post, NewsPostItem::TYPE_VIDEO, $childNode->getAttribute('title'), null, null, null, basename(parse_url($src, PHP_URL_PATH)));

                        }
                    } elseif ($childNode->nodeName == 'ul') {
                        $ulCrawler = (new Crawler($newContentCrawler->filterXPath("//ul")->html()))->filterXPath('//body')->children();
                        foreach ($ulCrawler as $ulNode) {
                            foreach ($ulNode->childNodes as $liChildNode) {
                                if ($liChildNode->nodeName != 'a') {
                                    continue;
                                }
                                foreach ($liChildNode->childNodes as $imgNode) {
                                    if ($imgNode->nodeName != 'img') {
                                        continue;
                                    }
                                    $srcImg = strpos($imgNode->getAttribute('src'), 'http') === false
                                        ? self::SITE_URL . $imgNode->getAttribute('src')
                                        : $imgNode->getAttribute('src');

                                    $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, null, $srcImg);
                                }
                            }
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
        // главная новость
        $listMain = $crawler->filterXPath("//*[@class='item__info col-md-6']/a");
        foreach ($listMain as $item) {
            $records[] = $item->getAttribute("href");
        }

        $list = $crawler->filterXPath("//*[@class='item__info']/a");
        foreach ($list as $item) {
            $records[] = $item->getAttribute("href");
        }

        return $records;
    }

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
     * @param string $date
     *
     * @return string
     */
    protected function getDate(string $date): string
    {
        $time = (new DateTime())->setTimezone(new DateTimeZone("UTC"))->format("H:i:s");
        $newDate = $date ? new DateTime($time . ' '. $date) : '';
        $newDate->setTimezone(new DateTimeZone("UTC"));
        return $newDate->format("Y-m-d H:i:s");
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