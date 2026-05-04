---
name: fetch-sales-info
description: "ポケカ抽選・予約販売情報を nyuka-now / pokecawatch から取得し、data/sales-info.json に追記して X 投稿候補を作成する。'販売情報取得', 'sales info', '抽選情報更新', '販売情報更新', 'ポケカ抽選取得' で発動。"
user_invocable: true
metadata:
  version: 1.0.0
---

# ポケカ抽選・予約販売情報 取得スキル

ポケカ（ポケモンカード）の**抽選・予約販売情報**を2ソースから取得し、`data/sales-info.json` に追記する。
重複排除と tweet_text 事前生成までを Claude が担当し、実際の投稿は `bin/post-sales-info.php` が cron / 手動で行う。

## 対象ソース

| ソース | URL | 取得方法 |
|--------|-----|---------|
| nyuka-now | `https://nyuka-now.com/archives/category/chusen` から最新まとめ記事 | 1ページに複数店舗の情報が集約 |
| pokecawatch | `https://pokecawatch.com/category/抽選・予約情報` から個別記事 | 一覧→個別記事の2段階 |

snkrdunk は SSR で WebFetch で取得不可のため除外。

## 制約

- **ポケカ限定**（他カテゴリは除外）
- **未来の抽選のみ**（`entry_end` が現在時刻より未来）
- **引用元URLはツイートに含めない**（`source_url` は記録のみ）
- **同一販売情報は1回のみ**（dedup_key で判定）

## ワークフロー

### Step 1: 現在のサーバー時刻を確認

時刻判定の基準を必ずサーバー側で取得する（メモリ参照のみで判断しない）。

```bash
date '+%Y-%m-%d %H:%M:%S %z'
```

### Step 2: 既存マスタを読み込む

`data/sales-info.json` を読み、既存の `dedup_key` 一覧を把握する。新規追加分の重複判定に使う。

```bash
cat data/sales-info.json | jq -r '.items[].dedup_key' | sort -u
```

### Step 3: nyuka-now の最新まとめ記事を取得

WebFetch で `https://nyuka-now.com/archives/category/chusen` を読み、**最新の「【YYYY年MM月DD日更新】」まとめ記事URL**を1つだけ抽出する。

そのURLを WebFetch で詳細取得する（プロンプト例）:

```
このまとめ記事から「ポケモンカードの抽選・予約情報」を全て抽出してください。
「在庫あり（先着）」セクションは除外。「抽選・予約応募受付中」のセクションのみ。
各エントリーを以下のJSON形式で返してください:
[{
  "store": "店舗名",
  "products": ["商品名1", "商品名2"],
  "lottery_type": "WEB抽選 | 店頭抽選 | アプリ抽選 | 予約 | 招待制販売",
  "entry_start": "YYYY-MM-DD HH:MM",
  "entry_end": "YYYY-MM-DD HH:MM",
  "result_announce": "YYYY-MM-DD HH:MM | テキスト | null",
  "release_date": "YYYY-MM-DD | null",
  "detail_url": "応募フォーム/詳細URL"
}]
```

### Step 4: pokecawatch の新規記事を取得

WebFetch で `https://pokecawatch.com/category/%E6%8A%BD%E9%81%B8%E3%83%BB%E4%BA%88%E7%B4%84%E6%83%85%E5%A0%B1` を読み、
記事一覧（タイトル / URL / 投稿日時）を取得する。

直近1週間以内に投稿された「【ポケカ】◯◯ 抽選・予約情報」記事のうち、まだ sales-info.json に
含まれていない商品（product_normalized が一致するものがない）について、個別記事をWebFetch する。

個別記事から取得するもの:
- `product_name`（正式名称）
- `release_date`（発売日）
- `stores[]`（店舗別の lottery_type / entry_start / entry_end / result_announce）

### Step 5: 正規化と重複排除

#### 商品名の正規化（product_normalized）

