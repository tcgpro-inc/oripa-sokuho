---
name: fetch-precan
description: "X APIでポケカ・遊戯王・ワンピースのプレキャン（プレゼントキャンペーン）ツイートを取得する。'プレキャン取得', 'プレキャン検索', 'プレキャン一覧', 'fetch precan', 'プレゼント企画検索', 'キャンペーンツイート'で発動。"
user_invocable: true
metadata:
  version: 1.0.0
---

# プレキャン（プレゼントキャンペーン）ツイート取得スキル

X API v2（Bearer Token認証）を使って、TCGカテゴリ別のプレゼントキャンペーンツイートを取得する。

---

## 前提

- X APIの認証情報はXserver上の `.env` に格納されている
- `lib/XSearch.php` にAPI検索クライアントが実装済み
- `/campaign/` ページ（`campaign.php`）でテーブル表示に使用されている
- キャッシュ: `data/cache/campaign_*.json`（有効期限1時間）

### SSH接続

```bash
ssh -i ~/.ssh/souhatsu.key souhatsu@sv16601.xserver.jp -p 10022
```

---

## Step 1: カテゴリを確認する

対応カテゴリ:

| カテゴリ | キーワード | キャンペーンページ |
|---------|-----------|-------------------|
| ポケカ | `ポケカ` | `/campaign/pokeka/` |
| ワンピース | `ワンピカード OR ワンピースカード` | `/campaign/onepiece/` |
| 遊戯王 | `遊戯王` | `/campaign/yugioh/` |

ユーザーから指定がなければ全カテゴリを対象とする。

---

## Step 2: Xserver上でX APIを叩く

Xserver上のPHPでAPI検索を実行する。ローカルには `.env` がないため、必ずSSH経由で実行すること。

### 基本コマンド（1カテゴリ取得）

```bash
ssh -i ~/.ssh/souhatsu.key souhatsu@sv16601.xserver.jp -p 10022 'cd ~/oripanews.com/public_html && /opt/php-8.3/bin/php -r "
require_once \"lib/XSearch.php\";
use App\XSearch;
\$config = require \"config/twitter.php\";
\$x = new XSearch(\$config);
\$result = \$x->searchCampaignTweets(\"ポケカ\", 20);
foreach (\$result[\"tweets\"] as \$t) {
    \$parsed = XSearch::parseTweetData(\$t);
    echo \$parsed[\"title\"] . \" | @\" . \$parsed[\"username\"] . \" | 景品: \" . \$parsed[\"prize\"] . \" | 締切: \" . \$parsed[\"deadline\"] . \" | 盛り上がり: \" . \$parsed[\"engagement\"] . \" | \" . \$parsed[\"url\"] . PHP_EOL;
}
"'
```

キーワードを変えれば他カテゴリも取得可能:
- ワンピース: `\"ワンピカード OR ワンピースカード\"`
- 遊戯王: `\"遊戯王\"`

### 全カテゴリ一括取得

```bash
ssh -i ~/.ssh/souhatsu.key souhatsu@sv16601.xserver.jp -p 10022 'cd ~/oripanews.com/public_html && /opt/php-8.3/bin/php -r "
require_once \"lib/XSearch.php\";
use App\XSearch;
\$config = require \"config/twitter.php\";
\$x = new XSearch(\$config);
\$categories = [\"ポケカ\" => \"ポケカ\", \"ワンピース\" => \"ワンピカード OR ワンピースカード\", \"遊戯王\" => \"遊戯王\"];
foreach (\$categories as \$label => \$kw) {
    echo \"\\n=== \$label ===\".PHP_EOL;
    \$result = \$x->searchCampaignTweets(\$kw, 20);
    echo \"取得件数: \" . count(\$result[\"tweets\"]) . \" (cached: \" . (\$result[\"cached\"] ? \"yes\" : \"no\") . \")\" . PHP_EOL;
    foreach (\$result[\"tweets\"] as \$t) {
        \$p = XSearch::parseTweetData(\$t);
        echo \$p[\"title\"] . \" | @\" . \$p[\"username\"] . \" | 景品: \" . \$p[\"prize\"] . \" | 締切: \" . \$p[\"deadline\"] . \" | 盛り上がり: \" . \$p[\"engagement\"] . \" | \" . \$p[\"url\"] . PHP_EOL;
    }
}
"'
```

