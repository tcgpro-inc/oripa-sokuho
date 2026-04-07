<?php

declare(strict_types=1);

namespace App;

/**
 * X API v2 検索クライアント（Bearer Token認証）
 *
 * App-only認証で Recent Search を行い、結果をファイルキャッシュする。
 */
class XSearch
{
    private const API_BASE = 'https://api.x.com/2';
    private const TOKEN_URL = 'https://api.x.com/oauth2/token';

    private string $consumerKey;
    private string $consumerSecret;
    private string $cacheDir;
    private int $cacheTtl;

    public function __construct(array $config, string $cacheDir = '', int $cacheTtl = 3600)
    {
        $this->consumerKey = $config['consumer_key'];
        $this->consumerSecret = $config['consumer_secret'];
        $this->cacheDir = $cacheDir ?: __DIR__ . '/../data/cache';
        $this->cacheTtl = $cacheTtl;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * プレキャン（プレゼントキャンペーン）ツイートを検索
     *
     * @param string $keyword カテゴリキーワード（例: "ポケカ"）
     * @param int    $maxResults 取得件数（10〜100）
     * @return array{tweets: array, users: array, cached: bool}
     */
    public function searchCampaignTweets(string $keyword, int $maxResults = 20): array
    {
        $cacheKey = 'campaign_' . md5($keyword);
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return array_merge($cached, ['cached' => true]);
        }

        $bearer = $this->getBearerToken();

        $query = "{$keyword} (プレゼントキャンペーン OR プレゼント企画 OR プレキャン OR \"プレゼント🎁\" OR \"BOXプレゼント\" OR \"BOXをプレゼント\" OR \"名様にプレゼント\") -is:retweet -当選 -届きました -届いた -ありがとうございます";
        $startTime = date('Y-m-d\TH:i:s\Z', strtotime('-7 days'));

        $params = http_build_query([
            'query' => $query,
            'start_time' => $startTime,
            'max_results' => min($maxResults, 100),
            'tweet.fields' => 'created_at,author_id,public_metrics',
            'expansions' => 'author_id',
            'user.fields' => 'username,name',
        ]);

        $url = self::API_BASE . "/tweets/search/recent?{$params}";

        $response = $this->httpGet($url, $bearer);

        $tweets = [];
        $users = [];

        if (isset($response['includes']['users'])) {
            foreach ($response['includes']['users'] as $u) {
                $users[$u['id']] = $u;
            }
        }

        if (isset($response['data'])) {
            foreach ($response['data'] as $tweet) {
                $user = $users[$tweet['author_id']] ?? null;
                $tweets[] = [
                    'id' => $tweet['id'],
                    'text' => $tweet['text'],
                    'created_at' => $tweet['created_at'],
                    'username' => $user['username'] ?? '',
                    'name' => $user['name'] ?? '',
                    'metrics' => $tweet['public_metrics'] ?? [],
                ];
            }
        }

        $result = ['tweets' => $tweets, 'users' => $users];
        $this->setCache($cacheKey, $result);

        return array_merge($result, ['cached' => false]);
    }

    /**
     * ツイートデータから表示用の構造化データを抽出
     */
    public static function parseTweetData(array $tweet): array
    {
        $text = $tweet['text'];
        $metrics = $tweet['metrics'] ?? [];
        $rt = $metrics['retweet_count'] ?? 0;
        $like = $metrics['like_count'] ?? 0;

        return [
            'id' => $tweet['id'],
            'username' => $tweet['username'],
            'name' => $tweet['name'],
            'title' => self::extractTitle($text),
            'prize' => self::extractPrize($text),
            'deadline' => self::extractDeadline($text),
            'deadline_raw' => self::extractDeadlineRaw($text),
            'engagement' => $rt + $like,
            'url' => "https://x.com/{$tweet['username']}/status/{$tweet['id']}",
        ];
    }

