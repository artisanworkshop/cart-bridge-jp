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
	MappingKindInfo,
	SettingsMappings,
	SettingsMappingValues,
} from '../types';
import {
	editableMaps,
	mappedTarget,
	parseMappingKinds,
	withMappedTarget,
} from './mapping-status';

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

const UNMAPPED = '';

/**
 * 編集中のマップ（`map_key` => 行の ID => 対応先の ID）。登録された全種類の分を持ち、節を出さない種類の値も保存時にそのまま送り返す
 * （`editableMaps()`）。
 */
type EditableMappings = Record< string, Record< string, string > >;

/**
 * 1 種類のマップの表示設定（R3-6b2 でサーバーの宣言〈`kinds`〉から組み立てる形にした）。向きは「どちら側の候補を行（source）にし、
 * どちら側から選ばせるか（target）」で表す（カテゴリは Woo → ASP、決済などは ASP → Woo）。文言はサーバーが翻訳して返す。
 */
interface MapSectionConfig {
	sourceSide: 'asp' | 'woo';
	title: string;
	help: string;
	sourceHeading: string;
	targetHeading: string;
	unmappedLabel: string;
	/** 選べる対応先が 0 件のときの案内。 */
	noTargetsHelp: string;
}

function sectionConfig( kind: MappingKindInfo ): MapSectionConfig {
	return {
		sourceSide: kind.source_side,
		title: kind.label || kind.key,
		help: kind.description,
		sourceHeading: kind.source_heading,
		targetHeading: kind.target_heading,
		unmappedLabel:
			kind.unmapped_label || __( '— Unmapped —', 'cart-bridge-jp' ),
		noTargetsHelp: kind.no_targets_help,
	};
}

function candidateList(
	candidates: Record< string, MappingCandidate[] > | undefined,
	key: string
): MappingCandidate[] {
	const list =
		candidates && Object.prototype.hasOwnProperty.call( candidates, key )
			? candidates[ key ]
			: undefined;

	return Array.isArray( list ) ? list : [];
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
 * マッピングの表はどれも「片側の候補を 1 行ずつ並べ、もう片側から選ばせる」同じ形なので 1 つのコンポーネントにまとめる。
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
				{ '' !== config.help && <p>{ config.help }</p> }
				{ 0 === sourceCandidates.length ? (
					<p>
						{ __(
							'No options available yet. Check the connection and try again.',
							'cart-bridge-jp'
						) }
					</p>
				) : (
					<>
						{ 0 === targetCandidates.length &&
							'' !== config.noTargetsHelp && (
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
										const currentValue = mappedTarget(
											map,
											source.id
										);
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
												<th scope="row">
													{ source.name }
												</th>
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
	disabled?: boolean;
}

/**
 * `GET/PUT /settings/mappings/{platform}` のマッピング設定（取得・編集・保存）。Mappings タブが使う（R3-0m）。
 * 節は応答の `kinds` のうち、この接続先で使うもの（`applies`）だけを並べる（R3-6b2）。
 * @param root0
 * @param root0.platform
 * @param root0.disabled
 */
export default function MappingSettings( {
	platform,
	disabled = false,
}: MappingSettingsProps ) {
	const [ mappings, setMappings ] = useState< SettingsMappings | null >(
		null
	);
	const [ kinds, setKinds ] = useState< MappingKindInfo[] >( [] );
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
		setKinds( [] );
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

				const parsed = parseMappingKinds( data?.kinds );

				setMappings( data );
				setKinds( parsed );
				setEdited( editableMaps( data, parsed ) );
			} )
			.catch( ( err: unknown ) => {
				if ( generationRef.current !== requestId ) {
					return;
				}

				setMappingsError( errorMessage( err ) );
			} );
	}, [ platform ] );

	function updateMap( mapKey: string, sourceId: string, targetId: string ) {
		setEdited( ( current ) => {
			if ( null === current ) {
				return current;
			}

			return {
				...current,
				[ mapKey ]: withMappedTarget(
					current[ mapKey ] ?? {},
					sourceId,
					targetId
				),
			};
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
			setEdited( editableMaps( data, kinds ) );
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

	const sections = kinds.filter( ( kind ) => kind.applies );

	if ( 0 === sections.length ) {
		// カテゴリを作れる接続先に無料版だけでつないだときなど（カテゴリのマッピングも要らない。R3-6c の後）。
		return (
			<p>
				{ __(
					'There are no mappings to set up for this platform.',
					'cart-bridge-jp'
				) }
			</p>
		);
	}

	return (
		<div className="cbjp-mappings__settings">
			{ sections.map( ( kind ) => {
				const config = sectionConfig( kind );
				const aspCandidates = candidateList(
					mappings.asp_candidates,
					kind.key
				);
				const wooCandidates = candidateList(
					mappings.woo_candidates,
					kind.key
				);

				return (
					<MappingSection
						key={ kind.key }
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
						map={ edited[ kind.map_key ] ?? {} }
						onChange={ ( sourceId, targetId ) =>
							updateMap( kind.map_key, sourceId, targetId )
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
