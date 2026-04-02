<?php

declare(strict_types=1);

namespace App\Crawler;

class FiveChCrawler
{
    /**
     * 板定義: [サーバー, 板名, 表示名]
     */
    private const BOARDS = [
        ['pug', 'tcg', 'TCG板'],
        ['eagle', 'livejupiter', 'なんJ'],
        ['nova', 'livegalileo', 'なんG'],
    ];

    /**
     * スレタイフィルタ用キーワード
     */
    private const KEYWORDS = [
        'オリパ',
        'ポケカ',
        '遊戯王',
        'ワンピカード',
        'ワンピースカード',
        'TCG',
    ];

    private const USER_AGENT = 'Monazilla/1.00 (OripaNewsBot/1.0)';
    private const RATE_LIMIT_SEC = 3;

    private DraftStore $store;

    public function __construct(DraftStore $store)
    {
        $this->store = $store;
    }

    /**
     * 5ch板を巡回してドラフトに保存
     * @return array{found: int, new: int}
     */
    public function crawl(): array
    {
        $logId = $this->store->logStart('5ch');
        $found = 0;
        $new = 0;

        try {
            foreach (self::BOARDS as [$server, $board, $boardName]) {
                $threads = $this->fetchSubjectTxt($server, $board);
                if ($threads === null) {
                    continue;
                }

                $matched = $this->filterThreads($threads);
                $found += count($matched);

                foreach ($matched as $thread) {
                    $threadUrl = "https://{$server}.5ch.net/test/read.cgi/{$board}/{$thread['id']}/";
                    $snippet = $this->fetchThreadSnippet($server, $board, $thread['id']);

                    $inserted = $this->store->insertDraft([
                        'source' => '5ch',
                        'source_url' => $threadUrl,
                        'title' => $thread['title'],
                        'snippet' => $snippet,
                        'thumbnail_url' => null,
                        'board' => $boardName,
                        'fetched_at' => (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s'),
                        'published_at' => null,
                    ]);

                    if ($inserted) {
                        $new++;
                    }

                    sleep(self::RATE_LIMIT_SEC);
                }

                sleep(self::RATE_LIMIT_SEC);
            }

            $this->store->logFinish($logId, $found, $new);
        } catch (\Throwable $e) {
            $this->store->logFinish($logId, $found, $new, $e->getMessage());
            throw $e;
        }

        return ['found' => $found, 'new' => $new];
    }

    /**
     * subject.txt を取得してパース
     * @return array<array{id: string, title: string, count: int}>|null
     */
    private function fetchSubjectTxt(string $server, string $board): ?array
    {
        $url = "https://{$server}.5ch.net/{$board}/subject.txt";
        $body = $this->fetch($url);
        if ($body === null) {
            return null;
        }

        // Shift_JIS → UTF-8
        $body = mb_convert_encoding($body, 'UTF-8', 'SJIS-win');

        $threads = [];
        foreach (explode("\n", trim($body)) as $line) {
            // 形式: "1234567890.dat<>スレッドタイトル (123)"
            if (preg_match('/^(\d+)\.dat<>(.+)\s+\((\d+)\)$/', $line, $m)) {
                $threads[] = [
                    'id' => $m[1],
                    'title' => $m[2],
                    'count' => (int) $m[3],
                ];
            }
        }

        return $threads;
    }

    /**
     * キーワードにマッチするスレッドを抽出
     */
    private function filterThreads(array $threads): array
    {
        $matched = [];
        foreach ($threads as $thread) {
            foreach (self::KEYWORDS as $keyword) {
                if (mb_stripos($thread['title'], $keyword) !== false) {
                    $matched[] = $thread;
                    break;
                }
            }
        }
        return $matched;
    }

    /**
     * スレッドの先頭5レスを取得してテキスト化
     */
    private function fetchThreadSnippet(string $server, string $board, string $threadId): ?string
    {
        $url = "https://{$server}.5ch.net/test/read.cgi/{$board}/{$threadId}/1-5";
        $body = $this->fetch($url);
        if ($body === null) {
            return null;
        }

        // HTML → Shift_JIS → UTF-8
        $body = mb_convert_encoding($body, 'UTF-8', 'SJIS-win');

        // レス本文を抽出（<dd> タグ内）
        $lines = [];
        if (preg_match_all('/<dd>\s*(.+?)\s*<\/dd>/s', $body, $matches)) {
            foreach ($matches[1] as $dd) {
                $text = strip_tags(str_replace('<br>', "\n", $dd));
                $text = trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
                if ($text !== '') {
                    $lines[] = $text;
                }
            }
        }

        return $lines ? implode("\n---\n", $lines) : null;
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false || $httpCode !== 200) {
            return null;
        }

        return $body;
    }
}
