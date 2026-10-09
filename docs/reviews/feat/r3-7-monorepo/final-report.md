# dev-cycle 最終報告: feat/r3-7-monorepo

## 開発内容
- タスク: R3-7 モノレポ化（D29）/ PR #113 https://github.com/artisanworkshop/cart-bridge-jp/pull/113
- 承認された計画の要約: 無料版を `plugins/cart-bridge-jp/`、中身の無い Pro アドオンを `plugins/cart-bridge-jp-pro/` に置き、開発ツールをルートへ。プラグインの動作は変えない。
  ルートを wp-env の `wp-content/cbjp-dev` にマウントして `.htaccess` で HTTP を拒否、無料版の zip の検査を CI と release に、依存の向き（Pro → 無料版）を PHPStan・PHPUnit で確かめる
- コミット:
  - `f2205a5` refactor: make test, rehearsal and build paths explicit before the monorepo move
  - `51be5a5` refactor: move the free plugin into plugins/cart-bridge-jp（改名だけ、297 件）
  - `cc40495` build: run the free plugin's toolchain from the repository root
  - `f5c9121` build: build and check the free plugin zip with one script in CI and release
  - `80c8b88` feat: add the empty Cart Bridge JP Pro add-on skeleton
  - `449eab1` chore: point the skills and topic rules at the monorepo layout
  - `278c3a8` docs: document the monorepo layout (R3-7)
  - `3bedeee` fix: test the Pro boot guards, show build errors and skip worktrees in lint（review-loop R1）
  - `7b5784a` docs: align the docs with the monorepo tooling and record review-loop R1
  - `63a174a` fix: load the Pro classes only after the free plugin is confirmed（review-loop R2）
  - `adc80e6` docs: record the review-loop result for R3-7
  - `16eba60` docs: record dev-cycle gate round 1
- 設計ドキュメントからの逸脱: なし（D29 のとおり）。D29 に無かった細部は `docs/03` §10.6 に記録した
- 既存の問題を 1 つ解消: ルートをプラグインとしてマウントしていたため、gitignore 済みの `colorme.env`（テスト用アプリの OAuth 資格情報）が dev サイトで HTTP 200 で読めた（R3-7 で 404、ルートのマウントは 403）

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | Medium 4・Low 4 | 4 | 4（`r3-7-monorepo/R1-L1`〜`L4`） |
| R2（検証。APPROVE） | R1 の 4 件すべて解消・新規 Low 3 | 3 | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 0 | 0 | 0 | 収束（🟢 Approval recommended） |
| G1 | Codex | 1 | 0 | 1 | 未収束 → G2 へ |
| G2 | Codex | 0 | 0 | 0 | 収束（Didn't find any major issues） |

### 修正した指摘
- なし（ゲートでの修正はなし）

### 修正しなかった指摘（PR 上で未解決のまま残してある）
| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-1 | Codex | P2（Medium） | Pro の通知の翻訳（`load_plugin_textdomain()` と `cart-bridge-jp-pro` の訳）が無い。承認済みの計画で Pro の翻訳は Pro の公開準備に回した範囲。`docs/10` の Pro の節と backlog `r3-7-monorepo/G1-1` に申し送り済み | [r4229717536](https://github.com/artisanworkshop/cart-bridge-jp/pull/113#discussion_r4229717536) |

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/37925599475 green（`16eba60`。PHP quality 8.2/8.3・PHPUnit〈無料版 1767 件・Pro 7 件、`check-dev-mount.sh` 403〉・JS/TS・Distribution〈161 ファイル〉・Dev tooling）
- 品質チェック（`quality.sh`）: green。main の旧手順の配布物との比較で、ファイルと中身は autoloader のクラス名の接尾辞と webpack のモジュール番号を除いて同じ。Plugin Check エラー・警告 0

## 次にできること（人間の判断）
- `colorme.env` の資格情報（テスト用アプリ）が R3-7 より前に dev サイトで HTTP で読める状態だった。同じネットワークの端末から読まれた可能性を気にするなら、テスト用アプリの client_secret の再発行を検討する
- 保留分の修正: G1-1 は Pro の公開準備で対応（`/cbj-dev-cycle fix G1-1` でこの PR に入れることもできる）
- マージ（GitHub 上で人間が行う）→ マージ後は `/post-merge`。手元では `composer install` → `npm run build` → `npx wp-env start`（PR 本文の手順）
- 次のタスク: R3-6（無料版と Pro の境目の切り替え。決め残しは `docs/03` §10.0）
