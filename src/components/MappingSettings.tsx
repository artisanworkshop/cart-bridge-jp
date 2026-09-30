import { useEffect, useRef, useState } from '@wordpress/element';
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
	MappingCandidate,
	MappingKey,
	SettingsMappings,
	SettingsMappingValues,
} from '../types';

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

const UNMAPPED = '';

export type MapKey =
	| 'category_map'
	| 'payment_map'
	| 'shipping_map'
	| 'status_map';

type EditableMappings = Record< MapKey, Record< string, string > >;

function toEditable( data: SettingsMappingValues ): EditableMappings {
	return {
		category_map: { ...data.category_map },
		payment_map: { ...data.payment_map },
		shipping_map: { ...data.shipping_map },
		status_map: { ...data.status_map },
	};
}

/**
 * 1 種類のマップの表示設定。`category_map` だけ向きが Woo → ASP で、他の 3 つは ASP → Woo
 * （`SettingsMappingValues` 参照）。向きは「どちら側の候補を行（source）にし、どちら側から選ばせるか（target）」で表す。
 */
interface MapSectionConfig {
	candidateKey: MappingKey;
	sourceSide: 'asp' | 'woo';
	title: string;
	help: string;
	sourceHeading: string;
	targetHeading: string;
	unmappedLabel: string;
	/** 選べる対応先が 0 件のときの案内。 */
	noTargetsHelp: string;
}

function sectionConfig( key: MapKey ): MapSectionConfig {
	switch ( key ) {
		case 'category_map':
			return {
				candidateKey: 'category',
				sourceSide: 'woo',
				title: __( 'Category mapping', 'cart-bridge-jp' ),
				help: __(
					'Used when exporting products. This platform cannot create new categories, so pick an existing platform category for each WooCommerce category you plan to export.',
					'cart-bridge-jp'
				),
				sourceHeading: __( 'WooCommerce category', 'cart-bridge-jp' ),
				targetHeading: __( 'Platform category', 'cart-bridge-jp' ),
				unmappedLabel: __( '— No category —', 'cart-bridge-jp' ),
				noTargetsHelp: __(
					'No platform categories are available to choose from. Check the connection, or create the categories on the platform first.',
					'cart-bridge-jp'
				),
			};
		case 'payment_map':
			return {
				candidateKey: 'payment',
				sourceSide: 'asp',
				title: __( 'Payment method mapping', 'cart-bridge-jp' ),
				help: __(
					'Maps each platform payment method to a WooCommerce payment method. Imported orders with an unmapped payment method get an empty WooCommerce payment method (the platform’s name is kept as the title) and a warning.',
					'cart-bridge-jp'
				),
				sourceHeading: __(
					'Platform payment method',
					'cart-bridge-jp'
				),
				targetHeading: __(
					'WooCommerce payment method',
					'cart-bridge-jp'
				),
				unmappedLabel: __( '— Unmapped —', 'cart-bridge-jp' ),
				noTargetsHelp: __(
					'No WooCommerce payment methods are available. Set them up in WooCommerce > Settings > Payments first.',
					'cart-bridge-jp'
				),
			};
		case 'shipping_map':
			return {
				candidateKey: 'shipping',
				sourceSide: 'asp',
				title: __( 'Shipping method mapping', 'cart-bridge-jp' ),
				help: __(
					'Maps each platform shipping method to a shipping method in a WooCommerce shipping zone. Imported orders with an unmapped shipping method keep only the platform’s name on the shipping line and get a warning.',
					'cart-bridge-jp'
				),
				sourceHeading: __(
					'Platform shipping method',
					'cart-bridge-jp'
				),
				targetHeading: __(
					'WooCommerce shipping method',
					'cart-bridge-jp'
				),
				unmappedLabel: __( '— Unmapped —', 'cart-bridge-jp' ),
				noTargetsHelp: __(
					'No WooCommerce shipping methods are available. Add a shipping zone with a shipping method in WooCommerce > Settings > Shipping first.',
					'cart-bridge-jp'
				),
			};
		case 'status_map':
			return {
				candidateKey: 'status',
				sourceSide: 'asp',
				title: __( 'Order status mapping', 'cart-bridge-jp' ),
				help: __(
					'Overrides the WooCommerce status an imported order gets for each platform status. Leave it at Default to use the standard status.',
					'cart-bridge-jp'
				),
				sourceHeading: __( 'Platform order status', 'cart-bridge-jp' ),
				targetHeading: __(
					'WooCommerce order status',
					'cart-bridge-jp'
				),
				unmappedLabel: __( '— Default —', 'cart-bridge-jp' ),
				noTargetsHelp: __(
					'No WooCommerce order statuses are available.',
					'cart-bridge-jp'
				),
			};
	}
}

