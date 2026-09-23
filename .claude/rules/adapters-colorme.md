---
paths:
  - "includes/Adapters/**"
  - "includes/Canonical/**"
  - "includes/Woo/Support/AddressMapper.php"
  - "tests/unit/Adapters/**"
  - "tests/unit/Canonical/**"
  - "tests/unit/Woo/Support/AddressMapperTest.php"
  - "tests/fixtures/**"
---

# アダプタ・カラーミー固有の落とし穴

> ASP アダプタ（`includes/Adapters/**`）・Canonical モデル・カラーミー API のフィクスチャ/テスト・住所変換（`AddressMapper`）を触るときに効く。カラーミー API の仕様の癖と、新しい ASP アダプタでも守る共通の基準を含む。
> CLAUDE.md の「コーディング規約」「アーキテクチャ原則」と併せて守る。ここは**触るファイルに対応するときだけ読み込まれる**パス指定ルール（`paths` frontmatter）で、内容は CLAUDE.md にあった項目をそのまま移したもの（issue/PR の番号は各項目に残してある）。

- カラーミー `shopCoupon.usage_limit` は `indisposable`/`disposable` の**enum文字列**（1ユーザーあたりの利用回数制限）であり、`CanonicalCoupon::usage_limit`（発行総数のint）に対応するのは別フィールドの `total_usage_limit`。`(int) 'indisposable'` は `0` になるため誤って `usage_limit` にキャストしないこと
- カラーミー `shopCoupon.group_limit_type`（`none`/`including`/`excluding`）は**商品**グループによる制限であり会員グループ制限ではない（swagger の description で確認可能）。「会員グループ限定クーポン」と読み違えたコメントが実際にコードへ入り込んだ実績があるため、制限系フィールドの意味は必ず swagger の description を読んでから書くこと
- `CanonicalCoupon::$has_unsupported_restrictions` は三値（`false`=Wooへ写せない制限が残っていない / `true`=残っている / `null`=アダプタが宣言していない）。`CouponWriter` は `true` と `null` の両方で保存を見送るため、新しいアダプタのクーポン変換で明示的に `false` を渡し忘れるとクーポンが1件も取り込まれない（テストで `new CanonicalCoupon(...)` を書く場合も同じ。名前付き引数 `has_unsupported_restrictions:` を使う）。Woo自身は商品・カテゴリ・メール制限をネイティブに持つが、`CanonicalCoupon` にそれらを運ぶフィールドが無く `CouponWriter` も該当setterを呼ばないため、**現時点で `false` にしてよいのは「ASP側に制限が無い」場合のみ**。「Wooに機能があるから写せるはず」で `false` を立てると制限が落ちた無制限クーポンが保存される
- カラーミー `product.images[]` には `mobile: true` の項目（PC用画像のモバイル向け重複エントリ）が混在する。フィルタせず取り込むとWoo側で画像が重複登録される
- カラーミー受注（`sale`）の `tax` フィールドは商品分の消費税のみで送料分を含まない。注文全体の税額（Wooの合計と整合する値）が必要な場合は `totals.normal_tax_amount + totals.reduced_tax_amount` を使うこと
- カラーミーの `display_state` はエンドポイントごとにenumが異なりうる（例: `GET /v1/groups`のレスポンスは`showing/hidden/showing_for_members/sale_for_members`の4値だが、`POST /v1/groups`のリクエストスキーマは`showing/hidden/members_only`の3値で別物）。修正時はレスポンス側の実際のスキーマ行を確認してから判定条件を書くこと
- `CanonicalProduct::stock`（バリエーション含む）に`null`を渡すと`Importer`は「在庫管理外＝在庫あり」と解釈する。在庫管理対象なのに実数が不明な場合は`null`ではなく`0`を返すこと
- 変換層（Transformer）が一部の行を除外・展開しうるエンティティ（バリエーション展開、非公開行の除外、変換失敗行のスキップ等）では、APIの生行数（`meta.total`等）をそのまま`Page::$total`として返さないこと。`processed`と1:1対応するとは限らず、進捗率が100%を超えたり永遠に届かなかったりする。1:1対応を保証できない場合はnullを返し、ページング終端の判定にだけ使う（`ColorMeAdapter`のproduct/customer/order/stock参照）。新しいASPアダプタでも同じ基準を適用すること
- カラーミー `product.options[]` はバリエーション軸の定義そのもの（`variants[].option1/option2.name` と同名）。`CanonicalProduct::$options`（非バリエーション属性）へ転記すると `ProductWriter` が同名衝突とみなし全バリエーション商品に `attribute_name_collision` が付く。軸名と一致するものは変換層で除外すること
- `Adapters\ColorMe\Transform\Cast::money()` は欠損・非数値を無言で`0`に丸める。税込/税抜のように対になったフィールドで片方だけこれを使うと、一方は非ゼロなのにもう一方が黙って`0`という財務的に矛盾した値になり得る（`OrderTransformer`の`unit_price_excl_tax`が実例。issue #14）。「金額が0円」と「復元できない」を区別してフェイルクローズの分岐に使いたい場合は`Cast::money_or_null()`（null透過）を使うこと
- 税込換算等の金額計算はfloat除算（`$amount / 100`等）を避け、整数演算（先に乗算してから`intdiv()`に`+50`/`+99`等の丸め調整値を足す）で行うこと。`CanonicalProduct::$price`が浮動小数点誤差を避けるため金額を文字列で保持する設計と揃える。境界値（税率0〜100・価格1〜200万円）を網羅してもfloatの丸め誤りは実際には再現しなかったが、財務計算では確定的な整数演算を優先する（`ProductTransformer::round_tax()`参照。issue #24）
- ASPのboolean系フィールド（例: `tax_reduced`）がswaggerで必須指定されていない場合、`true === Cast::to_bool_or_null(...) ? A : B`のような三項演算子は欠損・非boolean値も無条件にBへ倒す。その分岐が税率選択等の金額計算に影響するなら、欠損は「Bとみなす」のではなく「不明」としてnullを返し換算自体を諦めること（`ProductTransformer::list_price_including_tax()`参照。issue #24）
- `ProductWriter::resolve_sale_price()`は`sale_price <= 0`を不正とみなし`regular_price`を有効価格として採用する。この仕様を知らずにTransformer側で新しい価格分岐ロジックを書くと、正規の無料商品（`sales_price=0`）に高い定価が設定されている場合、無料商品が定価の有料商品に化ける。価格を条件分岐させるTransformerを書く際は対応するWriterの無効値判定を必ず確認すること（issue #24）
- `ColorMeAdapter`のようにAPI由来の設定（`shop.json`の税設定等）をインスタンス単位でキャッシュするTransformerは、`TokenStore::get()`のペイロードキャッシュ（インスタンス単位で永続）・`AdapterRegistry`（プラットフォーム単位でPHPプロセス単位に静的キャッシュ）と同じ寿命を共有する。同一プロセス内で片方だけ新しくなることはない（再接続は別プロセス・別インスタンスで行われるため、次のプロセスで両方作り直される）。「再接続時にキャッシュだけ古くなる」という指摘を見たら、まずこの寿命が本当にズレるか確認すること（issue #24）
- カラーミーAPIのswagger.json（OpenAPI定義）のパステンプレート（例: `/v1/sales`）は拡張子なしだが、実際のAPIリクエストパスは`.json`拡張子付き（`GET/POST /v1/sales.json`）が正しい（swagger内のAPI利用説明・curl例、`ColorMeAdapter`の実装で確認可能）。レビューbotがswagger定義の生パスへの統一を提案してくることがあるが、鵜呑みにせず実装・利用例と照合すること（issue #26）
- `ProductWriter::variation_axis_names()`はoption1が無くoption2のみの商品（ColorMeのoption1/2は独立フィールドで構造的にありうる）も配列キーの欠番（`[1 => 'Size']`）として保持するが、保存後のWC商品属性は`WC_Product_Attribute::get_position()`が単なる出現順で欠番があっても0番から詰められ、どちらのスロット（option1/2）由来だったかを覚えていない。永続化後のデータから受注明細のoption1/2値と属性を対応付ける処理を書く際は、スロット番号（0=option1固定）で対応付けず、非null値の「個数」と軸の数を突き合わせて位置ペアで対応付けること（`ProductResolver::resolve_variation_by_options()`参照。issue #27）
- カラーミーの決済/配送方法のID→名称は `GET /payments.json`/`GET /deliveries.json` で取得できる（`ColorMeAdapter` が `OrderTransformer` 用の名称マップ構築に内部利用済み。同じ取得ロジックを `ColorMeAdapter::mapping_candidates()` がE2-1のマッピングUI向け候補一覧としても再利用している）。OAuth接続さえ済んでいればColorMe管理画面へのブラウザログイン（副管理者アカウントでは受注詳細等の一部ページが権限不足で見られないことがある）は不要
- 同一フィールド名でもASPの書込み（POST/PUT）リクエストスキーマと読出し（GET）レスポンススキーマが別物のことがある。カラーミー`POST /products/{id}/options`の`values`はリクエストでは**オブジェクトの配列**（`[{"name":"赤"}]`）だが、`GET /products.json`の`options[].values`は**文字列配列**（`["赤","青"]`）。GETレスポンスのスキーマをそのまま書込みに流用すると422になり、修正しなければバリエーションが1件も作成できない（issue #43）。書込み系エンドポイントは必ずswaggerの`requestBody`定義を個別に確認すること
- 1エンティティのpushが複数リクエストに分割される実装（例: 商品本体→バリエーション→画像）では、各サブリクエストの失敗を「再試行対象」（429/5xx/通信断）と「終端」（その他4xx。再試行しても解決しない）に分類し、`RateLimitExhaustedException`は**全てのサブリクエストのcatchで最優先に再スロー**すること（握り潰すとレート制限時にジョブ全体を一時停止する契約が壊れる）。`ColorMeAdapter::is_retryable_failure()`/`record_failure()`/`append_failure_warning()`参照（issue #43）。他ASPのpush実装・push_customer/order/stockでも同じパターンを踏襲する
- 税込⇔税抜の相互変換で丸め方式（切り捨て/切り上げ/四捨五入）を実装する場合、順方向（税抜→税込）と逆方向（税込→税抜）で**同じ丸め方向を使うと往復が一致しない**（切り捨て・切り上げは逆方向の丸めが真の逆演算になる。四捨五入は同方向でよい。`php -r`で6000通りのnet/rate組を全数検証済み。`ProductTransformer::divide_with_rounding()`参照、issue #43）
- **【重大】カラーミーの `pref_id` はJIS X 0401（＝Wooの`JPxx`）と並びが一致しない（23県。例: `pref_id=4`は秋田だがJIS/Wooの4は宮城、16↔18、19〜23・25〜34・36〜37・43〜44）**。番号をそのまま同一視しない（`AddressMapper::PREF_ID_TO_JIS_NUMBER`、issue #44）。対応表はswaggerの`info.description`の**散文**（`<details>`内のMarkdown表）にしか無く、JSON実例1件から「標準通り」と一般化すると誤る（一度誤った）。**フィクスチャ既定の`pref_id=13`（東京）は表の固定点で何も検出できない**ため、テストには4/5・19〜23・25〜30等の固定点でない値を使うこと
- `ApiException`のステータス0は「未接続（`ColorMeAdapter::client()`）」「通信断（`HttpClient`のWP_Error）」「JSON破損（`ColorMeClient`）」のすべてで使われ、**0だけでは判別できない**。「再接続が必要」と案内してよいのは401/403か`context['not_connected'] === true`（アダプタが明示）のみ。ASPが429を返してリトライ上限に達した場合は`is_rate_limited()`（`RateLimitExhaustedException`はクライアント側スロットルのみ）。新しいアダプタは未接続を`not_connected`で明示すること（issue #46）
- カラーミーの`address1`は「市区町村・番地」を1フィールドに含むが、WooCommerceのJPロケール（`WC()->countries->get_address_fields('JP')`）は`billing_city`（市区町村）と`billing_address_1`（番地）を別の必須フィールドとして扱う。エクスポート方向でColorMeへ住所を送る際は`city`+`address_1`の連結が必要（`Woo\Support\AddressMapper::to_asp_address_payload()`参照。E2-3 PR-Cで`CustomerTransformer`から移設し、新設`OrderTransformer`（配送先・ゲスト顧客）とも共有する形にした）
- ASP側のpush系API（例: カラーミー`sale.details[].price`）が単価×数量方式の場合、Woo側の明細合計を数量で割った値をそのまま丸めて送ると、割り切れない数量（例: ¥1000を3個で割ると¥333.33...）で単価×数量が実際の合計と一致しなくなる（`price=333, product_num=3`→ColorMe側は¥999として計算し¥1円分が消える）。この場合に「単価を省略してASP側のカタログ価格へフォールバックする」設計は、それ自体が`remote_id`確定後は再試行されない恒久的な金額の食い違いを生む（`PRODUCT_PRICE_INVALID`と同じ金銭的リスクの構図）。単価を復元できない明細がある場合は省略せず受注全体をブロックすること（`Adapters\ColorMe\Transform\OrderTransformer::unit_price_divides_evenly()`参照。issue #45。Codexレビューで自分自身が入れた「省略フォールバック」修正の危険性を1ラウンド後に指摘され撤回した）
- `Adapters\ColorMe\Transform\Cast::to_string_or_null()`はリテラルな空文字列`""`のみをnullへ正規化し、空白のみの値（例: `"   "`）は非空文字列として扱う。手入力ミス・不正なCSV取込等で境界データが空白のみになりうる「存在チェック」（例: 配送先住所の有無判定）にこれを使うと、空白だけの値を「存在する」と誤判定し正しいフォールバック（例: 請求先住所）が起きない。この用途では`Cast::to_meaningful_string_or_null()`（トリム後に空ならnull。値自体はトリムしない）を使うこと（issue #45）
- カラーミーのバリエーション更新スキーマ（`productVariantUpdateRequest`。`PUT /v1/products/{id}/variants/{id}`）には、商品レベルの`stock_managed`に相当する「このバリエーションは在庫管理しない」を明示するフィールドが存在しない。`stocks`はnullable整数だが、他のnullableフィールド（`weight`/`option_price`等）と違い「`null`で未設定に戻る」という記載が無く、逆に「全バリエーション未設定の状態で1件でも値を送ると他バリエーションの在庫が0になる」という副作用のみ記載されている。バリエーション単位で在庫管理外を表現したい場合は未確認の挙動に賭けず、APIを呼ばずフェイルクローズすること（`Adapters\ColorMe\Transform\StockTransformer::to_variant_payload()`参照。issue #47）
- カラーミーのswagger説明文が「Xという副作用が起きる」と数値フィールドについて記載していても、関連する別型のフィールド（例: 真偽値フラグ）が同時に変化するとは限らない。`variant.stocks`の説明文「全バリエーション未設定の状態で1件でも値を送ると**商品全体の在庫数**（数値）がバリエーション在庫に揃う」を根拠に「商品側`product.stock_managed`（真偽値フラグ）も自動でtrueになる」と解釈したが、review-loop R2の検証で「数値が揃うことと真偽値フラグが変わることは別」と指摘され訂正した（issue #47）。ある副作用の記載を、明記されていない別フィールドへの副作用の根拠として流用しないこと。曖昧なまま実装するより、該当フィールドを明示的に設定するコードを書く方が安全
