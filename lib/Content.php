<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/Parsedown.php';

class Content
{
    public const CATEGORY_NAMES = [
        'pokeka' => 'ポケカ',
        'yugioh' => '遊戯王',
        'onepiece' => 'ワンピース',
        'other' => 'その他',
    ];

    public const CATEGORY_SLUGS = ['pokeka', 'yugioh', 'onepiece', 'other'];

    private string $articlesDir;
    private Parsedown $parsedown;
    private ?array $cachedArticles = null;

    public function __construct(string $articlesDir)
    {
        $this->articlesDir = $articlesDir;
        $this->parsedown = new Parsedown();
        // 記事は管理者が作成するMarkdownファイルのため、HTML埋め込みを許可
        $this->parsedown->setMarkupEscaped(false);
    }

    public static function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9\-_]+$/', $slug);
    }

    /**
     * 全記事を取得（公開日の降順）
     * @return array<int, array{slug: string, meta: array, html: string}>
     */
    public function getAllArticles(): array
    {
        if ($this->cachedArticles !== null) {
            return $this->cachedArticles;
        }

        $articles = [];
        $files = glob($this->articlesDir . '/*.md');

        foreach ($files as $file) {
            $fileSlug = basename($file, '.md');
            $raw = file_get_contents($file);
            $parsed = $this->parseFrontmatter($raw);
            // front matterのslugフィールドを優先、なければファイル名
            $slug = $parsed['meta']['slug'] ?? $fileSlug;
            $articles[] = [
                'slug' => $slug,
                'meta' => $parsed['meta'],
                'html' => $this->parsedown->text($parsed['body']),
            ];
        }

        usort($articles, function ($a, $b) {
            return strcmp($b['meta']['published_at'] ?? '', $a['meta']['published_at'] ?? '');
        });

        $this->cachedArticles = $articles;
        return $articles;
    }

    /**
     * カテゴリで絞り込み
     */
    public function getArticlesByCategory(string $category): array
    {
        return array_values(array_filter(
            $this->getAllArticles(),
            fn($a) => ($a['meta']['category'] ?? '') === $category
        ));
    }

    /**
     * slugで1記事取得（front matterのslugフィールドで検索）
     */
    public function getArticle(string $slug): ?array
    {
        // まずfront matterのslugで検索
        foreach ($this->getAllArticles() as $article) {
            if ($article['slug'] === $slug) {
                return $article;
            }
        }

        // フォールバック: ファイル名で直接検索
        $file = $this->articlesDir . '/' . $slug . '.md';
        if (!file_exists($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        $parsed = $this->parseFrontmatter($raw);

        return [
            'slug' => $parsed['meta']['slug'] ?? $slug,
            'meta' => $parsed['meta'],
            'html' => $this->parsedown->text($parsed['body']),
        ];
    }

    /**
     * HOT記事を取得
     */
    public function getHotArticles(int $limit = 5): array
    {
        $all = $this->getAllArticles();
        $hot = array_filter($all, fn($a) => !empty($a['meta']['hot']));

        // HOTがlimit未満なら新着で補完
        if (count($hot) < $limit) {
            $hot = array_slice($all, 0, $limit);
        }

        return array_slice(array_values($hot), 0, $limit);
    }

    /**
     * カテゴリ別の記事数を取得
     */
    public function getCategoryCounts(): array
    {
        $counts = array_fill_keys(self::CATEGORY_SLUGS, 0);
        foreach ($this->getAllArticles() as $article) {
            $cat = $article['meta']['category'] ?? 'other';
            if (isset($counts[$cat])) {
                $counts[$cat]++;
            }
        }
        return $counts;
    }

    /**
     * YAML風のfrontmatterをパース（シンプル実装）
     */
    private function parseFrontmatter(string $raw): array
    {
        $meta = [];
        $body = $raw;

        if (preg_match('/\A---\s*\n(.+?)\n---\s*\n(.*)\z/s', $raw, $m)) {
            $body = $m[2];
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^(\w+):\s*(.+)$/', trim($line), $kv)) {
                    $value = trim($kv[2]);
                    // クォート除去
                    $value = trim($value, '"\'');
                    // bool変換
                    if ($value === 'true') $value = true;
                    elseif ($value === 'false') $value = false;
                    $meta[$kv[1]] = $value;
                }
            }
        }

        return ['meta' => $meta, 'body' => $body];
    }
}
