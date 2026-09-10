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

	// 実行中にプラットフォームを切り替えた場合、遅れて届いた応答で別プラットフォームの
	// 表示を上書きしないための参照（ImportTab と同じ手法）。
	const platformRef = useRef( platform );
	platformRef.current = platform;

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
		setPlatform( value );
		setPreview( null );
		setCleanupProgress( null );
		setCleanupDone( false );
		setCleanupError( null );
		setRebuildCounts( null );
		setRebuildDone( false );
		setRebuildError( null );
	}

	const currentConnection =
		connections?.find( ( c ) => c.platform === platform ) ?? null;
	const platformLabel = currentConnection?.label ?? platform ?? '';
	const busy = previewing || cleaning || rebuilding;

	async function loadPreview() {
		if ( null === platform ) {
			return;
		}

		const requested = platform;
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

			if ( platformRef.current === requested ) {
				setPreview( data );
			}
		} catch ( err ) {
			if ( platformRef.current === requested ) {
				setCleanupError( errorMessage( err ) );
			}
		} finally {
			if ( platformRef.current === requested ) {
				setPreviewing( false );
			}
		}
	}

	async function runCleanup() {
		if ( null === platform || null === preview ) {
			return;
		}

		const confirmed =
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

				if ( platformRef.current !== requested ) {
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
			if ( platformRef.current === requested ) {
				setCleanupError( errorMessage( err ) );
			}
		} finally {
			if ( platformRef.current === requested ) {
				setCleaning( false );
			}
		}
	}

	async function runRebuild() {
		if ( null === platform ) {
			return;
		}

		const requested = platform;
		let counts: Counts = {};
		let cursor: string | null = null;

		setRebuilding( true );
		setRebuildError( null );
		setRebuildDone( false );
		setRebuildCounts( counts );

		try {
			for ( let batch = 0; batch < MAX_BATCHES; batch++ ) {
				const result: RebuildResult = await apiFetch< RebuildResult >( {
					path: '/cbjp/v1/tools/rebuild-mappings',
					method: 'POST',
					data: { platform: requested, cursor },
				} );

				if ( platformRef.current !== requested ) {
					return;
				}

				counts = mergeCounts( counts, result.counts );
				setRebuildCounts( counts );
				cursor = result.cursor;

				if ( null === cursor ) {
					setRebuildDone( true );

					return;
				}
			}

			setRebuildError(
				__(
					'The rebuild did not finish within the expected number of batches. Run it again to continue.',
					'cart-bridge-jp'
				)
			);
		} catch ( err ) {
			if ( platformRef.current === requested ) {
				setRebuildError( errorMessage( err ) );
			}
		} finally {
			if ( platformRef.current === requested ) {
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
		? sumCounts( preview.counts ) + preview.attachments
		: 0;

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
							{ 0 === previewTotal ? (
								<p>
									{ __(
										'Nothing to delete for this platform.',
										'cart-bridge-jp'
									) }
								</p>
							) : (
								<>
									<p>
										<strong>
											{ __(
												'The following linked records will be removed:',
												'cart-bridge-jp'
											) }
										</strong>
									</p>
									<CountList
										counts={ {
											...preview.counts,
											attachment: preview.attachments,
										} }
										keys={ CLEANUP_KEYS }
									/>
									{ preview.customers.delete +
										preview.customers.unlink >
										0 && (
										<p>
											{ sprintf(
												/* translators: 1: accounts to delete, 2: accounts to unlink */
												__(
													'Customers: %1$d accounts created by the import will be deleted, %2$d existing accounts will only be unlinked.',
													'cart-bridge-jp'
												),
												preview.customers.delete,
												preview.customers.unlink
											) }
										</p>
									) }
									<div className="cbjp-tools__actions">
										<Button
											variant="primary"
											isDestructive
											isBusy={ cleaning }
											disabled={
												busy || preview.run_in_progress
											}
											onClick={ () => void runCleanup() }
										>
											{ __(
												'Delete sample data',
												'cart-bridge-jp'
											) }
										</Button>
									</div>
								</>
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
		</div>
	);
}
