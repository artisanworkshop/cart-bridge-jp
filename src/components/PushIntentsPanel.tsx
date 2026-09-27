import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, TextControl } from '@wordpress/components';
import apiFetch from '../api';
import { ENTITY_LABELS } from '../entity-labels';
import { formatUtcMysqlTime } from '../format-time';
import type { PushIntent } from '../types';

interface Props {
	platform: string;
	/**
	 * `ExportTab`の`dryRunExportBusy || exportBusy`。実行中の解除は`RestController::resolve_push_intent()`が
	 * `has_active_job_for_platform()`で409（`cbjp_run_in_progress`）を返すため、先回りしてボタンを止め
	 * 無駄な失敗リクエストを避ける（根本のcheck-then-actは issue #57 に委ねたまま。
	 * `.claude/rules/sync-export-tools.md`参照）。
	 */
	runInProgress: boolean;
}

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

/**
 * `Woo\Tools\PushIntentPresenter::describe()`のentity_type別`details`を1行の説明文に組み立てる。
 * `exists === false`（Woo側の実体が削除済み）の場合は`details`が空になる。
 * @param intent
 */
function describeIntent( intent: PushIntent ): string {
	if ( ! intent.exists ) {
		return __(
			'(deleted from WooCommerce since this was recorded)',
			'cart-bridge-jp'
		);
	}

	const { details } = intent;

	switch ( intent.entity_type ) {
		case 'product':
			return details.sku
				? sprintf(
						/* translators: 1: product name, 2: SKU */
						__( '%1$s (SKU: %2$s)', 'cart-bridge-jp' ),
						details.name ?? '',
						details.sku
				  )
				: details.name ?? '';
		case 'customer':
			return details.email ?? '';
		case 'order':
			return sprintf(
				/* translators: 1: order number, 2: order total, 3: currency code */
				__( '#%1$s — %2$s %3$s', 'cart-bridge-jp' ),
				details.number ?? '',
				details.total ?? '',
				details.currency ?? ''
			);
		case 'coupon':
			return details.code ?? '';
		default:
			return '';
	}
}

/**
 * Export タブ: 送信結果が確認できていない実体（D21-B、issue #73）の一覧と解除操作。
 * `Sync\Exporter`が印を残した実体は自動では再送されないため、店舗が ColorMe 側を確認したうえで
 * 「未作成として解除」（次回 export で改めて作成）か「リンクして解除」（既に作成済みの remote_id を
 * 紐付ける）のいずれかを行う必要がある。破壊的な確定操作だが、`window.confirm()`は使わない
 * （`.claude/rules/frontend.md`）。代わりに常時表示の`Notice`で注意を促す。
 * @param root0
 * @param root0.platform
 * @param root0.runInProgress
 */
