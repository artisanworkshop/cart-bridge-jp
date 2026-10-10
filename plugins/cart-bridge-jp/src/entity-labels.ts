import type { EntityType } from './types';

/**
 * 実体の種類（とリンク再構築の対象〔`variant` など〕）の表示名。サーバーが `cbjpAdmin.entityLabels` に入れて渡す
 * （`Admin\Assets::enqueue()`・`EntityTypeRegistry::labels()`。Pro アドオンが足す種類も入る。R3-6b2）。
 *
 * ページの外から渡る値なので、オブジェクトで値が空でない文字列の項目だけを使う（`__proto__` などの継承プロパティも読まない）。
 * 名前が無いキーはキーのまま出す（画面を落とさない）。
 */
export function entityLabels(): Record< EntityType, string > {
	const raw: unknown = window.cbjpAdmin?.entityLabels;
	// 継承プロパティの無いオブジェクト（`constructor` などのキーを名前として拾わない）。
	const labels = Object.create( null ) as Record< EntityType, string >;

	if ( null === raw || 'object' !== typeof raw || Array.isArray( raw ) ) {
		return labels;
	}

	for ( const [ key, value ] of Object.entries( raw ) ) {
		if ( 'string' === typeof value && '' !== value ) {
			labels[ key ] = value;
		}
	}

	return labels;
}

/**
 * 1 つの種類の表示名（無ければキー）。
 * @param key
 */
export function entityLabel( key: EntityType ): string {
	const labels = entityLabels();

	return Object.prototype.hasOwnProperty.call( labels, key )
		? labels[ key ]
		: key;
}
