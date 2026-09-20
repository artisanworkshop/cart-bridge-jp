<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools;

use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\Logger;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Woo\Support\AddressMapper;
use CartBridgeJP\Woo\Support\SideEffectGuard;
use CartBridgeJP\Woo\Support\Value;
use CartBridgeJP\Woo\Writer\CustomerWriter;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WC_Order;
use WP_User;

/**
 * 県コード修復ツール（issue #46）。
 *
 * ColorMe の `pref_id` は JIS X 0401（＝ Woo の `JPxx`）と 23 県で並びが異なる。PR #44 より前の
 * `AddressMapper::state_code()` は `pref_id` をそのまま `JP%02d` にしていた（恒等変換）ため、それ以前に
 * インポートした顧客・受注の `billing_state`/`shipping_state` は誤った都道府県のまま残っている。
 * Woo 側には変換後の `state` しか残らず、修正前後のデータは値だけでは判別できない（入れ替え・巡回のため
 * 一律の表適用は修正後データを壊し、二重適用では別の誤った県になる）。checksum は Canonical（生の
 * `pref_id`）から算出するため修正では変わらず、再インポートも checksum 一致スキップで直らない。
 *
 * そこで、ASP から権威の `pref_id`（p）を再取得し、現在の `state`（s）が「旧バグの出力（`JP{p}`）」と
 * 一致し、かつ正しい値（`JP{表[p]}`）と異なる場合に限って `state` だけを補正する。
 *
 * - `s == 正しい値`（修正後の取込・補正済み・非影響24県）→ 変更しない（冪等）。
 * - どちらとも一致しない、または郵便番号・番地が ASP の値と一致しない（手修正・ASP 側で住所変更）→
 *   変更せず `unverified` として報告する（state だけ書き換えて「新しい県＋古い郵便番号」の
 *   キメラ住所を作らない）。
 * - 旧バグが出力しうる `state` は 23 値に限られるため、それ以外の値の実体は ASP に照会せず
 *   「被害なし」と確定する（API 呼び出しの節約）。
 *
 * Scan（`$apply = false`）と Repair（`$apply = true`）は同一の判定関数を共有し、書込みだけが異なる。
 * `$apply` に既定値は無く、呼び出し側が必ず明示する（既定で書込みに倒さない）。
 *
 * 顧客と受注の**新規作成はしない**（既にリンク済みの実体の `state` のみ更新）ため、`LimitPolicy` の
 * 累積カウントには影響しない。mapping 行も増減しないため、cursor は `local_ids()` 上の offset で
 * 安定に進行する。
 */
final class PrefStateRepair {

	/**
	 * 1回の `run()` が ASP へ照会する回数の既定上限（1リクエストの所要時間とレート制限の消費を抑える）。
	 */
	public const DEFAULT_BUDGET = 20;

	/**
	 * 1回の `run()` が走査するローカル行数の上限（ASP に照会しない行も含む）。
	 */
	public const MAX_ROWS_PER_CALL = 300;

	/**
	 * 走査順。
	 *
	 * @var array<int,string>
	 */
	public const SOURCES = [ 'customer', 'order' ];

	/**
	 * 判定結果の区分。エンティティ（顧客1人・受注1件）単位で数える。
	 * - fixed: 補正した（Scan では補正が必要な件数）
	 * - already_correct: 補正不要（修正後の取込・非影響県・海外・補正済み）
	 * - unverified: 旧バグの出力と断定できず変更しなかった（手修正・ASP 側の住所変更・郵便番号/番地の不一致）
	 * - unavailable: ASP が使える記録を返さなかった（ASP 側で削除済み・変換不能・別のIDの記録が返った）
	 * - skipped: Woo 側が対象外（実体が無い・自プラットフォーム所有でない・スタッフ・ゴミ箱の受注等）、
	 *   または補正の保存に失敗した（次回の Scan で再び要補正として現れる）
	 *
	 * @var array<int,string>
	 */
	public const BUCKETS = [ 'fixed', 'already_correct', 'unverified', 'unavailable', 'skipped' ];

