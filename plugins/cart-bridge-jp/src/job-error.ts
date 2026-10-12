import { __, sprintf } from '@wordpress/i18n';
import { joinSentences } from './i18n';

/**
 * 失敗したジョブの理由（`GET /runs/{run_id}` の `jobs[].error`。サーバーの `error_json`）を画面の文にする純粋関数。
 * 値はページの外から来るので形を確かめ、読めなければ汎用の文にする。
 */

/**
 * Action Scheduler のアクションを失った（停止中に失敗にされた等）ジョブ（`Sync\OrphanedRunRecovery::ERROR_ACTION_LOST`。R3-9）。
 */
export const ERROR_ACTION_LOST = 'action_lost';

/**
 * PHP の致命的エラーで止まったジョブ（`Sync\OrphanedRunRecovery::ERROR_FATAL`。message は PHP のエラーの 1 行目）。
 */
export const ERROR_FATAL = 'fatal_error';

/**
 * @param error `jobs[].error` の値
 * @return 失敗したジョブの下に出す文
 */
export function jobErrorMessage( error: unknown ): string {
	const record =
		null !== error && 'object' === typeof error && ! Array.isArray( error )
			? ( error as Record< string, unknown > )
			: {};
	const code = 'string' === typeof record.code ? record.code : '';
	const message = 'string' === typeof record.message ? record.message : '';

	if ( ERROR_ACTION_LOST === code ) {
		return __(
			'This job stopped because the background task that was processing it was lost (for example, the plugin was deactivated during the run, or PHP stopped while processing it). Retry to continue from where it stopped.',
			'cart-bridge-jp'
		);
	}

	if ( ERROR_FATAL === code ) {
		const explanation = __(
			'This job stopped because PHP ended with a fatal error while processing it. Retry to continue from where it stopped. If it stops again, ask your hosting provider about the error (for example, the memory or time limit).',
			'cart-bridge-jp'
		);

		return '' === message
			? explanation
			: joinSentences(
					explanation,
					sprintf(
						/* translators: %s: the PHP error message, e.g. "Allowed memory size of 134217728 bytes exhausted" */
						__( 'PHP error: %s', 'cart-bridge-jp' ),
						message
					)
			  );
	}

	return '' === message
		? __( 'This job failed.', 'cart-bridge-jp' )
		: message;
}
