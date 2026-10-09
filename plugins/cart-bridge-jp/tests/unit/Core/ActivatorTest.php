<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Core;

use CartBridgeJP\Core\Activator;
use WP_UnitTestCase;

final class ActivatorTest extends WP_UnitTestCase {

	public function test_activate_creates_the_three_tables(): void {
		delete_option( Activator::DB_VERSION_OPTION );

		Activator::activate();

		global $wpdb;

		foreach ( [ 'cbjp_jobs', 'cbjp_mappings', 'cbjp_logs' ] as $table ) {
			$full_table_name = $wpdb->prefix . $table;
			$found           = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table_name )
			);

			$this->assertSame( $full_table_name, $found, "Table {$full_table_name} was not created." );
		}

		$this->assertSame( Activator::DB_VERSION, get_option( Activator::DB_VERSION_OPTION ) );
	}

	public function test_maybe_upgrade_is_a_noop_when_version_matches(): void {
		update_option( Activator::DB_VERSION_OPTION, Activator::DB_VERSION );

		// 例外が発生しないこと（dbDeltaの再実行含め冪等であること）を確認する。
		Activator::maybe_upgrade();

		$this->assertSame( Activator::DB_VERSION, get_option( Activator::DB_VERSION_OPTION ) );
	}
}
