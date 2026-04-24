<?php

/**
 * 指定した1件のツイート本文を取得するCLIスクリプト（記事作成用）
 *
 * Usage:
 *   php bin/fetch-tweet.php <tweet_id>
 *   php bin/fetch-tweet.php <tweet_id1> <tweet_id2> ...
 *
 * .env の X_CONSUMER_KEY / X_CONSUMER_SECRET から App-only Bearer を発行し、
 * GET /2/tweets/:id を叩いて本文・作成日時・投稿者・メディアURLを表示する。
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/XSearch.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        putenv($line);
    }
}

$ids = array_slice($argv, 1);
if (empty($ids)) {
    fwrite(STDERR, "Usage: php bin/fetch-tweet.php <tweet_id> [<tweet_id> ...]\n");
    exit(1);
}

$consumerKey = getenv('X_CONSUMER_KEY');
$consumerSecret = getenv('X_CONSUMER_SECRET');
if (!$consumerKey || !$consumerSecret) {
    fwrite(STDERR, "X_CONSUMER_KEY / X_CONSUMER_SECRET が未設定です\n");
    exit(1);
}

$bearer = getBearerToken($consumerKey, $consumerSecret);

foreach ($ids as $id) {
    $id = trim($id);
    if (!preg_match('/^\d+$/', $id)) {
        fwrite(STDERR, "[skip] 無効なID: {$id}\n");
        continue;
    }

    $params = http_build_query([
        'tweet.fields' => 'created_at,author_id,public_metrics,entities,attachments',
        'expansions' => 'author_id,attachments.media_keys',
        'user.fields' => 'username,name',
        'media.fields' => 'url,preview_image_url,type',
    ]);
    $url = "https://api.x.com/2/tweets/{$id}?{$params}";

    $response = httpGet($url, $bearer);

    echo "===== Tweet {$id} =====\n";
    if (isset($response['errors'])) {
        foreach ($response['errors'] as $e) {
            echo "ERROR: " . ($e['detail'] ?? $e['title'] ?? json_encode($e)) . "\n";
        }
    }
    if (!isset($response['data'])) {
        echo "(no data)\n\n";
        continue;
    }

    $tweet = $response['data'];
    $users = [];
    foreach ($response['includes']['users'] ?? [] as $u) {
        $users[$u['id']] = $u;
    }
    $media = [];
    foreach ($response['includes']['media'] ?? [] as $m) {
        $media[$m['media_key']] = $m;
    }
    $author = $users[$tweet['author_id']] ?? null;

    echo "Author   : " . ($author['name'] ?? '?') . " (@" . ($author['username'] ?? '?') . ")\n";
    echo "CreatedAt: " . ($tweet['created_at'] ?? '?') . "\n";
    $m = $tweet['public_metrics'] ?? [];
    echo sprintf("Metrics  : RT=%d Like=%d Reply=%d Quote=%d\n",
        $m['retweet_count'] ?? 0, $m['like_count'] ?? 0,
        $m['reply_count'] ?? 0, $m['quote_count'] ?? 0);
    echo "URL      : https://x.com/" . ($author['username'] ?? 'i') . "/status/{$id}\n";
    echo "---- text ----\n";
    echo $tweet['text'] . "\n";
    echo "--------------\n";

    $mediaKeys = $tweet['attachments']['media_keys'] ?? [];
    if ($mediaKeys) {
        echo "Media:\n";
        foreach ($mediaKeys as $key) {
            $mm = $media[$key] ?? null;
            if (!$mm) continue;
            $u = $mm['url'] ?? $mm['preview_image_url'] ?? '?';
            echo "  - [{$mm['type']}] {$u}\n";
        }
    }

    $urls = $tweet['entities']['urls'] ?? [];
    if ($urls) {
        echo "Links:\n";
        foreach ($urls as $u) {
            $expanded = $u['expanded_url'] ?? $u['url'] ?? '';
            echo "  - {$expanded}\n";
        }
    }
    echo "\n";
}

function getBearerToken(string $key, string $secret): string
{
    $credentials = base64_encode(urlencode($key) . ':' . urlencode($secret));
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.x.com/oauth2/token',
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
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        fwrite(STDERR, "Bearer Token取得失敗 (HTTP {$code}): {$response}\n");
        exit(1);
    }
    $data = json_decode($response, true);
    return $data['access_token'] ?? '';
}

function httpGet(string $url, string $bearer): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearer],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) {
        fwrite(STDERR, "API Error (HTTP {$code}): {$response}\n");
        return ['errors' => [['title' => 'HTTP ' . $code, 'detail' => $response]]];
    }
    return json_decode($response, true) ?? [];
}
