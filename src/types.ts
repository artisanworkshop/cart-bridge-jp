export interface Capabilities {
	can_create_category: boolean;
	can_create_order: boolean;
	can_fetch_customers: boolean;
	can_update_customer: boolean;
	can_push_images: boolean;
	can_create_coupon: boolean;
	has_coupons: boolean;
	has_tags: boolean;
	has_reviews: boolean;
	has_variants: boolean;
	rate_limit_per_minute: number;
}

export interface ConnectionField {
	key: string;
	label: string;
	type: 'text' | 'password' | 'oauth_button';
	required: boolean;
	help: string | null;
}

export interface Connection {
	platform: string;
	label: string;
	connected: boolean;
	needs_reconnect: boolean;
	has_settings: boolean;
	masked_token: string | null;
	capabilities: Capabilities;
	callback_url: string | null;
	connection_fields: ConnectionField[];
}

export interface AuthorizeUrlResponse {
	url: string;
	redirect_uri: string;
}

export interface TestConnectionResult {
	ok: boolean;
	shop_name: string | null;
	message: string | null;
}

/**
 * `Sync\JobManager::ENTITY_ORDER` と同じ並び順（実行順）。
 */
export const ENTITY_ORDER = [
	'category',
	'tag',
	'product',
	'customer',
	'order',
	'stock',
	'coupon',
	'review',
] as const;

export type EntityType = ( typeof ENTITY_ORDER )[ number ];

export type RunType = 'dry_run' | 'import';

export type JobStatus =
	| 'pending'
	| 'running'
	| 'paused'
	| 'completed'
	| 'failed'
	| 'cancelled';

/**
 * `Sync\JobRepository::empty_totals()` と対応。`total` はページ処理毎の最大値
 * （進捗率の分母）で、アダプタが件数を保証できないエンティティでは0のまま
 * 留まる（CLAUDE.md「変換層が一部の行を除外・展開しうるエンティティ」参照）。
 */
export interface JobTotals {
	total: number;
	processed: number;
	created: number;
	updated: number;
	skipped: number;
	warned: number;
	failed: number;
	/**
	 * 受注ジョブのみ: この run で取得した受注の合計金額（1/100単位）。
	 * F1-7 より前に作られたジョブの `totals_json` には無い。
	 */
	remote_amount?: number;
}

export interface JobErrorInfo {
	code: string;
	message: string;
}

export interface Job {
	id: number;
	entity: EntityType;
	status: JobStatus;
	totals: JobTotals;
	error: JobErrorInfo | null;
}

export interface Run {
	run_id: string;
	jobs: Job[];
}

export interface LimitEntity {
	limit: number | null;
	unlocked: boolean;
	used: number | null;
	remaining: number | null;
}

export interface Limits {
	unlocked: boolean;
	entities: Record< EntityType, LimitEntity >;
}

/**
 * `GET /logs`は`$wpdb->get_results(..., ARRAY_A)`の生の行をそのまま返すため、
 * `id`/`job_id`を含む数値カラムも文字列で返る（wpdb/MySQLiの一般的な挙動）。
 */
export interface LogEntry {
	id: string;
	job_id: string | null;
	level: 'debug' | 'info' | 'warning' | 'error';
	message: string;
	context_json: string | null;
	created_at: string;
}

/**
 * `GET /tools/sample-cleanup?platform=` の応答（`Woo\Tools\SampleCleanup::preview()`）。
 * `delete` / `unlink` のキーは `SampleCleanup::RESULT_KEYS`（`attachment` は `delete` のみ意味を持つ）。
 * `requires_delete_users` が true で `can_delete_users` が false のとき、実行は 403 で拒否される。
 */
export interface CleanupPreview {
	platform: string;
	run_in_progress: boolean;
	delete: Record< string, number >;
	unlink: Record< string, number >;
	requires_delete_users: boolean;
	can_delete_users: boolean;
	sample_selected: boolean;
}

/**
 * `POST /tools/sample-cleanup` の応答（1バッチ分）。`has_more` が true の間は繰り返し呼ぶ。
 */
export interface CleanupResult {
	deleted: Record< string, number >;
	unlinked: Record< string, number >;
	has_more: boolean;
}

/**
 * `POST /tools/rebuild-mappings` の応答（1バッチ分）。`cursor` が null になるまで繰り返し呼ぶ。
 */
export interface RebuildResult {
	counts: Record< string, number >;
	cursor: string | null;
}

/**
 * `GET /runs/{run_id}/verification` の1行（`Sync\VerificationReport`）。金額は `"1234.00"` 形式の
 * 10進文字列で、受注以外は null。
 */
export interface VerificationEntity {
	entity: EntityType;
	status: JobStatus;
	processed: number;
	written: number;
	skipped: number;
	warned: number;
	linked: number;
	existing: number;
	missing: number;
	remote_amount: string | null;
	local_amount: string | null;
}

export interface VerificationReport {
	run_id: string;
	platform: string;
	type: RunType;
	currency: string;
	entities: VerificationEntity[];
}
