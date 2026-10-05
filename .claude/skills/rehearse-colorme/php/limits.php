<?php
/**
 * `rehearse.sh limits-on/limits-off` から呼ばれる。オプション `cbjp_rehearsal_limits` を保存・削除する。
 * 引数: set=<json>（`{"order":2}` や全 entity null など。値は整数か null だけ） / clear=1
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

$cbjp_opts = cbjp_rh_args( $args, [ 'set', 'clear' ] );

if ( isset( $cbjp_opts['clear'] ) ) {
	delete_option( 'cbjp_rehearsal_limits' );

	if ( false !== get_option( 'cbjp_rehearsal_limits', false ) ) {
		cbjp_rh_abort( 'could not delete the option cbjp_rehearsal_limits' );
	}

	echo "cleared cbjp_rehearsal_limits\n";

	return;
}

$cbjp_entities = [ 'category', 'tag', 'product', 'customer', 'order', 'stock', 'coupon', 'review' ];
$cbjp_limits   = json_decode( (string) ( $cbjp_opts['set'] ?? '' ), true );

if ( ! is_array( $cbjp_limits ) || [] === $cbjp_limits || array_is_list( $cbjp_limits ) ) {
	cbjp_rh_abort( 'set=<json> must be a non-empty object like {"order":2} or {"product":null,...}' );
}

foreach ( $cbjp_limits as $cbjp_entity => $cbjp_value ) {
	if ( ! in_array( $cbjp_entity, $cbjp_entities, true ) ) {
		cbjp_rh_abort( "unknown entity '{$cbjp_entity}' (allowed: " . implode( ', ', $cbjp_entities ) . ')' );
	}

	if ( ! is_int( $cbjp_value ) && null !== $cbjp_value ) {
		cbjp_rh_abort( "the limit for '{$cbjp_entity}' must be an integer or null" );
	}
}

update_option( 'cbjp_rehearsal_limits', $cbjp_limits, false );

// `update_option()` は値が同じでも false を返すので、戻り値ではなく読み直した値で確かめる。保存に失敗したまま mu-plugin を置くと、
// 古い上限のまま run が進んでサンプル・本移行の結果を取り違える（G1-4・G1-6。`rehearse.sh` はここが失敗すると mu-plugin を置かない）。
wp_cache_delete( 'cbjp_rehearsal_limits', 'options' );
$cbjp_stored = get_option( 'cbjp_rehearsal_limits' );

if ( $cbjp_stored !== $cbjp_limits ) {
	cbjp_rh_abort( 'cbjp_rehearsal_limits was not saved as requested (stored: ' . wp_json_encode( $cbjp_stored ) . ')' );
}

echo 'cbjp_rehearsal_limits = ' . wp_json_encode( $cbjp_stored ) . "\n";
