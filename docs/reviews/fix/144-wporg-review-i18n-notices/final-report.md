# dev-cycle 最終報告: fix/144-wporg-review-i18n-notices

## 開発内容
- タスク: issue #144 — wordpress.org の初回レビュー（2026-10-16、自動の事前レビュー）への対応 / PR #145 https://github.com/artisanworkshop/cart-bridge-jp/pull/145
- 承認された計画（`~/.claude/plans/synthetic-enchanting-abelson.md`）:
  - 🔴 `load_plugin_textdomain()` を外す・🟡 翻訳ファイルを配布物に入れない（ユーザー決定: `.mo`・`.l10n.php`・JSON はリポジトリからも消し、POT と `ja.po` は translate.wordpress.org へ取り込む元として残す。Pro は今のまま）
  - ガイドライン 11: 前提条件の通知 3 つを「プラグイン」画面と Cart Bridge JP の画面だけに出す（ユーザー決定）
  - 版は 1.0.0 のまま（未公開の申請の差し替え）
- コミット:

| sha | メッセージ |
|---|---|
| 5d0a331 | fix: stop bundling and loading translations in the free plugin |
| a7e7166 | fix: show the requirement notices only on the Plugins screen and the plugin page |
| cd3a525 | docs: record the response to the first WordPress.org review |
| 1dd7a2f | test: pin the requirement notice screens and the sodium notice entry point |
| 39a8f8e | docs: record review-loop R1 for the WordPress.org review response |
| 6b21688 | docs: record review-loop R2 for the WordPress.org review response |
| 2b5f616 | test: pin that the admin script translations come from the language pack |
| ca88c78 | docs: record dev-cycle gate round 1 |
| 2eed0c2 | test: pin that the PHP strings come from the language pack |
| 1312889 | docs: record dev-cycle gate round 2 |
| 4dc6bdb | test: pin the admin page enqueue and the script translation output |
| ad7751f | docs: record dev-cycle gate round 3 |

- 設計ドキュメントからの逸脱（合意済み。`docs/03` に記録した）:
  - §6「翻訳（R3-2）」の「`languages/` は配布物に入る」と R3-4a の「`load_plugin_textdomain()` を残す」をやめた。**承認後、日本語の言語パックができるまで、日本語のサイトでも無料版の画面は英語になる**
  - §7 のヘッダーから `Domain Path` を外した
  - 前提条件の通知を全管理画面から 2 画面に絞った（§7「前提条件の通知」）

## review-loop（PR 前）
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1（自己＋独立サブエージェント） | Critical/High/Medium 0・Low 6・対象外 2 | Low 5 | Low 1（R1-L3。G1・G3 で解消）・対象外 2 |
| R2（独立サブエージェントの検証） | 新規 0（R1 の修正 5 件が解消） | 文言の細部 1 | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 0 | 0 | 0 | 収束（Didn't find any major issues） |
| G1 | Copilot | 2（本文） | 1 | 1 | 未収束 |
| G2 | Copilot | 3（本文） | 1 | 1（＋対応不要 1） | 未収束 |
| G3 | Copilot | 2（本文） | 2 | 0 | 上限（3 回目。4 回目は依頼しない） |

Copilot の指摘はすべて、判定 🔵 の見出し直下の一文だけ（インラインのスレッド 0 件・箇所の指定なし）だった。

### 修正した指摘
| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| G1-B1 | Copilot | Low | 管理画面の JS の訳の登録のテスト（R1-L3）: `Assets::set_script_translations()` に切り出し、ドメイン・パスなし・言語パックの JSON を探すことを固定 | 2b5f616 | なし（[review 5555358374](https://github.com/artisanworkshop/cart-bridge-jp/pull/145#pullrequestreview-5555358374)） |
| G2-B2 | Copilot | Low | PHP の訳が言語パックから読まれることのテスト（本体のフィルターで模擬。`WP_LANG_DIR` に書かない） | 2eed0c2 | なし（[review 5555557616](https://github.com/artisanworkshop/cart-bridge-jp/pull/145#pullrequestreview-5555557616)） |
| G3-B1 | Copilot | Low | `enqueue()` が訳を登録することのテスト（`Assets` のコンストラクタが資産の一覧のパスを受ける） | 4dc6bdb | なし（[review 5555706362](https://github.com/artisanworkshop/cart-bridge-jp/pull/145#pullrequestreview-5555706362)） |
| G3-B2 | Copilot | Low | JS の訳の実際の出力のテスト（JSON を一時ファイルへ差し替え） | 4dc6bdb | なし（[review 5555706362](https://github.com/artisanworkshop/cart-bridge-jp/pull/145#pullrequestreview-5555706362)） |

### 修正しなかった指摘（スレッドが無い本文指摘。記録は G<n>.md と PR のサマリコメント）
| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-B2 | Copilot | Low | ネットワーク管理画面（`plugins-network`）では `admin_notices` が発火せず、画面 ID を足しても効かない。main の時点から同じで退行ではない → backlog（マルチサイトは issue #122） | なし（[review 5555374840](https://github.com/artisanworkshop/cart-bridge-jp/pull/145#pullrequestreview-5555374840)） |
| G2-B1 | Copilot | — | 対応不要: `.mo` は削除済み。Copilot がバイナリの差分を読めない既知の誤検知 | なし（[review 5555557616](https://github.com/artisanworkshop/cart-bridge-jp/pull/145#pullrequestreview-5555557616)） |
| G2-B3 | Copilot | Low | G1-B2 の再掲（保留のまま） | なし（[review 5555557616](https://github.com/artisanworkshop/cart-bridge-jp/pull/145#pullrequestreview-5555557616)） |

## 品質ゲート
- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/38999609873（ad7751f） green / 品質チェック: green（無料版 PHPUnit 1413 件・Pro 1005 件・Jest 111 件・`i18n:check`・マウントの 403）
- `bin/plugin-check-zip.sh --build`（実装時）: ERROR 0、警告は `docs/03` §10.9 で受け入れた DB の 4 種だけ、zip に `languages/` なし
- dev サイトで言語パックを模擬: PHP の `__()` と管理画面の JS が日本語になり、外すと英語に戻る

## 次にできること（人間の判断）
- マージ（GitHub 上で人が行う）→ マージ後は `/post-merge`
- **wordpress.org への再提出**（マージ後）: main で `bin/build-zip.sh` を実行して `dist/cart-bridge-jp-1.0.0.zip` を作り（必要なら `bin/plugin-check-zip.sh` で再確認）、「Add your plugin」のページ（アカウント `artisan-workshop-1`）から上げ直し、レビューのメールのスレッドに短く返信する（英文案は報告に載せた）
- 承認後: `plugins/cart-bridge-jp/languages/cart-bridge-jp-ja.po` を translate.wordpress.org へ取り込む（言語パックができるまで日本語のサイトでも英語になる。`docs/10` R3-4b）
- backlog に送ったもの: `fix/144-wporg-review-i18n-notices/G1-B2`（ネットワーク管理画面の通知）・`R1-X1`（sodium の通知の権限）・`R1-X2`（docs/03 の「too early」の記述）
