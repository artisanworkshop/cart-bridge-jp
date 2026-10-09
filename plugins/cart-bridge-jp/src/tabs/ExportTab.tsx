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
import { activeRunsSeed, runsToAnnounce, untrackedRuns } from '../active-runs';
import ActiveRunNotice from '../components/ActiveRunNotice';
import LimitsUpsellNotice from '../components/LimitsUpsellNotice';
import {
	dryRunEntityTotals,
	withoutDryRunTotals,
	type DryRunTotals,
} from '../components/upsell-breakdown';
import PushIntentsPanel from '../components/PushIntentsPanel';
import RunProgress from '../components/RunProgress';
import { ENTITY_LABELS } from '../entity-labels';
import { parseHash, tabHref } from '../hash-route';
import { useActiveRuns } from '../hooks/useActiveRuns';
import { joinList, joinSentences } from '../i18n';
import { useRunAdoption } from '../hooks/useRunAdoption';
import { isRunTerminal, useRunPolling } from '../hooks/useRunPolling';
import { clearStoredRunId, loadStoredRunId, storeRunId } from '../run-storage';
import type {
	ActiveRun,
	Capabilities,
	Connection,
	EntityType,
	ExportOptions,
	Job,
	Limits,
} from '../types';

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

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

/**
 * D24: エクスポートがベータ扱い（`Capabilities::$beta_features`）になるエンティティ。ベータの受注は接続先に
 * 受注（売上）を作るため、Beta 表示にして既定では選択しない。
 */
const BETA_FEATURE_BY_ENTITY: Partial< Record< EntityType, string > > = {
	order: 'order_export',
};

/**
 * D24: 商品画像のアップロード（`Capabilities::BETA_IMAGE_PUSH`）。
 */
const BETA_IMAGE_PUSH = 'image_push';

function isBetaFeature( capabilities: Capabilities, feature: string ): boolean {
	return ( capabilities.beta_features ?? [] ).includes( feature );
}

function isBetaEntity(
	capabilities: Capabilities,
	entity: EntityType
): boolean {
	const feature = BETA_FEATURE_BY_ENTITY[ entity ];

	return undefined !== feature && isBetaFeature( capabilities, feature );
}

/**
 * 実行対象の既定の選択。ベータ機能のエンティティは、店舗が明示的に選んだときだけ動かすため既定では外す（D24）。
 * @param capabilities
 */
function defaultExportEntities( capabilities: Capabilities ): EntityType[] {
	return availableExportEntities( capabilities ).filter(
		( entity ) => ! isBetaEntity( capabilities, entity )
	);
}

/**
 * ベータ機能に共通の注意書き（D24）。プレミアムプラン限定の API に依存し、テストショップが無く実店舗で
 * 検証できていないことを伝える。
 */
function betaNote(): string {
	return __(
		'Beta: this feature needs a premium plan on the connected shop and has not been verified on a real premium-plan shop yet. It is off by default — turn it on only if you want to try it.',
		'cart-bridge-jp'
	);
}

function betaEntityHelp( entity: EntityType ): string {
	if ( 'order' === entity ) {
		return joinSentences(
			__(
				'Creates orders (sales) in the connected shop.',
				'cart-bridge-jp'
			),
			betaNote()
		);
	}

	return betaNote();
}

/**
 * 画像アップロードの説明。オンにすると export 済みの商品が更新として再送され、ColorMe の
 * `POST /products/{id}/images` は同じ position の既存画像を**上書き**する（swagger）ため、
 * 管理画面で手作業で登録した画像が置き換わりうることを先に伝える。
 * @param beta
 */
