<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateInterval;
use DateTime;
use DateTimeZone;
use DOMNode;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер новостей с сайта http://spsuvorov.ru/
 */
class SpsuvorovRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'http://spsuvorov.ru';

    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
    }

    public function parse(): array
    {
        $posts = [];

        $urlNews = self::SITE_URL . '/news/';
        $newsPage = $this->getPageContent($urlNews);
        $newsList = $this->getListNews($newsPage);
        foreach ($newsList as $news) {
            $url = self::SITE_URL . $news['url'];
            $contentPage = $this->getPageContent($url, false);
            if (!$contentPage) {

                $title = $news['title'];
                $date = $this->getDate($news['date']);
                $image = $this->getHeadUrl($news['image']);
                $description = $news['description'];

                $posts[] = new NewsPost(
                    self::class,
                    $title,
                    $description,
                    $date,
                    $url,
                    $image
                );

                continue;
            }

            $title = $news['title'];
            $date = $this->getDate($news['date']);
            $image = $this->getHeadUrl($news['image']);
            $description = $news['description'];

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $itemCrawler = new Crawler($contentPage);
            $content = $itemCrawler->filterXPath('//div[@class="news-detail"]');
            if (!$content->getNode(0)) {
                continue;
            }


            $imgSrc = $content->filterXPath('//img[@class="detail_picture"]');
            if (!$post->image && $imgSrc->getNode(0)) {
                $post->image = $this->getHeadUrl($imgSrc->attr('src'));
            }
            $description = '';
            $descriptionSrc = $content->filterXPath('//div[@class="news-detail"]')->children();
            foreach ($descriptionSrc as $key => $item) {
                if ($key < 2) {
                    continue;
                }
                foreach ($item->childNodes as $value) {
                    if ($value->attributes && $value->getAttribute('class') == 'adsman') {
                        continue;
                    }
                    if (!$description && ($text = $this->clearText($value->nodeValue)) && $text != $title) {
                        $description = $text;
                    }
                }
            }
            if ($description) {
                $post->description = $description;
            }

            $newContentCrawler = $content->filterXPath('//div[@class="news-detail"]');
            foreach ($newContentCrawler as $contentNew) {
                foreach ($contentNew->childNodes as $key => $childNode) {
                    if ($key < 7) {
                        continue;
                    }
                    if ($childNode->attributes && strpos($childNode->getAttribute('class'), 'news-detail-share') !== false) {
                        continue;
                    }
                    $this->setItemPostValue($post, $childNode);
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
     * @param NewsPost $post
     * @param DOMNode $node
     */
    protected function setItemPostValue (NewsPost $post, DOMNode $node): void {
        $nodeValue = $this->clearText($node->nodeValue, [$post->description]);
        if (in_array($node->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']) && $nodeValue) {

            $this->addItemPost($post, NewsPostItem::TYPE_HEADER, $nodeValue, null, null, (int)substr($node->nodeName, 1));

        } elseif ($node->nodeName == 'a' && strpos($href = $this->getHeadUrl($node->getAttribute('href')), 'http') !== false) {

            if ($node->childNodes && $node->firstChild && $node->firstChild->nodeName == 'img') {
                $imgSrc = $this->getHeadUrl($node->firstChild->getAttribute('src'));
                if (!$post->image) {

                    $post->image = $imgSrc;

                } else {

                    $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, $node->getAttribute('title'), $imgSrc);

                }
            } else {

                $this->addItemPost($post, NewsPostItem::TYPE_LINK, $nodeValue, null, $href);

            }

        } elseif ($node->nodeName == 'img' && ($imgSrc = $this->getHeadUrl($node->getAttribute('src'))) != $post->image && getimagesize($imgSrc)) {

            $this->addItemPost($post, NewsPostItem::TYPE_IMAGE, $node->getAttribute('title'), $imgSrc);

        } elseif ($node->nodeName == 'iframe') {
            $src = $this->getHeadUrl($node->getAttribute('src'));
            if (strpos($src, 'youtube') !== false) {
                $youId = basename(parse_url($src, PHP_URL_PATH));
                $titleYou = $node->getAttribute('title');

                $this->addItemPost($post, NewsPostItem::TYPE_VIDEO, $titleYou, null, null, null, $youId);

            }
        } elseif ($nodeValue && $node->parentNode->nodeName == 'blockquote') {

            $this->addItemPost($post, NewsPostItem::TYPE_QUOTE, $nodeValue);

        } elseif ($node->childNodes->count()) {

            foreach ($node->childNodes as $childNode) {

                $this->setItemPostValue($post, $childNode);
            }

        }  elseif ($nodeValue && $nodeValue != $post->description && mb_strpos($post->description, $nodeValue) === false) {

            $this->addItemPost($post, NewsPostItem::TYPE_TEXT, $nodeValue);

        }
    }

    /**
     *
     * @param string $date
     *
     * @return string
     */
    protected function getDate(string $date = ''): string
    {
        $date = mb_substr($date, 0, mb_stripos($date, '|'));
        $date = str_replace(',', '', $date);
        $now = new DateTime();
        $today = $now->format('Y-m-d');
        $time = $now->format('H:i:s');
        $date = mb_strtolower($date);
        $yesterday = $now->sub(new DateInterval('P1D'))->format('Y-m-d');
        $ruMonths = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря', 'сегодня', 'вчера'];
        $enMonths = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', $today, $yesterday];
        $newDate = new DateTime(str_ireplace($ruMonths, $enMonths, $date));
        $newDate->setTimezone(new DateTimeZone("UTC"));
        $newDate = $newDate->format("Y-m-d H:i:s");
        if (strpos($newDate, '00:00:00') != false) {
            $newDate = str_replace('00:00:00', $time, $newDate);
        }
        return $newDate;
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

        $code = urldecode('%e2%80%8b');
        if (mb_stripos($url, $code) !== false) {
            $url = str_replace($code, urlencode($code), $url);
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
        $list = $crawler->filterXPath('//div[@class="paragraph"]/ul')->children();
        foreach ($list as $item) {
            $itemCrawler = new Crawler($item);
            $title = $itemCrawler->filterXPath('//div[@class="articles_cont_title"]/a')->text();
            $href = $itemCrawler->filterXPath('//div[@class="articles_cont_title"]/a')->attr('href');
            $date = $itemCrawler->filterXPath('//div[@class="articles_data"]')->text();
            $description = $itemCrawler->filterXPath('//div[@class="articles_cont"]/p[1]')->text();
            $image = $itemCrawler->filterXPath('//div[@class="paragraph_img"]/*/img')->attr('src');
            if (!in_array($href, $records)) {
                $records[] = [
                    'title'         => $title,
                    'description'  => $description,
                    'url'           => $href,
                    'date'          => $date,
                    'image'         => $image,
                ];
            }
        }

        return $records;
    }

    /**
     *
     * @param string $uri
     * @param bool $isMain
     *
     * @return string
     * @throws RuntimeException|\Exception
     */
    private function getPageContent(string $uri, bool $isMain = true): string
    {
        $curl = Helper::getCurl();

        $result = $curl->get($uri);
        $responseInfo = $curl->getInfo();

        $httpCode = $responseInfo['http_code'] ?? null;

        if ($httpCode >= 200 && $httpCode < 400) {
            return $result;
        }

        if ($isMain) {
            throw new RuntimeException("Не удалось скачать страницу {$uri}, код ответа {$httpCode}");
        } else {
            return '';
        }

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