import type { EntityType } from './types';

/**
 * dry-runレポートCSV（`GET /runs/{run_id}/report`）のダウンロードURLを組み立てる。
 * `<a href>`はカスタムヘッダー（`X-WP-Nonce`）を送れないため、cookie認証が
 * 受け付ける`?_wpnonce=`クエリパラメータを使う（`RestController::get_run_report()`参照）。
 * @param runId
 * @param options
 * @param options.entity
 * @param options.onlyWarnings
 */
export function buildReportUrl(
	runId: string,
	options: { entity?: EntityType; onlyWarnings?: boolean } = {}
): string {
	const restUrl = window.cbjpAdmin?.restUrl ?? '';
	const nonce = window.cbjpAdmin?.restNonce ?? '';
	const params = new URLSearchParams();

	if ( options.entity ) {
		params.set( 'entity', options.entity );
	}

	if ( options.onlyWarnings ) {
		params.set( 'only_warnings', '1' );
	}

	params.set( '_wpnonce', nonce );

	return `${ restUrl }cbjp/v1/runs/${ runId }/report?${ params.toString() }`;
}
