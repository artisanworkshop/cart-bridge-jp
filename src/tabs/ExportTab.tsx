import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
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
import { ENTITY_LABELS } from '../entity-labels';
import { isRunTerminal, useRunPolling } from '../hooks/useRunPolling';
import type {
	Capabilities,
	Connection,
	EntityType,
	Job,
	Limits,
	MappingCandidate,
	RunType,
	SettingsMappings,
	SettingsMappingValues,
} from '../types';

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

const UNMAPPED = '';

/**
 * `Sync\JobManager::EXPORT_ENTITIES_WITH_READER`（category/tag/reviewはexportエンティティ化しない。
 * categoryは`category_map`が担う。CLAUDE.md/`docs/03-design-decisions.md` §10.2「カテゴリ」）。
 */
const EXPORT_ENTITIES: EntityType[] = [
	'product',
	'customer',
	'order',
	'stock',
	'coupon',
];

/**
 * `Sync\JobManager::filter_and_order_export_entities()`と同じ条件をミラーする
 * （product/stockは常時対象、customer/order/couponはcapabilityでゲート）。
 * @param capabilities
 */
function availableExportEntities( capabilities: Capabilities ): EntityType[] {
	return EXPORT_ENTITIES.filter( ( entity ) => {
		switch ( entity ) {
			case 'customer':
				return capabilities.can_update_customer;
			case 'order':
				return capabilities.can_create_order;
			case 'coupon':
				return (
					capabilities.has_coupons && capabilities.can_create_coupon
				);
			default:
				return true;
		}
	} );
}

function exportRunStorageKey( platform: string, type: RunType ): string {
	return `cbjp_run_${ type }_${ platform }`;
}

/**
 * `ImportTab.tsx`の同名ヘルパーと同じ役割（実行中のrunはAction Scheduler側で進むため、
 * 管理画面をリロードしても直前のrun_idからポーリングを再開できるようにlocalStorageへ
 * 控えておく）。localStorageキーの命名規則を共有するため、Import側の`dry_run`/`import`と
 * 衝突しないよう`type`（`dry_run_export`/`export`）を含める。
 * @param platform
 * @param type
 */
function loadStoredExportRunId(
	platform: string,
	type: RunType
): string | null {
	try {
		return window.localStorage.getItem(
			exportRunStorageKey( platform, type )
		);
	} catch {
		return null;
	}
}

function storeExportRunId(
	platform: string,
	type: RunType,
	runId: string
): void {
	try {
		window.localStorage.setItem(
			exportRunStorageKey( platform, type ),
			runId
		);
	} catch {
		// プライベートブラウジング等でlocalStorageが使えなくても実行自体は継続できる。
	}
}

function clearStoredExportRunId( platform: string, type: RunType ): void {
	try {
		window.localStorage.removeItem( exportRunStorageKey( platform, type ) );
	} catch {
		// 何もしない（保存できていないなら消す必要もない）。
	}
}

interface ExportRunSectionState {
	runId: string | null;
	starting: boolean;
	retryingJobId: number | null;
	cancelling: boolean;
	onlyWarnings: boolean;
}

function initialExportRunSectionState(): ExportRunSectionState {
	return {
		runId: null,
		starting: false,
		retryingJobId: null,
		cancelling: false,
		onlyWarnings: false,
	};
}

/**
 * `docs/review-backlog.md`の`e2-2-exporter-core/R1-M8`: 実行(非dry-run)のexportで対象アイテムが
 * 全てskipped/warnedになっても（例: 未マッピングの決済方法・必須項目欠落等）ジョブは
 * `STATUS_COMPLETED`のまま終わり、個別警告はどこにも永続化されない（`Sync\Importer`と同じ
 * 既存方針）。実際に何も書き込まれなかったことに店舗オーナーが気付けるよう、
 * `created+updated===0 && warned>0`のcompletedジョブをUI側で検出してバナー表示する。
 * @param jobs
 */
