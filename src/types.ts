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
