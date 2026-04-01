<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Content.php';
require_once __DIR__ . '/lib/Comment.php';

$content = new Content(__DIR__ . '/content/articles');
$commentDb = new Comment(__DIR__ . '/data/comments.db');

$slug = $_GET['slug'] ?? '';

if (!Content::isValidSlug($slug)) {
    http_response_code(404);
    echo '404 Not Found';
    exit;
}

$article = $content->getArticle($slug);
if (!$article) {
    http_response_code(404);
    echo '404 Not Found';
    exit;
}

$meta = $article['meta'];
$pageTitle = $meta['title'] ?? '記事';
$currentCategory = $meta['category'] ?? '';

$categoryNames = Content::CATEGORY_NAMES;

// コメント取得
$comments = $commentDb->getByArticle($slug);
$commentCount = count($comments);

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

require __DIR__ . '/templates/header.php';
?>

<div class="container">
    <main>
        <div class="back-link">
            <a href="/">トップに戻る</a>
        </div>

        <div class="article-detail">
            <h1>
                <?php if (!empty($meta['tag'])): ?>
                    <span class="badge badge-tag"><?= htmlspecialchars($meta['tag']) ?></span>
                <?php endif; ?>
                <?= htmlspecialchars($meta['title'] ?? '無題') ?>
            </h1>

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
                ソース：<a href="<?= htmlspecialchars($meta['source_url']) ?>" target="_blank" rel="noopener">
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
                            <a href="/article/<?= urlencode($r['slug']) ?>/">
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
        <?php $rankingArticles = $content->getAllArticles(); require __DIR__ . '/templates/sidebar-ranking.php'; ?>
    </aside>
</div>

<?php require __DIR__ . '/templates/footer.php'; ?>
