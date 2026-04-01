<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Content.php';
require_once __DIR__ . '/lib/Comment.php';

// POSTのみ受付
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$slug = $_POST['slug'] ?? '';
$name = $_POST['name'] ?? '';
$body = $_POST['body'] ?? '';

if (!Content::isValidSlug($slug)) {
    http_response_code(400);
    exit;
}

$comment = new Comment(__DIR__ . '/data/comments.db');
$result = $comment->post($slug, $name, $body, $_SERVER['REMOTE_ADDR'] ?? '');

if (!$result['success']) {
    // エラーをクエリパラメータで返す
    $errorMsg = urlencode(implode('／', $result['errors']));
    header("Location: /article/$slug/?error=$errorMsg#comment-form");
    exit;
}

header("Location: /article/$slug/#comments");
exit;
