<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Content.php';
require_once __DIR__ . '/lib/XSearch.php';

use App\XSearch;

$twitterConfig = require __DIR__ . '/config/twitter.php';

$categories = [
    'pokeka' => ['label' => 'ポケカ', 'keyword' => 'ポケカ'],
    'onepiece' => ['label' => 'ワンピース', 'keyword' => 'ワンピカード OR ワンピースカード'],
    'yugioh' => ['label' => '遊戯王', 'keyword' => '遊戯王'],
];

$currentCat = $_GET['cat'] ?? '';
$isCategory = isset($categories[$currentCat]);

// カテゴリページの場合のみAPI検索
$tweets = [];
$error = '';
if ($isCategory) {
    $xSearch = new XSearch($twitterConfig);
    try {
        $result = $xSearch->searchCampaignTweets($categories[$currentCat]['keyword'], 20);
        foreach ($result['tweets'] as $tweet) {
            $tweets[] = XSearch::parseTweetData($tweet);
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// ページ設定
if ($isCategory) {
    $catLabel = $categories[$currentCat]['label'];
    $pageTitle = "{$catLabel}のプレキャン・プレゼント企画一覧｜オリパ速報";
    $metaDescription = "{$catLabel}のプレゼントキャンペーン・プレゼント企画をリアルタイムでまとめています。";
    $canonical = "https://oripanews.com/campaign/{$currentCat}/";
} else {
    $pageTitle = 'プレキャン・プレゼント企画まとめ｜オリパ速報';
    $metaDescription = 'ポケカ・遊戯王・ワンピースのプレゼントキャンペーン・プレゼント企画をリアルタイムでまとめています。';
    $canonical = 'https://oripanews.com/campaign/';
}
$ogType = 'website';
$ogTitle = $pageTitle;
$ogDescription = $metaDescription;
$ogImage = 'https://oripanews.com/img/ogp-default.png';
$currentCategory = '';
$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => $pageTitle,
        'description' => $metaDescription,
        'url' => $canonical,
    ],
];

require __DIR__ . '/templates/header.php';
?>

<div class="container">
    <main>
        <div class="campaign-page">

<?php if (!$isCategory): ?>
            <h1 class="campaign-title">◆ プレキャン・プレゼント企画まとめ ◆</h1>
            <p class="campaign-desc">ジャンルを選んでください</p>

            <div class="campaign-cat-grid">
                <?php foreach ($categories as $slug => $cat): ?>
                    <a href="/campaign/<?= $slug ?>/" class="campaign-cat-card">
                        <span class="campaign-cat-label"><?= htmlspecialchars($cat['label']) ?></span>
                        <span class="campaign-cat-sub">プレキャン一覧 →</span>
                    </a>
                <?php endforeach; ?>
            </div>

<?php else: ?>
            <h1 class="campaign-title">◆ <?= htmlspecialchars($catLabel) ?> プレキャン一覧 ◆</h1>
            <p class="campaign-desc">X上のプレゼント企画を自動収集（直近7日間・1時間ごと更新）</p>

            <nav class="campaign-shortcuts">
                <?php foreach ($categories as $slug => $cat): ?>
                    <a href="/campaign/<?= $slug ?>/" class="campaign-shortcut<?= $slug === $currentCat ? ' active' : '' ?>"><?= htmlspecialchars($cat['label']) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($error): ?>
                <p class="campaign-error">※ 取得エラー: データを読み込めませんでした</p>
            <?php elseif (empty($tweets)): ?>
                <p class="campaign-empty">※ 該当するプレキャン投稿が見つかりませんでした</p>
            <?php else: ?>
                <div class="campaign-table-wrap">
                    <table class="campaign-table" id="campaignTable">
                        <thead>
                            <tr>
                                <th>タイトル</th>
                                <th>アカウント</th>
                                <th class="sortable" data-sort="string">景品 <span class="sort-icon">⇅</span></th>
                                <th class="sortable" data-sort="date">締切 <span class="sort-icon">⇅</span></th>
                                <th class="sortable" data-sort="number">盛り上がり <span class="sort-icon">⇅</span></th>
                                <th>リンク</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tweets as $t): ?>
                                <tr>
                                    <td class="td-title"><?= htmlspecialchars($t['title']) ?></td>
                                    <td class="td-account"><a href="https://x.com/<?= htmlspecialchars($t['username']) ?>" target="_blank" rel="noopener">@<?= htmlspecialchars($t['username']) ?></a></td>
                                    <td class="td-prize" data-sort-value="<?= htmlspecialchars($t['prize']) ?>"><?= htmlspecialchars($t['prize']) ?></td>
                                    <td class="td-deadline" data-sort-value="<?= htmlspecialchars($t['deadline_raw']) ?>"><?= htmlspecialchars($t['deadline']) ?></td>
                                    <td class="td-engagement" data-sort-value="<?= $t['engagement'] ?>">
                                        <?php
                                        $eng = $t['engagement'];
                                        if ($eng >= 500) echo '🔥🔥🔥';
                                        elseif ($eng >= 100) echo '🔥🔥';
                                        elseif ($eng >= 20) echo '🔥';
                                        else echo '―';
                                        ?>
                                        <small>(<?= number_format($eng) ?>)</small>
                                    </td>
                                    <td class="td-link"><a href="<?= htmlspecialchars($t['url']) ?>" target="_blank" rel="noopener">ポストを見る→</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
