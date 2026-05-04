#!/usr/bin/env php
<?php

/**
 * ポケカ抽選・予約販売情報をXに投稿するスクリプト
 *
 * data/sales-info.json の items[] から status=pending を1件選んで投稿する。
 *
 * Usage:
 *   php bin/post-sales-info.php --list                    # 全件一覧（status別）
 *   php bin/post-sales-info.php --pending                 # 投稿候補一覧（pending のみ）
 *   php bin/post-sales-info.php --id=<id> [--dry-run]     # 指定ID投稿
 *   php bin/post-sales-info.php --auto [--dry-run]        # scheduled_at <= now の pending 1件を投稿（cron用）
 *   php bin/post-sales-info.php --expire                  # entry_end が過ぎた pending を expired に変更
 *
 * --auto は scheduled_at が設定された pending 項目のうち、scheduled_at が最も古いものを1件投稿する。
 * scheduled_at が未設定の項目は --auto では投稿されない（スケジューリング忘れ防止）。
 * 個別投稿したい場合は --id=<id> を使用。
 *
 * Cron設定例（毎分実行で予約配信、毎日 0:00 に expired クリーンアップ）:
 *   * * * * * cd /path/to/project && php bin/post-sales-info.php --auto >> data/sales-post.log 2>&1
 *   0 0 * * * cd /path/to/project && php bin/post-sales-info.php --expire >> data/sales-post.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\TwitterClient;

const SALES_FILE = __DIR__ . '/../data/sales-info.json';
const POSTS_FILE = __DIR__ . '/../data/x-posts.json';

function loadSales(): array
{
    if (!file_exists(SALES_FILE)) {
        return ['items' => []];
    }
    $data = json_decode(file_get_contents(SALES_FILE), true);
    return $data ?: ['items' => []];
}

function saveSales(array $data): void
{
    file_put_contents(SALES_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}

function jstNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
}

function isExpired(array $item, DateTimeImmutable $now): bool
{
    if (empty($item['entry_end'])) {
        return false;
    }
    try {
        $end = new DateTimeImmutable($item['entry_end'], new DateTimeZone('Asia/Tokyo'));
        return $end <= $now;
    } catch (\Throwable $e) {
        return false;
    }
}

$options = getopt('', ['list', 'pending', 'id:', 'auto', 'expire', 'dry-run']);

$data = loadSales();
$items = $data['items'] ?? [];
$now = jstNow();

// --- expired クリーンアップ ---
if (isset($options['expire'])) {
    $changed = 0;
    foreach ($data['items'] as &$item) {
        if (($item['status'] ?? '') === 'pending' && isExpired($item, $now)) {
            $item['status'] = 'expired';
            $changed++;
        }
    }
    unset($item);
    if ($changed > 0) {
        saveSales($data);
    }
    echo "[" . $now->format('Y-m-d H:i:s') . "] expired: {$changed}件\n";
    exit(0);
}

// --- 一覧表示 ---
if (isset($options['list']) || isset($options['pending'])) {
    $onlyPending = isset($options['pending']);

    $byStatus = ['pending' => [], 'posted' => [], 'expired' => [], 'skipped' => []];
    foreach ($items as $item) {
        $st = $item['status'] ?? 'pending';
        $byStatus[$st][] = $item;
    }

    foreach (['pending', 'posted', 'expired', 'skipped'] as $st) {
        if ($onlyPending && $st !== 'pending') {
            continue;
        }
        $list = $byStatus[$st];
        echo "=== {$st} (" . count($list) . "件) ===\n";
        foreach ($list as $item) {
            $end = $item['entry_end'] ?? '-';
            $endShort = $end !== '-' ? substr($end, 0, 16) : '-';
            $store = mb_strimwidth($item['store'] ?? '', 0, 24, '…');
            $product = mb_strimwidth($item['product'] ?? '', 0, 30, '…');
            echo sprintf("  %-12s 〜%s  %-24s  %s\n", substr($item['id'] ?? '', 0, 12), $endShort, $store, $product);
        }
        echo "\n";
    }
    exit(0);
}

// --- 投稿対象を選ぶ ---
$target = null;
$targetIdx = null;

if (isset($options['id'])) {
    foreach ($items as $i => $item) {
        if (($item['id'] ?? '') === $options['id']) {
            $target = $item;
            $targetIdx = $i;
            break;
        }
    }
    if ($target === null) {
        fwrite(STDERR, "エラー: id={$options['id']} の項目が見つかりません\n");
        exit(1);
    }
} elseif (isset($options['auto'])) {
    // pending かつ scheduled_at が設定済み かつ scheduled_at <= now の中で scheduled_at が最も古い1件
    $candidates = [];
    foreach ($items as $i => $item) {
        if (($item['status'] ?? '') !== 'pending') continue;
        if (empty($item['scheduled_at'])) continue;
        if (isExpired($item, $now)) continue;
        try {
            $sched = new DateTimeImmutable($item['scheduled_at'], new DateTimeZone('Asia/Tokyo'));
        } catch (\Throwable $e) {
            continue;
        }
        if ($sched > $now) continue;
        $candidates[] = ['idx' => $i, 'item' => $item, 'sched' => $sched];
    }
    if (empty($candidates)) {
        // 静かに終了（cron毎分実行で出力を抑える）
        exit(0);
    }
    usort($candidates, fn($a, $b) => $a['sched'] <=> $b['sched']);
    $target = $candidates[0]['item'];
    $targetIdx = $candidates[0]['idx'];
} else {
    echo <<<USAGE
Usage:
  php bin/post-sales-info.php --list                  # 全件一覧
  php bin/post-sales-info.php --pending               # pending のみ
  php bin/post-sales-info.php --id=<id> [--dry-run]   # 指定ID投稿
  php bin/post-sales-info.php --auto [--dry-run]      # cron用: 受付終了が近い pending を投稿
  php bin/post-sales-info.php --expire                # entry_end 経過の pending を expired にマーク

USAGE;
    exit(1);
}

$dryRun = isset($options['dry-run']);

$tweetText = $target['tweet_text'] ?? '';
$replyText = $target['reply_text'] ?? '';
$imageUrl  = $target['image_url']  ?? '';

if ($tweetText === '') {
    fwrite(STDERR, "エラー: tweet_text が空です（id={$target['id']}）\n");
    exit(1);
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "ID: {$target['id']}\n";
echo "店舗: " . ($target['store'] ?? '') . "\n";
echo "商品: " . ($target['product'] ?? '') . "\n";
echo "受付終了: " . ($target['entry_end'] ?? '-') . "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "【1投稿目】\n{$tweetText}\n";
if ($imageUrl !== '') echo "[画像: {$imageUrl}]\n";
if ($replyText !== '') {
    echo "\n【リプ】\n{$replyText}\n";
}
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

if ($dryRun) {
    echo "(dry-run: 投稿しません)\n";
    exit(0);
}

$twitterConfigFile = __DIR__ . '/../config/twitter.php';
if (!file_exists($twitterConfigFile)) {
    fwrite(STDERR, "エラー: config/twitter.php が見つかりません\n");
    exit(1);
}

$twitter = new TwitterClient(require $twitterConfigFile);

try {
    if ($replyText !== '') {
        $result = $twitter->postArticle($tweetText, $replyText, $imageUrl);
        $tweetId = $result['tweet_id'];
        $replyId = $result['reply_id'];
    } else {
        $mediaId = null;
        if ($imageUrl !== '') {
            try {
                $mediaId = $twitter->uploadImageFromUrl($imageUrl);
            } catch (\Throwable $e) {
                error_log('[post-sales-info] 画像アップロード失敗: ' . $e->getMessage());
            }
        }
        $tweet = $twitter->tweet($tweetText, $mediaId);
        $tweetId = $tweet['id'];
        $replyId = null;
    }

    $data['items'][$targetIdx]['status']    = 'posted';
    $data['items'][$targetIdx]['tweet_id']  = $tweetId;
    if ($replyId !== null) {
        $data['items'][$targetIdx]['reply_id'] = $replyId;
    }
    $data['items'][$targetIdx]['posted_at'] = $now->format('c');
    saveSales($data);

    // x-posts.json にも記録
    $posts = file_exists(POSTS_FILE) ? (json_decode(file_get_contents(POSTS_FILE), true) ?: ['posts' => []]) : ['posts' => []];
    $posts['posts'][] = [
        'slug'       => 'sales-info:' . $target['id'],
        'tweet_id'   => $tweetId,
        'reply_id'   => $replyId,
        'text'       => $tweetText,
        'reply_text' => $replyText,
        'posted_at'  => $now->format('c'),
    ];
    file_put_contents(POSTS_FILE, json_encode($posts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

    echo "投稿完了: https://x.com/i/status/{$tweetId}\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "投稿エラー: {$e->getMessage()}\n");
    exit(1);
}
