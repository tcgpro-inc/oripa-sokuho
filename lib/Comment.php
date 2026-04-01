<?php

declare(strict_types=1);

class Comment
{
    private PDO $db;

    public function __construct(string $dbPath)
    {
        $isNew = !file_exists($dbPath);
        $this->db = new PDO('sqlite:' . $dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($isNew) {
            $this->migrate();
        }
    }

    private function migrate(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                article_slug TEXT NOT NULL,
                name TEXT NOT NULL,
                body TEXT NOT NULL,
                created_at TEXT NOT NULL,
                ip_hash TEXT NOT NULL
            )
        ');
        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_comments_slug ON comments(article_slug)
        ');
    }

    /**
     * 記事のコメントを取得（古い順）
     */
    public function getByArticle(string $slug): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, body, created_at FROM comments WHERE article_slug = ? ORDER BY id ASC'
        );
        $stmt->execute([$slug]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 複数記事のコメント数を一括取得
     */
    public function countByArticles(array $slugs): array
    {
        if (empty($slugs)) return [];

        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $stmt = $this->db->prepare(
            "SELECT article_slug, COUNT(*) as cnt FROM comments WHERE article_slug IN ($placeholders) GROUP BY article_slug"
        );
        $stmt->execute($slugs);

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['article_slug']] = (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * コメントを投稿
     */
    public function post(string $slug, string $name, string $body, string $ip): array
    {
        $errors = [];

        $name = trim($name);
        $body = trim($body);

        if ($name === '') {
            $name = '名無しのオリパ民';
        }
        if (mb_strlen($name) > 30) {
            $errors[] = '名前は30文字以内にしてください';
        }
        if ($body === '') {
            $errors[] = '本文を入力してください';
        }
        if (mb_strlen($body) > 1000) {
            $errors[] = '本文は1000文字以内にしてください';
        }

        // 簡易スパム対策: 同一IPから10秒以内の連投禁止
        $ipHash = hash('sha256', $ip . date('Ymd'));
        $jstNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        $stmt = $this->db->prepare(
            'SELECT created_at FROM comments WHERE ip_hash = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$ipHash]);
        $last = $stmt->fetchColumn();
        if ($last && ($jstNow->getTimestamp() - (new DateTimeImmutable($last, new DateTimeZone('Asia/Tokyo')))->getTimestamp()) < 10) {
            $errors[] = '連投規制中です。少し待ってから投稿してください';
        }

        if ($errors) {
            return ['success' => false, 'errors' => $errors];
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO comments (article_slug, name, body, ip_hash, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$slug, $name, $body, $ipHash, $now]);

        return ['success' => true, 'errors' => []];
    }
}
