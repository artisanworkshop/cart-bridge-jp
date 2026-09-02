# レビューバックログ（今回対応しない指摘）
| 追記日 | ID | 重大度 | 場所 | 内容 | 起票状況 |
|---|---|---|---|---|---|
| 2026-08-28 | R1-L1 | Low | includes/Woo/Writer/CustomerWriter.php:75-79 | `write()`内`if(null===$user_id)`が`resolve_target()`導入後に到達不能なデッドコードになっている | 未起票 |
| 2026-08-28 | R1-L2 | Low | includes/Admin/RestController.php:78-96 | CSVストリーミングの`rest_pre_serve_request`自己解除フィルター配線は実HTTPサーブ経路でのみ発火し、`dispatch()`ベースのユニットテストでは配線自体をE2E確認できない（既存方針でintegration testに分離すべき範囲） | 未起票 |