function pushImagesHelp( beta: boolean ): string {
	const off = __(
		'Uploads the WooCommerce product images to the connected shop. When this is off, add product images in the shop’s own admin screen after exporting.',
		'cart-bridge-jp'
	);
	const on = __(
		'Turning it on sends products that were already exported again (as updates) on the next export, and images already registered in the shop at the same positions are overwritten by the WooCommerce images.',
		'cart-bridge-jp'
	);
	const base = joinSentences( off, on );

	return beta ? joinSentences( base, betaNote() ) : base;
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
 * `created+updated===0`のcompletedジョブをUI側で検出してバナー表示する。
 *
 * `warned>0`（1件でも警告）ではなく`warned===processed`（処理した全件が警告）を条件にする:
 * `Sync\Exporter::process_items()`はchecksum一致でskipした場合でも、読出時点の非ブロッキング
 * 警告（`$read_item->warnings`が空でなければ）を引き続き`warned`へ加算する（「解消済みに見えて
 * しまう」のを防ぐための既存仕様。`Exporter.php`のコメント参照）。そのため`warned>0`のままだと、
 * 健全な冪等スキップ（一部アイテムだけ残留警告あり）でも「何も書き込まれなかった」と誤検出しうる。
 * `warned===processed`（＝1件も書けず全件に警告が付いた）に絞ることで、この種の偽陽性を減らす
 * （完全な排除ではない: 全件が同じ残留警告を持つ場合は理論上なお誤検出しうるが、`Sync\Importer`
 * と同じ既存方針が対象とする「実質的に何も進まなかった」ケースにより近い判定になる）。
 * この絞り込みはトレードオフでもある: 「一部は警告付きでskip・残りは警告なしでskip
 * （無料版上限到達等。`Exporter.php`のクォータ枯渇分岐参照）」のように`warned < processed`と
 * なる部分的な失敗は検出できなくなる（偽陰性）。クォータ枯渇のケースは`LimitsUpsellNotice`が
 * 別途表示するため実害は小さいと判断した（R2レビューで指摘、`docs/review-backlog.md`の
 * `e2-4-export-ui-e2e/R2-L1`参照）。
 * @param jobs
 */
function zeroWrittenWarnedEntities( jobs: Job[] ): EntityType[] {
	return jobs
		.filter(
			( job ) =>
				'completed' === job.status &&
				job.totals.processed > 0 &&
				0 === job.totals.created + job.totals.updated &&
				job.totals.warned === job.totals.processed
		)
		.map( ( job ) => job.entity );
}

export default function ExportTab() {
	const [ connections, setConnections ] = useState< Connection[] | null >(
		null
	);
	const [ connectionsError, setConnectionsError ] = useState< string | null >(
		null
	);
	const [ platform, setPlatform ] = useState< string | null >( null );
	// プラットフォーム名だけでは同じプラットフォームへ短時間で戻った場合（A→B→A）を区別できない。
	// 画像設定のGET/PUT・run開始・Retry・キャンセル・limits取得の応答が遅れて届いたとき、
	// プラットフォーム名の一致チェックだけでは「新しい応答」と誤認して今の選択の状態を上書きしてしまう。
	// これらはすべて同じ世代カウンタを参照し、「このリクエストが発行された時点のプラットフォーム選択が
	// まだ現在のものか」を判定する（`.claude/rules/frontend.md`）。世代を進めるのは画像設定の取得effectだけ
	// （マッピングの取得・保存は R3-0m で Mappings タブの`MappingSettings`へ移した）。
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
	const [ dryRunTotals, setDryRunTotals ] = useState< DryRunTotals | null >(
		null
	);
	const [ limits, setLimits ] = useState< Limits | null >( null );
	// D24: プラットフォーム単位のエクスポート設定（画像アップロードのオン/オフ）。`null`は取得前または取得失敗。
	// 取得・保存とも`platformGenerationRef`で古い応答を捨てる（下のeffectと`setPushImages()`）。
	const [ exportOptions, setExportOptions ] =
		useState< ExportOptions | null >( null );
	const [ exportOptionsError, setExportOptionsError ] = useState<
		string | null
	>( null );
	const [ exportOptionsSaving, setExportOptionsSaving ] = useState( false );
	// `startRun()`/`limits`取得effectが応答を受け取った時点でまだ同じプラットフォーム選択かも、
	// 同じ`platformGenerationRef`で判定する（frontend.md:「同じ状態を更新しうる複数の非同期処理は
	// 同じ世代カウンタを共有する必要がある」）。
	const dryRunExportRetryConfirmPendingRef = useRef( false );
	const exportRetryConfirmPendingRef = useRef( false );
	// limits取得effectが応答を受け取った時点でまだ同じexport runを指しているかを判定するための
	// 参照（`useRunPolling`の`runIdRef`と同じ役割）。`platformGenerationRef`は同一platform内で
	// 新しいrunが始まった場合には変化しないため、そのケースの古い応答を弾けない
	// （Codexレビュー指摘, G3: runA完了時の`/limits`取得中にrunBを開始し`setLimits(null)`で
	// クリアした後、runAの応答が遅れて届くと`limits`を古い使用状況で上書きしてしまう）。
	const exportRunIdRef = useRef< string | null >( null );
	exportRunIdRef.current = exportState.runId;

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

		// 他のタブの案内（`ActiveRunNotice`）から来たときは、そちらで選んでいたプラットフォームを開く
		// （`#/export?platform=`）。ハッシュは任意の文字列を含みうるので、接続済みのものに一致するときだけ使う。
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

	// D24: エクスポート設定（画像アップロード）の取得。プラットフォームの選択が変わるたびに走る唯一の
	// 非同期取得なので、ここで`platformGenerationRef`を進める（R3-0m でマッピング取得effectを Mappings タブへ
	// 移すまではあちらが進めていた）。**世代を読む他のeffect・関数はこのeffectの後で動く**
	// （run開始等はユーザー操作、limits取得effectはこのeffectより後ろに宣言してある）。
	useEffect( () => {
		if ( null === platform ) {
			return;
		}

		const requestId = ++platformGenerationRef.current;

		setExportOptions( null );
		setExportOptionsError( null );
		setExportOptionsSaving( false );

		apiFetch< ExportOptions >( {
			path: `/cbjp/v1/settings/export-options/${ encodeURIComponent(
				platform
			) }`,
		} )
			.then( ( data ) => {
				if ( platformGenerationRef.current !== requestId ) {
					return;
				}

				setExportOptions( data );
			} )
			.catch( ( err: unknown ) => {
				if ( platformGenerationRef.current !== requestId ) {
					return;
				}

				setExportOptionsError( errorMessage( err ) );
			} );
	}, [ platform ] );

	// プラットフォームが変わったら、実行フロー側の状態（エンティティ選択・直前のrun_id・
	// 警告チェックボックス等）も読み込み直す。画像設定の取得effectとは独立した状態を扱うため
	// 別effectにするが、判定に使う`platformGenerationRef`は共有する。
	useEffect( () => {
		if ( null === platform || null === currentConnection ) {
			return;
		}

		setSelectedExportEntities(
			new Set( defaultExportEntities( currentConnection.capabilities ) )
		);
		setAcknowledgeProductionWrite( false );
		setRunStartError( null );
		setDryRunTotals( null );
		setLimits( null );
		setDryRunExportState( {
			...initialExportRunSectionState(),
			runId: loadStoredRunId( platform, 'dry_run_export' ),
		} );
		setExportState( {
			...initialExportRunSectionState(),
			runId: loadStoredRunId( platform, 'export' ),
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

	// dry-runが完了したら、Pro案内（D15/§10.3）で使う「総数」と「移行できる件数」をキャッシュする
	// （`ImportTab.tsx`と同じロジック。サンプリングを行わない全量走査なのでprocessedが
	// そのまま総数になる。内訳は`dryRunEntityTotals()`。issue #55）。
	useEffect( () => {
		if ( ! dryRunExportPolling.run || ! dryRunExportTerminal ) {
			return;
		}

		const totals: DryRunTotals = {};

		for ( const job of dryRunExportPolling.run.jobs ) {
			if ( 'completed' === job.status ) {
				totals[ job.entity ] = dryRunEntityTotals( job.totals );
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
		// この取得を発生させたrunのIDを閉じ込める。`platformGenerationRef`は同一platform内で
		// 新しいrunが始まっただけでは変化しないため、応答が届いた時点でまだ「この取得の
		// きっかけになったrun」がexportStateの現在値か（＝新しいrunに置き換わっていないか）も
		// 別途確認する（`exportRunIdRef`参照）。
		const requestedRunId = exportPolling.run?.run_id ?? null;

		apiFetch< Limits >( {
			path: `/cbjp/v1/limits?platform=${ encodeURIComponent(
				requestedPlatform
			) }`,
		} )
			.then( ( data ) => {
				if (
					platformGenerationRef.current !== requestId ||
					exportRunIdRef.current !== requestedRunId
				) {
					return;
				}

				setLimits( data );
			} )
			.catch( () => {
				if (
					platformGenerationRef.current !== requestId ||
					exportRunIdRef.current !== requestedRunId
				) {
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

	// 進行中の run の発見（R3-0i・issue #70。`ImportTab.tsx`と同じ）。run_id を控えていない run を見つけ、
	// このタブの種別ならセクションに取り込み、別タブの種別なら案内する。
	const activeRuns = useActiveRuns( platform, [
		dryRunExportState.runId,
		exportState.runId,
	] );
	const otherActiveRuns = untrackedRuns( activeRuns.runs, [
		dryRunExportState.runId,
		exportState.runId,
	] );
	// 追跡していない run があるうちは開始・Retry・設定の変更を止める（サーバーも 409 で拒否する）。
	const blockedByOtherRun = otherActiveRuns.length > 0;
	// 案内する run（`ImportTab.tsx`と同じ）: 別タブの run と、取り込めない同じ種別の run。
	const dryRunExportShowsActiveRun =
		null !== dryRunExportPolling.run &&
		dryRunExportPolling.run.run_id === dryRunExportState.runId &&
		! dryRunExportTerminal;
	const exportShowsActiveRun =
		null !== exportPolling.run &&
		exportPolling.run.run_id === exportState.runId &&
		! exportTerminal;
	const announcedRuns = runsToAnnounce(
		otherActiveRuns,
		'export',
		( type ) =>
			'dry_run_export' === type
				? dryRunExportShowsActiveRun
				: exportShowsActiveRun
	);

	useRunAdoption( {
		type: 'dry_run_export',
		runId: dryRunExportState.runId,
		busy:
			dryRunExportState.starting ||
			null !== dryRunExportState.retryingJobId ||
			dryRunExportState.cancelling,
		polling: dryRunExportPolling,
		activeRuns,
		onAdopt: ( run ) => adoptExportRun( 'dry_run_export', run ),
	} );
	useRunAdoption( {
		type: 'export',
		runId: exportState.runId,
		busy:
			exportState.starting ||
			null !== exportState.retryingJobId ||
			exportState.cancelling,
		polling: exportPolling,
		activeRuns,
		onAdopt: ( run ) => adoptExportRun( 'export', run ),
	} );
	// 画像アップロードの設定を持つ（能力がある）プラットフォームで、設定をまだ取得できていない間（取得前・取得失敗）。
	// チェックボックスは未チェックに見えるがサーバー側は true かもしれず、そのまま本番の export を始めると、
	// 画像を（同じ位置の既存画像の上書きを含めて）送りかねない。設定を取得できるまで本番の export を始めさせない
	// （フェイルクローズ。dry-run は何も書かないので止めない）。
	const exportOptionsPending =
		true === currentConnection?.capabilities.can_push_images &&
		null === exportOptions;

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

	/**
	 * 「商品画像をアップロードする」を保存する（変更のたびに即保存）。サーバーは真偽値だけを受け付け、実行中の
	 * runがあれば409で拒否する（`RestController::save_export_options()`）。
	 * @param checked
	 */
	async function setPushImages( checked: boolean ) {
		if ( null === platform || null === exportOptions ) {
			return;
		}

		// このリクエストを発行した時点のプラットフォーム世代を閉じ込める（`save()`と同じ理由）。
		const requestId = platformGenerationRef.current;
		const selection = activeRuns.selectionRef.current;

		setExportOptionsSaving( true );
		setExportOptionsError( null );

		try {
			const data = await apiFetch< ExportOptions >( {
				path: `/cbjp/v1/settings/export-options/${ encodeURIComponent(
					platform
				) }`,
				method: 'PUT',
				data: { push_images: checked },
			} );

			if ( platformGenerationRef.current !== requestId ) {
				return;
			}

			setExportOptions( data );
		} catch ( err ) {
			if ( platformGenerationRef.current !== requestId ) {
				return;
			}

			setExportOptionsError( errorMessage( err ) );

			const seed = activeRunsSeed( selection, err );

			// 別の run が進行中（409）なら、その run を案内する。
			if ( undefined !== seed ) {
				activeRuns.refresh( seed );
			}
		} finally {
			if ( platformGenerationRef.current === requestId ) {
				setExportOptionsSaving( false );
			}
		}
	}

	/**
	 * セクションで run の表示を始める（開始が成功したとき・進行中の run を見つけて取り込んだときの共通処理）。
	 * @param type
	 * @param runId
	 * @param entities この run のエンティティ（前回の dry-run 件数を捨てる対象）
	 */
	function beginTrackingExportRun(
		type: 'dry_run_export' | 'export',
		runId: string,
		entities: EntityType[]
	) {
		if ( 'export' === type ) {
			setLimits( null );
			// 実行のたびに再確認させる（`ImportTab.tsx`の`window.confirm()`は
			// クリックの都度出るのに対し、このチェックボックスは状態として残り続けるため、
			// 開始できたら明示的に外す。D17の「実行前に確認」を1回のみで弱めない）。
			// 見つけた export run を取り込んだときも、その run の前に付けた確認を次の実行へ持ち越さない。
			setAcknowledgeProductionWrite( false );
		}

		// 新しいdry-runの対象エンティティは前回の件数を捨てる（`ImportTab.tsx`と同じ。
		// `withoutDryRunTotals()`参照。PR #87 G1-1）。
		if ( 'dry_run_export' === type ) {
			setDryRunTotals( ( prev ) =>
				withoutDryRunTotals( prev, entities )
			);
		}

		( 'dry_run_export' === type ? setDryRunExportState : setExportState )(
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
	function adoptExportRun(
		type: 'dry_run_export' | 'export',
		run: ActiveRun
	) {
		if ( null === platform ) {
			return;
		}

		storeRunId( platform, type, run.run_id );
		beginTrackingExportRun( type, run.run_id, run.entities );
	}

	async function startExportRun( type: 'dry_run_export' | 'export' ) {
		if ( null === platform || 0 === selectedExportEntities.size ) {
			return;
		}

		if ( 'export' === type && ! acknowledgeProductionWrite ) {
			return;
		}

		if ( 'export' === type && exportOptionsPending ) {
			return;
		}

		const setState =
			'dry_run_export' === type ? setDryRunExportState : setExportState;
		const requestedPlatform = platform;
		const requestId = platformGenerationRef.current;
		const requestedEntities = Array.from( selectedExportEntities );
		const selection = activeRuns.selectionRef.current;

		setRunStartError( null );
		setState( ( prev ) => ( { ...prev, starting: true } ) );

		try {
			const response = await apiFetch< { run_id: string } >( {
				path: '/cbjp/v1/runs',
				method: 'POST',
				data: {
					type,
					platform: requestedPlatform,
					entities: requestedEntities,
					...( 'export' === type
						? { acknowledge_production_write: true }
						: {} ),
				},
			} );

			storeRunId( requestedPlatform, type, response.run_id );

			if ( platformGenerationRef.current !== requestId ) {
				return;
			}

			beginTrackingExportRun( type, response.run_id, requestedEntities );
			// 一覧に残っている前の run を、新しい run を始めたことで案内・取り込みし直さないよう取り直す（R2-2）。
			activeRuns.refresh();
		} catch ( err ) {
			if ( platformGenerationRef.current !== requestId ) {
				return;
			}

			setRunStartError( errorMessage( err ) );
			setState( ( prev ) => ( { ...prev, starting: false } ) );
			// 409 なら進行中の run を取り込む・案内する（`active_runs`）。409 以外（通信断で応答が届かなかった等）
			// でも、サーバー側では run が作られていることがあるため一覧を取り直して見つける（issue #70）。
			activeRuns.refresh( activeRunsSeed( selection, err ) );
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
		// このリクエストを発行した時点のプラットフォーム世代を閉じ込める。応答が届くまでの間に
		// ユーザーが別プラットフォームへ切り替えていた場合、そちらの状態（platform-change時に
		// 読み込み直し済み）をこの古い応答で上書きしない（`startExportRun()`/limits取得effectと
		// 同じ`platformGenerationRef`を使う）。
		const requestId = platformGenerationRef.current;
		const selection = activeRuns.selectionRef.current;

		setState( ( prev ) => ( { ...prev, retryingJobId: jobId } ) );

		try {
			await apiFetch( {
				path: `/cbjp/v1/jobs/${ jobId }/retry`,
				method: 'POST',
			} );

			if ( platformGenerationRef.current !== requestId ) {
				return;
			}

			confirmPendingRef.current = true;
			refetch();
		} catch ( err ) {
			if ( platformGenerationRef.current !== requestId ) {
				return;
			}

			setRunStartError( errorMessage( err ) );
			setState( ( prev ) => ( { ...prev, retryingJobId: null } ) );

			const seed = activeRunsSeed( selection, err );

			// 別の run が進行中（409）なら、その run を案内する。
			if ( undefined !== seed ) {
				activeRuns.refresh( seed );
			}
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
		const requestId = platformGenerationRef.current;

		setState( ( prev ) => ( { ...prev, cancelling: true } ) );

		try {
			await apiFetch( {
				path: `/cbjp/v1/runs/${ runId }/cancel`,
				method: 'POST',
			} );

			if ( platformGenerationRef.current !== requestId ) {
				return;
			}

			refetch();
			activeRuns.refresh();
		} catch ( err ) {
			if ( platformGenerationRef.current !== requestId ) {
				return;
			}

			setRunStartError( errorMessage( err ) );
		} finally {
			if ( platformGenerationRef.current === requestId ) {
				setState( ( prev ) => ( { ...prev, cancelling: false } ) );
			}
		}
	}

	function clearExportRun( type: 'dry_run_export' | 'export' ) {
		if ( null === platform ) {
			return;
		}

		clearStoredRunId( platform, type );
		( 'dry_run_export' === type ? setDryRunExportState : setExportState )(
			initialExportRunSectionState()
		);
		// 手放した run を、Clear より前に取得した一覧から取り込み直さない（取り直すまで stale になる）。
		activeRuns.refresh();
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
					'Connect a platform on the Connections tab before exporting.',
					'cart-bridge-jp'
				) }
			</p>
		);
	}

	return (
		<div className="cbjp-export">
			<Card>
				<CardBody>
					<p>
						{ /* カテゴリのマッピングは、カテゴリを作れないプラットフォームでだけ Mappings タブに出る（`MappingsTab.tsx`）。 */ }
						{ false ===
						currentConnection?.capabilities.can_create_category
							? __(
									'Category, payment method, shipping method, and order status mappings are set on the Mappings tab. Map your WooCommerce categories before exporting products, and payment and shipping methods before exporting orders.',
									'cart-bridge-jp'
							  )
							: __(
									'Payment method, shipping method, and order status mappings are set on the Mappings tab. Map payment and shipping methods before exporting orders.',
									'cart-bridge-jp'
							  ) }
					</p>
					<p>
						<a href={ tabHref( 'mappings', platform ) }>
							{ __( 'Open the Mappings tab', 'cart-bridge-jp' ) }
						</a>
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

			{ platform && (
				<PushIntentsPanel
					key={ platform }
					platform={ platform }
					runInProgress={
						dryRunExportBusy || exportBusy || blockedByOtherRun
					}
					activeRuns={ activeRuns }
				/>
			) }

			<Card className="cbjp-export__run">
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
							).map( ( entity ) => {
								const beta = isBetaEntity(
									currentConnection.capabilities,
									entity
								);

								return (
									<CheckboxControl
										key={ entity }
										label={
											beta
												? sprintf(
														/* translators: %s: entity name, e.g. "Orders" */
														__(
															'%s (Beta)',
															'cart-bridge-jp'
														),
														ENTITY_LABELS[ entity ]
												  )
												: ENTITY_LABELS[ entity ]
										}
										help={
											beta
												? betaEntityHelp( entity )
												: undefined
										}
										checked={ selectedExportEntities.has(
											entity
										) }
										disabled={
											dryRunExportBusy ||
											exportBusy ||
											blockedByOtherRun
										}
										onChange={ ( checked ) =>
											toggleExportEntity(
												entity,
												checked
											)
										}
									/>
								);
							} ) }
					</div>

					{ currentConnection?.capabilities.can_push_images && (
						<div className="cbjp-export__options">
							<p>
								<strong>
									{ __( 'Options', 'cart-bridge-jp' ) }
								</strong>
							</p>
							<CheckboxControl
								label={
									isBetaFeature(
										currentConnection.capabilities,
										BETA_IMAGE_PUSH
									)
										? __(
												'Upload product images (Beta)',
												'cart-bridge-jp'
										  )
										: __(
												'Upload product images',
												'cart-bridge-jp'
										  )
								}
								help={ pushImagesHelp(
									isBetaFeature(
										currentConnection.capabilities,
										BETA_IMAGE_PUSH
									)
								) }
								checked={ exportOptions?.push_images ?? false }
								// 取得前・保存中・実行中は操作させない（実行の途中で設定が変わると、同じrunの中で
								// 商品ごとに画像の扱いが割れる。サーバーも実行中は409で拒否する）。
								disabled={
									null === exportOptions ||
									exportOptionsSaving ||
									dryRunExportBusy ||
									exportBusy ||
									blockedByOtherRun
								}
								onChange={ setPushImages }
							/>
							{ exportOptionsError && (
								// 取得に失敗した（`exportOptions`が`null`のまま）ときは破棄しても再取得の手段が
								// 無く、チェックボックスが無効のまま何も表示されない状態になる。破棄できるのは
								// 保存失敗（設定は取得済みで、もう一度操作できる）のときだけにする。
								<Notice
									status="error"
									isDismissible={ null !== exportOptions }
									onRemove={ () =>
										setExportOptionsError( null )
									}
								>
									{ null === exportOptions
										? joinSentences(
												exportOptionsError,
												__(
													'Running an export is disabled until this setting loads. Reload the page to try again.',
													'cart-bridge-jp'
												)
										  )
										: exportOptionsError }
								</Notice>
							) }
						</div>
					) }

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
						disabled={ exportBusy || blockedByOtherRun }
						onChange={ setAcknowledgeProductionWrite }
					/>

					{ platform && (
						<ActiveRunNotice
							key={ platform }
							platform={ platform }
							runs={ announcedRuns }
							currentTab="export"
							onChanged={ () => activeRuns.refresh() }
						/>
					) }

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
								blockedByOtherRun ||
								exportOptionsSaving ||
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
								blockedByOtherRun ||
								exportOptionsSaving ||
								exportOptionsPending ||
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
								// 別run種別（`exportBusy`）に加え、同じ種別で新しいrunを
								// 開始中（`starting`）の間もRetryを止める: POST `/runs`が
								// 解決するまでこのカードは旧runを表示し続けるため、その間に
								// 旧runの失敗ジョブをRetryすると`JobManager::retry()`が
								// `start_run()`の同時実行ガードを経由せずrequeueし、
								// 新旧2つのrunが同時に本番へ書き込みうる（Codexレビュー指摘）。
								retryDisabled={
									exportBusy ||
									dryRunExportState.starting ||
									blockedByOtherRun
								}
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
										/* translators: %s: list of entity labels, e.g. "Products, Customers" */
										__(
											'Nothing was written for: %s. All items were skipped or produced warnings — check the dry-run report or the Logs tab for why.',
											'cart-bridge-jp'
										),
										joinList(
											zeroWrittenExportEntities.map(
												( entity ) =>
													ENTITY_LABELS[ entity ]
											)
										)
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
								// `starting`を含める理由は上のdry-run側カードと同じ
								// （Codexレビュー指摘）。
								retryDisabled={
									dryRunExportBusy ||
									exportState.starting ||
									blockedByOtherRun
								}
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
