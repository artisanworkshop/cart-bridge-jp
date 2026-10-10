<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Woo;

use CartBridgeJP\Pro\Woo\CommerceWarningCode;
use CartBridgeJP\Woo\WarningCatalog;
use CartBridgeJP\Woo\WarningCode;
use ArgumentCountError;
use ReflectionClass;
use ReflectionMethod;
use ValueError;
use WP_UnitTestCase;

final class CommerceWarningCatalogTest extends WP_UnitTestCase {

	private const DIRECTIONS = [ WarningCatalog::IMPORT, WarningCatalog::EXPORT ];

	/**
	 * CSV の行の種別（`JobManager` の実体の種別）と、種別の分からない空。
	 */
	private const ENTITIES = [ '', 'category', 'tag', 'product', 'customer', 'order', 'stock', 'coupon', 'review' ];

	private const KNOWN_SEVERITIES = [
		WarningCatalog::SEVERITY_BLOCKING,
		WarningCatalog::SEVERITY_ACTION_REQUIRED,
		WarningCatalog::SEVERITY_INFO,
	];

	/**
	 * `WarningCode` の全定数の値（警告コード）。
	 *
	 * @return array<string,string>
	 */
	private static function all_codes(): array {
		// 顧客・受注・クーポンのコード。説明は Pro が登録した種類が持つ（`Entities\*Warnings`）。
		$codes = ( new ReflectionClass( CommerceWarningCode::class ) )->getConstants();

		return array_filter( $codes, 'is_string' );
	}

	/**
	 * 文言の無い警告コードを出荷させない: 全定数について、両方向・どの行の種別でも既知の重大度と空でない原因がある。
	 * 「対処が要る」なら対処も空でない。
	 */
	public function test_every_warning_code_is_described_in_both_directions(): void {
		$codes = self::all_codes();
		$this->assertGreaterThan( 40, count( $codes ) );

		$problems = [];

		foreach ( $codes as $name => $code ) {
			foreach ( self::DIRECTIONS as $direction ) {
				foreach ( self::ENTITIES as $entity ) {
					$description = WarningCatalog::describe( $code, $direction, $entity );
					$where       = "{$name}/{$direction}/{$entity}";

					if ( ! in_array( $description['severity'], self::KNOWN_SEVERITIES, true ) ) {
						$problems[] = "{$where}: severity {$description['severity']}";
					}

					if ( '' === trim( $description['message'] ) ) {
						$problems[] = "{$where}: empty message";
					}

					if ( WarningCatalog::SEVERITY_ACTION_REQUIRED === $description['severity'] && '' === trim( $description['action'] ) ) {
						$problems[] = "{$where}: action_required without an action";
					}
				}
			}
		}

		$this->assertSame( [], $problems );
	}

	/**
	 * エクスポートを止める判定関数（`indicates_export_blocking()`）とカタログの重大度が食い違わない。
	 */
	public function test_export_blocking_codes_are_described_as_blocking_on_export(): void {
		$problems = [];

		foreach ( self::all_codes() as $name => $code ) {
			if ( ! WarningCode::indicates_export_blocking( [ $code ] ) ) {
				continue;
			}

			$severity = WarningCatalog::describe( $code, WarningCatalog::EXPORT )['severity'];

			if ( WarningCatalog::SEVERITY_BLOCKING !== $severity ) {
				$problems[] = "{$name}: {$severity}";
			}
		}

		$this->assertSame( [], $problems );
	}

	/**
	 * 書式のプレースホルダ（`%s`・`%1$s`）を差し込まないまま返さない（detail の有無の両方）。
	 */
	public function test_no_description_leaks_a_placeholder(): void {
		$problems = [];

		foreach ( self::all_codes() as $name => $code ) {
			foreach ( self::DIRECTIONS as $direction ) {
				foreach ( [ $code, WarningCode::with_detail( $code, 'DETAIL-Z' ) ] as $warning ) {
					$description = WarningCatalog::describe( $warning, $direction );

					foreach ( [ 'message', 'action' ] as $key ) {
						if ( 1 === preg_match( '/%(\d+\$)?[sd]/', $description[ $key ] ) ) {
							$problems[] = "{$name}/{$direction}/{$warning}: {$key}";
						}
					}
				}
			}
		}

		$this->assertSame( [], $problems );
	}

