import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import apiFetch from '../api';
import type {
	EntityType,
	VerificationEntity,
	VerificationReport as VerificationReportData,
} from '../types';

interface Props {
	runId: string;
	entityLabels: Record< EntityType, string >;
}

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

/**
 * バックエンドは金額を `"1234.00"` 形式の10進文字列で返す（float を避けるため）。
 * 表示だけはブラウザの通貨書式に委ねる（`Intl` が通貨コードを知らない場合は生の値を出す）。
 * @param amount
 * @param currency
 */
export function formatAmount( amount: string, currency: string ): string {
	const value = Number( amount );

	if ( ! Number.isFinite( value ) ) {
		return `${ amount } ${ currency }`;
	}

	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency,
		} ).format( value );
	} catch {
		return `${ amount } ${ currency }`;
	}
}

/**
 * ASP側（この run で取得した全件）と Woo側（リンク済みで実在する全件）はスコープが違う
 * （後者はプラットフォーム全体・全期間）ため、単なる一致/不一致ではなく差の向きを返す:
 * - `missing`: mapping はあるが Woo 側の実体が無い（要 Rebuild links / 再 import）
 * - `fewer`: 取得件数より Woo 側が少ない（無料版の上限・スキップ・警告。上限到達時は正常）
 * - `more`: 取得件数より Woo 側が多い（過去の run で取り込んだ分。ASP 側で減った場合など）
 * - `amount`: 件数は一致するが受注合計が一致しない
 * - `reconciled`: 件数・金額とも一致し missing も無い
 * @param row
 */
export type RowStatus = 'reconciled' | 'missing' | 'fewer' | 'more' | 'amount';

export function rowStatus(
	row: VerificationEntity,
	amountsComparable: boolean
): RowStatus {
	if ( row.missing > 0 ) {
		return 'missing';
	}

	if ( row.existing < row.processed ) {
		return 'fewer';
	}

	if ( row.existing > row.processed ) {
		return 'more';
	}

	if (
		amountsComparable &&
		null !== row.remote_amount &&
		row.remote_amount !== row.local_amount
	) {
		return 'amount';
	}

	return 'reconciled';
}

/**
 * 金額の突合が成立した受注行があるか（旧ジョブは ASP 側合計が null、通貨不一致時は比較不能）。
 * @param report
 */
function hasComparedOrderTotals( report: VerificationReportData ): boolean {
	return (
		! report.currency_mismatch &&
		report.entities.some( ( row ) => null !== row.remote_amount )
	);
}

const STATUS_LABELS: Record< RowStatus, string > = {
	reconciled: __( 'Reconciled', 'cart-bridge-jp' ),
	missing: __( 'Missing links', 'cart-bridge-jp' ),
	fewer: __( 'Fewer in WooCommerce', 'cart-bridge-jp' ),
	more: __( 'More in WooCommerce', 'cart-bridge-jp' ),
	amount: __( 'Totals differ', 'cart-bridge-jp' ),
};

interface StatusNotice {
	status: 'success' | 'info' | 'warning';
	message: string;
}

function buildNotices(
	statuses: Set< RowStatus >,
	report: VerificationReportData
): StatusNotice[] {
	const notices: StatusNotice[] = [];

	if ( statuses.has( 'missing' ) ) {
		// “Rebuild links” は残っている実体から mapping を作り直すだけで、消えた実体は戻せない。
		// 消えた実体を作り直せるのは再 import（stale な mapping は writer が新規作成にフォールバック）。
		notices.push( {
			status: 'warning',
			message: __(
				'Some linked records no longer exist in WooCommerce. Import again to recreate them, or run the sample cleanup to drop the stale links.',
				'cart-bridge-jp'
			),
		} );
	}

	if ( statuses.has( 'fewer' ) ) {
		notices.push( {
			status: 'info',
			message: __(
				'Some records fetched from the platform are not in WooCommerce. In the free version this is expected once the sample limit is reached; otherwise check the warnings above and the preview (dry-run) report. If the links were lost (for example after a database move), run “Rebuild links” on the Tools tab.',
				'cart-bridge-jp'
			),
		} );
	}

	if ( report.currency_mismatch ) {
		notices.push( {
			status: 'warning',
			message: sprintf(
				/* translators: 1: platform currency code, 2: store currency code */
				__(
					'Platform totals are in %1$s but the store currency is %2$s. Order amounts were stored without conversion, so the totals cannot be reconciled.',
					'cart-bridge-jp'
				),
				report.platform_currency,
				report.currency
			),
		} );
	}

	if ( statuses.has( 'more' ) ) {
		notices.push( {
			status: 'info',
			message: __(
				'WooCommerce holds more linked records than this run fetched. They were imported by earlier runs (for example records that have since been removed on the platform).',
				'cart-bridge-jp'
			),
		} );
	}

	if ( statuses.has( 'amount' ) ) {
		notices.push( {
			status: 'warning',
			message: __(
				'The order totals differ between the platform and WooCommerce even though the counts match. Check the orders with warnings.',
				'cart-bridge-jp'
			),
		} );
	}

	if ( 0 === notices.length ) {
		notices.push( {
			status: 'success',
			message: hasComparedOrderTotals( report )
				? __(
						'Every record fetched in this run exists in WooCommerce and the order totals match.',
						'cart-bridge-jp'
				  )
				: __(
						'Every record fetched in this run exists in WooCommerce.',
						'cart-bridge-jp'
				  ),
		} );
	}

	return notices;
}