<?php endif; ?>

        </div>
    </main>

    <aside class="sidebar">
        <div class="sidebar-box">
            <div class="sidebar-box-header">◆ ジャンル</div>
            <div class="sidebar-box-body">
                <ul class="campaign-nav">
                    <?php foreach ($categories as $slug => $cat): ?>
                        <li><a href="/campaign/<?= $slug ?>/"><?= htmlspecialchars($cat['label']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="sidebar-box">
            <div class="sidebar-box-header">◆ このページについて</div>
            <div class="sidebar-box-body campaign-about">
                X上のプレゼント企画を自動で収集しています。<br>
                データは1時間ごとに更新されます。<br>
                <small>※ 直近7日間の投稿が対象です</small>
            </div>
        </div>
    </aside>
</div>

<?php if ($isCategory && !empty($tweets)): ?>
<script>
(function() {
    var table = document.getElementById('campaignTable');
    if (!table) return;

    var headers = table.querySelectorAll('th.sortable');
    var tbody = table.querySelector('tbody');

    headers.forEach(function(th, colIndex) {
        // sortableのカラムインデックスを実際のtdインデックスに変換
        var actualIndex = Array.from(th.parentNode.children).indexOf(th);
        var sortType = th.dataset.sort;
        var ascending = true;

        th.style.cursor = 'pointer';
        th.addEventListener('click', function() {
            var rows = Array.from(tbody.querySelectorAll('tr'));

            rows.sort(function(a, b) {
                var aVal = a.children[actualIndex].dataset.sortValue || '';
                var bVal = b.children[actualIndex].dataset.sortValue || '';

                if (sortType === 'number') {
                    return ascending
                        ? (parseFloat(aVal) || 0) - (parseFloat(bVal) || 0)
                        : (parseFloat(bVal) || 0) - (parseFloat(aVal) || 0);
                }
                if (sortType === 'date') {
                    if (aVal === '' && bVal === '') return 0;
                    if (aVal === '') return 1;
                    if (bVal === '') return -1;
                    return ascending ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
                }
                return ascending ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
            });

            rows.forEach(function(row) { tbody.appendChild(row); });

            // ソート方向アイコン更新
            headers.forEach(function(h) { h.querySelector('.sort-icon').textContent = '⇅'; });
            th.querySelector('.sort-icon').textContent = ascending ? '↑' : '↓';
            ascending = !ascending;
        });
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/templates/footer.php'; ?>
