import type { EntityType } from './types';

/**
 * dry-runレポートCSV（`GET /runs/{run_id}/report`）のダウンロードURLを組み立てる。
 * `<a href>`はカスタムヘッダー（`X-WP-Nonce`）を送れないため、cookie認証が
 * 受け付ける`?_wpnonce=`クエリパラメータを使う（`RestController::get_run_report()`参照）。
 *
 * パーマリンク設定が「基本」（プリティパーマリンク無効）のサイトでは`rest_url()`が
 * `https://example.com/?rest_route=/`のようなクエリ文字列ベースのURLを返す。この場合に
 * 単純な文字列連結でパスとクエリを継ぎ足すと、ルートパスと`_wpnonce`等のクエリが
 * `rest_route`パラメータの値の一部として解釈されてしまい、ダウンロードが壊れる。
 * `URL`/`URLSearchParams`でクエリ文字列ベースかパスベースかを判定し、前者は
 * `rest_route`の値へルートパスを継ぎ足す。
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
	const route = `cbjp/v1/runs/${ runId }/report`;

	const url = new URL( restUrl, window.location.origin );

	if ( url.searchParams.has( 'rest_route' ) ) {
		url.searchParams.set(
			'rest_route',
			( url.searchParams.get( 'rest_route' ) ?? '' ) + route
		);
	} else {
		url.pathname += route;
	}

	if ( options.entity ) {
		url.searchParams.set( 'entity', options.entity );
	}

	if ( options.onlyWarnings ) {
		url.searchParams.set( 'only_warnings', '1' );
	}

	url.searchParams.set( '_wpnonce', nonce );

	return url.toString();
}
