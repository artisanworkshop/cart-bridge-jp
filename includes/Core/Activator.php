<?php
/**
 * プラグイン有効化時のDBテーブル作成。
 *
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Core;

/**
 * `docs/03-design-decisions.md` §3 のDDLを dbDelta で作成する。
 */
final class Activator {

	public const DB_VERSION = '0.1.0';

	public const DB_VERSION_OPTION = 'cbjp_db_version';

	public static function activate(): void {
		self::maybe_upgrade();
	}

	/**
	 * 保存済みDBバージョンと比較し、必要であればテーブルを作成/更新する。
	 * activation hook以外（プラグイン更新時等）からも呼べるように分離。
	 */
	public static function maybe_upgrade(): void {
		$installed_version = get_option( self::DB_VERSION_OPTION );

		if ( self::DB_VERSION === $installed_version ) {
			return;
		}

		self::create_tables();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$jobs_table     = $prefix . 'cbjp_jobs';
		$mappings_table = $prefix . 'cbjp_mappings';
		$logs_table     = $prefix . 'cbjp_logs';

		$sql = "CREATE TABLE {$jobs_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id char(36) NOT NULL,
  type varchar(20) NOT NULL,
  platform varchar(20) NOT NULL,
  entity varchar(20) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  cursor_json text NULL,
  totals_json text NULL,
  error_json text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY run_id (run_id),
  KEY status_platform (status, platform)
) {$charset_collate};
CREATE TABLE {$mappings_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  platform varchar(20) NOT NULL,
  entity_type varchar(20) NOT NULL,
  remote_id varchar(191) NOT NULL,
  local_id bigint(20) unsigned NOT NULL,
  checksum char(64) NULL,
  synced_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY platform_entity_remote (platform, entity_type, remote_id),
  KEY platform_entity_local (platform, entity_type, local_id)
) {$charset_collate};
CREATE TABLE {$logs_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id bigint(20) unsigned NULL,
  level varchar(10) NOT NULL,
  message text NOT NULL,
  context_json text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY job_level (job_id, level),
  KEY created_at (created_at)
) {$charset_collate};";

		dbDelta( $sql );
	}
}
