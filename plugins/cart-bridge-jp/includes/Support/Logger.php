<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Support;

/**
 * `cbjp_logs` テーブルへの書き込み + `WC_Logger` へのミラー（source: cart-bridge-jp）。
 *
 * 個人情報禁止ルール: $context には エンティティ種別・remote_id・local_id 等のIDのみを含めること。
 * 氏名・メールアドレス・住所・電話番号・APIトークン等の記録は禁止（コードレビュー観点として厳守する）。
 */
final class Logger {

	public const LEVEL_DEBUG   = 'debug';
	public const LEVEL_INFO    = 'info';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_ERROR   = 'error';

	/**
	 * @param array<string,mixed> $context IDのみ許可。個人情報・トークン禁止。
	 */
	public function log( string $level, string $message, array $context = [], ?int $job_id = null ): void {
		$this->write_to_db( $level, $message, $context, $job_id );
		$this->mirror_to_wc_logger( $level, $message, $context );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function debug( string $message, array $context = [], ?int $job_id = null ): void {
		$this->log( self::LEVEL_DEBUG, $message, $context, $job_id );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function info( string $message, array $context = [], ?int $job_id = null ): void {
		$this->log( self::LEVEL_INFO, $message, $context, $job_id );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function warning( string $message, array $context = [], ?int $job_id = null ): void {
		$this->log( self::LEVEL_WARNING, $message, $context, $job_id );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function error( string $message, array $context = [], ?int $job_id = null ): void {
		$this->log( self::LEVEL_ERROR, $message, $context, $job_id );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function write_to_db( string $level, string $message, array $context, ?int $job_id ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'cbjp_logs',
			[
				'job_id'       => $job_id,
				'level'        => $level,
				'message'      => $message,
				'context_json' => empty( $context ) ? null : wp_json_encode( $context ),
				'created_at'   => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function mirror_to_wc_logger( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		$logger->log(
			$this->map_level_to_wc( $level ),
			$message,
			[
				'source'  => 'cart-bridge-jp',
				'context' => $context,
			]
		);
	}

	private function map_level_to_wc( string $level ): string {
		return match ( $level ) {
			self::LEVEL_DEBUG => \WC_Log_Levels::DEBUG,
			self::LEVEL_WARNING => \WC_Log_Levels::WARNING,
			self::LEVEL_ERROR => \WC_Log_Levels::ERROR,
			default => \WC_Log_Levels::INFO,
		};
	}
}
