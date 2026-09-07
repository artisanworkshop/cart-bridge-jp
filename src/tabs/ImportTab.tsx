import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import apiFetch from '../api';
import LimitsUpsellNotice from '../components/LimitsUpsellNotice';
import RunProgress from '../components/RunProgress';
import { isRunTerminal, useRunPolling } from '../hooks/useRunPolling';
import type {
	Capabilities,
	Connection,
	EntityType,
	Limits,
	RunType,
} from '../types';
import { ENTITY_ORDER } from '../types';

const ENTITY_LABELS: Record< EntityType, string > = {
	category: __( 'Categories', 'cart-bridge-jp' ),
	tag: __( 'Tags', 'cart-bridge-jp' ),
	product: __( 'Products', 'cart-bridge-jp' ),
	customer: __( 'Customers', 'cart-bridge-jp' ),
	order: __( 'Orders', 'cart-bridge-jp' ),
	stock: __( 'Stock', 'cart-bridge-jp' ),
	coupon: __( 'Coupons', 'cart-bridge-jp' ),
	review: __( 'Reviews', 'cart-bridge-jp' ),
};

function availableEntities( capabilities: Capabilities ): EntityType[] {
	return ENTITY_ORDER.filter( ( entity ) => {
		switch ( entity ) {
			case 'tag':
				return capabilities.has_tags;
			case 'coupon':
				return capabilities.has_coupons;
			case 'review':
				return capabilities.has_reviews;
			case 'customer':
				return capabilities.can_fetch_customers;
			default:
				return true;
		}
	} );
}

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

function runStorageKey( platform: string, type: RunType ): string {
	return `cbjp_run_${ type }_${ platform }`;
}

/**
 * 実行中のrunはAction Scheduler側で進むため、管理画面をリロードしても
 * 直前のrun_idからポーリングを再開できるようにlocalStorageへ控えておく
 * （このタブ内だけのUI都合の値で、サーバー側の正としては扱わない）。
 * @param platform
 * @param type
 */
function loadStoredRunId( platform: string, type: RunType ): string | null {
	try {
		return window.localStorage.getItem( runStorageKey( platform, type ) );
	} catch {
		return null;
	}
}

function storeRunId( platform: string, type: RunType, runId: string ): void {
	try {
		window.localStorage.setItem( runStorageKey( platform, type ), runId );
	} catch {
		// プライベートブラウジング等でlocalStorageが使えなくても実行自体は継続できる。
	}
}

function clearStoredRunId( platform: string, type: RunType ): void {
	try {
		window.localStorage.removeItem( runStorageKey( platform, type ) );
	} catch {
		// 何もしない（保存できていないなら消す必要もない）。
	}
}

interface RunSectionState {
	runId: string | null;
	starting: boolean;
	retryingJobId: number | null;
	cancelling: boolean;
	onlyWarnings: boolean;
}

function initialRunSectionState(): RunSectionState {
	return {
		runId: null,
		starting: false,
		retryingJobId: null,
		cancelling: false,
		onlyWarnings: false,
	};
}