interface MappingSectionProps {
	config: MapSectionConfig;
	sourceCandidates: MappingCandidate[];
	targetCandidates: MappingCandidate[];
	map: Record< string, string >;
	onChange: ( sourceId: string, targetId: string ) => void;
	disabled: boolean;
}

/**
 * カテゴリ/決済/配送/注文ステータスの 4 つの表はどれも「片側の候補を 1 行ずつ並べ、もう片側から選ばせる」
 * 同じ形なので 1 つのコンポーネントにまとめる。
 * @param root0
 * @param root0.config
 * @param root0.sourceCandidates
 * @param root0.targetCandidates
 * @param root0.map
 * @param root0.onChange
 * @param root0.disabled
 */
function MappingSection( {
	config,
	sourceCandidates,
	targetCandidates,
	map,
	onChange,
	disabled,
}: MappingSectionProps ) {
	return (
		<Card className="cbjp-mappings__card">
			<CardHeader>
				<strong>{ config.title }</strong>
			</CardHeader>
			<CardBody>
				<p>{ config.help }</p>
				{ 0 === sourceCandidates.length ? (
					<p>
						{ __(
							'No options available yet. Check the connection and try again.',
							'cart-bridge-jp'
						) }
					</p>
				) : (
					<>
						{ 0 === targetCandidates.length && (
							<p>{ config.noTargetsHelp }</p>
						) }
						<div className="cbjp-mappings__scroll">
							<table className="cbjp-mappings__table">
								<thead>
									<tr>
										<th scope="col">
											{ config.sourceHeading }
										</th>
										<th scope="col">
											{ config.targetHeading }
										</th>
									</tr>
								</thead>
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
														// 行の見出し（左の列）と同じ名前を読み上げ用のラベルにする
														// （backlog e2-1-mapping-ui/R1-L5）。
														label={ source.name }
														hideLabelFromVision
														value={ currentValue }
														disabled={ disabled }
														options={ [
															{
																label: config.unmappedLabel,
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
																(
																	target
																) => ( {
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
					</>
				) }
			</CardBody>
		</Card>
	);
}

interface MappingSettingsProps {
	platform: string;
	/** 表示するマップ（表示順）。表示しないマップも保存時は取得した値のまま送り返す。 */
	mapKeys: MapKey[];
	disabled?: boolean;
}

/**
 * `GET/PUT /settings/mappings/{platform}` のマッピング設定（取得・編集・保存）。Mappings タブが使う（R3-0m）。
 * @param root0
 * @param root0.platform
 * @param root0.mapKeys
 * @param root0.disabled
 */
export default function MappingSettings( {
	platform,
	mapKeys,
	disabled = false,
}: MappingSettingsProps ) {
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
	// プラットフォーム名だけでは同じプラットフォームへ短時間で戻った場合（A→B→A）を区別できない。
	// Aの1回目のリクエスト（取得effectのGET、または保存中のPUT）がサーバー側の遅い候補取得
	// （ColorMeへの追加APIコール）で2回目のGETより遅れて解決すると、プラットフォーム名の一致チェック
	// だけでは「新しい応答」と誤認して新しい方や保存後の状態を上書きしてしまう。取得effectと`save()`の
	// 両方が同じ世代カウンタを参照し、「このリクエストが発行された時点のプラットフォーム選択がまだ
	// 現在のものか」を判定する（`.claude/rules/frontend.md`。世代を進めるのは取得effectだけ）。
	const generationRef = useRef( 0 );

	useEffect( () => {
		const requestId = ++generationRef.current;

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
				if ( generationRef.current !== requestId ) {
					return;
				}

				setMappings( data );
				setEdited( toEditable( data ) );
			} )
			.catch( ( err: unknown ) => {
				if ( generationRef.current !== requestId ) {
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
		if ( null === edited ) {
			return;
		}

		// このリクエストを発行した時点のプラットフォーム世代を閉じ込める。応答が届くまでの間に
		// 別プラットフォームへ切り替わっていた場合（A→B→Aのように戻った場合も世代で区別できる）、
		// そちらの`mappings`/`edited`（取得effectで読み込み直し済み）をこの古い応答で上書きしない。
		const requestId = generationRef.current;
		const requestedPlatform = platform;

		setSaving( true );
		setSaveError( null );

		try {
			// PUTは候補一覧（asp_candidates/woo_candidates）を返さない（`SettingsMappingValues`参照）。
			// 保存操作そのものでは候補は変化しないため、直前のGETで取得した`mappings`の候補部分は
			// そのまま保持し、保存済みマップ本体だけを差し替える。
			const data = await apiFetch< SettingsMappingValues >( {
				path: `/cbjp/v1/settings/mappings/${ encodeURIComponent(
					requestedPlatform
				) }`,
				method: 'PUT',
				data: edited,
			} );

			if ( generationRef.current !== requestId ) {
				return;
			}

			setMappings( ( current ) =>
				null === current ? current : { ...current, ...data }
			);
			setEdited( toEditable( data ) );
			setSaved( true );
		} catch ( err ) {
			if ( generationRef.current !== requestId ) {
				return;
			}

			setSaveError( errorMessage( err ) );
		} finally {
			setSaving( false );
		}
	}

	if ( mappingsError ) {
		// `mappings`/`edited`がnullのまま（取得失敗）のときだけ表示されるため、破棄可能にすると
		// 空のSpinnerだけが残る「詰み」状態になる（`platform`が変わらない限り再取得のeffectが
		// 発火しないため）。破棄不可にする。
		return (
			<Notice status="error" isDismissible={ false }>
				{ mappingsError }
			</Notice>
		);
	}

	if ( null === mappings || null === edited ) {
		return <Spinner />;
	}

	return (
		<div className="cbjp-mappings__settings">
			{ mapKeys.map( ( key ) => {
				const config = sectionConfig( key );
				const aspCandidates =
					mappings.asp_candidates[ config.candidateKey ];
				const wooCandidates =
					mappings.woo_candidates[ config.candidateKey ];

				return (
					<MappingSection
						key={ key }
						config={ config }
						sourceCandidates={
							'woo' === config.sourceSide
								? wooCandidates
								: aspCandidates
						}
						targetCandidates={
							'woo' === config.sourceSide
								? aspCandidates
								: wooCandidates
						}
						map={ edited[ key ] }
						onChange={ ( sourceId, targetId ) =>
							updateMap( key, sourceId, targetId )
						}
						disabled={ disabled || saving }
					/>
				);
			} ) }

			{ saveError && (
				<Notice status="error" onRemove={ () => setSaveError( null ) }>
					{ saveError }
				</Notice>
			) }
			{ saved && (
				<Notice status="success" onRemove={ () => setSaved( false ) }>
					{ __( 'Mapping settings saved.', 'cart-bridge-jp' ) }
				</Notice>
			) }

			<div className="cbjp-mappings__actions">
				<Button
					variant="primary"
					isBusy={ saving }
					disabled={ disabled || saving }
					onClick={ save }
				>
					{ __( 'Save mappings', 'cart-bridge-jp' ) }
				</Button>
			</div>
		</div>
	);
}
