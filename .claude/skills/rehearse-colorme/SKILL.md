---
name: rehearse-colorme
description: >
  カラーミーショップの「テストショップ」と wp-env の開発サイトの間で、Cart Bridge JP の全件リハーサル（インポート全般 →
  同じショップへの往復エクスポート → Woo 生まれのデータの作成エクスポート）を回し、ColorMe 側の値が往復で変わっていないかを
  スナップショットの差分で確かめる手順とスクリプト。「リハーサルして」「全件 E2E」「往復でデータが欠けないか確かめて」
  「テストショップで実データ移行を試して」「R3-1」「公開前の最終確認」「スナップショットを取って比べて」などと言われたら使う。
  実店舗（本番のショップ）には使わない（ユーザーが店舗を名指しして明示的に頼んだときだけ。安全規則参照）。
---

# /rehearse-colorme — テストショップでの全件リハーサル

単体テスト・mock では見えない**実 API との往復のずれ**（取込みでの変換 → Woo → export で ColorMe へ戻した値）を、
ColorMe 側のスナップショットを往復の前後で比べて見つける。R3-1（v1.0 公開前の最終確認）のために作った。
R3-4（公開）前の再確認や、v2.0 の BASE（B4-6）でも同じ考え方で使う。

## 安全規則（最初に読む）

- **テストショップ専用**。ColorMe に触るスクリプトは引数 `shop=<login_id>` を必須にし、接続中の店舗（`GET /shop.json` の `login_id`）と
  一致しなければ何もせずに止まる（`php/_lib.php` の `cbjp_rh_require_shop()`）。店舗の login_id は repo に書かず、毎回引数で渡す。
  このガードは「接続先が指定した店舗か」を確かめるだけで、「テストショップか」は確かめない（`run type=export` は `acknowledge_production_write` を自動で付ける）。
- 本番の店舗の login_id を渡さない。本番の店舗にはエクスポート（実 PUT/POST）しない。往復エクスポートは ColorMe の値を書き換える試験で、プラグインはリモートを削除できない。
- 開発サイト（wp-env、`http://localhost:10010`）は `reset-local` で初期化する前提。`reset-local` は環境が `local` かつ host が `localhost` のときだけ動く。
- 出力（スナップショット・dry-run の明細）は `.rehearsal/`（gitignore 済み）にだけ書く。**実店舗で使った場合、その値（商品・顧客・受注の ID、件数）を
  docs・PR・コミットに書かない**（CLAUDE.md）。テストショップの値でも、記録には件数と変化の種類だけを書く。
- 管理画面での入力（カテゴリ・グループ・クーポン・ストアフロントの注文、OAuth の接続）はユーザーが行う。

## スクリプト

`S=.claude/skills/rehearse-colorme/scripts/rehearse.sh`（リポジトリルートから。wp-env は起動済み、Node は `.nvmrc` の版）。
`$S php <name> key=value ...` は `php/<name>.php` を `wp eval-file` で実行する。

