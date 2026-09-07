import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '../api';
import type { Run } from '../types';

const POLL_INTERVAL_MS = 2000;

const TERMINAL_STATUSES = [ 'completed', 'failed', 'cancelled' ];

export function isRunTerminal( run: Run ): boolean {
	return run.jobs.every( ( job ) =>
		TERMINAL_STATUSES.includes( job.status )
	);
}

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

/**
 * `RestController::get_run()`が404で返す`cbjp_run_not_found`（`WP_Error`のcode）を
 * 判定する。タイムアウト等の一時的なエラーと違い、この run_id は今後も解決し得ない
 * ことが確定しているシグナルとして扱える。
 * @param err
 */
function isRunNotFoundError( err: unknown ): boolean {
	return 'cbjp_run_not_found' === ( err as { code?: string } )?.code;
}

/**
 * `run_id` の進捗を2秒間隔でポーリングする（03 §6「UIが2秒間隔でポーリング」）。
 * 全ジョブが終端状態になったら自動停止する。`retry()`でジョブを再実行させた
 * 直後など、既に終端になったrunを明示的に再開したい場合は `refetch()` を使う
 * （setTimeoutの連鎖なのでオーバーラップしない）。
 * @param runId
 */
export function useRunPolling( runId: string | null ) {
	const [ run, setRun ] = useState< Run | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	// 一時的なポーリングエラー（タイムアウト等。自動リトライで自己解決しうる）と、
	// このrun_idがもう存在しないと判明した確定的なエラーを区別する。「Clear」ボタンの
	// 有効化はterminalかこのフラグの場合のみに限定し、一時的な通信エラーだけで
	// 実行中のrunを誤って手放せないようにするため。
	const [ notFound, setNotFound ] = useState( false );
	const timerRef = useRef< number | null >( null );
	const runIdRef = useRef( runId );
	runIdRef.current = runId;
	// コンポーネントが完全にアンマウントされた後も、送信済みの`apiFetch`が解決した
	// 時点でタイマーを再スケジュールし続けないようにするガード（`runId`の変更による
	// クリーンアップでは`true`のまま。真の型アンマウント時のみ`false`にする）。
	const mountedRef = useRef( true );

	useEffect( () => {
		mountedRef.current = true;

		return () => {
			mountedRef.current = false;
		};
	}, [] );

	const clearTimer = useCallback( () => {
		if ( null !== timerRef.current ) {
			window.clearTimeout( timerRef.current );
			timerRef.current = null;
		}
	}, [] );

	const poll = useCallback( () => {
		const id = runIdRef.current;

		if ( null === id ) {
			return;
		}

		apiFetch< Run >( { path: `/cbjp/v1/runs/${ id }` } )
			.then( ( data ) => {
				if ( ! mountedRef.current || runIdRef.current !== id ) {
					return;
				}

				setRun( data );
				setError( null );
				setNotFound( false );
				clearTimer();

				if ( ! isRunTerminal( data ) ) {
					timerRef.current = window.setTimeout(
						poll,
						POLL_INTERVAL_MS
					);
				}
			} )
			.catch( ( err: unknown ) => {
				if ( ! mountedRef.current || runIdRef.current !== id ) {
					return;
				}

				// 一時的なネットワークエラーでポーリングを永久停止させない。次回成功時に
				// エラー表示は自動でクリアされる（成功分岐の`setError(null)`参照）。
				setError( errorMessage( err ) );
				setNotFound( isRunNotFoundError( err ) );
				clearTimer();
				timerRef.current = window.setTimeout( poll, POLL_INTERVAL_MS );
			} );
	}, [ clearTimer ] );

	useEffect( () => {
		setRun( null );
		setError( null );
		setNotFound( false );
		clearTimer();

		if ( runId ) {
			poll();
		}

		return clearTimer;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ runId ] );

	return { run, error, notFound, refetch: poll };
}