	/**
	 * 補正した実体に残す監査メタ（側ごとの from/to の JSON）。変更内容の追跡と手動での巻き戻し用。
	 */
	public const AUDIT_META = '_cbjp_state_repaired';

	private const SIDES = [ 'billing', 'shipping' ];

	/**
	 * `WC_Order::get_status()` の値（`wc-` 接頭辞なし）。ゴミ箱と、WooCommerce Blocks が
	 * チェックアウト中の下書きに使う内部ステータスは対象外にする（後者は 24 時間後に削除されうる）。
	 */
	private const SKIPPED_ORDER_STATUSES = [ 'trash', 'checkout-draft' ];

	/**
	 * プラットフォームごとの「旧バグが出力しうる state」（`JPxx` => true）のキャッシュ。
	 *
	 * @var array<string,array<string,true>>
	 */
	private array $affected_states = [];

	public function __construct(
		private readonly MappingRepository $mappings,
		private readonly PlatformAdapter $adapter,
		private readonly Logger $logger = new Logger()
	) {}

	/**
	 * @param bool $apply true で補正を書き込む。false（Scan）では何も書かず、`fixed` は
	 *   「補正が必要な件数」を表す。
	 * @param int  $budget ASP への照会回数の上限。到達した時点の位置を cursor として返す。
	 * @return array{
	 *   counts:array<string,array<string,int>>,
	 *   cursor:?string,
	 *   interruption:?string
	 * } `cursor` が null かつ `interruption` が null なら完了。`interruption` が非 null の場合は
	 *   ASP への照会に失敗して中断しており、`cursor` は失敗した行を指す（同じ位置から再開できる。
	 *   処理は冪等）。値は `RepairInterruptedException` の定数。
	 *
	 * @throws InvalidArgumentException cursor が不正な場合。
	 * @throws UnsupportedOperationException `$platform` が `pref_id` スキームを持たない場合。
	 */
	public function run( string $platform, bool $apply, ?string $cursor = null, int $budget = self::DEFAULT_BUDGET ): array {
		if ( ! AddressMapper::uses_pref_id_scheme( $platform ) ) {
			throw new UnsupportedOperationException( $platform, 'repair_states' );
		}

		[ $index, $offset ] = $this->decode_cursor( $cursor );

		$counts = [];

		foreach ( self::SOURCES as $source ) {
			$counts[ $source ] = array_fill_keys( self::BUCKETS, 0 );
		}

		$budget       = max( 1, $budget );
		$fetches      = 0;
		$rows_seen    = 0;
		$interruption = null;
		$sources      = count( self::SOURCES );

		while ( $index < $sources ) {
			$entity     = self::SOURCES[ $index ];
			$local_ids  = $this->mappings->local_ids( $platform, $entity );
			$total      = count( $local_ids );
			$remote_ids = $this->mappings->find_many_by_local_ids( $platform, $entity, array_slice( $local_ids, $offset, self::MAX_ROWS_PER_CALL ) );

			while ( $offset < $total ) {
				if ( $fetches >= $budget || $rows_seen >= self::MAX_ROWS_PER_CALL ) {
					break 2;
				}

				$local_id  = $local_ids[ $offset ];
				$remote_id = $remote_ids[ $local_id ]['remote_id'] ?? '';

				try {
					$bucket = 'customer' === $entity
						? $this->repair_customer( $platform, $apply, $local_id, $remote_id, $fetches )
						: $this->repair_order( $platform, $apply, $local_id, $remote_id, $fetches );
				} catch ( RepairInterruptedException $exception ) {
					$interruption = $exception->reason;

					break 2;
				}

				++$counts[ $entity ][ $bucket ];
				++$offset;
				++$rows_seen;
			}

			++$index;
			$offset = 0;
		}

		$next_cursor = $index < $sources ? $this->encode_cursor( $index, $offset ) : null;

		$this->logger->info(
			'Prefecture state repair batch finished.',
			[
				'platform'     => $platform,
				'apply'        => $apply,
				'counts'       => $counts,
				'done'         => null === $next_cursor && null === $interruption,
				'interruption' => $interruption,
			]
		);

		return [
			'counts'       => $counts,
			'cursor'       => $next_cursor,
			'interruption' => $interruption,
		];
	}

