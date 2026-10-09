import type { CleanupPreview } from '../types';

/**
 * サンプルクリーンアップ（Tools タブ）の実行ボタンを止めるか。
 *
 * 削除（`SampleCleanup::run()`）はプレビューの件数と関係なく、その時点の mapping をすべて対象にする。
 * プレビューの件数は確認の材料なので、run と重なったプレビューで実行させない（R3-0i R1-1）:
 * - プレビュー時点で run が進行中だった（`run_in_progress`）。run の途中の件数は過少で、件数 0 なら
 *   確認ダイアログも出ないまま、run が書いたデータを消してしまう。
 * - 今、run が進行中（`GET /runs?platform=` の一覧）。サーバーも 409 で拒否する。
 * - プレビューを取ったあとに run を見た（`previewOutdated`）。その run が書いたデータが件数に入っていない。
 * いずれもプレビューを取り直せば解ける（run が終わっていれば）。
 * @param preview         直近のプレビュー（未取得は null）
 * @param runInProgress   今、進行中の run があるか
 * @param previewOutdated プレビューのあとに進行中の run を見たか
 */
export function isCleanupBlocked(
	preview: Pick<
		CleanupPreview,
		'run_in_progress' | 'requires_delete_users' | 'can_delete_users'
	> | null,
	runInProgress: boolean,
	previewOutdated: boolean
): boolean {
	if ( null === preview ) {
		return false;
	}

	return (
		preview.run_in_progress ||
		runInProgress ||
		previewOutdated ||
		( preview.requires_delete_users && ! preview.can_delete_users )
	);
}
