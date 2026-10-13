import { __, sprintf } from '@wordpress/i18n';

/**
 * OAuth のコールバック（`/connect/{platform}/callback`）が Connections タブへ戻すときの結果（`?cbjp_connected=` / `?cbjp_connect_error=`）を
 * 画面の文にする純粋関数（R3-5b）。値は URL から来るので誰でも作れる: 文は URL から取らず、決まったコードを訳した文だけを出す
 * （任意の文を通知に出させない）。
 */

/**
 * 失敗のコード。`Admin\RestController::OAUTH_ERROR_CODES` と同じ一覧にする（知らないコードは汎用の文）。
 */
export const OAUTH_ERROR_CODES = [
	'unknown_platform',
	'state_invalid',
	'denied',
	'rejected',
	'network',
	'unavailable',
	'credentials_changed',
	'credentials_unreadable',
	'unexpected',
] as const;

export type OAuthStatus =
	| { status: 'success'; platform: string }
	| { status: 'error'; code: string };

/**
 * @param params ページの URL のクエリ
 * @return 結果。どちらのパラメータも無い（空）なら null
 */
export function parseOAuthStatus(
	params: URLSearchParams
): OAuthStatus | null {
	const error = params.get( 'cbjp_connect_error' );

	// 空の値は無いものとして扱う（以前と同じ）。
	if ( error ) {
		return { status: 'error', code: error };
	}

	const connected = params.get( 'cbjp_connected' );

	if ( connected ) {
		return { status: 'success', platform: connected };
	}

	return null;
}

/**
 * @param code `cbjp_connect_error` の値
 * @return 通知の文
 */
export function oauthErrorMessage( code: string ): string {
	switch ( code ) {
		case 'unknown_platform':
			return __(
				'The connection request was for a platform that this plugin does not support.',
				'cart-bridge-jp'
			);
		case 'state_invalid':
			return __(
				'The connection request could not be verified. Please try again, or use the manual code entry fallback.',
				'cart-bridge-jp'
			);
		case 'denied':
			return __(
				'Access was not allowed on the platform. To connect, start connecting again and allow access.',
				'cart-bridge-jp'
			);
		case 'rejected':
			return __(
				'The platform rejected the connection request. Check the Client ID, the Client Secret, and the redirect URI registered for your app, then connect again.',
				'cart-bridge-jp'
			);
		case 'network':
			return __(
				'Your site could not reach the platform’s server. Please try connecting again later.',
				'cart-bridge-jp'
			);
		case 'unavailable':
			return __(
				'The platform’s server is busy or unavailable. Please wait a few minutes, then connect again.',
				'cart-bridge-jp'
			);
		case 'credentials_changed':
			return __(
				'The saved Client ID and Client Secret are missing or were changed while connecting. Save them again, then connect.',
				'cart-bridge-jp'
			);
		case 'credentials_unreadable':
			return __(
				'The saved credentials cannot be read. Click “Clear saved credentials”, enter the Client ID and Client Secret again, click “Save settings”, then connect.',
				'cart-bridge-jp'
			);
		default:
			return __(
				'The connection could not be completed. Please try again, or use the manual code entry fallback.',
				'cart-bridge-jp'
			);
	}
}

/**
 * @param notice      コールバックの結果
 * @param connections 接続一覧（`GET /connections`）。成功の文の表示名を引く
 * @return 通知の文
 */
export function oauthNoticeMessage(
	notice: OAuthStatus,
	connections: ReadonlyArray< { platform: string; label: string } >
): string {
	if ( 'error' === notice.status ) {
		return oauthErrorMessage( notice.code );
	}

	// 一覧に無い値は URL の文字なので出さない。
	const label = connections.find(
		( connection ) => connection.platform === notice.platform
	)?.label;

	return undefined === label
		? __( 'Connected.', 'cart-bridge-jp' )
		: sprintf(
				/* translators: %s: the name of the shop, or of the platform (e.g. "Color Me Shop") */
				__( 'Connected to %s.', 'cart-bridge-jp' ),
				label
		  );
}