	/**
	 * @throws RepairInterruptedException
	 */
	private function repair_customer( string $platform, bool $apply, int $user_id, string $remote_id, int &$fetches ): string {
		$user = get_userdata( $user_id );

		// 所有・実在・スタッフの検証は Scan と Repair で共通。スタッフ（管理者等）のアカウントは
		// `CustomerWriter` が住所を書かずに SKIPPED で返すため、その `billing_state` は旧バグの出力ではなく
		// 店舗自身のデータで、ASP 由来の値で上書きしてはならない。
		if ( ! $user instanceof WP_User
			|| get_user_meta( $user_id, '_cbjp_platform', true ) !== $platform
			|| CustomerWriter::has_protected_role( $user_id )
		) {
			return 'skipped';
		}

		$current = [
			'billing'  => $this->user_address( $user_id, 'billing' ),
			'shipping' => $this->user_address( $user_id, 'shipping' ),
		];

		if ( ! $this->is_candidate( $platform, $current ) ) {
			return 'already_correct';
		}

		if ( '' === $remote_id ) {
			return 'unavailable';
		}

		++$fetches;

		$customer = $this->fetch_remote( fn (): ?CanonicalModel => $this->adapter->fetch_customer_by_remote_id( $remote_id ) );

		// 外部アダプタの戻り値は信用しない（アーキテクチャ原則8）。要求したIDと違う記録の住所で
		// 補正すると別人の県を書いてしまうため、`remote_id` の一致まで確認する。
		if ( ! $customer instanceof CanonicalCustomer || $customer->remote_id() !== $remote_id ) {
			return 'unavailable';
		}

		// `CustomerWriter` は同じ住所を請求先・配送先の両方へ書く。
		$changes = $this->evaluate(
			$platform,
			[
				'billing'  => $customer->address,
				'shipping' => $customer->address,
			],
			$current
		);

		if ( [] !== $changes['fix'] ) {
			if ( $apply ) {
				if ( ! $this->try_write( fn () => $this->write_customer( $user_id, $changes['fix'] ), 'customer', $user_id ) ) {
					return 'skipped';
				}

				$this->log_repaired( 'customer', $user_id, $remote_id, $changes['fix'] );
			}

			return 'fixed';
		}

		return $changes['unverified'] ? 'unverified' : 'already_correct';
	}

	/**
	 * @throws RepairInterruptedException
	 */
	private function repair_order( string $platform, bool $apply, int $order_id, string $remote_id, int &$fetches ): string {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order
			|| in_array( $order->get_status(), self::SKIPPED_ORDER_STATUSES, true )
			|| $order->get_meta( '_cbjp_platform' ) !== $platform
		) {
			return 'skipped';
		}

		$current = [
			'billing'  => [
				'state'     => $order->get_billing_state(),
				'postcode'  => $order->get_billing_postcode(),
				'address_1' => $order->get_billing_address_1(),
			],
			'shipping' => [
				'state'     => $order->get_shipping_state(),
				'postcode'  => $order->get_shipping_postcode(),
				'address_1' => $order->get_shipping_address_1(),
			],
		];

		if ( ! $this->is_candidate( $platform, $current ) ) {
			return 'already_correct';
		}

		if ( '' === $remote_id ) {
			return 'unavailable';
		}

		++$fetches;

		$fetched = $this->fetch_remote( fn (): ?CanonicalModel => $this->adapter->fetch_order_by_remote_id( $remote_id ) );