function zeroWrittenWarnedEntities( jobs: Job[] ): EntityType[] {
	return jobs
		.filter(
			( job ) =>
				'completed' === job.status &&
				0 === job.totals.created + job.totals.updated &&
				job.totals.warned > 0
		)
		.map( ( job ) => job.entity );
}

type MapKey = 'category_map' | 'payment_map' | 'shipping_map' | 'status_map';

type EditableMappings = Record< MapKey, Record< string, string > >;

function toEditable( data: SettingsMappingValues ): EditableMappings {
	return {
		category_map: { ...data.category_map },
		payment_map: { ...data.payment_map },
		shipping_map: { ...data.shipping_map },
		status_map: { ...data.status_map },
	};
}

interface MappingSectionProps {
	title: string;
	help?: string;
	sourceCandidates: MappingCandidate[];
	targetCandidates: MappingCandidate[];
	unmappedLabel: string;
	map: Record< string, string >;
	onChange: ( sourceId: string, targetId: string ) => void;
	disabled: boolean;
}

/**
 * カテゴリ/決済/配送/注文ステータスの4テーブルはいずれも「片側の候補一覧を1行ずつ列挙し、
 * もう片側から選ばせる」という同じ形なので1つのコンポーネントに集約する。向き（Woo→ASPか
 * ASP→Wooか）はどの候補一覧を`sourceCandidates`/`targetCandidates`に渡すかで表現する。
 * @param root0
 * @param root0.title
 * @param root0.help
 * @param root0.sourceCandidates
 * @param root0.targetCandidates
 * @param root0.unmappedLabel
 * @param root0.map
 * @param root0.onChange
 * @param root0.disabled
 */
