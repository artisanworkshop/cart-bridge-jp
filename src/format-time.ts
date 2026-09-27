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
