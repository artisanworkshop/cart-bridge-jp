/**
 * `current_time( 'mysql', true )`（UTCの`Y-m-d H:i:s`。例: `2026-09-06 07:15:49`）で保存された
 * タイムスタンプを、閲覧者のローカル時刻の文字列に変換する。スペース区切りのまま`Date`に渡すと
 * ブラウザのローカル時刻として誤解釈されるため、ISO 8601のUTC表記に変換してから渡す
 * （サイトのタイムゾーン設定ではなく閲覧者のブラウザのタイムゾーンになるが、管理画面を見る
 * 本人が自分のローカル時刻で確認できることを優先する）。
 * `LogsTab.tsx`（`Support\Logger::write_to_db()`）と `PushIntentsPanel.tsx`
 * （`Sync\PushIntentRepository`）が共有する（どちらも`current_time('mysql', true)`由来）。
 * @param mysqlUtcDateTime
 */
export function formatUtcMysqlTime( mysqlUtcDateTime: string ): string {
	const date = new Date( `${ mysqlUtcDateTime.replace( ' ', 'T' ) }Z` );

	return isNaN( date.getTime() ) ? mysqlUtcDateTime : date.toLocaleString();
}

/**
 * 既にオフセット付きISO 8601（`DATE_ATOM`。例: `2026-01-01T00:00:00+00:00`）の日時文字列を、
 * 閲覧者のローカル時刻の文字列に変換する。`formatUtcMysqlTime()`と違いタイムゾーン情報を
 * 補う必要が無いため`Date`にそのまま渡す（`Woo\Reader\OrderReader`由来の`date_created`等）。
 * @param isoDateTime
 */
export function formatIsoTime( isoDateTime: string ): string {
	const date = new Date( isoDateTime );

	return isNaN( date.getTime() ) ? isoDateTime : date.toLocaleString();
}
