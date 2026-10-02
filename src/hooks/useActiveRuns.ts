import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '../api';
import {
	parseActiveRuns,
	untrackedRuns,
	type ActiveRunsSeed,
} from '../active-runs';
import type { ActiveRun } from '../types';

/** 追跡していない run がある間の照会間隔（終わった・キャンセルされたことを早く反映する）。 */
const POLL_INTERVAL_MS = 5000;

/**
 * 追跡していない run が無い間の照会間隔。一覧が空でも照会を止めない: 止めると、このタブを開いた後に別のブラウザで
 * 始まった run を見つけられず、サーバーが拒否しない操作（Mappings の保存）が run の途中ですり抜ける（G1-4/G1-5）。
 */
const IDLE_POLL_INTERVAL_MS = 30000;

/** 4xx（権限が無い・プラットフォームが登録されていない等）の後の再試行間隔。すぐには直らないので間を空ける。 */
const CLIENT_ERROR_RETRY_MS = 60000;

/** 一覧が無いときに返す値（描画のたびに新しい配列を作り、利用側の effect を毎回走らせないため）。 */
const NO_RUNS: ActiveRun[] = [];

interface ActiveRunsState {
	/** この一覧を取得したプラットフォーム（null は未取得）。 */
	platform: string | null;
	runs: ActiveRun[];
	/** 取り直しを始めてから応答が届くまで true。古い一覧で run を取り込まないための印。 */
	stale: boolean;
	/**
	 * 一覧の版（反映するたびに増える。プラットフォームを切り替えても戻らない単調増加）。取り込み直しの判定を
	 * 一覧ごとに 1 回へ絞るために使う。0 はこのプラットフォームの一覧をまだ反映していない。
	 */
	generation: number;
}

const EMPTY_STATE: ActiveRunsState = {
	platform: null,
	runs: [],
	stale: true,
	generation: 0,
};

export interface ActiveRunsResult {
	/** 選択中のプラットフォームで進行中の run（古い順）。 */
	runs: ActiveRun[];
	stale: boolean;
	/** 選択中のプラットフォームの一覧を 1 回以上取得できたか。 */
	loaded: boolean;
	generation: number;
	/**
	 * いまのプラットフォーム選択の番号（選択が変わるたびに増える。読み取り専用）。409 を返しうる要求を出すときに
	 * `selectionRef.current` を控え、応答の `active_runs` を `activeRunsSeed( selection, err )` で `refresh()` に渡す。
	 */
	selectionRef: { readonly current: number };
	/**
	 * 一覧を取り直す。409 の `active_runs`（`activeRunsSeed()`）を `seed` に渡すと、応答を待たずにその一覧を表示する。
	 * 要求を出した後にプラットフォームの選択が変わっていた `seed` は捨てる（A→B→A と戻った場合も。値の一致ではなく
	 * 選択の番号で比べる。`.claude/rules/frontend.md`）。開始・Clear・キャンセルなど、一覧が古くなりうる操作のたびに呼ぶ。
	 */
	refresh: ( seed?: ActiveRunsSeed ) => void;
}

/**
 * プラットフォームで進行中の run を `GET /runs?platform=&status=active` で照会する（R3-0i・issue #70）。
 * run_id がブラウザに届かなかった run・別ブラウザで始めた run・失敗で止まった run を見つけるために使う。
 *
 * - 一覧を書き換えるのは取得関数（`fetchRuns()`）だけで、世代カウンタもその中で進める
 *   （`.claude/rules/frontend.md`。409 の一覧も `seed` として同じ経路で反映する）。
 * - 一覧は取得したプラットフォームと組で持ち、選択中のプラットフォームと違う一覧は返さない
 *   （切り替え直後の 1 回の描画で前のプラットフォームの run を取り込まないため）。
 * - 照会は続ける: 追跡していない run がある間は 5 秒ごと（追跡中の run は `useRunPolling` が 2 秒ごとに見ている）、
 *   無い間は 30 秒ごと。取得に失敗したら直前の一覧を残したまま再試行する（4xx は 60 秒後）。
 *
 * @param platform      選択中のプラットフォーム
 * @param trackedRunIds 呼び出し側のセクションが表示中の run_id
 */
