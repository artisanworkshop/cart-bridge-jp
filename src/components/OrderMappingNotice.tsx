import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import apiFetch from '../api';
import type { SettingsMappings } from '../types';
import {
	hasOrderMappingGaps,
	orderMappingStatus,
	type OrderMappingStatus,
} from './mapping-status';

interface OrderMappingNoticeProps {
	platform: string;
	/** 受注がインポート対象に選ばれているか。選ばれていないときは取得も表示もしない。 */
	active: boolean;
}

/**
 * Import タブの事前チェック（R3-0m）: 受注を取り込むときに使う決済・配送マッピングのうち、未設定の数を案内し、
 * Mappings タブへのリンクを出す。**案内だけで Preview / Run import は止めない**（未マッピングでも受注は
 * 取り込めるのが現行の設計で、書込み側は未マッピングを空の決済/配送方法と警告に倒す）。
 *
 * 候補の取得（ColorMe は `categories.json`/`payments.json`/`deliveries.json` の 3 コール）は、受注が選ばれて
 * いる間に platform ごとに 1 回だけ行う。受注のチェックを付け外しするたびには呼ばない。Mappings タブで保存して
 * 戻ってきたときは、タブの切り替えでこのコンポーネントがマウントし直されるので取り直す。
 * 取得に失敗したとき（未接続・レート制限など）は何も出さない（誤った警告を出さない）。
 * @param root0
 * @param root0.platform
 * @param root0.active
 */
export default function OrderMappingNotice( {
	platform,
	active,
}: OrderMappingNoticeProps ) {
	const [ status, setStatus ] = useState< OrderMappingStatus | null >( null );
	// platform が変わるたびに進める世代カウンタ。古い platform への応答を捨てる（`.claude/rules/frontend.md`）。
	const generationRef = useRef( 0 );
	// 今の世代で取得を始めたか。
	const requestedRef = useRef( false );

	// **取得 effect より前に置くこと**: 同じコミットで両方が走るとき、先に世代を進めて取得済みの印を外す。
	useEffect( () => {
		++generationRef.current;
		requestedRef.current = false;
		setStatus( null );
	}, [ platform ] );

	useEffect( () => {
		if ( ! active || requestedRef.current ) {
			return;
		}

		requestedRef.current = true;

		const requestId = generationRef.current;

		apiFetch< SettingsMappings >( {
			path: `/cbjp/v1/settings/mappings/${ encodeURIComponent(
				platform
			) }`,
		} )
			.then( ( data ) => {
				if ( generationRef.current !== requestId ) {
					return;
				}

				setStatus( orderMappingStatus( data ) );
			} )
			.catch( () => {
				// 案内は付加情報のため、取得に失敗したら何も出さない。
			} );
	}, [ platform, active ] );

	if ( ! active || null === status || ! hasOrderMappingGaps( status ) ) {
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
					'Some payment or shipping methods on the platform are not mapped to WooCommerce yet.',
					'cart-bridge-jp'
				) }
			</p>
			<ul>
				{ status.payment.unmapped > 0 && (
					<li>
						{ sprintf(
							/* translators: 1: number of unmapped payment methods, 2: number of payment methods on the platform */
							__(
								'Payment methods not mapped: %1$d of %2$d',
								'cart-bridge-jp'
							),
							status.payment.unmapped,
							status.payment.total
						) }
					</li>
				) }
				{ status.shipping.unmapped > 0 && (
					<li>
						{ sprintf(
							/* translators: 1: number of unmapped shipping methods, 2: number of shipping methods on the platform */
							__(
								'Shipping methods not mapped: %1$d of %2$d',
								'cart-bridge-jp'
							),
							status.shipping.unmapped,
							status.shipping.total
						) }
					</li>
				) }
			</ul>
			<p>
				{ __(
					'Orders are still imported, but an order that uses an unmapped method gets an empty WooCommerce payment or shipping method and a warning in the preview (dry run) report. Set up the mappings before importing. Orders imported while a method was unmapped are updated the next time they are imported.',
					'cart-bridge-jp'
				) }
			</p>
			<p>
				<a href="#/mappings">
					{ __(
						'Set up the mappings on the Mappings tab',
						'cart-bridge-jp'
					) }
				</a>
			</p>
		</Notice>
	);
}
