import apiFetch from '@wordpress/api-fetch';

declare global {
	interface Window {
		cbjpAdmin?: {
			restUrl: string;
			restNonce: string;
			/** WordPress のユーザーの言語（`ja`・`en-US`）。日時・金額の書式に使う（`displayLocale()`）。 */
			locale?: string;
		};
	}
}

export function configureApiFetch(): void {
	const settings = window.cbjpAdmin;

	if ( ! settings ) {
		return;
	}

	apiFetch.use( apiFetch.createRootURLMiddleware( settings.restUrl ) );
	apiFetch.use( apiFetch.createNonceMiddleware( settings.restNonce ) );
}

export default apiFetch;