---

## Step 3: 結果を返す

取得結果をテーブル形式で報告する:

```
## ポケカ プレキャン一覧（N件）

| タイトル | アカウント | 景品 | 締切 | 盛り上がり | リンク |
|---------|-----------|------|------|-----------|--------|
| ... | @xxx | BOX名 | 4/10 | 🔥🔥(200) | URL |
```

盛り上がり度の目安:
- 500以上: 🔥🔥🔥
- 100以上: 🔥🔥
- 20以上: 🔥
- 20未満: ―

---

## Step 4: キャッシュ管理（必要な場合）

キャッシュをクリアして最新データを取得したい場合:

```bash
ssh -i ~/.ssh/souhatsu.key souhatsu@sv16601.xserver.jp -p 10022 "rm -f ~/oripanews.com/public_html/data/cache/campaign_*.json"
```

キャッシュは1時間（3600秒）で自動失効する。

---

## 検索クエリの仕組み

### 現在のクエリ構造

```
{カテゴリキーワード} (プレゼントキャンペーン OR プレゼント企画 OR "BOXプレゼント"
OR "BOXをプレゼント" OR "名様にプレゼント" OR "抽選でプレゼント"
OR "抽選で1名" OR "抽選で2名" OR "抽選で3名" OR "抽選で5名"
OR "抽選で6名" OR "抽選で10名")
-is:retweet -当選 -届きました -届いた -ありがとうございます
-販売情報 -入荷 -買取 -招待コード -入場者プレゼント
```

### 除外ワードの設計思想

| 除外ワード | 理由 |
|-----------|------|
| `-is:retweet` | リツイートは重複するため |
| `-当選 -届きました -届いた` | 当選報告ポストを排除 |
| `-ありがとうございます` | お礼ポストを排除 |
| `-販売情報 -入荷 -買取` | ショップの在庫情報を排除 |
| `-招待コード` | オリパサイトの招待宣伝を排除 |
| `-入場者プレゼント` | 映画特典等の情報を排除 |

### クエリを修正する場合

`lib/XSearch.php` の `searchCampaignTweets()` メソッド内の `$query` を編集する。
修正後はキャッシュクリア + デプロイが必要。

---

## API利用量について

- X API v2 Pay-Per-Use（従量課金）プラン
- Usage API: `GET /2/usage/tweets` で消費量を確認可能
- 月間上限: 2,000,000ツイート
- 20件 x 3カテゴリ = 60件/回 → 影響は極めて小さい

### 利用量確認コマンド

```bash
ssh -i ~/.ssh/souhatsu.key souhatsu@sv16601.xserver.jp -p 10022 'cd ~/oripanews.com/public_html && /opt/php-8.3/bin/php -r "
require_once \"lib/XSearch.php\";
use App\XSearch;
\$config = require \"config/twitter.php\";
\$x = new XSearch(\$config);
// Bearer Tokenはキャッシュ済みなら再利用される
echo \"キャッシュ状態を確認:\\n\";
\$files = glob(\"data/cache/campaign_*.json\");
foreach (\$files as \$f) {
    \$d = json_decode(file_get_contents(\$f), true);
    \$exp = \$d[\"expires_at\"] ?? 0;
    \$remaining = max(0, \$exp - time());
    echo basename(\$f) . \" → 残り\" . floor(\$remaining/60) . \"分\\n\";
}
"'
```

---

## 注意事項

- ローカルにはX API認証情報（`.env`）がないため、**必ずXserver経由**で実行する
- Bearer Tokenも `data/cache/bearer_token.json` にキャッシュされる（1時間）
- ノイズ（プレキャンでない投稿）を見つけたら、除外ワードを `$query` に追加して改善する