export default function VerificationReport( { runId, entityLabels }: Props ) {
	const [ report, setReport ] = useState< VerificationReportData | null >(
		null
	);
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		let cancelled = false;

		setReport( null );
		setError( null );

		apiFetch< VerificationReportData >( {
			path: `/cbjp/v1/runs/${ runId }/verification`,
		} )
			.then( ( data ) => {
				if ( ! cancelled ) {
					setReport( data );
				}
			} )
			.catch( ( err ) => {
				if ( ! cancelled ) {
					setError( errorMessage( err ) );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ runId ] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	if ( ! report ) {
		return <Spinner />;
	}

	const amountsComparable = ! report.currency_mismatch;
	const statuses = new Set(
		report.entities.map( ( row ) => rowStatus( row, amountsComparable ) )
	);
	const hasAmounts = report.entities.some(
		( row ) => null !== row.remote_amount
	);

	return (
		<div className="cbjp-verification">
			<h3>{ __( 'Verification report', 'cart-bridge-jp' ) }</h3>
			{ buildNotices( statuses, report ).map( ( notice ) => (
				<Notice
					key={ notice.message }
					status={ notice.status }
					isDismissible={ false }
				>
					{ notice.message }
				</Notice>
			) ) }
			<div className="cbjp-verification__scroll">
				<table className="widefat striped cbjp-verification__table">
					<thead>
						<tr>
							<th>{ __( 'Entity', 'cart-bridge-jp' ) }</th>
							<th>
								{ __(
									'Fetched from platform',
									'cart-bridge-jp'
								) }
							</th>
							<th>
								{ __(
									'Written in this run',
									'cart-bridge-jp'
								) }
							</th>
							<th>{ __( 'Skipped', 'cart-bridge-jp' ) }</th>
							<th>
								{ __( 'In WooCommerce', 'cart-bridge-jp' ) }
							</th>
							<th>{ __( 'Missing links', 'cart-bridge-jp' ) }</th>
							{ hasAmounts && (
								<>
									<th>
										{ __(
											'Platform total',
											'cart-bridge-jp'
										) }
									</th>
									<th>
										{ __(
											'WooCommerce total',
											'cart-bridge-jp'
										) }
									</th>
								</>
							) }
							<th>{ __( 'Status', 'cart-bridge-jp' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ report.entities.map( ( row ) => (
							<tr key={ row.entity }>
								<td>
									{ entityLabels[ row.entity ] ?? row.entity }
								</td>
								<td className="cbjp-num">{ row.processed }</td>
								<td className="cbjp-num">{ row.written }</td>
								<td className="cbjp-num">{ row.skipped }</td>
								<td className="cbjp-num">{ row.existing }</td>
								<td className="cbjp-num">{ row.missing }</td>
								{ hasAmounts && (
									<>
										<td className="cbjp-num">
											{ null !== row.remote_amount
												? formatAmount(
														row.remote_amount,
														report.platform_currency
												  )
												: '—' }
										</td>
										<td className="cbjp-num">
											{ null !== row.local_amount
												? formatAmount(
														row.local_amount,
														report.currency
												  )
												: '—' }
										</td>
									</>
								) }
								<td>
									{
										STATUS_LABELS[
											rowStatus( row, amountsComparable )
										]
									}
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</div>
	);
}
