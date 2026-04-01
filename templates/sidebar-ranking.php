<div class="sidebar-box">
    <div class="sidebar-box-header">◆ 勢いランキング</div>
    <div class="sidebar-box-body">
        <ol class="ranking-list">
            <?php foreach (array_slice($rankingArticles, 0, 5) as $r): ?>
            <li>
                <a href="/article/<?= urlencode($r['slug']) ?>/">
                    <?= htmlspecialchars(mb_substr($r['meta']['title'] ?? '', 0, 30)) ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>
