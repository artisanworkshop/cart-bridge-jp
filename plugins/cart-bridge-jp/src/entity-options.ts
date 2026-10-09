import { joinSentences } from './i18n';
import type {
	Connection,
	EntityType,
	ExportEntityOption,
	ImportEntityOption,
} from './types';

/**
 * 接続先ごとの取込み・エクスポートの選択肢（`GET /connections` の `entities`。R3-6b1）を読む純粋関数（R3-6b2）。
 * 画面は実体の名前を知らず、ここで読んだ選択肢（並びは実行順）から組み立てる。応答はページの外の値なので、キーが空でない文字列で
 * ない項目と重複は捨て、真偽値は `true` だけを真と読む（原則 9。`beta` を読めなければベータ扱い〈既定で選ばない〉にする）。
 */

function isRecord( value: unknown ): value is Record< string, unknown > {
	return (
		'object' === typeof value && null !== value && ! Array.isArray( value )
	);
}

function ownValue( record: unknown, key: string ): unknown {
	return isRecord( record ) &&
		Object.prototype.hasOwnProperty.call( record, key )
		? record[ key ]
		: undefined;
}

function optionList(
	connection: Connection | null,
	direction: 'import' | 'export'
): Array< { key: EntityType; label: string; item: unknown } > {
	const list = ownValue( ownValue( connection, 'entities' ), direction );

	if ( ! Array.isArray( list ) ) {
		return [];
	}

	const options: Array< { key: EntityType; label: string; item: unknown } > =
		[];
	const seen = new Set< string >();

	for ( const item of list as unknown[] ) {
		const key = ownValue( item, 'key' );

		if ( 'string' !== typeof key || '' === key || seen.has( key ) ) {
			continue;
		}

		seen.add( key );

		const label = ownValue( item, 'label' );

		options.push( {
			key,
			label: 'string' === typeof label && '' !== label ? label : key,
			item,
		} );
	}

	return options;
}

/**
 * 取り込める実体の種類（実行順）。
 * @param connection
 */
export function importEntityOptions(
	connection: Connection | null
): ImportEntityOption[] {
	return optionList( connection, 'import' ).map(
		( { key, label, item } ) => ( {
			key,
			label,
			mapping_notice: true === ownValue( item, 'mapping_notice' ),
		} )
	);
}

/**
 * エクスポートできる実体の種類（実行順）。
 * @param connection
 */
export function exportEntityOptions(
	connection: Connection | null
): ExportEntityOption[] {
	return optionList( connection, 'export' ).map( ( { key, label, item } ) => {
		const description = ownValue( item, 'description' );

		return {
			key,
			label,
			beta: false !== ownValue( item, 'beta' ),
			description: 'string' === typeof description ? description : '',
		};
	} );
}

/**
 * 既定で選ぶエクスポートの種類（ベータは店舗が明示的に選んだときだけ動かす。D24）。
 * @param options
 */
export function defaultExportSelection(
	options: ExportEntityOption[]
): EntityType[] {
	return options
		.filter( ( option ) => ! option.beta )
		.map( ( option ) => option.key );
}

/**
 * エクスポートの選択肢の説明。サーバーの説明（例: 受注は「接続先に売上を作る」）に、ベータならベータの注意書きを続ける。説明が無く
 * ベータでもなければ出さない（undefined）。
 * @param option
 * @param betaNote ベータの注意書き（Export タブが翻訳して渡す。画像のアップロードと共通）
 */
export function exportOptionHelp(
	option: ExportEntityOption,
	betaNote: string
): string | undefined {
	if ( option.beta ) {
		return '' !== option.description
			? joinSentences( option.description, betaNote )
			: betaNote;
	}

	return '' !== option.description ? option.description : undefined;
}
