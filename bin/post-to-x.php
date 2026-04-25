#!/usr/bin/env php
<?php

/**
 * 記事をXに投稿するCLIスクリプト
 *
 * Usage:
 *   php bin/post-to-x.php --slug=carderia-psa10-pokeka-oripa
 *   php bin/post-to-x.php --slug=carderia-psa10-pokeka-oripa --text="カスタム投稿文"
 *   php bin/post-to-x.php --slug=carderia-psa10-pokeka-oripa --dry-run
 *   php bin/post-to-x.php --list                              # 未投稿記事一覧
 *   php bin/post-to-x.php --list=all                          # 全記事の投稿状態
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Content.php';

use App\TwitterClient;

$xPostsFile = __DIR__ . '/../data/x-posts.json';

/**
 * 投稿管理JSONを読み込む
 */
function loadXPosts(string $file): array
{
    if (!file_exists($file)) {
        return ['posts' => []];
    }
    $data = json_decode(file_get_contents($file), true);
    return $data ?: ['posts' => []];
}

/**
 * 投稿管理JSONに記録を追加する
 */
function saveXPost(string $file, array $record): void
{
    $data = loadXPosts($file);
    $data['posts'][] = $record;
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}

/**
 * 投稿済みslugの一覧を取得
 */
function getPostedSlugs(string $file): array
{
    $data = loadXPosts($file);
    return array_column($data['posts'], 'slug');
}

$options = getopt('', ['slug:', 'text:', 'reply:', 'dry-run', 'list::']);

// --- 一覧表示 ---
if (isset($options['list']) || array_key_exists('list', $options)) {
    $showAll = ($options['list'] ?? '') === 'all';
    $content = new Content(__DIR__ . '/../content/articles');
    $articles = $content->getAllArticles();
    $postedSlugs = getPostedSlugs($xPostsFile);
    $xPosts = loadXPosts($xPostsFile)['posts'];

    // slug => post record のマップ
    $postMap = [];
    foreach ($xPosts as $p) {
        $postMap[$p['slug']] = $p;
    }

    $unposted = [];
    $posted = [];

    foreach ($articles as $a) {
        $slug = $a['slug'];
        if (in_array($slug, $postedSlugs, true)) {
            $posted[] = $a;
        } else {
            $unposted[] = $a;
        }
    }

    echo "=== 未投稿記事 (" . count($unposted) . "件) ===\n\n";
    foreach ($unposted as $a) {
        $title = mb_strimwidth($a['meta']['title'] ?? '', 0, 60, '...');
        $date = $a['meta']['published_at'] ?? '';
        echo sprintf("  %-22s %s\n", $a['slug'], $title);
    }

    if ($showAll) {
        echo "\n=== 投稿済み記事 (" . count($posted) . "件) ===\n\n";
        foreach ($posted as $a) {
            $slug = $a['slug'];
            $title = mb_strimwidth($a['meta']['title'] ?? '', 0, 50, '...');
            $postedAt = $postMap[$slug]['posted_at'] ?? '不明';
            $tweetId = $postMap[$slug]['tweet_id'] ?? '';
            echo sprintf("  %-22s %s  [%s]\n", $slug, $title, $postedAt);
        }
    }

    echo "\n合計: " . count($articles) . "件 (投稿済み " . count($posted) . " / 未投稿 " . count($unposted) . ")\n";
    exit(0);
}

// --- 投稿 ---
if (!isset($options['slug'])) {
    echo "Usage:\n";
    echo "  php bin/post-to-x.php --slug=<slug> [--text=\"...\"] [--reply=\"...\"] [--dry-run]\n";
    echo "  php bin/post-to-x.php --list          # 未投稿記事一覧\n";
    echo "  php bin/post-to-x.php --list=all       # 全記事の投稿状態\n";
    exit(1);
}

$slug = $options['slug'];
$dryRun = isset($options['dry-run']);

// 既に投稿済みか確認
$postedSlugs = getPostedSlugs($xPostsFile);
if (in_array($slug, $postedSlugs, true) && !$dryRun) {
    echo "警告: この記事は既にXに投稿済みです: {$slug}\n";
    echo "続行しますか？ (y/N): ";
    $answer = trim(fgets(STDIN));
    if (strtolower($answer) !== 'y') {
        echo "中止しました。\n";
        exit(0);
    }
}

// 記事取得
$content = new Content(__DIR__ . '/../content/articles');
$article = $content->getArticle($slug);

if (!$article) {
    echo "エラー: 記事が見つかりません: {$slug}\n";
    exit(1);
}

$meta = $article['meta'];
$title = $meta['title'] ?? '';
$category = $meta['category'] ?? 'other';
$thumbnailUrl = $meta['thumbnail_url'] ?? '';
$articleUrl = "https://oripanews.com/article/{$slug}/";

$categoryTag = match ($category) {
    'pokeka' => '#ポケカ',
    'flame' => '#オリパ炎上',
    'free' => '#無料オリパ',
    default => '#トレカ',
};

// 投稿文（カスタムがあればそちらを使用）
$mainText = $options['text'] ?? $title . "\n\n" . $categoryTag . ' #オリパ';
$replyText = $options['reply'] ?? "▼ 詳細はこちら\n{$articleUrl}";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "【1投稿目】\n";
echo $mainText . "\n";
if ($thumbnailUrl) {
    echo "[画像: {$thumbnailUrl}]\n";
}
echo "\n【リプ】\n";
echo $replyText . "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

if ($dryRun) {
    echo "\n(dry-run: 実際には投稿しません)\n";
    exit(0);
}

// 投稿実行
$twitterConfigFile = __DIR__ . '/../config/twitter.php';
if (!file_exists($twitterConfigFile)) {
    echo "エラー: config/twitter.php が見つかりません\n";
    exit(1);
}

$twitterConfig = require $twitterConfigFile;
$twitter = new TwitterClient($twitterConfig);

try {
    $result = $twitter->postArticle($mainText, $replyText, $thumbnailUrl);

    // 投稿管理JSONに記録
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('c');
    saveXPost($xPostsFile, [
        'slug' => $slug,
        'tweet_id' => $result['tweet_id'],
        'reply_id' => $result['reply_id'],
        'text' => $mainText,
        'reply_text' => $replyText,
        'posted_at' => $now,
    ]);

    echo "\n投稿完了!\n";
    echo "ツイート: https://x.com/i/status/{$result['tweet_id']}\n";
    echo "リプライ: https://x.com/i/status/{$result['reply_id']}\n";
} catch (\Throwable $e) {
    echo "\n投稿エラー: {$e->getMessage()}\n";
    exit(1);
}