function MappingSection( {
	title,
	help,
	sourceCandidates,
	targetCandidates,
	unmappedLabel,
	map,
	onChange,
	disabled,
}: MappingSectionProps ) {
	return (
		<Card className="cbjp-export__mapping-card">
			<CardHeader>
				<strong>{ title }</strong>
			</CardHeader>
			<CardBody>
				{ help && <p>{ help }</p> }
				{ 0 === sourceCandidates.length ? (
					<p>
						{ __(
							'No options available yet. Check the connection and try again.',
							'cart-bridge-jp'
						) }
					</p>
				) : (
					<div className="cbjp-export__mapping-scroll">
						<table className="cbjp-export__mapping-table">
							<tbody>
								{ sourceCandidates.map( ( source ) => {
									const currentValue =
										map[ source.id ] ?? UNMAPPED;
									// 保存済みの値が現在の候補一覧に無い場合（決済ゲートウェイの
									// 無効化、配送ゾーンインスタンスの削除、ASP側メソッドの廃止等）、
									// ネイティブ<select>はどのoptionにも一致せず先頭
									// （「未マッピング」）を表示してしまい、実際の保存値と表示が
									// 食い違ったまま同じ選択肢を選び直しても変更なしと判定されて
									// 解除できなくなる。現在値を一時的な選択肢として差し込み、
									// 表示と選択解除の両方を可能にする。
									const currentValueKnown =
										UNMAPPED === currentValue ||
										targetCandidates.some(
											( target ) =>
												target.id === currentValue
										);

									return (
										<tr key={ source.id }>
											<td>{ source.name }</td>
											<td>
												<SelectControl
													value={ currentValue }
													disabled={ disabled }
													options={ [
														{
															label: unmappedLabel,
															value: UNMAPPED,
														},
														...( currentValueKnown
															? []
															: [
																	{
																		label: sprintf(
																			/* translators: %s: a mapping id that no longer exists among the current options */
																			__(
																				'%s (no longer available)',
																				'cart-bridge-jp'
																			),
																			currentValue
																		),
																		value: currentValue,
																	},
															  ] ),
														...targetCandidates.map(
															( target ) => ( {
																label: target.name,
																value: target.id,
															} )
														),
													] }
													onChange={ ( value ) =>
														onChange(
															source.id,
															value
														)
													}
												/>
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
				) }
			</CardBody>
		</Card>
	);
}

export default function ExportTab() {
	const [ connections, setConnections ] = useState< Connection[] | null >(
		null
	);
	const [ connectionsError, setConnectionsError ] = useState< string | null >(
		null
	);
	const [ platform, setPlatform ] = useState< string | null >( null );
	const [ mappings, setMappings ] = useState< SettingsMappings | null >(
		null
	);
	const [ mappingsError, setMappingsError ] = useState< string | null >(
		null
	);
	const [ edited, setEdited ] = useState< EditableMappings | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState< string | null >( null );
	const [ saved, setSaved ] = useState( false );
	// プラットフォーム名だけでは同じプラットフォームへ短時間で戻った場合
	// （A→B→A）を区別できない。Aの1回目のリクエスト（マッピング取得effectのGET、
	// または保存中のPUT）がサーバー側の遅い候補取得（ColorMeへの追加APIコール）で
	// 2回目のGETより遅れて解決すると、プラットフォーム名の一致チェックだけでは
	// 「新しい応答」と誤認して新しい方や保存後の状態を上書きしてしまう。
	// マッピング取得effectと`save()`の両方が同じ世代カウンタを参照し、
	// 「このリクエストが発行された時点のプラットフォーム選択がまだ現在のものか」を判定する。
	const platformGenerationRef = useRef( 0 );

	const [ selectedExportEntities, setSelectedExportEntities ] = useState<
		Set< EntityType >
	>( new Set() );
	const [ acknowledgeProductionWrite, setAcknowledgeProductionWrite ] =
		useState( false );
	const [ runStartError, setRunStartError ] = useState< string | null >(
		null
	);
	const [ dryRunExportState, setDryRunExportState ] =
		useState< ExportRunSectionState >( initialExportRunSectionState() );
	const [ exportState, setExportState ] = useState< ExportRunSectionState >(
		initialExportRunSectionState()
	);
	const [ dryRunTotals, setDryRunTotals ] = useState< Partial<
		Record< EntityType, number >
	> | null >( null );
	const [ limits, setLimits ] = useState< Limits | null >( null );
	// `startRun()`/`limits`取得effectが応答を受け取った時点でまだ同じプラットフォーム選択かを
	// 判定するために、マッピング取得effectと同じ`platformGenerationRef`を共有する（frontend.md:
	// 「同じ状態を更新しうる複数の非同期処理は同じ世代カウンタを共有する必要がある」の対象を
	// マッピングのGET/PUTに加えて実行フローのPOST/GETにも広げたもの）。
	const dryRunExportRetryConfirmPendingRef = useRef( false );
	const exportRetryConfirmPendingRef = useRef( false );

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

	useEffect( () => {
		if ( null === platform ) {
			return;
		}

		const requestId = ++platformGenerationRef.current;

		setMappings( null );
		setEdited( null );
		setMappingsError( null );
		setSaveError( null );
		setSaved( false );

		apiFetch< SettingsMappings >( {
			path: `/cbjp/v1/settings/mappings/${ encodeURIComponent(
				platform
			) }`,
		} )
			.then( ( data ) => {
				if ( platformGenerationRef.current !== requestId ) {
					return;
				}

				setMappings( data );
				setEdited( toEditable( data ) );
			} )
			.catch( ( err: unknown ) => {
				if ( platformGenerationRef.current !== requestId ) {
					return;
				}

				setMappingsError( errorMessage( err ) );
			} );
	}, [ platform ] );

	// プラットフォームが変わったら、実行フロー側の状態（エンティティ選択・直前のrun_id・
	// 警告チェックボックス等）も読み込み直す。マッピング取得effectとは独立した状態を扱うため
	// 別effectにするが、判定に使う`platformGenerationRef`は共有する。
	useEffect( () => {
		if ( null === platform || null === currentConnection ) {
			return;
		}

		setSelectedExportEntities(
			new Set( availableExportEntities( currentConnection.capabilities ) )
		);
		setAcknowledgeProductionWrite( false );
		setRunStartError( null );
		setDryRunTotals( null );
		setLimits( null );
		setDryRunExportState( {
			...initialExportRunSectionState(),
			runId: loadStoredExportRunId( platform, 'dry_run_export' ),
		} );
		setExportState( {
			...initialExportRunSectionState(),
			runId: loadStoredExportRunId( platform, 'export' ),
		} );
		// currentConnectionはplatformから導出される値なので、platform変更時のみ発火させる。
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ platform ] );

	const dryRunExportPolling = useRunPolling( dryRunExportState.runId );
	const exportPolling = useRunPolling( exportState.runId );

	const dryRunExportTerminal =
		null !== dryRunExportPolling.run &&
		isRunTerminal( dryRunExportPolling.run );
	const exportTerminal =
		null !== exportPolling.run && isRunTerminal( exportPolling.run );

	// dry-runが完了したら、Pro案内（D15/§10.3）で使う「総数」をキャッシュする
	// （`ImportTab.tsx`と同じロジック。サンプリングを行わない全量走査なのでprocessedが
	// そのまま総数になる）。
	useEffect( () => {
		if ( ! dryRunExportPolling.run || ! dryRunExportTerminal ) {
			return;
		}

		const totals: Partial< Record< EntityType, number > > = {};

		for ( const job of dryRunExportPolling.run.jobs ) {
			if ( 'completed' === job.status ) {
				totals[ job.entity ] = job.totals.processed;
			}
		}

		setDryRunTotals( ( prev ) => ( { ...prev, ...totals } ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ dryRunExportPolling.run, dryRunExportTerminal ] );

	// 実エクスポートが完了したら上限使用状況を取得し、Pro案内に使う（`ImportTab.tsx`と同じ）。
	useEffect( () => {
		if ( ! exportTerminal || null === platform ) {
			return;
		}

		const requestedPlatform = platform;
		const requestId = platformGenerationRef.current;

		apiFetch< Limits >( {
			path: `/cbjp/v1/limits?platform=${ encodeURIComponent(
				requestedPlatform
			) }`,
		} )
			.then( ( data ) => {
				if ( platformGenerationRef.current !== requestId ) {
					return;
				}

				setLimits( data );
			} )
			.catch( () => {
				if ( platformGenerationRef.current !== requestId ) {
					return;
				}

				setLimits( null );
			} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ exportPolling.run, exportTerminal, platform ] );

	useEffect( () => {
		if ( ! dryRunExportRetryConfirmPendingRef.current ) {
			return;
		}

		dryRunExportRetryConfirmPendingRef.current = false;
		setDryRunExportState( ( prev ) => ( {
			...prev,
			retryingJobId: null,
		} ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ dryRunExportPolling.run ] );

	useEffect( () => {
		if ( ! exportRetryConfirmPendingRef.current ) {
			return;
		}

		exportRetryConfirmPendingRef.current = false;
		setExportState( ( prev ) => ( { ...prev, retryingJobId: null } ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ exportPolling.run ] );

	const dryRunExportActive =
		null !== dryRunExportState.runId && ! dryRunExportTerminal;
	const exportActive = null !== exportState.runId && ! exportTerminal;
	const dryRunExportBusy =
		dryRunExportActive ||
		dryRunExportState.starting ||
		null !== dryRunExportState.retryingJobId;
	const exportBusy =
		exportActive ||
		exportState.starting ||
		null !== exportState.retryingJobId;

	function toggleExportEntity( entity: EntityType, checked: boolean ) {
		setSelectedExportEntities( ( prev ) => {
			const next = new Set( prev );

			if ( checked ) {
				next.add( entity );
			} else {
				next.delete( entity );
			}

			return next;
		} );
	}

	async function startExportRun( type: 'dry_run_export' | 'export' ) {
		if ( null === platform || 0 === selectedExportEntities.size ) {
			return;
		}

		if ( 'export' === type && ! acknowledgeProductionWrite ) {
			return;
		}

		const setState =
			'dry_run_export' === type ? setDryRunExportState : setExportState;
		const requestedPlatform = platform;
		const requestId = platformGenerationRef.current;

		setRunStartError( null );
		setState( ( prev ) => ( { ...prev, starting: true } ) );

		try {
			const response = await apiFetch< { run_id: string } >( {
				path: '/cbjp/v1/runs',
				method: 'POST',
				data: {
					type,
					platform: requestedPlatform,
					entities: Array.from( selectedExportEntities ),
					...( 'export' === type
						? { acknowledge_production_write: true }
						: {} ),
				},
			} );

			storeExportRunId( requestedPlatform, type, response.run_id );

			if ( platformGenerationRef.current !== requestId ) {
				return;
			}

			if ( 'export' === type ) {
				setLimits( null );
			}

			setState( ( prev ) => ( {
				...prev,
				runId: response.run_id,
				starting: false,
			} ) );
		} catch ( err ) {
			if ( platformGenerationRef.current !== requestId ) {
				return;
			}

			setRunStartError( errorMessage( err ) );
			setState( ( prev ) => ( { ...prev, starting: false } ) );
		}
	}

	async function retryExportJob(
		type: 'dry_run_export' | 'export',
		jobId: number
	) {
		const setState =
			'dry_run_export' === type ? setDryRunExportState : setExportState;
		const refetch =
			'dry_run_export' === type
				? dryRunExportPolling.refetch
				: exportPolling.refetch;
		const confirmPendingRef =
			'dry_run_export' === type
				? dryRunExportRetryConfirmPendingRef
				: exportRetryConfirmPendingRef;

		setState( ( prev ) => ( { ...prev, retryingJobId: jobId } ) );

		try {
			await apiFetch( {
				path: `/cbjp/v1/jobs/${ jobId }/retry`,
				method: 'POST',
			} );
			confirmPendingRef.current = true;
			refetch();
		} catch ( err ) {
			setRunStartError( errorMessage( err ) );
			setState( ( prev ) => ( { ...prev, retryingJobId: null } ) );
		}
	}

	async function cancelExportRun(
		type: 'dry_run_export' | 'export',
		runId: string
	) {
		const setState =
			'dry_run_export' === type ? setDryRunExportState : setExportState;
		const refetch =
			'dry_run_export' === type
				? dryRunExportPolling.refetch
				: exportPolling.refetch;

		setState( ( prev ) => ( { ...prev, cancelling: true } ) );

		try {
			await apiFetch( {
				path: `/cbjp/v1/runs/${ runId }/cancel`,
				method: 'POST',
			} );
			refetch();
		} catch ( err ) {
			setRunStartError( errorMessage( err ) );
		} finally {
			setState( ( prev ) => ( { ...prev, cancelling: false } ) );
		}
	}

	function clearExportRun( type: 'dry_run_export' | 'export' ) {
		if ( null === platform ) {
			return;
		}

		clearStoredExportRunId( platform, type );
		( 'dry_run_export' === type ? setDryRunExportState : setExportState )(
			initialExportRunSectionState()
		);
	}

	function updateMap( key: MapKey, sourceId: string, targetId: string ) {
		setEdited( ( current ) => {
			if ( null === current ) {
				return current;
			}

			const next = { ...current[ key ] };

			if ( UNMAPPED === targetId ) {
				delete next[ sourceId ];
			} else {
				next[ sourceId ] = targetId;
			}

			return { ...current, [ key ]: next };
		} );
		setSaved( false );
	}

	async function save() {
		if ( null === platform || null === edited ) {
			return;
		}

		// このリクエストを発行した時点のプラットフォーム世代を閉じ込める。応答が届くまでの間に
		// ユーザーが別プラットフォームへ切り替えていた場合（さらにA→B→Aのように戻った場合も
		// 世代カウンタにより区別できる）、そちらの`mappings`/`edited`（platform-change時に
		// 読み込み直し済み）をこの古い応答で上書きしない（マッピング取得effectと同じ
		// `platformGenerationRef`を使うことで、GETとPUTの両方の応答を一貫して判定する）。
		const requestId = platformGenerationRef.current;

		setSaving( true );
		setSaveError( null );

		try {
			// PUTは候補一覧（asp_candidates/woo_candidates）を返さない（`SettingsMappingValues`参照）。
			// 保存操作そのものでは候補は変化しないため、直前のGETで取得した`mappings`の候補部分は
			// そのまま保持し、保存済みマップ本体だけを差し替える。
			const data = await apiFetch< SettingsMappingValues >( {
				path: `/cbjp/v1/settings/mappings/${ encodeURIComponent(
					platform
				) }`,
				method: 'PUT',
				data: edited,
			} );

			if ( platformGenerationRef.current !== requestId ) {
				return;
			}

			setMappings( ( current ) =>
				null === current ? current : { ...current, ...data }
			);
			setEdited( toEditable( data ) );
			setSaved( true );
		} catch ( err ) {
			if ( platformGenerationRef.current !== requestId ) {
				return;
			}

			setSaveError( errorMessage( err ) );
		} finally {
			setSaving( false );
		}
	}

	const zeroWrittenExportEntities = exportPolling.run
		? zeroWrittenWarnedEntities( exportPolling.run.jobs )
		: [];

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
					'Connect a platform on the Connections tab before setting up export mappings.',
					'cart-bridge-jp'
				) }
			</p>
		);
	}

	return (
		<div className="cbjp-export">
			<Card>
				<CardHeader>
					<strong>
						{ __( 'Mapping settings', 'cart-bridge-jp' ) }
					</strong>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'Category mapping controls which platform category each WooCommerce category exports to. Payment method, shipping method, and order status mappings are shared with importing: they normalize platform values into WooCommerce when importing, and the same mapping is used, where possible, when exporting orders back to the platform. Unmapped items are skipped or reported as warnings.',
							'cart-bridge-jp'
						) }
					</p>
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
				</CardBody>
			</Card>

			{ mappingsError && (
				// `mappings`/`edited`がnullのまま（取得失敗）のときだけ表示されるため、
				// 破棄可能にすると空のSpinnerだけが残る「詰み」状態になる（`platform`が
				// 変わらない限り再取得のeffectが発火しないため）。`connectionsError`と同じ理由で
				// 破棄不可にする（G1指摘）。
				<Notice status="error" isDismissible={ false }>
					{ mappingsError }
				</Notice>
			) }

			{ ( null === mappings || null === edited ) && ! mappingsError ? (
				<Spinner />
			) : null }

			{ mappings && edited && (
				<>
					{ false ===
						currentConnection?.capabilities.can_create_category && (
						<MappingSection
							title={ __( 'Category mapping', 'cart-bridge-jp' ) }
							help={ __(
								'This platform cannot create new categories, so pick an existing platform category for each WooCommerce category you plan to export.',
								'cart-bridge-jp'
							) }
							sourceCandidates={
								mappings.woo_candidates.category
							}
							targetCandidates={
								mappings.asp_candidates.category
							}
							unmappedLabel={ __(
								'— No category —',
								'cart-bridge-jp'
							) }
							map={ edited.category_map }
							onChange={ ( sourceId, targetId ) =>
								updateMap( 'category_map', sourceId, targetId )
							}
							disabled={ saving }
						/>
					) }

					<MappingSection
						title={ __(
							'Payment method mapping',
							'cart-bridge-jp'
						) }
						help={ __(
							'Shared with importing: maps each platform payment method to a WooCommerce gateway.',
							'cart-bridge-jp'
						) }
						sourceCandidates={ mappings.asp_candidates.payment }
						targetCandidates={ mappings.woo_candidates.payment }
						unmappedLabel={ __( '— Unmapped —', 'cart-bridge-jp' ) }
						map={ edited.payment_map }
						onChange={ ( sourceId, targetId ) =>
							updateMap( 'payment_map', sourceId, targetId )
						}
						disabled={ saving }
					/>

					<MappingSection
						title={ __(
							'Shipping method mapping',
							'cart-bridge-jp'
						) }
						help={ __(
							'Shared with importing: maps each platform shipping method to a WooCommerce shipping method.',
							'cart-bridge-jp'
						) }
						sourceCandidates={ mappings.asp_candidates.shipping }
						targetCandidates={ mappings.woo_candidates.shipping }
						unmappedLabel={ __( '— Unmapped —', 'cart-bridge-jp' ) }
						map={ edited.shipping_map }
						onChange={ ( sourceId, targetId ) =>
							updateMap( 'shipping_map', sourceId, targetId )
						}
						disabled={ saving }
					/>

					<MappingSection
						title={ __( 'Order status mapping', 'cart-bridge-jp' ) }
						help={ __(
							'Shared with importing: overrides the WooCommerce status an imported order gets for each platform status.',
							'cart-bridge-jp'
						) }
						sourceCandidates={ mappings.asp_candidates.status }
						targetCandidates={ mappings.woo_candidates.status }
						unmappedLabel={ __( '— Default —', 'cart-bridge-jp' ) }
						map={ edited.status_map }
						onChange={ ( sourceId, targetId ) =>
							updateMap( 'status_map', sourceId, targetId )
						}
						disabled={ saving }
					/>

					{ saveError && (
						<Notice
							status="error"
							onRemove={ () => setSaveError( null ) }
						>
							{ saveError }
						</Notice>
					) }
					{ saved && (
						<Notice
							status="success"
							onRemove={ () => setSaved( false ) }
						>
							{ __(
								'Mapping settings saved.',
								'cart-bridge-jp'
							) }
						</Notice>
					) }

					<div className="cbjp-export__actions">
						<Button
							variant="primary"
							isBusy={ saving }
							disabled={ saving }
							onClick={ save }
						>
							{ __( 'Save mappings', 'cart-bridge-jp' ) }
						</Button>
					</div>
				</>
			) }

			<Card>
				<CardHeader>
					<strong>{ __( 'Export setup', 'cart-bridge-jp' ) }</strong>
				</CardHeader>
				<CardBody>
					<p>
						<strong>
							{ __( 'Entities to export', 'cart-bridge-jp' ) }
						</strong>
					</p>

					<div className="cbjp-export__entities">
						{ currentConnection &&
							availableExportEntities(
								currentConnection.capabilities
							).map( ( entity ) => (
								<CheckboxControl
									key={ entity }
									label={ ENTITY_LABELS[ entity ] }
									checked={ selectedExportEntities.has(
										entity
									) }
									disabled={ dryRunExportBusy || exportBusy }
									onChange={ ( checked ) =>
										toggleExportEntity( entity, checked )
									}
								/>
							) ) }
					</div>

					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Running an export writes data to the connected shop right away, up to the current plan’s limits. We recommend running this against a test shop first, not your live shop.',
							'cart-bridge-jp'
						) }
					</Notice>

					<CheckboxControl
						label={ __(
							'I understand this writes live data to the connected shop, and I’m ready to run it (ideally on a test shop).',
							'cart-bridge-jp'
						) }
						checked={ acknowledgeProductionWrite }
						disabled={ exportBusy }
						onChange={ setAcknowledgeProductionWrite }
					/>

					{ runStartError && (
						<Notice
							status="error"
							onRemove={ () => setRunStartError( null ) }
						>
							{ runStartError }
						</Notice>
					) }

					<div className="cbjp-export__actions">
						<Button
							variant="secondary"
							isBusy={ dryRunExportState.starting }
							disabled={
								dryRunExportBusy ||
								exportBusy ||
								0 === selectedExportEntities.size
							}
							onClick={ () => startExportRun( 'dry_run_export' ) }
						>
							{ __(
								'Preview export (dry run, no writes)',
								'cart-bridge-jp'
							) }
						</Button>{ ' ' }
						<Button
							variant="primary"
							isBusy={ exportState.starting }
							disabled={
								dryRunExportBusy ||
								exportBusy ||
								0 === selectedExportEntities.size ||
								! acknowledgeProductionWrite
							}
							onClick={ () => startExportRun( 'export' ) }
						>
							{ __( 'Run export', 'cart-bridge-jp' ) }
						</Button>
					</div>
				</CardBody>
			</Card>

			{ dryRunExportState.runId && (
				<Card className="cbjp-export__run">
					<CardHeader>
						<strong>
							{ __( 'Preview export results', 'cart-bridge-jp' ) }
						</strong>
						<Button
							variant="tertiary"
							disabled={
								null !== dryRunExportState.retryingJobId ||
								( ! dryRunExportTerminal &&
									! dryRunExportPolling.notFound )
							}
							onClick={ () => clearExportRun( 'dry_run_export' ) }
						>
							{ __( 'Clear', 'cart-bridge-jp' ) }
						</Button>
					</CardHeader>
					<CardBody>
						{ dryRunExportPolling.error && (
							<Notice status="error" isDismissible={ false }>
								{ dryRunExportPolling.error }
							</Notice>
						) }
						{ dryRunExportPolling.run && (
							<RunProgress
								run={ dryRunExportPolling.run }
								entityLabels={ ENTITY_LABELS }
								onRetry={ ( jobId ) =>
									retryExportJob( 'dry_run_export', jobId )
								}
								retryingJobId={
									dryRunExportState.retryingJobId
								}
								onCancel={ () =>
									cancelExportRun(
										'dry_run_export',
										dryRunExportState.runId as string
									)
								}
								cancelling={ dryRunExportState.cancelling }
								retryDisabled={ exportBusy }
								isTerminal={ dryRunExportTerminal }
								reportsAvailable
								onlyWarnings={ dryRunExportState.onlyWarnings }
								onOnlyWarningsChange={ ( value ) =>
									setDryRunExportState( ( prev ) => ( {
										...prev,
										onlyWarnings: value,
									} ) )
								}
							/>
						) }
					</CardBody>
				</Card>
			) }

			{ exportState.runId && (
				<Card className="cbjp-export__run">
					<CardHeader>
						<strong>
							{ __( 'Export results', 'cart-bridge-jp' ) }
						</strong>
						<Button
							variant="tertiary"
							disabled={
								null !== exportState.retryingJobId ||
								( ! exportTerminal && ! exportPolling.notFound )
							}
							onClick={ () => clearExportRun( 'export' ) }
						>
							{ __( 'Clear', 'cart-bridge-jp' ) }
						</Button>
					</CardHeader>
					<CardBody>
						{ exportPolling.error && (
							<Notice status="error" isDismissible={ false }>
								{ exportPolling.error }
							</Notice>
						) }
						{ exportTerminal &&
							zeroWrittenExportEntities.length > 0 && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ sprintf(
										/* translators: %s: comma-separated list of entity labels */
										__(
											'Nothing was written for: %s. All items were skipped or produced warnings — check the dry-run report or the Logs tab for why.',
											'cart-bridge-jp'
										),
										zeroWrittenExportEntities
											.map(
												( entity ) =>
													ENTITY_LABELS[ entity ]
											)
											.join( ', ' )
									) }
								</Notice>
							) }
						{ exportTerminal && limits && exportPolling.run && (
							<LimitsUpsellNotice
								jobs={ exportPolling.run.jobs }
								limits={ limits }
								entityLabels={ ENTITY_LABELS }
								dryRunTotals={ dryRunTotals }
							/>
						) }
						{ exportPolling.run && (
							<RunProgress
								run={ exportPolling.run }
								entityLabels={ ENTITY_LABELS }
								onRetry={ ( jobId ) =>
									retryExportJob( 'export', jobId )
								}
								retryingJobId={ exportState.retryingJobId }
								onCancel={ () =>
									cancelExportRun(
										'export',
										exportState.runId as string
									)
								}
								cancelling={ exportState.cancelling }
								retryDisabled={ dryRunExportBusy }
								isTerminal={ exportTerminal }
								reportsAvailable={ false }
								onlyWarnings={ exportState.onlyWarnings }
								onOnlyWarningsChange={ ( value ) =>
									setExportState( ( prev ) => ( {
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
