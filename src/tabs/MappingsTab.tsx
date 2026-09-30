import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import apiFetch from '../api';
import MappingSettings, { type MapKey } from '../components/MappingSettings';
import { parseHash } from '../hash-route';
import type { Capabilities, Connection } from '../types';

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

/**
 * 表示するマップ。決済/配送/注文ステータスは受注のインポート（とエクスポート）で使う。カテゴリは、カテゴリを
 * 作れないプラットフォームへ商品をエクスポートするときだけ要る（`can_create_category === false`）。
 * @param capabilities
 */
function visibleMapKeys( capabilities: Capabilities ): MapKey[] {
	const keys: MapKey[] = [ 'payment_map', 'shipping_map', 'status_map' ];

	if ( false === capabilities.can_create_category ) {
		keys.push( 'category_map' );
	}

	return keys;
}

/**
 * マッピング設定のタブ（R3-0m）。以前は Export タブにだけあり、インポートで同じ設定を使うことに
 * 店舗オーナーが気付けなかった。
 */
export default function MappingsTab() {
	const [ connections, setConnections ] = useState< Connection[] | null >(
		null
	);
	const [ connectionsError, setConnectionsError ] = useState< string | null >(
		null
	);
	const [ platform, setPlatform ] = useState< string | null >( null );

	useEffect( () => {
		apiFetch< Connection[] >( { path: '/cbjp/v1/connections' } )
			.then( ( data ) => setConnections( data ) )
			.catch( ( err: unknown ) =>
				setConnectionsError( errorMessage( err ) )
			);
	}, [] );

	const connectedPlatforms = useMemo(
		() => ( connections ?? [] ).filter( ( c ) => c.connected ),
		[ connections ]
	);

	useEffect( () => {
		if ( null !== platform || 0 === connectedPlatforms.length ) {
			return;
		}

		// Import/Export タブの案内から来たときは、そちらで選んでいたプラットフォームを開く（`#/mappings?platform=`）。
		// ハッシュは任意の文字列を含みうるので、接続済みのプラットフォームに一致するときだけ使う。
		const requested = parseHash( window.location.hash ).platform;

		setPlatform(
			connectedPlatforms.find( ( c ) => c.platform === requested )
				?.platform ?? connectedPlatforms[ 0 ].platform
		);
	}, [ connectedPlatforms, platform ] );

	const currentConnection = useMemo(
		() =>
			connectedPlatforms.find( ( c ) => c.platform === platform ) ?? null,
		[ connectedPlatforms, platform ]
	);

	if ( connectionsError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ connectionsError }
			</Notice>
		);
	}

	if ( null === connections ) {
		return <Spinner />;
	}

	if ( 0 === connectedPlatforms.length ) {
		// 保存済みのトークンを復号できない「要再接続」は `connected` が false になるため、未接続と区別して案内する
		// （CLAUDE.md: `connected` と `needs_reconnect` を組み合わせて見る）。
		const needsReconnect = connections.some( ( c ) => c.needs_reconnect );

		return (
			<p>
				{ needsReconnect
					? __(
							'Reconnect the platform on the Connections tab before setting up mappings.',
							'cart-bridge-jp'
					  )
					: __(
							'Connect a platform on the Connections tab before setting up mappings.',
							'cart-bridge-jp'
					  ) }
			</p>
		);
	}

	return (
		<div className="cbjp-mappings">
			<Card>
				<CardHeader>
					<strong>{ __( 'Mappings', 'cart-bridge-jp' ) }</strong>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'Mappings connect values on the platform to values in WooCommerce. Payment method, shipping method, and order status mappings are used when importing orders, and the same mappings are used, where possible, when exporting orders to the platform. Unmapped payment and shipping methods are reported as warnings in the preview (dry run) report. Set up the mappings before importing orders. Orders imported while a method was unmapped are updated the next time they are imported.',
							'cart-bridge-jp'
						) }
					</p>
					{ connectedPlatforms.length > 1 && (
						<SelectControl
							label={ __( 'Platform', 'cart-bridge-jp' ) }
							value={ platform ?? '' }
							options={ connectedPlatforms.map( ( c ) => ( {
								label: c.label,
								value: c.platform,
							} ) ) }
							onChange={ setPlatform }
						/>
					) }
				</CardBody>
			</Card>

			{ platform && currentConnection && (
				<MappingSettings
					platform={ platform }
					mapKeys={ visibleMapKeys( currentConnection.capabilities ) }
				/>
			) }
		</div>
	);
}
