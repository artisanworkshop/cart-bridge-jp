import {
	ENTITY_ORDER,
	type ActiveRun,
	type ActiveRunStatus,
	type EntityType,
	type RunType,
} from './types';

/**
 * 進行中の run（`GET /runs?platform=` と 409 `cbjp_run_in_progress` の `active_runs`。R3-0i・issue #70）を
 * 扱う純粋関数。UI の各タブはここで「どのタブの run か」「セクションに取り込むか」を決める。
 */

/** run を表示・操作できるタブ。 */
export type RunTab = 'import' | 'export';

export const RUN_TYPE_TAB: Record< RunType, RunTab > = {
	dry_run: 'import',
	import: 'import',
	dry_run_export: 'export',
	export: 'export',
};

const RUN_TYPES = Object.keys( RUN_TYPE_TAB ) as RunType[];

const ACTIVE_RUN_STATUSES: ActiveRunStatus[] = [
	'running',
	'paused',
	'pending',
];

/**
 * `RestController` のルート `/runs/(?P<run_id>[a-zA-Z0-9-]+)` と同じ文字だけを受け付ける（パスに埋め込むため）。
 */
const RUN_ID_PATTERN = /^[A-Za-z0-9-]+$/;

/**
 * @param type run の種別（解釈できない種別は null）
 * @return その run を表示・操作できるタブ（種別が不明なら null）
 */
export function runTab( type: RunType | null ): RunTab | null {
	return null === type ? null : RUN_TYPE_TAB[ type ];
}

function isRecord( value: unknown ): value is Record< string, unknown > {
	return (
		'object' === typeof value && null !== value && ! Array.isArray( value )
	);
}

function parseActiveRun( value: unknown ): ActiveRun | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const runId = value.run_id;

	if ( 'string' !== typeof runId || ! RUN_ID_PATTERN.test( runId ) ) {
		return null;
	}

	const type = RUN_TYPES.find( ( candidate ) => candidate === value.type );
	const status = ACTIVE_RUN_STATUSES.find(
		( candidate ) => candidate === value.status
	);
	const entities = Array.isArray( value.entities )
		? ( ENTITY_ORDER as readonly string[] ).filter( ( entity ) =>
				( value.entities as unknown[] ).includes( entity )
		  )
		: [];

	return {
		run_id: runId,
		type: type ?? null,
		status: status ?? 'unknown',
		entities: entities as EntityType[],
		// 安全側に倒す判定には使わない表示用の値だが、真偽値以外を「失敗あり」と読まない。
		has_failed_job: true === value.has_failed_job,
		created_at:
			'string' === typeof value.created_at ? value.created_at : '',
		updated_at:
			'string' === typeof value.updated_at ? value.updated_at : '',
	};
}

/**
 * サーバーの一覧をフェイルクローズで読む（読めない要素は捨てる。種別・状態は不明として残す）。
 * @param value `runs` / `active_runs` の値
 */
export function parseActiveRuns( value: unknown ): ActiveRun[] {
	if ( ! Array.isArray( value ) ) {
		return [];
	}

	return value
		.map( parseActiveRun )
		.filter( ( run ): run is ActiveRun => null !== run );
}

/**
 * apiFetch が投げた WP REST のエラー本文（`{ code, message, data }`）から、409 `cbjp_run_in_progress` の
 * `active_runs` を取り出す。
 * @param err
 * @return run が進行中のエラーでなければ null（一覧が無い・読めないときは空配列）
 */
export function activeRunsFromError( err: unknown ): ActiveRun[] | null {
	if ( ! isRecord( err ) || 'cbjp_run_in_progress' !== err.code ) {
		return null;
	}

	return parseActiveRuns(
		isRecord( err.data ) ? err.data.active_runs : undefined
	);
}

/**
 * このタブ（のセクション）がまだ追跡していない run。開始ボタン等を止める判定に使う。
 * @param runs
 * @param trackedRunIds セクションが表示中の run_id（null は空のセクション）
 */
export function untrackedRuns(
	runs: ActiveRun[],
	trackedRunIds: Array< string | null >
): ActiveRun[] {
	return runs.filter( ( run ) => ! trackedRunIds.includes( run.run_id ) );
}

/**
 * 別のタブの run（種別が不明な run を含む。`runTab( null )` は null で、どのタブとも一致しない）。
 * 案内（`ActiveRunNotice`）で知らせる対象。
 * @param runs
 * @param tab  表示中のタブ（Tools・Mappings のように run を扱わないタブは null で、全 run が対象）
 */
export function foreignRuns(
	runs: ActiveRun[],
	tab: RunTab | null
): ActiveRun[] {
	return runs.filter( ( run ) => null === tab || runTab( run.type ) !== tab );
}

/** run を表示するセクション（Import タブの dry-run / import など）の今の状態。 */
export interface RunSectionSnapshot {
	runId: string | null;
	/** 開始の POST・Retry・キャンセルの応答待ち。 */
	busy: boolean;
	/** ポーリングが表示中の run を 1 回以上取得できたか。 */
	loaded: boolean;
	/** 取得済みの run の全ジョブが終了している。 */
	terminal: boolean;
	/** 表示中の run_id がサーバーに存在しない（404）。 */
	notFound: boolean;
}

export type AdoptionDecision =
	| { kind: 'none' }
	| { kind: 'adopt'; run: ActiveRun }
	| { kind: 'reconcile' };

/**
 * 進行中の run の一覧から、このセクションに run を取り込むかを決める。
 *
 * - 一覧が古い（取り直し中）・セクションが応答待ちのときは何もしない（Clear・キャンセルの直前の一覧で、
 *   手放したばかりの run を取り込み直さないため）。
 * - 空のセクション、または表示中の run が終了・不明（404）なら、最も古い run を取り込む。
 * - 表示中の run を取得できていないうちは待つ（終了しているか分からないまま置き換えない）。
 * - 表示中の run が進行中なら置き換えない。
 * - 表示中の run を「終了」と見ているのに一覧では進行中なら `reconcile`（別ブラウザからの Retry 等で
 *   再開した run をポーリングが見失っている。または一覧のほうが古い。両方を取り直す）。
 *
 * @param section
 * @param candidates このセクションと同じ種別の run（一覧の順＝古い順）
 * @param stale      一覧を取り直している最中か
 */
export function decideAdoption(
	section: RunSectionSnapshot,
	candidates: ActiveRun[],
	stale: boolean
): AdoptionDecision {
	if ( stale || section.busy || 0 === candidates.length ) {
		return { kind: 'none' };
	}

	if (
		null !== section.runId &&
		candidates.some( ( run ) => run.run_id === section.runId )
	) {
		return section.loaded && section.terminal
			? { kind: 'reconcile' }
			: { kind: 'none' };
	}

	if ( null === section.runId || section.notFound ) {
		return { kind: 'adopt', run: candidates[ 0 ] };
	}

	if ( ! section.loaded ) {
		return { kind: 'none' };
	}

	return section.terminal
		? { kind: 'adopt', run: candidates[ 0 ] }
		: { kind: 'none' };
}