商品キーワードのみ抽出。例:
| 元の表記 | product_normalized |
|---------|-------------------|
| ポケモンカードゲーム MEGA 拡張パック「アビスアイ」 | abyss-eye |
| ポケモンカード アビスアイ | abyss-eye |
| メガブレイブ・メガシンフォニア | megabrave-megasymphonia |
| ニンジャスピナー | ninja-spinner |
| MEGAドリームex | mega-dream-ex |
| ブラックボルト・ホワイトフレア | blackvolt-whiteflare |

#### 店舗名の正規化（store_normalized）

ローマ字 + 小文字 + ハイフン区切り。チェーン名のみ抽出。例:
| 元の表記 | store_normalized |
|---------|-----------------|
| WonderGOO / 新星堂各店 | wondergoo-newseido |
| キッズリパブリック（アプリ） | kids-republic |
| シーガル各店 | seagull |
| エディオン・トレカキャピタル | edion-cp |
| ビックカメラ各店 | bic-camera |
| イオン北海道eショップ | aeon-hokkaido |
| ポケモンセンターオンライン | pokemon-center |

#### dedup_key

```
{store_normalized}|{product_normalized}|{entry_end_date}
```

例: `wondergoo-newseido|abyss-eye|2026-05-06`

`entry_end` が null の場合は `{entry_start_date}` を使用、それも null なら `none` とする。

#### id

```
{product_normalized}-{store_normalized}-{entry_end_date}
```

例: `abyss-eye-wondergoo-newseido-2026-05-06`

### Step 6: フィルタ

以下に該当しない項目は **追加しない**:
- カテゴリがポケカ（products に「ポケモンカード」を含む）
- `entry_end` が現在時刻より未来（または `entry_end` が null だが `entry_start` が直近2週間以内）
- 既存の `dedup_key` と重複していない

### Step 7: tweet_text を生成

各新規アイテムについて、以下のテンプレートで本文を生成（**140字以内**を目標、超えたら短縮）:

```
【ポケカ抽選】{product_short}

▼ {store_short}
受付: {entry_period_jp}
{result_announce_line}
方式: {lottery_type}

#ポケカ #抽選販売
```

- `product_short`: product_normalized から復元した日本語短縮形（「アビスアイ」「メガブレイブ」等）
- `store_short`: 店舗名の短縮（「WonderGOO/新星堂」「ビックカメラ」等、25文字以内）
- `entry_period_jp`: `5/2(土)10:00 〜 5/6(水)20:59` 形式
- `result_announce_line`: あれば `当選発表: 5/14(水)12:00以降`、なければこの行ごと省略
- 招待制販売など特殊な場合は構造を調整（例: 受付が常時の場合は「受付: 招待制（随時）」）

#### reply_text

`detail_url` があれば:
```
▼ 応募はこちら
{detail_url}
```
なければ空文字列。

#### image_url

商品の公式画像URLが取得できれば設定（pokecawatch の og:image 等）。基本は空でOK。

### Step 7.5: scheduled_at（投稿予約時刻）を決める

新規追加する各アイテムに `scheduled_at` を割り当てる。**ルール**:

1. **1日3投稿まで** — 同じ日付に既に3件 scheduled_at があるなら、その日は埋まり（次の日へ）
2. **締切早い順** — `entry_end` が早いものから先に枠を埋める
3. **時間帯固定**: 1枠目=12:00 / 2枠目=16:00 / 3枠目=20:00 (+09:00)
4. **当日残枠**: 「現在時刻より後の枠」のみ使える（例: 16時実行なら 20:00 のみ可）
5. **「今日」の枠を使い切ったら明日へ**

#### 締切に間に合わないケース

`scheduled_at >= entry_end` になる項目は **必ずユーザーに相談**。スキル側で勝手に「投稿しない」「時間を詰める」を判断しない。報告フォーマット:

```
⚠️ 締切に間に合わない項目があります:
- 店舗: ◯◯ / 商品: ◯◯ / 締切: YYYY-MM-DD HH:MM / 配信予定枠: なし
対応案:
  (a) 1日4投稿に増やして詰める
  (b) この項目はスキップ (status=skipped)
  (c) その他
```

ユーザー確認後に実行する。

#### 既存スケジュールの確認

```bash
jq -r '.items[] | select(.status == "pending") | .scheduled_at' data/sales-info.json | sort | uniq -c
```

日別件数を確認してから新規枠を割り当てる。

