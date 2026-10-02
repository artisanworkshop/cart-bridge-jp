import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';
import apiFetch from '../api';
import { runTab, type RunTab } from '../active-runs';
import { formatUtcMysqlTime } from '../format-time';
import { tabHref } from '../hash-route';
import type { ActiveRun } from '../types';

interface ActiveRunNoticeProps {
	platform: string;
	/** 案内する run（このタブでは表示・操作できない run）。空なら何も描画しない。 */
	runs: ActiveRun[];
	/** キャンセルが成功したあとに呼ぶ（一覧を取り直す）。 */
	onChanged: () => void;
	/** 表示中のタブ（そのタブへのリンクは出さない）。Tools・Mappings は null。 */
	currentTab?: RunTab | null;
	/**
	 * 担当タブへのリンクを出すか。Import/Export タブは接続済みのプラットフォームしか開けないため、
	 * Tools タブで未接続・要再接続のプラットフォームを選んでいるときは false にする（リンク先では
	 * 先頭の別プラットフォームが開いてしまう）。キャンセルは出す。
	 */
	showTabLinks?: boolean;
}

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

/**
 * 文頭に置く run の種類（describeRun() の `%1$s`）。
 * @param run
 */
function runLabel( run: ActiveRun ): string {
	switch ( run.type ) {
		case 'dry_run':
			return __( 'An import preview (dry run)', 'cart-bridge-jp' );
		case 'import':
			return __( 'An import', 'cart-bridge-jp' );
		case 'dry_run_export':
			return __( 'An export preview (dry run)', 'cart-bridge-jp' );
		case 'export':
			return __( 'An export', 'cart-bridge-jp' );
		default:
			return __( 'A run', 'cart-bridge-jp' );
	}
}

/**
 * run の状態を 1 文で説明する。種別ごと・状態ごとに完全な文にする（語句を連結すると訳せないため）。
 * @param run
 */
function describeRun( run: ActiveRun ): string {
	const started =
		'' !== run.created_at ? formatUtcMysqlTime( run.created_at ) : '—';

	// 失敗したジョブがあり、ほかに動いているジョブも無い run は、兄弟ジョブが pending のまま止まっている。
	if (
		run.has_failed_job &&
		'running' !== run.status &&
		'paused' !== run.status
	) {
		return sprintf(
			/* translators: 1: kind of run, e.g. "An import", 2: date and time the run started */
			__(
				'%1$s stopped because a job failed, but it still blocks new runs and tools on this platform (started %2$s). Retry the failed job or cancel the run.',
				'cart-bridge-jp'
			),
			runLabel( run ),
			started
		);
	}

	switch ( run.status ) {
		case 'running':
			return sprintf(
				/* translators: 1: kind of run, e.g. "An import", 2: date and time the run started */
				__(
					'%1$s is running on this platform (started %2$s).',
					'cart-bridge-jp'
				),
				runLabel( run ),
				started
			);
		case 'paused':
			return sprintf(
				/* translators: 1: kind of run, e.g. "An import", 2: date and time the run started */
				__(
					'%1$s is paused by the platform’s API rate limit and resumes automatically (started %2$s).',
					'cart-bridge-jp'
				),
				runLabel( run ),
				started
			);
		case 'pending':
			return sprintf(
				/* translators: 1: kind of run, e.g. "An import", 2: date and time the run started */
				__(
					'%1$s is waiting to run on this platform (started %2$s).',
					'cart-bridge-jp'
				),
				runLabel( run ),
				started
			);
		default:
			return sprintf(
				/* translators: 1: kind of run, e.g. "An import", 2: date and time the run started */
				__(
					'%1$s is in progress on this platform (started %2$s).',
					'cart-bridge-jp'
				),
				runLabel( run ),
				started
			);
	}
}

function tabLinkLabel( tab: RunTab ): string {
	return 'import' === tab
		? __( 'Open the Import tab to see its progress', 'cart-bridge-jp' )
		: __( 'Open the Export tab to see its progress', 'cart-bridge-jp' );
}

/**
 * このタブでは表示・操作できない進行中の run を知らせ、担当するタブへのリンクとキャンセルを出す
 * （R3-0i・issue #70）。run_id がブラウザに届かなかった run・別ブラウザで始めた run も、ここから見つけて
 * 止められる。キャンセルをここにも置くのは、Tools タブには未接続・要再接続のプラットフォームも並び、
 * その run は Import/Export タブ（接続済みのプラットフォームだけを表示する）では開けないため。
 * @param props
 */
export default function ActiveRunNotice( props: ActiveRunNoticeProps ) {
	const {
		platform,
		runs,
		onChanged,
		currentTab = null,
		showTabLinks = true,
	} = props;
	const [ cancellingRunId, setCancellingRunId ] = useState< string | null >(
		null
	);
	const [ error, setError ] = useState< string | null >( null );

	if ( 0 === runs.length ) {
		return null;
	}

	async function cancel( runId: string ) {
		setCancellingRunId( runId );
		setError( null );

		try {
			await apiFetch( {
				path: `/cbjp/v1/runs/${ runId }/cancel`,
				method: 'POST',
			} );
			onChanged();
		} catch ( err ) {
			setError( errorMessage( err ) );
		} finally {
			setCancellingRunId( null );
		}
	}

	return (
		<div className="cbjp-active-runs">
			{ runs.map( ( run ) => {
				const tab = runTab( run.type );
				const linkTab =
					showTabLinks && null !== tab && tab !== currentTab
						? tab
						: null;

				return (
					<Notice
						key={ run.run_id }
						status="warning"
						isDismissible={ false }
					>
						<p>{ describeRun( run ) }</p>
						<p className="cbjp-active-runs__actions">
							{ null !== linkTab && (
								<a href={ tabHref( linkTab, platform ) }>
									{ tabLinkLabel( linkTab ) }
								</a>
							) }{ ' ' }
							<Button
								variant="secondary"
								isDestructive
								isBusy={ cancellingRunId === run.run_id }
								disabled={ null !== cancellingRunId }
								onClick={ () => cancel( run.run_id ) }
							>
								{ __( 'Cancel run', 'cart-bridge-jp' ) }
							</Button>
						</p>
					</Notice>
				);
			} ) }
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
		</div>
	);
}
