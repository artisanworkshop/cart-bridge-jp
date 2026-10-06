# 設計補遺・確定事項

最終更新: 2026-09-26

`00-plan-overview.md` を具体化した実装設計。他の計画ドキュメント（00〜02・04）と本書が矛盾する場合は**本書を優先**する。
タスクの進行管理は `10-tasks.md` を参照。

## 1. 確定した方針（ユーザー確認済み・2026-07-06 / D11〜D13は2026-07-07 / D14〜D17は2026-07-08 / D18は2026-09-05 / D19は2026-09-13 / D20は2026-09-24 / D21〜D24は2026-09-26 / D25・D26は2026-10-05）

| # | 論点 | 決定 |
|---|---|---|
| D1 | 最初の開発範囲 | Phase 0 のみを最初のマイルストーンとする |
| D2 | API資格情報 | カラーミー: デベロッパー登録・テストショップ・アプリ登録済み / MakeShop: 自社利用登録・エンドポイント・永続トークン取得済み / BASE: BASE Developersアプリ登録済み・テストショップあり（2026-07-07確認）。**要検証事項は各Phaseの最初のタスクで実測して確定する** |
| D3 | タスク管理 | `docs/10-tasks.md` のWBSで管理（GitHub Issuesは使わない） |
| D4 | CI | GitHub Actions を Phase 0 で構築（リモート: `artisanworkshop/cart-bridge-jp`） |
| D5 | インターフェース範囲 | クーポン・タグ（カラーミーのグループ）・レビュー（MakeShop）を**オプショナルエンティティとして Phase 0 のIFに組み込む**（実装は後Phase） |
| D6 | 管理画面 | Phase 0 から React（@wordpress/scripts + TypeScript）基盤を構築 |
| D7 | 接続数 | 無料版は 1接続/プラットフォーム。DBは `platform` カラムで識別（将来 `connection_id` 追加で拡張可能な構造にする） |
| D8 | ブランチ運用 | `main` をデフォルトに、フェーズ/タスクごとに `feat/xxx` ブランチ → PR → CI通過でマージ。既存の `trunk` ブランチは `main` に統合して廃止 |
| D9 | 作者表記 | Author: Artisan Workshop（GitHub org と一致。Author URI は実装時に実URLを確認） |
| D10 | 受注明細の未マッチ商品 | Woo側に商品が無い明細は**カスタム行**（注文時商品名・単価・数量をそのまま）として作成し、元商品IDをメタ保存。スキップしない |
| D11 | BASE対応の追加 | 対応プラットフォームに **BASE を追加**し、インポートと、APIが許す範囲（商品・カテゴリ・在庫のみ）のエクスポートを実装する。詳細は `04-plan-base.md`。~~Phase 3（MakeShopの次）でインポート、Phase 4でエクスポート、v1.0公開はBASE込み（0基盤→1カラーミー→2MakeShop→3BASE→4エクスポート→5公開）~~ → **提供時期は D18 で改訂**: v1.0 には含めず **v2.0（Phase 4〜5）**で提供 |
| D12 | BASEの顧客移行方式 | BASEには顧客一覧APIが無いため、**受注インポート時に購入者情報からemail名寄せで顧客を生成**（オプション、デフォルトON。初回作成のみで上書きしない）。単独の顧客エンティティとしてはUIに出さない（`canFetchCustomers: false`） |
| D13 | 有効期限付きトークン対応 | BASEのアクセストークン1時間+リフレッシュトークン30日ローテーションに対応するため、**TokenStoreはPhase 0から構造化ペイロード（access/refresh/expires_at）+リフレッシュ排他ロックを前提に設計**する（§4参照。カラーミー/MakeShopは単一トークンとして同構造に格納） |
| D14 | ビジネスモデル | 無料版=挙動確認用（**dry-runは全量無料**+実移行はサンプルのみ）。Pro版=買切り**「移行プロジェクトライセンス」**: サイト数無制限・**初回アクティベーションから3ヶ月**のアップデート&サポート・認証済みサイトは期限後も永続動作（新規サイト認証と更新のみ不可）・価格 ¥19,800 前後・自社サイト直販（**WooCommerce API Manager**）・返金保証なし（無料版で事前検証可能なことを明記）。**継続同期（Pro同期）は販売しない**。詳細は §10.1 |
| D15 | 無料版の実行上限 | **最新受注10件起点のサンプル移行**: サンプル受注に紐づく商品（ハードキャップ50件）・顧客（最大10件）・受注10件のみ実インポート/エクスポート可。カテゴリ/タグは全量無料。上限はサーバーサイド（JobManager）で強制し、`cbjp/limits/{entity}` フィルター（総称表記: `cbjp/limits/*`）でPro版が解除。詳細は §10.2 |
| D16 | Pro本移行時の重複防止 | mappings による冪等 upsert + 本移行はカーソル先頭から全走査。取込済みデータの扱いは**開始時に選択式（更新/スキップ、デフォルト更新）**（**v1.0 では選択式を実装しない**: checksum が変わった分だけ更新・同じならスキップ〔2026-10-05 決定、R3-1〕。§10.3）。mappings欠損時の**リンク再構築ツール**（SKU/email/注文番号突合）と**サンプルクリーンアップツール**を提供。詳細は §10.3 |
| D17 | 付帯機能 | dry-runレポートCSVダウンロード / 移行後検証レポート（件数・金額突合）/ 301リダイレクトCSV（Pro）/ エクスポート実行前の本番書込み警告 を実装する。期限切れ後の再購入導線（リピート割引等）は**実装しない**。詳細は §10.4 |
| D18 | リリース計画の改訂（1ASPずつ公開） | **v1.0はカラーミーショップのみ**（インポート＋エクスポート）で公開し、**v2.0でBASE**、**v3.0でMakeShop**を追加する（各バージョンでインポート＋エクスポートを揃える）。D11のフェーズ構成と「v1.0公開はBASE込み」は本決定で置き換え、MakeShop/BASEの順序も入れ替える（新フェーズ構成: 0基盤→1カラーミーインポート→2カラーミーエクスポート→3 v1.0公開→4 BASEインポート→5 BASEエクスポート+v2.0公開→6 MakeShopインポート→7 MakeShopエクスポート+v3.0公開）。3ASP対応を前提に設計・実装済みのアーキテクチャ（PlatformAdapter・Canonical・Capabilities・TokenStoreのリフレッシュ構造=D13・HttpClientのレート制限判定フック・`canFetchCustomers` 等）は**そのまま維持し削除しない**。v2.0以降は、プラットフォーム固有のコードをアダプタ外に書かない（アーキテクチャ原則1）ことを維持しつつ、プラットフォーム非依存のコア拡張点（例: 受注インポート時に抽出した顧客をImporterが永続化するフック=B4-5、レート制限超過時の再試行遅延をアダプタ側から指定できるJobManagerの拡張点=E5-1）の追加は許容し、Importer/Exporter本体にプラットフォーム固有の分岐を持ち込まないことを検証観点とする（旧計画でMakeShopが担っていた観点はBASEへ）。v1.0 完了前に Phase 4 以降へ着手しない。フェーズ再編・タスクID採番は `10-tasks.md` 冒頭を参照 |
| D19 | マッピング候補一覧の取得方式（E2-1） | `PlatformAdapter`（§2「確定版」）に `mappingCandidates(): array` を追加する。`/settings/mappings/{platform}` のマッピングUI（カテゴリ/決済/配送/注文ステータス）が選択肢を動的に描画するための自己記述スキーマで、既存の `connectionFields()` と同じ設計思想。外部アドオンによるカスタムアダプタ実装は現時点で存在しないため、確定版インターフェースへの追加による後方互換リスクは低いと判断した（該当メソッドが無いカスタムアダプタは致命的エラーになるため、将来外部アダプタが増えた場合はこの追加を周知する）。あわせて `cbjp_settings_{platform}` に `category_map`（キー: Woo側カテゴリID、値: ASP側カテゴリID）を追加。カラーミーがカテゴリ作成不可なため、既存の `payment_map`/`shipping_map`/`status_map`（ASP側ID→Woo側ID）とは向きが逆になる。**E2-2/E2-3への申し送り**: `payment_map`/`shipping_map`はASP→Wooの単射とは限らない（複数のASP決済/配送方法が同じWooゲートウェイ/配送方法へ寄せられうる）ため、エクスポート時にWoo側の値からASP側の値へ機械的に逆引きすることはできない。E2-3の`push_order`実装時にこの逆引きの曖昧性をどう解決するか（例: 最初に一致した1件を使う、複数一致時は警告付きでフェイルクローズする等）を設計すること |
| D20 | `PlatformAdapter` の外部互換ポリシー（issue #49） | D19が「将来外部アダプタが増えた場合に周知する」としていた宿題を、v1.0公開前の今のうちに確定する。**外部（Pro版・サードパーティ）実装は `PlatformAdapter` を直接 implements せず、新設の `AbstractPlatformAdapter`（§2）を継承する**。v1.0.0公開までは（本決定を含め）インターフェースへの追加・シグネチャ変更を許容する（D19、`push_order`のシグネチャ変更=#45、`fetch_order_by_remote_id()`追加=#46 の前例を踏襲）。**v1.0.0公開後は既存メソッドのシグネチャを変更しない**。新しいメソッドは `AbstractPlatformAdapter` に既定実装（原則 `UnsupportedOperationException`）を同時に追加する形でのみ足す。この2点を `tests/unit/Adapters/AbstractPlatformAdapterTest`（リフレクションで抽象メソッド一覧とシグネチャをBASELINE定数と照合する契約テスト）でCI上強制する。詳細は §2「外部互換ポリシー」。不採用: 機能ごとの任意インターフェースへの分割（前例が無く`instanceof`分岐が増える。PR #48でCodex/Copilotが提案）。PR #48の該当2スレッド（`fetch_order_by_remote_id()`追加への指摘）はこの決定を根拠に解決する |
| D21 | エクスポートの重複作成防止（review-backlog `e2-3-push-*/G1-duplicate-on-retry`） | ColorMe への作成が確定した後で処理が途切れて mapping が残らず、次回 export が再作成して重複する問題を、途切れ方で2つに分けて対処する。**A**: remote_id が分かっている「作成後の中断」は、新設 `PartialPushException` で remote_id を `Exporter` まで運び checksum=null で mapping を書く（次回は PUT）。**B**: 作成結果が不明な場合（POST のタイムアウト・5xx・id 欠損・プロセス停止）は、送信前に `cbjp_push_intents` へ印を書き、送信しなかった／拒否が確定した場合だけ消す。印が残る実体は自動では再送せず、店舗が ColorMe を確認して「作成されていなかった」か「作成済み（ID を紐付け）」で解除する。B は受注だけでなく作成を伴う全エンティティに適用する。送信前検索（案C）は採らない。`PlatformAdapter` のシグネチャは変えない（D20 の凍結とは独立）。詳細は §10.2「エクスポートの重複作成防止（D21）」 |
| D22 | 在庫管理が混在する variable 商品のエクスポート（issue #52） | Woo でバリエーションごとに在庫管理の有無が違う商品は、商品単位の在庫管理しか持たない ColorMe では表現できず、管理外のバリエーションが売り切れ表示のまま戻らない。**混在した商品（とその在庫行）はエクスポートを止めて警告**し、Woo 側で揃えてもらう。仮の在庫数を送る案・商品全体を管理外で送る案は過剰販売につながるため採らない。判定は Reader（Woo の事実）、止めるかは `Capabilities::$supports_per_variant_stock_management`（末尾に既定 `false` で追加）で `Exporter` が決める。詳細は §10.2「在庫管理が混在する variable 商品のエクスポート（D22）」 |
| D23 | 「Any」バリエーションのエクスポート | Woo の「Any（すべての）」バリエーションは ColorMe に相当する仕組みが無く、正規化モデルでも表現できない。**v1.0 では非対応**とし、Any を含む商品と、その明細を持つ受注のエクスポートを止めて警告する（プラットフォーム非依存の blocking）。全組み合わせへの展開は v1.x で要望を見て検討する。詳細は §10.2「「Any（すべての）」バリエーションのエクスポート（D23）」 |
| D24 | プレミアムプラン限定機能のベータ扱い | プレミアムプランの ColorMe テストショップをすぐに用意できないため、プレミアム限定 API に依存する**受注のエクスポートと商品画像のアップロードを v1.0 ではベータ版**とし、実テストは行わない（R3-1 の対象外）。Export タブと readme で「Beta」と明示し、**どちらも既定オフ**（店舗が明示的に選んだときだけ動かす）にする。仕組みは `Capabilities::$beta_features`（末尾に既定 `[]` で追加）。詳細は §10.2「プレミアムプラン限定機能のベータ扱い（D24）」 |
| D25 | 往復（取り込んだ実体の再エクスポート・エクスポートで作った実体の再取込み）の扱い（R3-1、issue #98） | R3-1 のリハーサルで、ColorMe から取り込んだ実体を同じショップへエクスポートすると ColorMe の値が書き換わる（空の型番に仮 SKU、**会員限定販売が全員に販売可能**、在庫未設定が 0、埋め込み動画の消失ほか）こと、逆向きの再取込みが Woo で作った実体を ColorMe 由来の値で上書きすることを実データで確認した（`docs/reviews/feat/r3-1-e2e-rehearsal/rehearsal.md`）。**実体は作られた向きにだけ更新する**: 取り込んだ商品・顧客・在庫はエクスポートしない（スキップ＋情報の警告）、エクスポートで作った実体は取込みで上書きせず紐づけだけを保つ。出自は Woo 側の印で判定し、スキーマは変えない。~~ただし `_cbjp_platform`＋`_cbjp_remote_id` だけでは足りない…ので、「取込みで作った」ことを示す印を実装の計画で決める~~ → **R3-1a の計画で決定（2026-10-05）: 印は新設せず `_cbjp_platform` を「取込みで結ばれた」印に使い、「誰が作ったか」ではなく「誰が紐づけたか」で判定する**（メールで採用した既存の Woo 顧客は取込み側＝エクスポートしない。顧客は `_cbjp_created_by_import` の一致も取込み側。export で作った実体を再取込みすると印が付く問題は、取込みが書かなくなるので以後は起きない）。対象はエクスポートの Reader があるエンティティすべて（商品・顧客・在庫に加えて受注・クーポン）。エクスポートのサンプル選定から取込み品を除き、WC の商品複製ではリンクのメタを写さない。詳細と、往復を想定しないため対応しないものは §10.2「往復の扱い（D25）」。**往復は想定しない（2026-10-06 ユーザー決定）**: このプラグインは一方向の移行に特化する。D25 は誤って往復したときに値を壊さないための安全策で、往復の運用を支える機能ではない。D14（継続同期は販売しない）と整合する。実装は issue #98（v1.0 に含める） |
| D26 | 軽減税率・標準税率の税区分の見分け方（issue #102、R3-1e） | WooCommerce は既定の税区分をインストール時の言語で翻訳した名前から作るため（日本語は「軽減税」「免税」。スラッグは URL エンコード）、スラッグ `reduced-rate` の決め打ちでは日本語でインストールした店舗の軽減税率を見失い、取込みで 8% の商品が 10% の税区分に入る。**税率から自動判定することを優先する**: JP の税率が `shop.reduce_tax_rate`（8%）の税区分を軽減税率、`shop.tax`（10%）を標準税率とみなす。**必要な税率が Woo に設定されていない場合は、dry-run で先に税率を作るよう促す**（警告の文言・本実行を止めるか・候補が複数あるときの扱いは R3-1e の計画で決める）。R3-1d（issue #78。標準・軽減以外の税区分のエクスポートを止める）はこの判定を前提にする |

## 2. PlatformAdapter インターフェース（確定版）

`00-plan-overview.md` §3.2 を D5 に基づき拡張。

```php
namespace CartBridgeJP\Adapters;

interface PlatformAdapter {
    public function id(): string;                     // 'colorme' | 'makeshop' | 'base'
    public function label(): string;
    public function capabilities(): Capabilities;
    public function testConnection(): ConnectionResult;

    // 接続設定スキーマの宣言（UIが動的にフォーム生成。例: makeshopはendpoint+token）
    public function connectionFields(): array;        // ConnectionField[]

    // `/settings/mappings/{platform}` UI向けのASP側マッピング候補一覧（D19。connectionFields()と
    // 同じ「自己記述スキーマをUIが消費する」設計）。キーは category/payment/shipping/status。
    // 該当エンティティ・機能を持たないプラットフォームはキー省略・空配列可。
    public function mappingCandidates(): array;        // array<string, array<{id,name}>>

    // 取得（カーソルベースで再開可能）
    public function fetchProducts( Cursor $cursor ): Page;   // Page<CanonicalProduct>
    public function fetchCategories(): array;                // CanonicalCategory[]
    public function fetchTags(): array;                      // CanonicalTag[]（colorme: groups）
    public function fetchCustomers( Cursor $cursor ): Page;  // Page<CanonicalCustomer>
    public function fetchOrders( Cursor $cursor ): Page;     // Page<CanonicalOrder>
    public function fetchStocks( Cursor $cursor ): Page;     // Page<CanonicalStock>
    public function fetchCoupons( Cursor $cursor ): Page;    // Page<CanonicalCoupon>
    public function fetchReviews( Cursor $cursor ): Page;    // Page<CanonicalReview>（makeshopのみ）

    // 無料版サンプル選定・ID指定取得（D15。詳細は§10.2。API対応可否は要検証#14/#15）
    public function fetchLatestOrders( int $limit ): array;                          // CanonicalOrder[]（新しい順）
    public function fetchProductByRemoteId( string $remoteId ): ?CanonicalProduct;   // 404はnull
    public function fetchCustomerByRemoteId( string $remoteId ): ?CanonicalCustomer; // base: UnsupportedOperationException（D12）
    public function fetchOrderByRemoteId( string $remoteId ): ?CanonicalOrder;       // 404はnull。県コード修復（issue #46）で追加。ID指定の単一取得は日付窓（colorme: 直近7日）の影響を受けない

    // 書き込み（capabilityで不可のものは UnsupportedOperationException）
    public function pushProduct( CanonicalProduct $p, ?string $remoteId ): PushResult;
    public function pushCategory( CanonicalCategory $c ): PushResult;
    public function pushCustomer( CanonicalCustomer $c, ?string $remoteId ): PushResult;
    public function pushOrder( CanonicalOrder $o ): PushResult;
    public function pushStock( CanonicalStock $s ): PushResult;
    public function pushCoupon( CanonicalCoupon $c, ?string $remoteId ): PushResult;
}
```

### 外部互換ポリシー（D20）

`PlatformAdapter` はメソッドを足すたびに、これを直接 `implements` する外部実装を読み込み時点で fatal に
しうる（PHPは抽象メソッドの欠けたクラスをロードした時点でエラーにする）。issue #49 で以下を確定した:

1. **外部（Pro版・サードパーティ）アダプタは `PlatformAdapter` を直接 `implements` せず、
   `AbstractPlatformAdapter`（`includes/Adapters/AbstractPlatformAdapter.php`。本体は空の抽象クラス）
   を継承する。** 直接 `implements` する実装は本ポリシーの互換保証の対象外
2. **v1.0.0 公開前**: 従来どおりインターフェースへの追加・シグネチャ変更を許容する（D19、`push_order`の
   シグネチャ変更=#45、`fetch_order_by_remote_id()`追加=#46 の前例）。変更したら
   `AbstractPlatformAdapterTest::BASELINE` を更新する
3. **v1.0.0 公開後**（BASELINEの既存エントリは凍結）: 既存メソッドのシグネチャ（引数・型・戻り値型）を
   変更しない。変更が要る場合は新しいメソッドを足す。新しいメソッドは `AbstractPlatformAdapter` に
   既定実装を、`AbstractPlatformAdapterTest::BASELINE` にシグネチャを**同時に**追加する
   （どちらか一方だけでは互換性を保証できない。規則5参照）。既定実装は原則 `UnsupportedOperationException`
   を投げる。ただし `null`・空配列
   など「正常な結果」と区別できない値を既定にはしない（アーキテクチャ原則9。例: ID指定取得の`null`は
   「404（存在しない）」を意味するため、既定値にすると外部アダプタ側の「未実装」を「存在しない」と
   誤認させる）。呼び出し側は`UnsupportedOperationException`を捕捉してスキップ/フェイルクローズする
   （既存の`fetch_reviews()`等と同じ扱い）
4. **アダプタが組み立てる値オブジェクト**（`Capabilities`/`PushResult`/`ConnectionResult`/
   `ConnectionField`/`Page`/`Cursor`）: 新しい引数は末尾に既定値付きで追加する。`Capabilities`の新フラグの
   既定値は安全側（`false`）にする。`Canonical*`モデルの「`extras`より後ろに追加」規則（原則8）と同じ扱い
5. 上記2・3を `tests/unit/Adapters/AbstractPlatformAdapterTest` が2本のテストで強制する:
   `test_interface_signatures_match_v1_baseline`は`PlatformAdapter`インターフェース自体をリフレクションし、
   **現在の全メソッド一覧・シグネチャ（引数・型・参照渡し・可変長引数に加え`static`修飾子・戻り値の
   参照渡しも含む）が`BASELINE`定数と完全一致するか**を確認する（`AbstractPlatformAdapter`側で後から
   既定実装を持つようになったメソッドでも、インターフェース宣言自体の変更は検出する）。`BASELINE`は
   常にインターフェースの全メソッドを1対1で記録する設計にしており、新しいメソッドを`BASELINE`へ
   追記し忘れた場合もこのテストの不一致として検出する（追記を「任意」にすると、そのメソッドの
   シグネチャがどのテストでも一切凍結されないまま変更できてしまう。本ポリシー導入PRのG2でCodexが指摘）。
   `test_new_methods_have_default_implementations`は、`v1_method_names()`に無いメソッドが
   `AbstractPlatformAdapter`で既定実装を持たないまま（＝抽象のまま）追加されていないかを確認する。
   `v1_method_names()`は**v1.0.0公開前の今は`BASELINE`のキーと連動して動く**（D20が公開前のメソッド
   追加に既定実装を要求しないため。固定リストにすると公開前の正当な追加まで失敗する。本ポリシー導入PR
   のG3でCopilotが指摘）。**v1.0.0公開時（R3-4）に必ずこの実装をその時点のメソッド名のリテラル配列へ
   書き換えて固定すること**。固定しないと、公開後に追加されたメソッドの既定実装が（シグネチャは変えずに）
   削除される変更を検出できなくなる（同PRのG1でCodexが指摘）。固定した後は`BASELINE`のキーをそのまま
   使わないため、`BASELINE`にだけ追記して既定実装を削除する変更も検出し続けられる。ただし`BASELINE`や
   固定後の`v1_method_names()`自体の書き換えはテストでは防げない（公開後に既存エントリを書き換えて
   テストを通してしまうことは技術的に可能なため、レビューで検出する運用が前提）
6. 不採用案: 機能ごとの任意インターフェースへの分割（例: 受注の単一取得だけを別インターフェースにし、
   呼び出し側が`instanceof`で分岐する）。前例が無く分岐が増え、#38（受注のID指定取得）のように結局
   `PlatformAdapter`へ統合したくなる見込みのため（PR #48でCodex/Copilotが提案した代替案）
7. `docs/review-backlog.md`には`PlatformAdapter`の契約拡張が前提の保留項目がある
   （`e2-3-push-{product,customer,order}/G1-duplicate-on-retry`＝`PushResult`への部分成功remote_id
   伝搬、`fix-46-pref-state-repair/L-unavailable-not-split`＝`fetch_*_by_remote_id()`の404/変換不能の
   区別）。前者は`PushResult`への末尾追加（規則4）で対応できる見込みだが、後者は既存メソッドの戻り値の
   意味論変更が要る可能性がある。**R3-4（公開）前にこれらの対応要否を判断すること**（凍結後は新メソッド
   追加でしか解決できなくなる）

