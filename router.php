<?php
/**
 * PHPビルトインサーバー用ルーター
 * 使用方法: php -S localhost:8888 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 実在する静的ファイルはそのまま配信
if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

// /article/slug/ → article.php
if (preg_match('#^/article/([a-zA-Z0-9_-]+)/?$#', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/article.php';
    return true;
}

// /campaign/ or /campaign/pokeka/ → campaign.php
if (preg_match('#^/campaign/([a-z]+)/?$#', $uri, $m)) {
    $_GET['cat'] = $m[1];
    require __DIR__ . '/campaign.php';
    return true;
}
if (preg_match('#^/campaign/?$#', $uri)) {
    require __DIR__ . '/campaign.php';
    return true;
}

// /comment/post → comment-post.php
if (preg_match('#^/comment/post/?$#', $uri)) {
    require __DIR__ . '/comment-post.php';
    return true;
}

// それ以外 → index.php
require __DIR__ . '/index.php';
return true;
