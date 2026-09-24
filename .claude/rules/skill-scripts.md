---
paths:
  - ".claude/skills/**/scripts/**"
  - ".claude/skills/**/templates/**"
---

# `.claude/skills/` 配下のスクリプト・テンプレートの落とし穴

`ci-wait.sh`・`bot-wait.sh`・`mock-adapter.sh` などの開発補助スクリプトが対象（プラグイン本体ではない）。
PR #50 では同種の指摘を Copilot・Codex から計 4 ラウンド受けた。

- **判定の前提になる取得（PR の HEAD・ブランチ名・存在確認など）を `|| true` で握りつぶさない**。一時的な API 失敗が
  「変化なし」「ガード不要」に化けて、古い HEAD のチェック結果を成功と報告する（`ci-wait.sh` の最終 HEAD 取得と起動時の
  ブランチ名取得で連続して指摘された）。既存の失敗ポリシー（`MAX_API_FAILS` 回まで再試行し、超えたら非ゼロで終了）で扱う。
  `|| true` を付けてよいのは、失敗しても結論が変わらない取得だけ（アーキテクチャ原則 9「フェイルクローズ」のスクリプト版）
- **`set -e` 下で `out=$(cmd)` を単独の代入文にしない**。`cmd` が失敗した時点でスクリプトごと終了し、次行で出力を表示する前に
  エラー本文が消える（終了コードだけが残る）。`if out=$(cmd); then rc=0; else rc=$?; fi` で受けてから出力し、`return "$rc"` する
  （`mock-adapter.sh` の `wp()` 参照）
- **mu-plugin テンプレートは致命的エラーを出さない**。mu-plugin の fatal は開発サイトの全リクエストと、WP を起動する
  `mock-adapter.sh run`/`inspect`（＝ seed の cleanup）を落とす（`uninstall` はホスト側の `rm` なので効く）。
  オプション等から読む値は型を確認し、想定外の値は読み飛ばす（`templates/mu-plugin-mock-adapter.php` の `$rows()` 参照）
- **bot レビュー本文の整形（`gate-bodies.sh`）は、本文の形式が変わっても指摘を握りつぶさない側に倒す**。Copilot の本文は旧形式（`Suppressed comments`）と新形式（`ccr-overview-v2`: `Open`/`Previously missed`/`What changed in this PR` の `<details>`）が混在し、`Previously missed` はスレッドの無い新規指摘なのに旧版は整形出力に出さず、`--raw` で読まなければ見落とすところだった（PR #61 G2）。未知の `<details>` 節は出す側に倒し、ノイズと分かっている節（ファイル要約の表）だけ明示的に除外する。整形ロジックの回帰テストは `scripts/test-gate-bodies.sh`（`--format` に fixtures の本文を流す。ネットワーク不要。`quality.sh` と CI の `dev-tooling` ジョブで実行される）