**PR #48の申し送り**: `fetch_order_by_remote_id()`追加（#46）に対するCodex/Copilotの指摘
（[Codex](https://github.com/artisanworkshop/cart-bridge-jp/pull/48#discussion_r4057015250) /
[Copilot](https://github.com/artisanworkshop/cart-bridge-jp/pull/48#discussion_r4057158034)）は、
本D20の確定をもって解決したものとして扱う（該当スレッドは本ポリシー導入のPRで返信・Resolveする）。

### Capabilities（readonly値オブジェクト）

```php
final class Capabilities {
    public function __construct(
        public readonly bool $canCreateCategory,
        public readonly bool $canCreateOrder,     // base: false（注文作成APIなし）
        public readonly bool $canFetchCustomers,  // colorme/makeshop: true / base: false（受注から抽出=D12）
        public readonly bool $canUpdateCustomer,
        public readonly bool $canPushImages,      // 要検証#1/#4の結果で確定。base: true（URL指定方式）
                                                   // colorme: 接続先ショップのcontract_plan（shop.json）を見てプラン依存で算出（§9 #1）
        public readonly bool $canCreateCoupon,
        public readonly bool $hasCoupons,         // colorme: true（読取のみ）/ makeshop: true / base: false
        public readonly bool $hasTags,            // colorme: true（groups）/ makeshop: false / base: false
        public readonly bool $hasReviews,         // colorme: false / makeshop: true / base: false
        public readonly bool $hasVariants,        // base: true（ただし1軸のみ）
        public readonly int  $rateLimitPerMinute,
    ) {}
}
```

UI・JobManager は capability が false のエンティティを選択肢から除外する。アダプタ側は
非対応メソッドで `UnsupportedOperationException` を投げる（防御の二重化）。

### 値オブジェクト仕様

- **`Cursor`**: 不透明なペイロード `array<string,mixed>`（colorme: `['offset' => int]`、makeshop: ページング仕様確定後に定義、base: `['offset' => int]`（limit最大100）。`toJson()/fromJson()` で `cbjp_jobs.cursor_json` に永続化。初回は `Cursor::start()`。
- **`Page`**: `items: array`（Canonical配列）、`nextCursor: ?Cursor`（null = 終端）、`total: ?int`（取得可能な場合のみ。進捗率表示用）。
- **`PushResult`**: `remoteId: string`、`operation: 'created'|'updated'|'skipped'`、`warnings: string[]`。
- **`ConnectionResult`**: `ok: bool`、`shopName: ?string`、`message: ?string`（失敗理由。トークン等の機密を含めない）。
- **`ConnectionField`**: `key, label, type('text'|'password'|'oauth_button'), required, help`。

### Canonical追加モデル

- `CanonicalTag`（id, name）
- `CanonicalCoupon`（code, type('fixed'|'percent'), amount, minAmount, expiresAt, usageLimit, extras,
  freeShipping, usageLimitPerUser, hasUnsupportedRestrictions）
  - `hasUnsupportedRestrictions`（`?bool`）はASP側の利用制限のうち、現在の変換経路ではWooのクーポン設定へ
    **写せない**ものが残っているかの正規化フィールド。Woo自身は商品・カテゴリ・メールアドレスの制限軸を
    ネイティブに持つ（`WC_Coupon::set_product_ids()` 等）ため「ASPに制限がある」と「写せない」は同義ではない。
    ただし `CanonicalCoupon` にそれらを運ぶフィールドが無く `CouponWriter` も該当setterを呼ばないため、
    **v1.0 時点で `false` にしてよいのは「ASP側に制限が無い」場合のみ**（変換経路を実装した分だけ範囲を広げる）。
    ASP固有のキー名・enum値と写せるかの判定はアダプタしか持たないため判定は各Transformerが行い、
    `Woo\Writer\CouponWriter` はこのフィールドだけを見て保存を見送る（アーキテクチャ原則1）。
    `null`＝アダプタが宣言していない（不明）も保存しない側に倒す
    （原則9。楽観的デフォルトによるフェイルクローズ回避を防ぐ）。issue #15
- `CanonicalReview`（productRef, authorName, rating, title, content, createdAt, extras）

## 3. DBスキーマ（DDL確定版）

dbDelta 互換で `Core\Activator` が作成。スキーマバージョンを `cbjp_db_version` オプションに保存し、
将来のマイグレーションは Activator でバージョン比較して実行。

```sql
CREATE TABLE {$prefix}cbjp_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id CHAR(36) NOT NULL,                 -- 1回の移行実行（複数エンティティ）を束ねるUUID
  type VARCHAR(20) NOT NULL,                -- 'import' | 'export' | 'dry_run'
  platform VARCHAR(20) NOT NULL,            -- 'colorme' | 'makeshop' | 'base'
  entity VARCHAR(20) NOT NULL,              -- 'product'|'category'|'tag'|'customer'|'order'|'stock'|'coupon'|'review'
  status VARCHAR(20) NOT NULL DEFAULT 'pending',  -- 下記ステートマシン参照
  cursor_json TEXT NULL,
  totals_json TEXT NULL,                    -- {total,processed,created,updated,skipped,unchanged,warned,failed,remote_amount}（unchanged は skipped の内訳。§10.3「アップセル表示」）
  error_json TEXT NULL,                     -- 失敗時の最終エラー {code,message}（個人情報禁止）
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY run_id (run_id),
  KEY status_platform (status, platform)
);

CREATE TABLE {$prefix}cbjp_mappings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  platform VARCHAR(20) NOT NULL,
  entity_type VARCHAR(20) NOT NULL,
  remote_id VARCHAR(191) NOT NULL,
  local_id BIGINT UNSIGNED NOT NULL,
  checksum CHAR(64) NULL,                   -- Canonical正規化JSONのsha256。差分検出用
  synced_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY platform_entity_remote (platform, entity_type, remote_id),
  KEY platform_entity_local (platform, entity_type, local_id)
);

CREATE TABLE {$prefix}cbjp_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id BIGINT UNSIGNED NULL,
  level VARCHAR(10) NOT NULL,               -- 'debug'|'info'|'warning'|'error'
  message TEXT NOT NULL,
  context_json TEXT NULL,                   -- IDのみ。個人情報・トークン禁止
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY job_level (job_id, level),
  KEY created_at (created_at)
);
```

- `00-plan-overview.md` §3.5 からの変更点: `direction` 列を廃止（`type` で判別可能）、
  `run_id`・`entity` 列を追加、`cursor`→`cursor_json`（CURSORはMySQL予約語）。
- ログ保持: 日次cron（Action Scheduler）で30日超を削除。日数は `cbjp/logs/retention_days` フィルターで変更可。
- 接続情報・マッピング設定はオプションテーブル（`cbjp_settings_{platform}`、autoload無効）。トークンのみ TokenStore（§4）。

### ジョブのステートマシン

```
pending → running → completed
                  → failed      （リトライ上限到達。UIから retry で pending に戻せる）
                  → cancelled   （ユーザー操作）
running ⇄ paused                （レート制限長期化・ユーザー操作時）
```

1回の移行実行（run）はエンティティ順序 `category → tag → product → customer → order → stock → coupon → review`
で per-entity のジョブを直列実行（依存関係: 商品はカテゴリに、受注は商品・顧客に依存するため）。

## 4. Support層 設計

### TokenStore

- 暗号化: `sodium_crypto_secretbox`（PHP 7.2+ 標準バンドルのため fallback 不要。念のため activation 時に `function_exists('sodium_crypto_secretbox')` を検査し、無ければ管理画面通知）
- 鍵導出: `sodium_crypto_generichash( AUTH_KEY . AUTH_SALT, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES )`
- nonce は保存ごとにランダム生成し `base64( nonce . ciphertext )` をオプション `cbjp_token_{platform}`（autoload無効）に保存
- **保存単位は構造化ペイロード（D13）**: `{access_token, refresh_token?, expires_at?, extras?}` のJSONを暗号化。
  カラーミー（無期限）/ MakeShop（永続）は `refresh_token`/`expires_at` なしで同構造に格納
- **リフレッシュ排他ロック**: 有効期限付きトークン（BASE）の更新は `$wpdb` の原子的UPDATE（GET_LOCKまたはオプションCAS）で排他し、
  ローテーション式refresh_tokenの二重更新による失効を防ぐ。更新後は新しいaccess/refresh両方を即時上書き保存
- `AUTH_KEY` 変更等で復号失敗した場合、およびリフレッシュトークン失効（BASE: 30日超の放置）の場合は例外にせず「再接続が必要」状態を返し、UIで再接続を促す
- 画面表示は末尾4文字のみ（`****abcd`）

### HttpClient

- `wp_remote_request` ラッパー。タイムアウト30秒、`User-Agent: CartBridgeJP/{ver}`
- リトライ: 429/5xx/接続タイムアウトで指数バックオフ+ジッター（1s→2s→4s、最大3回）。`Retry-After` ヘッダーがあれば優先
- 4xx（429以外）はリトライせず `ApiException`（platform固有のエラー配列→メッセージ変換はアダプタ側Client担当）
- **例外**: BASEはレート制限超過を **HTTP 400** + エラーコード `hour_api_limit`/`day_api_limit` で返すため、
  「このレスポンスはレート制限か」の判定をアダプタ側Clientがフックできる拡張ポイント（コールバックまたはoverride）を設ける
- 全リクエストは呼び出し前に RateLimiter の許可を取る

### RateLimiter

- トークンバケット方式。プラットフォームごとに `capacity = rateLimitPerMinute`、毎分補充
- 状態はオプションに保存し、`$wpdb` の原子的UPDATEで競合回避（Action Schedulerの並列実行対策）
- 枯渇時は `wait()` でスリープ（Action Scheduler内なので許容）。長時間枯渇はジョブを `paused` にして次のスケジュールへ

### Logger

- `cbjp_logs` テーブルへの書き込み + `WC_Logger` へのミラー（source: `cart-bridge-jp`）
- context には エンティティ種別・remote_id・local_id のみ。氏名・メール・住所・トークンの記録を**コードレビュー観点として禁止**

## 5. ジョブ実行（Sync層）設計

- `JobManager::startRun( type, platform, entities[] )` → run_id 発行、per-entity ジョブ作成、先頭を Action Scheduler にエンキュー
- 1回のASアクション = 1ページ処理（fetch → 変換 → Woo書き込み → mappings upsert → cursor更新）。処理後に自分を再エンキュー（`as_enqueue_async_action`）。終端で次エンティティのジョブを起動
- ページサイズ初期値50（アダプタが上書き可）。1アクションはPHPのmax_execution_time内に収まる粒度を保つ
- **dry-run**: 同一パイプラインで Woo書き込みだけを `DryRunReporter` に差し替え。件数・警告（未マッピング決済方法、SKU重複等）を totals_json に集計し、UIでプレビュー表示
- 冪等性: mappings の UNIQUE キーで upsert。checksum 一致ならスキップ（totals.skipped++。内訳として totals.unchanged++。issue #55）
- 同時実行: 同一 platform で進行中（pending/running/paused）のジョブ、またはページを処理中の Action Scheduler アクションがある場合は新規開始を拒否（レート制限保護。判定とロックは下の「同時実行のロックと条件付きの状態遷移」）。
  `retry()`（失敗ジョブの再開）も同様に、対象ジョブとは異なる run が同一 platform で進行中なら拒否する
  （issue #54）。ただし判定は `run_id` を除外して行い、対象ジョブと同一 run 内でまだ未処理な
  兄弟ジョブ（`start_run()` は run 開始時に全エンティティのジョブを先に `pending` で作るため、
  1件が `failed` になっても他は `pending` のまま残りうる）を「進行中の別 run」と誤検知しない。
  拒否の 409 `cbjp_run_in_progress` は進行中の run（`active_runs`）を返し、管理画面はそれを表示・キャンセルできる
  （R3-0i・issue #70。§6「進行中 run の発見」）。判定から状態変更までは下の「同時実行のロック」で囲む（R3-0i・issue #57）

### 同時実行のロックと条件付きの状態遷移（R3-0i・issue #57）

**問題**: 同時実行の判定（`has_active_job_for_platform()`）は SELECT で、呼び出し元が別の SQL で状態を変える（check-then-act）。ミリ秒単位で同時に届いた要求同士
（同じ失敗ジョブへの二重 Retry、`retry(A)` と `start_run(B)`、`start_run` 同士、`start_run` とサンプルクリーンアップ等のツール）は、互いの変更前の状態を見て両方とも判定を
通る（同一 ASP への重複書込み・重複作成）。また `cancel_run()` で `cancelled` にしたジョブを、Action Scheduler（AS）で処理中の `process_job()` が後から
`completed`/`paused`/`failed`/`running` で無条件に上書きし、キャンセルした run が復活していた（`f1-6-import-ui/R1-X1`）。

**判定**（`JobRepository::is_platform_busy( $platform, ?$exclude_run_id )`）: 進行中（pending/running/paused）のジョブ（除外 run つき）**または**、そのプラットフォームのジョブの
ページを処理中（`in-progress`）の AS アクション（`has_in_flight_job_for_platform()`。hook `cbjp_process_job`・group `cart-bridge-jp`、ジョブの状態は問わない）。
キャンセルしたジョブも処理中のページは最後まで書く（`process_job()` は割り込めない）ため、`cancelled` になった瞬間に判定を素通りすると、書き終わるまでの間に新しい run・
ツールが重なる（2026-10-03 決定で判定に含めた）。処理中のアクションの判定には run の除外を適用しない。
異常終了したアクションの `in-progress` は、AS のキューランナー（WP-Cron・管理画面の非同期リクエスト・WP-CLI）が次に動いたときに、既定 5 分
（`10 × action_scheduler_queue_runner_time_limit`。`action_scheduler_failure_period` で変わり、負の値なら無効）を超えたものから失敗扱いになって判定から外れる。
したがって、キャンセルの後にワーカーが強制終了されると、`active_runs` が空のまま「少し待って再試行」の 409 が少なくとも 5 分続く（キャンセルでは解けない）。
逆に 1 ページの処理がその時間を超えると、書いている途中で AS が失敗扱いにして判定から外れる（ページはその時間に収まる粒度の前提。§5 冒頭）。

**ロック**（`Support\PlatformLock`）: core の `WP_Upgrader::create_lock()` と同じ「options への一意な `INSERT IGNORE`」（`GET_LOCK()` は Galera・一部の DB プロキシで
期待どおり動かないため不採用。2026-09-26 決定）。option 名 `cbjp_platform_lock_` . md5( platform )（アダプタのキーは外部コードが決めるため長さを保証できない）、
値 `"{期限の UNIX 時刻}|{UUID}"`、autoload `no`。core との違い:
- 取得時の値そのものをハンドルにし、解放は値の一致で消す（期限切れで他者が取り直したロックを、遅れて終わった元の保持者が消さない）。
- 期限切れの回収は値を比べて上書きする CAS（`TokenStore::acquire_refresh_lock()` と同じ。core の無条件 delete は同時に回収した 2 者が両方取得しうる）。
  読めない値・期限が「最長 TTL＋時計のずれの余裕（300 秒）」より先の値（壊れた値・時計の巻き戻り）も回収する（保持中とみなすと誰にも解放されずプラットフォームを塞ぐ）。
  余裕が無いと、判定する側の `time()` が保持する側より遅れたとき（秒境界をまたぐ・Web ノード間の時計のずれ）に、取得したばかりの `TTL_LONG` のロックを壊れた値とみなして奪う（R1-1）。
- 値は object cache を通さずに読む。`wpdb::get_var()` は空文字列を null に変えて「行が無い」と区別できないため `get_row()` で読む。
- 期限は区間ごと: run の開始・Retry・エクスポート設定の保存は `TTL_SHORT`（60 秒）、ツールの 1 バッチ・push intent の解除は `TTL_LONG`（3,600 秒＝core の upgrader の既定と同じ）。
  期限は区間が最も長引く場合から決めた（PR #96 G1-1・G2-1）: HTTP 1 本は最悪約 540 秒（`HttpClient` の試行 4 回 × [レート制限の待ち 60 秒＋タイムアウト 30 秒]＋`Retry-After` の待ち 60 秒 × 3）、
  照会 1 件で HTTP は最大 3 本（ColorMe の受注は `sales/{id}` に加え、変換器の初回に `payments.json`・`deliveries.json`。商品は初回に `shop.json` を足して 2 本、顧客は 1 本）。照会回数の上限（20 件）だけでは
  県コード修復の 1 バッチが期限を超え、書込みの途中で別の要求にロックを回収されうるため、県コード修復は 120 秒を過ぎたら新しい行に取りかからず cursor を返す
  （`PrefStateRepair::TIME_BUDGET_SECONDS`。再開は既存の cursor で冪等）。1 バッチは最長 120＋1,620 秒、push intent の解除は照会 1 件（最長 1,620 秒）、クリーンアップ・再構築は ASP を呼ばない。
  この見積もりの前提（`HttpClient`・`RateLimiter` の上限）は `PlatformLockTest` が固定する（上限を変えると落ちる）。照会の遅い外部アダプタは見積もりの外。
  プロセスが強制終了されて残った `TTL_LONG` のロックは、最長 1 時間そのプラットフォームの開始・ツールを塞ぐ（409・待って再試行の文言）。保持中のリクエストが致命的エラー・実行時間切れ・接続断で終わった場合も shutdown（`release_all()`）で解放する（`finally` はこれらで走らない）。
  期限はプロセスが強制終了されたときの安全網。再入はできない（同じリクエストで入れ子に取ると内側が取得に失敗する）。

ロックで囲むのは「判定 → 状態変更」の区間だけで、run 全体は囲まない（2026-09-26 決定）: `JobManager::start_run()`（判定〜ジョブ作成〜先頭の開始〜エンキュー）、
`retry()`（判定〜`failed→pending`〜エンキュー）、REST の `run_sample_cleanup`・`rebuild_mappings`・`repair_states`（Scan を含む。ASP を呼ぶため）・`save_export_options`・
`resolve_push_intent`（判定〜そのバッチの処理。`RestController::run_exclusively()`）。ロックを取れなければ 409 `cbjp_run_in_progress`（下記）。
接続の解除（`delete_connection`。`TTL_SHORT`）も同じく囲む（R3-0p、backlog `r3-0i-platform-lock/plan-X1` の B 案。2026-10-04 決定）。run の実行中に
トークンと client_id/secret を消すと、export は送信の失敗を 1 件ずつ skipped にして run が completed になり（何も送っていないのに「完了」）、import は次のリクエストで
処理するページの取得から失敗して後続のジョブが pending のまま残り、別のショップで認可し直すと、止まっていない run（paused 等）や Retry した run の続きがそのショップに対して走る
（cursor・`cbjp_mappings` はショップを区別しない。Action Scheduler が 1 リクエストで続けて処理するページは、メモリ上のトークンで削除の後も進む）。
Connections タブには run の進捗もキャンセルも無いため、一覧に run があるときの 409 の文言を「先にキャンセルする」案内に替える（§6）。案内先は Tools タブを先に挙げる:
Import/Export タブは接続済みのプラットフォームしか並べないが、Tools タブは未接続・要再接続でも run を並べてキャンセルできる（`ActiveRunNotice`）。要再接続（トークンを復号できない）では
資格情報の保存（`PUT /connections`）も認可 URL の取得も失敗し、切断が唯一の復旧手段なので、案内が辿れることが要る（R1-1）。
残る制限: (1) 再接続側（OAuth のコールバック・コードの貼り付け・資格情報の保存）は囲まない（C 案。コールバックはリダイレクトで、Connections タブへエラーを返す経路が要る）。
(2) run の開始時の接続先ショップと、ページごとの接続先が同じかは確かめない（D 案）。どちらも backlog `r3-0p-guard-disconnect/plan-X1-rest`。
(3) 強制終了で残ったロック（最長 1 時間）・処理中のアクション（5 分以上）の間は切断も 409 になる。トークンの漏洩などで今すぐ止めたいときは ASP 側でアプリの認可を
取り消す（プラグインの切断は ColorMe 側のトークンを取り消さない）。
`cancel_run` はロックを取らない（ツールのバッチの実行中でも止められるように。キャンセルは進行中を減らすだけ）。キャンセル済み run の失敗ジョブへの Retry は従来どおり許す
（利用者の操作として後勝ち）。クリーンアップのプレビュー（`run_in_progress`）は `is_platform_busy()` で判定するが、読むだけなのでロックは取らない。

**条件付きの状態遷移**: 状態は `JobRepository::transition( $id, $from, $to )`（今の状態が `$from` のどれかのときだけ 1 行を更新し、その成否を返す。`$to ∈ $from` は拒否:
`MYSQL_CLIENT_FLAGS` で CLIENT_FOUND_ROWS が立つと「一致」と「変更」の行数が食い違うため）で変える。`mark_failed()` は未終了のときだけ、`cancel_run()` は未終了のジョブを 1 文で。
- `start_run()` の先頭 `pending→running` が失敗したら（作成直後に一覧から見つけてキャンセルされた）エンキューせず、run の残りもキャンセルする（キャンセルがジョブの作成の途中に届くと、
  その後に作ったジョブが pending のまま残って進まない run がプラットフォームを塞ぐ。R1-2）。
- `retry()` の `failed→pending` に負けたら（同じジョブへの Retry がほぼ同時に 2 回）false を返しエンキューしない。
- `process_job()` 冒頭の `pending/paused→running`、レート制限の `running→paused`（成功したときだけ遅延エンキュー）、`complete_job_and_advance()` の `running→completed`
  （失敗＝キャンセル済みなら次へ進まない）と次のジョブの `pending→running`（既に running/paused なら二重にエンキューしない）。
- `update_progress()`（cursor・totals）は無条件のまま: キャンセルされたジョブにも、そのページで実際に書いた件数を残す。続きのページのエンキューも変えない（次のアクションは冒頭の
  終了判定で何もしない）。
- 無条件の `update_status()` はテストで任意の状態を作るためだけに残す。

**残る制限**: 同じジョブのアクションが重複した場合（AS の管理画面からの手動実行等）、`running` のジョブは冒頭の遷移で弾けず 2 本が同じページを処理しうる（重複の元だった二重 Retry・
次ジョブの二重起動は上の遷移で塞いだので、cursor の CAS は入れていない）。Mappings の PUT はサーバーで拒否しない（§6「進行中 run の発見」の残る制限のとおり）。
`DELETE /connections/{platform}` は R3-0p で囲んだ（上記）。再接続側は run 中も拒否しない（backlog `r3-0p-guard-disconnect/plan-X1-rest`）。

**実機確認（2026-10-03、wp-env・mock アダプタ `mockv`）**: `wp eval-file` を 2 プロセス同時に走らせた。`query` フィルターで `start_run()` を最初のジョブの INSERT 直前に 8 秒止め
（ロックを保持したまま）、その間にもう一方のプロセスで `start_run()` が `PlatformBusyException`、`POST /runs`・`POST /tools/sample-cleanup` が 409（一覧は空・待って再試行の文言）になり、
run は 1 本だけ作られ、終わった後にロックの行は残らなかった。続けて、そのジョブのアクションを in-progress にしてページの処理中（dry-run の行の INSERT 直前）に 8 秒止め、その間に
`POST /runs/{run}/cancel`（200）→ `POST /runs`・`POST /tools/rebuild-mappings` が 409。処理後もジョブは `cancelled` のまま（以前は `completed` で上書きされた）、アクションの完了後は
`POST /runs` が 200。期限内のロックの行では 409、期限切れの行は回収されて 200（行は解放で消える）。撤去済み。

### 受注インポートの詳細（D10）

1. 明細のSKU（無ければ remote product id → mappings）でWoo商品を解決
2. 解決できた明細: 商品リンク付き line item（ただし価格・商品名は**注文時の値**を使用）
3. 解決できない明細: 商品リンクなしのカスタム line item（注文時商品名・単価・数量）+ メタ `_cbjp_remote_product_id`。
   警告は、mappings に無い（未取込み・ASP側で削除済み）か mapping 先の商品が Woo 側で完全に削除済みなら `order_line_product_unresolved`、
   実在する variable 商品でオプション値から variation を1件に特定できないなら `order_line_variation_unmatched`（ゴミ箱の商品は `wc_get_product()` が
   返すので「実在する」側に入る）
   （R3-0n。実店舗では受注後に商品のオプションの軸が増えていた）
4. 合計・送料・手数料・割引はASP側の値をそのまま設定（Wooに再計算させない）
5. 注文メタ: `_cbjp_platform`, `_cbjp_remote_order_number`, 未マッピングの決済/配送は `_cbjp_original_payment_method` 等に元名称を保存
6. ステータスマッピングは 01/02 の表に従う。受注メール・在庫減算・ポイント付与等の副作用は全て抑止（新規は最終ステータスで1回だけ保存して状態変化フックを発火させない〈小節「受注の新規作成と状態変化フック」〉、メールは `SideEffectGuard` で止める）

**数量0の明細（R3-0n、2026-09-30 決定）**: ColorMe はキャンセルした受注の全明細と、一部を外した明細を `product_num=0`・`subtotal_price=0` にする
（単価は残る）。解決できた明細・できない明細のどちらでも、Woo 層（`Woo\Writer\OrderItemBuilder`）が受け取る数量と明細合計が数値として厳密に0なら、
数量0・金額0のまま警告なしで残す（以前は数量1に倒して `order_line_quantity_invalid` と、その副作用の `order_line_tax_inconsistent` が付いていた）。
欠損・負数・小数・「数量0だが金額あり」は従来どおり数量1＋`order_line_quantity_invalid`。文字列の0は float へ変換せず書式で判定する
（`'1e-400'` のようにアンダーフローで0になる値・指数表記・空白付きは受けない）。この判定を変換層で崩さないよう、ColorMe の変換層は
`subtotal_price` の欠損・非数値・非整数を `'0'` に丸めず null のまま運び（`Cast::exact_money_or_null()`。Woo 層が単価×数量で補う。
`money_or_null()` は小数を切り捨てるので使わない）、整数でない `product_num` は欠損と同じく受注ごと弾く（`Cast::to_exact_int_or_null()`。PR #90 G1/G2）。swagger が integer とするこの2フィールドは
float も受けない（JSON の `1e-400` は `json_decode()` の時点で `float(0)` にアンダーフローし、0と見分けられないため。G3）。エクスポート方向（`Woo\Reader\OrderReader`）は変えず、数量0の明細は引き続き
`order_line_quantity_invalid`（blocking）で止める。取り込んだ受注は元々 `order_update_not_supported` で送られないので送信内容は変わらないが、
エクスポートの dry-run では、従来 `updated`（移行できる件数）に数えていた該当受注が `skipped`（警告付き）になる

### 税の扱い

カラーミー・MakeShop・BASEとも価格は税込（BASEは `item_tax_type` で軽減税率商品を判別可能。extrasに保存）。インポート開始前に Woo の
`woocommerce_prices_include_tax` が `no` の場合は dry-run 警告に含める（自動変更はしない）。

### 受注の新規作成と状態変化フック（issue #91）

インポートで**新しく作る受注は、最初から最終ステータスで 1 回だけ保存**し、WooCommerce の状態変化（`woocommerce_order_status_*`・
`woocommerce_order_status_changed`）を発火させない。移行する過去の受注で、店舗の自動処理（請求書の PDF・メール、決済の売上確定、外部連携）が
動かないようにするため（D10 #6「副作用は全て抑止」の実装の一部）。

- 方法: `new WC_Order()` を組み立て、`set_status()` の間だけ `woocommerce_default_order_status` を最終ステータスにする（新しい受注の
  「変化前」のステータスはこのフィルターの値で決まるため、変化前＝変化後になり状態変化が記録されない。wp-env で実測）。保存で発火するのは
  保存の前後（`woocommerce_before/after_order_object_save`）・`woocommerce_new_order`・明細の作成（`woocommerce_new_order_item`）で、
  WooCommerce の受注件数キャッシュは最終ステータスに加算される。他プラグインの `woocommerce_new_order` の処理は最初から最終ステータスの受注を受け取る
  （以前は支払い待ちで受け取り、その後の状態変化で完了時処理が動いていた。抑止ではなく経路が移るだけ）。
- `wc_create_order()` は使わない（支払い待ちで先に作るため状態変化になる。顧客 IP・ユーザーエージェントにインポート実行環境の値も残す）。
  D10 #6 の「`wc_create_order` 後に直接プロパティ設定」はこれで置き換えた。
- 作成後の後処理（`OrderWriter::after_creating()`）: (1) Analytics の取込み予約（`woocommerce_schedule_import`）。即時取込みモード
  （`woocommerce_analytics_scheduled_import` が `yes` でない。WooCommerce 10.5 より前から使っている店舗の既定）では `woocommerce_update_order` か
  このフックでしか予約されず、1 回だけの保存（作成）では売上レポートから抜けるため（独立レビュー R1-S1）。(2) WooCommerce 本体の完了時処理のうち、
  `apply_status()` のフラグと `SideEffectGuard` で no-op にならない `wc_paying_customer()`（顧客の購入実績）を新規の completed で呼ぶ。後処理の失敗は
  受注を消さず WooCommerce のログ（source `cart-bridge-jp`）に残す（消すと mapping が書かれず次回に重複作成される）。mapping と checksum は保存されるので、
  失敗した後処理は再試行されず、結果レポートも普通の作成に見える（Analytics は WooCommerce の「Import historical data」で取り戻せる）。
- 状態変化を起こさないことで、本体の次の処理も新規作成では動かなくなる（D10 #6 の意図どおり）: 保留中（on-hold）の受注の売上件数の加算
  （`wc_update_total_sales_counts`。以前は on-hold への状態変化で加算されていた）、返金済み（refunded）へのマッピングでの全額返金レコードの作成
  （`wc_order_fully_refunded`。ステータスは refunded だが返金行が無いので、Analytics は売上として数える。backlog `fix-91/refunded-without-refund`）、
  フルフィルメントの自動作成、顧客の最終アクティブ日時の更新。
- 新規作成では、追加した明細がすべて保存されたか（ID が付いたか）を確かめる。作成の途中（行の作成→`woocommerce_new_order`→明細の保存）で
  他プラグインが `Exception` を投げると、`save()` は握りつぶして ID を返すが明細は保存されていないため（PR #92 G2。以前は 2 回目の保存が保存していた）。
  欠けていれば件数キャッシュを捨ててもう 1 回だけ保存し（更新なので `woocommerce_new_order` も状態変化も起きない）、それでも欠ければ例外にして下の後始末へ進む。
- 保存の途中で `Error` が出たら、作られた行を削除して再送出する（Importer は mapping を書かず次回やり直す）。`WC_Abstract_Order::save()` は
  `Exception` を握りつぶしてログに残すので、外へ出るのは `Error` などだけ。行ができる前に握りつぶされて ID が 0 なら、例外ではなく
  `ORDER_CREATE_FAILED` で見送る（mapping は書かれず次回に再試行。本番の実行では項目ごとの警告を保存しないため、どの受注かは
  WooCommerce のログの日時から追う。例外のときの `cbjp_logs` の「Writer threw」は remote_id 付き）。削除の後は WooCommerce の受注件数キャッシュを捨てて DB から数え直させる（公開クラスの `OrderCountCache::flush()`）。
  最終ステータスで消すので通常はずれないが、行ができた後、件数キャッシュの加算〈`woocommerce_new_order` の優先度 10〉より前で `Error` が
  出ると、加算されないまま減算だけが走って 1 件少なくなるため（PR #92 G1。永続キャッシュのある本番では残り続ける）。
- **既存受注の更新でステータスが変わる場合は対象外**（従来どおり状態変化として保存され、他プラグインのフックも動く。メールは `SideEffectGuard` で止まる）。
  WooCommerce の CRUD には既存受注の状態変化を記録せずに保存する手段が無く、他プラグインのコールバックを一時的に外す案は Jetpack などの正当な連携も止めて
  壊れやすいため見送った（ユーザー判断 2026-10-01。backlog `fix-91/update-status-hooks`）。

## 6. 管理画面・REST API 設計

### REST ルート（namespace: `cbjp/v1`、permission: 特記なき限り `manage_woocommerce` + nonce）

| Method | Route | 用途 |
|---|---|---|
| GET | `/connections` | 全プラットフォームの接続状態一覧 |
| PUT | `/connections/{platform}` | 接続設定保存（makeshop: endpoint+token / colorme・base: client_id+secret） |
| DELETE | `/connections/{platform}` | 接続解除（トークン・client_id/secret の削除）。run・ツールの実行中は 409（R3-0p。下記） |
| POST | `/connections/{platform}/test` | 接続テスト（ショップ名を返す） |
| GET | `/connections/{platform}/authorize-url` | OAuth認可URL取得（OAuth型プラットフォーム: colorme / base）。`?mode=oob` でコード手動貼り付けフォールバック用URLを取得 |
| POST | `/connections/{platform}/exchange-code` | OAuthコード手動貼り付けフォールバック（`{code}`。認証済み管理画面からの呼び出しのため通常のnonce+capability保護のみ。F1-2で追加） |
| GET | `/connect/{platform}/callback` | OAuthコールバック（ASP側に登録する公開URL。**permission例外**: `__return_true` + state検証必須。詳細は下記） |
| POST | `/runs` | 移行実行の開始 `{type, platform, entities[], acknowledge_production_write?}`（`type=export`は`acknowledge_production_write=true`必須。D17/§10.2「E2-4」） |
| GET | `/runs?platform=&status=active` | プラットフォームで進行中の run の一覧（`{ platform, runs }`。run_id を控えていない run の発見。R3-0i・issue #70。下の「進行中 run の発見」） |
| GET | `/runs/{run_id}` | 進捗（per-entityジョブのstatus/totals。UIが2秒間隔でポーリング） |
| POST | `/runs/{run_id}/cancel` | キャンセル |
| GET | `/runs/{run_id}/verification` | 移行後検証レポート（件数・受注合計の ASP/Woo 突合。`type=import` の run のみ。D17/§10.4） |
| POST | `/jobs/{id}/retry` | 失敗ジョブの再実行 |
| GET | `/logs?job_id=&level=&page=` | ログ閲覧 |
| GET/PUT | `/settings/mappings/{platform}` | カテゴリ/決済/配送/注文ステータスのマッピング設定（`category_map`/`payment_map`/`shipping_map`/`status_map`）。GETは選択肢UI用の `asp_candidates`/`woo_candidates`（D19）も同梱する |
| GET | `/limits?platform={platform}` | 無料版上限・Pro解除状態（アップセル表示用。D15/§10.2）。`platform` 指定時は使用状況（mappings累積カウント）・残数も返す。`pro_url` は Pro 版の案内先（検証済みの http/https URL か `''`。§10.3「アップセル表示」） |
| GET | `/tools/sample-cleanup?platform=` | サンプルクリーンアップの削除件数プレビュー（D16/§10.3） |
| POST | `/tools/sample-cleanup` | 無料版サンプルデータの一括削除（mappings記録に基づく。1バッチ分を処理し `has_more` を返す。D16/§10.3） |
| POST | `/tools/rebuild-mappings` | 所有メタ（`_cbjp_platform` + remote_id）の走査による mappings 再構築（1バッチ分を処理し `cursor` を返す。D16/§10.3） |
| GET / POST | `/tools/repair-states?platform=&cursor=` | 県コード修復（issue #46/§10.3）。GET=Scan（読取専用。補正が必要な件数を数える）、POST=Repair（`state` のみ補正）。1バッチ分を処理し `cursor` を返す。ASP への照会に失敗した場合は 503（レート制限。`Retry-After`）/409（未接続・認証切れ）/502 で、処理済みの `counts` と再開用 `cursor` をボディに含めて返す |

nonce（`X-WP-Nonce`）は管理画面Reactアプリからの呼び出しにのみ適用。`/connect/{platform}/callback` は
ASPからの外部リダイレクトで叩かれるためnonce・capabilityを課さず、代わりに `state` ワンタイムトークンで検証する。

同一プラットフォームで run が進行中のとき、`POST /runs`・`POST /jobs/{id}/retry`・`/tools/*` の実行（POST）と県コード修復の Scan（GET。クリーンアップの件数プレビューは 409 にせず `run_in_progress` を返す）・`PUT /settings/export-options/{platform}`・
`POST /push-intents/{platform}/{id}/resolve` は 409 `cbjp_run_in_progress` を返す。エラーの `data.active_runs` に `GET /runs` と同じ形の一覧を載せる
（retry は対象ジョブ自身の run を除く。判定と応答の間に run が終われば空配列）。キャンセルした run が処理中のページを書き終えていないとき・別の操作がプラットフォームの
ロックを持っているとき（§5「同時実行のロックと条件付きの状態遷移」）も同じコードで返す（UI が一覧の取り直しに同じ経路を使えるように）。一覧が空のときだけ、文言を
「Another operation is still in progress for this platform. Try again in a moment.」にする（一覧に run があるときは従来の「A run is already in progress…」）。
`DELETE /connections/{platform}` も同じ判定・ロックで 409 を返す（R3-0p。§5「同時実行のロックと条件付きの状態遷移」）。一覧に run があるときの文言だけを
「A run on this platform has not finished yet. Cancel it on the Tools tab (or the Import or Export tab) first, then try again.」にする（Connections タブはサーバーの文言を
そのまま表示し、run の進捗・キャンセルを持たないため。失敗して止まった run にも合う表現にし、未接続・要再接続でも run を並べる Tools タブを先に挙げる）。コードと `data.active_runs` は他のルートと同じ。

### 進行中 run の発見（R3-0i・issue #70）

**背景**: `POST /runs` はジョブを作って実行を始めてから run_id を返す。管理画面は run_id を localStorage（`cbjp_run_{type}_{platform}`）に控えて進捗を
ポーリングするが、応答がブラウザに届かない（リロード・通信断）・別のブラウザで始めた・localStorage が使えない、のいずれかでは run を見失う。
その間も同時実行ガードは開始・Retry・ツールを 409 で拒否し続けるため、利用者には「何も動いていないのに開始できない」状態になり、最初のジョブが失敗して
兄弟ジョブが `pending` のまま止まった run は無期限にプラットフォームを塞ぐ。

**REST**: `GET /runs?platform=&status=active`（`JobRepository::find_active_runs_for_platform()`）。`args` は GET のエンドポイントにだけ付ける
（route レベルの `args` は `register_rest_route()` が全エンドポイントへ合流させ、`args` を持たない `POST /runs` の既存の検証〔配列の `platform` を 404〕を 400 に変えてしまう）。
`platform` はツール系と同じ `$platform_arg`（`validate_callback` 併記）、`status` は `enum: ['active']`・既定 `active`（将来の絞り込みの余地）。
run ごとの値は全ジョブから集約する: `status` は未終了のジョブの running > paused > pending、`entities` は全ジョブ（失敗したものを含む。取り込む UI が前回の dry-run
件数を捨てる対象）、`has_failed_job`（止まった run を「実行中」と区別して表示する）、`created_at`（最小）/`updated_at`（最大）。一覧は古い順。
同時実行ガードは「1 プラットフォーム 1 run」を保つ前提だが、判定が原子的でない間（issue #57）は複数ありうるため一覧で返す。

**UI**（`src/active-runs.ts`〔純粋関数〕・`src/hooks/useActiveRuns.ts`・`src/hooks/useRunAdoption.ts`・`src/components/ActiveRunNotice.tsx`）:
- 全タブ（Import / Export / Tools / Mappings）が platform ごとに一覧を照会する。一覧を書き換えるのは取得関数だけで、世代カウンタもその中で進める。409 の
  `active_runs` は `refresh( seed )` で同じ経路から反映する。seed は要求を出したときの**プラットフォーム選択の番号**（`selectionRef`。選択が変わるたびに増える）と組
  （`activeRunsSeed()`）で、今の番号と違えば捨てる（応答を待つ間に platform を切り替えたとき、別の選択の run を今の一覧として取り込まないため。platform 名で比べると
  A→B→A と戻った場合に古い応答を通してしまう。R1-2・G1-1・G1-3）。一覧は取得した platform と組で持ち、選択中の platform と違う一覧は返さない。
  一覧の版（`generation`）は platform を切り替えても戻らない単調増加（取り込み直しを一覧ごとに 1 回へ絞る判定が、別の platform の同じ番号で飛ばされないため。G1-2）。
  409 を返す操作（開始・Retry・ツール・画像アップロード設定・push intent の解除）はすべて seed を渡す。
- 照会は止めない: 追跡していない run がある間は 5 秒ごと（追跡中の run は `useRunPolling` が 2 秒ごとに見る）、無い間も 30 秒ごと（一覧が空になったら止めると、
  タブを開いた後に別のブラウザで始まった run を見つけられず、サーバーが拒否しない Mappings の保存がすり抜ける。G1-4/G1-5。ユーザー決定 2026-10-02）。
  取得に失敗しても直前の一覧は消さない（4xx はすぐには直らないので 60 秒後に再試行する。止めると stale のまま固まる。R2-3）。
- Import/Export タブは自分の種別の run をセクションに取り込む（`decideAdoption()`）: 一覧の取り直し中・セクションの応答待ち（開始・Retry・キャンセル）は何もしない。
  空のセクション、または表示中の run が終了・404 なら最古の run を取り込み、表示中の run が進行中・未取得なら置き換えない。表示中の run を「終了」と見ているのに
  一覧では進行中なら、その run のポーリングだけを一覧 1 つにつき 1 回取り直す（別ブラウザからの Retry で再開した run を見失わないため）。一覧は取り直さない
  （取り直すと世代が進んで再び同じ判定に入り、ポーリングが失敗し続ける間は遅延なしで照会を繰り返す。R1-3。一覧が古いだけなら、追跡中の run はボタンを止めない）。
  そのかわり開始が成功したときに一覧を取り直す（終わったあとも一覧に残っていた前の run が、新しい run を始めた途端「追跡していない run」になって案内・
  取り込みし直されないように。R2-2）。
  取り込みは開始成功時と同じ後処理（run_id の控え・`setLimits(null)`・`withoutDryRunTotals()`・export なら本番書込みの確認を外す）を共有する。
  Clear・キャンセル成功・開始の**すべての**エラー（409 以外でも、応答が届かなかっただけで run が作られていることがある）で一覧を取り直す。
- 別タブの種別（種別不明を含む）の run と、このタブの種別でもセクションが別の進行中の run を表示していて取り込めない run（同時実行ガードの競合で 2 本目が
  できた等）は `ActiveRunNotice` で案内する（`runsToAnnounce()`。状態の説明・担当タブへのリンク `#/<tab>?platform=`・キャンセルボタン）。
  キャンセルを案内にも置くのは、Tools タブには未接続・要再接続の platform も並び、その run は（接続済みの platform だけを出す）Import/Export タブで開けないため
  （その platform ではリンクを出さない。リンク先では先頭の別 platform が開いてしまう）。
  Import/Export タブはハッシュの `?platform=` を接続済みの platform に一致するときだけ初期選択にする（Mappings タブと同じ）。
- 追跡していない run がある間は、開始・Retry・エンティティ選択・画像アップロード設定・本番書込みの確認（Export）、`PushIntentsPanel` の解除、Tools の
  再構築/Scan/Repair（プレビューは可）、Mappings の保存を止める。Mappings の保存はサーバーが拒否しないので、一覧を最初に取得できるまでも止める（フェイルクローズ。G1-4）。
- Tools のクリーンアップ（`isCleanupBlocked()`）は、プレビュー時点で run が進行中だった（`run_in_progress`）・今 run が進行中・プレビューのあとに run を見た、
  のいずれでも止め、プレビューの取り直しを求める（「プレビューのあとに run を見た」の印は、取り直しに成功したときだけ下ろす。失敗したら古いプレビューが残るため。
  一覧の取り直し中は印を付けない。直前に終わった run が古い一覧に残っていると、その後の新しいプレビューまで古いと誤判定するため。R2-1・R2-4）。削除（`SampleCleanup::run()`）はプレビューの件数と関係なくその時点の mapping をすべて対象にするため、
  run と重なったプレビューの件数で確認させない（件数 0 なら確認ダイアログも出ないまま、run が書いたデータを消してしまう。R1-1。今の一覧だけで判定した最初の実装は、
  run が終わった時点で古いプレビューのまま削除できる退行だった）。

**残る制限**:
- Mappings タブの保存の無効化は UI だけで、サーバーは run 中の保存を拒否しない（R3-0m の判断どおり。設定は次のアイテムから効き、未設定側は警告に倒れる）。
  別のブラウザで run が始まってから、このタブの次の照会（最大 30 秒）までの間は保存できてしまう（G1-4/G1-5 でサーバー側の 409 は採らなかった。ユーザー決定 2026-10-02）。
- キャンセルしたジョブが `paused`/`completed` で上書きされて復活しうる根本原因（`JobManager::process_job()` の無条件の状態更新）と、判定の非原子性は
  R3-0i の (3)(4)（issue #57）で直した（§5「同時実行のロックと条件付きの状態遷移」）。
- 開始ボタンの 409 で出たエラー文（「A run is already in progress…」）は、塞いでいた run が終わった後も閉じるまで残る（ほかのエラーと同じ扱い）。

**実機確認（2026-10-02、wp-env・mock アダプタ `mockv`）**: run_id をブラウザに渡さずにジョブを直接作り（Action Scheduler の action なし＝動かない run）、
localStorage を消した状態で確認した。Import タブが dry-run を取り込んで Cancel run を出し（一覧の照会は 1 回、以後は run の 2 秒ポーリングだけ）、
Export / Tools / Mappings タブは案内・リンク・キャンセルを出してボタンを止めた（Export と Tools は一覧を約 5 秒ごとに照会）。案内のリンクから Import タブへ移ると
mockv が選ばれ（colorme も接続済みの環境）、キャンセル → Clear 後は取り込み直さない。Import タブを開いたまま裏で export run を作って Preview を押すと、409 の
`active_runs` から再読込なしで案内が出た。失敗で止まった export run は Import タブで「a job failed」の案内、Export タブで Failed・Retry つきで取り込まれた。
REST は `rest_do_request()` で 200（一覧）/400（platform なし・配列・不正な status）/404（未登録）/409（`active_runs` に該当 run）を確認。コンソールエラーなし。撤去済み。
review-loop R1 の修正後に再確認: 同じ種別の dry-run を 2 本作ると、Import タブは古いほうを取り込み、2 本目をリンクなし・キャンセルつきで案内した。Tools タブで run の最中に
プレビュー → 裏で run を止めると、約 5 秒で案内が消えてボタンが戻り、プレビューには「取り直して」の警告が出た（`isCleanupBlocked()`）。撤去済み。

### OAuth コールバック（カラーミー・BASE共通）

- コールバックURL: `{site_url}/wp-json/cbjp/v1/connect/{platform}/callback`（ユーザーが各ASPのアプリ登録画面に登録する。設定画面にコピー用で表示）
- `state` = ワンタイムトークン（transient、10分、管理ユーザーIDに紐付け）で CSRF 対策。`permission_callback` は `__return_true` とし、state 検証を必須にする
- code→トークン交換後、管理画面（接続タブ）へリダイレクト
- **フォールバック**: ASP側がhttpsリダイレクトURIを要求する場合に備え、「認可後のURLからcodeを手動貼り付け」する入力欄も用意（カラーミーはhttp/localhostでも自動リダイレクト可と実機確認済み=要検証#7。BASEは要検証#9で確認。BASEの認可コード有効期限は約1時間なので手動貼付でも運用可能）
- BASE固有: トークン交換・リフレッシュ時に `redirect_uri` パラメータが**毎回必須**（BaseOAuthで保持）

### React アプリ（src/）

- `@wordpress/scripts` ビルド、TypeScript strict、`@wordpress/components` + `@wordpress/api-fetch`
- ルーティングは単一管理ページ内のタブ切替（Connections / **Mappings** / Import / Export / Logs / **Tools**）。URLは `#/import` 形式。Tools タブにはサンプルクリーンアップ / リンク再構築（D16）/ 県コード修復（issue #46）（`/tools/*` ルート）を配置
- **Mappings タブ（`#/mappings`、R3-0m）**: `GET/PUT /settings/mappings/{platform}` の編集 UI。E2-1 で Export タブに作ったものを共有コンポーネント
  `src/components/MappingSettings.tsx` に切り出して移した（GET と PUT は同じ世代カウンタを共有する）。決済方法・配送方法・注文ステータスを常に、
  カテゴリは `can_create_category === false` のときだけ出す。run 中の保存は R3-0i で UI 側だけ止めた（`GET /runs?platform=` で進行中の run を
  見つけたら保存を無効にして案内する。サーバーは拒否しない: `Woo\Support\MethodMap` は参照のたびにオプションを読むので、run の途中で保存した設定は
  次のアイテムから効き、未設定側は未マッピング＝警告に倒れるだけ）。
  Export タブには Mappings タブへの案内とリンクだけを残した（platform の選択は Export タブ先頭のカードへ移し、世代カウンタは画像設定の取得 effect が進める）。
  Import/Export の案内のリンクは `#/mappings?platform=<platform>` で選択中の platform を引き継ぎ、Mappings タブは接続済みの platform に一致するときだけ
  それを初期選択にする（`src/hash-route.ts`。ハッシュは任意の文字列を含みうるため。PR #89 G1-1）
- **Import タブの事前チェック（R3-0m）**: 受注が選ばれているとき、`GET /settings/mappings/{platform}` を platform ごとに 1 回だけ呼び
  （ColorMe は `categories.json`/`payments.json`/`deliveries.json` の 3 コール）、ASP 側の決済・配送方法のうち使える設定の無い数
  （未設定、または設定先が現在の Woo 側の候補に無い。`OrderWriter` の実在チェックと同じ扱い）を `Notice`（warning）で案内し、Mappings タブへリンクする。
  **案内のみで Preview / Run import は止めない**（未マッピングでも受注は作成され、決済/配送方法が空のまま警告になる現行設計を維持）。
  計算は純粋関数 `src/components/mapping-status.ts`。候補の取得に失敗したとき（未接続・レート制限。REST は空の候補を返す）は何も出さない。
  `status_map` は未設定でも既定のステータスに落ちて警告にならないため数えない。
  **未マッピングの受注は checksum を保存しない**（`PAYMENT_METHOD_UNMAPPED`/`SHIPPING_METHOD_UNMAPPED` は `WarningCode::indicates_unresolved_reference()` の対象）:
  保存すると、後から Mappings タブで設定しても `Sync\Importer` の checksum 一致で飛ばされて空の決済/配送方法のまま直らず、再 dry-run も検証を飛ばして
  警告だけが消える。未マッピングのまま運用すると該当受注は毎回再処理される（ほかの未解決参照と同じ扱い）。エクスポートは未マッピングの受注を送らない
  （`ColorMeAdapter::order_skip_warnings()`）ため影響しない。**要検証（v1.0 では見送り、2026-09-30 決定）**: ColorMe の
  `payments.json`/`deliveries.json` に削除済みの方法が含まれるか。非表示（`display=false`/`display_state`）の方法は応答に含まれ候補にも出る
  （`ColorMeAdapter::id_name_map()` は表示状態で絞らない）が、削除済みの方法を参照する旧受注があると、その ID は候補に無いため UI からマッピングできず、
  事前チェックにも数えられない（backlog `e2-1-mapping-ui/R2-L3` の source 側孤立と同根）。F1-8 の実店舗は決済・配送の方法すべてが現行の候補にあった。
  必要になったら、直近 dry-run の警告 detail（`cbjp_dry_run_items`）から未マッピング ID を集計して行を足す REST を検討する
- ページ登録: WooCommerce メニュー配下 `admin.php?page=cart-bridge-jp`
- UI文字列は英語 + `@wordpress/i18n`（`wp_set_script_translations`）

## 7. プラグインヘッダー・互換宣言（確定）

```php
/**
 * Plugin Name: Cart Bridge JP – Migrate for WooCommerce
 * Description: Migrate products, customers, and orders between Japanese e-commerce platforms (Color Me Shop, MakeShop, BASE) and WooCommerce.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * Author: Artisan Workshop
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cart-bridge-jp
 * Domain Path: /languages
 *
 * WC requires at least: 10.0
 */
```

- **Description の v1.0 化（D18）**: 上記ヘッダーと `composer.json` の Description は3ASPを併記しているが、v1.0 公開時（R3-3）に「Color Me Shop」のみへ改め、BASE（v2.0 / R5-1）・MakeShop（v3.0 / R7-1）はそれぞれの公開時に追記する
- HPOS: `before_woocommerce_init` で `FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true )`
- WooCommerce 未有効時は管理画面通知を出して機能を無効化（fatalにしない）
- アンインストール: `uninstall.php`。オプション `cbjp_delete_data_on_uninstall`（デフォルトfalse）が true の場合のみテーブル・オプション削除

## 8. CI（GitHub Actions）

`.github/workflows/ci.yml` — push / PR（main宛）で実行:

1. **php-quality**: PHP 8.2/8.3 マトリクスで `composer lint`（PHPCS）+ `composer analyze`（PHPStan level 6）
2. **php-test**: `wp-env` を起動して `composer test`（PHPUnit）
3. **js**: `npm ci && npm run lint && npm run build`（tsc型チェック含む）

## 9. 要検証事項トラッカー（00 §7 の具体化）

| # | 項目 | 確定タイミング | 状態 |
|---|---|---|---|
| 1 | カラーミー: 商品POST/PUTの画像登録可否 | Phase 1 タスク F1-0（swagger精査+実測） | **済（プラン依存）**: `products.json`のcreate/update本体に画像フィールドはないが、専用エンドポイント`POST /v1/products/{product_id}/images`（マルチパート、`image`+`position`）が別途存在する。**ただしプレミアムプラン契約ショップのみ利用可**（レギュラープラン等は403想定・要実機確認）。`canPushImages`は固定falseではなく、`GET /shop.json`の`contract_plan`を見てプラン依存で判定する設計に変更（F1-5で実装、E2-3で画像push実装時に403時のCSVフォールバックへの切替を含める） |
| 2 | MakeShop: レート制限値 | v3.0 Phase 6 タスク M6-0（FAQ/問い合わせ） | 未 |
| 3 | MakeShop: 自社利用登録の条件（プラン・費用） | 取得済みのため契約内容をREADME用に記録（v3.0 M6-0） | 未 |
| 4 | MakeShop: createProduct の画像入力形式 | v3.0 Phase 6 タスク M6-0 | 未 |
| 5 | カラーミー: 受注POSTの必須項目・決済/配送ID | v1.0 Phase 2 タスク E2-3（テストショップ実測） | **済（swagger精査 2026-09-15）**: `POST /v1/sales`はプレミアムプラン契約のショップのみ利用可。必須は`sale.details`（各行`product_id`+`product_num`）と`sale.payment_id`のみ。`sale.customer`は丸ごと省略可（既存顧客IDを渡す場合、他の属性は無視される）。`sale.sale_deliveries`は配送不要商品を含む場合を除き必須（各行必須: `delivery_id`/`name`/`furigana`/`postal`/`pref_id`/`address1`/`tel`）。`sale.details[].price`は省略可（省略するとColorMeの現在のカタログ価格が適用される）。`PUT /sales/{id}`は入金状態・配送情報の一部しか更新できない（明細・決済/配送方法の変更は不可）。実装詳細は§10.2「E2-3 PR-C」。実店舗での`add_member`通知メール確認（要検証#17）とあわせて、実際のColorMeショップに対する動作確認はE2-4またはF1-8相当の実機E2Eで行う |
| 6 | 大規模ショップのジョブ実行時間 | Phase 1 E2E（F1-8）で計測 | 未 |
| 7 | カラーミー: リダイレクトURIのhttps要否（ローカル開発時のOAuth可否） | Phase 1 タスク F1-2 | **済（実機確認 2026-09-03）**: デベロッパーコンソールに `http://localhost:8888/wp-json/cbjp/v1/connect/colorme/callback`（wp-env 既定ポート。実際に登録するURLは `GET /connections` が返す `callback_url` を使うこと。実測環境では wp-env が 8898 にバインドされていたが結果は同じ）を登録でき、自動リダイレクト方式で接続完了した。**httpsは必須ではない**（ローカル開発でも自動リダイレクトが使える）。OOB手動貼付フォールバックは引き続き保持する（BASE=要検証#9は別途） |
| 8 | MakeShop: searchProduct等のページング方式（cursor/offset・最大件数） | v3.0 Phase 6 タスク M6-0 | 未 |
| 9 | BASE: リダイレクトURIのhttps要否・localhost可否 | v2.0 Phase 4 タスク B4-0 | 未 |
| 10 | BASE: 明細単位発送ステータスの注文全体への集約規則（dispatch_statusの実値一覧含む） | v2.0 Phase 4 タスク B4-0 | 未 |
| 11 | BASE: エラーレスポンス形式・レート制限超過時の挙動（Retry-Afterヘッダー有無） | v2.0 Phase 4 タスク B4-0 | 未 |
| 12 | BASE: API利用費用・スコープ承認フロー（README前提条件用） | v2.0 Phase 4 タスク B4-0（公式FAQ確認） | 未 |
| 13 | BASE: add_image のURL取得要件（Basic認証下・ローカルURLの挙動）と canPushImages 最終確定 | v2.0 Phase 5 タスク E5-1 | 未 |
| 14 | 各ASP: 一覧APIの新しい順ソート指定可否（受注は必須、商品・顧客・クーポンはフォールバック用。サンプル選定=D15） | F1-0 / B4-0 / M6-0 | **カラーミー済（実機確認済み 2026-09-03）**: `GET /sales.json`はソートパラメータなしでデフォルト`make_date`降順（新しい順）で返るが、**`after`/`before`省略時の検索対象は直近7日間に限定される**（`after`未指定時は`before`の7日前0時がデフォルト。swagger実測確認）。ショップの直近7日間の受注が10件未満の場合、`fetchLatestOrders(10)`は探索窓（`after`）を過去方向へ4倍ずつ広げて複数回リクエストし、10件集まるか受注履歴の下限（2000-01-01）に達するまで走査する（**F1-5実装済み**: `before`は常に省略し暗黙の現在時刻に固定したまま`after`のみを広げる方式。`fetch_orders`によるカーソル全量走査も`after=2000-01-01`を明示することで直近7日制限を回避する）。**テストショップ実測**: 直近7日の受注0件・全履歴2件の店舗で、`after`省略→28日→112日→448日→1792日→7168日→2000-01-01 の7回の`sales.json`呼び出し（＋`payments.json`/`deliveries.json`各1回）で下限に到達し2件を取得、所要1.7秒。直近7日に10件以上ある店舗では1回で確定する。MakeShop/BASEは未 |
| 15 | 各ASP: 商品・顧客のID指定取得エンドポイントの有無（サンプル取得=D15） | F1-0 / B4-0 / M6-0 | **カラーミー済**: `GET /products.json` `/customers.json` `/sales.json` すべて `ids` クエリパラメータで複数ID指定取得可能。個別詳細 `/products/{id}.json` 等も利用可（swagger + 実測確認）。MakeShop/BASEは未 |
| 16 | カラーミー: 商品の定価（`price`）が税抜/税込どちらか（`CanonicalProduct.sale_price` への反映可否） | F1-3で判明。実店舗での実測時（Phase 1 E2E等） | **済（実機確認 2026-09-03）**: テストショップ（`shop.tax_type=excluded`, `tax=10`）で定価8,000円・販売価格6,000円の商品を登録した結果、APIは`price=8000`, `sales_price=6000`, `sales_price_including_tax=6600`を返し、店頭は定価「¥8,800」・販売価格「¥6,600」を表示した。つまり**`price`（定価）は`sales_price`と同じ税基準の値**（`tax_type=excluded`なら税抜、`included`なら税込）で、税込版フィールドは無い。Woo反映は「`regular_price`=定価の税込換算値、`sale_price`=`sales_price_including_tax`（定価未設定または定価≦販売価格なら`regular_price`=`sales_price_including_tax`、`sale_price`=null）」とし、税込換算は`shop.tax_type`/`tax`/`reduce_tax_rate`/`tax_rounding_method`と商品`tax_reduced`から行う。**実装済み**: `ProductTransformer`が店舗税設定をコンストラクタで受け取り（`ColorMeAdapter::product_transformer()`が`GET /shop.json`から注入）、既知の許可値（`tax_type`が`excluded`/`included`、丸め方式が`round_off`/`round_down`/`round_up`）のみ肯定形で判定する。未知値・欠損・税設定未取得の場合は換算せず現行の`regular_price = sales_price_including_tax` / `sale_price = null`にフェイルクローズする。`tax_type=included`の店舗は未実測（計算上は換算不要） |
| 17 | カラーミー: `POST /v1/customers`の`add_member: true`が会員登録時に通知メール（パスワード設定案内等）を自動送信するか | E2-3 PR-Bで判明。実店舗での実測時（要検証#5と合わせて） | 未。swaggerに記載無し。`push_customer()`は往復インポート整合性のため新規作成時に常時`add_member: true`を送るが、本プロジェクトは移行時の副作用（通知メール等）抑止を重視する方針（`docs/01-plan-colorme.md`「通知メールは送らない」）。`POST /sales/{id}/mails.json`が受注確認メールを独立エンドポイントに切り出している設計から自動送信の可能性は低いと推測するが未確認。実店舗確認まで、E2-3の実機確認（要検証#5）と合わせて要検証のまま残す |
| 18 | カラーミー: `GET /sales.json?ids=` が `after`/`before` 省略時の「直近7日」制限（#14）を上書きするか（複数ID指定で古い受注を取れるか） | 県コード修復（issue #46）の計画で判明。実店舗での実測時 | **済（実店舗で実測 2026-09-30、R3-0n）: 上書きしない**。2018年の受注IDを `ids` だけで指定すると `total: 0` で返る（同じIDは `GET /sales/{id}.json` では取得できる）。以下は実測前の記録: swagger は `ids` が日付範囲を上書きするとは書いておらず（`after` の説明は「未指定時は現在から7日前の0時」）、#15 は「`ids` で複数ID指定取得可能」としか確認していない。`ids` だけでは古い受注が黙って0件になる可能性がある。県コード修復ツールは一覧の `ids` を使わず、日付窓の影響を受けない単一取得 `GET /sales/{id}.json`（`ColorMeAdapter::fetch_order_by_remote_id()`）を使う。issue #38（受注のID指定取得による無料版サンプル選定）で `ids` の一括取得を採用する場合は、`after` を明示したうえで実機確認が必要 |
| 19 | カラーミー: `PUT /products/{id}/variants/{id}` で`variant.stocks`を送った際、商品側の`stock_managed`が`false`の商品でも反映されるか | E2-3 PR-D（issue #47）review-loop R1で判明、R2で記述を精査、G1（Codex指摘）でコード側の対応方針を確定。実店舗での実測時 | 未実測だが**運用上は解消済み**。swaggerの`variant.stocks`説明文は「全バリエーションの在庫数が未設定(`null`)の状態で1件でも値を送ると商品全体の在庫数（数値）がバリエーション在庫に揃う」としか述べておらず、`product.stock_managed`という真偽値フラグ自体が追随するとは書かれていないため、`push_stock()`はG1でバリエーションの`stocks`をPUTする前に`PUT /products/{id}`へ`{stock_managed: true}`を明示送信するよう変更した（`docs/03`§10.2「E2-3 PR-D」参照）。これによりコードは「`variant.stocks`だけで反映されるか」という未確認の挙動に依存しなくなったため、本項目は**ブロッカーではない**。実店舗で2リクエスト構成が意図どおり動くことの確認自体は今後の実機確認（E2-4/R3-1）で行う。**R3-1（2026-10-05）**: テストショップで在庫管理ありの可変商品（1 軸・2 軸）の在庫を export し、2 リクエスト構成（`stock_managed: true` → バリエーションの `stocks`）がエラーなく通り、在庫数が変わらない（往復で一致）ことを確認した。在庫管理なしの可変商品のバリエーションは `stock_variant_unmanaged_not_pushable` で送らない（仕様どおり）。`stock_managed=false` の商品に `variant.stocks` だけを送ったときの挙動そのものは引き続き未実測（コードは依存しない） |
| 20 | カラーミー: `sale.totals`（nullable）が欠損する条件 | 実店舗の受注 dry-run（R3-0n） | **済（実店舗で実測 2026-09-30）**: 2019-09-09 以前の受注で null、2019-09-12 以降は全件にある（軽減税率の導入直前に追加されたと見られる）。欠損時の `sale.tax` は商品分のみ（当時の税率8%）で送料分を含まないため、`order_tax_total_incomplete`（情報）は正しい。該当受注はすべて送料があり、「送料・手数料・割引が0なら `sale.tax` が全税額」という絞り込みは効かないので入れていない |
| 21 | カラーミー: `sale.details[].product_num=0` の明細 | 実店舗の受注 dry-run（R3-0n） | **済（実店舗で実測 2026-09-30）**: キャンセルした受注は全明細が `product_num=0`・`subtotal_price=0`（商品合計・受注合計も0）、一部の明細を外した受注はその行だけ同じ形（単価 `price`/`price_with_tax` は残る。商品合計はその行を含まない）。該当の受注の多くはキャンセル受注。数量0・金額0のまま取り込む（D10「数量0の明細」） |
| 22 | カラーミー: 受注明細の `option1_value`/`option2_value`/`product_model_number` は受注時点の値か現在の商品の値か（swagger は「最新の商品情報」） | 実店舗の受注 dry-run（R3-0n） | **一部確認（2026-09-30）**: 受注後にオプションの軸が増えた商品の2018年の受注では、`option2_value` が null・`product_model_number` が当時のバリエーションの型番（現在のバリエーションには型番が無い）で、**受注時点の値**だった。現存するバリエーションだけが対象のときに現在の値へ置き換わるかは未確認。`ProductResolver` は軸の数が合わなければ特定を諦める（`order_line_variation_unmatched`） |

確定したら本表と該当計画ドキュメント（Capabilities値等）を更新すること。

**カラーミー顧客APIの補足（F1-0で判明、要検証事項外）**: `customers.json` レスポンスには法人名`hojin`・部署`busho`フィールドが存在し実データでも値が入る場合があるが、管理画面の標準「顧客登録」フォームにはこの2項目の入力欄がない（CSV一括登録等の別経路でのみ設定可能と推測）。F1-3のCustomerTransformer実装時に、hojin/bushoがnullでも異常とせず正しくマッピングすること。

## 10. 無料版制限・Pro版ライセンス設計（D14〜D17）

### 10.1 ビジネスモデル・ライセンス（D14）

無料版の役割は「自分のショップのデータで挙動確認ができること」に限定し、本番利用は Pro 版に誘導する。

- **無料版**（wordpress.org 配布）: dry-run は全エンティティ全量無料（変換結果・警告のプレビューが購入判断材料）。実インポート/エクスポートは §10.2 のサンプル上限内のみ
- **Pro版**（別プラグイン・自社サイト直販）: 「移行プロジェクトライセンス」として販売
  - サイト数無制限 / **初回アクティベーション時点から3ヶ月**のアップデート・メールサポート
  - 期限後: **認証済みサイトは永続動作**。新規サイトのライセンス認証とアップデート取得のみ不可（＝新規案件では買い直し）
  - 価格: ¥19,800 前後 / 返金保証なし（無料版で事前検証可能なことを販売ページに明記）
  - 販売基盤: 自社WooCommerceサイト + **WooCommerce API Manager**（アクティベーションAPI・アップデート配信）。
    有効期限の起点を「購入時」でなく「初回アクティベーション時」にする点はカスタマイズポイント
  - 適格請求書（インボイス）対応は販売サイト側で行う（本体プラグインのスコープ外）
  - 期限切れ後の再購入導線（リピート割引・プラグイン内販促通知）は**実装しない**
- **継続同期（定期差分同期・在庫双方向同期）は販売しない**。`cbjp/sync/*` フックの提供予定も廃止
- Pro版の解除機構: wordpress.org 規約上、無料版内に鍵付きコードを同梱できないため、
  Pro は別プラグインが `cbjp/limits/*` フィルターで上限を解除する構成とする（無料版に Pro 固有コードを含めない方針は維持）

### 10.2 無料版の実行上限とサンプリング（D15）

**エンティティ別上限**（インポート/エクスポート共通。dry-run は全量無料）:

| エンティティ | 無料版の実行上限 |
|---|---|
| カテゴリ / タグ | 全量（サンプル商品の検証に必須のため制限しない） |
| 受注 | 最新10件（サンプル） |
| 顧客 | サンプル受注の購入者（最大10件） |
| 商品 | サンプル受注に含まれる商品（**ハードキャップ50件**） |
| 在庫 | サンプル商品分のみ |
| レビュー | サンプル商品に紐づくもののみ |
| クーポン | 最新10件 |

**サンプル選定ロジック（SampleSelector）**:

1. 実行開始時に `fetchLatestOrders(10)` で最新受注10件を取得。
   カラーミーは一覧APIの日時範囲パラメータ省略時に検索対象が直近7日間へ暗黙的に絞られるため（§9 #14）、
   `fetchLatestOrders` 実装は7日間で10件に満たない場合、探索窓（`after`）を過去方向へ4倍ずつ広げて
   再取得を繰り返し、10件集まるか受注履歴の下限（2000-01-01）に達するまで走査すること
   （単発リクエストでは不足しうる。**F1-5実装確定**: `before`は常に省略し現在時刻を暗黙の上限に
   固定したまま`after`のみを過去へ広げる。swagger記述（`after`パラメータの説明文）を読むと
   `before`省略時のデフォルトは`after`の有無に関わらず常に現在時刻であるため、`before`を
   明示的にずらす必要はない。要検証#14はF1-5実機確認（2026-09-03）で実測済み: 直近7日の受注が
   0件の店舗で7回の`sales.json`呼び出し・1.7秒で下限に到達し全受注を取得した）
2. 明細から商品 remote_id、購入者（email / remote_id）を抽出し重複排除（ゲスト購入は顧客枠にカウントしない）
3. サンプルセットをオプション `cbjp_sample_{platform}`（autoload無効）に保存。再実行は同一セットの upsert
4. 商品・顧客・受注は **ID指定取得**（`fetchProductByRemoteId` / `fetchCustomerByRemoteId` /
   `fetchOrderByRemoteId`）で取り込む（全量カーソル走査してスキップする方式はレート制限を浪費するため
   不採用。受注はissue #38でこのリストから漏れていたことが判明し是正した。`fetchOrderByRemoteId`は
   `after`/`before`省略時の直近7日制限〔要検証#14〕を受けない単一取得のため、要検証#18〔`ids`一括
   取得の日付窓〕とは無関係に採用できる）。
   **例外（受注のみ）**: `fetchOrderByRemoteId`はインターフェース契約上、未対応ASPが
   `UnsupportedOperationException`を投げることを許容する（商品・顧客のID指定取得には無い許容）。
   受注は`fetchOrders`（カーソル走査）自体が全アダプタ必須で代替経路が無いため、単一ID取得が
   未対応のアダプタでは`JobManager`が通常のカーソル走査（`LimitPolicy`で上限は掛かる）へ
   フォールバックする。商品・顧客はこの許容が無く、フォールバックも実装しない（issue #38 G2、
   Copilot指摘）。
   カテゴリ/タグ/クーポンは通常のカーソル走査。
   **在庫はサンプル商品のID指定取得の結果（CanonicalProduct.stock）から書き込み**、`fetchStocks` の全量走査は無料版では使わない
5. **フォールバック**: 受注0件のショップ・受注エンティティ未選択時は「各エンティティ10件」。
   受注が10件未満なら全受注 + 残枠を商品・顧客で補完。
   **「最新10件」の並び順定義**: 受注は新しい順（`fetchLatestOrders`）。商品・顧客・クーポンは
   APIが新しい順ソートを指定できる場合のみ新しい順、できない場合は**API標準順の先頭10件**とする
   （通常カーソルの先頭ページで代用。ソート指定可否は要検証#14で各ASP・各エンティティについて確定）。
   **F1-5実装済み**: `SampleSelector::top_up_with_first_page()` が `fetch_products`/`fetch_customers`
   の `Cursor::start()` 先頭ページのみ（複数ページの全量走査はしない）から不足分を補う。
   顧客側は `Capabilities::can_fetch_customers` が false のアダプタ（例: BASE。D12）では呼び出さず、
   いずれのエンティティも一覧取得自体が失敗した場合は補完をスキップして受注由来の分だけで確定する
   （Sync層にプラットフォーム固有知識を置かない原則。アーキテクチャ原則8）
6. エッジケース: 削除済み商品の明細は404→警告+カスタム行（D10）/ バリエーションは親商品で1件 /
   BASE は顧客のID指定取得なし→サンプル受注10件分の購入者から生成（D12）
7. サンプルのやり直しは「サンプルクリーンアップ（§10.3）→ 再選定」で行う。
   **クリーンアップせずに再選定は不可**（mappings累積カウントと商品ハードキャップの整合を守るため。UIでもこの順序を強制する）
8. **エクスポート側の選定**: Woo→ASPも同基準で、**Woo側の最新受注10件**（`wc_get_orders` の日付降順）を起点に
   紐づく商品・顧客をサンプルとする。フォールバック規則も同様（Woo側は日付ソートが常に可能）。
   **D25（R3-1a）以後は、書き出し先と同じプラットフォームからの取込みで結ばれた受注・商品・顧客を起点・明細・補充のすべてから除く**
   （エクスポートされないため。除かないと、取り込んだ受注〔ASP の注文日時を保つので最新側に並ぶ〕だけでサンプルが埋まって保存され、
   クリーンアップするまで全件スキップになる）。受注の絞り込みは取得後に PHP で判定し（`meta_query` はレガシー保存方式で無視される）、
   新しい順に 50 件ずつ最大 10 回読んで打ち切る（打ち切っても結果は変わらない: 無料版では同じプラットフォームからの取込みが上限〔受注 10・
   商品 50・顧客 10〕で止まり、超えるのは Pro 版で取り込んだ後に無料版へ戻した場合だけで、そのときは `LimitPolicy::used()` が上限を超えて
   エクスポートの作成枠が 0。PR #104 G1-1）。保存済みのサンプルは選び直さず、残る取込み品は `Exporter` のスキップに任せる

**上限の強制**: UI ではなく `Sync\JobManager` がサーバーサイドで強制する。累積カウントは
`cbjp_mappings` の件数（platform + entity_type）を正とし、再実行で上限が加算されない。
上限値は `cbjp/limits/{entity}` フィルターで提供し、Pro プラグインが解除する
（実際のフック名は `cbjp/limits/product` のようにエンティティごと。本ドキュメント群で `cbjp/limits/*` とあるのはその総称）。

**import/export共有**: `cbjp_mappings` は方向を持たない設計（`UNIQUE(platform, entity_type,
remote_id)`）のため、上記累積カウントは import 由来・export 由来の行を区別せず合算する
（E2-2で確定。無料版=挙動確認という位置づけ（D14）から、往復を通じた合計上限として扱う）。
**D25（R3-1a）以後は原則として 1 つの行を両方向が書かない**: 取込みで結ばれた実体はエクスポートせず、エクスポートで結ばれた実体は取込みで
上書きしないため、各行の checksum は結んだ向きだけが書く（下の「往復の扱い（D25）」。例外は同節の「往復を想定しないため対応しないもの」2）。以下の 2 段落は D25 より前の状態の記録で、
名前空間の混ぜ込みは D25 より前に両方向が書いた行と、判定の取りこぼしへの防御として残している。

**checksum列も同じ行をimport/exportで共有するが値の意味は別物**（E2-2 R1で判明。詳細は
`Sync\Exporter`クラスdocblock）: `Importer`が書くchecksumはASP側`CanonicalModel`のハッシュ、
`Exporter`が書くのはWoo側`CanonicalModel`のハッシュで、同じ実体でも一致しない。素朴に同じ値として
比較すると、一度でも両方向が触った実体（例: importで作られた商品がWooで購入されexportのサンプルにも
選ばれた場合）で、以後のimportが「ASP側は変わっていないのにchecksum不一致」と誤判定し
`ProductWriter::write()`がWoo側の手動編集を無条件に上書きし続けてしまう。`cbjp_mappings.checksum`は
`CHAR(64)`固定長（生のsha256 hex digest専用）のためImporterの生ハッシュへ文字列プレフィックスを
付ける方式は使えず、`Exporter::export_checksum()`が`canonical_json()`をハッシュする**前**に
固定の名前空間文字列を混ぜ込むことで、出力を64文字のsha256 hex digestに保ったまま名前空間を分離する
（`Importer`側は無変更。SHA-256の衝突耐性に依拠し、双方とも「自分が最後に書いた値と一致するか」
だけを見るため相手方向の生ハッシュとは構造的に一致せず安全側＝再同期に倒れる）。

**この修正が解決する範囲（E2-2 R2で明確化）**: 解決するのは「本来は変更があるのに誤って
`一致`と判定してしまう」偽陽性のみである。両方向が同じ行を触った直後は、生ハッシュと名前空間付き
ハッシュが構造的に一致しないため**必ず**「変更あり」と判定され、次にそのentityを触った方向が
再同期（Woo→ASPは再push、ASP→Wooは`ProductWriter::write()`等の再書込）を行う。これは本修正が
新たに生んだ挙動ではなく、修正前から（ASP側JSONとWoo側JSONは`extras`・画像URL等が構造的に
異なるため通常は）実質的に同じ結果だった（=修正前後で「再同期コストがある」という性質自体は
不変で、本修正は「本来なら再同期すべきなのに誤ってスキップする」危険な方を無くしたもの）。
「両方向を跨いで触られた行は次の同期で必ず1回だけ余分な書込が起きる」こと自体を無くすには
`cbjp_mappings`に方向別のchecksum列を追加する（スキーマ変更）等の設計が必要で、無料版のサンプル
規模（最大50件）では実害が小さいと判断し本PRのスコープ外とした。

#### エクスポート方向の実装（E2-2 PR-A）

- **アーキテクチャ**: `Sync\Importer`/`Sync\WooWriter` の対称形として `Sync\Exporter`/
  `Sync\PlatformWriter`/`Sync\WooReader` を新設。Woo→Canonical読出は `Woo\Reader\EntityReader`
  実装（PR-A: `Woo\Reader\ProductReader` のみ。`Woo\WooReaderRepository` が entity ごとに
  ディスパッチ）、ASPへのpushは `Woo\Export\AdapterPlatformWriter`
  （`PlatformAdapter::push_*()` へディスパッチ）/ `Woo\Export\DryRunPlatformWriter`
  （dry-run。アダプタを一切呼ばずmappingsの有無だけでcreated/updatedを判定）が担う。
  `JobManager::process_job()` は `type` が `export`/`dry_run_export` のとき
  この経路へ分岐する（`import`/`dry_run`は既存の`Importer`経路のまま）。
- **「SKU/email突合」の実装範囲**: `cbjp_mappings`（Wooローカルエンティティからの逆引き。
  `MappingRepository::find_remote_id()`/`find_many_by_local_ids()`）の有無のみでcreate/update
  を判定する。ASP側APIへの投機的なSKU/email検索は行わない（D16が`MappingRebuilder`で
  「SKU/email突合は誤リンクの危険があるため不採用」と確定済みの方針と整合）。
- **無料版サンプル選定（エクスポート方向。D15 §10.2 #8）**: `Sync\ExportSampleSelector`
  （`SampleSelector`のWoo向け対称形）が `wc_get_orders()`（日付降順・`wc-checkout-draft`除外）
  で最新10件を起点に、明細の商品（`WC_Order_Item_Product::get_product_id()`で常に親商品IDを
  取得するため、バリエーションは自動的に親商品で1件になる）と購入者（ゲスト=`customer_id 0`は
  除外）を抽出する。フォールバック（受注10件未満）は商品・顧客一覧の先頭ページ（Woo側は日付
  ソートが常に可能なため無条件に新しい順）で補う。永続化キーは `cbjp_export_sample_{platform}`
  （import用 `cbjp_sample_{platform}` とは別オプション）。
- **`cbjp_dry_run_items` はスキーマ変更なし**: export方向のdry-run行は `existing_local_id` 列に
  Wooローカルエンティティの実ID（常に既知）を格納し、`remote_id` 列（`NOT NULL`・
  `UNIQUE(job_id, entity, remote_id)`）は更新時は既存remote_id、新規作成候補時は
  一意性確保のためのプレースホルダ `local:{local_id}` を格納する（`Sync\Exporter::dry_run_row()`）。
- **カテゴリ**: Wooのカテゴリ/タグ自体は独立したexportエンティティにしない
  （`PlatformAdapter`に`push_tag()`は存在せず、`push_category()`はcategory作成可能な
  プラットフォーム向け。ColorMeは`can_create_category=false`）。`Woo\Reader\ProductReader`が
  D19の`category_map`（Woo側カテゴリID→ASP側カテゴリID）を商品ごとに解決し、未マッピングの
  カテゴリは警告（`WarningCode::CATEGORY_MAP_UNRESOLVED`）付きで除外する。Wooのタグはv1.0では
  転送しない（`tag_map`が存在せず、ColorMeは groups をカテゴリとしてのみ扱うため）。
- **エンティティの絞り込み**: `JobManager::EXPORT_ENTITIES_WITH_READER`（PR-A時点は`product`
  のみ）で、Readerが未実装のentityは要求されても対象にしない。customer/order/stock/coupon用の
  Readerを追加するPR-Bで、`can_create_category`/`has_coupons && can_create_coupon`/
  `can_update_customer`/`can_create_order`によるcapabilityベースの絞り込みも合わせて追加する
  （PR-A時点でこれを含めるとPHPStanが到達不能コードとして検出するため見送った）。
- **本番書込み警告（D17）のサーバー側担保**: `POST /runs`（`type=export`）は
  `acknowledge_production_write`（`true`/`'1'`/`'true'`のみ受理。フェイルクローズ）が
  真であることを要求し、無ければ400。dry-run（`dry_run_export`）には適用しない。
- **受注（D19の申し送り。PR-Bで実装）**: `payment_map`/`shipping_map`の逆引きの曖昧性解決は
  スコープ外のまま据え置き、`Woo\Reader\OrderReader`はWoo側の生コード（決済ゲートウェイID・
  配送方法ID）のままCanonicalOrderへ載せ、ASP側コードへの解決はアダプタの`push_order()`実装
  （E2-3）に委ねる。詳細は下記「エクスポート方向の実装（E2-2 PR-B）」参照。

#### エクスポート方向の実装（E2-2 PR-B）

PR-A（product）に続き、`Woo\Reader\CustomerReader`/`OrderReader`/`StockReader`/`CouponReader`
（`JobManager::EXPORT_ENTITIES_WITH_READER`に追加）を実装した。

- **住所はWooネイティブのキーのまま運ぶ**: `Woo\Support\AddressMapper::to_woo()`はColorMeの
  `pref_id`スキームを解釈するインポート方向専用の変換（`PREF_ID_SCHEME_PLATFORMS`で明示的に
  限定）。`CustomerReader`/`OrderReader`は`WC_Order::get_address()`/`billing_*` usermetaが返す
  Wooネイティブのキー（`address_1`/`address_2`/`city`/`state`/`postcode`/`country`等）をそのまま
  `CanonicalCustomer::$address`/`CanonicalOrder::$shipping`へ格納し、ASP固有スキームへの変換は
  E2-3の`push_customer()`/`push_order()`（ColorMeアダプタ）の責務にする（決済/配送方法IDと
  同じD19の原則。アーキテクチャ原則1）。
- **受注明細の`remote_product_id`と`customer_ref`は`cbjp_mappings`で解決する**:
  payment_map/shipping_mapは「ASPだけが知っているコード変換」に限定された申し送りであり、
  商品・顧客参照の解決はプラットフォーム非依存のインフラ（`ProductReader::variants()`が
  既にvariantのremote_id解決に使っている仕組みと同じ）。未解決の場合はその明細/顧客参照だけを
  `null`にして注文自体は構築を続け（`ORDER_LINE_PRODUCT_NOT_EXPORTED`/`ORDER_CUSTOMER_NOT_EXPORTED`。
  `WarningCode::indicates_unresolved_reference()`対象＝商品/顧客が後からエクスポートされれば
  自動的に再試行される）、importの`ORDER_LINE_PRODUCT_UNRESOLVED`（カスタム行として注文自体は
  保存する設計）と同じ方針を踏襲する。
- **StockReaderは商品を販売単位へ展開する**: simple商品は1件、variable商品は
  `get_children()`＋`publish`ステータスの明示チェック（`ProductReader::variants()`と同じ方針。
  **`get_visible_children()`は使わない**——在庫切れバリエーションも
  `woocommerce_hide_out_of_stock_items`設定次第で除外してしまい、在庫がゼロになった瞬間に
  その行がASP側へ在庫切れを伝える手段ごと消えてしまうため）で公開バリエーションのみを
  1件ずつ`CanonicalStock`に展開する。`product_ref`/`variant_ref`が`cbjp_mappings`で未解決の場合、
  `CanonicalStock::$product_ref`が非nullable stringのため有効な値を作れず、新規コード
  `STOCK_PRODUCT_NOT_EXPORTED`を`WarningCode::indicates_export_blocking()`に加えてpush自体を
  止める（checksumはキャッシュされないため商品エクスポート後に自動再試行される）。非公開
  バリエーションはこの行自体を生成しない（`CanonicalStock::remote_id()`がvariant_ref欠落時に
  product_refへフォールバックするため、警告付きで行を出すと親商品の在庫を誤って更新しかねない。
  'product'エンティティ側の`VARIATION_UNPUBLISHED`警告で情報は失われない）。
- **CouponReaderは`fixed_product`型と一部のWooネイティブ制限を`has_unsupported_restrictions`へ
  倒す**: `CanonicalCoupon::$type`は`'fixed'|'percent'`の2値のみで、Wooの`discount_type`の
  3値目`fixed_product`（商品単位の定額値引き）を表現できない。`fixed_cart`（カート単位）へ
  無警告で丸めると割引の効き方が変わる金銭的リスクがあるため、他の「Wooにはあるが運べない
  制限」（商品/カテゴリ/メールアドレス制限・`maximum_amount`）と同じ`has_unsupported_restrictions
  =true`の扱いにする（`CanonicalCoupon`のdocblockが定める三値契約。値は必ず`true`/`false`を
  明示し`null`にはしない）。既存の`WarningCode::COUPON_RESTRICTIONS_UNSUPPORTED`（import方向の
  `CouponWriter`と同じ意味）を読出時点でも積み、`indicates_export_blocking()`に追加して
  E2-3で`push_coupon()`が実装される前から安全側に倒す。
- **サンプリング判定のバグ修正**: `Sync\JobManager::process_export_page()`は元々
  `$this->limits->limit_for($entity)`（エンティティ自身の上限）でサンプリング要否を判定していたが、
  `LimitPolicy::DEFAULT_LIMITS['stock']`は`null`（数値上限なし＝サンプル商品分のみという
  メンバーシップ制限）のため、この基準では`stock`が無料版でも常に全量対象になってしまっていた
  （D15 §10.2の表「在庫: サンプル商品分のみ」に反する）。importの`process_page()`が`stock`を
  `'product'`の上限基準で判定しているのと対称に、`product`/`customer`/`order`/`stock`の
  4エンティティ（`EXPORT_SAMPLE_ID_ENTITIES`）は`product`の上限を基準に`ExportSampleSelector`の
  サンプルID（`order_ids`/`product_ids`/`customer_ids`。`stock`は`product_ids`を受け取り内部で
  バリエーションへ展開）で絞り込むよう修正した。
- **couponは受注サンプルに紐づかない独立上限**: D15 §10.2の表で「クーポン: 最新10件」は
  受注サンプル起点ではなく独立した上限（`LimitPolicy::DEFAULT_LIMITS['coupon']`=10）。importの
  `coupon`処理と同じく、`only_local_ids`は使わず通常のカーソル走査＋`LimitPolicy`だけで
  上限を効かせる（`EXPORT_SAMPLE_ID_ENTITIES`に含めない）。
- **capabilityゲート**: `filter_and_order_export_entities()`に`$adapter`引数を復活させ、
  `customer`→`can_update_customer`、`order`→`can_create_order`、`coupon`→
  `has_coupons && can_create_coupon`のゲートを追加した（`product`/`stock`は対応する
  capabilityフラグが存在しないため常に対象）。`category`は引き続き`EXPORT_ENTITIES_WITH_READER`
  に含めない（Readerを作らない。`category_map`で解決する既存方針のまま）。
- **`AdapterPlatformWriter::write()`のディスパッチ拡張**: PR-A時点は`product`のみだった
  entityディスパッチに`customer`/`order`/`stock`/`coupon`を追加し、対応する
  `PlatformAdapter::push_*()`へ委譲するようにした（`DryRunPlatformWriter`はentity非依存の
  実装のため変更不要）。

**PR-B review-loop（自己レビュー＋独立サブエージェント）で判明し対応した項目**:

- **明細金額は`get_subtotal()`/`get_subtotal_tax()`を使う（`get_total()`/`get_total_tax()`ではない）**:
  Wooの`total`はWoo自身のクーポン計算後（割引後）の金額になるが、`totals.discount`
  （`get_discount_total()`）は明細とは無関係な注文レベルの控除として別に運ぶ契約
  （`Woo\Writer\OrderWriter::apply_totals()`。ColorMeのポイント/GMOポイント値引きが明細価格を
  一切変えないimport方向の契約と対称）。`get_total()`を使うと割引が「明細に織り込み済み」と
  「`totals.discount`で別途控除」の二重に効いてしまう（Wooネイティブのクーポンを使った注文で
  顕在化）。`OrderReader::line_item_amounts()`参照。
- **請求先住所・支払済み状態をexportする**: `Woo\Writer\OrderWriter::apply_addresses()`は
  `extras['customer_snapshot']`から、`apply_dates()`は`extras['paid']`から復元するため、
  空の`extras`のままだとエクスポート→再取込の往復で毎回失われる。`OrderReader::extras()`が
  請求先住所（Wooネイティブのキー。上記の住所方針と同じ）と`null !== $order->get_date_paid()`を
  積む。
- **決済手数料・ギフト包装料等のFee明細を合算する**: `$order->get_items()`は既定で`line_item`のみ
  返し`WC_Order_Item_Fee`を含まないが、`totals.total`（`get_total()`）にはFeeが合算済みで
  反映される。`OrderReader::payment()`が`$order->get_items('fee')`の合計を`payment.fee`へ積む
  （Woo側の汎用Fee明細は種別（決済手数料/ギフト包装料等）を安定に見分ける手段が無いため合算のみ。
  再取込では1本のFee行に統合される簡略化）。
- **`ORDER_TOTALS_INVALID`を`indicates_export_blocking()`に追加**: 注文合計（discount/
  shipping_fee/tax/total）が不正な場合、`0`へフェイルクローズするだけでは「¥0の注文」として
  実際の明細と一緒にpushされてしまう。importの`OrderWriter::validate_totals()`（`WC_Order`に
  一切触れる前に注文全体をskip）と対称に、push自体を止める。
- **参照先が削除済みの場合は再試行警告を出さない**: `ORDER_LINE_PRODUCT_NOT_EXPORTED`/
  `ORDER_CUSTOMER_NOT_EXPORTED`は「商品/顧客が実在するがまだエクスポートされていない」場合のみ
  積む（再エクスポートで解決する見込みがあるため）。参照先が削除済み（`WC_Order_Item_Product::
  get_product()`が`false`、または`get_userdata()`が`false`）の場合は再試行しても解決しない
  終端状態のため警告を積まない（`indicates_unresolved_reference()`のdocblockが定める
  「解決される見込みが無い終端状態は含めない」方針と同じ）。
- **N+1クエリの解消**: `OrderReader`/`StockReader`はページ内の全商品/バリエーション/顧客IDを
  先にスキャンし、`MappingRepository::find_many_by_local_ids()`で一括解決してから各行を組み立てる
  （`ProductReader::variants()`の一括先読みと同じパターン。アイテム毎の`find_remote_id()`呼び出しを
  避ける）。
- **CouponReaderの`has_native_restrictions()`を拡張**: 商品/カテゴリ/メールアドレス制限・
  `maximum_amount`に加え、`get_limit_usage_to_x_items()`（対象商品1点限定）・
  `get_exclude_sale_items()`（セール品除外）・`get_individual_use()`（他クーポンと併用不可）も
  `CanonicalCoupon`が運べない制限のため同じ扱いにする。
- **CouponReaderのカーソル順を新しい順に変更**: D15 §10.2「クーポン: 最新10件」を実現するため
  `orderby=date, order=DESC`にする（`'ID'`昇順＝作成日昇順のままだと、店を長く運営しているほど
  古い（期限切れの可能性が高い）クーポンだけが無料枠を占有してしまう）。
- **CustomerReaderの氏名復元を修正**: ColorMeの氏名は「姓 名」の単一文字列で、
  `Woo\Support\AddressMapper::split_name()`が最初のトークンを`last_name`、残りを`first_name`
  としてWooへ保存する。`first_name . ' ' . last_name`（Western順）で単純に組み直すと姓名が
  入れ替わって復元される（例:「山田 太郎」→復元すると「太郎 山田」）ため、
  `CustomerWriter::apply_extras_meta()`が同時に書く`_cbjp_full_name`（元の文字列そのもの）を
  優先して使う。このメタが無い場合（Woo上でネイティブに作成された顧客等）はWestern順に
  フォールバックする。
- **`Admin\DryRunReportCsv`に`reference_pending_export`ノートを追加**: `ORDER_LINE_PRODUCT_NOT_EXPORTED`/
  `ORDER_CUSTOMER_NOT_EXPORTED`/`STOCK_PRODUCT_NOT_EXPORTED`を`indicates_pending_import()`
  （「先にインポートしてください」）にそのまま混ぜると向きが逆の誤った案内になるため、
  `WarningCode::indicates_pending_export()`を新設し専用の`note`値を割り当てた
  （`CATEGORY_MAP_UNRESOLVED`用の`indicates_mapping_required()`と同じ設計）。

**E2-3への申し送り（E2-2 R1/PR-Bレビューで判明した未解決事項）**:

- **バリエーションのremote_id永続化経路が無い**: `Woo\Reader\ProductReader`は
  `cbjp_mappings`（entity_type `variant`）からバリエーションの既存remote_idを逆引きするが
  （`VariationWriter::sync_one()`が書く行の読出側対称形）、`push_product()`の戻り値
  `Adapters\PushResult`は商品1件につきremote_id 1つしか運べない。E2-3で実際にColorMeへ
  バリエーションを作成できるようになった時点で、個々のバリエーションremote_idを
  `cbjp_mappings`（`variant`）へ書き戻す経路（`PushResult`の拡張、または商品とは別の
  戻り値チャネル）を設計すること。**現状のまま実装すると、バリエーションを持つ商品の
  再エクスポートのたびに新しいバリエーションがASP側に重複作成される**（`variant`のremote_idが
  常に未確定＝空文字列のまま新規作成候補として送られ続けるため）。
- **サンプルクリーンアップは自プラットフォーム未所有のWoo商品を削除できない**:
  `Woo\Tools\SampleCleanup`は`_cbjp_platform`メタで所有権を確認できる実体のみ削除する。
  Woo側で直接作成された商品（インポート由来ではない）をエクスポートしてもこのメタは付与
  されない（`AdapterPlatformWriter`はWoo側を一切書き込まないため）ため、クリーンアップは
  該当商品のmapping行を`unlink`するだけで実体もASP側remote entityも削除しない。この状態で
  再度サンプル選定→エクスポートを行うと、同じWoo商品が「未リンク」として扱われ**ASP側に
  重複した商品が作成される**。エクスポート方向のサンプルクリーンアップを提供する場合、
  「作成元がexportで、対応する削除APIをASPが提供しない」実体はunlinkも含めて拒否する
  （原則4「破壊的操作の禁止」を踏まえ、削除ではなく状況を明示した警告に倒す）等の設計が必要。
- **複数リクエストから成るpushの部分完了契約が無い（E2-2 G3レビューで判明）**: `push_product()`
  が商品本体の作成に続けて画像・バリエーション等の別リクエストを行う実装になった場合
  （E2-3のスコープ）、後続リクエストが失敗・レート制限に達すると、現状の`Adapters\PushResult`
  は「商品自体は作成できたがremote_idを持つ」という部分完了状態を表現できない。アダプタが
  例外を投げれば`Exporter`は1件失敗として扱うが、既に作成された商品のremote_idはどこにも
  記録されないため再実行時に別の重複商品が作られる。かといってremote_idを持つ`PushResult`を
  警告付きで返しても、その警告が`WarningCode::indicates_unresolved_reference()`の集合に
  含まれない限り`Exporter`はchecksumをキャッシュしてしまい、以後の再試行でアダプタ自体が
  スキップされ画像・バリエーション等の欠落が永久に修復されない。E2-3で複数リクエストに
  分割されるpushを実装する場合、部分完了（remote_idは確定したがサブリソースは要再試行）を
  表現できる耐久的な契約を`PushResult`/`Exporter`に設計すること（`docs/review-backlog.md`
  `e2-2-exporter-core/G3-H-partial-completion-contract`参照）。
- **`push_order()`は更新（remote_id指定）を受け付けない（PR-B review-loopで判明）**:
  `PlatformAdapter::push_order( CanonicalOrder $order ): PushResult`は`push_product()`/
  `push_customer()`/`push_coupon()`と異なり`?string $remote_id`引数を持たないため、
  `AdapterPlatformWriter`は`Sync\Exporter`が解決した既存remote_idをpushに渡せない。
  受注の内容変更（ステータス変更等）でchecksumが変わり再pushが必要になった場合、E2-3の
  `push_order()`実装は常に「新規作成」として扱わざるを得ず、ASP側に重複した受注が作られる
  懸念がある。E2-3で受注の更新を許容するASPが出てきた場合、インターフェース変更
  （`push_order( CanonicalOrder $order, ?string $remote_id ): PushResult`。3ASP全ての実装への
  影響を要確認）を検討すること。

#### エクスポート方向の実装（E2-3 PR-A: `push_product()`）

`ColorMeAdapter::push_product()`（PR #43）が上記「E2-3への申し送り」の3項目
（バリエーションremote_id永続化・部分完了契約・要検証#5着手前の実装）に対応した。
`PushResult::$variant_remote_ids` + `ReadItem::$variant_local_ids`でバリエーション単位の
remote_idを`cbjp_mappings`（`variant`）へ書き戻し、部分完了は`WarningCode::
indicates_unresolved_reference()`対象の警告＋`is_retryable_failure()`/`record_failure()`/
`append_failure_warning()`（再試行可能/終端の分類）で表現する。詳細・レビュー履歴は
`docs/reviews/feat/e2-3-push-product/`参照。

#### エクスポート方向の実装（E2-3 PR-B: `push_customer()`）

- **【重大・要対応】ColorMeの`pref_id`はJIS X 0401標準（＝WooCommerceの`JPxx`番号）と並びが
  一致しない（G3ゲートで判明, Codex/Copilot, P1）**: `state_code()`（インポート方向。**Phase 1
  から本番稼働中の既存コード**）は`pref_id`をそのまま`JP%02d`の数値部分として使っていたが、
  ColorMeのAPIドキュメント（swagger `info.description`に埋め込まれた「都道府県コード一覧」表。
  構造化されたJSONスキーマとしては提供されていないため見落としやすい）はJIS標準と異なる並びで、
  47都道府県中**23件**で番号がずれる（当初「約20件」と記したが、issue #46で全数を確認して訂正。
  `AddressMapperTest`で件数と全単射性を固定。例: ColorMeのpref_id=4は秋田県だがJIS/Wooの4番は宮城県、
  16↔18は福井/富山が入れ替わり、19〜23・25〜30・31〜34・36〜37・43〜44も同様）。都道府県名で
  突き合わせた明示的な対応表`AddressMapper::PREF_ID_TO_JIS_NUMBER`を新設し、`state_code()`と
  新設`pref_id_from_state()`の両方をこの表経由に修正した（`wp eval`でのWooCommerce実測と
  swagger記載を名前で機械的に突き合わせ、全47件をプログラムで検証済み）。
  **本番データへの影響**: この不具合はPhase 1（F1-4/F1-5）から存在するため、影響を受ける
  23都道府県の顧客・受注住所は、本PRマージ以前にインポート済みの実店舗データで**既に誤った
  都道府県が保存されている可能性が高い**（F1-8の実店舗2件を含む）。是正は本PRのスコープ外とし、
  **issue #46 の「県コード修復ツール」（§10.3）で対応した**（再インポートでは checksum 一致スキップに
  阻まれて直らないため、ASP から権威の `pref_id` を再取得して `state` のみを補正する設計）。
- **住所スキーム変換は`Woo\Support\AddressMapper`に対称の逆関数を追加**: インポート方向の
  `state_code()`/`is_overseas()`（ColorMeの`pref_id`1-47=都道府県／48=海外というエンコーディングを
  `PREF_ID_SCHEME_PLATFORMS`でColorMeのみに限定して解釈する）に対し、エクスポート方向で必要な
  `state`→`pref_id`の逆変換を`pref_id_from_state( string $platform, ?string $state, ?string $country
  ): ?int`として同じファイル・同じゲートで追加した。`state`が`JP01`〜`JP47`（`PREF_ID_TO_JIS_NUMBER`
  経由）ならその`pref_id`、一致せず`country`が非空かつ`'JP'`以外なら`48`（海外。swaggerの`pref_id`
  description記載の特別値）、それ以外は変換不能として`null`。「ASP固有スキームへの変換は
  push_customer()の責務」（E2-2 PR-B、上記「住所はWooネイティブのキーのまま運ぶ」参照）という方針は
  「`Woo\Reader\CustomerReader`（プラットフォーム非依存）に変換を持ち込まない」ことが本旨であり、
  既にColorMe専用ゲート済みの`AddressMapper`を`Adapters\ColorMe\Transform\CustomerTransformer`から
  再利用することはこれに反しないと判断した（対称の変換を複製すると2箇所が食い違うリスクを負う）。
- **新規作成必須フィールドの欠落はAPIを呼ばずスキップ**: `POST /v1/customers`は`name`/`mail`/
  `pref_id`/`postal`/`address1`/`tel`が必須（`PUT`は swagger では部分更新で必須項目なしだが、実際は名前と住所が必須。
  R3-1 の実測で訂正し、R3-1c〔issue #100〕で更新も同じくスキップするようにした。§10.2「名前・住所がそろわない顧客の更新と説明の `<script>`・`<style>`（R3-1c）」）。Woo顧客の請求先情報
  から`pref_id`/`postal`/`address1`/`tel`のいずれかを解決できない場合、送信すると確実に422になる
  ため`CustomerTransformer::to_create_payload()`が`null`を返し、`ColorMeAdapter::push_customer()`が
  `PushResult('', OPERATION_SKIPPED, [WarningCode::CUSTOMER_REQUIRED_FIELD_MISSING])`で
  フェイルクローズする（`push_product()`の`requires_hidden_safeguard()`と同じ思想）。`remote_id`が
  空文字列のため`Sync\Exporter`はmappingsへupsertせず、店舗がWoo側の顧客情報を補完すれば次回
  exportで自動的に再試行される。この警告は`PushResult`からのみ発生し`DryRunPlatformWriter`は
  アダプタを呼ばないため、dry-runでは検出できない（`PRODUCT_DETAILS_PUSH_INCOMPLETE`等と同じ
  既知の限界）。
- **新規作成時に`add_member: true`を送る**: ColorMeの`member`（会員登録済みフラグ）が`false`の
  顧客は`CustomerTransformer::transform()`（インポート方向）がWoo顧客として取り込まないゲスト
  スナップショット扱いのため、対称性を保つには作成した顧客も会員登録する必要がある。付けないと
  ColorMe側でログイン不可のゲスト相当になり、往復インポートで再度取り込めなくなる。
- **`extras`の往復ロスは対応しない（既知の制限）**: `Woo\Reader\CustomerReader::to_read_item()`は
  `CanonicalCustomer::$extras`を常に`[]`で構築するため、`fax`/`sex`/`tel_mobile`/
  `answer_free_form1-3`はColorMeにインポート時点で取り込まれていてもexportで送信できない。
  この往復時のデータ欠損の扱いはE2-4「往復E2E」のスコープとする。
- **`address1`は`city`+`address_1`を連結する（R1レビューで判明）**: ColorMeの`address1`は
  swagger上「住所1（**市区町村**・番地）」の1フィールドだが、WooCommerceのJPロケール
  （`WC()->countries->get_address_fields('JP')`で実測確認）は`billing_city`（市区町村・必須）と
  `billing_address_1`（番地・必須）を別フィールドとして扱う。`city`を無視すると、ネイティブWoo顧客
  （exportの主対象）の住所から市区町村がまるごと欠落したまま警告も無く作成されてしまう
  （独立サブエージェントによる敵対的レビューで検出）。ColorMe由来の往復顧客は`AddressMapper::
  to_woo()`が`city`を常に空文字列にする契約のため、連結しても元の1フィールド文字列のまま変わらない。
- **`tel`は装飾文字のみ除去してパターン検証する（R1レビューで判明）**: swaggerの`tel`は
  `pattern: "^[\d-]+$"`（数字とハイフンのみ）だが、Wooの`billing_phone`は空白・括弧を含む表記を
  許容する。明らかに装飾目的の空白・半角/全角括弧のみを除去してからパターン一致を検証し、
  それでも一致しない値（国際番号の`+`付き等）は解決不能としてnullへ倒す（`+`を機械的に除去すると
  国番号が消えた別の番号に化けるため、桁を落とす変換はしない）。新規作成は必須項目のためスキップ、
  更新は省略する。
- **`postal`/`address1`/`pref_id`は3点セットで解決できた場合のみ送る（R1レビューで判明）**:
  `to_update_payload()`が各要素を個別に省略すると、一部だけ解決できた場合（例:
  郵便番号は分かるが都道府県が不明で`pref_id`が省略される）に「新しい郵便番号＋ColorMe側に
  残った古い都道府県・住所」という内部矛盾した住所へ更新しかねない。`address2`は補足情報のため
  この3点セットとは独立に送ってよい。
- **`add_member: true`の通知メール有無は未検証（要検証#17）**: 会員登録時にColorMeが
  パスワード設定案内等を自動送信するかはswaggerに記載が無い。本プロジェクトは移行時の副作用
  （通知メール等）抑止を重視する方針のため、実店舗確認まで要検証のまま残す（コードは
  round-trip整合性のため`add_member: true`を維持）。

#### エクスポート方向の実装（E2-3 PR-C: `push_order()`）

要検証#5（受注POSTの必須項目・決済/配送ID）を`tests/fixtures/colorme/swagger.json`の
`POST /v1/sales`精査で確定: プレミアムプラン契約のショップのみ利用可（`capabilities()`の
`can_create_order`は`is_premium_plan()`で既にゲート済み）、必須は`sale.details`
（各行`product_id`+`product_num`）と`sale.payment_id`のみ、`sale.customer`は丸ごと省略可で
既存顧客IDを渡す場合は他の属性が無視される、`sale.sale_deliveries`は配送不要商品を含む場合を
除き必須（各行の必須項目に`furigana`を含む＝Wooに無いデータ）、`sale.details[].price`を
省略するとColorMeの現在のカタログ価格が適用される、`PUT /sales/{id}`は入金状態・配送情報の
一部しか更新できず内容更新は実質不可能。

- **`PlatformAdapter::push_order()`のシグネチャを他のpush系と統一**: `push_order(CanonicalOrder
  $order): PushResult` → `push_order(CanonicalOrder $order, ?string $remote_id): PushResult`
  （`push_product`/`push_customer`/`push_coupon`と同じ形。D19が確立した「外部アドオンによる
  カスタムアダプタ実装は現時点で存在しないため、確定版インターフェースへの追加による後方互換
  リスクは低い」という判断を踏襲）。`$remote_id`が非nullの場合はAPIを呼ばず`PushResult('',
  OPERATION_SKIPPED, [ORDER_UPDATE_NOT_SUPPORTED])`を返す。PUTが内容更新を実質サポートしない
  ため、再POSTすると重複した受注が作成されてしまう（E2-2 PR-Bレビューで判明した申し送り事項の
  解消）。checksumはキャッシュされないため、この状態は解消される見込みがない終端警告として
  毎回の再エクスポートで出続ける（`CUSTOMER_ACCOUNT_PROTECTED`と同じ位置づけ）。
- **D19の申し送り（`payment_map`/`shipping_map`の逆引きの曖昧性）の解決**: `Woo\Support\
  MethodMap`に`asp_payment_id()`/`asp_delivery_id()`を追加。Woo側の値に対応するASP側キーを
  列挙し、**ちょうど1件**のときのみ解決する（0件=未マッピング・2件以上=複数のASP方式が同じ
  Woo方式に寄せられ曖昧、のいずれも同じ「未解決」として扱い、申し送りが挙げた案のうち
  「複数一致時はフェイルクローズする」を採用）。未解決の場合は受注全体をpushせず、既存の
  `WarningCode::PAYMENT_METHOD_UNMAPPED`/`SHIPPING_METHOD_UNMAPPED`（import方向と共通のコード）
  で警告する。
- **明細行**: `Woo\Reader\OrderReader`が既に解決済みの`remote_product_id`（親商品のremote_id。
  バリエーションは`option1_value_current`/`option2_value_current`で識別）をそのまま使う。1行
  でも未解決なら受注全体をpushしない（`details[].product_id`必須のため部分的な受注を作れない）。
  この場合の警告は`OrderTransformer::to_create_payload()`からは積まない: `Woo\Reader\
  OrderReader`が既にreadItemの警告（`ORDER_LINE_PRODUCT_NOT_EXPORTED`等）へ積んでおり、
  `Sync\Exporter::process_items()`がpush結果と無関係にこれを最終警告へマージするため重複させる
  必要が無い。
- **顧客**: `customer_ref`が解決済みなら`customer.id`のみ送る（swagger:
  「顧客ID以外の顧客情報は無視されます」）。未解決の場合は`extras['customer_snapshot']`
  （Wooの請求先情報）からベストエフォートでゲスト顧客情報を組み立てる。`customer`自体が
  `sale`作成に必須ではないため、解決できない項目は省略し受注全体はブロックしない。
- **配送先（`sale_deliveries`）**: Wooの配送先住所が空（`shipping.address_1`が空）の場合は
  請求先住所（`customer_snapshot`）へフォールバックする（「配送先を別途指定」しなかった場合、
  配送先は空のまま保存され請求先が実際の届け先になる一般的なWooチェックアウトの挙動を踏まえた
  判断）。`postal`/`pref_id`/`address1`/`tel`/`name`のいずれかが解決できない場合は受注全体を
  pushしない（新警告`ORDER_SHIPPING_ADDRESS_INCOMPLETE`）。`furigana`は値を持たないため
  空文字列を送る（swagger上パターン制約・`minLength`指定が無いため有効な値）。**既知の制限**:
  配送不要な仮想商品のみの受注も`CanonicalOrder`が配送要否を運ぶフィールドを持たないため
  同じ経路でスキップされる。
- **明細価格**: ショップの`tax_type`（`shop.json`）が既知の値（`excluded`/`included`）の場合
  のみ、`Woo\Reader\OrderReader`が計算済みの明細単価（`unit_price_excl_tax`/`price`＝税込）を
  明示指定する。省略するとColorMeの現在のカタログ価格が適用されてしまう（swagger）ため、過去の
  受注金額を保持するには本来必要だが、税区分が不明なまま断定的に送ると誤った税基準の金額に
  なりうるため、不明な場合は省略しカタログ価格適用という文書化済みのフォールバックに委ねる。
  `push_order()`専用に`ColorMeAdapter::order_tax_type()`（`shop.json`を遅延取得・インスタンス
  単位でキャッシュ）を新設した。import方向の`OrderTransformer::transform()`はこのデータを
  使わないため、全fetch系メソッドが経由する共有インスタンス`order_transformer()`には持たせず
  独立させている（`order_transformer()`に混ぜて全呼び出しで無条件に`shop.json`を叩くと、
  importのみを行うジョブでも不要なAPIコールが発生する）。
- **在庫二重引き当ての防止**: `POST /sales.json?reserve_stocks=false`を指定する。過去のWoo
  受注を複製するのであって新規注文ではないため、既定（在庫引き当て）のままだとColorMe側の
  現在庫を実売と無関係に消費してしまう（在庫同期は別タスクの`push_stock()`の責務）。
- **共有ロジックの抽出（移動のみ、ロジック変更なし）**: `CustomerTransformer::
  address_payload()`の本体（postal/pref_id/address1/address2の3点セット判定＋海外住所の地域
  付記＋`join_address1()`）を`Woo\Support\AddressMapper::to_asp_address_payload( string
  $platform, array $woo_address ): array`へ移設し（`pref_id_from_state()`と同様`$platform`
  引数を持つプラットフォーム非依存の形）、`CustomerTransformer`・新設`OrderTransformer`
  （配送先・ゲスト顧客変換）で共有する。`CustomerTransformer::normalize_tel()`も
  `Adapters\ColorMe\Transform\Cast::normalize_tel()`へ移設した。「対称の変換を複製すると
  2箇所が食い違うリスクを負う」というD19 PR-Bで確立済みの方針を踏襲したが、`normalize_tel()`
  自体はR1レビューで`/v1/customers`専用（swaggerのパターン制約`^[\d-]+$`はこのエンドポイント
  にしか無い）と判明したため、`OrderTransformer`はこのメソッドを使わない設計に修正した
  （下記「R1レビューで判明し対応した指摘」参照）。
- **対象外（既知の制限として記録。次PR以降）**: 受注ステータス（paid/delivered/cancelled）の
  事後同期は行わない（作成時点のColorMe既定状態のまま）。将来必要になれば`PUT /sales/{id}`
  （`paid`）・`PUT /sales/{id}/cancel`へのフォローアップリクエストとして別途設計する
  （`push_product()`の複数リクエスト部分完了契約と同種の設計が必要になる）。`push_stock()`は
  次のPRで対応する。
- **R1レビューで判明し対応した指摘**（独立サブエージェントによる敵対的レビュー）:
  - 配送先の`name`/`tel`は住所（`postal`/`pref_id`/`address1`）とは独立に、無い方だけ請求先へ
    フォールバックするよう修正（Wooの配送先フォームは電話番号欄を持たないテーマ・バージョンが
    多く、住所自体は入力されているのに`tel`だけ欠ける一般的なケースで受注全体が不必要に
    スキップされていた）
  - `Adapters\ColorMe\Transform\Cast::normalize_tel()`（`/v1/customers`の`pattern: "^[\d-]+$"`
    専用）を受注方向（`sale_deliveries[].tel`/`sale.customer.tel`）には適用しないよう修正
    （swagger確認: どちらもパターン制約が無く、国際番号等の正当な値を無警告でnullへ丸めていた）
  - `Woo\WarningCode::indicates_export_blocking()`に`ORDER_LINE_AMOUNT_INVALID`/
    `ORDER_LINE_QUANTITY_INVALID`を追加（`Woo\Reader\OrderReader`がフェイルクローズ済みの
    ¥0/捏造数量を、`price`明示指定時にColorMeへ恒久的な金額・数量として送ってしまう
    `PRODUCT_PRICE_INVALID`と同型の金銭的リスクだったため）
  - `sale.details`が0行（商品明細を持たない受注）を`line_items_unresolved`と区別する
    `line_items_empty`フラグ・`WarningCode::ORDER_LINE_ITEMS_EMPTY`を新設（`Woo\Reader\
    OrderReader`は明細0行に警告を積まないため、従来は無警告のまま結果から消えていた）
  - `WarningCode::ORDER_DISCOUNT_NOT_PUSHED`を新設（`POST /v1/sales`のリクエストスキーマに
    割引・クーポン額を運ぶフィールドが無いため、Wooのクーポン値引きが定価のまま送信されることを
    情報提供として警告する。ブロックはしない）
  - 対応を見送った指摘は`docs/review-backlog.md`の`e2-3-push-order/R1-*`を参照（顧客が未export
    のまま受注が先にゲスト扱いでpushされ後から会員紐付けを復元できない設計上の限界=Medium、
    memo/preferred_date/preferred_periodの往復ロス=Low、非数値マッピング値の`(int)`丸め=Low）
- **G1ゲート（Copilot/Codex）で判明し対応した指摘**:
  - 明細単価の端数丸めで合計がずれる（Copilot, High）: `sale.details[].price`は単価×`product_num`
    方式のため、Wooの明細合計が数量で割り切れない場合（例: ¥1000÷3個→単価333.33→整数円333、
    333×3=999）、整数円へ丸めた単価×数量が実際の合計と一致しなくなる。割り切れない場合は
    `price`自体を省略しカタログ価格適用へフォールバックするよう修正（`unit_price_divides_evenly()`）
  - 既存受注スキップ時の情報提供警告欠落（Copilot, Medium）: `push_order()`の早期return
    （既にエクスポート済みの受注のスキップ経路）が`to_create_payload()`を経由しないため、
    割引・手数料付きの受注でも対応する警告が一切積まれなかった。`OrderTransformer::
    has_discount()`/`has_non_representable_charges()`をI/O不要の`public static`にし、
    早期returnからも呼べるよう修正
  - 決済手数料・送料が運べない（Codex, P1）: `sale`のリクエストスキーマ
    （`customer`/`sale_deliveries`/`details`/`payment_id`）には`payment.fee`/`shipping.fee`を
    運ぶフィールドが無い（swagger確認済み）。`ORDER_DISCOUNT_NOT_PUSHED`と同じ理由・同じ設計
    （blocking化すると送料の付くほぼ全ての受注が移行できなくなる）で`WarningCode::
    ORDER_FEE_NOT_PUSHED`を新設し情報提供の警告に留める
  - 受注日時が保持されない（Codex, P1）: `sale`のリクエストスキーマに受注日時フィールドが無い
    （swagger確認済み）ため、ColorMe側の受注日時は`CanonicalOrder::$placed_at`ではなくpushを
    実行した時刻になる。新規作成成功時は常に`WarningCode::ORDER_PLACED_AT_NOT_PRESERVED`を
    付与する（`PRODUCT_IMAGES_NOT_PUSHED`と同種の、ストアの性質上恒久的に解消しない情報提供警告）
  - 対応を見送った指摘は`docs/review-backlog.md`の`e2-3-push-order/G1-*`を参照
    （dry-runでの割引/手数料警告の非対応=Low/既知の限界、応答喪失時の重複作成リスク=High/対象外
    ＜push_product/customerと同根の限界＞、404での再作成不可=Medium/対象外、create-sale非対応
    決済種別のフィルタリング未実装=Medium/保留）
- **G2ゲート（Codex再依頼）で判明し対応した指摘**: 単価を復元できない場合（tax_type不明・
  数量で割り切れない）の設計を撤回・強化した（P1）。「`price`を省略してカタログ価格へ
  フォールバックする」という上記G1の設計は、それ自体が`remote_id`確定後は再試行されない
  恒久的な金額の食い違いを生みうると判明したため、**単価を復元できない場合は受注全体を
  ブロックする**方針に変更した（`PRODUCT_PRICE_INVALID`と同じ金銭的リスクの構図）。
  `line_price_unresolved`フラグ・`WarningCode::ORDER_LINE_PRICE_UNRESOLVED`を新設。あわせて
  `ORDER_LINE_TAX_CLASS_UNSUPPORTED`（非課税・送料のみ課税等、`CanonicalOrder`が表現できない
  税区分）を`indicates_export_blocking()`へ追加した（既存コードの見落とし）。対応を見送った
  指摘（ワイルドカードバリエーションの選択値喪失=High/要検証、`OrderReader.php`が本PRの
  差分範囲外のため）は`docs/review-backlog.md`の`e2-3-push-order/G2-*`を参照。

#### エクスポート方向の実装（E2-3 PR-D: `push_stock()`）

`ColorMeAdapter::push_stock()`（issue #47）がE2-3の最後のピースを実装した。ColorMeには在庫専用の
書込みエンドポイントが無い（`GET /v1/stocks`はGETのみ）ため、商品/バリエーション更新APIを叩く。
単純商品・管理外バリエーション（skip）は1リクエストのみだが、管理中バリエーションは2リクエスト
（詳細は下記「バリエーション」項）:

- **単純商品**（`variant_ref === null`）: `PUT /v1/products/{id}`に`stock_managed`
  （`quantity !== null`）を常時送り、管理中（`quantity`が非null）のときのみ`stocks`（絶対値）を
  加える。`Adapters\ColorMe\Transform\ProductTransformer::base_payload()`が`push_product()`で
  既に確立している規約と同じにした。`increment`は使わない: 再送のたびに二重加算されると
  checksum一致スキップによる冪等性（再エクスポートで重複がゼロになる契約）と相容れないため
  （`push_order()`が`reserve_stocks=false`で二重引き当てを避けるのと同じ理由）。
- **バリエーション**（`variant_ref !== null`）: バリエーションの`stocks`（絶対値）をPUTする**前**に
  `PUT /v1/products/{id}`へ`{product: {stock_managed: true}}`を送り、商品全体の在庫管理を明示的に
  有効化してから`PUT /v1/products/{id}/variants/{id}`に`stocks`のみ送る（G1ゲート、Codex指摘）。
  swagger実測: `productVariantUpdateRequest`には商品レベルの`stock_managed`に相当するフィールドが
  無く、`stocks`の説明文は「全バリエーションの在庫数が未設定の状態で本フィールドに0以上の値を
  指定すると、他のバリエーションの在庫数は`0`になり、商品全体の在庫数（数値）はバリエーション在庫に
  揃う」とだけ述べていて、`product.stock_managed`という**真偽値フラグ自体**が追随してtrueになるとは
  書かれておらず、「全バリエーションnull」と「`stock_managed=false`」も同一とは限らない
  （review-loop R1〜R2で判明、要検証#19）。この曖昧さに賭けて`stocks`のみを送ると、ColorMe側が
  実際には在庫管理を認識せず値を無視した場合、`push_stock()`は成功を返してしまい`Exporter`が
  checksumをキャッシュして以後再試行されなくなる（Codex指摘: 恒久的な在庫未同期のリスク）。
  商品側`stock_managed`を明示的に`true`へ更新してからバリエーションを送ることで、この曖昧さに
  依存しない設計にした（`ProductTransformer::base_payload()`が`stock_managed`を常時明示送信する
  規約と同じ思想）。要検証#19（実店舗での動作確認）は解消していないが、コードがその答えに
  依存しない形になったため、確認できなくてもリスクは限定的。1リクエスト増える（100req/分の
  レート制限への影響は軽微）。
  `CanonicalStock::$quantity = null`（Wooがそのバリエーションを個別管理していない）はColorMe側へ
  明示的に伝える手段が無いため、`stocks`フィールド自体はnullable=trueだが送信せず、APIを一切
  呼ばずフェイルクローズしてスキップする（新設`WarningCode::STOCK_VARIANT_UNMANAGED_NOT_PUSHABLE`。
  CLAUDE.mdアーキテクチャ原則9）。`stocks`は他のnullableフィールド（`weight`/`option_price`等）と
  違い「`null`で未設定に戻る」という記載が無く、逆に「全バリエーション未設定の状態で1件でも値を
  送ると他バリエーションの在庫が0になる」という副作用のみ記載されているため、未確認の挙動に賭ける
  より安全側に倒した。この警告は`indicates_export_blocking()`/`indicates_unresolved_reference()`の
  いずれにも含めない（`PRODUCT_IMAGES_NOT_PUSHED`と同じ位置づけ。merchantがWoo側で個別在庫管理を
  ONにすれば`quantity`が具体的な数値になり自然に解消するが、確実に解消する保証は無い終端寄りの
  警告）。**既知の限界**: (1) `CUSTOMER_REQUIRED_FIELD_MISSING`等と同じく`PushResult`からのみ
  発生するため`DryRunPlatformWriter`（アダプタを呼ばない）では検出されず、dry-runでは
  「updated」と表示されるのに実行では警告付きでskipされる食い違いが起こりうる。(2)
  ColorMeから往復インポートした「元々管理外（`stock_managed=false`）」のバリエーションは、
  Woo側も`manage_stock=false`のまま両者が一致した正常な状態でも、再エクスポートのたびに
  この警告が繰り返し発火し続ける（実害は無いノイズ）。
- **部分完了契約は不要**: 単純商品/バリエーション（管理外スキップ）は単一リクエストのみのため
  `push_customer()`/`push_order()`と同じく`is_retryable_failure()`/`record_failure()`等
  （`push_product()`の複数リクエスト用パターン）は使わない。バリエーション（管理中）は
  `stock_managed`PUT→`stocks`PUTの2リクエストになったが、1件目は冪等（何度送っても
  `stock_managed:true`のまま）で2件目だけが在庫数を変えるため、2件目が失敗すれば例外が
  そのまま`Sync\Exporter::process_items()`の汎用catchへ伝播しchecksumはキャッシュされず、
  次回exportで2件とも再試行される。部分完了状態が永続することはないため、これも専用の
  部分完了契約は不要と判断した。ColorMe側で商品/バリエーションが削除済み（404）の場合も
  同様に素通しする（stale mapping全般の設計は別途、`e2-3-push-product/G1-stale-mapping-on-404`
  と同根の申し送り）。
- **既知の限界（本PRでは対応せず）**: `docs/review-backlog.md`の`e2-3-push-product/R1-L3`
  （一部バリエーションだけ`stocks`を送ると他バリエーションの在庫がColorMe仕様上0になりうる）は
  `push_stock()`にも引き続き適用される。`Sync\Exporter`がCanonicalモデルを1件ずつpushする設計
  （同一商品の全バリエーションをまとめて1リクエストへ束ねる仕組みが無い）と構造的に結び付いており、
  本PRの差分範囲では解消しない。G1ゲートでCopilotから同種の指摘（Wooで個別在庫管理していない
  =`quantity=null`のため恒久的にpushをスキップするバリエーションが、兄弟バリエーションのpushで
  ColorMe側を0にされたまま二度と補正されない、というR1-L3の具体化）を受けたが、根本対応には
  同一商品の全バリエーションをまとめて扱う設計変更（`Sync\Exporter`の1アイテムずつのpushループの
  見直し）が必要でありPRの差分範囲を大きく超えるため、ユーザー判断のもと本PRでは対応せず
  `docs/review-backlog.md`（`e2-3-push-stock/G1-1`）へ記録し、issue #52として起票した。
  **2026-09-26 追記**: 原因は1件ずつの push ループではなく ColorMe の在庫管理が商品単位しかないことで、束ねても直らないと判明した。
  混在した商品のエクスポートを止める方針に決定（D22。下記「在庫管理が混在する variable 商品のエクスポート（D22）」）。
- `PushResult::$remote_id`は`CanonicalStock::remote_id()`（`variant_ref ?? product_ref`）を返す
  （`cbjp_mappings`の`stock`エンティティ行のキーとして使われる。E2-2 PR-Bで追加済みだった同メソッドの
  唯一の呼び出し元）。
- `Woo\Export\AdapterPlatformWriter::write()`の`stock`ディスパッチ・`JobManager`の配線（E2-2 PR-B）は
  変更なし。`JobManagerExportTest::test_export_pushes_stock_when_supported`等が`MockPlatformAdapter`
  経由で既にこの配線をテスト済みのため、ColorMe固有の新規結合テストは追加していない。

#### エクスポートUI + 往復E2E（E2-4）

バックエンド（E2-2/E2-3）は完成済みのため、本タスクはフロントエンドのみ（PHP変更なし）。
`src/tabs/ExportTab.tsx`（E2-1のマッピング設定カード）へ、`ImportTab.tsx`と同型の実行フロー
（エンティティ選択・dry-run/実行・進捗ポーリング・結果レポート）を追加した。

- **エンティティ選択**: `availableExportEntities()`が`JobManager::filter_and_order_export_entities()`
  と同じ条件（product/stockは常時、customer=`can_update_customer`、order=`can_create_order`、
  coupon=`has_coupons && can_create_coupon`）をフロント側でミラーする。category/tag/reviewは
  exportエンティティとして選択肢に出さない（`category_map`が別途担う）。
- **`VerificationReport`は使わない**: `GET /runs/{run_id}/verification`は`type=import`専用
  （§6のREST表・`RestController::get_run_verification()`参照）で、exportのrunに対しては400を返す。
- **本番書込み警告（D17）の実装方式**: §10.4は「実行前に確認ダイアログで...」とだけ規定しており
  実装手段までは指定していない。`ImportTab.tsx`は`window.confirm()`（ネイティブ確認ダイアログ）を
  使っているが、ExportTabでは常時表示の`Notice status="warning"`＋その下の`CheckboxControl`
  （「本番データへの書込みであることを理解した」）で「Run export」ボタンをゲートする方式にした。
  理由: `.claude/rules/frontend.md`がネイティブ`window.confirm()`はブラウザ拡張の自動操作
  （Claude in Chrome等）やE2Eツールからのクリックでレンダラーをブロックしフリーズしうると
  既に指摘しており、本タスク自体が下記の実機E2Eをブラウザ自動操作で行う必要があったため。
  チェック済みで「Run export」を押すと、`POST /runs`のJSONボディへ
  `acknowledge_production_write: true`を含める（サーバー側の検証は既存の
  `RestController::acknowledged_production_write()`をそのまま利用。E2-2で実装済み）。
  ImportTab側の`window.confirm()`は本PRの差分範囲外のため変更していない（将来的に揃えるかは
  別途判断。`docs/review-backlog.md`の`e2-4-export-ui-e2e/confirm-ux-asymmetry`参照）。
  R1レビュー指摘を受け、チェック済みで実export（`type=export`）を開始できたら
  `acknowledgeProductionWrite`を自動的にfalseへ戻すよう修正した（ImportTabの
  `window.confirm()`は実行のたびに確認を取るのに対し、チェックボックスは状態として
  残り続けるため、外さないと2回目以降の本番実行が確認なしでクリック1回になってしまう）。
- **警告バナー**（`docs/review-backlog.md`の`e2-2-exporter-core/R1-M8`を解消）: 実行(非dry-run)の
  exportで対象アイテムが全てskipped/warnedになってもジョブは`STATUS_COMPLETED`のまま終わり
  個別警告はどこにも永続化されない（`Sync\Importer`と同じ既存方針）。export結果カードで
  `created + updated === 0`のcompletedジョブを検出し、対象エンティティを列挙する警告バナーを
  表示するようにした。条件は`warned > 0`ではなく`warned === processed`（処理した全件が警告）
  にしている: `Sync\Exporter::process_items()`はchecksum一致でskipした場合でも読出時点の
  非ブロッキング警告があれば`warned`を加算し続けるため（「解消済みに見えてしまう」のを防ぐ
  既存仕様）、`warned > 0`のままだと健全な冪等スキップ（一部アイテムだけ残留警告あり）でも
  誤検出しうる（R1レビュー指摘、独立サブエージェントが検出）。
- **capabilityゲート**（`docs/review-backlog.md`の`e2-2-exporter-pr-b/R1-L6`を解消）:
  上記`availableExportEntities()`により、`can_create_order=false`等のプラットフォームでは
  そもそも対応するチェックボックスを出さない（実機E2E時、非プレミアムのColorMeテストショップで
  Order/Couponのチェックボックスが出ないことを確認済み）。

##### 実機E2E（ColorMeテストショップ、2026-09-23）

セッション内メモ（developer.shop-pro.jpの資格情報。gitにはコミットしていないローカル
`colorme.env`と、アシスタントのローカルmemory）が指すColorMe資格情報（旧アプリ・旧テストショップ）
が別ログインに紐づいていたため、同一セッション内でdeveloper.shop-pro.jpに新規プライベートアプリ＋
新規テストショップを作成し直して接続した（旧テストショップの実データは今回引き継げていない）。
同ショップは非プレミアムプラン（`can_create_order=false`/`can_create_coupon=false`）のため、
**受注・クーポンのexportは検証できていない**（Export UI側でチェックボックスが正しく非表示になる
ことのみ確認）。製品・顧客・在庫で以下を確認した:

1. dry-run export（全量走査、無料版でも上限なし=D15）→ Preview export resultsのcompleted表示・
   CSVレポートDLが機能することを確認。
2. 実export → 対象7件中2件のみ実際に作成された。原因は無料版の上限（`LimitPolicy::
   DEFAULT_LIMITS['product']=50`。未到達）ではなく、`Sync\ExportSampleSelector`が受注起点
   （wp-env環境に残っていた過去セッション由来のHPOSプレースホルダー受注10件）でサンプルを
   選定し、その明細に含まれていた3商品のうち1件（variable商品で全バリエーション非公開＝
   dry-runでも`skipped`だった商品）が対象外だったため（`LimitsUpsellNotice`の「Products: 7
   found, 2 migrated...the remaining 5 require the Pro version」という文言は、この「限定的な
   サンプルにしか含まれず未走査」なケースを「上限到達」と区別できておらず、実態を正確に
   表していない。`docs/review-backlog.md`の`e2-4-export-ui-e2e/limits-upsell-message-misleading`
   参照。`LimitsUpsellNotice.tsx`はE2-1由来の共有コンポーネントで本PRの差分範囲外のため
   修正はしていない）。ColorMe側APIを直接叩いて商品2件・顧客1件が実際に作成されたことを確認
   （price/stock_managed/pref_id/postal/telが正しく変換されている）。同じrun内でproductジョブが
   先に完了しmappingが確定するため、後続のstockジョブが新規作成直後の商品へも正しくpush
   （updated）できることを確認した。
3. Importタブで再取込み（ImportTab側の`window.confirm()`はブラウザ自動操作を止めうるため、UI
   クリックではなく`rest_do_request()`を`wp eval-file`から直接呼んでrunを起動し、Action
   Schedulerジョブは`wp action-scheduler run`で手動実行した。ImportTab自体のUI変更は無いため
   本タスクの検証対象外）→ 既存mappingにより**重複作成されず更新**（Woo側の商品/顧客総数は
   往復前後で不変）。価格・在庫管理フラグ・郵便番号・pref_id・電話番号は正しく往復した。
   **既知の制限を実地で再現**: `Woo\Reader\CustomerReader::name()`のdocblockが記載するとおり、
   ColorMe由来ではないネイティブWoo顧客（`_cbjp_full_name`メタが無い）をexportすると
   `first_name . ' ' . last_name`（Western順）の文字列がColorMeの単一`name`フィールドへ送られ、
   再importの`AddressMapper::split_name()`（「姓 名」＝Japanese順を前提に分割）で姓名が入れ替わる。
   今回のE2Eテスト顧客（wp-env上でネイティブに作成）がまさにこのケースで再現したが、既存の
   設計判断どおり対応不要（修正は本タスクの範囲外）。
4. 再exportで冪等性を確認: 顧客・在庫はchecksum一致で`Skipped`（`Sync\Exporter::process_items()`）。
   商品はカテゴリマッピング未設定（テストショップにマッピング可能なカテゴリを用意していない）
   により`category_map_unresolved`警告が`WarningCode::indicates_unresolved_reference()`へ
   該当し続け、設計どおりchecksumをキャッシュせず`Updated`を繰り返すが、ColorMe側の商品数は
   API確認で2件のまま**重複ゼロ**（既存remote_idへのPUTのみ）。Phase 2完了チェック
  （`docs/10-tasks.md`）の「重複ゼロ」は満たすが、「checksum一致skip」は商品エンティティでは
   未確認のまま（カテゴリマッピング設定後に別途確認可能。本PRの差分範囲外）。

#### エクスポート方向の実装（価格の税込正規化とバリエーションのセール価格。issue #59 / #60）

E2-3 PR-A のゲートで「差分範囲外・要判断」として保留していた High 2件（`docs/review-backlog.md`
`e2-3-push-product/G1-out-of-scope-prices-include-tax` / `G2-variation-sale-price`）を v1.0 公開前に解消した。

**Canonical の価格契約は「消費者が実際に支払う税込金額」**（`CanonicalProduct` docblock）。`ProductReader`
がこの契約に正規化してから渡し、`ProductTransformer::to_push_amount()` は従来どおり「入力は税込」前提で
ColorMe の税設定（`shop.tax_type`）へ逆算する。

1. **税込への正規化（`Woo\Support\TaxInclusivePrice`）**。換算するのは「税計算ON（`wc_tax_enabled()`）・
   税抜入力（`! wc_prices_include_tax()`）・課税商品（`is_taxable()`）」のときだけ。税計算OFF
   （フレッシュな WC の既定）は入力価格＝消費者の支払額＝税込なので無変換が正しく、旧仕様の
   `PRICES_INCLUDE_TAX_DISABLED` は既定環境でも発火する誤検知だった（Reader では出さなくなった）。
   - **`wc_get_price_including_tax()` は使わない**: 税抜モードでは顧客ロケーション依存の `WC_Tax::get_rates()`
     を使い、顧客が居ない／ロケーションが空の文脈（WP-CLI・Action Scheduler）では税率0件になり価格が
     **無変換で返る**（実測: 基準所在地JP・税率10%登録済みでも `wc_get_price_including_tax(999)` が `999.0`、
     `get_rates()` が0件・`get_base_tax_rates()` が1件）。決定的な店舗の基準所在地の税率
     （`WC_Tax::get_base_tax_rates( $product->get_tax_class() )`）で、WC 本体の税抜分岐と同じ計算・丸め
     （`calc_tax` → `woocommerce_tax_round_at_subtotal` → `wc_round_tax_total` → `NumberUtil::round`
     〈`wc_get_price_decimals()`〉）を行う。`TaxInclusivePriceTest` が `woocommerce_get_tax_location` で
     ロケーションを基準所在地へ固定して `wc_get_price_including_tax()` との一致を検証し、丸めのドリフトを検出する。
   - **フェイルクローズ**: その税区分に税率が1件も無ければ Woo は課税しない（入力価格＝支払額）ので無変換。
     税率は登録済みだが基準所在地に合致するものが無い場合は、どの税率で課税されるか（顧客の配送先次第）を
     決められないため換算不能とし、`PRODUCT_PRICE_INVALID`（バリエーションは `VARIATION_PRICE_INVALID`）＋
     `PRICE_TAX_BASIS_UNRESOLVED` を積む（後者は `indicates_export_blocking()` 対象）。
   - 換算を適用したときだけ、情報警告 `PRICES_CONVERTED_TO_TAX_INCLUSIVE` をページ（Reader インスタンス）
     につき1回積む（非 blocking。dry-run レポートで売価が Woo の入力値と異なる理由を示す）。
   - 変換対象は単純商品の定価・セール価格、variable 親の代表値（最安定価。親の税区分で換算。バリエーション
     間で税区分が異なる場合の厳密さは対象外）、各バリエーションの定価・セール価格。
2. **バリエーションのセール価格**。`ProductReader::variants()` が、`is_on_sale( 'edit' )` かつ単純商品と同じ基準
   （数値・0超・通常価格未満）を満たすバリエーションにだけ、税込の `sale_price` キーを載せる。
   `CanonicalProduct::$variants[]` は自由形式の連想配列なのでコンストラクタは変えていない（外部アダプタ互換）。
   **セール中でなければキー自体を出さない**: 全 variable 商品の checksum が変わり、次回 export で（多段階
   リクエストの重い）全件再 push になるのを避けるため。`ColorMeAdapter::push_variant_details()` はセール中
   `option_price`（販売価格）＝実売価格・`option_market_price`（定価）＝通常価格を送る（swagger
   `productVariantUpdateRequest`。商品レベルの `sales_price`/`price` と同じ意味論）。実売価格を換算できない・0以下・通常価格超え
   （通常価格を換算できず比較できない場合を含む。`CanonicalProduct::$variants` は外部アダプタ境界のため
   `ProductReader` の検証を通っている保証が無く、`to_push_amount()` は 0・負値・定価超えをそのまま通す。PR #61
   Copilot G3-1）の場合は、0円・不正な販売価格や通常価格を販売価格として送らないよう価格フィールドを両方省く（「省く」は ColorMe 側の既存値を保持する。`null` 明示で未設定に戻す案は採らない: 実フィクスチャ `product_option_detail.json` のとおり `option_price: null` のバリエーションは商品レベルの価格〈variable 親では最安の定価〉にフォールバックし、より高いバリエーションの売価を誤るため。PR #61 Copilot G2-1）。
   - 既知の限界: セール外の送信では `option_market_price` を省略する（単純商品の `push_prices()` と同じ）ため、
     セール終了後に ColorMe 側へ古い定価が残る（販売価格は正しい。表示上の差のみ）。
   - **セール終了日（`date_on_sale_to`）は運べない**: `CanonicalProduct` は終了日を持たず、エクスポートは継続同期
     しない（D14）ため、期間限定セールの実売価格が ColorMe では恒久的な販売価格になり、Woo 側でセールが終わっても
     値引き価格のまま残る。単純商品・バリエーションとも、終了日付きのセールを送るときは情報警告
     `SALE_END_DATE_NOT_PUSHED`（バリエーションは `:{variation_id}`。非 blocking。blocking にすると期間限定セール中の
     商品を一切エクスポートできない）で dry-run レポートに知らせる。variable 親の代表値がセールを焼き付けない
     （最安の定価を使う）のとは対照的だが、バリエーション単位の実売価格はセール中の売価そのものなので送る
     （#60）。単純商品は本 PR 以前から同じ構図で、警告だけが新しい。
   - **`option_market_price` の税基準（R3-1 で確認、2026-10-05）**: `option_market_price` の税基準が `option_price` と同じ（`shop.tax_type=excluded`
     なら税抜）という仮定は、**表示からは判定できない**と分かった。テストショップ（税抜表示）で、セール中のバリエーションを export すると
     `option_price=2500`・`option_market_price=3000`（どちらも税抜換算）で作成されたが、管理画面のオプション一覧（項目・型番・在庫数・適正在庫数・
     販売価格・会員価格）にもストアフロントの「オプションの値段詳細」にも、バリエーションの定価は表示されない。API にも税込の対が無い。
     販売価格は税抜換算で正しく表示される（2,500 → 2,750 円）。表示に使われない値なので、仮定が違っていても店舗・購入者に見える影響は無いと判断し、
     要検証を閉じる（商品レベルの `price` が `sales_price` と同じ基準であることは要検証#16 で確認済み）。
3. **checksum への影響**: 税抜入力店舗の商品（価格が変わる）とセール中バリエーションを持つ商品は、リリース後の
   初回 export で canonical が変わり1回だけ再 push される（意図どおり。ColorMe 側は既存 remote_id への PUT で重複しない）。

**インポート方向は対象外**（本項目の鏡像として残る）: `Woo\Writer\ProductWriter` は ColorMe の税込額を
`regular_price` へ書く。税計算ON・税抜入力の Woo 店舗ではチェックアウト時に税が上乗せされて二重課税になりうる
が、docs/03 §5「税の扱い」（取込み方向は警告のみ・自動変更しない）のとおり `PRICES_INCLUDE_TAX_DISABLED` の警告に留めている
（`docs/review-backlog.md` `e2-2-exporter-core/G3-M-tax-basis-conversion` の残り）。

#### エクスポートの重複作成防止（D21）

**背景**: エクスポートは ColorMe 側への作成（POST）が成功した後で `cbjp_mappings` に remote_id を書く。
その間で処理が途切れると mapping が残らず、次回の export が同じ Woo 実体を「未エクスポート」として再度 POST し、
ColorMe 側に**重複**を作る。原則4によりプラグインはリモートを削除しないため、重複は店舗が手作業で消すしかない
（受注の重複は売上集計も狂わせる）。`docs/review-backlog.md` の `e2-3-push-product/G1-duplicate-on-retry`・
`e2-3-push-customer/G1-duplicate-on-retry`・`e2-3-push-order/G1-duplicate-on-retry`（いずれも High）をまとめて扱う。

**途切れ方の分類**（コード確認 2026-09-26）:

| 分類 | 場面 | remote_id | 対策 |
|---|---|---|---|
| (1) 作成後の中断 | `push_product()` の POST 成功後、追いPUT・バリエーション・画像のいずれかで `RateLimitExhaustedException` が再スローされる（他の失敗は既に警告へ畳んで `PushResult` を返している） | **アダプタは知っている** | A: 例外で remote_id を運ぶ |
| (2) 作成結果が不明 | 作成 POST のタイムアウト・通信断・5xx（`HttpClient` は POST を自動再送しない）、2xx だが応答に id が無い（`RuntimeException`）、POST 後・mapping 書込み前の PHP プロセス停止 | **誰も知らない** | B: 送信中の印（push intent） |

当初は「商品・顧客は remote_id を運べば直る」と整理していたが、顧客の重複シナリオ（2xx で id 欠損）と商品・受注の
POST 自体の応答喪失は (2) に属し、remote_id を運ぶ方式では直らない。そのため B は受注に限らず、**作成（POST）を伴う
全エンティティ**に適用する。

##### A: 作成後の中断で remote_id を運ぶ（`PartialPushException`）

- 新設 `Adapters\PartialPushException`（`RuntimeException` 派生）: コンストラクタ `( string $remote_id, Throwable $previous )`。
  「リモートへの作成（または更新）は確定したが、後続の処理が途中で止まった」ことを表す
- **アダプタの契約**（`PlatformAdapter` の `push_*` docblock と `AbstractPlatformAdapter` に明記）: 作成が確定した後は、
  `RateLimitExhaustedException` を含むあらゆる例外を素のまま外へ出さず、`PartialPushException` に包んで投げる。
  ColorMe は `push_product()` の作成後ブロック（追いPUT〜画像）で再スローしている `RateLimitExhaustedException` を包む
- **`Sync\Exporter`**: `PartialPushException` を捕まえたら、remote_id を **checksum=null** で upsert する（旧 remote_id と
  異なれば既存どおり `delete_one()` で旧行を消す）。次回 export は既存 remote_id への PUT になり、後続処理を再試行する。
  `previous` が `RateLimitExhaustedException` なら mapping を書いた後にそれを再スローし、ジョブを従来どおり `paused` にする。
  それ以外なら1件の部分失敗として `created`＋`warned` に数えて先へ進む。警告コード `PUSH_INTERRUPTED_AFTER_CREATE` を積む
- 空の remote_id を持つ `PartialPushException` は契約違反として (2) と同じ扱い（印を残す）にする（原則8）
- **互換性**: シグネチャは変えない（例外クラスの追加と docblock 上の挙動契約のみ）。D20 の BASELINE は変わらない。
  契約に従わない外部アダプタでも、現状（重複しうる）より悪くはならない
- **実装（R3-0a、issue #72）**:
  - `ColorMeAdapter::push_product()` は、商品本体（POST/PUT）が成功して remote_id が確定した後の処理（追いPUT・
    バリエーション同期・画像）を `finish_product_push()` に切り出し、**作成経路（`$remote_id === null`）のときだけ**その呼び出し全体を
    1つの `try/catch (Throwable)` で `PartialPushException` に包む。6か所の個別 catch（`RateLimitExhaustedException` の再スロー）は
    変えない（包む場所を1か所にして取りこぼしを避ける。個別 catch の外側にある `wp_remote_get()` 等の予期しない例外も拾える）
  - `Sync\Exporter::process_items()` は `PartialPushException` を捕まえ、`PushResult( remote_id, created|updated, [ PUSH_INTERRUPTED_AFTER_CREATE ] )` に
    読み替えて**通常の書込み経路に流す**（旧 remote_id が違えば `delete_one()`、`upsert()`、`indicates_unresolved_reference()` による
    checksum=null、totals、warned を再利用する）。原因が `RateLimitExhaustedException` のときは、そのアイテムの mapping を書き終えた後に再スローする
  - `WarningCode::PUSH_INTERRUPTED_AFTER_CREATE` を `indicates_unresolved_reference()` に登録した（simple 商品には
    `PRODUCT_*_PUSH_INCOMPLETE` のような別の未完了印が無く、これが checksum をキャッシュしない唯一の根拠になる）
  - 無料版の枠は戻さない（リモートに実体ができているため、mapping が `LimitPolicy` の累計に数えられる）
  - **D21 の記述からの差**（実装時に判断した点）:
    1. 更新経路（既存 remote_id への PUT）は包まない。mapping が既にあり、checksum が一致するまで次回も同じ PUT になるため重複しない。
       外部アダプタが更新経路で `PartialPushException` を投げた場合は `updated`＋`warned` に数える（D21 は作成経路の `created` のみ記述）
    2. remote_id が空の `PartialPushException`（契約違反）は、包まれていなかった場合と同じにするため**本来の原因を投げ直す**
       （レート制限ならジョブを一時停止、それ以外は汎用の1件失敗）。R3-0a 時点では印（push intent）が無いので「印を残す」は未実装。
       **R3-0b（B）を実装するときは、この経路を結果表の「その他の例外」行（印を残す）として扱うこと**
- **テスト**: 3経路（追いPUT・バリエーション〔詳細取得・オプション作成・バリエーションPUT〕・画像）の `RateLimitExhaustedException` が
  remote_id 付きで包まれること、`Exporter` が mapping を checksum=null で書いてから再スローすること、再開時に `POST products.json` が増えず
  PUT になること（`ColorMeAdapterTest` の結合テスト）。包む処理・`Exporter` の catch・`indicates_unresolved_reference()` への登録・
  再スローの位置（mapping 書込みの後）・空 remote_id の分岐・更新経路を包まない判断を、それぞれ一時的に壊して落ちることを確認した

##### B: 作成結果が不明な実体を自動では再送しない（push intent）

- **新テーブル `{$wpdb->prefix}cbjp_push_intents`**（`Core\Activator::DB_VERSION` を上げ、`Uninstaller` で削除）:
  `id`・`platform`・`entity_type`・`local_id`・`run_id`・`job_id`・`reason`（`NULL`=送信中／停止、`ambiguous_error` 等）・
  `created_at`・`updated_at`、`UNIQUE KEY (platform, entity_type, local_id)`
  - Woo 実体のメタに持たない理由: 受注のメタ保存は `WC_Order::save()` を通り、`date_modified` の更新・
    `woocommerce_update_order`（Analytics 取込み・Webhook）を毎回発火させる（§10.3 県コード修復ツールの既知の制限と同じ）
  - `cbjp_mappings` にプレースホルダ行（`pending:{local_id}`）を置かない理由: `find_many_by_local_ids()` が remote_id として
    返し `PUT products/pending:12` を送りうるほか、`VerificationReport`・`SampleCleanup`・`LimitPolicy` の全読み手に
    意味の読み替えを強いる
- **対象**: `existing_remote_id === null`（作成経路）かつ dry-run でない、**作成を伴う push**（`?string $remote_id` を取る
  `push_product`/`push_customer`/`push_order`/`push_coupon`）。`push_stock()` は既存実体への更新のみで冪等なため対象外。
  判定はインターフェースのシグネチャから導くためプラットフォーム非依存（原則1）
- **ライフサイクル**（`Sync\Exporter::process_items()`）: 無料版の枠を確保した後、`$writer->write()` の直前に intent を INSERT する
  （既に存在すれば push せず下記「ブロック」へ）。push の結果で次のとおり扱う:

  | push の結果 | intent | mapping |
  |---|---|---|
  | `PushResult`（remote_id あり・created/updated） | upsert の後に削除 | upsert（従来どおり） |
  | `PushResult`（remote_id 空・skipped。例: `CUSTOMER_REQUIRED_FIELD_MISSING`） | 削除（送信していない） | なし |
  | `PartialPushException`（A） | upsert の後に削除 | checksum=null で upsert |
  | 素の `RateLimitExhaustedException`（`RateLimiter::wait()` が送信**前**に投げる） | 削除 | なし（再スローして paused） |
  | `UnsupportedOperationException` | 削除 | なし |
  | `ApiException` で 4xx（429 を含む） | 削除（サーバーが拒否したことが確定） | なし |
  | `ApiException` で 5xx・status 0（通信断・タイムアウト） | **残す**（`reason=ambiguous_error`） | なし |
  | その他の例外（id 欠損の `RuntimeException`・`TypeError` 等） | **残す** | なし |
  | PHP プロセスの停止（POST 後・mapping 書込み前） | **残る**（`reason=NULL`） | なし |

  判定は「送信していない／拒否が確定した」を肯定形で列挙し、それ以外はすべて残す（原則9）。
  POST 前に停止した場合も印は残る（誤って止める側に倒れる。店舗の確認で解除できる）
- **ブロック**: intent が残る実体は、以後の export（dry-run を含む）で push せず `skipped`＋`warned` とし、警告コード
  `PUSH_OUTCOME_UNCONFIRMED` を付ける。checksum 判定より前に行う（mapping が無いので checksum 一致には入らないが、
  判定順を明示する）。dry-run レポート・CSV にもこの警告が並ぶ
- **無料版の上限**: 未解決の intent は `LimitPolicy` の累積カウントに**含める**（作成済みかもしれない実体の分の枠を空けない。
  原則7）。「作成されていなかった」と解除すると枠が戻る。店舗が作成済みのものを「作成されていない」と偽って解除すると
  上限を超えて作れるが、送信結果が不明になる状況を利用者が意図して起こすのは現実的でないため受容する
- **解除 UI と REST**（`manage_woocommerce`。パスの `platform` は `get_url_params()`、`id` はスキーマで整数検証）:
  - `GET /push-intents/{platform}`: 未解決の一覧。種別・ローカルID・表示名（`DryRunLabel`）・Woo 編集画面 URL・送信日時・run。
    店舗が ColorMe 管理画面で探せるよう、受注は受注番号・日時・合計、商品は名前・SKU、顧客はメールを添える
    （画面と REST 応答のみ。`Support\Logger` には出さない）
  - `POST /push-intents/{platform}/{id}/resolve`、`action`:
    - `not_created`: ColorMe に無いことを店舗が確認した → intent を削除。次回 export で改めて作成する
    - `link` ＋ `remote_id`: ColorMe に作成済みだった → `fetch_{entity}_by_remote_id()` で実在と種別を確認し、その remote_id が
      別の実体の mapping に使われていないこと（`upsert()` の `ON DUPLICATE KEY UPDATE` が他の `local_id` を黙って
      付け替えるため）を確認してから、checksum=null で upsert して intent を削除する。
      `PlatformAdapter` に ID 指定取得が無いエンティティ（クーポン）とアダプタが `UnsupportedOperationException` を投げる場合は、実在を確認できないため `link` を 422 で拒否し `not_created` だけを許す
  - Export タブ: 未解決の intent があれば Notice と一覧（解除ボタン、ColorMe ID の入力欄）を表示する。
    `window.confirm()` は使わない（`.claude/rules/frontend.md`）
- **周辺への影響**: `SampleCleanup` は対応するローカル実体を削除・unlink する際に、その実体の intent も消す
  （残すと、存在しない実体の印が一覧に残り続ける）。intent は自動では期限切れにしない（フェイルクローズ）。
  `JobManager` の同時実行ガードとは独立だが、`UNIQUE KEY` により同じ実体を2つの run が同時に作成することも防ぐ
  （#57 の部分的な緩和。ガード全体の原子化は R3-0i〔issue #57〕のロックで行った。§5「同時実行のロックと条件付きの状態遷移」）
- **実装（R3-0b、issue #73）**: バックエンド＋REST を PR #79（PR 1/2、2026-09-27 マージ）、Export タブの解除 UI を
  PR 2/2（本節）で実装した。
  - **D21 の記述からの差**（実装時に判断した点）:
    1. `SampleCleanup` の intent 削除は個別エンティティ単位ではなく、「プラットフォームの mappings を全て処理し
       終えた（全量完了）」タイミングで、そのプラットフォームの未解決 intent を一括で対象にする
       （`LocalEntityLookup` で対応する Woo 実体がまだ実在するか確認してから消す。未解決の intent は定義上
       mapping を持たないため、mapping 駆動の削除ループでは個別に検出できない）
    2. `Sync\LimitPolicy::used()`（＝ `/limits` の `used`）が未解決の intent を含むようになったため、既存の値の
       意味が変わる（`limit - used === remaining` の整合性を保つための変更。D21-B 本文の記述どおり）
    3. 契約違反（remote_id が空の `PartialPushException`。R3-0a の申し送り。または `PushResult` が
       created/updated を主張しつつ remote_id が空）は、原因の例外型（レート制限か否か）によらず
       必ず「印を残す」側に倒す
    4. `Core\Activator::maybe_upgrade()` のようなマイグレーションは `admin_init` 限定のフックに登録しない
       （issue #73 の実装過程で発見。`Core\Plugin::boot()` 自体は `plugins_loaded` から呼ばれるため、そこで
       直接呼ぶ。CLAUDE.md「コーディング規約」参照）
  - **UI（`src/components/PushIntentsPanel.tsx`、Export タブ）**: 実装した REST 応答は当初の設計メモが示していた
    `DryRunLabel` より詳細な `entity_type` 別 `details`（`Woo\Tools\PushIntentPresenter::describe()`。product は
    name/sku、customer は email、order は number/total/currency/date_created、coupon は code）に進化している。
    未解決 intent があるときだけ常時表示の `Notice` + 表を出し（`window.confirm()` は使わない。
    `.claude/rules/frontend.md`）、行ごとに「未作成として解除」（`not_created`）と remote_id 入力＋
    「リンクして解除」（`link`）を提供する。`link` が使えない種別（クーポン等。`LINK_UNSUPPORTED`／422）は
    entity_type によるハードコードで事前に隠さず、行内のエラー表示に委ねる（原則8「アダプタ拡張点の信頼境界」。
    どの entity_type が `link` 非対応かはアダプタ実装依存であり、UI に持ち込むとプラットフォーム固有の制約が
    アダプタ外に漏れる）。一覧はマウント時（platform 切替時）、実 export の実行中フラグが
    true→false に変わったとき（新たに ambiguous な intent が残りうるため）、および解除成功時／
    解除が404（既に解除済み）だったときに取得し直す。取得は `frontend.md` の規約どおり単調増加する
    世代カウンタで古い応答を捨てる。解除成功時にローカルで行を除去するだけの実装は、export完了直後の
    再取得と解除が競合すると新しく増えた intent を取りこぼす回帰を招いたため（G2、Codex 指摘）、
    全ての取得経路を`fetchIntents()`一本に統一し、常に最後に発行した取得だけが反映される設計にした
  - **検証ツール**: `.claude/skills/verify-with-mock-adapter/templates/mu-plugin-mock-adapter.php` に
    `cbjp_verify_seed.push`（`enabled`/`create_failure`）を追加し、`MockPlatformAdapter` の
    `push_products_supported`/`push_others_supported`/`create_push_failure` を配線した（それまでは push 系が
    常に `UnsupportedOperationException` になり、export 方向の実機検証ができなかった）
  - **実機確認（wp-env、2026-09-27）**: mock を非衝突キー（`mockv`）で登録し、`rest_do_request()` 経由で
    push=disabled（`UnsupportedOperationException`）／push.create_failure=`ambiguous_5xx`（intent が残る）→
    以後の export がブロック（`skipped`+`PUSH_OUTCOME_UNCONFIRMED`）→ `GET /push-intents` 一覧に反映→
    `not_created` 解除→再 export で作成される、を確認した。UI（`PushIntentsPanel`）自体は実店舗（`colorme`）に
    手動で intent 行を挿入し、Export タブで Notice・表・編集リンクの表示と「未作成として解除」操作（押下後に
    行が消える、`window.confirm()` によるフリーズなし）を目視確認した。`link` の成功系統一式（実在確認・別実体
    使用中の 409）は `PushIntentResolverTest`／`RestControllerTest` の単体テストで確認済みで、実機では
    `link` の 404（remote 側に存在しない）応答が正しく届くことのみ確認した（mock アダプタに商品を事前登録して
    いないため、実機での link 成功系統は未確認のまま）。検証に使った一時スクリプトはコミットしていない
    （撤去済み。手順は `.claude/skills/verify-with-mock-adapter/SKILL.md`）
- **テスト方針**: `Exporter` は結果表の全行（特に「残す」行）を、分岐を一時的に壊して落ちることまで確認する（CLAUDE.md
  「テスト方針」のミューテーション）。ColorMe は HTTP モックで「POST 成功→追いPUT で `RateLimitExhaustedException`」と
  「POST が 5xx／タイムアウト」を再現する。実機（wp-env）では `/verify-with-mock-adapter` のモックアダプタに
  「作成 POST を 5xx にする」切替を足して、ブロック→一覧表示→解除（`not_created`・`link`）→再 export を確認する

**残る制限**: (2) の後、店舗が確認せずに `not_created` で解除すれば重複しうる（UI の説明文で、ColorMe 側を確認してから
解除するよう促す）。ColorMe には冪等キーが無く、送信前検索（顧客・日時の突き合わせ）は誤判定しやすいため採らない（D21 案C 不採用）。
インポート方向は、作成済みで mapping の無い ColorMe 実体を次のインポートが別の Woo 実体として取り込みうるが、
`link` で解除すれば mapping で突合されて解消する。

#### 在庫管理が混在する variable 商品のエクスポート（D22、issue #52）

**問題**: Woo は在庫管理をバリエーションごとに設定できるが、ColorMe の `stock_managed` は**商品単位のみ**
（variant スキーマに相当フィールドが無い）。`StockDerivation::for_variation()` はバリエーション在庫を
「整数（管理中、または管理外で在庫切れ=0）」か「`null`（管理外で在庫あり）」で表す。整数と `null` が同じ商品に混在すると、
`ProductTransformer::is_stock_managed_for_push()` が商品を `stock_managed=true` にし、`null` のバリエーションは
ColorMe 側で在庫0（swagger: 全バリエーションが未設定の状態で1件に値を入れると他は0になる）＝**売り切れ表示**になる。
`push_stock()` は管理外バリエーションを `STOCK_VARIANT_UNMANAGED_NOT_PUSHABLE` で恒久的に送らないため、以後も戻らない（売り逃し）。
`push_product()` の時点で起きるため、issue #52 に挙げていた「在庫を商品単位でまとめて送る」「送信前に GET で確認する」案では直らない
（送り方ではなく、ColorMe で表現できないことが原因）。

**決定（2026-09-26）**: 混在した商品は**エクスポートを止め**、Woo 側で全バリエーションの在庫管理を揃えるよう警告する。
仮の在庫数を送る案（過剰販売）と商品全体を管理外で送る案（管理中のバリエーションも在庫が効かなくなり過剰販売）は、原則9に反するため採らない。

- **判定**（Woo 側の事実。`Woo\Reader`）: variable 商品のバリエーション在庫（`StockDerivation::for_variation()` の `quantity`。
  ゴミ箱・非公開で除外済みのものは数えない）に**整数と `null` が両方ある**。`ProductReader` は商品の `ReadItem` に、
  `StockReader` はその商品の各バリエーションの在庫行に、警告 `VARIATION_STOCK_MANAGEMENT_MIXED` を積む
- **止めるかどうか**（プラットフォームの能力。`Sync\Exporter`）: 商品単位の在庫管理しか持たない ASP では表現できないが、
  バリエーション単位で持つ ASP では正しく送れる（原則1: ColorMe 固有の制約を Reader に書かない）。`Capabilities` に
  `supports_per_variant_stock_management`（bool）を**末尾に既定値 `false` 付きで**追加し（D20 の値オブジェクト規則。
  既定を `false` にするのは、宣言しない外部アダプタでも安全側＝止める側に倒すため）、`Exporter` は `false` かつ警告ありなら
  push せず `skipped`＋`warned` とする（`indicates_export_blocking()` と同じ位置。dry-run・CSV にも出る）。ColorMe は `false`
- **在庫行も止める理由**: 商品が混在になる前にエクスポート済みだった場合、在庫 push が `stock_managed=true` を明示 PUT し、
  管理外バリエーションは古い値のまま残る。商品と同じ判定で止め、揃えるまで在庫を動かさない
- **案内**: 警告文言は「在庫管理を全バリエーションで有効にする、または全バリエーションで無効にしたうえで在庫状況（在庫あり／在庫切れ）も揃える」ことを示す
  （管理外の在庫切れは `0`、在庫ありは `null` のため、全て管理外でも両者が混ざっていれば混在のまま止まる。R1 独立レビューで指摘）。
  親で一括管理（`manage_stock='parent'`）は既存の `VARIATION_STOCK_SHARED_WITH_PARENT`（在庫0へフェイルクローズ）の対象で本判定とは別
- **テスト**: 混在（整数と `null`）・全て整数・全て `null`・管理外の在庫切れ（0）と在庫あり（`null`）の混在（これも混在として止まる）を
  Reader と Exporter で確認し、判定を一時的に壊すと落ちることを確かめる
- **実装（R3-0c、issue #52）**: 設計どおり。実装時に決めた点:
  1. **判定は 1 つの純関数を両 Reader が共有**する（`Woo\Support\StockDerivation::has_mixed_variation_management()`）。
     母集団は「公開（`publish`）バリエーションすべて」の `for_variation()['quantity']`。価格不正・換算不能で
     `CanonicalProduct::$variants` から外れるバリエーションも数える（`StockReader` は価格を見ないため、両 Reader の母集団を
     揃えて商品行と在庫行の判定が食い違わないようにする。安全側でもある）。`shared_with_parent` は 0（整数）として数える。
     商品行と在庫行の一致は `StockReaderTest::test_product_and_stock_readers_agree_on_mixed_management` で固定
  2. **`StockReader` は mapping 未解決の行（`STOCK_PRODUCT_NOT_EXPORTED`）にも警告を積む**。商品より先に在庫行だけを
     dry-run で見る店舗にも理由が出る（該当行は元々 blocking のため挙動は変わらない）
  3. **`VARIATION_STOCK_MANAGEMENT_MIXED` は `indicates_export_blocking()` に登録しない**（登録するとバリエーション単位で
     在庫管理できる ASP でも止まる）。専用の `WarningCode::indicates_variation_stock_mixed()` を `Exporter` が
     `! $adapter->capabilities()->supports_per_variant_stock_management` と組み合わせて使う。警告が付いたときだけ
     `capabilities()` を呼ぶ。判定位置は既存の blocking 分岐と同じ（D21-B の未解決 intent 判定の後・checksum 一致スキップの前）ため、
     エクスポート済みで内容が変わった商品も止まり、checksum はキャッシュされない（揃えれば次回 export で再送される）
  4. 警告の「案内文言」は `WarningCode` 定数の docblock に書く（警告コードは i18n しない安定キーで、UI・CSV にコードの説明文は
     無い。全コード共通）。CSV の `note` 列には固有ノートを足していない
  5. **実機確認（mock アダプタ `mockv`・push 有効、`JobManager` 経由。2026-09-28）**: 混在商品（管理中5・管理外の在庫あり・管理外の
     在庫切れ）は product 行が `skipped`＋`variation_stock_management_mixed`（エクスポート済みの商品でも）、在庫 3 行も同様に
     `skipped`。実 export でも push されず mapping/checksum は変わらない。在庫管理を全バリエーションで有効にすると止まらなくなる
  6. **実 ColorMe API での確認（テストショップ `ttka3lg60f`・非プレミアム〔regular〕・税抜10%/軽減8%/四捨五入。2026-09-28）**:
     実アダプタ（`ColorMeAdapter`）＋ `Exporter` で、Woo のテスト商品 3 件（control＝全バリエーション管理中・具体値のみ／mixed＝管理中5＋管理外の在庫あり／
     any＝S と Any）を確認した。(a) 実アダプタでの dry-run は control が作成対象、mixed が `variation_stock_management_mixed`、any が
     `variation_any_attribute_unsupported:{id}` で skipped。(b) 実 export は control だけが作成され（ColorMe 側は `stock_managed=true`・在庫 8＝S5＋M3・
     バリエーションの型番/オプション値/在庫が Woo と一致）、mixed・any は push も mapping 作成もされない。(c) エクスポート済みの control の M を管理外に変えて再 export
     すると、商品 3 件・在庫 6 行とも skipped で **ColorMe 側は変わらない**（在庫 5/3・商品 3 件のまま）。(d) mixed の M を管理中に、any の Any を具体値 L に直して再 export すると
     ColorMe に作成された（`stock_managed=true`、mixed は S5/M2、any は S4/L4）。未解決の push intent は 0 件。管理画面（`admin.shop-pro.jp`）の商品一覧でも 5 件を目視確認。
     **未確認**: 受注の Any 明細（テストショップが非プレミアムで `POST /v1/sales` が使えない。mock まで）
  7. **swagger の副作用の実測（テストショップにプローブ用の非公開商品を作って確認。2026-09-28）**: D22 の前提を実 API で確認した。
     (a) `stock_managed` が false でも true でも、全バリエーション未設定（`null`）の状態で `PUT /products/{id}/variants/{vid}` に `stocks: 5` を送ると
     **他のバリエーションは 0 になり、商品全体の在庫は 5 に揃う**（`stock_managed` の値は変わらない）。(b) その後に別のバリエーションへ `stocks: null` を送ると
     **422「商品オプションごとの在庫数を設定する場合は、全てのオプションに設定してください」**＝バリエーション単位で「未設定（管理外）」に戻す手段は無い。
     (c) 商品 `PUT` の `variants[]`（`id` ではなく `option1_value`/`option2_value` で識別）で一部のバリエーションだけ送っても同じ 422 になり、全バリエーションを送ると
     原子的に更新される（商品の在庫は合計）。したがって ColorMe は在庫を「全バリエーションに設定する」か「1 つも設定しない」しか許さず、
     管理中と管理外が混在するバリエーション構成は**仕様として表現できない**（D22 の前提は実測でも成り立つ）。`ColorMeAdapter` が現在バリエーションごとに PUT する送り方は、
     全バリエーションを送り終えるまで未送信のバリエーションが一時的に 0 になる（中断されても再試行される。`docs/review-backlog.md` `r3-0cd-swagger-probe/L1`）

#### 「Any（すべての）」バリエーションのエクスポート（D23）

**問題**: Woo は軸属性を「Any」にしたバリエーション（例: サイズ=Any）を作れる。バリエーション自体の属性値は空文字列で保存され
（`VariationAxisResolver::attribute_value()` は `null` を返す）、購入時に選ばれた値は受注明細のメタにだけ残る。ColorMe の
バリエーションは具体的な値の組ごとに存在し、「Any」に当たる仕組みが無い。現状:
- **商品**: 軸名はあるが値が `null` のバリエーションは、`ColorMeAdapter::axis_map_from_pairs()` がフェイルクローズして
  ColorMe のバリエーションに対応付けられず送られない。商品の残り（他のバリエーション・商品本体）は送られるため、
  **ColorMe 側でバリエーションが欠けた商品になりうる**（全バリエーションが Any なら、選択肢の無い商品になりうる）
- **受注**: `OrderReader::variation_option_values()` は Any の軸を `null` のまま「解決済み」（`true`）として返すため、
  **どの値の注文か分からないまま `push_order()` へ渡りうる**（review-backlog `e2-3-push-order/G2-wildcard-variation-option-values`。
  コード上は確認、ColorMe の実応答は未検証）

**決定（2026-09-26）**: **v1.0 では「Any」を非対応**とし、含む商品と、その明細を持つ受注のエクスポートを止める。
全組み合わせへの展開（ColorMe に S/M/L を作り、受注は明細メタから選ばれた値を読む）は、在庫の割り振り等の新たな判断を伴うため、要望が出てから v1.x で検討する。

- **商品**: `ProductReader` は、軸属性のいずれかが `null`（Any）のバリエーションを1件でも持つ商品に警告
  `VARIATION_ANY_ATTRIBUTE_UNSUPPORTED` を積み、`WarningCode::indicates_export_blocking()` に登録する。「Any」は正規化モデル
  （`CanonicalProduct::$variants` の `option*_value`）で表現できないため、`ALL_VARIATIONS_EXCLUDED` と同じくプラットフォーム非依存に止める
  （capability にしない）。ゴミ箱・非公開で除外済みのバリエーションは数えない
- **受注**: `variation_option_values()` は、存在する軸の値が `null` なら解決不能（`false`）を返す。明細は既存の
  `ORDER_LINE_VARIATION_UNRESOLVED`（blocking）で止まる。商品が止まっていれば通常は mapping が無いため別経路でも止まるが、
  商品が Any を含む前にエクスポート済みだった場合に備えて明細側でも止める
- **案内**: 警告文言は「Any を具体的な値のバリエーションに分けると移行できる」ことを示す
- **実測（実装の最初に行う）**: wp-env で Any バリエーションを持つ商品と受注を作り、(1) バリエーションの属性値が空文字列で保存されること、
  (2) 受注明細のメタに選ばれた値が残ること、(3) 現行コードで商品・受注がどう送られるか（モックアダプタで payload を観測）を確認して記録する
- **テスト**: Any を含む商品が止まること・含まない商品は止まらないこと、Any の明細を持つ受注が止まること。判定を一時的に壊すと落ちること
- **実測結果（2026-09-28、wp-env の dev サイト。修正前のコード）**:
  1. **Any のバリエーションの属性値は空文字列で保存される**（ローカル属性 `attribute_size=""`、taxonomy 属性
     `attribute_pa_…=""` とも。`WC_Product_Variation::get_attributes()` も `['size'=>'']`）。具体値の兄弟は `'S'`
  2. **受注明細のメタに選ばれた値が残る**: `wc_create_order()`＋`add_product( $any_variation, 1, ['variation'=>['attribute_size'=>'M']] )`
     の明細メタは `{"size":"M"}`（`attribute_` を除いたキー）、`get_variation_id()` は Any のバリエーション自身
  3. **現行コードの挙動**（修正前）: `ProductReader` は Any のバリエーションを `option1_value=null` の要素として警告なしで `variants`
     に入れる。`OrderReader` は Any の明細を `option1_value_current=null` のまま**警告なしの解決済み**として返す
     （backlog `e2-3-push-order/G2-wildcard-variation-option-values` の主張どおり。実 API への送信は未確認だが、コード上は
     どの値の注文か不明のまま `push_order()` に渡る）。`ColorMeAdapter::push_product()`（HTTP スタブ）は商品を作成し、
     オプション値には S だけを送り、Any のバリエーションは `axis_map_from_pairs()` で対応付けられずバリエーション更新が行われない
     （`variant_remote_ids=["9001",""]`、警告 `product_variant_push_failed`＋`product_variant_surplus_on_remote`）＝
     **ColorMe 側でバリエーションが欠けた商品になる**（`created` として扱われる）
- **実装（R3-0d、issue #74）**: 設計どおり。実装時に決めた点:
  1. **「Any」の定義は 1 箇所**（`Woo\Support\VariationAxisResolver::has_any_attribute()`。存在する軸のいずれかで
     `attribute_value()` が `null`＝空文字列またはキー欠損）。`ProductReader` と `OrderReader` が共有する。軸が 1 つも無いバリエーションは
     Any ではない（従来どおり）
  2. 商品: 公開バリエーションごとに `VARIATION_ANY_ATTRIBUTE_UNSUPPORTED:{variation_id}`（`VARIATION_UNPUBLISHED` と同形式）。
     `indicates_export_blocking()` に登録（プラットフォーム非依存）。非公開の Any は数えない。Any の要素は従来どおり `variants` に入れる
     （`Exporter` が止めるため送られない。`ColorMeAdapter::axis_map_from_pairs()` のフェイルクローズは多重防御として残す）
  3. 受注: `OrderReader::variation_option_values()` が Any を解決不能（`[null, null, false]`）にし、既存の
     `ORDER_LINE_VARIATION_UNRESOLVED:{variation_id}`（blocking）で受注ごと止まる。具体値の明細は従来どおり解決する
  4. **実機確認（mock アダプタ・`JobManager` 経由。2026-09-28）**: Any を含む商品は product 行が `skipped`＋
     `variation_any_attribute_unsupported:{id}`、Any 明細の受注は `skipped`＋`order_line_variation_unresolved:{id}`。実 export でも
     push されず mapping は作られない。Any を具体値のバリエーションにすると止まらなくなる
  5. **実 ColorMe API での確認（テストショップ。2026-09-28）**: 商品側は D22 の実 API 確認（上記 6.）と同じ実行で確認した。Any を含む商品は実アダプタの dry-run・実 export とも
     止まり ColorMe に作成されず（修正前は Any のバリエーションだけ欠けた商品が `created` になっていた）、Any を具体値 L に直すと作成された（S4/L4）。
     **受注の Any 明細は実 API では未確認**（テストショップが非プレミアムのため。mock まで）

#### プレミアムプラン限定機能のベータ扱い（D24）

**背景**: ColorMe でプレミアムプラン契約の店舗だけが使える API がある。プラグインでこれに依存するのは次の2機能で、
いずれも `ColorMeAdapter::is_premium_plan()`（`shop.json` の `contract_plan`）でゲートしている:

| 機能 | API | ゲート |
|---|---|---|
| 受注のエクスポート | `POST /v1/sales`（`push_order()`） | `Capabilities::$can_create_order` |
| 商品画像のアップロード | `POST /v1/products/{id}/images`（`push_images()`） | `Capabilities::$can_push_images` |

開発に使える ColorMe テストショップは非プレミアムで（E2-4 実機E2E）、**プレミアムプランのテストショップはすぐには用意できない**
（2026-09-26 時点）。この2機能は swagger と HTTP モックのテストだけで作られており、実 API では一度も動かしていない。
クーポンのエクスポートは ColorMe 側が読取専用のため元々非対応（`can_create_coupon=false`）で、本決定の対象外。

**決定（2026-09-26）**: 上記2機能は v1.0 で**ベータ版**として提供する。**実店舗・テストショップでの実テストは行わない**
（R3-1 のリハーサル対象から外す）。プレミアムプランのテストショップが用意できた時点でベータを外すための実テストを行う（v1.0.x 以降）。

- **表示**: Export タブで、受注のチェックボックスと画像アップロードの項目に「Beta」の表示と、「プレミアムプランの実店舗では未検証」である旨の
  説明を付ける。readme（R3-3）の機能一覧・FAQ にもベータであることを書く
- **既定はオフ（明示的に選んだときだけ動かす）**: 受注のエクスポートは ColorMe 側に受注（売上）を作るため、誤作動の影響が大きい。
  プレミアムプランの店舗でも、受注のチェックボックスは既定で**未選択**にする（現状は `availableExportEntities()` が選べるもの全てを既定で選択）。
  画像アップロードは現状、プレミアムプランなら自動で行われるため、**Export タブに「商品画像をアップロードする（Beta）」の選択肢を追加し、
  既定はオフ**にする。オフのときは既存の `PRODUCT_IMAGES_NOT_PUSHED`（情報提供の警告）が出て、画像は ColorMe 管理画面で登録してもらう
- **仕組み（プラットフォーム非依存）**: `Capabilities` に `beta_features`（`array<int,string>`、例: `order_export`・`image_push`）を
  **末尾に既定 `[]` で**追加する（D20 の値オブジェクト規則）。UI はこの配列を見て「Beta」表示と既定オフを決める。ColorMe は両方を宣言する
- **画像アップロードのオン／オフの持ち方**: `push_product()` のシグネチャを変えずに済むよう、プラットフォーム単位の設定
  （`cbjp_export_options_{platform}` の `push_images`）に保存する。**実装で当初案から変えた点（2026-09-29）**: 当初案は
  「`ColorMeAdapter::can_push_images()` を『プレミアムプラン かつ 設定がオン』にする」だったが、`capabilities()->can_push_images` が同メソッドを
  呼ぶため、設定オフの間は能力自体が false になり、UI が「この店舗で画像をアップロードできるか（＝プレミアムか）」を判別できず**オンにする手段が
  無くなる**。そこで能力（`capabilities()->can_push_images` ＝プランだけで決まる）と、実際に送るか（`ColorMeAdapter::should_push_images()` ＝プラン かつ 設定オン）を分けた。
  設定の保存は既存の設定系 REST と同じ規約（`manage_woocommerce`、`platform` は `get_url_params()`、真偽値は `is_bool()` で検証）
- **既知の制限と、その解消（2026-09-29）**: 画像をオフのままエクスポートした商品は checksum がキャッシュされるため、後で画像をオンにしても、
  商品に変更が無ければ画像は送られない。**`Sync\Exporter` が、画像アップロードがオンの間だけ商品の checksum に印（`CHECKSUM_SALT_IMAGES`）を混ぜる**ことで
  解消した（オンにすると checksum が変わり、次の export で更新として再送される）。オフの checksum は従来と同一なので既存の mapping は影響を受けない
  （`.claude/rules/sync-export-tools.md` の「入力側へ名前空間を混ぜ込む」と同じ方式）
- **テスト方針**: 実 API の代わりに HTTP モックと `/verify-with-mock-adapter`（`capabilities()` でプレミアム相当を宣言する）で、
  ベータ表示・既定オフ・オンにしたときの送信を確認する。D21-B（#73）・D23（#74）の受注側の変更も同様にモックだけで確認する

**実装（R3-0j、issue #75。2026-09-29）**:

1. **`Capabilities::$beta_features`**（末尾・既定 `[]`。識別子は `BETA_ORDER_EXPORT='order_export'`・`BETA_IMAGE_PUSH='image_push'`）。`to_array()` が非文字列・空文字・重複を落とし
   `array_values` で詰め直した素の配列にして返す（外部アダプタの契約違反でも UI の `.includes()` が落ちない。原則 8）。`ColorMeAdapter::capabilities()` は両方を**静的に**宣言し、
   項目を出すかどうかは従来どおり `can_create_order` / `can_push_images`（プラン）が決める（非プレミアムでは受注・画像とも出ない）。
2. **`Support\ExportOptions`**（オプション `cbjp_export_options_{platform}`、autoload なし）。`push_images_enabled()` は **`true ===` の厳密比較だけ**をオンとする（`'true'`・`1`・配列・`stdClass`・欠損は全てオフ）。
   `cbjp_settings_{platform}`（マッピング。REST が 4 つのマップキーだけを全置換で書く）とは別オプションにした（同居させるとマッピング保存で本設定が消える）。
3. **REST `GET/PUT /settings/export-options/{platform}`**: `manage_woocommerce`・`platform` は `get_url_params()`。PUT は `push_images` を **`is_bool()`** で検証（`"true"`・`1`・配列・`null`・欠損は 400 で保存済みの値を変えない）。
   **オン（true）にできるのは `capabilities()->can_push_images` が true の platform だけ**（能力の無い店舗で先にオンを保存すると、後でプランが変わったときに誰も選ばないまま画像が送られ始める。オフは常に可）。
   進行中の run があれば 409（実行の途中で設定が変わると、同じ run の中で商品ごとに画像の扱いが割れる。判定と保存は `R3-0i`〔issue #57〕のプラットフォーム単位のロックで囲む）。
4. **Exporter の checksum の印**: 上記「既知の制限と、その解消」。product エンティティかつ「`ExportOptions::push_images_enabled()` かつ `capabilities()->can_push_images`」のときだけ、`hash('sha256', CHECKSUM_NAMESPACE . 'images:' . canonical_json)`。
   オフのときの値は従来と同一。オンにすると次の export（dry-run の判定も）で更新として再送され、オフに戻すと印が外れて一度だけ画像なしの更新として再送される。
5. **Export タブ**: ベータの受注は既定で未選択・ラベル「Orders (Beta)」・説明付き。能力があるときだけ「Options」に「Upload product images (Beta)」（既定オフ。変更のたびに即保存し、取得前・保存中・run 実行中は無効）。
   取得・保存とも既存の `platformGenerationRef`（世代カウンタ）で古い応答を捨てる。
6. **外部アダプタ向けの契約と画像の上書き（R1 の独立レビュー指摘。2026-09-29）**: 設定 `push_images` は汎用（`ExportOptions`）なので、`Capabilities::$can_push_images` が true の**全アダプタ**が、
   画像を `ExportOptions::push_images_enabled( $this->id() )` に従って送る契約とした（`Capabilities` と `PlatformAdapter::push_product()` の docblock に明記。従わないアダプタでは、Export タブの
   「既定オフ」の項目が効かず、切替のたびに商品が再送されるだけになる。`ColorMeAdapter::should_push_images()` が参照実装）。**画像をオンにすると、ColorMe の `POST /v1/products/{id}/images` は
   同じ position の既存画像を上書きする**（swagger）うえ、`push_images()` は既存画像を確認せず position 0〜 に POST するため、オフの間に ColorMe 管理画面で手作業で登録した画像も Woo の画像で置き換わる。
   Export タブの説明文にこの旨を書いた。オンにした直後は、画像を持たない商品まで一度更新として再送される点と、オフに戻すと画像付きで送った商品が一度だけ再送される点は許容
   （`docs/review-backlog.md` の `r3-0j-premium-beta-features/R1-L1`）。
7. **確認結果（mock アダプタ・モック HTTP。実 API は未確認＝D24 の方針どおり）**: PHPUnit（新規・変更 PHP テストを含む全 1282 件）・ミューテーション 15 件すべて検出。`/verify-with-mock-adapter`（`mockv`）で `/connections` の
   `beta_features`・`export-options` の GET/PUT/不正値・非プレミアム相当の 400、`JobManager` 経由の商品 export（専用商品の mapping の `checksum`/`synced_at`）で「オフで 2 回目は再送なし → オンにすると再送 → オンで続けて回すと再送なし →
   オフに戻すと一度だけ再送 → 以後再送なし」、実 `ColorMeAdapter::push_product()` をモック HTTP で直接呼び「プレミアム＋オフ=画像 POST なし＋`PRODUCT_IMAGES_NOT_PUSHED`／プレミアム＋オン=画像 GET＋POST／非プレミアム＋オン=送らない」。
   管理画面（ブラウザ）で Beta 表示・受注が既定未選択・画像が既定オフ・オンへ切替→リロードで保持・オフへ戻す・非プレミアム相当の宣言では受注も画像も出ない、コンソールエラーなしを確認。

#### 往復の扱い（D25、issue #98。R3-1a）

D25「実体は作られた向きにだけ更新する」の実装。判定は「誰が作ったか」ではなく**「このプラットフォームとの紐づけを誰が作ったか」**。

- **前提: 往復は想定しない（2026-10-06 ユーザー決定）**: このプラグインは一方向の移行（ASP → Woo、または Woo → ASP）に特化する。
  同じショップとの間で取込みとエクスポートを行き来する運用（取り込んだ実体を書き戻す・エクスポートした実体を取り込み直す）は想定しない。
  D25 は、誤って往復したときに ASP・Woo の値を壊さないための安全策で、往復の運用を支える機能ではない。そのため、往復でしか起きない
  取りこぼし（下の「往復を想定しないため対応しないもの」）は直さない。readme・FAQ にもこの前提を書く（R3-3）。

- **印は新設しない**: 取込みの全 writer（`ProductWriter`/`VariationWriter`/`CustomerWriter`/`OrderWriter`/`CouponWriter`/`TermWriter`）が
  作成・更新・メールでの採用のたびに書く `_cbjp_platform` を「取込みで結ばれた」印として使う。エクスポートは Woo に何も書かない。
  取込みがエクスポートで結ばれた実体に書かなくなった以後は、mapping のある実体は「取込みで結ばれた」「エクスポートで結ばれた」の
  どちらか一方になる（1 ビットで分かれる）。`SampleCleanup`・`MappingRebuilder`・`PrefStateRepair`・`PlatformOwnership` も
  `_cbjp_platform` を同じ意味で使っている。顧客は作成時だけ書く `_cbjp_created_by_import` の一致も取込み側に含める
  （別プラットフォームがメールで採用し直して `_cbjp_platform` が書き換わっても作成元を見失わない）。判定は `Woo\Support\EntityOrigin` に集約。
  - **メールで採用した既存の Woo 顧客は取込み側**（紐づけたのは取込みで、取込みは毎回その顧客を更新している。エクスポートはしない）。
  - エクスポートが Woo に印を書く案は採らない: エクスポートを Woo 読み取り専用に保てる（HPOS の受注にメタを書かない）、印が無ければ
    「取込みで結ばれていない」＝上書きしない側に倒れる（印の書き損じで取込みが上書きする側に倒れない）。v0.1.0 はエクスポート前の
    リリースなので、D25 より前の往復でできた旧データは開発・テストサイトにしか無い（再リハーサルは `reset-local` から行う）。
- **エクスポート側**: 各 Reader が `ReadItem::$linked_by_import` を立て（商品・クーポンは投稿、顧客はユーザー、受注は `WC_Order::get_meta()`、
  在庫は親商品で判定。在庫行を作る全 5 か所〔単純商品・解決したバリエーション・未解決の行＝mapping の無い単純商品・mapping の無いバリエーション・
  mapping の無い variable 親のバリエーション〕で立て、`ProductReader` と同じ関数を使う）、
  `Exporter` は push intent の自己修復の直後で送らずにスキップする。**mapping の有無によらない**（mapping を失った取込み品を作成し直して
  ASP に重複を作らない）。mapping・無料枠・push intent に触れず、取込みが書いた checksum もそのまま残す。dry-run 行には
  `linked_by_import_not_exported` だけを載せる（送らない行に止める警告などの読出時の警告を出さない）。変換は従来どおり全部行う
  （判定が退行しても、空の Canonical を送るのではなく従来の送信に戻るだけにする）。`ProductReader` のページ 1 回の情報警告
  （`PRICES_CONVERTED_TO_TAX_INCLUSIVE`）は取込み品で消費しない。対象はエクスポートの Reader があるエンティティすべて
  （商品・顧客・在庫・受注・クーポン。ColorMe の受注は従来 `ORDER_UPDATE_NOT_SUPPORTED` で見送っていたものが新コードに変わる）。
- **インポート側**: `WooRepository`/`DryRunRepository` が writer を呼ぶ前に `EntityOrigin::blocks_import()` で判定し、mapping が指す実体が
  エクスポートで結ばれていれば `WriteResult( 0, skipped, [ linked_by_export_not_imported ] )` を返す（local_id 0 なので `Importer` は mapping に
  触れず、エクスポートの checksum が残る）。対象は商品・顧客・受注・クーポン。「実体がある」は各 writer の stale-ID 判定と同じ条件
  （商品は posts の行と投稿タイプ、顧客は `get_userdata()`、受注は `wc_get_order() instanceof WC_Order`、クーポンは投稿タイプ）で、
  実体が無い mapping と保護ロールの顧客（取込みが印を書かない）は判定せず従来どおり各 writer に任せる。**在庫**は在庫の mapping ではなく
  商品・バリエーションの mapping で対象を解決する（在庫の mapping が無くても届く）ので、`StockWriter` が解決した対象で同じ判定をする
  （`STOCK_PARENT_OF_VARIABLE` の分岐より前。後ろだと親の ID を返して stock の mapping に取込みの checksum を書いてしまう）。
- **件数**: どちらの向きのスキップも `skipped`・`warned` に数え、**mapping があるときだけ `unchanged` にも数える**（既に結ばれている＝
  `LimitPolicy::used()` に数えられているので、数えないと Pro 案内の「未移行」が過小になる。§10.3 R3-0h の `unchanged` の定義を拡張）。
  2 つの警告コードは情報のみで、`indicates_export_blocking`／`indicates_unresolved_reference`／CSV の note のどれにも入れない。
  本実行では件ごとの警告は残らない（dry-run の CSV にだけ出る。sync-export-tools ルール 1 項目目）。
- **サンプル選定**（§10.2 #8）: エクスポートのサンプルから取込みで結ばれた受注・商品・顧客を除く。
- **商品の複製**: WooCommerce の複製（`WC_Admin_Duplicate_Product::product_duplicate()`）は既定でメタをすべて写すため、
  `woocommerce_duplicate_product_exclude_meta` で `_cbjp_platform`・`_cbjp_remote_id` を外す（`Core\Plugin::boot()`。WC 11 の実ソースで
  バリエーションの複製にも同じ一覧が使われることを確認）。写すと複製が「取込み品」と判定されて黙ってエクスポートされず、
  リンク再構築が同じ remote_id で複製側に mapping を付け替えうる（既存の問題も同時に解消）。
- **受注明細の商品解決**: 取込み受注の明細がエクスポートで作った可変商品を指すとき、そのバリエーションは取込みの印を持たない
  （取込みがその商品を書かなくなったので、以前のように印が付くことも無い）。`ProductResolver::resolve_variation_by_options()` は、印に加えて
  このプラットフォームの variant の mapping で結ばれたバリエーションも対象にする（単純商品が remote_id の mapping で解決するのと揃える。
  印も mapping も無い、店舗が Woo で足したバリエーションは従来どおり対象外。review-loop R1 の独立レビュー A-1）。
- **backlog `e2-2-exporter-core/R2-M-checksum-shared-row` は解消**: 原則として 1 つの mapping 行を両方向が書かなくなった（例外は下の 2）。
- **往復を想定しないため対応しないもの**（2026-10-06 ユーザー決定。上の「前提」）:
  1. エクスポートで結ばれた実体が mapping を失う（クリーンアップ等）と、取込みの向きからは Woo で手作りした実体と区別できない。
     リンク再構築（`MappingRebuilder`）は取込みの印からしか復元しないので、再取込みで商品・受注・クーポンは Woo に作り直され（重複）、
     顧客はメール突合で採用・上書きされうる。
  2. 保護ロールが絡むアカウントは印で判定しない（取込みが印を書かない）: 保護ロールで採用された後に `customer` へ降格したアカウントは
     取込みの印が無いのでエクスポートで更新として送られうる。逆にエクスポートで作った顧客が後から保護ロールを持つと、ガードを通らず
     `CustomerWriter` が保護ロールとしてスキップし（local_id はそのユーザー）、`Importer` がその行に取込みの checksum を書く（値は書かない）。
  3. 複数プラットフォーム（v2.0）: P が採用した顧客を Q が採用し直すと `_cbjp_platform` が Q になり、P の取込みはその顧客をスキップする。
  4. エクスポート生まれの顧客にも、受注の取込みで `paying_customer` などの関係の情報は付く（値の上書きではない）。
  5. カテゴリ・タグ・レビューは対象外（エクスポートの Reader が無く、ColorMe は `can_create_category=false`）。BASE（v2.0）で
     カテゴリをエクスポートするようになっても、往復は想定しない（判定を足すのは、一方向の移行で値を壊すおそれが出たときだけ）。
- **検証**: PHPUnit（`EntityOriginTest`・各 Reader・`ExporterTest`・`WooRepositoryTest`・`StockWriterTest`・`ImporterTest`・
  `ExportSampleSelectorTest`・`WarningCodeTest`・`OrderWriterTest`、実配線の往復 `RoundTripOriginTest`）。`mutate-check.sh` で各ガード（Exporter の分岐と
  `unchanged`、Reader 5 つと在庫行の全 5 か所、顧客の作成の印、リポジトリ 2 つ、実体の有無の判定、保護ロール、空のプラットフォーム、
  StockWriter の判定と順序、Importer の `unchanged`、サンプル選定 8 か所〔走査の重複除去・ID でない値の除外を含む〕、複製フィルターの登録、
  受注明細のバリエーション解決〔このプラットフォームの mapping だけ〕）を壊して落ちることを確認（38 種）。サンプル選定の走査は同じ日時の行がページの境目で重複・欠落しないよう
  `orderby => 'date ID'`（HPOS・CPT・`WP_User_Query` とも空白区切りを受け付けることを実ソースで確認）で並べ、ID だけを取得する。
  テストショップでの再リハーサル（`rehearse-colorme` の手順 2・3）は未実施（2026-10-05 ユーザー判断で後回し。`docs/10-tasks.md` R3-1a）。

#### 商品名の保存形式（R3-1b、issue #99）

R3-1 のリハーサルで、取込みの商品名の保存結果が Action Scheduler のどのランナーで処理されたかで変わると分かった（WP-Cron は未ログインで kses が
`title_save_pre` の `wp_filter_kses` を通し `Tom & Jerry <set>` → `Tom &amp; Jerry `、管理画面から動く非同期ランナーは管理者の Cookie を転送するので
そのまま残る）。WordPress は投稿のタイトルを HTML として保存・表示するので、**Canonical の名前は平文、Woo の名前は HTML** と決め、変換を
`Woo\Support\HtmlText` に集約した（2026-10-05 ユーザー決定「取込みで実体参照にして保存し、エクスポートで戻す」。方式は R3-1b の計画で決定）。

- **取込み**（`ProductWriter::prepare()`）: 名前を `HtmlText::from_plain()` で保存する。`&` `<` `>` を実体参照にし（既存の実体参照も二重に符号化する）、
  バックスラッシュを `&#092;` にする。引用符は kses が変えないので符号化しない（`Men&#039;s` にすると `Men's` で商品を検索できなくなる）。
  二重に符号化するので、名前に文字どおり `&amp;` があっても戻せる。バックスラッシュは `wp_insert_post()` の `wp_unslash()` がランナーによらず
  消していた（WooCommerce のデータストアが slash せずに渡す）。`&#092;` は kses が数値実体参照を正規化した形なので保存で変わらない。
  issue #99 の対応案（`esc_html()`）は、引用符も符号化し既存の実体参照を二重に符号化しないので採らなかった。
- **説明・短い説明**: アダプタの浄化（カラーミーは `Cast::sanitize_html()`）に頼らず、Writer が `wp_kses_post()` してから保存する
  （kses を通っても変わらない形。外部アダプタの値でもランナーで変わらない）。カラーミーの値は既に浄化済みなので結果は変わらない。
  R3-1c（issue #101）からは `HtmlText::sanitize_post_html()`（`<script>`・`<style>` を中身ごと除いてから kses）を使う。
- **バリエーションの名前**は WooCommerce が親の post_title と属性の値から作り、読み込み時に作り直して直接書き戻す（`WC_Product_Variation_Data_Store_CPT::read()`）。
  Writer は触らない（親の名前が決まれば、読み直した名前はランナーによらない。テストで確認）。属性の要約（`post_excerpt`）は下の対象外。
- **エクスポート**: `ProductReader` は名前を `HtmlText::to_plain()`（`html_entity_decode( ENT_QUOTES | ENT_HTML5 )`）で平文へ戻して送る。
  Woo で作られた名前は、管理画面の生の値（`Tom & Jerry <set>`）のことも、REST・kses の条件で実体参照になった値（`Tom &amp; Jerry`、`&hellip;`）のこともあるので、
  5 種類だけを戻す `wp_specialchars_decode()` ではなく全ての実体参照を戻す（店舗の表示どおりの文字にする）。タグは除かない
  （管理者が名前に書いた `<…>` は管理画面に文字として出ているので、そのまま送る）。
- **ターム名**: WP はターム名を常に実体参照で保存する（`pre_term_name` の `_wp_specialchars`。ランナーによらない）ので、グローバル属性の値
  （`VariationAxisResolver::attribute_value()` のタクソノミー分岐・`ProductReader::options()`）も `to_plain()` で戻す。以前は Woo 生まれの
  `Black & White` が ColorMe のオプション値に `Black &amp; White` で送られていた。ローカル属性の値と属性のラベルは生の値なので変えない。
- **表示**: push intent の一覧（`PushIntentPresenter`）が画面へ渡す商品名も `to_plain()` する（React は文字として出すので、実体参照がそのまま見えていた）。
- **既存のデータ**: 取込みの checksum は Canonical（ColorMe の値）から作るので、保存形式を変えても既存の取込み済み商品は「更新」にならず、書き直さない
  （ColorMe 側で変わったときに新しい形で書かれる。v0.1.0 の利用は開発・テストサイトだけで、再リハーサルは `reset-local` から行う）。
  エクスポートでは、名前・ターム名が実体参照だった Woo 生まれの商品の Canonical が変わるので、1 回だけ再送される（意図どおりの修正）。
  ColorMe 側に古いオプション値（`Black &amp; White`）が残っていれば、`ensure_option_values()` が新しい値を追加する（v1.0 前なので開発環境にしか無い）。
- **制御文字**（タブ・改行・復帰を除く `\x00-\x1F`）は kses の `wp_kses_no_null()` が WP-Cron でだけ消すので、`from_plain()` が先に消す
  （実体参照にしても kses が書き換える。review-loop R1 の独立レビュー A-1）。
- **対象外**（backlog `r3-1b-product-name-entities/plan-X1`〜`X3`・`R1-L1`）: 取込み受注の明細名（Woo は HTML として表示時に kses。保存は kses を通らないので
  ランナーには依存しない）、クーポンの説明（`post_excerpt`。ランナーに依存する）、説明のバックスラッシュ、WooCommerce が作るバリエーションの
  `post_excerpt`（属性の要約。ランナーに依存し、名前と違って `read()` は作り直さない）。
- **検証**: PHPUnit（`HtmlTextTest`、`ProductWriterTest` で未ログイン〈kses あり〉と管理者のそれぞれで保存した名前・更新・バリエーション名・説明が一致、
  `ProductReaderTest` で Woo 生まれの名前・取込みの名前の往復・タクソノミー属性、`VariationAxisResolverTest`、`RestControllerTest` の push intent）。
  `mutate-check.sh` で 15 種（Writer の符号化・更新時の符号化・説明の kses 2 か所・制御文字の除去・`ENT_SUBSTITUTE`・バックスラッシュの置換とその順序・二重符号化・
  引用符・復号の範囲・Reader の名前とターム・Resolver・Presenter）がすべて CAUGHT。wp-env の dev サイトでも実際の Writer/Reader で WP-Cron の条件（未ログイン＋`kses_init_filters()`）と管理者の
  保存結果が一致し、読み戻した名前が元どおりになることを確認した。テストショップでの確認（`rehearse-colorme` の `run context=cron|admin` → `check-import`、
  Woo 生まれの `ZZW-6` の作成エクスポート）は R3-1b〜e の実装後の再リハーサルでまとめて行う（2026-10-06 ユーザー決定）。

#### 名前・住所がそろわない顧客の更新と説明の `<script>`・`<style>`（R3-1c、issue #100・#101）

R3-1 のリハーサル（`docs/reviews/feat/r3-1-e2e-rehearsal/rehearsal.md` の所見 B・C）で見つかった 2 件。

- **顧客の更新（issue #100）**: `PUT /customers/{id}` は swagger では必須項目が無いが、ColorMe は名前と住所（市区町村・番地）が無いと 422 にする
  （テストショップで実測。`.claude/rules/adapters-colorme.md`）。以前の `CustomerTransformer::to_update_payload()` は住所 3 点（`pref_id`/`postal`/`address1`）を
  そろえられないと住所を省いて送り、海外の会員の更新が毎回 422・`Exporter` の 1 件失敗（ログは例外のクラス名だけ）になっていた。
  `to_update_payload()` を `?array` にし、名前（空白だけでない・50 文字以内）と住所 3 点を作成と共有の判定で確かめ、そろわなければ `null` を返す。
  `ColorMeAdapter::push_customer()` は作成と同じく API を呼ばずに `PushResult('', skipped, [CUSTOMER_REQUIRED_FIELD_MISSING])` を返す
  （`push_order()` の更新スキップと同じ形。`Exporter` は既存の mapping〔remote_id・checksum〕に触れず、checksum も保存しないので、店舗が住所を補えば次回送る）。
  電話番号は作成だけの必須のまま（更新で必須かは未実測。解決できなければ従来どおり省く）。作成側にも名前が空のスキップを足した（以前は `display_name` 頼みで、空なら 422）。
  D25 で取り込んだ会員はエクスポートしないので、対象は Woo 生まれの顧客で作成後に住所を消した・名前を 50 文字超に変えた場合だけ。
  理由の見せ方は警告コードだけ（2026-10-06 ユーザー決定。dry-run はアダプタを呼ばないので明細・CSV には出ない。作成時と同じ既知の限界。項目別警告の保存は R3-0k の決定どおり v1.1 以降）。
  backlog `e2-3-push-customer/G1-name-length-on-update` は解消。
- **説明の `<script>`・`<style>`（issue #101）**: `wp_kses_post()` はこの 2 つのタグを外すだけで中身の JS・CSS を文字として残し、商品ページに表示される
  （`a<script>console.log("zzr")</script><style>.zzr{color:red}</style>b` → `aconsole.log("zzr").zzr{color:red}b`。wp-env で実測）。
  `Woo\Support\HtmlText::sanitize_post_html()` を新設し、2 つの要素を中身ごと除いてから kses を掛ける。取込みの `Cast::sanitize_html()`（商品の説明・簡易説明・
  スマートフォン用説明、カテゴリ・グループの説明）と、Writer が自分で浄化する `ProductWriter` の説明・短い説明（R3-1b）の両方で使う
  （商品の説明は外部アダプタ・将来の BASE でも同じ結果になる。ターム〔カテゴリ・タグ〕の説明は `TermWriter` が浄化しないので、アダプタが `sanitize_post_html()` を通す。backlog `r3-1c-customer-update-and-script-strip/R1-X1`）。
  - 開始タグは kses と同じ区切り（`wp_kses_split()` の正規表現: コメント、または `<` から最初の `>` まで）で見つける。kses がタグとして外す範囲だけを対象にし、
    属性値（`<p title="<script>">`）・CDATA の中の文字は開始タグと見なさない。最初は文字列全体を正規表現で探していたため、属性値・コメント・CDATA の中の
    `<script>`・`<style>` から後ろ（閉じタグが無ければ末尾まで）の説明を消していた（review-loop R1 の独立レビュー A-1。wp-env で実測）。
  - 中身はブラウザと同じく生のテキストとして読み、最初の `</script`・`</style`（直後が空白・`/`・`>`）で閉じる（JS の `a<b` をタグと見なさない。`</scripts>` は閉じタグではない。
    閉じタグに `>` が無ければ末尾まで）。
  - 閉じタグが無い要素は除かない（後ろの説明を失わない。kses がタグを外し、中身は文字として残る従来の結果）。
  - コメントの中も同じ規則で除く（kses はコメントの中身にも kses を掛けるので、コメントアウトした `<script>` の中身が文字として出ていた）。
  - テキストの部分は `<` を含まないので、除いた前後がつながって新しい開始タグになることは無い（繰り返しは要らない）。
  - 区切りと閉じタグは `strpos()`・`stripos()` で探す（正規表現の遅延一致は約 1MB のコメントで PCRE の上限に達し、閉じタグの無い開始タグ・`>` の無い閉じタグが
    多い入力では探し直しが二乗になった〔640KB で 1.3 秒・720KB で 5 秒。review-loop R1 で実測〕）。閉じタグの見つからなかった要素名は覚えて探し直さない。
    これで PCRE が失敗する経路は無くなった（kses の後ろでは同じ入力に kses 自体が 100〜500ms かかり、除去の上乗せはその 1〜3 割）。
  - 既知の限界: `<textarea>`・`<title>` の中（ブラウザでは文字）の `<script>…</script>` も除く（kses と同じく区別しない）。
  - 既存の取込み済み商品は、説明に script/style がある商品だけ Canonical が変わり、次の取込みで更新される（それ以外は checksum 不変で書き直さない）。
- **検証**: PHPUnit（`HtmlTextTest`〔中身ごとの除去・大文字・属性・改行・閉じタグの形〈空白・`/`・属性・`</scripts>` は閉じない〉・中身の `<`・コメント内〈複数行も〉・`<scripts>` は残る・閉じタグの `>` 無し・属性値／CDATA／閉じていないコメント／閉じタグ無しでは何も除かない〕、
  `CastTest`・`ProductTransformerTest`〔説明 3 種〕・`CategoryTransformerTest`・`TagTransformerTest`・`ProductWriterTest`〔未ログイン〈kses あり〉と管理者の保存が一致し JS・CSS の文字が無い〕、
  `CustomerTransformerTest`〔住所なし・一部・海外で郵便番号なし・海外で住所あり・電話なし・名前の空/空白/50/51 文字を作成と更新の両方〕、
  `ColorMeAdapterTest`〔更新で PUT を送らない・`Exporter` との結合で mapping が残りエラーログが無い〕）。`mutate-check.sh` で除去・顧客の判定の各ガードがすべて CAUGHT（種類と件数は `docs/reviews/feat/r3-1c-customer-update-and-script-strip/R1.md`）。
  wp-env の dev サイトで、P11 と同じ説明を `Cast::sanitize_html()` と実際の `ProductWriter`（WP-Cron の条件〔未ログイン＋`kses_init_filters()`〕と管理者）に通して JS・CSS の文字が残らず両者が一致すること、
  実アダプタの `push_customer()` が住所の無い顧客の更新で HTTP を一切送らずに警告つきでスキップすることを確認した。テストショップでの確認（`rehearse-colorme` の `check-import`〔script/style の中身が残れば MISMATCH〕と
  手順 3 の顧客の更新）は R3-1b〜e の実装後の再リハーサルでまとめて行う（2026-10-06 ユーザー決定）。

### 10.3 Pro本移行時の重複防止・ツール（D16）

- **本移行**（Pro解除後）: カーソル先頭から全走査。mappings 一致分は checksum 比較のうえ
  **開始時に選択した上書きポリシー**（既存を更新 / 既存はスキップ。デフォルト: 更新）に従い、未取込分のみ新規作成。
  dry-run で「新規◯件・更新◯件・スキップ◯件」を事前表示する
  - **v1.0 の範囲（2026-10-05 決定、R3-1）**: 上書きポリシーの選択式（既存はスキップ）は実装しない。v1.0 の本移行は「mapping のある実体は
    checksum を比べ、変わっていれば更新・同じならスキップ（`unchanged`）」の 1 通りで、上の「既存を更新」に当たる。選択式が効くのは、
    サンプル移行から本移行までの間に ColorMe 側のデータが変わり、かつ店舗が Woo 側で手直しした場合だけで、v1.0 の必須ではないと判断した
    （選択式は `docs/review-backlog.md` の `r3-1-e2e-rehearsal/D16-overwrite-policy` で v1.x 以降に検討する）。
    R3-1 のリハーサルでは、サンプル → キャンセル → 上限解除の本移行で、サンプル・キャンセル前に作成した実体が作り直されず `unchanged`
    になることを実データで確認した（`docs/reviews/feat/r3-1-e2e-rehearsal/rehearsal.md`）
- **リンク再構築ツール**（`POST /tools/rebuild-mappings`）: 再インストール・DB移設等で mappings が失われた場合に、
  SKU（商品）/ email（顧客）/ `_cbjp_remote_order_number` メタ（受注）で既存Wooデータと突合して mappings を再構築
- **サンプルクリーンアップツール**（`POST /tools/sample-cleanup`）: 無料版サンプル由来のWooデータと対応 mappings を一括削除。
  対象は mappings の記録に基づき、実行前に削除件数を表示して確認を取る（本移行前のリセット・サンプル再選定に使用）
- **県コード修復ツール**（`GET/POST /tools/repair-states`。issue #46）: PR #44 より前にインポートした顧客・受注の都道府県（`state`）の是正。
  Scan（読取専用）で補正が必要な件数を確認してから Repair を実行する。詳細は下記「県コード修復ツール（issue #46）」
- **アップセル表示**: dry-run で総数が判明するため、上限到達時に
  「移行対象◯件のうち10件を無料版で移行済み。残り◯件は Pro 版で移行できます」と具体数で表示（`GET /limits`）
  - **件数の内訳と Pro への言及の切替（2026-09-26 決定、issue #55）**: 当初は dry-run の `processed` と移行済み件数（`used`）の差を
    「Pro 版で移行できる残数」としていたが、差には価格未設定・blocking 警告等で **Pro 版でも移行できない件数**が含まれ、上限未到達でも
    「Pro 版が必要」と誤表示していた（E2-4 実機E2E）。`Importer`/`Exporter` の totals に checksum 一致スキップの件数 `unchanged` を追加し、
    dry-run の `created + updated + unchanged` を「移行できる件数」とする。表示は「移行できるが未移行（= 移行できる件数 − `used`）」と
    「どの版でも移行できない（= `processed` − 移行できる件数）」に分け、後者は Pro に触れず dry-run レポートへ誘導する中立文言にする。
    Pro 版への言及（見出し文を含む）は、新設フィルター `cbjp/limits/pro_url`（既定 `''`）が有効な URL を返すときだけ出し、リンクを付ける。
    空なら Pro に触れず「無料版はサンプルを移行します。N 件は未移行です」とだけ示す（v1.0 と同時に Pro 版を販売するかの判断を後回しにできる）。
    フィルターの戻り値は外部コード由来の信頼境界（原則8）のため、文字列かつ `http`/`https` の URL だけを `esc_url_raw()` して `/limits` の
    `pro_url` に載せ、それ以外は `''` として扱う。フロントは `target="_blank" rel="noopener noreferrer"` で開く
  - **実装（R3-0h、issue #55）**: 上の決定からの差と実装の要点。
    1. `unchanged` は `skipped` の内訳（`skipped` の意味は変えない）。`Importer`/`Exporter` の checksum 一致スキップの分岐だけで数える。
       `Exporter` は blocking 判定が checksum 一致より先なので、移行済みの実体が後から止まる状態になっても「移行できる」には入らない。
       **D25（R3-1a）で、往復の向きで送らない／上書きしないスキップのうち mapping があるものも数える**（既に結ばれていて `used` にも
       数えられているため。§10.2「往復の扱い（D25）」）。
       導入前に完了したジョブの `totals_json` には無く（`GET /runs/{id}` は生の JSON を返す）、フロントは内訳不明として扱う。
       導入（プラグイン更新）をまたいで続いたジョブは、`JobManager::decode_totals()` が `empty_totals()` をマージするため導入前のページ分が 0 のまま
       `unchanged` が載り、「移行できない」が過大になる（既知の制限。更新と長い dry-run が重なったときだけ。R1-3）。
    2. **在庫・レビューは内訳を出さない**（計画時に決定）: dry-run は対象商品が未移行だと在庫を全件スキップする
       （`STOCK_PRODUCT_UNRESOLVED`／`STOCK_PRODUCT_NOT_EXPORTED`）ため、式どおりだと商品を移行すれば移行できる在庫まで「移行できない」と
       表示してしまう。「プレビューで N 件、移行済み M 件。サンプル商品の分だけ移行します」とだけ示す（dry-run の件数が移行済み数を上回り、
       かつ商品に「移行できるが未移行」が残っている〔商品の内訳が不明なときも出す〕とき。商品を移行し終えた後に残る在庫は止まる商品の分で、
       どの版でも移行できないため。R1-1 で追加）。
    3. **「未移行」が 0 件なら行を出さない**（計画時に決定）。「移行できない」件数は未移行がある行に併記する（dry-run の結果・CSV には既に出ている）。
    4. Pro への言及は見出しだけに置き、各行は `pro_url` の有無によらず同じ中立文言にする。見出しは `pro_url` 有効時「無料版はサンプルを移行します。
       未移行の分も Pro 版で移行できます」＋リンク、空なら「無料版はサンプルを移行します」だけ。
    5. 内訳が分からない（dry-run が無い・`unchanged` の無い導入前のジョブ）ときは従来の「上限に到達。dry-run で残りを確認」文言に
       フォールバックする（上限に達しているときだけ）。
    6. 近似: `used`（mappings＋未解決 push intent）は後から止まる状態になった移行済み実体や ASP 側で削除された実体も含むため、
       「未移行」＝`migratable − used` は過小になりうる（0 で下限）。export の dry-run はアダプタの送信時スキップ（必須項目欠落の顧客等）を
       反映しないため「未移行」が過大になりうる。
    7. `LimitPolicy::pro_url()` は `esc_url_raw()` の前に scheme（http/https）と host の有無を確かめる（scheme の無い `example.com/pro` には
       `esc_url_raw()` が `http://` を補い、`https:example.com/pro` は host が無いまま通すため。実測）。フロントも `sanitizeProUrl()` で多重に確かめる。
       `/limits` ルートには `platform` の `args`（`type: string`）を追加し、`?platform[]=x` を 400 にした（以前は `(string)` キャストで警告）。
    8. リンクは `ExternalLink` に `rel="noopener noreferrer"` を明示する。WP 7.1 コア同梱の `wp-components`（実行時に使われる）の `ExternalLink` は
       npm 版（28.x）と違って `rel` を付けない（実測）。
    9. 計算は純粋関数 `src/components/upsell-breakdown.ts` に置き、wp-scripts 同梱の Jest（`npm run test:js`。CI・`quality.sh` にも追加）で単体テストする。
       フロントの `sanitizeProUrl()` もサーバーと同じく `https:example.com` のような `//` の無い形を拒否する（`URL` は補って通すため。R1-4）。
    10. タブは dry-run の件数を前回値へマージして保持する（対象を絞った再 dry-run の後も他エンティティの件数を残すため）。新しい dry-run を始めたら、
        その対象エンティティの前回の件数を捨てる（`withoutDryRunTotals()`）。一部のジョブが失敗・キャンセルしたとき、前回の内訳が今回のものとして
        残らないようにするため（PR #87 G1-1/G1-2）。「移行できない」の案内先は dry-run レポートと Logs タブ（remote_id 欠損のように CSV に行を
        作れない項目は Logs にだけ残る。G1-3）。
    実機確認（mock アダプタ `mockv`、Export タブ）: dry-run 9 件（移行できる 4・止まる 5）→ 上限 2 の export で 2 件作成、の状態で
    「Products: 9 found by the preview, 2 migrated, 2 not migrated yet. 5 cannot be migrated as is. …」と表示（修正前なら「残り 7 件は Pro 版が必要」）。
    `pro_url` 空では Pro に触れず、設定時は見出しにリンク（`target="_blank" rel="noopener noreferrer"`）が付くことを確認した。

#### ツールの実装詳細（F1-7）

- **サンプルクリーンアップ**（`Woo\Tools\SampleCleanup`、`GET/POST /tools/sample-cleanup`）: `cbjp_mappings`（platform単位）を正とし、
  指す先の実体が `_cbjp_platform` メタで自プラットフォーム所有と確認できるものだけ削除する。所有権が無い・実体が既に無い行は
  mapping 行だけ外す（`unlinked`）。削除順は order → stock/review（mapping行のみ）→ product（`VariationWriter::find_owned_variation_remote_ids()`
  + `remove_all()` で所有 variation を先に削除）→ variant → coupon → customer → tag → category → 所有添付（`_cbjp_platform` + `_cbjp_source_url` 付き
  attachment のうち、親投稿が無い／削除済みで、既存タームの `thumbnail_id` でもない**孤児**のみ。mappings を失った店舗でクリーンアップを実行しても
  残っている商品・タームの画像を道連れにしない）。
  **顧客**: `CustomerWriter` は email 突合で採用した既存 WP ユーザーにも `_cbjp_platform` を書くため、新規作成時にのみ書く
  `_cbjp_created_by_import` マーカー（値は**作成したプラットフォームID**。不変で、採用では書き換えない）が自プラットフォームと一致し、
  別プラットフォームが現在リンク中（`_cbjp_platform` が他プラットフォーム）でなく、店舗スタッフ権限（`PROTECTED_ROLES`）を持たず、
  実行中の管理者自身でもないアカウントを、**実行者が `delete_user` 権限を持つ場合のみ** `wp_delete_user()` する（ルートの `manage_woocommerce`
  だけでは shop_manager が WP 管理画面でできないアカウント削除を行えてしまうため）。それ以外はリンク用メタ（`_cbjp_platform`/`_cbjp_remote_id`。
  自プラットフォームがリンクしている場合のみ）を外して残し、マーカーは残す（別プラットフォームがリンクを解いた後に作成元が再び取り込めば削除できる）。
  ただし**自プラットフォームが作成したアカウントが mapping に残っているのに実行者が `delete_users` を持たない場合は、実行自体を 403 で拒否**する
  （unlink だけして mappings とサンプルセットをリセットすると、無料版の顧客上限（`LimitPolicy`）を回避してアカウントを増やし続けられるため。
  アーキテクチャ原則 7）。
  **プレビュー**（`GET /tools/sample-cleanup`）は mapping 行数ではなく、`run()` と同じ所有権・実在・権限判定で「削除される件数」と
  「mapping を外すだけの件数」をエンティティ別に返し、`requires_delete_users` / `can_delete_users` / `sample_selected`（mapping が無くても
  サンプルセットだけ残っている場合に「選定のクリア」として実行できる）を併せて返す。
  **バッチ契約**: Action Scheduler ジョブにはせず、1リクエストで予算（100実体。商品削除に伴う variation も数え、予算を使い切った時点で
  取得済みページの残りも次のバッチへ回す）まで削除して `has_more` を返し、管理画面がループする（削除済み行は消えるため cursor 不要）。全て消え切った呼び出しで残骸 mappings（`delete_for_platform()`）と `cbjp_sample_{platform}` を削除し、
  次回 import でサンプルが再選定される（§10.2 #7）。実行中のジョブ（pending/running/paused）がある間は 409。削除中は `SideEffectGuard` で
  メール・在庫復元を抑止する
- **リンク再構築**（`Woo\Tools\MappingRebuilder`、`POST /tools/rebuild-mappings`）: D16 の記述（SKU/email/注文番号突合）に対し、実装は
  各 Writer が Woo 側実体へ必ず書く **`_cbjp_platform` + `_cbjp_remote_id`（受注は `_cbjp_remote_order_number` = `CanonicalOrder::remote_id()`）
  メタを主キー**に走査する（category/tag は term meta、product/variant/coupon は post meta、customer は user meta、order は `wc_get_orders()`。
  受注の `meta_query` は HPOS の `OrdersTableQuery` しか解釈せず、レガシー投稿型ストレージでは WC 9.2+ が非対応引数として無視するため、
  HPOS 有効時の最適化としてのみ付け、どちらの構成でも取得後に `_cbjp_platform` を検証したものだけを対象にする。cursor の前進はクエリが
  返した件数で判定する）。SKU/email 突合は「本プラグイン外で作られた Woo データを ASP に紐付ける」動作になり誤リンクの危険があるため採用しない。
  checksum は null で upsert し、次回 import で必ず再検証させる。stock（product/variant から再解決される）と review（v1.0 に Writer 無し）は対象外。
  予算 200 件/リクエストで `{entity, offset}` の cursor を返し、管理画面がループする（upsert は走査結果を変えないため offset ページングで安定）

#### 県コード修復ツール（issue #46）

PR #44 より前のコードは ColorMe の `pref_id` をそのまま `JP%02d` にしていた（恒等変換）ため、それ以前にインポートした
顧客・受注の `billing_state`/`shipping_state` は 23 県で誤っている（§「E2-3 PR-B」）。是正手段は次の理由で「ASP を正として再取得し、
`state` のみを条件付きで補正する」ツール（`Woo\Tools\PrefStateRepair`、`GET/POST /tools/repair-states`、Tools タブの
「Repair prefecture data」）になった。

- **再インポートでは直らない**: Canonical は生の `pref_id` を保持し変換は Writer 側で行うため、対応表を直しても checksum は変わらず
  `Sync\Importer` の checksum 一致スキップに阻まれる。checksum を無効化する案は、店舗が Woo 側で手直しした他フィールドまで D16 の既定
  （上書き）で巻き戻すため不採用。
- **Woo 側だけでは判別できない**: 保存されるのは変換後の `state` のみで、顧客と受注請求先（`customer_snapshot` は `OrderWriter::meta_extras()` が
  破棄）の生 `pref_id` は残らない（受注配送先だけは `_cbjp_sale_deliveries` に残るが、ロジックを二系統にしないため使わない）。修正前後のデータは
  値だけでは区別できず（入れ替え・巡回のため、全件へ表を適用すると修正後データを壊し、二重適用では別の県になる）、5巡回・4巡回の 9 県は
  手作業/SQL で一括変換してしまうと戻せない。
- **判定**（側ごと＝請求先・配送先を独立に）: ASP から権威の `pref_id`（p）を単一 ID 取得（顧客 `fetch_customer_by_remote_id()`、受注
  `fetch_order_by_remote_id()`。`PlatformAdapter` に追加）し、現在の state（s）が旧出力（`JP{p}`）と一致し、かつ正しい値（`JP{表[p]}`）と異なり、
  **かつ国・郵便番号（数字のみ）・番地が ASP 由来の期待値（`AddressMapper::to_woo()` の出力）と一致する**場合に限り `state` のみを更新する。
  `s == 正しい値` は変更しない（冪等）。それ以外（手修正・ASP 側の住所変更・郵便番号/番地の不一致）は変更せず `unverified` として報告する
  （その県自体は旧バグの影響を受けなくても、現在の state が「旧バグが別の県で出力しうる値」のまま残っている場合は ASP 側で県が変わった可能性があるため、`ok` とは断定せず `unverified`）
  （state だけ書き換えて「新しい県＋古い郵便番号」のキメラ住所を作らない）。旧出力が取りうる state は 23 値に限られるため、それ以外の値の実体は
  ASP に照会せず「被害なし」と確定する（表は `AddressMapper::state_code()` から導出し複製しない）。旧恒等変換（`legacy`）はツール内の private に
  留め、新規書込みへの誤用の誘い水になる `AddressMapper` には置かない。
- **Scan と Repair は同一の判定関数を共有**し、書込みだけが異なる。REST は GET（Scan。Woo のデータについて読取専用。バッチごとの集計を `cbjp_logs` に1行記録する）/POST（Repair）で分け、`dry_run` の真偽値パラメータは
  作らない（欠損・型違いが書込み側に倒れる fail-open の排除。`sample-cleanup` の preview/run と同じ流儀）。`PrefStateRepair::run()` の `$apply` も既定値なし。
- **対象の絞り込み**: mappings が指す実体のうち、実在し、`_cbjp_platform` が自プラットフォーム（所有判定に不変の作成マーカー `_cbjp_created_by_import` は使わない。それは「誰が作成したか＝削除してよいか」の判定用で、
  `CustomerWriter` は email 突合で採用した既存アカウントにも住所を書くため、その誤った state もインポートが書いたものとして修復の対象になる）、顧客はスタッフ権限（`CustomerWriter::has_protected_role()`。
  `CustomerWriter` は住所を書かずにスキップするため、その state は店舗自身のデータ）でなく、受注は `WC_Order`（refund 除外）かつ `trash`/`checkout-draft` でないもの。
  該当しなければ `skipped`。新規実体は作らないため `LimitPolicy` の累積カウントに影響しない。
- **ASP アクセスは単一 ID 取得に限定**: 一覧の `ids` は受注で「直近7日」に絞られる可能性がある（要検証#18）ため使わず、日付窓の影響を受けない
  `GET /sales/{id}.json` / `GET /customers/{id}.json` を使う。無料版の上限（顧客 10・受注 10）で 1 サイト最大約 20 行のため、1 リクエスト 20 照会
  （`PrefStateRepair::DEFAULT_BUDGET`）・`{entity, offset}` の cursor で十分。**未接続・レート制限・ASP 障害は例外にせず**、処理済みの `counts` と失敗した行を
  指す `cursor` を返して中断する（503/409/502。UI は件数を失わず同じ位置から再開できる。処理は冪等）。分類は `PrefStateRepair::classify_api_failure()`:
  **認証エラー（401/403）またはアダプタが `context['not_connected'] === true` で明示した「未接続」だけを 409 にする**（ステータス 0 は `HttpClient` の通信断・
  `ColorMeClient` の JSON 破損でも使われるため、0 を一律に「再接続」と案内すると一時的な通信断でも店舗を再認可へ誘導してしまう。`ColorMeAdapter::client()` が
  文脈で明示する）。クライアント側スロットル（`RateLimitExhaustedException`）と、ASP が 429 を返してリトライ上限に達した場合（`ApiException::is_rate_limited()`）は 503
  （`Retry-After`）。アダプタが単一 ID 取得に非対応（`UnsupportedOperationException`）の場合は 501 `cbjp_repair_unsupported`（再開しても同じ結果になるため cursor は返さない）。
  ASP が返した記録の `remote_id` が要求と一致しない場合は信用しない（`unavailable`）。
- **書込み**: 顧客は `update_user_meta`（`CustomerWriter` と同経路）、受注は `WC_Order` CRUD（HPOS 対応）を `SideEffectGuard` で囲む。補正が必要な実体だけ `save()`
  する。補正した実体に監査メタ `_cbjp_state_repaired`（側ごとの from/to の JSON。同じ側を再度補正しても最初の `from`＝本当の元の値は保持）を残す。
  **`WC_Abstract_Order::save()` は保存中の例外を内部で握りつぶしてログに残すだけで ID を返す**（WC 11.1 の実ソースで確認）ため、書き込めたことは DB からの読み直しで確認し、
  一致しなければ（`update_user_meta()` は同値更新でも失敗でも false で区別できないため顧客も同様）その1件だけを `skipped`（保存失敗）として先へ進む。
  確認が無いと、保存に失敗したのに「補正した」と報告してしまう。
- **既知の制限**（PR 本文にも記載）: (1) `WC_Order::save()` は `date_modified` を現在時刻へ更新する（実測: WC 11.1.1・HPOS 有効。`set_date_modified()` で戻しても保持できない）。
  (2) 受注の保存は `woocommerce_update_order` を発火するため、Analytics 取込みの Action Scheduler アクションと `order.updated` Webhook が飛ぶ（無料版の上限内なので件数は小さい）。
  (3) `wc_customer_lookup.state`（Analytics の顧客テーブル）は `update_user_meta` では更新されない。(4) 本ツールの実行前に手作業/SQL で都道府県を一括変換した実体のうち、
  巡回置換の 9 県（19〜23・25・26・28・30）は `unverified` のまま救済できない。(5) 実店舗の実 API での確認は ColorMe 認証情報待ち（要検証#18 と合わせて）。
  (6) **「Sample data cleanup」を先に実行すると、email 突合で採用した既存アカウントは修復できなくなる**: `SampleCleanup::remove_customer()` は削除せず残すアカウントから `_cbjp_platform`/`_cbjp_remote_id` を外し
  mapping も消すため、ASP の ID を失って本ツールが列挙できない（インポートが書いた誤った state は残る）。**修復はクリーンアップより前に実行する**（UI の説明文にも明記）。
  クリーンアップ時に出自を残す設計変更は本 PR の範囲外（backlog）。なお、そのアカウントが次回のインポートで再び選ばれれば、現行コードの Writer が正しい state で上書きする。

### 10.4 付帯機能（D17）

- **dry-runレポートのCSVダウンロード**: 変換結果・警告（未マッピング決済方法、SKU重複、バリエーション軸超過等）を全量出力（F1-6 PR-A実装済み）
- **移行後検証レポート**: エンティティ別件数と受注合計金額の ASP / Woo 突合を実行結果画面に表示
- **301リダイレクトCSV**（Pro機能・Pro側で実装）: 旧商品URL→新商品URLの対応表を mappings から生成
- **エクスポート実行前の本番書込み警告**: 無料版のサンプル10件でも ASP 本番環境に書き込むため、
  実行前に確認ダイアログでテストショップの利用を推奨する

#### dry-runレポートCSVの実装詳細（F1-6 PR-A）

- **生成経路**: `Woo\Writer\EntityWriter::validate()`（`write()`と参照解決・値検証ロジックを共有する新設メソッド）
  → `Woo\DryRunRepository`（`Sync\WooWriter`実装。`validate()`しか呼ばず何も永続化しない）
  → `Sync\Importer::process_items()`がページ単位で`Sync\DryRunItemRepository`へバッチ記録
  → `Admin\DryRunReportCsv`が`GET /runs/{run_id}/report`（`Admin\RestController::get_run_report()`）でCSVをストリーミング配信
- **保存**: 新テーブル`cbjp_dry_run_items`（`(job_id, entity, remote_id)`のUNIQUE KEY + `ON DUPLICATE KEY UPDATE`で再実行冪等）。NULL許容カラムを持たず、`label=''`/`existing_local_id=0`を「無し」の番兵値とする（生SQLがnullを空文字に変換する罠を回避）。保持期間は`cbjp/dry_run_items/retention_days`フィルター（既定30日）で`Sync\LogCleanup`の日次ジョブに相乗り
- **CSV列**: `entity, remote_id, label, operation, existing_local_id, warning_code, warning_detail, note`。1アイテム×1警告=1行に展開（`WarningCode::split()`で`:`区切りを最初の1つだけ分割）。`note`列は`WarningCode::indicates_mapping_required()`が真の警告（`category_map_unresolved`と、R3-0m で加えた`payment_method_unmapped`/`shipping_method_unmapped`。マッピング設定〔Mappings タブ〕を追加すれば消える）に`mapping_required`、`indicates_pending_export()`が真の警告に`reference_pending_export`、`WarningCode::indicates_order_reference_unresolved()`が真の警告（`order_line_product_unresolved`/`order_customer_unresolved`。R3-0n）に`reference_unresolved`（未インポート、またはASP側で削除済み・インポート対象外。実店舗の受注では、この2コードの参照先はすべて ColorMe 側で削除済み〔404〕で、先にインポートしても消えなかった）、`WarningCode::indicates_pending_import()`が真の警告（`indicates_unresolved_reference()`の集合＋`stock_product_unresolved`から、前述の2コードと`order_line_variation_unmatched`〔商品は取込み済み。注記なし〕を除いたもの）に`reference_pending_import`を付与（初回dry-runではmappingsが空なため大量に出る「未インポートが原因の未解決」を、実際の不整合と区別するため。在庫は親商品未解決だとアイテム自体を保存しないためchecksumキャッシュ判定の対象外だが、レポート上は同じ注記を付ける。F1-5実機確認で判明）。UTF-8 BOM付き。全ASCII制御文字（タブ/CR/LF含む）を除去したうえで、OWASP CSVインジェクション対策として`=`/`+`/`-`/`@`始まりのセルに`'`前置
- **dry-runでは判定できない警告**（保存を実際に試みないと分からない、またはネットワークI/Oを伴うため`validate()`では意図的に実行しない）: `PRODUCT_SAVE_FAILED` / `ORDER_CREATE_FAILED` / `COUPON_SAVE_FAILED` / `TERM_CREATE_FAILED` / `TERM_UPDATE_FAILED`（更新パスのバリデーション失敗のみ。新規作成パスの名前衝突は`term_exists()`による事前チェックで`write()`と共有し判定可能） / `VARIATION_SAVE_FAILED` / `VARIATION_REMOVED` / `VARIATION_PRICE_INVALID` / `VARIATION_SNAPSHOT_INCOMPLETE`（`VariationWriter`は親ID確定後にしか走らないため） / `IMAGE_DOWNLOAD_FAILED`（dry-runは実際のダウンロードを行わない） / `CUSTOMER_CREATE_FAILED`（`CUSTOMER_EMAIL_CONFLICT`は`email_exists()`による読取専用の事前チェックで`write()`と共有し判定可能）
- **F1-6の残作業（PR-B）**: React Import タブ（エンティティ選択・dry-runプレビュー・CSVダウンロードリンク・進捗ポーリング・結果レポート・上限到達時のPro案内）と Logs タブのUI実装。バックエンド（本節の内容）はPR-Aで完結し、`GET /runs/{run_id}`（進捗）・`GET /runs/{run_id}/report`（CSV）・`GET /limits`（Pro案内用の残数）は実装済み

#### 移行後検証レポートの実装詳細（F1-7）

- **ASP側の金額**: `Sync\Importer::process_items()` が `CanonicalOrder` の `totals['total']` を、書込の成否・checksum 一致スキップに関わらず
  全 processed 分で `remote_amount`（1/100 単位の int。`Support\Money` で文字列解析し float を使わない）へ累積し、`JobRepository::empty_totals()` の
  キーとして job の `totals_json` に永続化する（`JobManager::merge_totals()` はページ毎に加算）
- **集計**（`Sync\VerificationReport`、`GET /runs/{run_id}/verification`。`type=import` の run のみ。dry-run は 400）: ジョブごとに
  `processed`（この run で ASP から取得）/ `written`（created+updated）/ `skipped` / `warned` を totals から、`linked`（mappings が指す
  ローカルIDの重複除去数）/ `existing`（`Woo\Tools\LocalEntityLookup` が 200 件ずつ実在確認。受注は要件どおり `wc_get_order()` のみで判定し
  ゴミ箱は不在扱い）/ `missing`（= linked − existing）を現在の Woo から算出する。受注は `remote_amount` と、実在するリンク済み受注の
  `WC_Order::get_total()` 合計（`local_amount`）を `"1234.00"` 形式の10進文字列で返す。通貨は店舗通貨（`currency`）と ASP 側
  （`platform_currency` = `OrderWriter::PLATFORM_CURRENCY` = JPY）を分けて返し、両者が異なる場合（`currency_mismatch`）は `OrderWriter` が
  金額を換算せずそのまま保存しているため UI は金額突合を「不可」として扱う。F1-7 より前のジョブ（`totals_json` に `remote_amount` 無し）は
  ASP 側合計を null（不明）で返す
- **解釈**: ASP側は「この run で取得した全件」（無料版の上限でスキップした分を含む）、Woo側は「リンク済みで実在する全件」（プラットフォーム
  全体・全期間）とスコープが異なる。UI（`src/components/VerificationReport.tsx`）は単なる一致/不一致ではなく差の向きを行ごとに示す:
  `missing`（実体を失った mapping → warning。Rebuild links / 再 import を案内）、`fewer`（Woo 側が取得件数より少ない → info。無料版の上限・
  スキップ・警告）、`more`（Woo 側が多い → info。過去の run で取り込んだ分、ASP 側で減った場合等）、`amount`（件数一致で受注合計のみ不一致 → warning）、
  `reconciled`。全ジョブが completed の import run にのみ表示する（failed/cancelled を含む `isTerminal` だけでゲートしない）
