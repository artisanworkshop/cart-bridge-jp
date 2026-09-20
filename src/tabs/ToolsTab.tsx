import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
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
import { ENTITY_LABELS } from '../entity-labels';
import type {
	CleanupPreview,
	CleanupResult,
	Connection,
	RebuildResult,
	StateRepairBucket,
	StateRepairCounts,
	StateRepairEntity,
	StateRepairResult,
} from '../types';

/**
 * `Woo\Tools\SampleCleanup::RESULT_KEYS` と同じ並び（表示順）。
 */
const CLEANUP_KEYS = [
	'category',
	'tag',
	'product',
	'variant',
	'customer',
	'order',
	'stock',
	'coupon',
	'review',
	'attachment',
] as const;

const TOOL_LABELS: Record< string, string > = {
	...ENTITY_LABELS,
	variant: __( 'Variations', 'cart-bridge-jp' ),
	attachment: __( 'Images', 'cart-bridge-jp' ),
};

/**
 * バックエンドが `has_more` / `cursor` を返し続けても管理画面が無限にリクエストを打たない
 * ための上限。予算 100〜200 件/回なので、この回数で終わらないデータ量は想定していない。
 */
const MAX_BATCHES = 1000;

type Counts = Record< string, number >;

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

function label( key: string ): string {
	return TOOL_LABELS[ key ] ?? key;
}

function mergeCounts( into: Counts, add: Counts ): Counts {
	const merged = { ...into };

	for ( const [ key, value ] of Object.entries( add ) ) {
		merged[ key ] = ( merged[ key ] ?? 0 ) + value;
	}

	return merged;
}

function sumCounts( counts: Counts ): number {
	return Object.values( counts ).reduce(
		( total, value ) => total + value,
		0
	);
}

const REPAIR_ENTITIES: readonly StateRepairEntity[] = [ 'customer', 'order' ];

/**
 * 県コード修復のエラーのうち、同じ位置から再開しても結果が変わらないもの（`RestController` のエラーコード）。
 */
const NON_RESUMABLE_REPAIR_ERRORS: readonly string[] = [
	'cbjp_invalid_cursor',
	'cbjp_repair_not_applicable',
	'cbjp_repair_unsupported',
];

const REPAIR_BUCKETS: readonly StateRepairBucket[] = [
	'fixed',
	'already_correct',
	'unverified',
	'unavailable',
	'skipped',
];

function emptyRepairCounts(): StateRepairCounts {
	const empty = (): Record< StateRepairBucket, number > => ( {
		fixed: 0,
		already_correct: 0,
		unverified: 0,
		unavailable: 0,
		skipped: 0,
	} );

	return { customer: empty(), order: empty() };
}

function mergeRepairCounts(
	into: StateRepairCounts,
	add: StateRepairCounts
): StateRepairCounts {
	const merged = emptyRepairCounts();

	for ( const entity of REPAIR_ENTITIES ) {
		for ( const bucket of REPAIR_BUCKETS ) {
			merged[ entity ][ bucket ] =
				( into[ entity ]?.[ bucket ] ?? 0 ) +
				( add[ entity ]?.[ bucket ] ?? 0 );
		}
	}

	return merged;
}

function repairBucketTotal(
	counts: StateRepairCounts,
	bucket?: StateRepairBucket
): number {
	return REPAIR_ENTITIES.reduce(
		( total, entity ) =>
			total +
			( bucket
				? counts[ entity ][ bucket ]
				: REPAIR_BUCKETS.reduce(
						( sum, key ) => sum + counts[ entity ][ key ],
						0
				  ) ),
		0
	);
}

/**
 * 中断（レート制限・接続切れ等）した県コード修復を、先頭からやり直さず続きから再開するための状態。
 * `cursor` は失敗した行を指す（サーバーがエラー応答に含める）。処理は冪等なので同じ行から再開してよい。
 */
interface RepairPending {
	mode: 'scan' | 'repair';
	cursor: string | null;
	counts: StateRepairCounts;
}

