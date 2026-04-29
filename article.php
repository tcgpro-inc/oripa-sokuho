<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Content.php';
require_once __DIR__ . '/lib/Comment.php';
require_once __DIR__ . '/lib/PageView.php';

$content = new Content(__DIR__ . '/content/articles');
$commentDb = new Comment(__DIR__ . '/data/comments.db');
$pageViewDb = new PageView(__DIR__ . '/data/pageviews.db');

$slug = $_GET['slug'] ?? '';

if (!Content::isValidSlug($slug)) {
    require __DIR__ . '/404.php';
    exit;
}

$article = $content->getArticle($slug);
if (!$article) {
    require __DIR__ . '/404.php';
    exit;
}

$meta = $article['meta'];
$pageTitle = $meta['title'] ?? '記事';
$currentCategory = $meta['category'] ?? '';

$categoryNames = Content::CATEGORY_NAMES;

// SEOメタデータ
$baseUrl = 'https://oripanews.com';
$canonical = "{$baseUrl}/article/{$slug}/";
$excerpt = mb_substr(strip_tags($article['html']), 0, 80);
$metaDescription = $excerpt . '…';
$ogType = 'article';
$ogTitle = $pageTitle;
$ogDescription = $metaDescription;
$ogImage = $meta['thumbnail_url'] ?? '';
// LCP対策: ヒーロー画像はweserv.nl経由のWebP配信＋preload
$heroImageProxy = !empty($meta['thumbnail_url'])
    ? Content::thumbnailProxy($meta['thumbnail_url'], 1000)
    : '';
$preloadImage = $heroImageProxy;

$catLabel = $categoryNames[$currentCategory] ?? 'その他';
$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $meta['title'] ?? '',
        'datePublished' => date('c', strtotime($meta['published_at'] ?? 'now')),
        'author' => ['@type' => 'Organization', 'name' => 'オリパ速報'],
        'publisher' => ['@type' => 'Organization', 'name' => 'オリパ速報'],
        'mainEntityOfPage' => $canonical,
        'image' => $meta['thumbnail_url'] ?? '',
        'articleSection' => $catLabel,
    ],
    [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'トップ', 'item' => "{$baseUrl}/"],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $catLabel, 'item' => "{$baseUrl}/?category=" . urlencode($currentCategory)],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $pageTitle],
        ],
    ],
];

// コメント取得
$comments = $commentDb->getByArticle($slug);
$commentCount = count($comments);

// PV記録
$pageViewDb->record($slug);

// エラーメッセージ
$commentError = $_GET['error'] ?? '';

// 関連記事（同カテゴリ、自分以外、最大3件）
$related = array_slice(
    array_filter(
        $content->getArticlesByCategory($currentCategory),
        fn($a) => $a['slug'] !== $slug
    ),
    0,
    3
);

// PVランキング（直近7日間）
$pvRanking = $pageViewDb->getRanking(5, 7);
$rankingArticles = [];
foreach ($pvRanking as $pv) {
    $a = $content->getArticle($pv['slug']);
    if ($a) {
        $rankingArticles[] = $a;
    }
}
if (count($rankingArticles) < 5) {
    $allArticlesForRanking = $content->getAllArticles();
    $existingSlugs = array_map(fn($a) => $a['slug'], $rankingArticles);
    foreach ($allArticlesForRanking as $a) {
        if (!in_array($a['slug'], $existingSlugs, true)) {
            $rankingArticles[] = $a;
            if (count($rankingArticles) >= 5) break;
        }
    }
}

require __DIR__ . '/templates/header.php';
?>

<?php require __DIR__ . '/templates/sp-ranking.php'; ?>