| コマンド | 内容 |
|---|---|
| `$S php inspect shop=<id>` | 店舗の税設定・プラン・件数・カテゴリ/グループ/クーポン/決済/配送と、ローカルの件数（mapping・ジョブ・Woo 実体・Woo の税設定）。読み取りのみ |
| `$S php reset-local [mode=preview\|yes]` | 開発サイトの Woo データ（商品・受注・role が customer だけの顧客・クーポン・商品カテゴリ/タグ・取込んだ画像）と `cbjp_*` テーブル・サンプル選定のオプション・ジョブの pending アクションを消す。接続・マッピング設定・Woo の設定は残す。既定は preview（件数だけ） |
| `$S php seed-shop shop=<id> [part=all\|products\|customers] [categories=require\|skip]` | `ZZR-` の商品 55 件（往復リスクを突く 19 件＋埋め草）と `zzr-…@example.com` の会員 12 件を API で投入。同じ名前・メールが既にあれば飛ばす（途中で失敗した投入は再実行で残りだけ入る） |
| `$S php seed-woo [extra-email=<address>]` | 開発サイトの Woo に、ColorMe 由来でない `ZZW-` の商品 5 件（単純〔SKU なし〕・軽減税率・`zero-rate`・1 軸でセール中のバリエーション・2 軸）と顧客 1 件を作る（手順 3 の作成エクスポート用）。`extra-email` はユーザーが受信できるアドレスで顧客を 1 件足す（`add_member` の通知メールの有無の確認用。repo に書かない） |
| `$S php snapshot shop=<id> label=<name> [side=both\|colorme\|woo]` | ColorMe（商品・会員・クーポン・受注）と Woo（商品・バリエーション・顧客・受注・クーポン・`_cbjp_*` メタ・mapping）を `.rehearsal/<name>.json` へ |
| `$S php check-import label=<name>` | side=both のスナップショットで、取込んだ値を ColorMe の値と突き合わせる（`MISMATCH`＝規則から見て食い違い／`NOTE`＝規則どおりだが往復で問題になりうる変換／`MISSING`）。商品（名前・型番・公開状態〔非公開であるべきものの公開は MISMATCH〕・税区分・価格・在庫と在庫管理・説明・同じ id の重複）、バリエーション（型番・価格・セール価格・在庫）、会員（メール・郵便番号・県・住所・法人名・電話・名前）、受注（合計・税額〔`totals` の標準＋軽減〕・決済/配送のマッピング先） |
| `$S php diff a=<name> b=<name> [entity=products,customers\|all]` | 2 つのスナップショットの ColorMe 側を id・項目単位で比べる（`make_date`/`update_date`/`account_id` は除外） |
| `$S php run shop=<id> type=<dry_run\|import\|dry_run_export\|export> [entities=a,b] [cancel-after=n] [max-minutes=n] [attach=<run_id>] [context=cron\|admin]` | `POST /runs` で始め、その run のジョブだけを Action Scheduler の claim を取って同期で処理（管理画面を開いたままでも二重に処理しない。他の run の閉じたジョブのアクションは処理して片付け、開いたジョブのものは手放す）。**ジョブは既定で未ログイン＋kses あり（WP-Cron と同じ）で処理する**（`context=admin` は管理画面から非同期ランナーが動く場合と同じ管理者。どちらで処理されたかで商品名・説明の保存結果が変わる）。`attach` は止まった run を続きから処理する。paused は再開時刻まで待つ。dry-run は明細を `.rehearsal/<run_id>-items.json` に保存して操作・警告コードの件数を出す。import は検証レポートも出す |
| `$S limits-on '<json>'` / `$S limits-off` | 無料版の上限を差し替える mu-plugin（`templates/mu-plugin-rehearsal-limits.php`）とオプション `cbjp_rehearsal_limits` を置く／消す。`{"order":2}` で受注だけ 2 件、全 entity を `null` にすると Pro 版の解除を模擬（F1-8 と同じ） |

## 手順

各段の結果は `docs/reviews/<branch>/rehearsal.md` などに記録する（件数・判定・見つかった差。店舗の ID は書かない）。

### 0. 準備

1. `$S php inspect shop=<id>` で接続先がテストショップであること、税設定（`tax_type`/`tax`/`reduce_tax_rate`/`tax_rounding_method`）とプランを確認する。
2. **ユーザー（管理画面）**: `ZZR-` で始まる大カテゴリ 2（うち 1 つに小カテゴリ 2）・グループ 2・クーポン 2〜3（定額・定率・最低購入金額つきなど）を作る。
   決済方法を 2 種類にしておくと、受注の決済マッピングを複数で確かめられる。
3. `$S php seed-shop shop=<id>` で商品・会員を投入する（`FAIL` が出たものは API で作れなかった条件として記録する）。
4. **ユーザー（ストアフロント）**: 注文を 3〜5 件（会員でログインして／ゲストで、オプション商品・軽減税率の商品・複数明細・クーポン使用を混ぜる）。
5. 開発サイト: `$S php reset-local`（preview）→ `mode=yes`。Woo の税設定を想定する店舗の構成にする（日本の一般的な構成なら「税込入力・標準 10%・`reduced-rate` 8%」）。
   変更前の値は記録しておく。Mappings タブで決済・配送のマッピングを設定する。
6. `$S php snapshot shop=<id> label=s0 side=colorme`（往復前の ColorMe）。

### 1. インポート（ColorMe → Woo）

1. `$S php run shop=<id> type=dry_run`（全量）→ 警告コードの件数と明細を見る。
2. サンプル（無料版の上限）: 受注が少なければ `$S limits-on '{"order":2}'` → `$S php run shop=<id> type=import` → 件数が上限以下であること。
3. キャンセル試験: `$S limits-on '{"category":null,"tag":null,"product":null,"customer":null,"order":null,"stock":null,"coupon":null,"review":null}'`
   → `$S php run shop=<id> type=import cancel-after=2` → もう一度 `type=import`（最後まで）→ 重複が無いこと（mapping 数＝ColorMe の件数、Woo の実体数が一致）。