export default function PushIntentsPanel( { platform, runInProgress }: Props ) {
	const [ intents, setIntents ] = useState< PushIntent[] | null >( null );
	const [ listError, setListError ] = useState< string | null >( null );
	const [ resolvingId, setResolvingId ] = useState< number | null >( null );
	const [ remoteIdInputs, setRemoteIdInputs ] = useState<
		Record< number, string >
	>( {} );
	const [ rowErrors, setRowErrors ] = useState< Record< number, string > >(
		{}
	);

	// GET（一覧取得）とPOST後の再取得の両方がこのstateを更新しうるため、frontend.mdの規約どおり
	// 値比較ではなく単調増加する世代カウンタで古い応答を捨てる。
	const generationRef = useRef( 0 );

	function fetchIntents() {
		const requestId = ++generationRef.current;

		apiFetch< { platform: string; intents: PushIntent[] } >( {
			path: `/cbjp/v1/push-intents/${ encodeURIComponent( platform ) }`,
		} )
			.then( ( data ) => {
				if ( generationRef.current !== requestId ) {
					return;
				}

				setIntents( data.intents );
				setListError( null );
			} )
			.catch( ( err: unknown ) => {
				if ( generationRef.current !== requestId ) {
					return;
				}

				setListError( errorMessage( err ) );
			} );
	}

	useEffect( () => {
		setIntents( null );
		setListError( null );
		setRowErrors( {} );
		fetchIntents();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ platform ] );

	function resolve(
		intent: PushIntent,
		action: 'not_created' | 'link',
		remoteId?: string
	) {
		setResolvingId( intent.id );
		setRowErrors( ( prev ) => {
			const next = { ...prev };

			delete next[ intent.id ];

			return next;
		} );

		apiFetch< { resolved: true } >( {
			path: `/cbjp/v1/push-intents/${ encodeURIComponent( platform ) }/${
				intent.id
			}/resolve`,
			method: 'POST',
			data:
				'link' === action
					? { action, remote_id: remoteId }
					: { action },
		} )
			.then( () => {
				setResolvingId( null );
				// 余分なGETを避け、解除できた行だけローカルで取り除く。
				setIntents(
					( prev ) =>
						prev?.filter( ( i ) => i.id !== intent.id ) ?? prev
				);
			} )
			.catch( ( err: unknown ) => {
				setResolvingId( null );
				setRowErrors( ( prev ) => ( {
					...prev,
					[ intent.id ]: errorMessage( err ),
				} ) );
			} );
	}

	if ( listError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ listError }
			</Notice>
		);
	}

	if ( null === intents || 0 === intents.length ) {
		return null;
	}

	return (
		<div className="cbjp-push-intents">
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'Some items could not be confirmed as created or rejected on the connected platform. They will not be re-sent automatically. Check the platform’s own admin screen to see whether each item actually exists there before resolving it below.',
					'cart-bridge-jp'
				) }
			</Notice>
			{ runInProgress && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'An export or import is currently running for this platform. Wait for it to finish before resolving these items.',
						'cart-bridge-jp'
					) }
				</Notice>
			) }
			<table className="widefat striped cbjp-push-intents__table">
				<thead>
					<tr>
						<th>{ __( 'Type', 'cart-bridge-jp' ) }</th>
						<th>{ __( 'Details', 'cart-bridge-jp' ) }</th>
						<th>{ __( 'Sent at', 'cart-bridge-jp' ) }</th>
						<th>{ __( 'Resolve', 'cart-bridge-jp' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ intents.map( ( intent ) => {
						const busy = resolvingId === intent.id;
						const disabled = runInProgress || null !== resolvingId;

						return (
							<tr key={ intent.id }>
								<th scope="row">
									{ ENTITY_LABELS[ intent.entity_type ] }
								</th>
								<td>
									{ describeIntent( intent ) }
									{ intent.edit_url && (
										<>
											{ ' ' }
											<Button
												variant="link"
												href={ intent.edit_url }
												target="_blank"
											>
												{ __(
													'Edit in WooCommerce',
													'cart-bridge-jp'
												) }
											</Button>
										</>
									) }
								</td>
								<td>
									{ formatUtcMysqlTime( intent.created_at ) }
								</td>
								<td>
									{ rowErrors[ intent.id ] && (
										<Notice
											status="error"
											isDismissible={ false }
										>
											{ rowErrors[ intent.id ] }
										</Notice>
									) }
									<div className="cbjp-push-intents__actions">
										<Button
											variant="secondary"
											isBusy={ busy }
											disabled={ disabled }
											onClick={ () =>
												resolve( intent, 'not_created' )
											}
										>
											{ __(
												'Mark as not created',
												'cart-bridge-jp'
											) }
										</Button>
									</div>
									<div className="cbjp-push-intents__actions">
										<TextControl
											label={ __(
												'Platform ID',
												'cart-bridge-jp'
											) }
											hideLabelFromVision
											placeholder={ __(
												'Platform ID',
												'cart-bridge-jp'
											) }
											disabled={ disabled }
											value={
												remoteIdInputs[ intent.id ] ??
												''
											}
											onChange={ ( value ) =>
												setRemoteIdInputs(
													( prev ) => ( {
														...prev,
														[ intent.id ]: value,
													} )
												)
											}
										/>
										<Button
											variant="secondary"
											isBusy={ busy }
											disabled={
												disabled ||
												! (
													remoteIdInputs[
														intent.id
													] ?? ''
												).trim()
											}
											onClick={ () =>
												resolve(
													intent,
													'link',
													(
														remoteIdInputs[
															intent.id
														] ?? ''
													).trim()
												)
											}
										>
											{ __(
												'Link and resolve',
												'cart-bridge-jp'
											) }
										</Button>
									</div>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}
