# dev-cycle 最終報告: refactor/49-platform-adapter-compat-policy

## 開発内容

- タスク: issue #49「v1.0 公開前に `PlatformAdapter` の外部互換ポリシーを決める」
- PR: #58 https://github.com/artisanworkshop/cart-bridge-jp/pull/58
- 承認された計画の要約: issue本文の選択肢(a)+(b)を今実装する（ユーザー判断）。
  - 新設`Adapters\AbstractPlatformAdapter`（本体は空の抽象クラス）を外部（Pro版・サードパーティ）
    アダプタが継承すべき基底とし、`ColorMeAdapter`/`MockPlatformAdapter`を移行
  - v1.0.0公開前は従来どおりインターフェースへの追加・シグネチャ変更を許容し、公開後は既存シグネチャを
    変えず新メソッドは`AbstractPlatformAdapter`に既定実装を添えて追加するルールを`docs/03-design-decisions.md`
    §2 D20 と CLAUDE.md 原則8に明記
  - 上記をリフレクションベースの契約テスト（`tests/unit/Adapters/AbstractPlatformAdapterTest`）で
    CI上検出する仕組みを新設
  - PR #48 の未解決2スレッド（`fetch_order_by_remote_id()`追加へのCodex/Copilot指摘）にD20の決定を
    返信しResolve
- コミット一覧（sha・メッセージ）:
  | sha | メッセージ |
  |---|---|
  | 9a46f97 | refactor: add AbstractPlatformAdapter as the base for external adapters |
  | ebb28c4 | docs: record the PlatformAdapter compatibility policy (D20) |
  | 0328f37 | fix: detect signature drift on default-backed methods and ref/variadic params (R1) |
  | 7fabb49 | docs: note BASELINE review discipline and pending contract-extension backlog items (R1) |
  | 5625cf9 | fix: capture static/by-reference modifiers and decouple default-check from BASELINE (G1) |
  | d1c0311 | fix: require every PlatformAdapter method to have a BASELINE entry (G2) |
  | 5ebcf63 | fix: make the default-implementation check pre-release-aware (G3) |
  | その他 | `docs:` 記録用コミット多数（各ラウンドの状態ファイル更新） |

## 設計ドキュメントからの逸脱

- issue #49 本文の推奨は「(b) は v1.0 公開時に導入」だったが、ユーザー判断で今（v1.0公開前）導入した
  （最終形は同じ。Phase 3 への積み残しがない）
- 契約テスト（`AbstractPlatformAdapterTest`）は issue に無い追加。方針をCI上で検出可能にするため
- D19の「外部アダプタが増えたら周知する」はD20で置き換えた（D19の行は履歴として残し、D20から参照）
- `docs/00-plan-overview.md` §3.2の古いインターフェースの写しは更新していない（`docs/03`優先の既存ルールに従う）
- **要判断として残したもの**: `docs/review-backlog.md`の`PlatformAdapter`契約拡張前提の保留項目
  （`e2-3-push-{product,customer,order}/G1-duplicate-on-retry`＝High3件、
  `fix-46-pref-state-repair/L-unavailable-not-split`＝Low1件）を、R3-4（wordpress.org申請）でのBASELINE
  凍結前に対応するか判断する必要がある旨をD20規則7・R3-4に明記した（本PRでは対応していない）

## review-loop（PR前）

| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | Medium 2件（独立サブエージェント検出） | 2件とも修正 | 0件 |

判定: APPROVE（Critical/Highなし）。詳細: `docs/reviews/refactor/49-platform-adapter-compat-policy/R1.md`

## Codex / Copilot ゲート

| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Copilot | 1 | 1 | 0 | 未収束（次ラウンドへ） |
| G1 | Codex | 2 | 2 | 0 | 未収束（次ラウンドへ） |
| G2 | Codex | 1 | 1 | 0 | 未収束（次ラウンドへ） |
| G2 | Copilot | 0（本文1件） | 1 | 0 | 未収束（次ラウンドへ） |
| G3 | Codex | 0 | — | — | **収束** |
| G3 | Copilot | 0（本文1件） | 1 | 0 | **未確認**（依頼上限3回到達。TIMEOUTではなく応答自体は届いたが、指摘への対応後の再確認はしていない） |

