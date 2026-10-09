import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import apiFetch from '../api';
import { activeRunsSeed } from '../active-runs';
import ActiveRunNotice from '../components/ActiveRunNotice';
import { entityLabel } from '../entity-labels';
import { useActiveRuns } from '../hooks/useActiveRuns';
import { joinList } from '../i18n';
import { type Counts, mergeCounts, mergeSkipped } from '../rebuild-result';
import type { Connection, RebuildResult } from '../types';

/**
 * バックエンドが `cursor` を返し続けても管理画面が無限にリクエストを打たない
 * ための上限。予算 100〜200 件/回なので、この回数で終わらないデータ量は想定していない。
 */
const MAX_BATCHES = 1000;

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

function sumCounts( counts: Counts ): number {
	return Object.values( counts ).reduce(
		( total, value ) => total + value,
		0
	);
}

/**
 * 件数の一覧。並びは応答の `counts` のキーの順（サーバーの走査順。Pro アドオンが足す種類も入る。R3-6b2）。
 * @param root0
 * @param root0.counts
 */
function CountList( { counts }: { counts: Counts } ) {
	const rows = Object.keys( counts ).filter(
		( key ) => ( counts[ key ] ?? 0 ) > 0
	);

	if ( 0 === rows.length ) {
		return null;
	}

	return (
		<ul className="cbjp-tools__counts">
			{ rows.map( ( key ) => (
				<li key={ key }>
					{ sprintf(
						/* translators: 1: a kind of record, e.g. "Products", 2: how many of them */
						__( '%1$s: %2$d', 'cart-bridge-jp' ),
						entityLabel( key ),
						counts[ key ]
					) }
				</li>
			) ) }
		</ul>
	);
}

/**
 * Tools タブは run を表示しないため、追跡する run は無い（見つけた run はすべて案内の対象）。
 */
const NO_TRACKED_RUNS: Array< string | null > = [];