<div class="container">
    <main>
        <nav class="breadcrumb">
            <a href="/">トップ</a> &gt;
            <a href="/?category=<?= urlencode($currentCategory) ?>"><?= htmlspecialchars($catLabel) ?></a> &gt;
            <span><?= htmlspecialchars(mb_substr($pageTitle, 0, 40)) ?></span>
        </nav>

        <div class="article-detail">
            <h1>
                <?php if (!empty($meta['tag'])): ?>
                    <span class="badge badge-tag"><?= htmlspecialchars($meta['tag']) ?></span>
                <?php endif; ?>
                <?= htmlspecialchars($meta['title'] ?? '無題') ?>
            </h1>

            <?php if (!empty($meta['thumbnail_url'])): ?>
            <div class="article-detail-img">
                <img src="<?= htmlspecialchars($heroImageProxy) ?>" alt="<?= htmlspecialchars($meta['title'] ?? '') ?>" width="700" height="400" fetchpriority="high">
            </div>
            <?php endif; ?>

            <div class="article-detail-meta">
                <?= date('Y年m月d日 H:i', strtotime($meta['published_at'] ?? 'now')) ?>
                カテゴリ：<span class="category-label">
                    <a href="/?category=<?= urlencode($currentCategory) ?>">
                        <?= $categoryNames[$currentCategory] ?? 'その他' ?>
                    </a>
                </span>
                <?php if (!empty($meta['company'])): ?>
                企業：<?= htmlspecialchars($meta['company']) ?>
                <?php endif; ?>
                コメント：<a href="#comments"><?= $commentCount ?>件</a>
            </div>

            <div class="content">
                <?= $article['html'] ?>
            </div>

            <?php if (!empty($meta['source_url'])): ?>
            <div class="source-link">
                ━━━━━━━━━━━━━━━━<br>
                ソース：<a href="<?= htmlspecialchars($meta['source_url']) ?>" target="_blank" rel="noopener nofollow">
                    <?= htmlspecialchars($meta['source_name'] ?? '元記事') ?>で読む →
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- コメント欄 -->
        <div class="comment-section" id="comments">
            <div class="comment-header">
                ◆ コメント欄（<?= $commentCount ?>件）
            </div>

            <?php if (empty($comments)): ?>
                <div class="comment-empty">
                    まだコメントはありません。最初のコメントを書いてみよう。
                </div>
            <?php endif; ?>

            <?php foreach ($comments as $i => $c):
                $num = $i + 1;
            ?>
            <div class="comment-item" id="comment-<?= $num ?>">
                <div class="comment-meta">
                    <span class="comment-num"><?= $num ?></span>
                    <span class="comment-name"><?= htmlspecialchars($c['name']) ?></span>
                    <span class="comment-date"><?= $c['created_at'] ?></span>
                    <span class="comment-id">ID:<?= substr(md5($c['created_at'] . $c['name']), 0, 8) ?></span>
                </div>
                <div class="comment-body">
                    <?= nl2br(htmlspecialchars($c['body'])) ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- 投稿フォーム -->
            <div class="comment-form-wrap" id="comment-form">
                <div class="comment-form-header">◆ コメントを書く</div>

                <?php if ($commentError): ?>
                <div class="comment-error">
                    ※ <?= htmlspecialchars($commentError) ?>
                </div>
                <?php endif; ?>

                <form action="/comment/post" method="POST" class="comment-form">
                    <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
                    <div class="comment-form-row">
                        <label for="name">名前</label>
                        <input type="text" id="name" name="name" placeholder="名無しのオリパ民" maxlength="30">
                    </div>
                    <div class="comment-form-row">
                        <label for="body">本文</label>
                        <textarea id="body" name="body" rows="4" maxlength="1000" required placeholder="コメントを入力..."></textarea>
                    </div>
                    <div class="comment-form-row">
                        <button type="submit" class="comment-submit">書き込む</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 関連記事 -->
        <?php if ($related): ?>
        <div style="margin-top: 16px;">
            <div class="sidebar-box">
                <div class="sidebar-box-header">◆ 関連記事（<?= $categoryNames[$currentCategory] ?? 'その他' ?>）</div>
                <div class="sidebar-box-body">
                    <ul class="ranking-list">
                        <?php foreach ($related as $r): ?>
                        <li>
                            <a href="/article/<?= urlencode($r['slug']) ?>/" target="_blank" rel="noopener">
                                <?= htmlspecialchars($r['meta']['title'] ?? '') ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <!-- サイドバー -->
    <aside class="sidebar">
        <?php require __DIR__ . '/templates/sidebar-ranking.php'; ?>
    </aside>
</div>

<?php require __DIR__ . '/templates/footer.php'; ?>
