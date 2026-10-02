import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '../api';
import { parseActiveRuns, untrackedRuns } from '../active-runs';
import type { ActiveRun } from '../types';

const POLL_INTERVAL_MS = 5000;

/** 一覧が無いときに返す値（描画のたびに新しい配列を作り、利用側の effect を毎回走らせないため）。 */
const NO_RUNS: ActiveRun[] = [];

interface ActiveRunsState {
	/** この一覧を取得したプラットフォーム（null は未取得）。 */
	platform: string | null;
	runs: ActiveRun[];
	/** 取り直しを始めてから応答が届くまで true。古い一覧で run を取り込まないための印。 */
	stale: boolean;
	/** 一覧を取得できた回数（取り込み直しの判定を一覧ごとに 1 回へ絞るために使う）。 */
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
	 * 一覧を取り直す。409 の `active_runs` を `seed` に渡すと、応答を待たずにその一覧を表示する。
	 * 開始・Clear・キャンセル・終了など、一覧が古くなりうる操作のたびに呼ぶ。
	 */
	refresh: ( seed?: ActiveRun[] ) => void;
}

/**
 * プラットフォームで進行中の run を `GET /runs?platform=&status=active` で照会する（R3-0i・issue #70）。
 * run_id がブラウザに届かなかった run・別ブラウザで始めた run・失敗で止まった run を見つけるために使う。
 *
 * - 一覧を書き換えるのは取得関数（`fetchRuns()`）だけで、世代カウンタもその中で進める
 *   （`.claude/rules/frontend.md`。409 の一覧も `seed` として同じ経路で反映する）。
 * - 一覧は取得したプラットフォームと組で持ち、選択中のプラットフォームと違う一覧は返さない
 *   （切り替え直後の 1 回の描画で前のプラットフォームの run を取り込まないため）。
 * - 5 秒ごとの照会は、呼び出し側が追跡していない run があるときだけ行う（追跡中の run は
 *   `useRunPolling` が 2 秒ごとに見ている）。取得に失敗したら直前の一覧を残したまま再試行する。
 *
 * @param platform      選択中のプラットフォーム
 * @param trackedRunIds 呼び出し側のセクションが表示中の run_id
 */
export function useActiveRuns(
	platform: string | null,
	trackedRunIds: Array< string | null >
): ActiveRunsResult {
	const [ state, setState ] = useState< ActiveRunsState >( EMPTY_STATE );
	const generationRef = useRef( 0 );
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

	const fetchRuns = useCallback( ( seed?: ActiveRun[] ) => {
		const target = platformRef.current;
		const requestId = ++generationRef.current;

		if ( null !== timerRef.current ) {
			window.clearTimeout( timerRef.current );
			timerRef.current = null;
		}

		if ( null === target ) {
			setState( EMPTY_STATE );

			return;
		}

		setState( ( prev ) => {
			const base =
				prev.platform === target
					? prev
					: { ...EMPTY_STATE, platform: target };

			// 409 の一覧はサーバーが今返した値なので、取り込みに使ってよい（stale にしない）。
			return undefined === seed
				? { ...base, stale: true }
				: {
						platform: target,
						runs: seed,
						stale: false,
						generation: base.generation + 1,
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
					generationRef.current !== requestId
				) {
					return;
				}

				setState( ( prev ) => ( {
					platform: target,
					runs: parseActiveRuns( data?.runs ),
					stale: false,
					generation:
						( prev.platform === target ? prev.generation : 0 ) + 1,
				} ) );
			} )
			.catch( () => {
				if (
					! mountedRef.current ||
					generationRef.current !== requestId
				) {
					return;
				}

				// 一時的な失敗で一覧（＝止めているボタン・案内）を消さない。古いままの印を残して再試行する。
				timerRef.current = window.setTimeout(
					() => fetchRuns(),
					POLL_INTERVAL_MS
				);
			} );
	}, [] );

	useEffect( () => {
		fetchRuns();
	}, [ platform, fetchRuns ] );

	const current = state.platform === platform;
	const runs = current ? state.runs : NO_RUNS;
	const stale = current ? state.stale : true;
	const trackedKey = trackedRunIds.join( '\n' );
	const hasUntracked = untrackedRuns( runs, trackedRunIds ).length > 0;

	// 追跡していない run がある間だけ、終わったか（キャンセルされたか）を照会し続ける。
	useEffect( () => {
		if ( ! current || stale || ! hasUntracked ) {
			return;
		}

		const timer = window.setTimeout( () => fetchRuns(), POLL_INTERVAL_MS );

		return () => window.clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ current, stale, hasUntracked, state.generation, trackedKey ] );

	return {
		runs,
		stale,
		loaded: current && state.generation > 0,
		generation: current ? state.generation : 0,
		refresh: fetchRuns,
	};
}
