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
 * ない項目と重複は捨て、真偽値は `true` だけを真と読む（原則 9。`beta` を読めなければベータ扱い〈既定で選ばない〉にする。
 * `dry_run_only`〈R3-8b〉も同じく、読めなければ dry-run だけ〈実際の取込み・エクスポートで送らない〉にする）。
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
 * 拡張が dry-run だけにした種類か（R3-8b）と、その理由。`false` のときだけ外す（読めない値は実際の書込みで送らない側に倒す）。
 * @param item
 */
function dryRunOnly( item: unknown ): {
	dry_run_only: boolean;
	dry_run_only_reason: string;
} {
	const reason = ownValue( item, 'dry_run_only_reason' );

	return {
		dry_run_only: false !== ownValue( item, 'dry_run_only' ),
		dry_run_only_reason: 'string' === typeof reason ? reason : '',
	};
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
			...dryRunOnly( item ),
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
			...dryRunOnly( item ),
		};
	} );
}

/**
 * 選んだ種類のうち、実際の取込み・エクスポートで送るもの（実行順。R3-8b）。拡張が dry-run だけにした種類は選んでいても外す
 * （プレビューには選んだ種類をすべて送る）。空なら実行のボタンを押せない（何も選んでいないときと同じ）。
 * @param options  選択肢（実行順）
 * @param selected 選んだ種類のキー
 */
export function writableOptions<
	T extends { key: EntityType; dry_run_only: boolean },
>( options: T[], selected: ReadonlySet< EntityType > ): T[] {
	return options.filter(
		( option ) => selected.has( option.key ) && ! option.dry_run_only
	);
}

/**
 * 空でない文を順につなぐ。どれも空なら出さない（undefined）。
 * @param candidates
 */
function joinNonEmpty( ...candidates: string[] ): string | undefined {
	const parts = candidates.filter( ( sentence ) => '' !== sentence );

	return 0 === parts.length
		? undefined
		: parts.reduce( ( joined, sentence ) =>
				joinSentences( joined, sentence )
		  );
}

/**
 * dry-run だけの種類の注記（画面の汎用の注記に、拡張の理由を続ける）。dry-run だけでなければ空。
 * @param option
 * @param dryRunOnlyNote
 */
function dryRunOnlyHelp(
	option: ImportEntityOption | ExportEntityOption,
	dryRunOnlyNote: string
): string {
	if ( ! option.dry_run_only ) {
		return '';
	}

	return joinNonEmpty( dryRunOnlyNote, option.dry_run_only_reason ) ?? '';
}

/**
 * 取込みの選択肢の説明。dry-run だけの種類にだけ出す（R3-8b）。
 * @param option
 * @param dryRunOnlyNote dry-run だけの種類の注記（Import タブが翻訳して渡す）
 */
export function importOptionHelp(
	option: ImportEntityOption,
	dryRunOnlyNote: string
): string | undefined {
	return joinNonEmpty( dryRunOnlyHelp( option, dryRunOnlyNote ) );
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
 * エクスポートの選択肢の説明。サーバーの説明（例: 受注は「接続先に売上を作る」）に、ベータならベータの注意書き、dry-run だけなら
 * その注記と理由を続ける（R3-8b）。どれも無ければ出さない（undefined）。
 * @param option
 * @param betaNote       ベータの注意書き（Export タブが翻訳して渡す。画像のアップロードと共通）
 * @param dryRunOnlyNote dry-run だけの種類の注記（Export タブが翻訳して渡す）
 */
export function exportOptionHelp(
	option: ExportEntityOption,
	betaNote: string,
	dryRunOnlyNote: string
): string | undefined {
	return joinNonEmpty(
		option.description,
		option.beta ? betaNote : '',
		dryRunOnlyHelp( option, dryRunOnlyNote )
	);
}
