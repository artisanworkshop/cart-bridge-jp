import { __, _n, sprintf } from '@wordpress/i18n';
import { joinSentences } from '../i18n';

/**
 * 県コード修復（Tools タブ）の Scan の結果の文。文は `joinSentences()` でつなぐ（`' '` を直に挟むと日本語訳で「。 」になる）。
 * @param needsRepair 直す記録がある（Scan の集計で `fixed` が 1 件以上）
 * @param toRepair    直す記録の件数
 * @param unresolved  確認できなかった・ASP から使える記録が返らなかった件数
 */
export function scanResultMessage(
	needsRepair: boolean,
	toRepair: number,
	unresolved: number
): string {
	const unresolvedMessage = sprintf(
		/* translators: %d: number of records that could not be confirmed or checked */
		_n(
			'%d record could not be confirmed or checked and will be left as it is. See the numbers below.',
			'%d records could not be confirmed or checked and will be left as they are. See the numbers below.',
			unresolved,
			'cart-bridge-jp'
		),
		unresolved
	);

	if ( ! needsRepair ) {
		return unresolved > 0
			? unresolvedMessage
			: __( 'No records need repair.', 'cart-bridge-jp' );
	}

	const repairMessage = sprintf(
		/* translators: %d: number of records that need repair */
		_n(
			'%d record needs repair. Review the numbers below, then click “Repair”.',
			'%d records need repair. Review the numbers below, then click “Repair”.',
			toRepair,
			'cart-bridge-jp'
		),
		toRepair
	);

	return unresolved > 0
		? joinSentences( repairMessage, unresolvedMessage )
		: repairMessage;
}

/**
 * 県コード修復の Repair の結果の文。最後に Scan をやり直すよう促す。
 * @param corrected  直した記録の件数
 * @param unresolved 確認できなかった・ASP から使える記録が返らなかった件数
 */
export function repairResultMessage(
	corrected: number,
	unresolved: number
): string {
	let message = sprintf(
		/* translators: %d: number of records corrected */
		_n(
			'Repair finished. %d record was corrected.',
			'Repair finished. %d records were corrected.',
			corrected,
			'cart-bridge-jp'
		),
		corrected
	);

	if ( unresolved > 0 ) {
		message = joinSentences(
			message,
			sprintf(
				/* translators: %d: number of records that could not be confirmed or checked */
				_n(
					'%d record could not be confirmed or checked and was left unchanged.',
					'%d records could not be confirmed or checked and were left unchanged.',
					unresolved,
					'cart-bridge-jp'
				),
				unresolved
			)
		);
	}

	return joinSentences(
		message,
		__(
			'Run “Scan” again to confirm that nothing is left.',
			'cart-bridge-jp'
		)
	);
}