	/**
	 * detail を差し込む文言は `sprintf()` に通るので、`%` を `%%` と書き忘れると `ValueError` で detail の無い文言へ黙って倒れる。
	 * 全コード×向きの detail 付きの文言が書式として正しく、detail を含むことを確かめる（`entry()` は非公開なのでリフレクションで呼ぶ）。
	 */
	public function test_every_detail_template_formats_and_includes_the_detail(): void {
		$entry    = new ReflectionMethod( WarningCatalog::class, 'entry' );
		$problems = [];
		$checked  = 0;

		foreach ( self::all_codes() as $name => $code ) {
			foreach ( [ true, false ] as $import ) {
				foreach ( self::ENTITIES as $entity ) {
					$template = $entry->invoke( null, $code, $import, $entity )['detail_message'] ?? '';

					if ( '' === $template ) {
						continue;
					}

					++$checked;

					try {
						$formatted = sprintf( $template, 'DETAIL-Z' );
					} catch ( ValueError | ArgumentCountError $error ) {
						$problems[] = "{$name}/{$entity}: " . $error->getMessage();
						continue;
					}

					if ( ! str_contains( $formatted, 'DETAIL-Z' ) ) {
						$problems[] = "{$name}/{$entity}: the detail is not in the message";
					}
				}
			}
		}

		$this->assertGreaterThan( 40 * count( self::ENTITIES ), $checked );
		$this->assertSame( [], $problems );
	}

	/**
	 * 同じコードでも向きで意味が違う（取込みは保存して知らせ、エクスポートは送らない）。
	 */
	public function test_the_direction_changes_the_description(): void {
		$import = WarningCatalog::describe( CommerceWarningCode::CURRENCY_MISMATCH, WarningCatalog::IMPORT );
		$export = WarningCatalog::describe( CommerceWarningCode::CURRENCY_MISMATCH, WarningCatalog::EXPORT );

		$this->assertNotSame( WarningCatalog::SEVERITY_BLOCKING, $import['severity'] );
		$this->assertSame( WarningCatalog::SEVERITY_BLOCKING, $export['severity'] );
		$this->assertNotSame( $import['message'], $export['message'] );
	}

	/**
	 * 管理者・スタッフのアカウントと同じメールの顧客は、顧客の行ではプロフィールを書かずに飛ばし（`CustomerWriter`）、
	 * 受注の行ではゲスト受注として書く（`OrderWriter`）。同じコードでも行の種別で重大度が違い、種別が分からなければ重いほうに倒す。
	 */
	public function test_a_protected_customer_is_described_by_the_row_entity(): void {
		$warning  = WarningCode::with_detail( CommerceWarningCode::CUSTOMER_ACCOUNT_PROTECTED, 'c-1' );
		$customer = WarningCatalog::describe( $warning, WarningCatalog::IMPORT, 'customer' );
		$order    = WarningCatalog::describe( $warning, WarningCatalog::IMPORT, 'order' );

		$this->assertSame( WarningCatalog::SEVERITY_BLOCKING, $customer['severity'] );
		$this->assertSame( WarningCatalog::SEVERITY_ACTION_REQUIRED, $order['severity'] );
		$this->assertNotSame( $customer['message'], $order['message'] );

		foreach ( [ '', 'product', 'Order' ] as $entity ) {
			$this->assertSame( $customer, WarningCatalog::describe( $warning, WarningCatalog::IMPORT, $entity ), $entity );
		}

		$this->assertSame( $customer, WarningCatalog::describe( $warning, WarningCatalog::IMPORT ) );
	}

	/**
	 * 知らない向きは取込み扱いにしない（どちらの説明でも誤りうる）。
	 */
	public function test_an_unknown_direction_is_described_as_unknown(): void {
		foreach ( [ '', 'sideways', 'IMPORT' ] as $direction ) {
			$description = WarningCatalog::describe( CommerceWarningCode::CURRENCY_MISMATCH, $direction );

			$this->assertSame( WarningCatalog::SEVERITY_UNKNOWN, $description['severity'], $direction );
			$this->assertStringContainsString( CommerceWarningCode::CURRENCY_MISMATCH, $description['message'] );
		}
	}
}
