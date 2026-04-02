<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'オリパ速報') ?> - オリパ速報＠TCGオリパまとめ</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.png" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
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

    <link rel="stylesheet" href="/css/style.css">

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
        <h1><a href="/">◆ オリパ速報 ◆</a></h1>
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
            'yugioh' => 'tab-yugioh',
            'onepiece' => 'tab-onepiece',
            'other' => 'tab-other',
        ];
        $tabs = ['' => ['label' => '総合', 'class' => '']];
        foreach (Content::CATEGORY_NAMES as $catKey => $label) {
            $tabs[$catKey] = ['label' => $catKey === 'onepiece' ? 'ワンピ' : $label, 'class' => $tabClasses[$catKey] ?? ''];
        }
        foreach ($tabs as $tabSlug => $tab): ?>
            <a href="<?= $tabSlug ? "/?category=$tabSlug" : '/' ?>"
               class="<?= $tab['class'] ?> <?= $currentCategory === $tabSlug ? 'active' : '' ?>">
                <?= $tab['label'] ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