export default function ToolsTab() {
	const [ connections, setConnections ] = useState< Connection[] | null >(
		null
	);
	const [ connectionsError, setConnectionsError ] = useState< string | null >(
		null
	);
	const [ platform, setPlatform ] = useState< string | null >( null );

	const [ rebuilding, setRebuilding ] = useState( false );
	const [ rebuildCounts, setRebuildCounts ] = useState< Counts | null >(
		null
	);
	const [ rebuildDone, setRebuildDone ] = useState( false );
	const [ rebuildError, setRebuildError ] = useState< string | null >( null );
	// 走査に失敗して飛ばした種類（R3-6b2。backlog r3-6b1/R1-L6）。理由はサーバーのログ（Logs タブ）にある。
	const [ rebuildSkipped, setRebuildSkipped ] = useState< string[] >( [] );
	// バッチ上限やエラーで止まった再構築を、先頭からやり直さずに続きから再開するための cursor。
	const [ rebuildCursor, setRebuildCursor ] = useState< string | null >(
		null
	);

	// 実行中にプラットフォームを切り替えた場合、遅れて届いた応答で別プラットフォームの表示を
	// 上書きしないためのカウンタ。値の一致（プラットフォーム名）で判定すると A→B→A と戻ったときに
	// 古い応答を最新と誤認するため、切替のたびに単調増加させる。同じ状態を更新しうる全ての非同期処理
	// （リンク再構築）が同じカウンタを共有する（ExportTab の platformGenerationRef と同じ流儀）。
	const platformGenerationRef = useRef( 0 );

	useEffect( () => {
		let cancelled = false;

		apiFetch< Connection[] >( { path: '/cbjp/v1/connections' } )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}

				setConnections( data );
				setPlatform( data[ 0 ]?.platform ?? null );
			} )
			.catch( ( err ) => {
				if ( ! cancelled ) {
					setConnectionsError( errorMessage( err ) );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [] );

	function handlePlatformChange( value: string ) {
		platformGenerationRef.current += 1;
		setPlatform( value );
		setRebuildCounts( null );
		setRebuildDone( false );
		setRebuildError( null );
		setRebuildSkipped( [] );
		setRebuildCursor( null );
	}

	const currentConnection =
		connections?.find( ( c ) => c.platform === platform ) ?? null;
	const platformLabel = currentConnection?.label ?? platform ?? '';
	const busy = rebuilding;
	// 進行中の run の発見（R3-0i・issue #70）。どのツールも進行中の run がある間はサーバーが 409 で拒否するため、
	// 先にボタンを止め、どのタブでその run を確認・キャンセルできるかを案内する。
	const activeRuns = useActiveRuns( platform, NO_TRACKED_RUNS );
	const runInProgress = activeRuns.runs.length > 0;

	/**
	 * ツールの 409（`cbjp_run_in_progress`）なら、応答の `active_runs` で案内を出す。
	 * @param selection 要求を出したときのプラットフォーム選択の番号（切り替え後の一覧に混ぜないため）
	 * @param err
	 */
	function showActiveRunsFrom( selection: number, err: unknown ) {
		const seed = activeRunsSeed( selection, err );

		if ( undefined !== seed ) {
			activeRuns.refresh( seed );
		}
	}

	async function runRebuild() {
		if ( null === platform ) {
			return;
		}

		const requested = platform;
		const selection = activeRuns.selectionRef.current;
		const generation = platformGenerationRef.current;
		let counts: Counts = {};
		let skipped: string[] = [];
		let cursor: string | null = rebuildCursor;

		setRebuilding( true );
		setRebuildError( null );
		setRebuildDone( false );
		setRebuildSkipped( skipped );
		setRebuildCounts( counts );

		try {
			for ( let batch = 0; batch < MAX_BATCHES; batch++ ) {
				const result: RebuildResult = await apiFetch< RebuildResult >( {
					path: '/cbjp/v1/tools/rebuild-mappings',
					method: 'POST',
					data:
						null === cursor
							? { platform: requested }
							: { platform: requested, cursor },
				} );

				if ( platformGenerationRef.current !== generation ) {
					return;
				}

				counts = mergeCounts( counts, result.counts );
				setRebuildCounts( counts );
				skipped = mergeSkipped( skipped, result.skipped );
				setRebuildSkipped( skipped );
				cursor = result.cursor;
				setRebuildCursor( cursor );

				if ( null === cursor ) {
					setRebuildDone( true );

					return;
				}
			}

			setRebuildError(
				__(
					'The rebuild paused after the maximum number of batches. Click “Rebuild links” again to continue from where it stopped.',
					'cart-bridge-jp'
				)
			);
		} catch ( err ) {
			if ( platformGenerationRef.current === generation ) {
				setRebuildError( errorMessage( err ) );
				showActiveRunsFrom( selection, err );
			}
		} finally {
			if ( platformGenerationRef.current === generation ) {
				setRebuilding( false );
			}
		}
	}

	if ( connectionsError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ connectionsError }
			</Notice>
		);
	}

	if ( null === connections ) {
		return <Spinner />;
	}

	if ( 0 === connections.length || null === platform ) {
		return <p>{ __( 'No platforms are available.', 'cart-bridge-jp' ) }</p>;
	}

	return (
		<div className="cbjp-tools">
			{ connections.length > 1 && (
				<SelectControl
					label={ __( 'Platform', 'cart-bridge-jp' ) }
					value={ platform }
					options={ connections.map( ( c ) => ( {
						label: c.label,
						value: c.platform,
					} ) ) }
					onChange={ handlePlatformChange }
					disabled={ busy }
				/>
			) }

			{ platform && (
				<ActiveRunNotice
					key={ platform }
					platform={ platform }
					runs={ activeRuns.runs }
					// Import/Export タブは接続済みのプラットフォームしか開けない（未接続ならキャンセルだけ出す）。
					showTabLinks={ true === currentConnection?.connected }
					onChanged={ () => activeRuns.refresh() }
				/>
			) }

			<Card className="cbjp-tools__card">
				<CardHeader>
					<strong>{ __( 'Rebuild links', 'cart-bridge-jp' ) }</strong>
				</CardHeader>
				<CardBody>
					<p>
						{ sprintf(
							/* translators: %s: platform label */
							__(
								'Restores the links between %s records and the WooCommerce data this plugin created (for example after a reinstall or a database move). It scans the ownership metadata written during import; nothing is fetched from the platform and no data is modified. Linked records are re-checked on the next import.',
								'cart-bridge-jp'
							),
							platformLabel
						) }
					</p>

					{ rebuildError && (
						<Notice
							status="error"
							onRemove={ () => setRebuildError( null ) }
						>
							{ rebuildError }
						</Notice>
					) }

					<div className="cbjp-tools__actions">
						<Button
							variant="secondary"
							isBusy={ rebuilding }
							disabled={ busy || runInProgress }
							onClick={ () => void runRebuild() }
						>
							{ __( 'Rebuild links', 'cart-bridge-jp' ) }
						</Button>
					</div>

					{ rebuildCounts && (
						<div className="cbjp-tools__result">
							{ rebuilding && (
								<p>
									{ sprintf(
										/* translators: %d: number of links restored so far */
										_n(
											'Scanning… %d link restored so far.',
											'Scanning… %d links restored so far.',
											sumCounts( rebuildCounts ),
											'cart-bridge-jp'
										),
										sumCounts( rebuildCounts )
									) }
								</p>
							) }
							{ rebuildDone && (
								<Notice
									status="success"
									isDismissible={ false }
								>
									{ sprintf(
										/* translators: %d: number of links restored */
										_n(
											'Rebuild finished. %d link restored.',
											'Rebuild finished. %d links restored.',
											sumCounts( rebuildCounts ),
											'cart-bridge-jp'
										),
										sumCounts( rebuildCounts )
									) }
								</Notice>
							) }
							{ rebuildSkipped.length > 0 && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ sprintf(
										/* translators: %s: list of kinds of records, e.g. "Products, Orders" */
										__(
											'Some kinds of records could not be scanned, so their links were not restored: %s. Check the Logs tab for the reason.',
											'cart-bridge-jp'
										),
										joinList(
											rebuildSkipped.map( ( key ) =>
												entityLabel( key )
											)
										)
									) }
								</Notice>
							) }
							<CountList counts={ rebuildCounts } />
						</div>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
