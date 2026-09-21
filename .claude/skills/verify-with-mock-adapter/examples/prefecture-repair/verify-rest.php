<?php
// 例（issue #46 県コード修復ツール）: REST を rest_do_request() で通し、Scan → Repair → 再 Scan → 再 Repair を確認する。
// 実行: mock-adapter.sh run .claude/skills/verify-with-mock-adapter/examples/prefecture-repair/verify-rest.php
// 期待: Scan は書かない（実行前後で state が同じ）／Repair で ZZV-C1・C2 と ZZV-O1 が補正される／再 Scan・再 Repair は変更 0（冪等）／
//       ZZV-C3（東京）は ASP に照会せず確定／ZZV-C4（手修正）は unverified／開発サイトに元からある実 platform の実体は skipped で無傷。
wp_set_current_user( 1 );

$call = static function ( string $method, array $params ): array {
	$request = new WP_REST_Request( $method, '/cbjp/v1/tools/repair-states' );

	// クエリ文字列をルートに含めると rest_no_route になる。GET は set_query_params()、POST は set_body_params()。
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_body_params( $params );
	}

	$response = rest_do_request( $request );

	return [
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	];
};

$ids = get_option( 'cbjp_verify_ids', [] );

$dump = static function ( string $title ) use ( $ids ): void {
	echo "--- {$title}\n";

	foreach ( $ids['users'] ?? [] as $remote => $uid ) {
		printf(
			"  %s user#%d billing=%s shipping=%s audit=%s\n",
			$remote,
			$uid,
			get_user_meta( $uid, 'billing_state', true ),
			get_user_meta( $uid, 'shipping_state', true ),
			get_user_meta( $uid, '_cbjp_state_repaired', true ) ?: '-'
		);
	}

	foreach ( $ids['orders'] ?? [] as $number => $oid ) {
		$order = wc_get_order( $oid );

		if ( ! $order ) {
			echo "  {$number} order#{$oid} (deleted)\n";

			continue;
		}

		printf( "  %s order#%d billing=%s shipping=%s audit=%s\n", $number, $oid, $order->get_billing_state(), $order->get_shipping_state(), $order->get_meta( '_cbjp_state_repaired' ) ?: '-' );
	}
};

$summary = static function ( array $r ): string {
	$counts = $r['data']['counts'] ?? $r['data']['data']['counts'] ?? [];
	$parts  = [];

	foreach ( $counts as $entity => $buckets ) {
		$parts[] = $entity . ':' . wp_json_encode( array_filter( $buckets ) );
	}

	return 'HTTP ' . $r['status'] . ' cursor=' . wp_json_encode( $r['data']['cursor'] ?? null ) . ' ' . implode( ' ', $parts );
};

$dump( 'BEFORE (legacy)' );
echo 'SCAN   : ' . $summary( $call( 'GET', [ 'platform' => 'colorme' ] ) ) . "\n";
$dump( 'AFTER SCAN (must be unchanged)' );
echo 'REPAIR : ' . $summary( $call( 'POST', [ 'platform' => 'colorme' ] ) ) . "\n";
$dump( 'AFTER REPAIR' );
echo 'RESCAN : ' . $summary( $call( 'GET', [ 'platform' => 'colorme' ] ) ) . "\n";
echo 'REPAIR2: ' . $summary( $call( 'POST', [ 'platform' => 'colorme' ] ) ) . "\n";
$dump( 'AFTER 2nd REPAIR (idempotent)' );
