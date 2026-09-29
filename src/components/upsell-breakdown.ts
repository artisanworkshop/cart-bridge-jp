import type { EntityType, JobTotals, Limits } from '../types';

/**
 * 直前の dry-run（無料版でも上限なしの全量走査。D15）で分かった件数。
 *
 * `migratable` は「移行できる件数」＝ `created + updated + unchanged`（issue #55。`docs/03` §10.3
 * 「アップセル表示」）。`processed` との差が「どの版でも移行できない件数」（価格未設定・
 * blocking 警告など）。`unchanged` の無い導入前のジョブでは内訳が分からないため `null`。
 */
export interface DryRunEntityTotals {
	processed: number;
	migratable: number | null;
}

export type DryRunTotals = Partial< Record< EntityType, DryRunEntityTotals > >;

export type UpsellLineData =
	| {
			entity: EntityType;
			kind: 'breakdown';
			found: number;
			migrated: number;
			notMigrated: number;
			blocked: number;
	  }
	| {
			entity: EntityType;
			kind: 'dependent';
			found: number;
			migrated: number;
	  }
	| {
			entity: EntityType;
			kind: 'limit_reached';
			limit: number;
	  };

/**
 * 在庫・レビューは数値上限を持たず、サンプル商品への紐付けで間接的に制限される
 * （D15/§10.2）。dry-run は対象商品が未移行だと在庫を全件スキップする
 * （`STOCK_PRODUCT_UNRESOLVED`/`STOCK_PRODUCT_NOT_EXPORTED`）ため、
 * `processed − migratable` を「どの版でも移行できない」と読むと、商品を移行すれば移行できる
 * 在庫まで誤ってそう表示してしまう。内訳は出さず、件数と移行済み数だけを示す（issue #55）。
 */
const DEPENDENT_ENTITIES: readonly EntityType[] = [ 'stock', 'review' ];

function isCount( value: unknown ): value is number {
	return 'number' === typeof value && Number.isFinite( value ) && value >= 0;
}

export function dryRunEntityTotals( totals: JobTotals ): DryRunEntityTotals {
	const { processed, created, updated, unchanged } = totals;

	return {
		processed: isCount( processed ) ? processed : 0,
		migratable:
			isCount( created ) && isCount( updated ) && isCount( unchanged )
				? created + updated + unchanged
				: null,
	};
}

/**
 * stock/reviewは`LimitPolicy`の数値上限が常にnull（`unlocked`も常にtrue）だが、
 * これは「無制限」ではなく「サンプル商品への紐付けで間接的に制限される」ため
 * （D15/§10.2「stock/reviewはサンプル商品分のみ」）。実際に free/Pro のどちらの
 * 状態かは、紐付け先である商品（product）の`unlocked`が示す。これを見ずに
 * 自身の`unlocked`（常にtrue）だけで判定すると、無料版でも stock/review の
 * アップセルが一切出せなくなる（Codexレビュー指摘）。
 * @param entity
 * @param limits
 */
function isEntityRestricted( entity: EntityType, limits: Limits ): boolean {
	if ( DEPENDENT_ENTITIES.includes( entity ) ) {
		return false === ( limits.entities.product?.unlocked ?? true );
	}

	const info = limits.entities[ entity ];

	return ! ( info?.unlocked ?? true ) && null !== ( info?.limit ?? null );
}

/**
 * 1 エンティティ分の案内行。出さない場合は `null`。
 *
 * - 数値上限のあるエンティティで内訳が分かる: 「移行できるが未移行」（`migratable − used`）が
 *   1 件以上のときだけ、「どの版でも移行できない」（`processed − migratable`）を併記して出す。
 *   未移行が 0 件なら出さない（移行できない件数は dry-run の結果・CSV に出ている）。
 * - 内訳が分からない（dry-run が無い・導入前のジョブ）: 上限に達しているときだけ出す。
 * - 在庫・レビュー: 内訳を出さず、dry-run の件数が移行済み数を上回るときだけ出す。
 *
 * `used`（mappings＋未解決の push intent の累積。`LimitPolicy::used()`）は、後から止まる状態に
 * なった移行済みの実体なども含むため、差は近似で 0 を下限にする。
 * @param entity
 * @param limits
 * @param dryRunTotals
 */
export function buildUpsellLineData(
	entity: EntityType,
	limits: Limits,
	dryRunTotals: DryRunTotals | null
): UpsellLineData | null {
	const info = limits.entities[ entity ];

	if ( ! info || ! isEntityRestricted( entity, limits ) ) {
		return null;
	}

	const used = info.used ?? 0;
	const totals = dryRunTotals?.[ entity ];

	if ( DEPENDENT_ENTITIES.includes( entity ) ) {
		return totals && totals.processed > used
			? {
					entity,
					kind: 'dependent',
					found: totals.processed,
					migrated: used,
			  }
			: null;
	}

	if ( totals && null !== totals.migratable ) {
		const notMigrated = Math.max( 0, totals.migratable - used );

		if ( 0 === notMigrated ) {
			return null;
		}

		return {
			entity,
			kind: 'breakdown',
			found: totals.processed,
			migrated: used,
			notMigrated,
			blocked: Math.max( 0, totals.processed - totals.migratable ),
		};
	}

	if ( null !== info.limit && used >= info.limit ) {
		return { entity, kind: 'limit_reached', limit: info.limit };
	}

	return null;
}

/**
 * Pro 版の案内先の多重防御（サーバー側の `LimitPolicy::pro_url()` が検証済み）。http/https で
 * host のある URL だけを正規化した形で返し、それ以外は `''`（Pro 版に触れない）。
 * @param value
 */
export function sanitizeProUrl( value: unknown ): string {
	if ( 'string' !== typeof value || '' === value.trim() ) {
		return '';
	}

	try {
		const url = new URL( value );

		return ( 'http:' === url.protocol || 'https:' === url.protocol ) &&
			'' !== url.hostname
			? url.href
			: '';
	} catch {
		return '';
	}
}
