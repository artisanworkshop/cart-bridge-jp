<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures\Gizmo;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\WarningFlag;
use CartBridgeJP\Entities\WarningText;
use CartBridgeJP\Entities\WooServices;
use CartBridgeJP\Woo\Reader\EntityReader;
use CartBridgeJP\Woo\Support\EntityOrigin;
use CartBridgeJP\Woo\Tools\Link\PostLinkSource;
use CartBridgeJP\Woo\Tools\LocalEntityLookup;
use CartBridgeJP\Woo\WarningCatalog;
use CartBridgeJP\Woo\Writer\EntityWriter;
use RuntimeException;
use Throwable;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- `EntityType` のシグネチャに合わせる。

/**
 * テスト用の外部の実体の種類（R3-6b1）。Pro アドオンが `cbjp/entity_types/register` で足す種類と同じ形で、
 * 取込み・エクスポート・push intent・D25・リンク再構築・検証レポート・警告・マッピングの拡張点を全部通す。
 * リモートの実体は `$remote`（アダプタには gizmo の API が無い）。
 */
final class GizmoType extends EntityType {

	public const KEY = 'gizmo';

	/**
	 * @var array<int,CanonicalGizmo>
	 */
	public array $remote = [];

	/**
	 * @var array<int,array{0:CanonicalGizmo,1:?string}>
	 */
	public array $pushed = [];

	/**
	 * 指定すると作成の送信がこの例外を投げる（push intent が結果不明として残る）。
	 */
	public ?Throwable $create_failure = null;

	/**
	 * 真にすると `remote_amount()`・`dry_run_label()` が例外を投げる（取込みのページが 1 件の異常で止まらないことの確認用）。
	 */
	public bool $explode_on_item = false;

	/**
	 * 真にすると `records_push_intent()` が例外を投げる（push intent を残す側に倒れることの確認用）。
	 */
	public bool $explode_on_push_intent = false;

	private int $next_remote_id = 900;

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return __( 'Gizmos', 'cart-bridge-jp' );
	}

	public function position(): int {
		return 45;
	}

	public function supports_import( PlatformAdapter $adapter ): bool {
		return true;
	}

	public function fetch_page( PlatformAdapter $adapter, Cursor $cursor ): Page {
		$offset = (int) $cursor->get( 'offset', 0 );
		$slice  = array_slice( $this->remote, $offset, 2 );
		$next   = $offset + 2 < count( $this->remote ) ? new Cursor( [ 'offset' => $offset + 2 ] ) : null;

		return new Page( $slice, $next, count( $this->remote ) );
	}

	public function writer( string $platform, WooServices $services ): EntityWriter {
		return new GizmoWriter( $platform );
	}

	public function supports_export( PlatformAdapter $adapter ): bool {
		return true;
	}

	public function is_export_beta( PlatformAdapter $adapter ): bool {
		return true;
	}

	public function reader( string $platform, WooServices $services ): EntityReader {
		return new GizmoReader( $platform );
	}

	public function push( PlatformAdapter $adapter, CanonicalModel $item, ?string $remote_id ): PushResult {
		if ( ! $item instanceof CanonicalGizmo ) {
			throw new RuntimeException( 'Not a gizmo.' );
		}

		if ( null === $remote_id && null !== $this->create_failure ) {
			throw $this->create_failure;
		}

		$this->pushed[] = [ $item, $remote_id ];

		if ( null !== $remote_id ) {
			return new PushResult( $remote_id, PushResult::OPERATION_UPDATED );
		}

		return new PushResult( (string) $this->next_remote_id++, PushResult::OPERATION_CREATED );
	}

	public function records_push_intent(): bool {
		if ( $this->explode_on_push_intent ) {
			throw new RuntimeException( 'push intent' );
		}

		return true;
	}

	public function fetch_by_remote_id( PlatformAdapter $adapter, string $remote_id ): ?CanonicalModel {
		foreach ( $this->remote as $gizmo ) {
			if ( $gizmo->id === $remote_id ) {
				return $gizmo;
			}
		}

		return null;
	}

	public function describe_local( int $local_id ): array {
		if ( 'post' !== get_post_type( $local_id ) ) {
			return parent::describe_local( $local_id );
		}

		return [
			'exists'   => true,
			'edit_url' => get_edit_post_link( $local_id, 'raw' ),
			'details'  => [ 'name' => get_the_title( $local_id ) ],
		];
	}

	public function is_linked_by_export( string $platform, int $local_id ): bool {
		return 'post' === get_post_type( $local_id ) && ! EntityOrigin::post_linked_by_import( $local_id, $platform );
	}

	public function link_sources(): array {
		return [ new PostLinkSource( self::KEY, 45, $this->label(), 'post' ) ];
	}

	public function existing_local_ids( array $local_ids ): array {
		return ( new LocalEntityLookup() )->existing_posts( [ 'post' ], $local_ids );
	}

	public function remote_amount( CanonicalModel $item ): ?int {
		if ( $this->explode_on_item ) {
			throw new RuntimeException( 'amount' );
		}

		return $item instanceof CanonicalGizmo ? $item->amount * 100 : null;
	}

	public function local_amount_summary( array $local_ids ): array {
		$total = 0;

		foreach ( $local_ids as $local_id ) {
			$total += (int) get_post_meta( $local_id, '_gizmo_amount', true ) * 100;
		}

		return [
			'total_minor' => $total,
			'currencies'  => [ 'JPY' ],
		];
	}

	public function dry_run_label( CanonicalModel $item ): string {
		if ( $this->explode_on_item ) {
			throw new RuntimeException( 'label' );
		}

		return $item instanceof CanonicalGizmo ? $item->name : '';
	}

	public function warning_flags(): array {
		return [
			'gizmo_pending' => [ WarningFlag::UNRESOLVED_REFERENCE, WarningFlag::PENDING_IMPORT ],
			'gizmo_blocked' => [ WarningFlag::EXPORT_BLOCKING ],
		];
	}

	public function describe_warning( string $code, bool $import, string $row_entity ): ?WarningText {
		return match ( $code ) {
			'gizmo_pending' => new WarningText(
				WarningCatalog::SEVERITY_ACTION_REQUIRED,
				__( 'A gizmo part is not imported yet.', 'cart-bridge-jp' ),
				__( 'Import the parts first.', 'cart-bridge-jp' )
			),
			'gizmo_blocked' => new WarningText( WarningCatalog::SEVERITY_BLOCKING, __( 'This gizmo cannot be exported.', 'cart-bridge-jp' ) ),
			default => null,
		};
	}

	public function mapping_kinds(): array {
		return [ new GizmoMappingKind() ];
	}
}
