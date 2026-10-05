<?php
/**
 * Plugin Name: CBJP rehearsal limits (dev only)
 * Description: cbjp-rehearsal-limits: managed by .claude/skills/rehearse-colorme — overrides the free-version limits from the option `cbjp_rehearsal_limits`.
 *
 * リハーサル（rehearse-colorme スキル）専用。`rehearse.sh limits-on '<json>'` が置き、`limits-off` が消す。
 * オプション `cbjp_rehearsal_limits`（`{ entity: int|null }`）のキーがある entity だけ `cbjp/limits/{entity}` を差し替える。
 * 0 以上の int はその件数、null は上限なし（Pro 版の解除の模擬。F1-8 と同じ方法）。キーが無い・型が違う値・負の値は元の上限のまま。
 * mu-plugin は Action Scheduler・管理画面・CLI のどのプロセスでも読み込まれるので、`eval-file` の中で add_filter するのと違って run 全体に効く。
 * 致命的エラーを出さない（開発サイトの全リクエストを落とすため。`.claude/rules/skill-scripts.md`）。
 *
 * @package CartBridgeJP
 */

foreach ( [ 'category', 'tag', 'product', 'customer', 'order', 'stock', 'coupon', 'review' ] as $cbjp_rehearsal_entity ) {
	add_filter(
		"cbjp/limits/{$cbjp_rehearsal_entity}",
		static function ( $limit ) use ( $cbjp_rehearsal_entity ) {
			$limits = get_option( 'cbjp_rehearsal_limits', [] );

			if ( ! is_array( $limits ) || ! array_key_exists( $cbjp_rehearsal_entity, $limits ) ) {
				return $limit;
			}

			$value = $limits[ $cbjp_rehearsal_entity ];

			// 0 以上の整数か null だけを使う（負の値など壊れた値は元の上限のまま）。
			return ( is_int( $value ) && $value >= 0 ) || null === $value ? $value : $limit;
		},
		20
	);
}
unset( $cbjp_rehearsal_entity );
