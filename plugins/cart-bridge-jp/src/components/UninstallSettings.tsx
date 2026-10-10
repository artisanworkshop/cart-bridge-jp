import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
} from '@wordpress/components';
import apiFetch from '../api';
import { joinSentences } from '../i18n';
import type { UninstallSettings as Settings } from '../types';

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

/**
 * アンインストール時にプラグインのデータを消すかの設定（R3-5a。`GET/PUT /settings/uninstall`）。サイト全体で 1 つなので、
 * Tools タブのプラットフォームの選択・接続とは関係なく出す。
 *
 * `settings` は取得前と取得失敗のとき `null`（チェックボックスを止める。取得前の「オフに見える」状態で操作させない）。
 * 変更のたびに即保存し、保存中は止める（Export タブの画像の設定と同じ）。アンマウント後の応答は捨てる。
 */
export default function UninstallSettings() {
	const [ settings, setSettings ] = useState< Settings | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );
	const mountedRef = useRef( true );

	useEffect( () => {
		mountedRef.current = true;

		apiFetch< Settings >( { path: '/cbjp/v1/settings/uninstall' } )
			.then( ( data ) => {
				if ( mountedRef.current ) {
					setSettings( data );
				}
			} )
			.catch( ( err: unknown ) => {
				if ( mountedRef.current ) {
					setError( errorMessage( err ) );
				}
			} );

		return () => {
			mountedRef.current = false;
		};
	}, [] );

	async function save( checked: boolean ) {
		if ( null === settings || ! settings.can_change ) {
			return;
		}

		setSaving( true );
		setError( null );

		try {
			const data = await apiFetch< Settings >( {
				path: '/cbjp/v1/settings/uninstall',
				method: 'PUT',
				data: { delete_data: checked },
			} );

			if ( mountedRef.current ) {
				setSettings( data );
			}
		} catch ( err ) {
			if ( mountedRef.current ) {
				setError( errorMessage( err ) );
			}
		} finally {
			if ( mountedRef.current ) {
				setSaving( false );
			}
		}
	}

	return (
		<Card className="cbjp-tools__card cbjp-tools__uninstall">
			<CardHeader>
				<strong>{ __( 'Data on uninstall', 'cart-bridge-jp' ) }</strong>
			</CardHeader>
			<CardBody>
				<CheckboxControl
					label={ __(
						'Delete this plugin’s data when it is uninstalled',
						'cart-bridge-jp'
					) }
					help={ joinSentences(
						__(
							'When this is on, uninstalling the plugin deletes its own data: the saved connections, the links between the stores, the job history and dry-run reports, the logs, and the settings. The data that was migrated stays in WooCommerce.',
							'cart-bridge-jp'
						),
						__(
							'Deactivating the plugin does not delete anything.',
							'cart-bridge-jp'
						)
					) }
					checked={ settings?.delete_data ?? false }
					disabled={
						null === settings || ! settings.can_change || saving
					}
					onChange={ ( checked: boolean ) => void save( checked ) }
				/>

				{ null !== settings && ! settings.can_change && (
					<p className="description">
						{ __(
							'Only users who can delete plugins can change this setting.',
							'cart-bridge-jp'
						) }
					</p>
				) }

				{ true === settings?.delete_data && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Uninstalling the plugin will delete this data, and it cannot be restored. If you install the plugin again, the links to the items that were imported can be rebuilt with “Rebuild links”, but the links to the items that were exported cannot, so exporting those items again creates duplicates in the store you export to.',
							'cart-bridge-jp'
						) }
					</Notice>
				) }

				{ error && (
					// 取得に失敗した（`settings` が `null` のまま）ときは破棄しても再取得の手段が無く、チェックボックスが
					// 止まったまま何も出ない状態になる。破棄できるのは保存の失敗（設定は取得済みで、もう一度操作できる）のときだけ。
					<Notice
						status="error"
						isDismissible={ null !== settings }
						onRemove={ () => setError( null ) }
					>
						{ null === settings
							? joinSentences(
									error,
									__(
										'Reload the page to try again.',
										'cart-bridge-jp'
									)
							  )
							: error }
					</Notice>
				) }
			</CardBody>
		</Card>
	);
}