export default function ImportTab() {
	const [ connections, setConnections ] = useState< Connection[] | null >(
		null
	);
	const [ connectionsError, setConnectionsError ] = useState< string | null >(
		null
	);
	const [ platform, setPlatform ] = useState< string | null >( null );
	const [ selectedEntities, setSelectedEntities ] = useState<
		Set< EntityType >
	>( new Set() );
	const [ startError, setStartError ] = useState< string | null >( null );
	const [ dryRunState, setDryRunState ] = useState< RunSectionState >(
		initialRunSectionState()
	);
	const [ importState, setImportState ] = useState< RunSectionState >(
		initialRunSectionState()
	);
	const [ dryRunTotals, setDryRunTotals ] = useState< Partial<
		Record< EntityType, number >
	> | null >( null );
	const [ limits, setLimits ] = useState< Limits | null >( null );
	const platformRef = useRef( platform );
	platformRef.current = platform;

	useEffect( () => {
		apiFetch< Connection[] >( { path: '/cbjp/v1/connections' } )
			.then( ( data ) => setConnections( data ) )
			.catch( ( err: unknown ) =>
				setConnectionsError( errorMessage( err ) )
			);
	}, [] );

	const connectedPlatforms = useMemo(
		() => ( connections ?? [] ).filter( ( c ) => c.connected ),
		[ connections ]
	);

	// 接続済みプラットフォームが確定したら、既定で先頭を選択し直前のrun_idを復元する。
	useEffect( () => {
		if ( null !== platform || 0 === connectedPlatforms.length ) {
			return;
		}

		setPlatform( connectedPlatforms[ 0 ].platform );
	}, [ connectedPlatforms, platform ] );

	const currentConnection = useMemo(
		() =>
			connectedPlatforms.find( ( c ) => c.platform === platform ) ?? null,
		[ connectedPlatforms, platform ]
	);

	// プラットフォームが変わったら、そのプラットフォームの既定エンティティ選択と
	// 直前のrun_id（あれば）を読み込む。
	useEffect( () => {
		if ( null === platform || null === currentConnection ) {
			return;
		}

		setSelectedEntities(
			new Set( availableEntities( currentConnection.capabilities ) )
		);
		setDryRunTotals( null );
		setLimits( null );
		setStartError( null );
		setDryRunState( {
			...initialRunSectionState(),
			runId: loadStoredRunId( platform, 'dry_run' ),
		} );
		setImportState( {
			...initialRunSectionState(),
			runId: loadStoredRunId( platform, 'import' ),
		} );
		// currentConnectionはplatformから導出される値なので、platform変更時のみ発火させる。
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ platform ] );

	const dryRunPolling = useRunPolling( dryRunState.runId );
	const importPolling = useRunPolling( importState.runId );

	const dryRunTerminal =
		null !== dryRunPolling.run && isRunTerminal( dryRunPolling.run );
	const importTerminal =
		null !== importPolling.run && isRunTerminal( importPolling.run );

	// dry-runが完了したら、Pro案内（D15/§10.3）で使う「総数」をキャッシュする。
	// サンプリングを行わない全量走査なので processed がそのまま総数になる。
	useEffect( () => {
		if ( ! dryRunPolling.run || ! dryRunTerminal ) {
			return;
		}

		const totals: Partial< Record< EntityType, number > > = {};

		for ( const job of dryRunPolling.run.jobs ) {
			if ( 'completed' === job.status ) {
				totals[ job.entity ] = job.totals.processed;
			}
		}

		// 置き換えではなくマージする: 対象エンティティを絞った再dry-runの後も、
		// 直前の広いdry-runで判明していた他エンティティの総数（Pro案内の分母）を保持するため。
		setDryRunTotals( ( prev ) => ( { ...prev, ...totals } ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ dryRunPolling.run?.run_id, dryRunTerminal ] );

	// 実移行が完了したら上限使用状況を取得し、Pro案内に使う。
	useEffect( () => {
		if ( ! importTerminal || null === platform ) {
			return;
		}

		// このリクエストを発行した時点のプラットフォームを閉じ込めておく。応答が
		// 届くまでの間にユーザーが別プラットフォームへ切り替えていた場合、そちらの
		// `limits`（platform-change時にnullへリセット済み）を古い応答で上書きしない。
		const requestedPlatform = platform;

		apiFetch< Limits >( {
			path: `/cbjp/v1/limits?platform=${ encodeURIComponent(
				platform
			) }`,
		} )
			.then( ( data ) => {
				if ( platformRef.current !== requestedPlatform ) {
					return;
				}

				setLimits( data );
			} )
			.catch( () => {
				// アップセル表示は付加情報のため、取得失敗時は黙って表示を省略する。
			} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ importPolling.run?.run_id, importTerminal, platform ] );

	const anyRunActive =
		( null !== dryRunState.runId && ! dryRunTerminal ) ||
		( null !== importState.runId && ! importTerminal );

	function toggleEntity( entity: EntityType, checked: boolean ) {
		setSelectedEntities( ( prev ) => {
			const next = new Set( prev );

			if ( checked ) {
				next.add( entity );
			} else {
				next.delete( entity );
			}

			return next;
		} );
	}

	async function startRun( type: RunType ) {
		if ( null === platform || 0 === selectedEntities.size ) {
			return;
		}

		if (
			'import' === type &&
			// Pro版で上限が解除されている場合、この実行はサンプルではなく全件書込みに
			// なる（`Sync\JobManager`のサンプリング分岐参照）。「サンプルのみ」と誤って
			// 断定すると、Proユーザーが小規模な操作だと誤解したまま全カタログ・全顧客・
			// 全受注の書込みを承認してしまいかねないため、無料版/Pro版どちらでも正しい
			// 表現にとどめる。
			// eslint-disable-next-line no-alert
			! window.confirm(
				__(
					'This will write real WooCommerce data (products, orders, customers, etc.) to this site, up to the current plan’s limits. Continue?',
					'cart-bridge-jp'
				)
			)
		) {
			return;
		}

		const setState = 'dry_run' === type ? setDryRunState : setImportState;
		// このリクエストを発行した時点のプラットフォームを閉じ込める。応答が届くまでの
		// 間にユーザーが別プラットフォームへ切り替えていた場合、共有state（dryRunState/
		// importState）は既に新プラットフォーム用にリセットされているため、そこへ
		// 旧プラットフォームのrun_idを紛れ込ませない（localStorageへは引き続き
		// 旧プラットフォームのキーで保存し、後で切り戻したときに発見できるようにする）。
		const requestedPlatform = platform;

		setStartError( null );
		setState( ( prev ) => ( { ...prev, starting: true } ) );

		try {
			const response = await apiFetch< { run_id: string } >( {
				path: '/cbjp/v1/runs',
				method: 'POST',
				data: {
					type,
					platform: requestedPlatform,
					entities: Array.from( selectedEntities ),
				},
			} );

			storeRunId( requestedPlatform, type, response.run_id );

			if ( platformRef.current !== requestedPlatform ) {
				return;
			}

			setState( ( prev ) => ( {
				...prev,
				runId: response.run_id,
				starting: false,
			} ) );
		} catch ( err ) {
			if ( platformRef.current !== requestedPlatform ) {
				return;
			}

			setStartError( errorMessage( err ) );
			setState( ( prev ) => ( { ...prev, starting: false } ) );
		}
	}

	async function retryJob( type: RunType, jobId: number ) {
		const setState = 'dry_run' === type ? setDryRunState : setImportState;
		const refetch =
			'dry_run' === type ? dryRunPolling.refetch : importPolling.refetch;

		setState( ( prev ) => ( { ...prev, retryingJobId: jobId } ) );

		try {
			await apiFetch( {
				path: `/cbjp/v1/jobs/${ jobId }/retry`,
				method: 'POST',
			} );
			refetch();
		} catch ( err ) {
			setStartError( errorMessage( err ) );
		} finally {
			setState( ( prev ) => ( { ...prev, retryingJobId: null } ) );
		}
	}

	async function cancelRun( type: RunType, runId: string ) {
		const setState = 'dry_run' === type ? setDryRunState : setImportState;
		const refetch =
			'dry_run' === type ? dryRunPolling.refetch : importPolling.refetch;

		setState( ( prev ) => ( { ...prev, cancelling: true } ) );

		try {
			await apiFetch( {
				path: `/cbjp/v1/runs/${ runId }/cancel`,
				method: 'POST',
			} );
			refetch();
		} catch ( err ) {
			setStartError( errorMessage( err ) );
		} finally {
			setState( ( prev ) => ( { ...prev, cancelling: false } ) );
		}
	}

	function clearRun( type: RunType ) {
		if ( null === platform ) {
			return;
		}

		clearStoredRunId( platform, type );
		( 'dry_run' === type ? setDryRunState : setImportState )(
			initialRunSectionState()
		);
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

	if ( 0 === connectedPlatforms.length ) {
		return (
			<p>
				{ __(
					'Connect a platform on the Connections tab before importing.',
					'cart-bridge-jp'
				) }
			</p>
		);
	}

	return (
		<div className="cbjp-import">
			<Card>
				<CardHeader>
					<strong>{ __( 'Import setup', 'cart-bridge-jp' ) }</strong>
				</CardHeader>
				<CardBody>
					{ connectedPlatforms.length > 1 && (
						<SelectControl
							label={ __( 'Platform', 'cart-bridge-jp' ) }
							value={ platform ?? '' }
							options={ connectedPlatforms.map( ( c ) => ( {
								label: c.label,
								value: c.platform,
							} ) ) }
							onChange={ setPlatform }
						/>
					) }

					<p>
						<strong>
							{ __( 'Entities to migrate', 'cart-bridge-jp' ) }
						</strong>
					</p>

					{ currentConnection &&
						availableEntities( currentConnection.capabilities ).map(
							( entity ) => (
								<CheckboxControl
									key={ entity }
									label={ ENTITY_LABELS[ entity ] }
									checked={ selectedEntities.has( entity ) }
									disabled={ anyRunActive }
									onChange={ ( checked ) =>
										toggleEntity( entity, checked )
									}
								/>
							)
						) }

					{ startError && (
						<Notice
							status="error"
							onRemove={ () => setStartError( null ) }
						>
							{ startError }
						</Notice>
					) }

					<div className="cbjp-import__actions">
						<Button
							variant="secondary"
							isBusy={ dryRunState.starting }
							disabled={
								anyRunActive ||
								dryRunState.starting ||
								importState.starting ||
								0 === selectedEntities.size
							}
							onClick={ () => startRun( 'dry_run' ) }
						>
							{ __(
								'Preview (dry run, no limit)',
								'cart-bridge-jp'
							) }
						</Button>{ ' ' }
						<Button
							variant="primary"
							isBusy={ importState.starting }
							disabled={
								anyRunActive ||
								dryRunState.starting ||
								importState.starting ||
								0 === selectedEntities.size
							}
							onClick={ () => startRun( 'import' ) }
						>
							{ __( 'Run import', 'cart-bridge-jp' ) }
						</Button>
					</div>
				</CardBody>
			</Card>

			{ dryRunState.runId && (
				<Card className="cbjp-import__run">
					<CardHeader>
						<strong>
							{ __( 'Preview results', 'cart-bridge-jp' ) }
						</strong>
						<Button
							variant="tertiary"
							disabled={
								! dryRunTerminal && ! dryRunPolling.notFound
							}
							onClick={ () => clearRun( 'dry_run' ) }
						>
							{ __( 'Clear', 'cart-bridge-jp' ) }
						</Button>
					</CardHeader>
					<CardBody>
						{ dryRunPolling.error && (
							<Notice status="error" isDismissible={ false }>
								{ dryRunPolling.error }
							</Notice>
						) }
						{ dryRunPolling.run && (
							<RunProgress
								run={ dryRunPolling.run }
								entityLabels={ ENTITY_LABELS }
								onRetry={ ( jobId ) =>
									retryJob( 'dry_run', jobId )
								}
								retryingJobId={ dryRunState.retryingJobId }
								onCancel={ () =>
									cancelRun(
										'dry_run',
										dryRunState.runId as string
									)
								}
								cancelling={ dryRunState.cancelling }
								isTerminal={ dryRunTerminal }
								reportsAvailable
								onlyWarnings={ dryRunState.onlyWarnings }
								onOnlyWarningsChange={ ( value ) =>
									setDryRunState( ( prev ) => ( {
										...prev,
										onlyWarnings: value,
									} ) )
								}
							/>
						) }
					</CardBody>
				</Card>
			) }

			{ importState.runId && (
				<Card className="cbjp-import__run">
					<CardHeader>
						<strong>
							{ __( 'Import results', 'cart-bridge-jp' ) }
						</strong>
						<Button
							variant="tertiary"
							disabled={
								! importTerminal && ! importPolling.notFound
							}
							onClick={ () => clearRun( 'import' ) }
						>
							{ __( 'Clear', 'cart-bridge-jp' ) }
						</Button>
					</CardHeader>
					<CardBody>
						{ importPolling.error && (
							<Notice status="error" isDismissible={ false }>
								{ importPolling.error }
							</Notice>
						) }
						{ importTerminal && limits && importPolling.run && (
							<LimitsUpsellNotice
								jobs={ importPolling.run.jobs }
								limits={ limits }
								entityLabels={ ENTITY_LABELS }
								dryRunTotals={ dryRunTotals }
							/>
						) }
						{ importPolling.run && (
							<RunProgress
								run={ importPolling.run }
								entityLabels={ ENTITY_LABELS }
								onRetry={ ( jobId ) =>
									retryJob( 'import', jobId )
								}
								retryingJobId={ importState.retryingJobId }
								onCancel={ () =>
									cancelRun(
										'import',
										importState.runId as string
									)
								}
								cancelling={ importState.cancelling }
								isTerminal={ importTerminal }
								reportsAvailable={ false }
								onlyWarnings={ importState.onlyWarnings }
								onOnlyWarningsChange={ ( value ) =>
									setImportState( ( prev ) => ( {
										...prev,
										onlyWarnings: value,
									} ) )
								}
							/>
						) }
					</CardBody>
				</Card>
			) }
		</div>
	);
}