		if ( ! $fetched instanceof CanonicalOrder || $fetched->remote_id() !== $remote_id ) {
			return 'unavailable';
		}

		// `OrderWriter::apply_addresses()` と同じ入力: 請求先は注文時点の `customer_snapshot`、
		// 配送先は代表（先頭）配送先の `shipping`。
		$changes = $this->evaluate(
			$platform,
			[
				'billing'  => Value::array_or_null( $fetched->extras['customer_snapshot'] ?? null ) ?? [],
				'shipping' => $fetched->shipping,
			],
			$current
		);

		if ( [] !== $changes['fix'] ) {
			if ( $apply ) {
				if ( ! $this->try_write( fn () => $this->write_order( $order, $changes['fix'] ), 'order', $order_id ) ) {
					return 'skipped';
				}

				$this->log_repaired( 'order', $order_id, $remote_id, $changes['fix'] );
			}

			return 'fixed';
		}

		return $changes['unverified'] ? 'unverified' : 'already_correct';
	}

	/**
	 * 側（請求先・配送先）ごとに独立して判定する。Scan と Repair が共有する唯一の判定経路。
	 *
	 * @param array<string,array<string,mixed>>  $addresses ASP 由来の Canonical の住所（側 => 住所）。
	 * @param array<string,array<string,string>> $current   Woo の現在値（側 => state/postcode/address_1）。
	 * @return array{fix:array<string,array{from:string,to:string}>,unverified:bool}
	 */
	private function evaluate( string $platform, array $addresses, array $current ): array {
		$fix        = [];
		$unverified = false;

		foreach ( self::SIDES as $side ) {
			$decision = $this->decide( $platform, $addresses[ $side ], $current[ $side ] );

			if ( 'fix' === $decision ) {
				$fix[ $side ] = [
					'from' => $current[ $side ]['state'],
					'to'   => AddressMapper::state_code( $platform, $addresses[ $side ] ),
				];
			} elseif ( 'unverified' === $decision ) {
				$unverified = true;
			}
		}

		return [
			'fix'        => $fix,
			'unverified' => $unverified,
		];
	}

	/**
	 * @param array<string,mixed>  $address ASP 由来の住所（`pref_id`/`postal`/`address1` 等）。
	 * @param array<string,string> $current Woo の現在値（`state`/`postcode`/`address_1`）。
	 * @return 'fix'|'ok'|'unverified'
	 */
	private function decide( string $platform, array $address, array $current ): string {
		$pref_id = Value::int( $address['pref_id'] ?? null );
		$correct = AddressMapper::state_code( $platform, $address );

		// `pref_id` の欠損・海外（48）・範囲外は、旧コードも新コードも `''` を書く（影響なし）。
		if ( null === $pref_id || '' === $correct ) {
			return 'ok';
		}

		// 旧バグの出力（恒等変換）。
		$legacy = sprintf( 'JP%02d', $pref_id );

		if ( $current['state'] === $correct ) {
			return 'ok';
		}

		// 表が恒等の24県は新旧で同じ値になり、この県自体は旧バグの影響を受けない。ただし現在の state が
		// 「旧バグが別の県で出力しうる値」（照会の対象になった理由）のまま残っている場合は、ASP 側で県が
		// 変わった（＝この state は取込み時の別の県に由来する）可能性があり、正常とは断定できない。
		if ( $legacy === $correct ) {
			return isset( $this->affected_states( $platform )[ $current['state'] ] ) ? 'unverified' : 'ok';
		}

		if ( $current['state'] !== $legacy ) {
			return 'unverified';
		}

		// 旧バグの出力と一致した。ASP 側で住所が変わっていないこと（＝この state がインポート時の
		// 住所に由来すること）を、`AddressMapper::to_woo()` が同じ入力から生成する郵便番号・番地との
		// 一致で確認する。一致しなければ state だけを書き換えない。
		$expected = AddressMapper::to_woo( $platform, $address, '', '', null, null );

		if ( ! $this->same_postcode( $expected['postcode'], $current['postcode'] )
			|| ! $this->same_line( $expected['address_1'], $current['address_1'] )
		) {
			return 'unverified';
		}

		return 'fix';
	}

	/**
	 * いずれかの側の state が「旧バグが出力しうる23値」のどれかか。どれでもなければ、旧バグの
	 * 被害を受けていないことが ASP に照会せず確定する（旧出力が非影響県の値なら新旧で同じ）。
	 *
	 * @param array<string,array<string,string>> $current
	 */
	private function is_candidate( string $platform, array $current ): bool {
		$affected = $this->affected_states( $platform );

		foreach ( self::SIDES as $side ) {
			if ( isset( $affected[ $current[ $side ]['state'] ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `pref_id` 1〜47 のうち、旧出力（`JP{p}`）と新しい出力が異なるものの旧出力の集合。表を
	 * 複製せず `AddressMapper::state_code()`（Writer が使う関数）から導出する。
	 *
	 * @return array<string,true>
	 */
	private function affected_states( string $platform ): array {
		if ( ! isset( $this->affected_states[ $platform ] ) ) {
			$set = [];

			for ( $pref_id = 1; $pref_id <= 47; $pref_id++ ) {
				$legacy  = sprintf( 'JP%02d', $pref_id );
				$correct = AddressMapper::state_code( $platform, [ 'pref_id' => $pref_id ] );

				if ( '' !== $correct && $legacy !== $correct ) {
					$set[ $legacy ] = true;
				}
			}

			$this->affected_states[ $platform ] = $set;
		}

		return $this->affected_states[ $platform ];
	}

	/**
	 * @return array{state:string,postcode:string,address_1:string}
	 */
	private function user_address( int $user_id, string $side ): array {
		return [
			'state'     => $this->meta_string( $user_id, "{$side}_state" ),
			'postcode'  => $this->meta_string( $user_id, "{$side}_postcode" ),
			'address_1' => $this->meta_string( $user_id, "{$side}_address_1" ),
		];
	}

	private function meta_string( int $user_id, string $key ): string {
		$value = get_user_meta( $user_id, $key, true );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * 郵便番号は桁の並びだけで比較する（ハイフンの有無の差を無視。値が空のときは根拠にならないため不一致）。
	 */
	private function same_postcode( string $expected, string $current ): bool {
		$expected_digits = (string) preg_replace( '/\D+/', '', $expected );
		$current_digits  = (string) preg_replace( '/\D+/', '', $current );

		return '' !== $expected_digits && $expected_digits === $current_digits;
	}

	/**
	 * 前後の空白と連続する空白（全角含む）の差を無視して比較する。
	 */
	private function same_line( string $expected, string $current ): bool {
		return $this->squish( $expected ) === $this->squish( $current );
	}

	private function squish( string $value ): string {
		return trim( (string) preg_replace( '/[\s\x{3000}]+/u', ' ', $value ) );
	}

	/**
	 * ASP への照会を実行し、失敗を `RepairInterruptedException` へ翻訳する。ここだけがアダプタの
	 * 例外を扱う（Woo 側の書込み失敗を「ASP の障害」と取り違えないため、書込みはこの外で行う）。
	 *
	 * @param callable():?CanonicalModel $fetch
	 * @throws RepairInterruptedException
	 */
	private function fetch_remote( callable $fetch ): ?CanonicalModel {
		try {
			return $fetch();
		} catch ( RateLimitExhaustedException ) {
			throw new RepairInterruptedException( RepairInterruptedException::RATE_LIMITED );
		} catch ( ApiException $exception ) {
			throw new RepairInterruptedException( $this->classify_api_failure( $exception ) );
		} catch ( UnsupportedOperationException ) {
			throw new RepairInterruptedException( RepairInterruptedException::UNSUPPORTED );
		} catch ( Throwable $exception ) {
			// 200 応答なのに想定した形でない等、アダプタの契約違反。個人情報を含みうるメッセージは
			// 記録せず例外クラス名だけを残す（`Sync\Importer` のログ方針と同じ）。
			$this->logger->error( 'Prefecture state repair failed to fetch a record.', [ 'exception' => $exception::class ] );

			throw new RepairInterruptedException( RepairInterruptedException::API_ERROR );
		}
	}

	/**
	 * ASP の API 失敗を、UI が案内を分けられる区分へ翻訳する。
	 *
	 * - 再接続が必要: 認証エラー（401/403）、またはアダプタが「未接続」と明示した場合
	 *   （`context['not_connected'] === true`。`ColorMeAdapter::client()`）。**ステータス 0 だけでは
	 *   判別できない**: `HttpClient`（通信断）や `ColorMeClient`（JSON 破損）も 0 で投げるため、
	 *   0 を一律に「再接続」と案内すると、一時的な通信断でも店舗を再認可へ誘導してしまう。
	 * - レート制限: クライアント側スロットル（`RateLimitExhaustedException`）とは別に、ASP が 429 を返して
	 *   リトライ上限に達した場合も待てば再開できる。
	 */
	private function classify_api_failure( ApiException $exception ): string {
		if ( in_array( $exception->status_code(), [ 401, 403 ], true ) || true === ( $exception->context()['not_connected'] ?? false ) ) {
			return RepairInterruptedException::NOT_CONNECTED;
		}

		if ( $exception->is_rate_limited() || 429 === $exception->status_code() ) {
			return RepairInterruptedException::RATE_LIMITED;
		}

		return RepairInterruptedException::API_ERROR;
	}

	/**
	 * Woo への書込み。1件の保存失敗（他プラグインのフックが投げる例外・書込み確認の不一致等）で走査全体を落とさず、
	 * 件数と再開位置を失わないよう、その実体だけを `skipped` として先へ進む。同じ行で失敗し続けても
	 * 走査が止まらない。冪等なので次回の Scan で再び「要補正」として現れる。例外メッセージは個人情報を
	 * 含みうるため記録せず、例外クラス名だけを残す。
	 *
	 * @param callable():void $write
	 */
	private function try_write( callable $write, string $entity, int $local_id ): bool {
		try {
			$write();

			return true;
		} catch ( Throwable $exception ) {
			$this->logger->error(
				'Prefecture state repair could not save a record.',
				[
					'entity'    => $entity,
					'local_id'  => $local_id,
					'exception' => $exception::class,
				]
			);

			return false;
		}
	}

	/**
	 * @param array<string,array{from:string,to:string}> $changes 側 => 変更。
	 */
	private function write_customer( int $user_id, array $changes ): void {
		foreach ( $changes as $side => $change ) {
			update_user_meta( $user_id, "{$side}_state", $change['to'] );

			// `update_user_meta()` は同値の更新でも書込み失敗でも false を返し区別できないため、書き込めたことは
			// 再読込で確認する（`try_write()` が例外を「保存失敗」として扱う）。
			if ( $this->meta_string( $user_id, "{$side}_state" ) !== $change['to'] ) {
				throw new RuntimeException( 'The user state was not persisted.' );
			}
		}

		update_user_meta( $user_id, self::AUDIT_META, $this->merge_audit( get_user_meta( $user_id, self::AUDIT_META, true ), $changes ) );
	}

	/**
	 * @param array<string,array{from:string,to:string}> $changes 側 => 変更。
	 */
	private function write_order( WC_Order $order, array $changes ): void {
		// ステータスは変えないためメール・在庫の副作用は起きないが、`Woo\Writer\OrderWriter` と
		// 同じく `SideEffectGuard` で囲み、他プラグインのフックが送信する通知も抑止する。
		//
		// `save()` は `date_modified` を現在時刻へ更新する（実測: WC 11.1.1・HPOS 有効。保存前に
		// `set_date_modified()` で元の値を戻しても、WC が同値を「変更なし」と扱うため保持できない）。
		// 実際に住所を書き換える操作なので更新日時が進むのは事実に沿うとして許容し、補正が不要な受注は
		// `save()` しない（`decide()` が `fix` を返した受注だけがここへ来る）。`woocommerce_update_order` は
		// Analytics 取込みの Action Scheduler アクションと `order.updated` Webhook を発火する。
		( new SideEffectGuard() )->run(
			function () use ( $order, $changes ): void {
				foreach ( $changes as $side => $change ) {
					if ( 'billing' === $side ) {
						$order->set_billing_state( $change['to'] );
					} else {
						$order->set_shipping_state( $change['to'] );
					}
				}

				$order->update_meta_data( self::AUDIT_META, $this->merge_audit( $order->get_meta( self::AUDIT_META ), $changes ) );
				$order->save();

				// `WC_Abstract_Order::save()` は保存中の例外（他プラグインのフックが投げたもの等）を内部で
				// 握りつぶしてログに残すだけで ID を返す（WC 11.1 の実ソースで確認）。呼び出し側からは成功に
				// 見えるため、書き込めたことは DB から読み直して確認する。
				$saved = wc_get_order( $order->get_id() );

				foreach ( $changes as $side => $change ) {
					$actual = $saved instanceof WC_Order ? ( 'billing' === $side ? $saved->get_billing_state() : $saved->get_shipping_state() ) : null;

					if ( $actual !== $change['to'] ) {
						throw new RuntimeException( 'The order state was not persisted.' );
					}
				}
			}
		);
	}

	/**
	 * 既存の監査メタ（JSON。過去の補正で側が既に記録されている場合）へ今回の変更を重ねる。
	 *
	 * @param array<string,array{from:string,to:string}> $changes
	 */
	private function merge_audit( mixed $existing, array $changes ): string {
		$previous = is_string( $existing ) && '' !== $existing ? json_decode( $existing, true ) : [];
		$merged   = is_array( $previous ) ? $previous : [];

		foreach ( $changes as $side => $change ) {
			// 同じ側を再度補正しても、最初の `from`（＝本当の元の値）は失わない（手動で巻き戻せる保証）。
			$original        = $merged[ $side ]['from'] ?? null;
			$merged[ $side ] = [
				'from' => is_string( $original ) ? $original : $change['from'],
				'to'   => $change['to'],
			];
		}

		return (string) wp_json_encode( $merged );
	}

	/**
	 * @param array<string,array{from:string,to:string}> $changes
	 */
	private function log_repaired( string $entity, int $local_id, string $remote_id, array $changes ): void {
		$this->logger->info(
			'Repaired prefecture state.',
			[
				'entity'    => $entity,
				'local_id'  => $local_id,
				'remote_id' => $remote_id,
				'changes'   => $changes,
			]
		);
	}

	/**
	 * @return array{0:int,1:int} [走査順のインデックス, offset]
	 *
	 * @throws InvalidArgumentException
	 */
	private function decode_cursor( ?string $cursor ): array {
		if ( null === $cursor || '' === $cursor ) {
			return [ 0, 0 ];
		}

		$decoded = json_decode( $cursor, true );
		$entity  = is_array( $decoded ) ? ( $decoded['entity'] ?? null ) : null;
		$offset  = is_array( $decoded ) ? ( $decoded['offset'] ?? null ) : null;
		$index   = is_string( $entity ) ? array_search( $entity, self::SOURCES, true ) : false;

		if ( false === $index || ! is_int( $offset ) || $offset < 0 ) {
			throw new InvalidArgumentException( 'Invalid repair cursor.' );
		}

		return [ $index, $offset ];
	}

	private function encode_cursor( int $index, int $offset ): string {
		return (string) wp_json_encode(
			[
				'entity' => self::SOURCES[ $index ],
				'offset' => $offset,
			]
		);
	}
}
