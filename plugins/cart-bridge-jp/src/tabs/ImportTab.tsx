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
import { activeRunsSeed, runsToAnnounce, untrackedRuns } from '../active-runs';
import ActiveRunNotice from '../components/ActiveRunNotice';
import OrderMappingNotice from '../components/OrderMappingNotice';
import RunProgress from '../components/RunProgress';
import VerificationReport from '../components/VerificationReport';
import { ENTITY_LABELS } from '../entity-labels';
import { parseHash } from '../hash-route';
import { useActiveRuns } from '../hooks/useActiveRuns';
import { useRunAdoption } from '../hooks/useRunAdoption';
import { isRunTerminal, useRunPolling } from '../hooks/useRunPolling';
import { clearStoredRunId, loadStoredRunId, storeRunId } from '../run-storage';
import type {
	ActiveRun,
	Capabilities,
	Connection,
	EntityType,
	RunType,
} from '../types';
import { ENTITY_ORDER } from '../types';

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
	const platformRef = useRef( platform );
	platformRef.current = platform;
	// リトライ後、次に成功したポーリング応答が届くまで`retryingJobId`を解除しない
	// ためのラッチ。`useRunPolling`は失敗時も内部で自動的に再試行し続けるため、
	// 「リトライ後の確認フェッチ」が一時的な通信エラーで一旦失敗しても
	// （そのエラーはpoll()内部でcatchされ`refetch()`自体は成功扱いで解決する）、
	// ここでは古い（リトライ前の）terminalスナップショットのまま「解除できた」と
	// 誤認しない。`run`はpoll成功時にのみ新しい参照になるため、これを監視すれば
	// 「本当に新しいスナップショットが届いたか」を確実に検出できる。
	const dryRunRetryConfirmPendingRef = useRef( false );
	const importRetryConfirmPendingRef = useRef( false );

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

		// 他のタブの案内（`ActiveRunNotice`）から来たときは、そちらで選んでいたプラットフォームを開く
		// （`#/import?platform=`）。ハッシュは任意の文字列を含みうるので、接続済みのものに一致するときだけ使う。
		const requested = parseHash( window.location.hash ).platform;

		setPlatform(
			connectedPlatforms.find( ( c ) => c.platform === requested )
				?.platform ?? connectedPlatforms[ 0 ].platform
		);
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

	// リトライ後、最初に届いた「新しい」（＝ポーリング成功による）スナップショットで
	// `retryingJobId`ラッチを解除する。`retryJob()`参照。
	useEffect( () => {
		if ( ! dryRunRetryConfirmPendingRef.current ) {
			return;
		}

		dryRunRetryConfirmPendingRef.current = false;
		setDryRunState( ( prev ) => ( { ...prev, retryingJobId: null } ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ dryRunPolling.run ] );

	useEffect( () => {
		if ( ! importRetryConfirmPendingRef.current ) {
			return;
		}

		importRetryConfirmPendingRef.current = false;
		setImportState( ( prev ) => ( { ...prev, retryingJobId: null } ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ importPolling.run ] );

	const dryRunActive = null !== dryRunState.runId && ! dryRunTerminal;
	const importActive = null !== importState.runId && ! importTerminal;
	// `dryRunActive`/`importActive`はrun_idが確定してからterminalになるまでしか
	// trueにならない。POSTがまだ応答を返していない`starting`中や、失敗ジョブを
	// pendingへ戻す`retryingJobId`中もプラットフォームを占有しうる（`start_run()`の
	// 同時実行ガードも`JobManager::retry()`のジョブ復帰も、この「行間」を互いに
	// 認識しない）ため、もう片方のセクションのRetry/開始操作を塞ぐ判定には
	// これらも含める。
	const dryRunBusy =
		dryRunActive ||
		dryRunState.starting ||
		null !== dryRunState.retryingJobId;
	const importBusy =
		importActive ||
		importState.starting ||
		null !== importState.retryingJobId;

	// 進行中の run の発見（R3-0i・issue #70）。run_id を控えていない run（応答がブラウザに届かなかった・
	// 別ブラウザで始めた・失敗で止まった）を見つけ、このタブの種別ならセクションに取り込み、
	// 別タブの種別なら案内する。
	const activeRuns = useActiveRuns( platform, [
		dryRunState.runId,
		importState.runId,
	] );
	const otherActiveRuns = untrackedRuns( activeRuns.runs, [
		dryRunState.runId,
		importState.runId,
	] );
	// 追跡していない run があるうちは開始・Retry を止める（サーバーも 409 で拒否する）。
	const blockedByOtherRun = otherActiveRuns.length > 0;
	// 案内する run: 別タブの run と、このタブの種別でもセクションが別の進行中の run を表示していて取り込めない run。
	const dryRunShowsActiveRun =
		null !== dryRunPolling.run &&
		dryRunPolling.run.run_id === dryRunState.runId &&
		! dryRunTerminal;
	const importShowsActiveRun =
		null !== importPolling.run &&
		importPolling.run.run_id === importState.runId &&
		! importTerminal;
	const announcedRuns = runsToAnnounce(
		otherActiveRuns,
		'import',
		( type ) =>
			'dry_run' === type ? dryRunShowsActiveRun : importShowsActiveRun
	);

	useRunAdoption( {
		type: 'dry_run',
		runId: dryRunState.runId,
		busy:
			dryRunState.starting ||
			null !== dryRunState.retryingJobId ||
			dryRunState.cancelling,
		polling: dryRunPolling,
		activeRuns,
		onAdopt: ( run ) => adoptRun( 'dry_run', run ),
	} );
	useRunAdoption( {
		type: 'import',
		runId: importState.runId,
		busy:
			importState.starting ||
			null !== importState.retryingJobId ||
			importState.cancelling,
		polling: importPolling,
		activeRuns,
		onAdopt: ( run ) => adoptRun( 'import', run ),
	} );

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

	/**
	 * セクションで run の表示を始める（開始が成功したとき・進行中の run を見つけて取り込んだときの共通処理）。
	 * @param type
	 * @param runId
	 */
	function beginTrackingRun( type: RunType, runId: string ) {
		( 'dry_run' === type ? setDryRunState : setImportState )(
			( prev ) => ( {
				...prev,
				runId,
				starting: false,
			} )
		);
	}

	/**
	 * 進行中の run を見つけたとき、このセクションに取り込む（`useRunAdoption`）。
	 * @param type
	 * @param run
	 */
	function adoptRun( type: RunType, run: ActiveRun ) {
		if ( null === platform ) {
			return;
		}

		storeRunId( platform, type, run.run_id );
		beginTrackingRun( type, run.run_id );
	}

	async function startRun( type: RunType ) {
		if ( null === platform || 0 === selectedEntities.size ) {
			return;
		}

		if (
			'import' === type &&
			// eslint-disable-next-line no-alert
			! window.confirm(
				__(
					'This will write real WooCommerce data (products, orders, customers, etc.) to this site. Continue?',
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
		const requestedEntities = Array.from( selectedEntities );
		// 409 の一覧を、応答を待つ間に切り替えた別の選択の一覧として取り込まないため（G1-3）。
		const selection = activeRuns.selectionRef.current;

		setStartError( null );
		setState( ( prev ) => ( { ...prev, starting: true } ) );

		try {
			const response = await apiFetch< { run_id: string } >( {
				path: '/cbjp/v1/runs',
				method: 'POST',
				data: {
					type,
					platform: requestedPlatform,
					entities: requestedEntities,
				},
			} );

			storeRunId( requestedPlatform, type, response.run_id );

			if ( platformRef.current !== requestedPlatform ) {
				return;
			}

			beginTrackingRun( type, response.run_id );
			// 一覧に残っている前の run（取り込んだあと終わったが、追跡中なので照会し直していない）を、新しい run を
			// 始めたことで「追跡していない run」として案内・取り込みし直さないよう、取り直す（R2-2）。
			activeRuns.refresh();
		} catch ( err ) {
			if ( platformRef.current !== requestedPlatform ) {
				return;
			}

			setStartError( errorMessage( err ) );
			setState( ( prev ) => ( { ...prev, starting: false } ) );
			// 409 なら進行中の run を取り込む・案内する（`active_runs`）。409 以外（通信断で応答が届かなかった等）
			// でも、サーバー側では run が作られていることがあるため一覧を取り直して見つける（issue #70）。
			activeRuns.refresh( activeRunsSeed( selection, err ) );
		}
	}

	async function retryJob( type: RunType, jobId: number ) {
		// 409 の一覧を、応答を待つ間に切り替えた別の選択の一覧として取り込まないため（R1-2・G1-3）。
		const selection = activeRuns.selectionRef.current;
		const setState = 'dry_run' === type ? setDryRunState : setImportState;
		const refetch =
			'dry_run' === type ? dryRunPolling.refetch : importPolling.refetch;
		const confirmPendingRef =
			'dry_run' === type
				? dryRunRetryConfirmPendingRef
				: importRetryConfirmPendingRef;

		setState( ( prev ) => ( { ...prev, retryingJobId: jobId } ) );

		try {
			await apiFetch( {
				path: `/cbjp/v1/jobs/${ jobId }/retry`,
				method: 'POST',
			} );
			// `refetch()`（=`poll()`）は一時的な通信エラーを内部でcatchして解決する
			// （ポーリングを止めないため）。そのため単に`await refetch()`した直後に
			// `retryingJobId`を解除すると、確認フェッチがちょうど失敗した場合に
			// 古い（リトライ前の）terminalスナップショットのままClear/開始操作が
			// 再度有効になってしまう。ラッチを立てておき、実際に新しいスナップショットが
			// 届いた時点（`run`オブジェクトの参照が変わった時点＝ポーリング成功時のみ）で
			// 上のeffectが解除する。
			confirmPendingRef.current = true;
			refetch();
		} catch ( err ) {
			// リトライAPI呼び出し自体が失敗した場合は何もrequeueされていないため、
			// ただちに解除してよい。
			setStartError( errorMessage( err ) );
			setState( ( prev ) => ( { ...prev, retryingJobId: null } ) );

			const seed = activeRunsSeed( selection, err );

			// 別の run が進行中（409）なら、その run を案内する。
			if ( undefined !== seed ) {
				activeRuns.refresh( seed );
			}
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
			activeRuns.refresh();
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
		// 手放した run を、Clear より前に取得した一覧から取り込み直さない（取り直すまで stale になる）。
		activeRuns.refresh();
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

					<div className="cbjp-import__entities">
						{ currentConnection &&
							availableEntities(
								currentConnection.capabilities
							).map( ( entity ) => (
								<CheckboxControl
									key={ entity }
									label={ ENTITY_LABELS[ entity ] }
									checked={ selectedEntities.has( entity ) }
									disabled={
										dryRunBusy ||
										importBusy ||
										blockedByOtherRun
									}
									onChange={ ( checked ) =>
										toggleEntity( entity, checked )
									}
								/>
							) ) }
					</div>

					{ platform && (
						// 受注を選んでいるとき、未設定の決済/配送マッピングを案内する（R3-0m。案内だけで実行は止めない）。
						<OrderMappingNotice
							platform={ platform }
							active={ selectedEntities.has( 'order' ) }
						/>
					) }

					{ platform && (
						<ActiveRunNotice
							key={ platform }
							platform={ platform }
							runs={ announcedRuns }
							currentTab="import"
							onChanged={ () => activeRuns.refresh() }
						/>
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
								dryRunBusy ||
								importBusy ||
								blockedByOtherRun ||
								0 === selectedEntities.size
							}
							onClick={ () => startRun( 'dry_run' ) }
						>
							{ __( 'Preview (dry run)', 'cart-bridge-jp' ) }
						</Button>{ ' ' }
						<Button
							variant="primary"
							isBusy={ importState.starting }
							disabled={
								dryRunBusy ||
								importBusy ||
								blockedByOtherRun ||
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
								null !== dryRunState.retryingJobId ||
								( ! dryRunTerminal && ! dryRunPolling.notFound )
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
								// 別run種別（`importBusy`）に加え、同じ種別で新しいrunを開始中
								// （`dryRunState.starting`）の間もRetryを止める: POST `/runs`が
								// 解決するまでこのカードは旧runを表示し続けるため、その間に旧runの
								// 失敗ジョブをRetryすると、サーバー側は新runがpendingになった時点で
								// 409を返すようになった（`JobManager::retry()`のガード、issue #54）が、
								// ユーザーに無用な409エラーを見せないよう先にボタン側で止める
								// （`ExportTab.tsx`と同型）。
								retryDisabled={
									importBusy ||
									dryRunState.starting ||
									blockedByOtherRun
								}
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
								null !== importState.retryingJobId ||
								( ! importTerminal && ! importPolling.notFound )
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
								// `starting`を含める理由は上のdry-run側カードと同じ（issue #54）。
								retryDisabled={
									dryRunBusy ||
									importState.starting ||
									blockedByOtherRun
								}
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
						{ /* 検証レポート（D17）は全ジョブが completed のときだけ出す。`isTerminal` は
						     failed/cancelled を含むため、それだけでゲートすると途中で止まった run の
						     部分的な結果を「完了した移行」として突合してしまう（RunProgress の
						     allCompleted と同じ判定）。 */ }
						{ importTerminal &&
							importPolling.run &&
							importPolling.run.jobs.every(
								( job ) => 'completed' === job.status
							) && (
								<VerificationReport
									runId={ importState.runId }
									entityLabels={ ENTITY_LABELS }
								/>
							) }
					</CardBody>
				</Card>
			) }
		</div>
	);
}
