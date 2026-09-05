# レビューバックログ（今回対応しない指摘）
| 追記日 | ID | 重大度 | 場所 | 内容 | 起票状況 |
|---|---|---|---|---|---|
| 2026-08-28 | R1-L1 | Low | includes/Woo/Writer/CustomerWriter.php:75-79 | `write()`内`if(null===$user_id)`が`resolve_target()`導入後に到達不能なデッドコードになっている | 未起票 |
| 2026-08-28 | R1-L2 | Low | includes/Admin/RestController.php:78-96 | CSVストリーミングの`rest_pre_serve_request`自己解除フィルター配線は実HTTPサーブ経路でのみ発火し、`dispatch()`ベースのユニットテストでは配線自体をE2E確認できない（既存方針でintegration testに分離すべき範囲） | 未起票 |
| 2026-09-04 | f1-5-list-price/R1-X1 | High（対象外） | includes/Adapters/ColorMe/ColorMeAdapter.php:305,371,390 | `order_transformer()`の基盤取得（payments.json/deliveries.json）失敗が`transform_rows_flat()`の行レベルcatchに飲み込まれ、「1件目の受注の変換失敗」として毎回誤ログされページ全体が0件変換で完了しうる。`fetch_product_by_remote_id`で同種バグを修正した際に発見（R1参照）。別issue化を推奨 | 未起票 |
| 2026-09-04 | f1-5-list-price/R1-L1 | Low | includes/Adapters/ColorMe/Transform/ProductTransformer.php:127-134 | `round_off`（四捨五入と推測）の丸め挙動はswaggerに明記されておらず実機未検証（端数が出ない価格でしか実測していない）。F1-8実機E2Eで端数の出る価格の商品を使って確認すること | 未起票 |
| 2026-09-04 | f1-5-list-price/R1-L2 | Low | includes/Adapters/ColorMe/ColorMeAdapter.php:521-538 | `product_transformer()`はインスタンス単位でのみ`shop.json`をキャッシュし、プロセスを跨ぐ大規模カタログ同期では毎ページ再取得しうる（`order_transformer()`も同様の既存パターン）。`test_connection()`が`contract_plan`を`TokenStore`のextrasにキャッシュしている仕組みを税設定にも適用すれば削減可能 | 未起票 |
