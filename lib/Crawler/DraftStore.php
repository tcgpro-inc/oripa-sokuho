<?php

declare(strict_types=1);

namespace App\Crawler;

use PDO;

class DraftStore
{
    private PDO $db;

    public function __construct(string $dbPath)
    {
        $this->db = new PDO('sqlite:' . $dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS drafts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT NOT NULL,
                source_url TEXT NOT NULL UNIQUE,
                source_site_url TEXT,
                source_site_name TEXT,
                title TEXT NOT NULL,
                snippet TEXT,
                thumbnail_url TEXT,
                board TEXT,
                status TEXT NOT NULL DEFAULT \'new\',
                fetched_at TEXT NOT NULL,
                published_at TEXT
            )
        ');
        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_drafts_status ON drafts(status)
        ');
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS crawl_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT NOT NULL,
                started_at TEXT NOT NULL,
                finished_at TEXT,
                items_found INTEGER DEFAULT 0,
                items_new INTEGER DEFAULT 0,
                error TEXT
            )
        ');
    }

    /**
     * ドラフトを挿入（重複URLはスキップ）
     * @return bool 新規挿入されたらtrue
     */
    public function insertDraft(array $draft): bool
    {
        $stmt = $this->db->prepare('
            INSERT OR IGNORE INTO drafts (source, source_url, source_site_url, source_site_name, title, snippet, thumbnail_url, board, fetched_at, published_at)
            VALUES (:source, :source_url, :source_site_url, :source_site_name, :title, :snippet, :thumbnail_url, :board, :fetched_at, :published_at)
        ');
        $stmt->execute([
            ':source' => $draft['source'],
            ':source_url' => $draft['source_url'],
            ':source_site_url' => $draft['source_site_url'] ?? null,
            ':source_site_name' => $draft['source_site_name'] ?? null,
            ':title' => $draft['title'],
            ':snippet' => $draft['snippet'] ?? null,
            ':thumbnail_url' => $draft['thumbnail_url'] ?? null,
            ':board' => $draft['board'] ?? null,
            ':fetched_at' => $draft['fetched_at'],
            ':published_at' => $draft['published_at'] ?? null,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * ステータス別ドラフト一覧
     */
    public function getDrafts(string $status = 'new', int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM drafts WHERE status = ? ORDER BY fetched_at DESC LIMIT ?'
        );
        $stmt->execute([$status, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * ドラフト単体取得
     */
    public function getDraft(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM drafts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * ステータス更新
     */
    public function updateStatus(int $id, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE drafts SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    /**
     * クロールログ開始
     */
    public function logStart(string $source): int
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare('INSERT INTO crawl_log (source, started_at) VALUES (?, ?)');
        $stmt->execute([$source, $now]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * クロールログ完了
     */
    public function logFinish(int $logId, int $found, int $new, ?string $error = null): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE crawl_log SET finished_at = ?, items_found = ?, items_new = ?, error = ? WHERE id = ?'
        );
        $stmt->execute([$now, $found, $new, $error, $logId]);
    }
}
