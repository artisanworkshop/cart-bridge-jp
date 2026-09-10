import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
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
 * ASP側（この run で取得した全件）と Woo側（リンク済みで実在する全件）が件数・金額とも一致し、
 * 実体を失った mapping（missing）も無い状態。無料版で上限に達した run では一致しないのが正常。
 * @param row
 */
export function isReconciled( row: VerificationEntity ): boolean {
	return (
		0 === row.missing &&
		row.existing === row.processed &&
		( null === row.remote_amount || row.remote_amount === row.local_amount )
	);
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

	const allReconciled = report.entities.every( isReconciled );
	const hasAmounts = report.entities.some(
		( row ) => null !== row.remote_amount
	);

	return (
		<div className="cbjp-verification">
			<h3>{ __( 'Verification report', 'cart-bridge-jp' ) }</h3>
			<Notice
				status={ allReconciled ? 'success' : 'info' }
				isDismissible={ false }
			>
				{ allReconciled
					? __(
							'Every record fetched in this run exists in WooCommerce and the order totals match.',
							'cart-bridge-jp'
					  )
					: __(
							'Some records fetched from the platform are not in WooCommerce. In the free version this is expected once the sample limit is reached; otherwise check the warnings above and the preview (dry-run) report.',
							'cart-bridge-jp'
					  ) }
			</Notice>
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
														report.currency
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
									{ isReconciled( row )
										? __( 'Reconciled', 'cart-bridge-jp' )
										: __( 'Differs', 'cart-bridge-jp' ) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</div>
	);
}
