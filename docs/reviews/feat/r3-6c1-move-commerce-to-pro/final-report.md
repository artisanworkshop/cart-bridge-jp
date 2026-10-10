# 最終報告: R3-6c1（PR #117）
- ブランチ: `feat/r3-6c1-move-commerce-to-pro` / PR: https://github.com/artisanworkshop/cart-bridge-jp/pull/117（OPEN。マージはユーザーが行う）
- 判定: review-loop R2 で APPROVE、ボットゲート G1 で両 bot 収束（Codex: Didn't find any major issues、Copilot: 0 open findings）。CI は全ジョブ green

## やったこと
- 顧客・受注・クーポンのコード（実体の種類・Canonical・Writer/Reader・`AddressMapper`・ColorMe の変換器・テスト・フィクスチャ）を Pro アドオンへ `git mv` し、名前空間 `CartBridgeJP\Pro\…`・テキストドメイン `cart-bridge-jp-pro` にした
- Pro に専用のアダプタ層（`CommerceAdapter`・`CommerceCapabilities`・`CommerceAdapters`・`ColorMeCommerceAdapter`）を作り、無料版の `PlatformAdapter`・`Capabilities`・`ColorMeAdapter`・`WarningCode` から顧客・受注・クーポンを外した
- 無料版に汎用の口（`MappingKind::platform_candidates()`・`MethodMap::lookup()/reverse_lookup()`・`indicates_reference_not_found()`・`CBJP_EXTENSION_API_VERSION`）を足し、検証レポートは登録の無い種類を「不明」（画面は「Not checked」）にした。Pro は古い無料版では起動しない
- 境目のテスト（Pro の `FreeApiSurfaceTest`・`CommerceAdapterContractTest`、無料版の `FreeScopeTest`）、docs/03 §10.0「R3-6c1 の実装」、`CLAUDE.md` 原則 7、ルール・スキル・i18n

## 確認したこと
- `quality.sh` green（無料版 1209 件・Pro 570 件・Jest 86 件ほか）、CI green、配布 zip に顧客・受注・クーポンのファイル無し
- 特性テスト（カタログのハッシュ `df668212…` を含む）が移動前の期待値のまま Pro で通る
- dev サイト（mock `mockv`）で Pro 有効・Pro を外した状態の両方を REST と画面で確認。撤去後に検証前の状態へ戻した

## マージ前にユーザーが確認すること
1. **実 API（ColorMe）では確かめていない**（Copilot の総評も同じ点を挙げた）。ColorMe の顧客・受注・クーポンの処理は移しただけで、移したテストと特性テストが同じ値で通ることを根拠にしている。テストショップのリハーサル（`/rehearse-colorme`）は、R3-6c2（OAuth スコープの分割）の後にまとめて回すのがよいと考える
2. **Pro へ移した文字列は、Pro の翻訳ができるまで日本語のサイトでも英語になる**（種類名・マッピングの節・受注などの警告の説明・push intent の要約。backlog `r3-6c1/R1-L4`。Pro の公開準備で main の訳を引き継ぐ）
3. **readme とスクリーンショットは R3-6d まで古いまま**（顧客・受注を取り込む等の記述。`wporg-screenshots` は tests サイトで Pro が無効だと止まる。R3-6d で `shots.json` を直してから撮り直す）
4. 次のタスク: R3-6c2（OAuth スコープの分割。R3-4 の前に必須）→ R3-6d

## 持ち越し（backlog）
- `r3-6c1-move-commerce-to-pro/X1`: Pro を止めたサイトで過去の run の顧客・受注の CSV（`?entity=order`）が 400
- `r3-6c1-move-commerce-to-pro/R1-L4`: Pro の翻訳（上記 2）
- `r3-6c1-move-commerce-to-pro/R1-L11`: 登録の無い種類の表示名がキーのまま