export function useActiveRuns(
	platform: string | null,
	trackedRunIds: Array< string | null >
): ActiveRunsResult {
	const [ state, setState ] = useState< ActiveRunsState >( EMPTY_STATE );
	// 取得要求の世代（古い応答を捨てる）。
	const requestRef = useRef( 0 );
	// 反映した一覧の版（`ActiveRunsState::generation`）。
	const versionRef = useRef( 0 );
	// プラットフォームの選択の番号（409 の seed が今の選択のものかを比べる）。
	const selectionRef = useRef( 0 );
	const timerRef = useRef< number | null >( null );
	const platformRef = useRef( platform );
	platformRef.current = platform;
	const mountedRef = useRef( true );

	useEffect( () => {
		mountedRef.current = true;

		return () => {
			mountedRef.current = false;

			if ( null !== timerRef.current ) {
				window.clearTimeout( timerRef.current );
				timerRef.current = null;
			}
		};
	}, [] );

	const fetchRuns = useCallback( ( seed?: ActiveRunsSeed ) => {
		const target = platformRef.current;
		const requestId = ++requestRef.current;
		// 要求を出した後にプラットフォームの選択が変わっていたら、その 409 の一覧は今の選択のものではない。
		const seedRuns =
			undefined !== seed && seed.selection === selectionRef.current
				? seed.runs
				: undefined;

		if ( null !== timerRef.current ) {
			window.clearTimeout( timerRef.current );
			timerRef.current = null;
		}

		if ( null === target ) {
			setState( EMPTY_STATE );

			return;
		}

		const seedVersion = undefined === seedRuns ? 0 : ++versionRef.current;

		setState( ( prev ) => {
			const base =
				prev.platform === target
					? prev
					: { ...EMPTY_STATE, platform: target };

			// 409 の一覧はサーバーが今返した値なので、取り込みに使ってよい（stale にしない）。
			return undefined === seedRuns
				? { ...base, stale: true }
				: {
						platform: target,
						runs: seedRuns,
						stale: false,
						generation: seedVersion,
				  };
		} );

		apiFetch< { runs?: unknown } >( {
			path: `/cbjp/v1/runs?platform=${ encodeURIComponent(
				target
			) }&status=active`,
		} )
			.then( ( data ) => {
				if (
					! mountedRef.current ||
					requestRef.current !== requestId
				) {
					return;
				}

				setState( {
					platform: target,
					runs: parseActiveRuns( data?.runs ),
					stale: false,
					generation: ++versionRef.current,
				} );
			} )
			.catch( ( err: unknown ) => {
				if (
					! mountedRef.current ||
					requestRef.current !== requestId
				) {
					return;
				}

				// 一時的な失敗で一覧（＝止めているボタン・案内）を消さない。古いままの印を残して再試行する。
				// 4xx（権限が無い・プラットフォームが登録されていない等）はすぐには直らないので、5 秒ごとに叩かず
				// 間隔を空ける（止めてしまうと stale のまま固まり、取り込みも案内の更新も止まる。R2-3）。
				const status = ( err as { data?: { status?: unknown } } )?.data
					?.status;
				const clientError =
					'number' === typeof status && status >= 400 && status < 500;

				timerRef.current = window.setTimeout(
					() => fetchRuns(),
					clientError ? CLIENT_ERROR_RETRY_MS : POLL_INTERVAL_MS
				);
			} );
	}, [] );

	useEffect( () => {
		// 選択が変わった。これより前に出した要求の 409 の一覧は、もう反映しない（`refresh()`）。
		selectionRef.current += 1;
		fetchRuns();
	}, [ platform, fetchRuns ] );

	const current = state.platform === platform;
	const runs = current ? state.runs : NO_RUNS;
	const stale = current ? state.stale : true;
	const trackedKey = trackedRunIds.join( '\n' );
	const hasUntracked = untrackedRuns( runs, trackedRunIds ).length > 0;

	// 一覧を取り直し続ける。追跡していない run がある間は、終わった（キャンセルされた）ことを早く反映するため短い間隔で。
	useEffect( () => {
		if ( ! current || stale ) {
			return;
		}

		const timer = window.setTimeout(
			() => fetchRuns(),
			hasUntracked ? POLL_INTERVAL_MS : IDLE_POLL_INTERVAL_MS
		);

		return () => window.clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ current, stale, hasUntracked, state.generation, trackedKey ] );

	return {
		runs,
		stale,
		loaded: current && state.generation > 0,
		generation: current ? state.generation : 0,
		selectionRef,
		refresh: fetchRuns,
	};
}
