import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import apiFetch from '../api';
import type { LogEntry } from '../types';

const LEVEL_OPTIONS = [
	{ label: __( 'All levels', 'cart-bridge-jp' ), value: '' },
	{ label: __( 'Debug', 'cart-bridge-jp' ), value: 'debug' },
	{ label: __( 'Info', 'cart-bridge-jp' ), value: 'info' },
	{ label: __( 'Warning', 'cart-bridge-jp' ), value: 'warning' },
	{ label: __( 'Error', 'cart-bridge-jp' ), value: 'error' },
];

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

/**
 * `Logger::write_to_db()`は`current_time('mysql', true)`でUTCの
 * `Y-m-d H:i:s`形式（例: `2026-09-06 07:15:49`）を保存する。スペース区切りの
 * まま`Date`に渡すとブラウザのローカル時刻として誤解釈されるため、ISO 8601の
 * UTC表記に変換してから閲覧者のローカル時刻へ変換する（サイトのタイムゾーン設定
 * ではなく閲覧者のブラウザのタイムゾーンになるが、管理画面を見る本人が自分の
 * ローカル時刻で確認できることを優先する）。
 * @param createdAt
 */
function formatLogTime( createdAt: string ): string {
	const date = new Date( `${ createdAt.replace( ' ', 'T' ) }Z` );

	return isNaN( date.getTime() ) ? createdAt : date.toLocaleString();
}

function formatContext( contextJson: string | null ): string | null {
	if ( ! contextJson ) {
		return null;
	}

	try {
		return JSON.stringify( JSON.parse( contextJson ), null, 2 );
	} catch {
		return contextJson;
	}
}

export default function LogsTab() {
	const [ jobId, setJobId ] = useState( '' );
	const [ level, setLevel ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ logs, setLogs ] = useState< LogEntry[] | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		const params = new URLSearchParams();

		if ( '' !== jobId ) {
			params.set( 'job_id', jobId );
		}

		if ( '' !== level ) {
			params.set( 'level', level );
		}

		params.set( 'page', String( page ) );

		let cancelled = false;

		apiFetch< LogEntry[] >( {
			path: `/cbjp/v1/logs?${ params.toString() }`,
		} )
			.then( ( data ) => {
				if ( ! cancelled ) {
					setLogs( data );
					setError( null );
				}
			} )
			.catch( ( err: unknown ) => {
				if ( ! cancelled ) {
					// 直前のフィルタ/ページの結果を残したままにすると、新しい絞り込み条件の
					// 下に古い一覧が表示され続け、一致した結果と誤認されうる。
					setLogs( null );
					setError( errorMessage( err ) );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ jobId, level, page ] );

	// フィルタ変更時に1ページ目へ戻す（`onChange`側で直接`setPage(1)`する。別effectに
	// すると、フィルタ変更時に「古いpageで1回・リセット後のpage=1でもう1回」と
	// 無駄なAPIリクエストが発生するため避ける）。
	function handleJobIdChange( value: string ) {
		setJobId( value );
		setPage( 1 );
	}

	function handleLevelChange( value: string ) {
		setLevel( value );
		setPage( 1 );
	}

	return (
		<div className="cbjp-logs">
			<div className="cbjp-logs__filters">
				<TextControl
					label={ __( 'Job ID', 'cart-bridge-jp' ) }
					type="number"
					value={ jobId }
					onChange={ handleJobIdChange }
				/>
				<SelectControl
					label={ __( 'Level', 'cart-bridge-jp' ) }
					value={ level }
					options={ LEVEL_OPTIONS }
					onChange={ handleLevelChange }
				/>
			</div>

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ null === logs && ! error && <Spinner /> }

			{ logs && 0 === logs.length && (
				<p>{ __( 'No logs found.', 'cart-bridge-jp' ) }</p>
			) }

			{ logs && logs.length > 0 && (
				<table className="widefat striped cbjp-logs__table">
					<thead>
						<tr>
							<th>{ __( 'Time', 'cart-bridge-jp' ) }</th>
							<th>{ __( 'Level', 'cart-bridge-jp' ) }</th>
							<th>{ __( 'Job', 'cart-bridge-jp' ) }</th>
							<th>{ __( 'Message', 'cart-bridge-jp' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ logs.map( ( log ) => {
							const context = formatContext( log.context_json );

							return (
								<tr key={ log.id }>
									<td>{ formatLogTime( log.created_at ) }</td>
									<td>{ log.level }</td>
									<td>{ log.job_id ?? '—' }</td>
									<td>
										{ log.message }
										{ context && (
											<details>
												<summary>
													{ __(
														'Context',
														'cart-bridge-jp'
													) }
												</summary>
												<pre>{ context }</pre>
											</details>
										) }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }

			<div className="cbjp-logs__pagination">
				<Button
					variant="secondary"
					disabled={ page <= 1 }
					onClick={ () =>
						setPage( ( prev ) => Math.max( 1, prev - 1 ) )
					}
				>
					{ __( 'Previous', 'cart-bridge-jp' ) }
				</Button>{ ' ' }
				<Button
					variant="secondary"
					disabled={ ! logs || logs.length < 50 }
					onClick={ () => setPage( ( prev ) => prev + 1 ) }
				>
					{ __( 'Next', 'cart-bridge-jp' ) }
				</Button>
			</div>
		</div>
	);
}
