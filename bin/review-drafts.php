#!/usr/bin/env php
<?php

/**
 * ドラフト確認・承認・記事化 CLIツール
 *
 * Usage:
 *   php bin/review-drafts.php                  # 新着一覧
 *   php bin/review-drafts.php --status=approved # 承認済み一覧
 *   php bin/review-drafts.php --approve=3       # ID:3を承認
 *   php bin/review-drafts.php --reject=5        # ID:5を却下
 *   php bin/review-drafts.php --publish=3       # ID:3を記事化(.md生成)
 *   php bin/review-drafts.php --detail=3        # ID:3の詳細表示
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Crawler\DraftStore;

$dbPath = __DIR__ . '/../data/crawl.db';
$articlesDir = __DIR__ . '/../content/articles';
$store = new DraftStore($dbPath);

$options = getopt('', ['status:', 'approve:', 'reject:', 'publish:', 'detail:']);

// --- 承認 ---
if (isset($options['approve'])) {
    $id = (int) $options['approve'];
    $draft = $store->getDraft($id);
    if (!$draft) {
        echo "エラー: ID:{$id} が見つかりません\n";
        exit(1);
    }
    $store->updateStatus($id, 'approved');
    echo "ID:{$id} を承認しました: {$draft['title']}\n";
    exit(0);
}

// --- 却下 ---
if (isset($options['reject'])) {
    $id = (int) $options['reject'];
    $draft = $store->getDraft($id);
    if (!$draft) {
        echo "エラー: ID:{$id} が見つかりません\n";
        exit(1);
    }
    $store->updateStatus($id, 'rejected');
    echo "ID:{$id} を却下しました: {$draft['title']}\n";
    exit(0);
}

// --- 詳細表示 ---
if (isset($options['detail'])) {
    $id = (int) $options['detail'];
    $draft = $store->getDraft($id);
    if (!$draft) {
        echo "エラー: ID:{$id} が見つかりません\n";
        exit(1);
    }
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "ID:      {$draft['id']}\n";
    echo "タイトル: {$draft['title']}\n";
    echo "ソース:  {$draft['source']}" . ($draft['board'] ? " ({$draft['board']})" : '') . "\n";
    echo "URL:     {$draft['source_url']}\n";
    echo "状態:    {$draft['status']}\n";
    echo "取得日:  {$draft['fetched_at']}\n";
    echo "公開日:  " . ($draft['published_at'] ?? '不明') . "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    if ($draft['snippet']) {
        echo "\n--- スニペット ---\n";
        echo $draft['snippet'] . "\n";
    }
    exit(0);
}

// --- 記事化 ---
if (isset($options['publish'])) {
    $id = (int) $options['publish'];
    $draft = $store->getDraft($id);
    if (!$draft) {
        echo "エラー: ID:{$id} が見つかりません\n";
        exit(1);
    }

    $date = date('Y-m-d');
    $slug = generateSlug($draft['title'], $date);
    $filename = "{$date}-{$slug}.md";
    $filepath = $articlesDir . '/' . $filename;

    if (file_exists($filepath)) {
        echo "エラー: ファイルが既に存在します: {$filename}\n";
        exit(1);
    }

    $sourceName = match ($draft['source']) {
        'google_news' => 'ニュースサイト',
        '5ch' => '5ch',
        default => 'その他',
    };

    $category = detectCategory($draft['title']);
    $tag = '速報';
    $publishedAt = $draft['published_at'] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('c');

    $sanitizeYaml = fn(string $s): string => str_replace(
        ['\\', '"', "\n", "\r"],
        ['\\\\', '\\"', ' ', ''],
        $s
    );
    $safeTitle = $sanitizeYaml($draft['title']);
    $safeUrl = $sanitizeYaml($draft['source_url']);

    $frontmatter = <<<YAML
---
title: "{$safeTitle}"
tag: "{$tag}"
category: "{$category}"
source_url: "{$safeUrl}"
source_name: "{$sourceName}"
thumbnail_url: ""
slug: "{$slug}"
published_at: "{$publishedAt}"
hot: false
---
YAML;

    $body = "\n\n" . ($draft['snippet'] ?? '（本文を追記してください）') . "\n";
    $content = $frontmatter . $body;

    file_put_contents($filepath, $content);
    $store->updateStatus($id, 'published');

    echo "記事を生成しました: {$filename}\n";
    echo "※ 本文の編集が必要です: {$filepath}\n";

    // X (Twitter) に自動投稿
    $twitterConfigFile = __DIR__ . '/../config/twitter.php';
    if (file_exists($twitterConfigFile)) {
        try {
            $twitterConfig = require $twitterConfigFile;
            $twitter = new \App\TwitterClient($twitterConfig);

            $articleUrl = "https://oripanews.com/article/{$slug}/";
            $categoryTag = match ($category) {
                'pokeka' => '#ポケカ',
                'flame' => '#オリパ炎上',
                'free' => '#無料オリパ',
                default => '#トレカ',
            };

            // 1投稿目：サムネ + ヒキのある文章（リンクなし）
            $mainText = $draft['title'] . "\n\n" . $categoryTag . ' #オリパ';

            // リプ：リンク
            $replyText = "▼ 詳細はこちら\n{$articleUrl}";

            $thumbnailUrl = ''; // ドラフトにはサムネがないので空
            $result = $twitter->postArticle($mainText, $replyText, $thumbnailUrl);

            // 投稿管理JSONに記録
            $xPostsFile = __DIR__ . '/../data/x-posts.json';
            $xData = file_exists($xPostsFile) ? json_decode(file_get_contents($xPostsFile), true) : ['posts' => []];
            $xData['posts'][] = [
                'slug' => $slug,
                'tweet_id' => $result['tweet_id'],
                'reply_id' => $result['reply_id'],
                'text' => $mainText,
                'reply_text' => $replyText,
                'posted_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('c'),
            ];
            file_put_contents($xPostsFile, json_encode($xData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

            echo "X投稿完了: https://x.com/i/status/{$result['tweet_id']}\n";
        } catch (\Throwable $e) {
            echo "X投稿エラー（記事生成は成功）: {$e->getMessage()}\n";
        }
    }

    exit(0);
}

// --- 一覧表示 ---
$status = $options['status'] ?? 'new';
$drafts = $store->getDrafts($status);

if (empty($drafts)) {
    echo "ドラフトがありません (status={$status})\n";
    exit(0);
}

$count = count($drafts);
echo "=== ドラフト一覧 [status={$status}] ({$count}件) ===\n\n";

foreach ($drafts as $d) {
    $source = $d['source'];
    if ($d['board']) {
        $source .= "/{$d['board']}";
    }
    $title = mb_strimwidth($d['title'], 0, 60, '...');
    echo sprintf(
        "  [%d] %-14s %s  %s\n",
        $d['id'],
        $source,
        $d['fetched_at'],
        $title
    );
}

echo "\n操作: --detail=ID / --approve=ID / --reject=ID / --publish=ID\n";

// --- ユーティリティ ---

function generateSlug(string $title, string $date): string
{
    // タイトルからスラッグ生成（英数字・ハイフンのみ）
    // 日本語タイトルの場合は日付ベース
    $ascii = preg_replace('/[^a-zA-Z0-9\s-]/', '', $title);
    $ascii = trim($ascii);
    if ($ascii !== '') {
        $slug = strtolower(preg_replace('/[\s]+/', '-', $ascii));
        return mb_strimwidth($slug, 0, 50, '');
    }
    return 'article-' . str_replace('-', '', $date) . '-' . random_int(100, 999);
}

function detectCategory(string $title): string
{
    if (mb_stripos($title, 'ポケカ') !== false || mb_stripos($title, 'ポケモンカード') !== false) {
        return 'pokeka';
    }
    if (mb_stripos($title, '炎上') !== false || mb_stripos($title, '被害') !== false || mb_stripos($title, '逮捕') !== false) {
        return 'flame';
    }
    if (mb_stripos($title, '無料') !== false || mb_stripos($title, '0pt') !== false || mb_stripos($title, 'ゼロpt') !== false) {
        return 'free';
    }
    if (mb_stripos($title, '口コミ') !== false || mb_stripos($title, '評判') !== false || mb_stripos($title, '体験') !== false) {
        return 'review';
    }
    return 'guide';
}
