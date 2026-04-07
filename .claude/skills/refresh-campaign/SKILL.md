---
name: refresh-campaign
description: "プレキャンデータの日次更新を実行・管理する。'プレキャン更新', 'キャンペーン更新', 'campaign refresh', 'プレキャンcron', 'プレキャン状況', 'プレキャンデータ'で発動。"
user_invocable: true
metadata:
  version: 1.0.0
---

# プレキャン日次更新スキル

`bin/refresh-campaign.php` を使ったプレキャンデータの更新・管理を行う。

---

## 概要

| 項目 | 値 |
|------|-----|
| スクリプト | `bin/refresh-campaign.php` |
| 永続ストア | `data/campaign/{pokeka,onepiece,yugioh}.json` |
| 実行ログ | `data/campaign-refresh.log` |
| データソース | X API v2 Recent Search（Bearer Token認証） |
| 更新頻度 | 毎日9:00（Xserver cron） |
| 締切切れ除去 | 自動（締切日 < 今日） |
| 締切なし失効 | 14日で自動除去 |

---

## 手動実行

### 全カテゴリ更新

```bash
ssh -i ~/.ssh/souhatsu.key souhatsu@sv16601.xserver.jp -p 10022 "cd ~/oripanews.com/public_html && /opt/php-8.3/bin/php bin/refresh-campaign.php"
```

### 特定カテゴリのみ

```bash
ssh -i ~/.ssh/souhatsu.key souhatsu@sv16601.xserver.jp -p 10022 "cd ~/oripanews.com/public_html && /opt/php-8.3/bin/php bin/refresh-campaign.php pokeka"
```

### ドライラン（保存しない）

```bash
ssh -i ~/.ssh/souhatsu.key souhatsu@sv16601.xserver.jp -p 10022 "cd ~/oripanews.com/public_html && /opt/php-8.3/bin/php bin/refresh-campaign.php --dry-run"
```

### 現在のデータ状況確認

```bash
ssh -i ~/.ssh/souhatsu.key souhatsu@sv16601.xserver.jp -p 10022 "cd ~/oripanews.com/public_html && /opt/php-8.3/bin/php bin/refresh-campaign.php --status"
```

---

## Cron設定

Xserverサーバーパネルの「Cron設定」から以下を登録する。

### 設定値

| 項目 | 値 |
|------|-----|
| 分 | 0 |
| 時 | 9 |
| 日 | * |
| 月 | * |
| 曜日 | * |
| コマンド | `cd /home/souhatsu/oripanews.com/public_html && /opt/php-8.3/bin/php bin/refresh-campaign.php >> data/campaign-refresh.log 2>&1` |

### 設定手順

1. Xserverサーバーパネルにログイン
2. 「Cron設定」→「Cron設定追加」
3. 上記の値を入力して「確認画面へ進む」→「追加する」

### 注意事項

- Xserverの「Cron設定」画面はユーザーが操作する（CLIからのcrontab編集は不可）
- 実行ログは `data/campaign-refresh.log` に追記される
- ログが大きくなったら定期的に truncate: `> data/campaign-refresh.log`

---

## データ構造

### 永続ストア（`data/campaign/{slug}.json`）

```json
[
    {
        "id": "2041440857417498635",
        "username": "P_K_M_OFFICIAL",
        "name": "ポケまる",
        "title": "MEGAドリームex プレゼントキャンペーン！",
        "prize": "MEGAドリームex 1BOX",
        "deadline": "4/30",
        "deadline_raw": "2026-04-30",
        "engagement": 150,
        "url": "https://x.com/P_K_M_OFFICIAL/status/2041440857417498635",
        "added_at": "2026-04-07"
    }
]
```

engagement降順でソート済み。

### データの流れ

```
X API（直近7日間）
    ↓ searchCampaignTweets()
新規ツイート20件
    ↓ parseTweetData()
タイトル・景品・締切を抽出
    ↓ マージ（IDで重複排除）
既存データ + 新規データ
    ↓ フィルタ
締切切れ → 除去
締切なし14日超 → 除去
    ↓ engagement順ソート
data/campaign/{slug}.json に保存
    ↓
campaign.php が読み込んでテーブル表示
```

---

## トラブルシューティング

### データが空になった

APIエラーで0件取得 → 既存データにマージされるので消えない。
万が一消えた場合は手動実行で再取得:

```bash
ssh -i ~/.ssh/souhatsu.key souhatsu@sv16601.xserver.jp -p 10022 "cd ~/oripanews.com/public_html && /opt/php-8.3/bin/php bin/refresh-campaign.php"
```

### ノイズが混入している

1. 該当ツイートのIDをメモ
2. `data/campaign/{slug}.json` から手動で削除:

```bash
ssh -i ~/.ssh/souhatsu.key souhatsu@sv16601.xserver.jp -p 10022 'cd ~/oripanews.com/public_html && /opt/php-8.3/bin/php -r "
\$file = \"data/campaign/pokeka.json\";
\$data = json_decode(file_get_contents(\$file), true);
\$data = array_values(array_filter(\$data, fn(\$t) => \$t[\"id\"] !== \"ここにツイートID\"));
file_put_contents(\$file, json_encode(\$data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo \"残り: \" . count(\$data) . \"件\\n\";
"'
```

3. 根本対策: `lib/XSearch.php` の `$query` に除外ワードを追加

### ログ確認

```bash
ssh -i ~/.ssh/souhatsu.key souhatsu@sv16601.xserver.jp -p 10022 "tail -30 ~/oripanews.com/public_html/data/campaign-refresh.log"
```

---

## 検索クエリの改善

ノイズパターンを見つけたら `lib/XSearch.php` の `searchCampaignTweets()` 内の `$query` を修正する。

修正後の手順:
1. コードを修正して git push
2. Xserverで `git pull`
3. APIキャッシュをクリア: `rm -f data/cache/campaign_*.json`
4. 手動実行で確認: `php bin/refresh-campaign.php --dry-run`
5. 問題なければ本実行: `php bin/refresh-campaign.php`
