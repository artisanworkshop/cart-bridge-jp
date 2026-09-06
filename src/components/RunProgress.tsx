import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	CheckboxControl,
	Notice,
	ProgressBar,
} from '@wordpress/components';
import { buildReportUrl } from '../report-url';
import type { EntityType, Job, JobStatus, Run } from '../types';

interface Props {
	run: Run;
	entityLabels: Record< EntityType, string >;
	onRetry: ( jobId: number ) => void;
	retryingJobId: number | null;
	onCancel: () => void;
	cancelling: boolean;
	isTerminal: boolean;
	onlyWarnings: boolean;
	onOnlyWarningsChange: ( value: boolean ) => void;
	/**
	 * CSVレポート（`GET /runs/{run_id}/report`）はdry-run実行時のみ`cbjp_dry_run_items`に
	 * 記録される（`Sync\Importer::process_items()`参照）。実移行（import）のrunに対しては
	 * 常に空（ヘッダー行のみ）のCSVになるため、誤解を招かないようdry-run実行時のみ
	 * ダウンロードUIを表示する。
	 */
	reportsAvailable: boolean;
}

const STATUS_LABELS: Record< JobStatus, string > = {
	pending: __( 'Pending', 'cart-bridge-jp' ),
	running: __( 'Running', 'cart-bridge-jp' ),
	paused: __( 'Paused (rate limit)', 'cart-bridge-jp' ),
	completed: __( 'Completed', 'cart-bridge-jp' ),
	failed: __( 'Failed', 'cart-bridge-jp' ),
	cancelled: __( 'Cancelled', 'cart-bridge-jp' ),
};

function totalsSummary( job: Job ): string {
	const { created, updated, skipped, warned, failed } = job.totals;

	return sprintf(
		/* translators: 1: created count, 2: updated count, 3: skipped count, 4: warned count, 5: failed count */
		__(
			'Created: %1$d / Updated: %2$d / Skipped: %3$d / Warnings: %4$d / Failed: %5$d',
			'cart-bridge-jp'
		),
		created,
		updated,
		skipped,
		warned,
		failed
	);
}

function JobRow( {
	job,
	label,
	runId,
	onRetry,
	retrying,
	reportsAvailable,
}: {
	job: Job;
	label: string;
	runId: string;
	onRetry: ( jobId: number ) => void;
	retrying: boolean;
	reportsAvailable: boolean;
} ) {
	const inProgress = 'pending' === job.status || 'running' === job.status;
	// アダプタが総数を保証できないエンティティ（CLAUDE.md参照）は`total`が0のまま
	// 留まるため、`processed`が既にあるのに0%と誤読させないよう不定進捗にする。
	const hasKnownTotal = job.totals.total > 0;

	return (
		<div className="cbjp-run-progress__job">
			<div className="cbjp-run-progress__job-header">
				<strong>{ label }</strong>
				<span
					className={ `cbjp-run-progress__status cbjp-run-progress__status--${ job.status }` }
				>
					{ STATUS_LABELS[ job.status ] }
				</span>
			</div>

			{ inProgress && hasKnownTotal && (
				<>
					<ProgressBar
						value={ Math.min(
							100,
							( job.totals.processed / job.totals.total ) * 100
						) }
					/>
					<p>
						{ sprintf(
							/* translators: 1: processed count, 2: total count */
							__( '%1$d of %2$d processed', 'cart-bridge-jp' ),
							job.totals.processed,
							job.totals.total
						) }
					</p>
				</>
			) }

			{ inProgress && ! hasKnownTotal && (
				<>
					<ProgressBar />
					<p>
						{ sprintf(
							/* translators: %d: processed count so far */
							__( '%d processed so far…', 'cart-bridge-jp' ),
							job.totals.processed
						) }
					</p>
				</>
			) }

			{ 'paused' === job.status && (
				<p>
					{ __(
						'Waiting for the rate limit to recover. This will resume automatically.',
						'cart-bridge-jp'
					) }
				</p>
			) }

			{ ! inProgress && <p>{ totalsSummary( job ) }</p> }

			{ job.error && (
				<Notice status="error" isDismissible={ false }>
					{ job.error.message }
				</Notice>
			) }

			{ 'failed' === job.status && (
				<Button
					variant="secondary"
					isBusy={ retrying }
					disabled={ retrying }
					onClick={ () => onRetry( job.id ) }
				>
					{ __( 'Retry', 'cart-bridge-jp' ) }
				</Button>
			) }

			{ reportsAvailable &&
				( 'completed' === job.status || 'failed' === job.status ) && (
					<Button
						variant="link"
						href={ buildReportUrl( runId, { entity: job.entity } ) }
					>
						{ __(
							'Download this entity’s report (CSV)',
							'cart-bridge-jp'
						) }
					</Button>
				) }
		</div>
	);
}

export default function RunProgress( {
	run,
	entityLabels,
	onRetry,
	retryingJobId,
	onCancel,
	cancelling,
	isTerminal,
	onlyWarnings,
	onOnlyWarningsChange,
	reportsAvailable,
}: Props ) {
	const anyActive = ! isTerminal;

	return (
		<div className="cbjp-run-progress">
			{ ( anyActive || reportsAvailable ) && (
				<div className="cbjp-run-progress__toolbar">
					{ anyActive && (
						<Button
							variant="secondary"
							isDestructive
							isBusy={ cancelling }
							disabled={ cancelling }
							onClick={ onCancel }
						>
							{ __( 'Cancel run', 'cart-bridge-jp' ) }
						</Button>
					) }
					{ reportsAvailable && (
						<>
							<CheckboxControl
								label={ __(
									'Only include warnings in the CSV report',
									'cart-bridge-jp'
								) }
								checked={ onlyWarnings }
								onChange={ onOnlyWarningsChange }
							/>
							<Button
								variant="secondary"
								href={ buildReportUrl( run.run_id, {
									onlyWarnings,
								} ) }
							>
								{ __(
									'Download full report (CSV)',
									'cart-bridge-jp'
								) }
							</Button>
						</>
					) }
				</div>
			) }

			{ run.jobs.map( ( job ) => (
				<JobRow
					key={ job.id }
					job={ job }
					label={ entityLabels[ job.entity ] }
					runId={ run.run_id }
					onRetry={ onRetry }
					retrying={ retryingJobId === job.id }
					reportsAvailable={ reportsAvailable }
				/>
			) ) }
		</div>
	);
}