    private static function extractTitle(string $text): string
    {
        // URLを除去
        $clean = preg_replace('#https?://\S+#', '', $text);
        // ハッシュタグを除去
        $clean = preg_replace('/#\S+/', '', $clean);
        // 絵文字・装飾記号を除去
        $clean = preg_replace('/[🎁🔥💝✨🎊🎉💕❤️⚡️✅◎📢🗓️👑📸💥🍎🌈🥷⭐️★☆◆■▼▲━─═＝→←↓↑＼／\\\\\/]+/u', '', $clean);
        // 先頭の空行・空白を除去して1行目を取得
        $lines = preg_split('/[\r\n]+/', trim($clean));
        $title = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if (mb_strlen($line) > 3) {
                $title = $line;
                break;
            }
        }
        if ($title === '') {
            $title = mb_substr(trim($clean), 0, 60);
        }
        return mb_strlen($title) > 60 ? mb_substr($title, 0, 60) . '…' : $title;
    }

    private static function extractPrize(string $text): string
    {
        // 「〜をプレゼント」「〜プレゼント」「〜が当たる」パターン
        if (preg_match('/[「｢『]([^」｣』]+)[」｣』]\s*(?:を)?(?:プレゼント|が当たる)/u', $text, $m)) {
            return mb_substr($m[1], 0, 30);
        }
        // BOX名パターン
        if (preg_match('/([\w\s\-ー]+(?:BOX|ボックス|box))/ui', $text, $m)) {
            return mb_substr(trim($m[1]), 0, 30);
        }
        // 「N BOXをプレゼント」「NBOXプレゼント」
        if (preg_match('/(\d+\s*BOX)\s*(?:を)?プレゼント/ui', $text, $m)) {
            return $m[1];
        }
        // カード名 + プレゼント
        if (preg_match('/(.{2,20}?)(?:を|の|が)\s*(?:\d+名様に\s*)?プレゼント/u', $text, $m)) {
            $prize = trim(preg_replace('/[🎁✨🔥💝]+/u', '', $m[1]));
            if (mb_strlen($prize) >= 2) {
                return mb_substr($prize, 0, 30);
            }
        }
        // ptプレゼント
        if (preg_match('/([\d,]+\s*(?:pt|ポイント|円))\s*(?:を)?プレゼント/ui', $text, $m)) {
            return $m[1];
        }
        return '―';
    }

    private static function extractDeadline(string $text): string
    {
        $raw = self::extractDeadlineRaw($text);
        if ($raw === '') {
            return '―';
        }
        // YYYY-MM-DD → 表示用
        $ts = strtotime($raw);
        return $ts ? date('n/j', $ts) : '―';
    }

    private static function extractDeadlineRaw(string $text): string
    {
        $year = date('Y');
        // 「4月10日」「4/10」パターン（〆切・締切・まで・受付・応募 の近くにある日付）
        if (preg_match('/(?:〆切|締切|まで|応募期間|受付)[^\d]{0,10}(\d{1,2})[\/月](\d{1,2})/u', $text, $m)) {
            return sprintf('%s-%02d-%02d', $year, (int)$m[1], (int)$m[2]);
        }
        // 日付が先に来るパターン「4/10まで」「4月10日〆切」
        if (preg_match('/(\d{1,2})[\/月](\d{1,2})[日]?\s*(?:まで|〆切|締切)/u', $text, $m)) {
            return sprintf('%s-%02d-%02d', $year, (int)$m[1], (int)$m[2]);
        }
        // 「4月10日(木)まで」
        if (preg_match('/(\d{1,2})[\/月](\d{1,2})[日]?\s*[\(（][月火水木金土日][\)）]\s*(?:まで|〆切|締切)/u', $text, $m)) {
            return sprintf('%s-%02d-%02d', $year, (int)$m[1], (int)$m[2]);
        }
        return '';
    }

    private function getBearerToken(): string
    {
        $cacheKey = 'bearer_token';
        $cached = $this->getCache($cacheKey);
        if ($cached !== null && isset($cached['token'])) {
            return $cached['token'];
        }

        $credentials = base64_encode(
            urlencode($this->consumerKey) . ':' . urlencode($this->consumerSecret)
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::TOKEN_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $credentials,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Bearer Token取得失敗 (HTTP ' . $httpCode . ')');
        }

        $data = json_decode($response, true);
        if (!isset($data['access_token'])) {
            throw new \RuntimeException('Bearer Token取得失敗: access_token なし');
        }

        $this->setCache($cacheKey, ['token' => $data['access_token']], 3600);

        return $data['access_token'];
    }

    private function httpGet(string $url, string $bearerToken): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearerToken],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException('X API検索エラー (HTTP ' . $httpCode . '): ' . $response);
        }

        return json_decode($response, true) ?? [];
    }

    private function getCache(string $key): ?array
    {
        $file = $this->cacheDir . '/' . $key . '.json';
        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data || !isset($data['expires_at'])) {
            return null;
        }

        if (time() > $data['expires_at']) {
            unlink($file);
            return null;
        }

        return $data['payload'];
    }

    private function setCache(string $key, array $payload, ?int $ttl = null): void
    {
        $file = $this->cacheDir . '/' . $key . '.json';
        $data = [
            'expires_at' => time() + ($ttl ?? $this->cacheTtl),
            'payload' => $payload,
        ];
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
