# ゲートラウンド G9（テスト用の記録）
- PR: #64 / 対象 HEAD: abc1234
- レビュー: [Codex #1](https://example.com/pull/64#pullrequestreview-1) / [Copilot #2](https://example.com/pull/64#pullrequestreview-2)（🟡 Changes recommended）
- 再依頼: していない

## 指摘
### [G9-1][Codex P1][src/a.sh:10]
`--first` が旧 HEAD の自動レビューを応答と誤認する。
判定: **修正**。実コードで確認した。
**対応:** CI 待ちの前後で HEAD を比べる。
コミット: @SHA@
スレッド: [r1001](https://example.com/pull/64#discussion_r1001)

### [G9-2][Copilot][src/b.sh:20]
要旨: 表の | を含む要旨。
2 行目の要旨。
判定: 修正
対応: 1 行目の対応。

- 箇条書き A
- 箇条書き B
コミット: @SHA@, @SHA@
スレッド: [r1002](https://example.com/pull/64#discussion_r1002)

### [G9-3][Copilot][src/c.sh:30]
要旨: 誤検知の指摘。
<!-- コメント行は読み飛ばす。TODO(記入) と書いてあっても数えない -->
判定: 対応不要（誤検知）【承認済み】
対応: 実コードに該当の形が無い。
コミット: —
スレッド: [r1003](https://example.com/pull/64#discussion_r1003)

### [G9-4][Copilot][src/d.sh:40]
要旨: 承認を記録していない誤検知。
判定: 対応不要（誤検知）
対応: 実コードに該当の形が無い。
コミット: —
スレッド: [r1004](https://example.com/pull/64#discussion_r1004)

### [G9-5][Copilot][src/e.sh:50]
要旨: 後日対応する Low。
判定: 保留
対応: Low のため backlog へ。
コミット: —
スレッド: [r1005](https://example.com/pull/64#discussion_r1005)

### [G9-B1][Copilot][src/f.sh:60]
要旨: 本文指摘（スレッド無し）。
判定: 修正
対応: 文言を訂正した。
コミット: @SHA@
スレッド: なし（本文指摘）

## 検証
- テスト: 86 項目すべて ok
- ミューテーション 10 種を検出

## 収束判定
| bot | 依頼回数 | 新規スレッド | 状態 |
|---|---|---|---|
| Codex | 1 | 1 | 未収束 |
