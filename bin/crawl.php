#!/usr/bin/env php
<?php

/**
 * ニュース自動巡回 Cronスクリプト
 *
 * Usage:
 *   php bin/crawl.php                 # 全ソース巡回
 *   php bin/crawl.php --source=google # Google Newsのみ
 *   php bin/crawl.php --source=5ch    # 5chのみ
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Crawler\DraftStore;
use App\Crawler\GoogleNewsCrawler;
use App\Crawler\FiveChCrawler;

$dbPath = __DIR__ . '/../data/crawl.db';
$store = new DraftStore($dbPath);

$options = getopt('', ['source:']);
$source = $options['source'] ?? 'all';

$jstNow = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
echo "=== オリパ速報 ニュース巡回 [{$jstNow}] ===\n\n";

// Google News
if ($source === 'all' || $source === 'google') {
    echo "[Google News] 巡回開始...\n";
    try {
        $crawler = new GoogleNewsCrawler($store);
        $result = $crawler->crawl();
        echo "[Google News] 完了: {$result['found']}件取得, {$result['new']}件新規\n\n";
    } catch (Throwable $e) {
        echo "[Google News] エラー: {$e->getMessage()}\n\n";
    }
}

// 5ch
if ($source === 'all' || $source === '5ch') {
    echo "[5ch] 巡回開始...\n";
    try {
        $crawler = new FiveChCrawler($store);
        $result = $crawler->crawl();
        echo "[5ch] 完了: {$result['found']}件取得, {$result['new']}件新規\n\n";
    } catch (Throwable $e) {
        echo "[5ch] エラー: {$e->getMessage()}\n\n";
    }
}

echo "=== 巡回完了 ===\n";
