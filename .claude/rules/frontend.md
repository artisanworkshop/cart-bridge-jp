---
paths:
  - "src/**"
  - "includes/Admin/Assets.php"
---

# フロントエンド（React/TypeScript）・管理画面の規約

> 管理画面 UI（`src/**`）と、そのアセット読み込み（`includes/Admin/Assets.php`）・OAuth ポップアップ・タブ/CSS を触るときに効く。
> CLAUDE.md の「コーディング規約」「アーキテクチャ原則」と併せて守る。ここは**触るファイルに対応するときだけ読み込まれる**パス指定ルール（`paths` frontmatter）で、内容は CLAUDE.md にあった項目をそのまま移したもの（issue/PR の番号は各項目に残してある）。

- OAuth認可ポップアップは `window.open()` をクリックハンドラから同期的に呼ぶ（await後だとブロックされうる）。`noopener`指定時は成否に関わらず戻り値が常に`null`になる仕様なので、ポーリング等でウィンドウハンドルが必要な場合は`noopener`を使わず、生成できたハンドル側で`.opener = null`を手動設定してreverse tabnabbing対策すること
- `wp-scripts build` はJSエントリー（例: `index`）からimportしたCSSを `build/index.css` ではなく `build/style-index.css`（`style-<エントリー名>.css`）として出力する。`wp_enqueue_style()` 側のパスをこれに合わせないと `file_exists()` ガードが常にfalseになりCSSが一切enqueueされない（`wp-components` のコアCSSも道連れで読み込まれず、管理画面が丸ごと無スタイルになった実例あり。`includes/Admin/Assets.php` 参照）
- 管理画面のタブナビゲーションは自前CSSではなくWordPressコア標準の `nav-tab-wrapper` / `nav-tab` / `nav-tab-active` クラス（`wp-admin/css/common.css` に定義済み）を使うこと。間隔・アクティブ状態の表示が無料で手に入る。自前CSSはコアクラスがカバーしない余白調整のみに留める（`src/App.tsx`, `src/style.css` 参照）
- ポーリングhook（`useRunPolling`等）の`refetch()`が一時的な通信エラーを内部でcatchして自動再試行する設計の場合、そのPromiseは通信の成否に関わらず正常解決する。呼び出し元が「`await refetch()`が解決した＝新しいstateが反映された」と決め打ちすると、一時的な失敗時に古いstateのまま後続処理（例: ボタンの再有効化）が進んでしまう。真に「新しいデータが届いた」ことを検知したい場合は、Promiseの解決ではなくstate自体（成功時のみ新しい参照になるオブジェクト等）の変化をeffectで監視すること（`src/hooks/useRunPolling.ts`, `src/tabs/ImportTab.tsx`のretryJob参照。issue #30）
- 複数ジョブから成るrunの「終端判定」（`isTerminal`: 全ジョブがcompleted/failed/cancelledのいずれか）と「成功判定」（全ジョブがcompleted）を混同しないこと。キャンセル・一部失敗したrunも`isTerminal`はtrueになるため、「完了時のみ出す」UI（全体レポートDL・アップセル集計等）を`isTerminal`だけでゲートすると、部分的・失敗した結果を完全な結果として提示してしまう。個別の判定（全ジョブcompleted）を別途用意すること（`src/components/RunProgress.tsx`のallCompleted参照。issue #30）
- WordPressコアが登録する`wp-components`等の共有アセットハンドルは、WooCommerce等の他プラグインが独自バージョンを登録しているとそちらが優先されることがあり、同じ`@wordpress/components`の`Notice`等でも環境によってレイアウト（例: `flex`+`padding: 8px 12px` vs `grid`+`padding: 12px`）が変わりうる。見た目の余白等を安定させたい箇所はアップストリームのデフォルトに依存せず自前CSSで明示的に上書きすること（`src/style.css`の`.components-notice.is-info`参照）
- 破壊的・本番書込み系の確認ダイアログ（`ImportTab.tsx`の本移行実行確認、`ToolsTab.tsx`のクリーンアップ確認）にネイティブ`window.confirm()`を使っている。ブラウザ拡張系の自動操作（Claude in Chrome等）やE2Eツールからクリックすると、ネイティブダイアログがレンダラーをブロックしてタブがフリーズし、ブラウザ再起動が必要になることがある（F1-8の実店舗テストで発生）。将来wp-e2e-playwright等でE2Eを自動化する場合も同じ問題になるため、`@wordpress/components`のモーダル等ネイティブダイアログに依存しない確認UIへの置き換えを検討すること
- 非同期処理（fetch effect・保存処理等）の古い応答が新しい状態を上書きしないようにするガードは、比較対象を「値の一致」（例: プラットフォーム名）ではなく**単調増加する世代カウンタ**にすること。値の一致だけで判定すると、同じ値へ短時間で戻った場合（例: プラットフォームA→B→A）に古いリクエストの応答を「最新」と誤認する。さらに、同じ状態を更新しうる複数の非同期処理（例: 取得のGETと保存のPUT）がある場合は、それら**すべてが同じ世代カウンタを共有**する必要がある。片方だけ導入してももう片方が古い判定方法のままだと同種のレースが残る（`src/tabs/ExportTab.tsx`の`platformGenerationRef`参照。issue #39）。`ToolsTab.tsx`は置換済み（issue #46）。**`ImportTab.tsx`には値比較の`platformRef`が残っている**（backlog `fix-46-pref-state-repair/R1-X1`）