4. 上限解除の本移行がサンプル分を作り直さないこと（D16。サンプルで作成済みの実体は `unchanged` か `updated`、`created` にならない）。
5. もう一度 `type=import` → 全件 `unchanged`（checksum 一致）。
6. `$S php snapshot shop=<id> label=w1` → `$S php check-import label=w1`。`MISMATCH` は原因を調べ、`NOTE` は往復リスクの実例として記録する。
7. 検証レポート（`run` が出す `verification`）の件数・受注合計が一致していること。

### 2. 往復エクスポート（Woo → 同じショップへ PUT）

1. `$S php run shop=<id> type=dry_run_export` → 警告（カテゴリ対応の未設定 `category_map_unresolved` など）を見る。Export タブでカテゴリ対応を設定するかは目的に合わせる。
2. `$S php run shop=<id> type=export`（取込んだ実体は mapping があるので全件 PUT。export の checksum は import と別の名前空間のため、初回は手直しが無くても全件送る）。
3. `$S php snapshot shop=<id> label=s1 side=colorme` → `$S php diff a=s0 b=s1`。**差のある値が往復で ColorMe 側に起きた変化**。下の「往復リスク」と照らして仕分ける。
4. もう一度 `type=export` → `unchanged`（カテゴリ未対応の商品は毎回 `updated` になる既知の挙動）。もう一度 `type=import` → 重複なし
   （import/export が同じ mapping 行の checksum を共有するため 1 回だけ再同期する。backlog `R2-M-checksum-shared-row`）。

### 3. Woo 生まれのデータの作成エクスポート

Woo で作った商品・顧客（`_cbjp_*` メタ無し）を export して ColorMe に新規作成し、API で読み戻して値を確かめる。`$S php seed-woo` で作る（単純〔SKU なし〕、可変〔1 バリエーションだけセール〕、
軽減税率、`zero-rate` などの非標準の税区分〔hidden 安全策・issue #78 の発動条件〕、2 軸、顧客）。読み戻した後に import して重複が無いこと。
バリエーションの `option_market_price`（定価）の税基準は API の応答に税込の対が無いので、管理画面・ストアフロントの表示で確かめる。

### 4. mock で確かめるもの

送信結果が不明な状況（D21 / R3-0a/b）とプレミアム限定のベータ機能（受注 export・画像アップロード、D24）は実 API で起こせない・使えないので
`verify-with-mock-adapter`（キー `mockv`）で確かめる。

## 往復リスク（diff・check-import で見る観点）

静的解析で挙げたもの（R3-1）。実データで起きたか・直したかは `docs/10-tasks.md` の R3-1 と `docs/03` を見る。

1. 型番が空 → 取込みが付ける仮 SKU（`colorme-{id}`／`colorme-{pid}-{vid}`）が export で型番として書き戻される
2. `display_state` の `sale_for_members`→`showing`、`showing_for_members`→`hidden`、取込みで private にした商品（販売期間外・売切れ非表示）が `hidden` に固定
3. 在庫管理ありで在庫 null → 取込みで 0 → export で 0（バリエーションでは他のバリエーションと商品の在庫も 0 になる。D22 実装 7.）
4. 説明 HTML の `wp_kses_post` で消えた要素（iframe・script 等）が export で ColorMe からも消える
5. Woo に `reduced-rate` の税区分が無い → `tax_reduced=false`・標準税率で逆算
6. バリエーションの `option_price` null（商品価格を継承）→ 明示の値、`get_sku()`/`get_weight()` の親へのフォールバックで親の値が送られる
7. variable 商品の `sales_price` が最安バリエーションの価格で上書き
8. Woo が税抜入力（かつ税率あり）だと、取込んだ税込額にさらに税を足して逆算する
9. 商品名の `&`・`<` などがエンティティ化・除去されて書き戻される

## 後片付け

- `$S limits-off`（mu-plugin とオプションを消す）。
- テストショップの `ZZR-`・Woo 生まれの商品・会員はプラグインから消せない（リモートを削除しない）。ユーザーが管理画面で消すか判断する。
- 開発サイトを戻すなら `$S php reset-local mode=yes`。Woo の税設定を変えた場合は記録した値に戻す。
- `.rehearsal/` は gitignore 済み。不要なら手で消す。

## 既知の限界

- `diff` は事実（変わった値）だけを出し、想定内か外かは人が仕分ける。
- `check-import` の価格の突合は `tax_type=excluded` かつ `round_off`、または `tax_type=included` の店舗だけ（他の丸めでは価格の行を飛ばす）。
- 商品レベルの重量・単位・表示順・`soldout_display` などは ColorMe の作成・更新 API が受け付けないため、`seed-shop` では作れない（管理画面で設定する）。
