import type { RunType } from './types';

/**
 * Import/Export タブが直前の run_id を控える localStorage のキー。実行中の run は Action Scheduler 側で
 * 進むため、管理画面をリロードしてもこの run_id からポーリングを再開できる（タブ内だけの UI 都合の値で、
 * サーバー側の正としては扱わない。控えが無い・別ブラウザの run は `GET /runs?platform=` で見つける。
 * R3-0i・issue #70）。キーの形式は既存の控えを読み続けられるよう変えない。
 * @param platform
 * @param type
 */
export function runStorageKey( platform: string, type: RunType ): string {
	return `cbjp_run_${ type }_${ platform }`;
}

export function loadStoredRunId(
	platform: string,
	type: RunType
): string | null {
	try {
		return window.localStorage.getItem( runStorageKey( platform, type ) );
	} catch {
		return null;
	}
}

export function storeRunId(
	platform: string,
	type: RunType,
	runId: string
): void {
	try {
		window.localStorage.setItem( runStorageKey( platform, type ), runId );
	} catch {
		// プライベートブラウジング等で localStorage が使えなくても実行自体は継続できる。
	}
}

export function clearStoredRunId( platform: string, type: RunType ): void {
	try {
		window.localStorage.removeItem( runStorageKey( platform, type ) );
	} catch {
		// 何もしない（保存できていないなら消す必要もない）。
	}
}
