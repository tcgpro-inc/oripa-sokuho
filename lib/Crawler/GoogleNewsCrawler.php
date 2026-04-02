<?php

declare(strict_types=1);

namespace App\Crawler;

class GoogleNewsCrawler
{
    private const RSS_BASE = 'https://news.google.com/rss/search?hl=ja&gl=JP&ceid=JP:ja&q=';

    private const KEYWORDS = [
        'オリパ',
        'ポケカ オリパ',
        'TCG オリパ',
        'オンラインオリパ',
    ];

    private DraftStore $store;

    public function __construct(DraftStore $store)
    {
        $this->store = $store;
    }

    /**
     * Google News RSSを巡回してドラフトに保存
     * @return array{found: int, new: int}
     */
    public function crawl(): array
    {
        $logId = $this->store->logStart('google_news');
        $found = 0;
        $new = 0;

        try {
            $seenUrls = [];

            foreach (self::KEYWORDS as $keyword) {
                $url = self::RSS_BASE . urlencode($keyword);
                $xml = $this->fetchRss($url);
                if ($xml === null) {
                    continue;
                }

                foreach ($xml->channel->item as $item) {
                    $link = (string) $item->link;

                    // キーワード間の重複スキップ
                    if (isset($seenUrls[$link])) {
                        continue;
                    }
                    $seenUrls[$link] = true;
                    $found++;

                    $pubDate = null;
                    if (!empty((string) $item->pubDate)) {
                        try {
                            $dt = new \DateTimeImmutable((string) $item->pubDate);
                            $pubDate = $dt->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('c');
                        } catch (\Exception $e) {
                            // パース失敗は無視
                        }
                    }

                    // <source url="https://prtimes.jp">PR TIMES</source> から元サイト情報を取得
                    $sourceSiteUrl = null;
                    $sourceSiteName = null;
                    if (isset($item->source)) {
                        $sourceSiteName = (string) $item->source;
                        $attrs = $item->source->attributes();
                        if (isset($attrs['url'])) {
                            $sourceSiteUrl = (string) $attrs['url'];
                        }
                    }

                    // タイトルから " - サイト名" を除去
                    $title = (string) $item->title;
                    if ($sourceSiteName && str_ends_with($title, ' - ' . $sourceSiteName)) {
                        $title = substr($title, 0, -strlen(' - ' . $sourceSiteName));
                    }

                    // 元サイトURLからOG画像を取得試行
                    $thumbnailUrl = null;
                    if ($sourceSiteUrl) {
                        $thumbnailUrl = $this->fetchOgImage($sourceSiteUrl, $title);
                    }

                    $inserted = $this->store->insertDraft([
                        'source' => 'google_news',
                        'source_url' => $link,
                        'source_site_url' => $sourceSiteUrl,
                        'source_site_name' => $sourceSiteName,
                        'title' => $title,
                        'snippet' => strip_tags((string) $item->description),
                        'thumbnail_url' => $thumbnailUrl,
                        'fetched_at' => (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s'),
                        'published_at' => $pubDate,
                    ]);

                    if ($inserted) {
                        $new++;
                    }
                }

                // レート制限
                usleep(500_000);
            }

            $this->store->logFinish($logId, $found, $new);
        } catch (\Throwable $e) {
            $this->store->logFinish($logId, $found, $new, $e->getMessage());
            throw $e;
        }

        return ['found' => $found, 'new' => $new];
    }

    /**
     * 元サイトのページからog:imageを取得
     * 全サイトに対してfetchすると遅いので、主要サイトのみ対応
     */
    private function fetchOgImage(?string $sourceSiteUrl, string $title): ?string
    {
        if ($sourceSiteUrl === null) {
            return null;
        }

        // PR TIMESの場合: Google検索で元記事ページを特定するのは困難なのでスキップ
        // 代わりに、source_site_urlを保存しておき、記事化時に手動でサムネを設定する運用
        // ただし、PR TIMESのRSSから直接取得した記事にはサムネが付くので問題は限定的

        return null;
    }

    private function fetchRss(string $url): ?\SimpleXMLElement
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; OripaNewsBot/1.0)',
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false || $httpCode !== 200) {
            return null;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();

        return $xml ?: null;
    }
}
