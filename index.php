<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Content.php';
require_once __DIR__ . '/lib/Comment.php';

$content = new Content(__DIR__ . '/content/articles');
$commentDb = new Comment(__DIR__ . '/data/comments.db');

// カテゴリフィルター
$currentCategory = $_GET['category'] ?? '';

if ($currentCategory && !in_array($currentCategory, Content::CATEGORY_SLUGS, true)) {
    $currentCategory = '';
}

$articles = $currentCategory
    ? $content->getArticlesByCategory($currentCategory)
    : $content->getAllArticles();

$allArticles = $content->getAllArticles();
$hotArticles = $content->getHotArticles(4);
$categoryCounts = $content->getCategoryCounts();

// コメント数を一括取得
$allSlugs = array_map(fn($a) => $a['slug'], $articles);
$commentCounts = $commentDb->countByArticles($allSlugs);

$categoryNames = Content::CATEGORY_NAMES;

$pageTitle = $currentCategory
    ? ($categoryNames[$currentCategory] ?? '') . 'の記事一覧'
    : 'トップ';

require __DIR__ . '/templates/header.php';
?>

<div class="container">

    <!-- ヘッドラインカード -->
    <?php if (!$currentCategory && $hotArticles): ?>
    <div class="headline-section">
        <div class="headline-cards">
            <?php foreach ($hotArticles as $article): ?>
            <a href="/article/<?= urlencode($article['slug']) ?>/" class="headline-card" style="text-decoration:none;color:inherit;">
                <div class="headline-card-img">
                    <?php if (!empty($article['meta']['thumbnail_url'])): ?>
                    <img src="<?= htmlspecialchars($article['meta']['thumbnail_url']) ?>" alt="<?= htmlspecialchars($article['meta']['title'] ?? '') ?>" loading="lazy">
                    <?php else: ?>
                    <span class="headline-card-placeholder"><?= htmlspecialchars(mb_substr($article['meta']['tag'] ?? '速報', 0, 3)) ?></span>
                    <?php endif; ?>
                </div>
                <div class="headline-card-body">
                    <div class="headline-card-title">
                        <?= htmlspecialchars($article['meta']['title'] ?? '') ?>
                    </div>
                    <div class="headline-card-date">
                        <?= date('Y/m/d', strtotime($article['meta']['published_at'] ?? 'now')) ?>
                    </div>
                </div>
                <?php if (!empty($article['meta']['hot'])): ?>
                <div class="badge-overlay">
                    <span class="badge badge-hot">HOT!</span>
                </div>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 記事一覧 -->
    <main>
        <div class="article-list">
            <?php if (empty($articles)): ?>
                <div style="padding: 20px; text-align: center; color: #888;">
                    記事がありません
                </div>
            <?php endif; ?>

            <?php foreach ($articles as $article):
                $meta = $article['meta'];
                $pubDate = date('Y/m/d H:i', strtotime($meta['published_at'] ?? 'now'));
                $catName = $categoryNames[$meta['category'] ?? ''] ?? 'その他';
                $isNew = isset($meta['published_at']) &&
                    (time() - strtotime($meta['published_at'])) < 3 * 24 * 3600;

                $excerpt = mb_substr(strip_tags($article['html']), 0, 100) . '…';
            ?>
            <div class="article-item">
                <div class="article-thumb">
                    <?php if (!empty($meta['thumbnail_url'])): ?>
                        <img src="<?= htmlspecialchars($meta['thumbnail_url']) ?>" alt="<?= htmlspecialchars($meta['title'] ?? '') ?>" loading="lazy">
                    <?php else: ?>
                        <?= mb_substr($catName, 0, 3) ?>
                    <?php endif; ?>
                </div>
                <div class="article-body">
                    <div class="article-title">
                        <?php if ($isNew): ?><span class="badge badge-new">NEW!</span><?php endif; ?>
                        <?php if (!empty($meta['hot'])): ?><span class="badge badge-hot">HOT!</span><?php endif; ?>
                        <a href="/article/<?= urlencode($article['slug']) ?>/">
                            <?= htmlspecialchars($meta['title'] ?? '無題') ?>
                        </a>
                    </div>
                    <div class="article-meta">
                        <?= $pubDate ?>
                        カテゴリ：<span class="category-label"><?= $catName ?></span>
                        <?php if (!empty($meta['source_name'])): ?>
                        ソース：<?= htmlspecialchars($meta['source_name']) ?>
                        <?php endif; ?>
                        <?php $cc = $commentCounts[$article['slug']] ?? 0; ?>
                        <a href="/article/<?= urlencode($article['slug']) ?>/#comments" class="comment-count-link">
                            コメ(<?= $cc ?>)
                        </a>
                    </div>
                    <div class="article-excerpt"><?= htmlspecialchars($excerpt) ?></div>
                    <a href="/article/<?= urlencode($article['slug']) ?>/" class="article-readmore">続きを読む</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>

    <!-- サイドバー -->
    <aside class="sidebar">
        <!-- 勢いランキング -->
        <?php $rankingArticles = $allArticles; require __DIR__ . '/templates/sidebar-ranking.php'; ?>

        <!-- カテゴリ -->
        <div class="sidebar-box">
            <div class="sidebar-box-header">◆ カテゴリ</div>
            <div class="sidebar-box-body">
                <ul class="category-list">
                    <?php foreach ($categoryCounts as $slug => $count): ?>
                    <li>
                        <a href="/?category=<?= $slug ?>"><?= $categoryNames[$slug] ?? $slug ?></a>
                        <span class="count">(<?= $count ?>)</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- サイト情報 -->
        <div class="sidebar-box">
            <div class="sidebar-box-header">◆ このサイトについて</div>
            <div class="sidebar-box-body" style="font-size: 12px; color: #555;">
                TCGオリパの最新情報を速報配信するニュースサイトです。<br>
                ポケカ・遊戯王・ワンピース等のオリパ情報をまとめています。
            </div>
        </div>
    </aside>
</div>

<?php require __DIR__ . '/templates/footer.php'; ?>
