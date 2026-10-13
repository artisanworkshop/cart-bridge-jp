# dev-cycle 最終報告: feat/r3-4a-wporg-submission-prep

## 開発内容
- タスク: **R3-4a — wordpress.org 申請の準備（リポジトリ内で閉じるもの）**（`docs/10-tasks.md` の R3-4 を a・b に分けた。1.0.0 への版上げは Pro の販売サイトの URL 待ちなので R3-4b）
- PR: #133 https://github.com/artisanworkshop/cart-bridge-jp/pull/133
- 承認された計画（`~/.claude/plans/velvet-meandering-crab.md`）の要約と、計画時のユーザー決定（2026-10-13）:
  - Plugin Check の残りの警告 2 つ: 無料版の zip に `composer.json` を入れる（`missing_composer_json_file`）。`load_plugin_textdomain()` は**理由つきで残す**（WordPress 7.1 まではネットワーク有効化のプラグインの翻訳のパスを自動で登録しない）
  - readme の Contributors は `artisan-workshop-1, shohei.tanaka`、ヘッダーに `WC tested up to: 11.2`
  - GitHub Release の本文は **readme の Changelog の節**（`bin/readme-release.php`。`release.yml`）
  - wordpress.org へは**手動実行のデプロイ**（`deploy-wporg.yml`。secrets の無い build と Environment `wordpress-org` の deploy）
  - `AbstractPlatformAdapterTest::v1_method_names()` を固定（D20）。`EntityTypeContractTest` は最初からリテラル配列なので変えない（計画外の申し送りに気付いて見直し、変更不要と記録）
- コミット:

| sha | メッセージ |
|---|---|
| 7a378d5 | feat: clear the remaining Plugin Check warnings and fill in the readme headers (R3-4a) |
| 3875a01 | feat: build GitHub Release notes from the readme changelog and add a manual WordPress.org deploy (R3-4a) |
| dad50b8 | test: freeze the v1.0 PlatformAdapter method list (D20, R3-4a) |
| 31ada04 | docs: record R3-4a (WordPress.org submission prep) in the plan and design docs |
| 9e131d5 | fix: read the readme with the WordPress.org parser's rules in readme-release.php |
| e1178c9 | ci: keep the WordPress.org SVN credentials out of the build job |
| e7a112f | docs: record review-loop R1 for R3-4a |
| 12cb4e4 | fix: match the remaining WordPress.org readme parser rules in readme-release.php |
| 1b2cbb5 | ci: correct the deploy environment advice and keep the artifact for 7 days |
| d3b9378 | docs: record review-loop R2 for R3-4a |
| f447c5a | docs: record dev-cycle gate round 1 for R3-4a |

- 設計ドキュメントからの逸脱（いずれも計画時のユーザー決定。`docs/03` に記録済み）:
  1. `AbstractPlatformAdapterTest` の固定を「v1.0.0 公開時」より前（R3-4a）に行った（時期だけ）
  2. 無料版の zip に `composer.json` を入れる（§10.6・`bin/build-zip.sh` は除く前提だった）
  3. GitHub Release の本文を自動生成から readme の Changelog の節へ
  4. `load_plugin_textdomain()` を残し、Plugin Check の警告を注釈で抑える

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己レビュー＋独立サブエージェント opus） | Medium 1・Low 4・対象外 1 | 5（Medium: `readme-release.php` の見出し・ヘッダーの判定を wordpress.org のパーサーと同じ規則に。Low: CLI の入口のテスト・release.yml の本文の確認・deploy-wporg.yml のジョブ分割・docs） | 1 |
| R2（独立サブエージェントが wordpress.org のパーサーを動かして突き合わせ） | **APPROVE**。新規 Low 3 | 3（ヘッダーの終わりのテスト・行の区切り／`empty()`／`##` の差・Environment の保護の勧め方と artifact の保存期間） | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 0 | 0 | 0 | 収束（自動レビューが 5 分で来ず nudge で依頼。「Didn't find any major issues」） |
| G1 | Copilot | 0 | 0 | 0 | 収束（「0 open findings」。判定 🔵 Needs a closer look は総評だけ） |

### 修正した指摘
なし（ゲートでの指摘は 0 件）

### 修正しなかった指摘（PR 上で未解決のまま残してある）
なし

## 品質ゲート
- CI: G1 の対象 d3b9378 は green（PHP quality 8.2/8.3・PHPUnit〔wp-env〕・JS/TS・Distribution・Dev tooling）。最終 HEAD の CI は push 後に確認する
- 品質チェック（`quality.sh`）: green（無料版 PHPUnit 1386 件・Pro 867 件・Jest 111 件・i18n・dev tooling）
- 変異: `readme-release.php` の実装時 11 か所・R1 の 12 か所・R2 の 6 か所、readme・`.distignore`・ヘッダー・`build-zip.sh` がすべて検出。凍結（固定前は既定実装の無い追加を通し、固定後は止める）
- 配布 zip の Plugin Check 2.1.0（全チェック）: 2 つの警告が消え、ほかは main と同じ（展開したものは削除済み）。`actionlint` 0 件

## マージ前に人が確認すること
- Copilot の総評: **リリースと wordpress.org へのデプロイの自動化（`release.yml`・`deploy-wporg.yml`）は最終的に人が確認を**。どちらもこの PR の中では動かせない（タグの push・手動実行）。
  `deploy-wporg.yml` は承認・secrets の登録の後にだけ使い、初回は `dry_run` で変更の一覧（`svn status`）を見る想定
- 無料版の配布 zip に `composer.json` が入る（`require` は php と拡張だけ、`autoload-dev` は zip に無い `tests/` を指すが実行時の害は無い）

## 次にできること（人間の判断）
- マージ（GitHub 上で人が行う）→ マージ後は `/post-merge`
- R3-4b（販売サイトの後）: Pro の販売サイトの URL を readme の FAQ へ、1.0.0 への版上げ、バナーとアイコン、Plugin Check（ソースと zip）、申請。承認後に Environment `wordpress-org` の secrets と保護
  （承認者・デプロイできるブランチ = main）を設定し、`v1.0.0` のタグ → `deploy-wporg.yml`（初回は `dry_run`）。translate.wordpress.org へ日本語訳の取込み。release-bump スキルの対応
- backlog `r3-4a-wporg-submission-prep/R1-X1`（リリースの経路で `CBJP_VERSION` を照合するか）は R3-4b の版上げの前に判断する
