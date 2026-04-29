<div class="sp-ranking">
    <div class="sp-ranking-header">人気記事</div>
    <ol class="sp-ranking-list">
        <?php foreach (array_slice($rankingArticles, 0, 5) as $i => $r): ?>
        <li class="sp-ranking-item">
            <a href="/article/<?= urlencode($r['slug']) ?>/" target="_blank" rel="noopener" class="sp-ranking-link">
                <span class="sp-ranking-num"><?= $i + 1 ?></span>
                <div class="sp-ranking-thumb">
                    <?php if (!empty($r['meta']['thumbnail_url'])): ?>
                    <img src="<?= htmlspecialchars(Content::thumbnailProxy($r['meta']['thumbnail_url'], 120)) ?>" alt="<?= htmlspecialchars($r['meta']['title'] ?? '') ?>" loading="lazy" width="60" height="40">
                    <?php endif; ?>
                </div>
                <span class="sp-ranking-title"><?= htmlspecialchars($r['meta']['title'] ?? '') ?></span>
                <span class="sp-ranking-arrow">›</span>
            </a>
        </li>
        <?php endforeach; ?>
    </ol>
</div>
