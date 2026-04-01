<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'オリパ速報') ?> - オリパ速報＠TCGオリパまとめ</title>
    <link rel="stylesheet" href="/css/style.css">
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
        foreach (Content::CATEGORY_NAMES as $slug => $label) {
            $tabs[$slug] = ['label' => $slug === 'onepiece' ? 'ワンピ' : $label, 'class' => $tabClasses[$slug] ?? ''];
        }
        foreach ($tabs as $tabSlug => $tab): ?>
            <a href="<?= $tabSlug ? "/?category=$tabSlug" : '/' ?>"
               class="<?= $tab['class'] ?> <?= $currentCategory === $tabSlug ? 'active' : '' ?>">
                <?= $tab['label'] ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