function CountList( {
	counts,
	keys,
}: {
	counts: Counts;
	keys: readonly string[];
} ) {
	const rows = keys.filter( ( key ) => ( counts[ key ] ?? 0 ) > 0 );

	if ( 0 === rows.length ) {
		return null;
	}

	return (
		<ul className="cbjp-tools__counts">
			{ rows.map( ( key ) => (
				<li key={ key }>
					{ label( key ) }: { counts[ key ] }
				</li>
			) ) }
		</ul>
	);
}

export default function ToolsTab() {
	const [ connections, setConnections ] = useState< Connection[] | null >(
		null
	);
	const [ connectionsError, setConnectionsError ] = useState< string | null >(
		null
	);
	const [ platform, setPlatform ] = useState< string | null >( null );

	const [ preview, setPreview ] = useState< CleanupPreview | null >( null );
	const [ previewing, setPreviewing ] = useState( false );
	const [ cleaning, setCleaning ] = useState( false );
	const [ cleanupProgress, setCleanupProgress ] = useState< {
		deleted: Counts;
		unlinked: Counts;
	} | null >( null );
	const [ cleanupDone, setCleanupDone ] = useState( false );
	const [ cleanupError, setCleanupError ] = useState< string | null >( null );

	const [ rebuilding, setRebuilding ] = useState( false );
	const [ rebuildCounts, setRebuildCounts ] = useState< Counts | null >(
		null
	);
	const [ rebuildDone, setRebuildDone ] = useState( false );
	const [ rebuildError, setRebuildError ] = useState< string | null >( null );
	// バッチ上限やエラーで止まった再構築を、先頭からやり直さずに続きから再開するための cursor。
	const [ rebuildCursor, setRebuildCursor ] = useState< string | null >(
		null
	);

	const [ repairMode, setRepairMode ] = useState< 'scan' | 'repair' | null >(
		null
	);
	const [ repairView, setRepairView ] = useState< {
		mode: 'scan' | 'repair';
		counts: StateRepairCounts;
		done: boolean;
	} | null >( null );
	// 最後に完了した Scan の集計。Repair はこれが「要補正 1件以上」のときだけ実行できる
	// （Scan 結果が確認ステップを兼ねる。ネイティブの confirm は使わない）。
	const [ scanTotals, setScanTotals ] = useState< StateRepairCounts | null >(
		null
	);
	const [ repairPending, setRepairPending ] =
		useState< RepairPending | null >( null );
	const [ repairError, setRepairError ] = useState< string | null >( null );

	// 実行中にプラットフォームを切り替えた場合、遅れて届いた応答で別プラットフォームの表示を
	// 上書きしないためのカウンタ。値の一致（プラットフォーム名）で判定すると A→B→A と戻ったときに
	// 古い応答を最新と誤認するため、切替のたびに単調増加させる。同じ状態を更新しうる全ての非同期処理
	// （サンプル削除・リンク再構築・県コード修復の Scan/Repair）が同じカウンタを共有する
	// （ExportTab の platformGenerationRef と同じ流儀）。
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
		setPreview( null );
		setCleanupProgress( null );
		setCleanupDone( false );
		setCleanupError( null );
		setRebuildCounts( null );
		setRebuildDone( false );
		setRebuildError( null );
		setRebuildCursor( null );
		setRepairMode( null );
		setRepairView( null );
		setScanTotals( null );
		setRepairPending( null );
		setRepairError( null );
	}

	const currentConnection =
		connections?.find( ( c ) => c.platform === platform ) ?? null;
	const platformLabel = currentConnection?.label ?? platform ?? '';
	const busy = previewing || cleaning || rebuilding || null !== repairMode;

	async function loadPreview() {
		if ( null === platform ) {
			return;
		}

		const requested = platform;
		const generation = platformGenerationRef.current;
		setPreviewing( true );
		setCleanupError( null );
		setCleanupDone( false );
		setCleanupProgress( null );

		try {
			const data = await apiFetch< CleanupPreview >( {
				path: `/cbjp/v1/tools/sample-cleanup?platform=${ encodeURIComponent(
					requested
				) }`,
			} );

			if ( platformGenerationRef.current === generation ) {
				setPreview( data );
			}
		} catch ( err ) {
			if ( platformGenerationRef.current === generation ) {
				setCleanupError( errorMessage( err ) );
			}
		} finally {
			if ( platformGenerationRef.current === generation ) {
				setPreviewing( false );
			}
		}
	}

	async function runCleanup() {
		if ( null === platform || null === preview ) {
			return;
		}

		const confirmed =
			0 === previewTotal ||
			// eslint-disable-next-line no-alert -- 破壊的操作の確認。ImportTab の実行前確認と同じ流儀。
			window.confirm(
				sprintf(
					/* translators: %s: platform label */
					__(
						'Delete every product, order, customer, coupon, category, tag and image imported from %s? This cannot be undone.',
						'cart-bridge-jp'
					),
					platformLabel
				)
			);

		if ( ! confirmed ) {
			return;
		}

		const requested = platform;
		const generation = platformGenerationRef.current;
		let deleted: Counts = {};
		let unlinked: Counts = {};

		setCleaning( true );
		setCleanupError( null );
		setCleanupDone( false );
		setCleanupProgress( { deleted, unlinked } );

		try {
			for ( let batch = 0; batch < MAX_BATCHES; batch++ ) {
				const result = await apiFetch< CleanupResult >( {
					path: '/cbjp/v1/tools/sample-cleanup',
					method: 'POST',
					data: { platform: requested },
				} );

				if ( platformGenerationRef.current !== generation ) {
					return;
				}

				deleted = mergeCounts( deleted, result.deleted );
				unlinked = mergeCounts( unlinked, result.unlinked );
				setCleanupProgress( { deleted, unlinked } );

				if ( ! result.has_more ) {
					setCleanupDone( true );
					setPreview( null );

					return;
				}
			}

			setCleanupError(
				__(
					'The cleanup did not finish within the expected number of batches. Run it again to continue.',
					'cart-bridge-jp'
				)
			);
		} catch ( err ) {
			if ( platformGenerationRef.current === generation ) {
				setCleanupError( errorMessage( err ) );
			}
		} finally {
			if ( platformGenerationRef.current === generation ) {
				setCleaning( false );
			}
		}
	}

	async function runRebuild() {
		if ( null === platform ) {
			return;
		}

		const requested = platform;
		const generation = platformGenerationRef.current;
		let counts: Counts = {};
		let cursor: string | null = rebuildCursor;

		setRebuilding( true );
		setRebuildError( null );
		setRebuildDone( false );
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
			}
		} finally {
			if ( platformGenerationRef.current === generation ) {
				setRebuilding( false );
			}
		}
	}

	async function runRepair( mode: 'scan' | 'repair' ) {
		if ( null === platform ) {
			return;
		}

		const requested = platform;
		const generation = platformGenerationRef.current;
		// 中断した同じ種類の実行があれば続きから再開し、そうでなければ先頭から始める。
		const resume = repairPending?.mode === mode ? repairPending : null;
		let counts: StateRepairCounts = resume?.counts ?? emptyRepairCounts();
		let cursor: string | null = resume?.cursor ?? null;

		setRepairMode( mode );
		setRepairError( null );
		setRepairPending( null );
		setRepairView( { mode, counts, done: false } );

		if ( 'scan' === mode ) {
			setScanTotals( null );
		}

		try {
			for ( let batch = 0; batch < MAX_BATCHES; batch++ ) {
				const result: StateRepairResult =
					await apiFetch< StateRepairResult >(
						'scan' === mode
							? {
									path: `/cbjp/v1/tools/repair-states?platform=${ encodeURIComponent(
										requested
									) }${
										null === cursor
											? ''
											: `&cursor=${ encodeURIComponent(
													cursor
											  ) }`
									}`,
							  }
							: {
									path: '/cbjp/v1/tools/repair-states',
									method: 'POST',
									data:
										null === cursor
											? { platform: requested }
											: { platform: requested, cursor },
							  }
					);

				if ( platformGenerationRef.current !== generation ) {
					return;
				}

				counts = mergeRepairCounts( counts, result.counts );
				cursor = result.cursor;
				setRepairView( { mode, counts, done: null === cursor } );

				if ( null === cursor ) {
					// Repair の後は状態が変わっているため、確認のために Scan をやり直させる。
					setScanTotals( 'scan' === mode ? counts : null );

					return;
				}
			}

			setRepairPending( { mode, cursor, counts } );
			setRepairError(
				__(
					'The run paused after the maximum number of batches. Click “Continue” to carry on from where it stopped.',
					'cart-bridge-jp'
				)
			);
		} catch ( err ) {
			if ( platformGenerationRef.current === generation ) {
				// ASP への照会に失敗して中断した応答（レート制限・接続切れ等）は、処理済みの件数と
				// 失敗した行を指す cursor を含む。件数を失わず、同じ位置から再開できる（処理は冪等）。
				const failure = err as {
					code?: string;
					data?: {
						counts?: StateRepairCounts;
						cursor?: string | null;
						interruption?: string;
					};
				};

				if ( failure.data?.counts ) {
					counts = mergeRepairCounts( counts, failure.data.counts );

					if ( 'string' === typeof failure.data.interruption ) {
						cursor = failure.data.cursor ?? null;
					}
				}

				setRepairView( { mode, counts, done: false } );

				// 不正な cursor・修復の対象外・アダプタが単一取得に非対応、は再開しても同じ結果になるため
				// 保持しない（「Start over」で先頭からやり直す）。
				if (
					! NON_RESUMABLE_REPAIR_ERRORS.includes( failure.code ?? '' )
				) {
					setRepairPending( { mode, cursor, counts } );
				}

				setRepairError( errorMessage( err ) );
			}
		} finally {
			if ( platformGenerationRef.current === generation ) {
				setRepairMode( null );
			}
		}
	}

	function startRepairOver() {
		setRepairPending( null );
		setRepairError( null );
		setRepairView( null );
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
		return (
			<p>
				{ __(
					'No platform adapters are registered yet.',
					'cart-bridge-jp'
				) }
			</p>
		);
	}

	const previewTotal = preview
		? sumCounts( preview.delete ) + sumCounts( preview.unlink )
		: 0;
	const cleanupBlocked =
		null !== preview &&
		( preview.run_in_progress ||
			( preview.requires_delete_users && ! preview.can_delete_users ) );
	const repairNeedsRepair =
		null !== scanTotals && repairBucketTotal( scanTotals, 'fixed' ) > 0;
	const canRepair = repairNeedsRepair || 'repair' === repairPending?.mode;
	// 「確認できなかった」（unverified）と「ASP から使える記録が返らなかった」（unavailable）は、直っていない
	// 可能性が残る件数。完了通知を緑の成功にせず、警告として表に誘導する。
	const repairUnresolved = repairView
		? repairBucketTotal( repairView.counts, 'unverified' ) +
		  repairBucketTotal( repairView.counts, 'unavailable' )
		: 0;
	let scanNoticeStatus: 'success' | 'info' | 'warning' = 'success';

	if ( repairUnresolved > 0 ) {
		scanNoticeStatus = 'warning';
	} else if ( repairNeedsRepair ) {
		scanNoticeStatus = 'info';
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

			<Card className="cbjp-tools__card">
				<CardHeader>
					<strong>
						{ __( 'Sample data cleanup', 'cart-bridge-jp' ) }
					</strong>
				</CardHeader>
				<CardBody>
					<p>
						{ sprintf(
							/* translators: %s: platform label */
							__(
								'Deletes the WooCommerce data that was imported from %s (products, orders, customers, coupons, categories, tags and images) together with the links between the two stores. Use it to reset the free-version sample before a full migration; the next import selects a fresh sample.',
								'cart-bridge-jp'
							),
							platformLabel
						) }
					</p>
					<p>
						{ __(
							'Customer accounts that already existed before the import are kept and only unlinked.',
							'cart-bridge-jp'
						) }
					</p>

					{ cleanupError && (
						<Notice
							status="error"
							onRemove={ () => setCleanupError( null ) }
						>
							{ cleanupError }
						</Notice>
					) }

					<div className="cbjp-tools__actions">
						<Button
							variant="secondary"
							isBusy={ previewing }
							disabled={ busy }
							onClick={ () => void loadPreview() }
						>
							{ __( 'Preview cleanup', 'cart-bridge-jp' ) }
						</Button>
					</div>

					{ preview && (
						<div className="cbjp-tools__preview">
							{ preview.run_in_progress && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ __(
										'A run is in progress for this platform. Wait for it to finish (or cancel it on the Import tab) before cleaning up.',
										'cart-bridge-jp'
									) }
								</Notice>
							) }
							{ preview.requires_delete_users &&
								! preview.can_delete_users && (
									<Notice
										status="warning"
										isDismissible={ false }
									>
										{ __(
											'Customer accounts created by the import can only be deleted by a user who is allowed to delete users. Ask an administrator to run the cleanup.',
											'cart-bridge-jp'
										) }
									</Notice>
								) }
							{ 0 === previewTotal ? (
								<p>
									{ preview.sample_selected
										? __(
												'Nothing is linked any more, but a sample selection is still stored. Clear it so the next import selects a fresh sample.',
												'cart-bridge-jp'
										  )
										: __(
												'Nothing to delete for this platform.',
												'cart-bridge-jp'
										  ) }
								</p>
							) : (
								<>
									{ sumCounts( preview.delete ) > 0 && (
										<>
											<p>
												<strong>
													{ __(
														'The following records will be deleted:',
														'cart-bridge-jp'
													) }
												</strong>
											</p>
											<CountList
												counts={ preview.delete }
												keys={ CLEANUP_KEYS }
											/>
										</>
									) }
									{ sumCounts( preview.unlink ) > 0 && (
										<>
											<p>
												<strong>
													{ __(
														'The following records will only be unlinked (kept):',
														'cart-bridge-jp'
													) }
												</strong>
											</p>
											<CountList
												counts={ preview.unlink }
												keys={ CLEANUP_KEYS }
											/>
											<p>
												{ __(
													'Unlinked records are customer accounts that existed before the import, records owned by another platform, or links whose target no longer exists.',
													'cart-bridge-jp'
												) }
											</p>
										</>
									) }
								</>
							) }
							{ ( previewTotal > 0 ||
								preview.sample_selected ) && (
								<div className="cbjp-tools__actions">
									<Button
										variant="primary"
										isDestructive={ previewTotal > 0 }
										isBusy={ cleaning }
										disabled={ busy || cleanupBlocked }
										onClick={ () => void runCleanup() }
									>
										{ previewTotal > 0
											? __(
													'Delete sample data',
													'cart-bridge-jp'
											  )
											: __(
													'Clear sample selection',
													'cart-bridge-jp'
											  ) }
									</Button>
								</div>
							) }
						</div>
					) }

					{ cleanupProgress && (
						<div className="cbjp-tools__result">
							{ cleaning && (
								<p>
									{ sprintf(
										/* translators: %d: number of records removed so far */
										__(
											'Deleting… %d records removed so far.',
											'cart-bridge-jp'
										),
										sumCounts( cleanupProgress.deleted )
									) }
								</p>
							) }
							{ cleanupDone && (
								<Notice
									status="success"
									isDismissible={ false }
								>
									{ __(
										'Cleanup finished. The next import will select a new sample.',
										'cart-bridge-jp'
									) }
								</Notice>
							) }
							{ sumCounts( cleanupProgress.deleted ) > 0 && (
								<>
									<p>
										<strong>
											{ __(
												'Deleted',
												'cart-bridge-jp'
											) }
										</strong>
									</p>
									<CountList
										counts={ cleanupProgress.deleted }
										keys={ CLEANUP_KEYS }
									/>
								</>
							) }
							{ sumCounts( cleanupProgress.unlinked ) > 0 && (
								<>
									<p>
										<strong>
											{ __(
												'Unlinked only (kept)',
												'cart-bridge-jp'
											) }
										</strong>
									</p>
									<CountList
										counts={ cleanupProgress.unlinked }
										keys={ CLEANUP_KEYS }
									/>
								</>
							) }
						</div>
					) }
				</CardBody>
			</Card>

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
							disabled={ busy }
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
										__(
											'Scanning… %d links restored so far.',
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
										__(
											'Rebuild finished. %d links restored.',
											'cart-bridge-jp'
										),
										sumCounts( rebuildCounts )
									) }
								</Notice>
							) }
							<CountList
								counts={ rebuildCounts }
								keys={ CLEANUP_KEYS }
							/>
						</div>
					) }
				</CardBody>
			</Card>

			<Card className="cbjp-tools__card">
				<CardHeader>
					<strong>
						{ __( 'Repair prefecture data', 'cart-bridge-jp' ) }
					</strong>
				</CardHeader>
				<CardBody>
					<p>
						{ sprintf(
							/* translators: %1$s: platform label */
							__(
								'Earlier versions of this plugin saved the wrong prefecture for some customers and orders imported from %1$s (23 prefectures were affected; for example Akita was saved as Miyagi). This tool compares the imported records with %1$s and corrects only the prefecture of records that were saved with that mistake.',
								'cart-bridge-jp'
							),
							platformLabel
						) }
					</p>
					<p>
						{ __(
							'Records are corrected only when their postal code and address still match the platform. Anything else, such as a prefecture you edited by hand, is left as it is. Only records that this plugin imported are touched, including existing accounts it matched by email and updated. Click “Scan” first to see what would change; nothing is written until you click “Repair”. WooCommerce Analytics customer data is not updated by this tool. Run this before “Sample data cleanup”: the cleanup removes the links this tool relies on, so accounts it unlinks can no longer be found.',
							'cart-bridge-jp'
						) }
					</p>

					{ repairError && (
						<Notice
							status="error"
							onRemove={ () => setRepairError( null ) }
						>
							{ repairError }
						</Notice>
					) }

					<div className="cbjp-tools__actions">
						<Button
							variant="secondary"
							isBusy={ 'scan' === repairMode }
							disabled={
								busy || 'repair' === repairPending?.mode
							}
							onClick={ () => void runRepair( 'scan' ) }
						>
							{ 'scan' === repairPending?.mode
								? __( 'Continue scan', 'cart-bridge-jp' )
								: __( 'Scan', 'cart-bridge-jp' ) }
						</Button>{ ' ' }
						<Button
							variant="primary"
							isBusy={ 'repair' === repairMode }
							disabled={
								busy ||
								! canRepair ||
								'scan' === repairPending?.mode
							}
							onClick={ () => void runRepair( 'repair' ) }
						>
							{ 'repair' === repairPending?.mode
								? __( 'Continue repair', 'cart-bridge-jp' )
								: __( 'Repair', 'cart-bridge-jp' ) }
						</Button>
						{ null !== repairPending && (
							<>
								{ ' ' }
								<Button
									variant="tertiary"
									disabled={ busy }
									onClick={ startRepairOver }
								>
									{ __( 'Start over', 'cart-bridge-jp' ) }
								</Button>
							</>
						) }
					</div>

					{ repairView && (
						<div className="cbjp-tools__result">
							{ 'scan' === repairMode && (
								<p>
									{ sprintf(
										/* translators: %d: number of records checked so far */
										__(
											'Scanning… %d records checked so far.',
											'cart-bridge-jp'
										),
										repairBucketTotal( repairView.counts )
									) }
								</p>
							) }
							{ 'repair' === repairMode && (
								<p>
									{ sprintf(
										/* translators: %d: number of records checked so far */
										__(
											'Repairing… %d records checked so far.',
											'cart-bridge-jp'
										),
										repairBucketTotal( repairView.counts )
									) }
								</p>
							) }
							{ repairView.done && 'scan' === repairView.mode && (
								<Notice
									status={ scanNoticeStatus }
									isDismissible={ false }
								>
									{ repairNeedsRepair &&
										sprintf(
											/* translators: %d: number of records that need repair */
											__(
												'%d records need repair. Review the numbers below, then click “Repair”.',
												'cart-bridge-jp'
											),
											repairBucketTotal(
												repairView.counts,
												'fixed'
											)
										) }
									{ ! repairNeedsRepair &&
										0 === repairUnresolved &&
										__(
											'No records need repair.',
											'cart-bridge-jp'
										) }
									{ repairUnresolved > 0 && (
										<>
											{ repairNeedsRepair ? ' ' : '' }
											{ sprintf(
												/* translators: %d: number of records that could not be confirmed or checked */
												__(
													'%d records could not be confirmed or checked and will be left as they are. See the numbers below.',
													'cart-bridge-jp'
												),
												repairUnresolved
											) }
										</>
									) }
								</Notice>
							) }
							{ repairView.done &&
								'repair' === repairView.mode && (
									<Notice
										status={
											repairUnresolved > 0
												? 'warning'
												: 'success'
										}
										isDismissible={ false }
									>
										{ sprintf(
											/* translators: %d: number of records corrected */
											__(
												'Repair finished. %d records were corrected.',
												'cart-bridge-jp'
											),
											repairBucketTotal(
												repairView.counts,
												'fixed'
											)
										) }
										{ repairUnresolved > 0 && (
											<>
												{ ' ' }
												{ sprintf(
													/* translators: %d: number of records that could not be confirmed or checked */
													__(
														'%d records could not be confirmed or checked and were left unchanged.',
														'cart-bridge-jp'
													),
													repairUnresolved
												) }
											</>
										) }{ ' ' }
										{ __(
											'Run “Scan” again to confirm that nothing is left.',
											'cart-bridge-jp'
										) }
									</Notice>
								) }
							<table className="widefat striped cbjp-tools__repair-table">
								<thead>
									<tr>
										<th />
										<th>
											{ 'scan' === repairView.mode
												? __(
														'Needs repair',
														'cart-bridge-jp'
												  )
												: __(
														'Repaired',
														'cart-bridge-jp'
												  ) }
										</th>
										<th>
											{ __(
												'Already correct',
												'cart-bridge-jp'
											) }
										</th>
										<th>
											{ __(
												'Could not be confirmed',
												'cart-bridge-jp'
											) }
										</th>
										<th>
											{ __(
												'Not found on the platform',
												'cart-bridge-jp'
											) }
										</th>
										<th>
											{ __(
												'Skipped',
												'cart-bridge-jp'
											) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ REPAIR_ENTITIES.map( ( entity ) => (
										<tr key={ entity }>
											<th scope="row">
												{ label( entity ) }
											</th>
											{ REPAIR_BUCKETS.map(
												( bucket ) => (
													<td key={ bucket }>
														{
															repairView.counts[
																entity
															][ bucket ]
														}
													</td>
												)
											) }
										</tr>
									) ) }
								</tbody>
							</table>
							{ repairBucketTotal(
								repairView.counts,
								'unverified'
							) > 0 && (
								<p>
									{ __(
										'“Could not be confirmed” records were left unchanged: their prefecture, postal code or address no longer matches the platform, for example because it was edited by hand or changed on the platform.',
										'cart-bridge-jp'
									) }
								</p>
							) }
							{ repairBucketTotal(
								repairView.counts,
								'unavailable'
							) > 0 && (
								<p>
									{ __(
										'“Not found on the platform” records could not be checked because the platform returned no usable record for them (for example they were deleted there).',
										'cart-bridge-jp'
									) }
								</p>
							) }
							{ repairBucketTotal(
								repairView.counts,
								'skipped'
							) > 0 && (
								<p>
									{ __(
										'“Skipped” records were not changed: the WooCommerce record no longer exists, was not imported or updated by this plugin, is a staff account, is a trashed or draft order, or could not be saved. Run “Scan” again to see whether anything is left to repair.',
										'cart-bridge-jp'
									) }
								</p>
							) }
						</div>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
