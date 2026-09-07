import { __, sprintf } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import type { EntityType, Job, Limits } from '../types';

interface Props {
	jobs: Job[];
	limits: Limits;
	entityLabels: Record< EntityType, string >;
	/**
	 * 直前に実行したdry-run（サンプリングを行わない全量走査）の
	 * `processed`件数。総数が判明している場合のみ渡す（D15/§10.3の
	 * 「対象◯件のうち」の分母。CLAUDE.mdの通り一部エンティティは
	 * アダプタが総数を保証できず判明しないことがある）。
	 */
	dryRunTotals: Partial< Record< EntityType, number > > | null;
}

interface UpsellLine {
	entity: EntityType;
	message: string;
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
	if ( 'stock' === entity || 'review' === entity ) {
		return false === ( limits.entities.product?.unlocked ?? true );
	}

	const info = limits.entities[ entity ];

	return ! ( info?.unlocked ?? true ) && null !== ( info?.limit ?? null );
}

function buildUpsellLine(
	entity: EntityType,
	label: string,
	limits: Limits,
	dryRunTotals: Partial< Record< EntityType, number > > | null
): UpsellLine | null {
	const info = limits.entities[ entity ];

	if ( ! info || ! isEntityRestricted( entity, limits ) ) {
		return null;
	}

	const used = info.used ?? 0;
	const total = dryRunTotals?.[ entity ];

	if ( 'number' === typeof total && total > used ) {
		return {
			entity,
			message: sprintf(
				/* translators: 1: entity label, 2: total item count found by the preview, 3: count already migrated in the free version, 4: remaining count that needs the Pro version */
				__(
					'%1$s: %2$d found, %3$d migrated in the free version. The remaining %4$d require the Pro version.',
					'cart-bridge-jp'
				),
				label,
				total,
				used,
				total - used
			),
		};
	}

	if ( null !== info.limit && used >= info.limit ) {
		return {
			entity,
			message: sprintf(
				/* translators: 1: entity label, 2: free version limit */
				__(
					'%1$s: reached the free version limit (%2$d). Run a dry-run preview to see the exact remaining count.',
					'cart-bridge-jp'
				),
				label,
				info.limit
			),
		};
	}

	return null;
}

export default function LimitsUpsellNotice( {
	jobs,
	limits,
	entityLabels,
	dryRunTotals,
}: Props ) {
	const lines = jobs
		.map( ( job ) =>
			buildUpsellLine(
				job.entity,
				entityLabels[ job.entity ],
				limits,
				dryRunTotals
			)
		)
		.filter( ( line ): line is UpsellLine => null !== line );

	if ( 0 === lines.length ) {
		return null;
	}

	return (
		<Notice status="info" isDismissible={ false }>
			<p>
				{ __(
					'This is the free version of Cart Bridge JP. The Pro version removes the sample limits below.',
					'cart-bridge-jp'
				) }
			</p>
			<ul>
				{ lines.map( ( line ) => (
					<li key={ line.entity }>{ line.message }</li>
				) ) }
			</ul>
		</Notice>
	);
}
