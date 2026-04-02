<?php

declare(strict_types=1);

class PageView
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
            CREATE TABLE IF NOT EXISTS page_views (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                article_slug TEXT NOT NULL,
                viewed_at TEXT NOT NULL
            )
        ');
        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_pv_slug ON page_views(article_slug)
        ');
        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_pv_viewed_at ON page_views(viewed_at)
        ');
    }

    /**
     * PVを記録
     */
    public function record(string $slug): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO page_views (article_slug, viewed_at) VALUES (?, ?)'
        );
        $stmt->execute([$slug, $now]);
    }

    /**
     * 直近N日間のPV数で上位記事slugを取得
     * @return array<int, array{slug: string, pv: int}>
     */
    public function getRanking(int $limit = 5, int $days = 7): array
    {
        $since = (new DateTimeImmutable("now -{$days} days", new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare('
            SELECT article_slug AS slug, COUNT(*) AS pv
            FROM page_views
            WHERE viewed_at >= ?
            GROUP BY article_slug
            ORDER BY pv DESC
            LIMIT ?
        ');
        $stmt->execute([$since, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
