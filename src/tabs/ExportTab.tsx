import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import apiFetch from '../api';
import type {
	Connection,
	MappingCandidate,
	SettingsMappings,
	SettingsMappingValues,
} from '../types';

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

const UNMAPPED = '';

type MapKey = 'category_map' | 'payment_map' | 'shipping_map' | 'status_map';

type EditableMappings = Record< MapKey, Record< string, string > >;

function toEditable( data: SettingsMappingValues ): EditableMappings {
	return {
		category_map: { ...data.category_map },
		payment_map: { ...data.payment_map },
		shipping_map: { ...data.shipping_map },
		status_map: { ...data.status_map },
	};
}

interface MappingSectionProps {
	title: string;
	help?: string;
	sourceCandidates: MappingCandidate[];
	targetCandidates: MappingCandidate[];
	unmappedLabel: string;
	map: Record< string, string >;
	onChange: ( sourceId: string, targetId: string ) => void;
	disabled: boolean;
}

/**
 * カテゴリ/決済/配送/注文ステータスの4テーブルはいずれも「片側の候補一覧を1行ずつ列挙し、
 * もう片側から選ばせる」という同じ形なので1つのコンポーネントに集約する。向き（Woo→ASPか
 * ASP→Wooか）はどの候補一覧を`sourceCandidates`/`targetCandidates`に渡すかで表現する。
 * @param root0
 * @param root0.title
 * @param root0.help
 * @param root0.sourceCandidates
 * @param root0.targetCandidates
 * @param root0.unmappedLabel
 * @param root0.map
 * @param root0.onChange
 * @param root0.disabled
 */
