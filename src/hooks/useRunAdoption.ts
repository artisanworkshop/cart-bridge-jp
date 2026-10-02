import { useEffect, useRef } from '@wordpress/element';
import { decideAdoption } from '../active-runs';
import type { ActiveRun, Run, RunType } from '../types';
import type { ActiveRunsResult } from './useActiveRuns';
import { isRunTerminal } from './useRunPolling';

interface RunAdoptionOptions {
	/** このセクションが扱う run の種別。 */
	type: RunType;
	runId: string | null;
	/** 開始の POST・Retry・キャンセルの応答待ち。 */
	busy: boolean;
	polling: {
		run: Run | null;
		notFound: boolean;
		refetch: () => void;
	};
	activeRuns: ActiveRunsResult;
	/**
	 * 見つけた run をこのセクションに取り込む。開始が成功したときと同じ後処理（run_id の控え・
	 * 前回の件数の破棄等）を行う。
	 */
	onAdopt: ( run: ActiveRun ) => void;
}

/**
 * 進行中の run の一覧（`useActiveRuns`）から、Import/Export タブのセクションに run を取り込む
 * （R3-0i・issue #70。判定は `decideAdoption()`）。
 *
 * セクションが「終了」と見ている run が一覧では進行中のとき（別ブラウザからの Retry で再開した、
 * または一覧のほうが古い）は、ポーリングと一覧の両方を取り直す。食い違いが続いても無限に繰り返さない
 * よう、取り直しは一覧 1 つにつき 1 回にする。
 *
 * **このフックはセクションの状態をプラットフォームごとに読み込み直す effect より後で呼ぶ**
 * （切り替え直後の描画で、前のプラットフォームのセクションの状態のまま判定しないため）。
 * @param options
 */
export function useRunAdoption( options: RunAdoptionOptions ): void {
	const { type, runId, busy, polling, activeRuns } = options;
	const onAdoptRef = useRef( options.onAdopt );
	onAdoptRef.current = options.onAdopt;
	const refetchRef = useRef( polling.refetch );
	refetchRef.current = polling.refetch;
	const refreshRef = useRef( activeRuns.refresh );
	refreshRef.current = activeRuns.refresh;
	const reconciledGenerationRef = useRef< number | null >( null );

	const loaded = null !== polling.run && polling.run.run_id === runId;
	const terminal = loaded && isRunTerminal( polling.run as Run );

	useEffect( () => {
		const decision = decideAdoption(
			{
				runId,
				busy,
				loaded,
				terminal,
				notFound: polling.notFound,
			},
			activeRuns.runs.filter( ( run ) => run.type === type ),
			activeRuns.stale
		);

		if ( 'adopt' === decision.kind ) {
			onAdoptRef.current( decision.run );
		} else if (
			'reconcile' === decision.kind &&
			reconciledGenerationRef.current !== activeRuns.generation
		) {
			reconciledGenerationRef.current = activeRuns.generation;
			refetchRef.current();
			refreshRef.current();
		}
	}, [
		type,
		runId,
		busy,
		loaded,
		terminal,
		polling.notFound,
		activeRuns.runs,
		activeRuns.stale,
		activeRuns.generation,
	] );
}
