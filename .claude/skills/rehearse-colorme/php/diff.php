<?php
/**
 * 2 つのスナップショットの ColorMe 側を、remote_id と項目の単位で比べる（往復の前後で ColorMe の値が変わったか）。読み取りのみ。
 * 引数: a=<label> b=<label> [entity=products|customers|coupons|sales|all]（既定 products,customers）
 *
 * - 比べないもの: `make_date`/`update_date`/`account_id`（PUT で必ず変わる・意味が無い）。
 * - 商品のバリエーション・オプションは id で突き合わせて項目ごとに比べる。
 * - 出力の各行を「想定（既知の制限）／想定外」に仕分けるのは人（SKILL.md の手順 2）。このスクリプトは事実だけを出す。
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

$cbjp_opts     = cbjp_rh_args( $args, [ 'a', 'b', 'entity' ] );
$cbjp_a        = cbjp_rh_load_snapshot( $cbjp_opts['a'] ?? '' );
$cbjp_b        = cbjp_rh_load_snapshot( $cbjp_opts['b'] ?? '' );
$cbjp_entity   = $cbjp_opts['entity'] ?? 'products,customers';
$cbjp_entities = 'all' === $cbjp_entity ? [ 'products', 'customers', 'coupons', 'sales' ] : explode( ',', $cbjp_entity );

foreach ( $cbjp_entities as $cbjp_e ) {
	if ( ! in_array( $cbjp_e, [ 'products', 'customers', 'coupons', 'sales' ], true ) ) {
		cbjp_rh_abort( "unknown entity '{$cbjp_e}'" );
	}

	if ( ! is_array( $cbjp_a['colorme'][ $cbjp_e ] ?? null ) || ! is_array( $cbjp_b['colorme'][ $cbjp_e ] ?? null ) ) {
		cbjp_rh_abort( "both snapshots need colorme.{$cbjp_e} (take them with side=both or side=colorme)" );
	}
}

const CBJP_RH_IGNORED = [ 'make_date', 'update_date', 'account_id' ];

$cbjp_show = static function ( $value ): string {
	$json = (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	return mb_strlen( $json ) > 140 ? mb_substr( $json, 0, 120 ) . '…(' . mb_strlen( $json ) . ' chars)' : $json;
};

/**
 * 連想配列を再帰的に比べ、変わった葉を `path => [old, new]` で返す。リスト（id を持つ行の配列）は id で突き合わせる。
 *
 * @return array<string,array{0:mixed,1:mixed}>
 */
$cbjp_compare = static function ( $old, $new, string $path = '' ) use ( &$cbjp_compare ): array {
	if ( ! is_array( $old ) || ! is_array( $new ) ) {
		return $old === $new ? [] : [ $path => [ $old, $new ] ];
	}

	$keyed = static function ( array $rows ): ?array {
		if ( ! array_is_list( $rows ) || [] === $rows ) {
			return null;
		}

		$out = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
				return null;
			}

			$out[ 'id=' . $row['id'] ] = $row;
		}

		return $out;
	};

	$old_keyed = $keyed( $old );
	$new_keyed = $keyed( $new );

	if ( null !== $old_keyed && null !== $new_keyed ) {
		$old = $old_keyed;
		$new = $new_keyed;
	}

	$diffs = [];

	foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $key ) {
		if ( in_array( $key, CBJP_RH_IGNORED, true ) ) {
			continue;
		}

		$sub = '' === $path ? (string) $key : "{$path}.{$key}";

		if ( ! array_key_exists( $key, $old ) ) {
			$diffs[ $sub ] = [ '(absent)', $new[ $key ] ];
		} elseif ( ! array_key_exists( $key, $new ) ) {
			$diffs[ $sub ] = [ $old[ $key ], '(absent)' ];
		} else {
			$diffs += $cbjp_compare( $old[ $key ], $new[ $key ], $sub );
		}
	}

	return $diffs;
};

$cbjp_total = 0;

foreach ( $cbjp_entities as $cbjp_e ) {
	$cbjp_old     = $cbjp_a['colorme'][ $cbjp_e ];
	$cbjp_new     = $cbjp_b['colorme'][ $cbjp_e ];
	$cbjp_added   = array_diff( array_keys( $cbjp_new ), array_keys( $cbjp_old ) );
	$cbjp_removed = array_diff( array_keys( $cbjp_old ), array_keys( $cbjp_new ) );
	$cbjp_fields  = [];

	echo "== {$cbjp_e}: {$cbjp_opts['a']} (" . count( $cbjp_old ) . ") → {$cbjp_opts['b']} (" . count( $cbjp_new ) . ") ==\n";

	// 増えた・消えた実体も差として数える（作成・削除だけのスナップショットで「変化 0」と出さないため。G3-B2）。
	foreach ( $cbjp_added as $cbjp_id ) {
		echo "  + {$cbjp_id} " . ( $cbjp_new[ $cbjp_id ]['name'] ?? '' ) . "\n";
		++$cbjp_total;
	}

	foreach ( $cbjp_removed as $cbjp_id ) {
		echo "  - {$cbjp_id} " . ( $cbjp_old[ $cbjp_id ]['name'] ?? '' ) . "\n";
		++$cbjp_total;
	}

	foreach ( array_intersect( array_keys( $cbjp_old ), array_keys( $cbjp_new ) ) as $cbjp_id ) {
		$cbjp_diffs = $cbjp_compare( $cbjp_old[ $cbjp_id ], $cbjp_new[ $cbjp_id ] );

		if ( [] === $cbjp_diffs ) {
			continue;
		}

		echo "  ~ {$cbjp_id} " . ( $cbjp_old[ $cbjp_id ]['name'] ?? '' ) . "\n";

		foreach ( $cbjp_diffs as $cbjp_path => [ $cbjp_from, $cbjp_to ] ) {
			echo "      {$cbjp_path}: " . $cbjp_show( $cbjp_from ) . ' → ' . $cbjp_show( $cbjp_to ) . "\n";
			$cbjp_field                 = preg_replace( '/id=[^.]+/', '*', $cbjp_path );
			$cbjp_fields[ $cbjp_field ] = ( $cbjp_fields[ $cbjp_field ] ?? 0 ) + 1;
			++$cbjp_total;
		}
	}

	if ( [] !== $cbjp_fields ) {
		ksort( $cbjp_fields );
		echo "  -- changed fields ({$cbjp_e}) --\n";
		foreach ( $cbjp_fields as $cbjp_field => $cbjp_count ) {
			echo "    {$cbjp_field}: {$cbjp_count}\n";
		}
	}
}

echo "total differences (changed values + added/removed entities): {$cbjp_total}\n";