### Step 8: sales-info.json に追記

各新規アイテムを以下の形で `items[]` に追加:

```json
{
  "id": "abyss-eye-wondergoo-newseido-2026-05-06",
  "dedup_key": "wondergoo-newseido|abyss-eye|2026-05-06",
  "source": "nyuka-now",
  "source_url": "https://nyuka-now.com/archives/2459",
  "store": "WonderGOO / 新星堂各店",
  "store_normalized": "wondergoo-newseido",
  "product": "ポケモンカード アビスアイ",
  "product_normalized": "abyss-eye",
  "lottery_type": "店頭抽選",
  "entry_start": "2026-05-02T10:00:00+09:00",
  "entry_end": "2026-05-06T20:59:00+09:00",
  "result_announce": "2026-05-14T12:00:00+09:00",
  "release_date": "2026-05-22",
  "detail_url": "https://nyuka-now.com/archives/157981",
  "tweet_text": "【ポケカ抽選】アビスアイ\n\n▼ WonderGOO/新星堂\n受付: 5/2(土)10:00 〜 5/6(水)20:59\n当選発表: 5/14(水)12:00以降\n方式: 店頭抽選\n\n#ポケカ #抽選販売",
  "reply_text": "▼ 応募はこちら\nhttps://nyuka-now.com/archives/157981",
  "image_url": "",
  "fetched_at": "2026-05-04T15:30:00+09:00",
  "scheduled_at": "2026-05-05T12:00:00+09:00",
  "status": "pending"
}
```

JSON書き込み後、必ずバリデーション:

```bash
php -r 'json_decode(file_get_contents("data/sales-info.json"), true) ?: exit("JSON invalid\n");' && echo OK
```

### Step 9: 結果サマリ

ユーザーに以下を報告:

```
## 取得結果
- nyuka-now: N件取得 / うち新規 X件
- pokecawatch: N件取得 / うち新規 X件
- 合計新規: X件 / pending総数: Y件

## 次回投稿候補（受付終了が最も近い pending）
- 店舗: ...
- 商品: ...
- 受付終了: ...
- tweet_text プレビュー: ...
```

その後、**deploy-site スキル** で本番反映するか確認する。

## デプロイ後の運用

cron 設定（Xserver側、2026-05-04 から稼働中）:

```cron
# 毎時0分: scheduled_at <= now の pending を1件ずつ投稿
0 * * * * cd /home/souhatsu/oripanews.com/public_html && /usr/bin/php8.3 bin/post-sales-info.php --auto >> data/sales-post.log 2>&1
# 毎日 0:00: entry_end 経過の pending を expired にマーク
0 0 * * * cd /home/souhatsu/oripanews.com/public_html && /usr/bin/php8.3 bin/post-sales-info.php --expire >> data/sales-post.log 2>&1
```

post-scheduled.php (記事配信) と同じく **毎時0分** 実行。scheduled_at は時刻精度 (毎時00分にマッチする時刻) で設定する。

`--auto` は `scheduled_at` が設定されていない pending は触らないので、毎分実行でも安全。
ユーザーが cron 化する前は、SSH 経由で `--auto` を都度叩くか、`--id=<id>` で個別投稿。

## 動作確認コマンド

```bash
# 一覧
php bin/post-sales-info.php --list

# 投稿候補
php bin/post-sales-info.php --pending

# dry-run（次の自動投稿をプレビュー）
php bin/post-sales-info.php --auto --dry-run

# 個別投稿
php bin/post-sales-info.php --id=abyss-eye-wondergoo-newseido-2026-05-06 --dry-run
```

## 注意事項

- **引用元 URL を tweet_text に含めない** — `source_url` は記録のみ。
- **重複投稿防止** — 必ず `dedup_key` で既存照合してから追加。
- **時刻情報は ISO8601 + JST (+09:00)** で統一。
- **tweet_text は140字以内** を目標（超えたら短縮）。
- **新規追加0件の場合** — 「新規なし」と報告して終了。投稿候補一覧だけ示してもよい。
- ポケカ以外のカード（遊戯王等）は本スキルでは扱わない（将来別スキル化）。
