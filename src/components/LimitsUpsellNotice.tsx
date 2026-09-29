import { __, _n, sprintf } from '@wordpress/i18n';
import { ExternalLink, Notice } from '@wordpress/components';
import type { EntityType, Job, Limits } from '../types';
import {
	buildUpsellLineData,
	sanitizeProUrl,
	type DryRunTotals,
	type UpsellLineData,
} from './upsell-breakdown';

interface Props {
	jobs: Job[];
	limits: Limits;
	entityLabels: Record< EntityType, string >;
	/**
	 * 直前に実行したdry-run（サンプリングを行わない全量走査）の件数。判明している
	 * エンティティのみ（D15/§10.3の「対象◯件のうち」の分母。CLAUDE.mdの通り一部
	 * エンティティはアダプタが総数を保証できず判明しないことがある）。
	 */
	dryRunTotals: DryRunTotals | null;
}

/**
 * 各行は `pro_url` の有無によらず同じ中立の文言にし、Pro 版への言及は見出しだけに置く
 * （issue #55。`docs/03` §10.3「アップセル表示」）。
 * @param line
 * @param label
 */
function lineMessage( line: UpsellLineData, label: string ): string {
	switch ( line.kind ) {
		case 'breakdown': {
			const summary = sprintf(
				/* translators: 1: entity label, 2: item count found by the preview (dry run), 3: count already migrated, 4: count that can be migrated but was not migrated yet */
				__(
					'%1$s: %2$d found by the preview, %3$d migrated, %4$d not migrated yet.',
					'cart-bridge-jp'
				),
				label,
				line.found,
				line.migrated,
				line.notMigrated
			);

			if ( 0 === line.blocked ) {
				return summary;
			}

			return `${ summary } ${ sprintf(
				/* translators: %d: count of items that cannot be migrated in any version (for example, a missing price) */
				_n(
					'%d cannot be migrated as is. See the preview (dry-run) report for the reason.',
					'%d cannot be migrated as is. See the preview (dry-run) report for the reasons.',
					line.blocked,
					'cart-bridge-jp'
				),
				line.blocked
			) }`;
		}
		case 'dependent':
			return sprintf(
				/* translators: 1: entity label (stock or reviews), 2: item count found by the preview (dry run), 3: count already migrated */
				__(
					'%1$s: %2$d found by the preview, %3$d migrated. The free version migrates these only for the sample products.',
					'cart-bridge-jp'
				),
				label,
				line.found,
				line.migrated
			);
		case 'limit_reached':
			return sprintf(
				/* translators: 1: entity label, 2: free version limit */
				__(
					'%1$s: reached the free version limit (%2$d). Run a dry-run preview to see the exact remaining count.',
					'cart-bridge-jp'
				),
				label,
				line.limit
			);
	}
}

export default function LimitsUpsellNotice( {
	jobs,
	limits,
	entityLabels,
	dryRunTotals,
}: Props ) {
	// failed/cancelledのジョブは`used`（実際に永続化された件数）が本来の実行結果を
	// 反映していない（例: 書込み前にキャンセルされた場合`used`は0のまま）。それを
	// 「見つかった件数のうち0件しか移行できず、残り全件がPro版必須」と読める
	// メッセージにしてしまうと、無料版のサンプル移行がまだ試せる状態なのに
	// 誤解を与える。実際に完了したジョブのみを対象にする。
	const lines = jobs
		.filter( ( job ) => 'completed' === job.status )
		.map( ( job ) =>
			buildUpsellLineData( job.entity, limits, dryRunTotals )
		)
		.filter( ( line ): line is UpsellLineData => null !== line );

	if ( 0 === lines.length ) {
		return null;
	}

	// 有効な購入 URL があるときだけ Pro 版に触れる（v1.0 と同時に Pro 版を販売するかの判断を後回しにできる）。
	const proUrl = sanitizeProUrl( limits.pro_url );

	return (
		<Notice status="info" isDismissible={ false }>
			<p>
				{ '' !== proUrl ? (
					<>
						{ __(
							'The free version of Cart Bridge JP migrates a sample of your data. The Pro version also migrates the items not migrated yet.',
							'cart-bridge-jp'
						) }{ ' ' }
						{ /* `rel` は明示する: WP 7.1 コア同梱の `ExternalLink`（実行時に使われる `wp-components`）は
						npm 版と違い `rel` を付けない（実測）。 */ }
						<ExternalLink href={ proUrl } rel="noopener noreferrer">
							{ __(
								'Learn about the Pro version',
								'cart-bridge-jp'
							) }
						</ExternalLink>
					</>
				) : (
					__(
						'The free version of Cart Bridge JP migrates a sample of your data.',
						'cart-bridge-jp'
					)
				) }
			</p>
			<ul>
				{ lines.map( ( line ) => (
					<li key={ line.entity }>
						{ lineMessage( line, entityLabels[ line.entity ] ) }
					</li>
				) ) }
			</ul>
		</Notice>
	);
}
