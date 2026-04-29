<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'オリパ速報') ?> | オリパ速報</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.png" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
<?php if (!empty($noindex)): ?>
    <meta name="robots" content="noindex, follow">
<?php endif; ?>
<?php if (!empty($metaDescription)): ?>
    <meta name="description" content="<?= htmlspecialchars($metaDescription) ?>">
<?php endif; ?>
<?php if (!empty($canonical)): ?>
    <link rel="canonical" href="<?= htmlspecialchars($canonical) ?>">
<?php endif; ?>

    <!-- Open Graph -->
    <meta property="og:title" content="<?= htmlspecialchars($ogTitle ?? $pageTitle ?? 'オリパ速報') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogDescription ?? $metaDescription ?? 'TCGオリパの最新情報を速報配信。ポケカ・遊戯王・ワンピースのオリパ情報をまとめています。') ?>">
    <meta property="og:type" content="<?= htmlspecialchars($ogType ?? 'website') ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonical ?? 'https://oripanews.com/') ?>">
    <meta property="og:site_name" content="オリパ速報">
<?php if (!empty($ogImage)): ?>
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
<?php endif; ?>

    <!-- Twitter Card -->
    <meta name="twitter:card" content="<?= !empty($ogImage) ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= htmlspecialchars($ogTitle ?? $pageTitle ?? 'オリパ速報') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($ogDescription ?? $metaDescription ?? 'TCGオリパの最新情報を速報配信。') ?>">
<?php if (!empty($ogImage)): ?>
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">
<?php endif; ?>

<?php if (!empty($preloadImage)): ?>
    <link rel="preload" as="image" href="<?= htmlspecialchars($preloadImage) ?>" fetchpriority="high" type="image/webp">
<?php endif; ?>
    <link rel="stylesheet" href="/css/style.css">

    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-HZE63FN81K"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', 'G-HZE63FN81K');
    </script>

    <!-- WebSite構造化データ（Googleサイト名表示用） -->
    <script type="application/ld+json">{"@context":"https://schema.org","@type":"WebSite","name":"オリパ速報","url":"https://oripanews.com/"}</script>

<?php if (!empty($jsonLd)): ?>
<?php foreach ($jsonLd as $ld): ?>
    <script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
<?php endforeach; ?>
<?php endif; ?>
</head>
<body>

<!-- ヘッダー -->
<div class="header">
    <div class="header-inner">
        <div class="site-title"><a href="/">◆ オリパ速報 ◆</a></div>
        <span class="header-sub">TCGオリパまとめ速報</span>
    </div>
</div>

<!-- ティッカー -->
<div class="ticker">
    <div class="ticker-inner">
        ★お知らせ★ オリパの最新情報、誰よりも早く。━━ ポケカ・遊戯王・ワンピースのオリパ情報を速報配信中 ━━
    </div>
</div>

<!-- カテゴリタブ -->
<div class="category-tabs">
    <div class="category-tabs-inner">
        <?php
        $currentCategory = $currentCategory ?? '';
        $tabClasses = [
            'pokeka' => 'tab-pokeka',
            'review' => 'tab-review',
            'guide' => 'tab-guide',
            'free' => 'tab-free',
            'flame' => 'tab-flame',
        ];
        $tabs = ['' => ['label' => '総合', 'class' => '']];
        foreach (Content::CATEGORY_NAMES as $catKey => $label) {
            $tabs[$catKey] = ['label' => $label, 'class' => $tabClasses[$catKey] ?? ''];
        }
        foreach ($tabs as $tabSlug => $tab): ?>
            <a href="<?= $tabSlug ? "/?category=$tabSlug" : '/' ?>"
               class="<?= $tab['class'] ?> <?= $currentCategory === $tabSlug ? 'active' : '' ?>">
                <?= $tab['label'] ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
