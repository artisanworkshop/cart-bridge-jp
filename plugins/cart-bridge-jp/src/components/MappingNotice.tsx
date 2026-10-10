import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import apiFetch from '../api';
import { tabHref } from '../hash-route';
import { importMappingGaps } from './mapping-status';

interface MappingNoticeProps {
	platform: string;
	/**
	 * 選ばれた実体の種類のうち、取込みの前に案内するマッピングを持つもの（`entities.import[].mapping_notice`）が
	 * あるか。無いときは取得も表示もしない。
	 */
	active: boolean;
	/** 選ばれた実体の種類（案内はこれらが持つマッピングだけ）。 */
	selected: ReadonlySet< string >;
}

/**
 * Import タブの事前チェック（R3-0m。R3-6b2 で種類の宣言から組み立てる形にした）: 選ばれた種類が持つマッピング（受注の決済・配送など）の
 * うち、未設定の数を案内し、Mappings タブへのリンクを出す。**案内だけで Preview / Run import は止めない**（未設定でも取り込めるのが
 * 今の設計で、書込み側は未設定を空の値と警告に倒す。`MappingKind::import_notice()`）。
 *
 * 候補の取得（ColorMe は `categories.json`/`payments.json`/`deliveries.json` の 3 コール）は、案内の要る種類が選ばれている間に
 * platform ごとに 1 回だけ行う。選択を付け外しするたびには呼ばない（数えるのは取得した応答と今の選択から）。Mappings タブで保存して
 * 戻ってきたときは、タブの切り替えでこのコンポーネントがマウントし直されるので取り直す。
 * 取得に失敗したとき（未接続・レート制限など）は何も出さない（誤った警告を出さない）。
 * @param root0
 * @param root0.platform
 * @param root0.active
 * @param root0.selected
 */
export default function MappingNotice( {
	platform,
	active,
	selected,
}: MappingNoticeProps ) {
	const [ data, setData ] = useState< unknown >( null );
	// platform が変わるたびに進める世代カウンタ。古い platform への応答を捨てる（`.claude/rules/frontend.md`）。
	const generationRef = useRef( 0 );
	// 今の世代で取得を始めたか。
	const requestedRef = useRef( false );

	// **取得 effect より前に置くこと**: 同じコミットで両方が走るとき、先に世代を進めて取得済みの印を外す。
	useEffect( () => {
		++generationRef.current;
		requestedRef.current = false;
		setData( null );
	}, [ platform ] );

	useEffect( () => {
		if ( ! active || requestedRef.current ) {
			return;
		}

		requestedRef.current = true;

		const requestId = generationRef.current;

		apiFetch< unknown >( {
			path: `/cbjp/v1/settings/mappings/${ encodeURIComponent(
				platform
			) }`,
		} )
			.then( ( response ) => {
				if ( generationRef.current !== requestId ) {
					return;
				}

				setData( response );
			} )
			.catch( () => {
				// 案内は付加情報のため、取得に失敗したら何も出さない。
			} );
	}, [ platform, active ] );

	if ( ! active || null === data ) {
		return null;
	}

	const gaps = importMappingGaps( data, selected );

	if ( 0 === gaps.length ) {
		return null;
	}

	return (
		<Notice
			status="warning"
			isDismissible={ false }
			className="cbjp-import__mapping-notice"
		>
			<p>
				{ __(
					'Some values on the platform are not mapped to WooCommerce yet.',
					'cart-bridge-jp'
				) }
			</p>
			<ul>
				{ gaps.map( ( gap ) => (
					<li key={ gap.key }>
						{ sprintf(
							/* translators: 1: what is mapped, e.g. "Platform payment method", 2: number of unmapped values, 3: number of values on the platform */
							__(
								'%1$s: %2$d of %3$d not mapped',
								'cart-bridge-jp'
							),
							gap.heading,
							gap.unmapped,
							gap.total
						) }
					</li>
				) ) }
			</ul>
			<p>
				{ __(
					'Records that use an unmapped value are still imported, with a warning in the preview (dry run) report. Set up the mappings before importing. Records imported while a value was unmapped are updated the next time they are imported.',
					'cart-bridge-jp'
				) }
			</p>
			<p>
				<a href={ tabHref( 'mappings', platform ) }>
					{ __(
						'Set up the mappings on the Mappings tab',
						'cart-bridge-jp'
					) }
				</a>
			</p>
		</Notice>
	);
}