function MappingSection( {
	title,
	help,
	sourceCandidates,
	targetCandidates,
	unmappedLabel,
	map,
	onChange,
	disabled,
}: MappingSectionProps ) {
	return (
		<Card className="cbjp-export__mapping-card">
			<CardHeader>
				<strong>{ title }</strong>
			</CardHeader>
			<CardBody>
				{ help && <p>{ help }</p> }
				{ 0 === sourceCandidates.length ? (
					<p>
						{ __(
							'No options available yet. Check the connection and try again.',
							'cart-bridge-jp'
						) }
					</p>
				) : (
					<div className="cbjp-export__mapping-scroll">
						<table className="cbjp-export__mapping-table">
							<tbody>
								{ sourceCandidates.map( ( source ) => {
									const currentValue =
										map[ source.id ] ?? UNMAPPED;
									// 保存済みの値が現在の候補一覧に無い場合（決済ゲートウェイの
									// 無効化、配送ゾーンインスタンスの削除、ASP側メソッドの廃止等）、
									// ネイティブ<select>はどのoptionにも一致せず先頭
									// （「未マッピング」）を表示してしまい、実際の保存値と表示が
									// 食い違ったまま同じ選択肢を選び直しても変更なしと判定されて
									// 解除できなくなる。現在値を一時的な選択肢として差し込み、
									// 表示と選択解除の両方を可能にする。
									const currentValueKnown =
										UNMAPPED === currentValue ||
										targetCandidates.some(
											( target ) =>
												target.id === currentValue
										);

									return (
										<tr key={ source.id }>
											<td>{ source.name }</td>
											<td>
												<SelectControl
													value={ currentValue }
													disabled={ disabled }
													options={ [
														{
															label: unmappedLabel,
															value: UNMAPPED,
														},
														...( currentValueKnown
															? []
															: [
																	{
																		label: sprintf(
																			/* translators: %s: a mapping id that no longer exists among the current options */
																			__(
																				'%s (no longer available)',
																				'cart-bridge-jp'
																			),
																			currentValue
																		),
																		value: currentValue,
																	},
															  ] ),
														...targetCandidates.map(
															( target ) => ( {
																label: target.name,
																value: target.id,
															} )
														),
													] }
													onChange={ ( value ) =>
														onChange(
															source.id,
															value
														)
													}
												/>
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
				) }
			</CardBody>
		</Card>
	);
}

export default function ExportTab() {
	const [ connections, setConnections ] = useState< Connection[] | null >(
		null
	);
	const [ connectionsError, setConnectionsError ] = useState< string | null >(
		null
	);
	const [ platform, setPlatform ] = useState< string | null >( null );
	const [ mappings, setMappings ] = useState< SettingsMappings | null >(
		null
	);
	const [ mappingsError, setMappingsError ] = useState< string | null >(
		null
	);
	const [ edited, setEdited ] = useState< EditableMappings | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState< string | null >( null );
	const [ saved, setSaved ] = useState( false );
	const platformRef = useRef( platform );
	platformRef.current = platform;

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

		setPlatform( connectedPlatforms[ 0 ].platform );
	}, [ connectedPlatforms, platform ] );

	const currentConnection = useMemo(
		() =>
			connectedPlatforms.find( ( c ) => c.platform === platform ) ?? null,
		[ connectedPlatforms, platform ]
	);

	useEffect( () => {
		if ( null === platform ) {
			return;
		}

		const requestedPlatform = platform;

		setMappings( null );
		setEdited( null );
		setMappingsError( null );
		setSaveError( null );
		setSaved( false );

		apiFetch< SettingsMappings >( {
			path: `/cbjp/v1/settings/mappings/${ encodeURIComponent(
				platform
			) }`,
		} )
			.then( ( data ) => {
				if ( platformRef.current !== requestedPlatform ) {
					return;
				}

				setMappings( data );
				setEdited( toEditable( data ) );
			} )
			.catch( ( err: unknown ) => {
				if ( platformRef.current !== requestedPlatform ) {
					return;
				}

				setMappingsError( errorMessage( err ) );
			} );
	}, [ platform ] );

	function updateMap( key: MapKey, sourceId: string, targetId: string ) {
		setEdited( ( current ) => {
			if ( null === current ) {
				return current;
			}

			const next = { ...current[ key ] };

			if ( UNMAPPED === targetId ) {
				delete next[ sourceId ];
			} else {
				next[ sourceId ] = targetId;
			}

			return { ...current, [ key ]: next };
		} );
		setSaved( false );
	}

	async function save() {
		if ( null === platform || null === edited ) {
			return;
		}

		// このリクエストを発行した時点のプラットフォームを閉じ込める。応答が届くまでの
		// 間にユーザーが別プラットフォームへ切り替えていた場合、そちらの`mappings`/`edited`
		// （platform-change時に読み込み直し済み）をこの古い応答で上書きしない
		// （ImportTab.tsxの`requestedPlatform`と同じパターン）。
		const requestedPlatform = platform;

		setSaving( true );
		setSaveError( null );

		try {
			// PUTは候補一覧（asp_candidates/woo_candidates）を返さない（`SettingsMappingValues`参照）。
			// 保存操作そのものでは候補は変化しないため、直前のGETで取得した`mappings`の候補部分は
			// そのまま保持し、保存済みマップ本体だけを差し替える。
			const data = await apiFetch< SettingsMappingValues >( {
				path: `/cbjp/v1/settings/mappings/${ encodeURIComponent(
					platform
				) }`,
				method: 'PUT',
				data: edited,
			} );

			if ( platformRef.current !== requestedPlatform ) {
				return;
			}

			setMappings( ( current ) =>
				null === current ? current : { ...current, ...data }
			);
			setEdited( toEditable( data ) );
			setSaved( true );
		} catch ( err ) {
			if ( platformRef.current !== requestedPlatform ) {
				return;
			}

			setSaveError( errorMessage( err ) );
		} finally {
			setSaving( false );
		}
	}

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
		return (
			<p>
				{ __(
					'Connect a platform on the Connections tab before setting up export mappings.',
					'cart-bridge-jp'
				) }
			</p>
		);
	}

	return (
		<div className="cbjp-export">
			<Card>
				<CardHeader>
					<strong>
						{ __( 'Mapping settings', 'cart-bridge-jp' ) }
					</strong>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'Category mapping controls which platform category each WooCommerce category exports to. Payment method, shipping method, and order status mappings are shared with importing: they normalize platform values into WooCommerce when importing, and the same mapping is used, where possible, when exporting orders back to the platform. Unmapped items are skipped or reported as warnings.',
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

			{ mappingsError && (
				// `mappings`/`edited`がnullのまま（取得失敗）のときだけ表示されるため、
				// 破棄可能にすると空のSpinnerだけが残る「詰み」状態になる（`platform`が
				// 変わらない限り再取得のeffectが発火しないため）。`connectionsError`と同じ理由で
				// 破棄不可にする（G1指摘）。
				<Notice status="error" isDismissible={ false }>
					{ mappingsError }
				</Notice>
			) }

			{ ( null === mappings || null === edited ) && ! mappingsError ? (
				<Spinner />
			) : null }

			{ mappings && edited && (
				<>
					{ false ===
						currentConnection?.capabilities.can_create_category && (
						<MappingSection
							title={ __( 'Category mapping', 'cart-bridge-jp' ) }
							help={ __(
								'This platform cannot create new categories, so pick an existing platform category for each WooCommerce category you plan to export.',
								'cart-bridge-jp'
							) }
							sourceCandidates={
								mappings.woo_candidates.category
							}
							targetCandidates={
								mappings.asp_candidates.category
							}
							unmappedLabel={ __(
								'— No category —',
								'cart-bridge-jp'
							) }
							map={ edited.category_map }
							onChange={ ( sourceId, targetId ) =>
								updateMap( 'category_map', sourceId, targetId )
							}
							disabled={ saving }
						/>
					) }

					<MappingSection
						title={ __(
							'Payment method mapping',
							'cart-bridge-jp'
						) }
						help={ __(
							'Shared with importing: maps each platform payment method to a WooCommerce gateway.',
							'cart-bridge-jp'
						) }
						sourceCandidates={ mappings.asp_candidates.payment }
						targetCandidates={ mappings.woo_candidates.payment }
						unmappedLabel={ __( '— Unmapped —', 'cart-bridge-jp' ) }
						map={ edited.payment_map }
						onChange={ ( sourceId, targetId ) =>
							updateMap( 'payment_map', sourceId, targetId )
						}
						disabled={ saving }
					/>

					<MappingSection
						title={ __(
							'Shipping method mapping',
							'cart-bridge-jp'
						) }
						help={ __(
							'Shared with importing: maps each platform shipping method to a WooCommerce shipping method.',
							'cart-bridge-jp'
						) }
						sourceCandidates={ mappings.asp_candidates.shipping }
						targetCandidates={ mappings.woo_candidates.shipping }
						unmappedLabel={ __( '— Unmapped —', 'cart-bridge-jp' ) }
						map={ edited.shipping_map }
						onChange={ ( sourceId, targetId ) =>
							updateMap( 'shipping_map', sourceId, targetId )
						}
						disabled={ saving }
					/>

					<MappingSection
						title={ __( 'Order status mapping', 'cart-bridge-jp' ) }
						help={ __(
							'Shared with importing: overrides the WooCommerce status an imported order gets for each platform status.',
							'cart-bridge-jp'
						) }
						sourceCandidates={ mappings.asp_candidates.status }
						targetCandidates={ mappings.woo_candidates.status }
						unmappedLabel={ __( '— Default —', 'cart-bridge-jp' ) }
						map={ edited.status_map }
						onChange={ ( sourceId, targetId ) =>
							updateMap( 'status_map', sourceId, targetId )
						}
						disabled={ saving }
					/>

					{ saveError && (
						<Notice
							status="error"
							onRemove={ () => setSaveError( null ) }
						>
							{ saveError }
						</Notice>
					) }
					{ saved && (
						<Notice
							status="success"
							onRemove={ () => setSaved( false ) }
						>
							{ __(
								'Mapping settings saved.',
								'cart-bridge-jp'
							) }
						</Notice>
					) }

					<div className="cbjp-export__actions">
						<Button
							variant="primary"
							isBusy={ saving }
							disabled={ saving }
							onClick={ save }
						>
							{ __( 'Save mappings', 'cart-bridge-jp' ) }
						</Button>
					</div>
				</>
			) }
		</div>
	);
}