3ラウンドすべてで指摘は3件のバグ（設計テスト自体の検出漏れ。参照渡し/可変長引数の未考慮→static/参照戻り値の
未考慮→BASELINE追記の任意性による穴が2箇所〔追加側・既定実装側〕）で、いずれも一時的な変更で実地に
検出できることを検証したうえで修正した。両ボットとも依頼回数が上限の3回に達したため、これ以上の依頼はしていない。

### 修正した指摘

| ID | bot | 重大度 | 内容 | コミット | スレッド |
|---|---|---|---|---|---|
| R1-1 | 独立サブエージェント | Medium | 参照渡し・可変長引数が契約テストをすり抜ける | 0328f37 | — |
| R1-2 | 独立サブエージェント | Medium | 既定実装取得後のシグネチャ変更が検出されない | 0328f37 | — |
| G1-1 | Copilot | Medium | `static`修飾子・戻り値の参照渡しが未考慮 | 5625cf9 | [#r4087792819](https://github.com/artisanworkshop/cart-bridge-jp/pull/58#discussion_r4087792819) |
| G1-2 | Codex | Medium | BASELINE追記後に既定実装だけ削除される変更が検出されない | 5625cf9 | [#r4087835780](https://github.com/artisanworkshop/cart-bridge-jp/pull/58#discussion_r4087835780) |
| G1-3 | Codex | Medium | G1-1と同一指摘 | 5625cf9 | [#r4087835790](https://github.com/artisanworkshop/cart-bridge-jp/pull/58#discussion_r4087835790) |
| G2-1 | Codex | Medium | BASELINE追記漏れでシグネチャが一切凍結されない（G1-2の裏返し） | d1c0311 | [#r4087943658](https://github.com/artisanworkshop/cart-bridge-jp/pull/58#discussion_r4087943658) |
| G2-2 | Copilot（本文） | Medium | PR #48の該当2スレッドが未Resolveのまま | PR #48側で対応 | [#pullrequestreview-5297527299](https://github.com/artisanworkshop/cart-bridge-jp/pull/58#pullrequestreview-5297527299) |
| G3-1 | Copilot（本文） | Medium | 固定BASELINEが公開前の正当な追加まで失敗させる | 5ebcf63 | [#pullrequestreview-5297617962](https://github.com/artisanworkshop/cart-bridge-jp/pull/58#pullrequestreview-5297617962) |

### 修正しなかった指摘

なし（全件修正済み）。

## 品質ゲート

- CI: https://github.com/artisanworkshop/cart-bridge-jp/actions/runs/35930260416 green
- 品質チェック: green（`composer lint && composer analyze && composer test:wpenv`、PHPUnit 1082 tests）
- 実機（wp-env dev）確認: `AdapterRegistry::get('colorme') instanceof AbstractPlatformAdapter` true、
  `rest_do_request()`で`/cbjp/v1/connections`が200を返すことを確認

## 次にできること（人間の判断）

- **Copilotの状態が「未確認」**: G3の本文指摘（G3-1）は修正済みだが、依頼上限（3回）に達したため
  修正結果に対する再レビューは受けていない。数時間後（別セッションでもよい）に`/fix-copilot-review 58`
  を実行し、遅れて届いた指摘が無いか確認することを推奨（Codexは新規指摘0件で収束済みのため再確認不要）
- **`docs/review-backlog.md`の`PlatformAdapter`契約拡張前提の保留項目**（`e2-3-push-*/G1-duplicate-on-retry`
  High3件・`fix-46-pref-state-repair/L-unavailable-not-split`Low1件）は、R3-4（wordpress.org申請・
  BASELINE凍結）前に対応要否を判断する必要がある（本PRでは未着手）
- **`docs/10-tasks.md` R3-4に追加した凍結手順**（`v1_method_names()`をリテラル配列へ書き換える）を、
  実際のv1.0.0公開作業時に必ず実行すること
- マージ（GitHub上で人間が行う）→ マージ後は `/post-merge`
