<div class="sidebar-box">
    <div class="sidebar-box-header">◆ 勢いランキング</div>
    <div class="ranking-cards">
        <?php foreach (array_slice($rankingArticles, 0, 5) as $r): ?>
        <a href="/article/<?= urlencode($r['slug']) ?>/" class="ranking-card" target="_blank" rel="noopener">
            <?php if (!empty($r['meta']['thumbnail_url'])): ?>
            <img src="<?= htmlspecialchars(Content::thumbnailProxy($r['meta']['thumbnail_url'], 600)) ?>" alt="<?= htmlspecialchars($r['meta']['title'] ?? '') ?>" loading="lazy" width="300" height="200" class="ranking-card-img">
            <?php else: ?>
            <div class="ranking-card-img ranking-card-placeholder"></div>
            <?php endif; ?>
            <div class="ranking-card-overlay">
                <span class="ranking-card-title"><?= htmlspecialchars($r['meta']['title'] ?? '') ?></span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
