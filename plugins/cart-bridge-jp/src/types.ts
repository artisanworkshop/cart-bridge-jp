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
	supports_per_variant_stock_management: boolean;
	/**
	 * 実店舗で未検証のベータ機能の識別子（`Adapters\Capabilities::BETA_*`。D24）。UI は「Beta」表示と、既定で
	 * 選択しない扱いにだけ使う（可否そのものは`can_create_order`/`can_push_images`が決める）。
	 * サーバーが文字列だけの配列へ正規化して返す。
	 */
	beta_features: string[];
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

export type RunType = 'dry_run' | 'import' | 'dry_run_export' | 'export';

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
	/**
	 * `skipped` の内訳: checksum 一致（変更なし）で書かなかった件数（issue #55）。dry-run の
	 * `created + updated + unchanged` が「移行できる件数」になる（`upsell-breakdown.ts`）。
	 * 導入前に完了したジョブの `totals_json` には無い（`get_run()` は生の JSON を返す）。
	 */
	unchanged?: number;
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

/**
 * 進行中の run の状態。`Sync\JobRepository::find_active_runs_for_platform()` が未終了のジョブから
 * running > paused > pending の優先で代表させる。解釈できない値は `unknown`（`active-runs.ts`）。
 */
export type ActiveRunStatus = 'running' | 'paused' | 'pending' | 'unknown';

/**
 * プラットフォームで進行中の run（`GET /runs?platform=` と 409 `cbjp_run_in_progress` の
 * `active_runs`。R3-0i・issue #70）。run_id がブラウザに届かなかった run を見つけるために使う。
 */
export interface ActiveRun {
	run_id: string;
	/** 解釈できない種別（外部コード等）は null。どのタブにも属さない run として扱う。 */
	type: RunType | null;
	status: ActiveRunStatus;
	/** run の全ジョブのエンティティ（終了済み・失敗したものを含む）。 */
	entities: EntityType[];
	/** 失敗したジョブがある（兄弟ジョブが pending のまま止まった run）。 */
	has_failed_job: boolean;
	/** UTC の `Y-m-d H:i:s`（`formatUtcMysqlTime()` で表示する）。 */
	created_at: string;
	updated_at: string;
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
	/**
	 * Pro 版の案内先（`Sync\LimitPolicy::pro_url()` が検証した http/https URL か `''`）。
	 * 空なら Pro 版に触れない（`docs/03` §10.3「アップセル表示」、issue #55）。
	 */
	pro_url: string;
}

/**
 * `Woo\Tools\PushIntentPresenter::describe()`が種別ごとに返す手がかり（D21-B）。
 * 全フィールド任意なのは、`entity_type`ごとに異なるサブセットしか埋まらないため
 * （product: name/sku、customer: email、order: number/total/currency/date_created、
 * coupon: code）。実体が削除済み（`exists === false`）の場合は空になる。
 */
export interface PushIntentDetails {
	name?: string;
	sku?: string | null;
	email?: string;
	number?: string;
	total?: string;
	currency?: string;
	date_created?: string | null;
	code?: string;
}

/**
 * `GET /push-intents/{platform}`の1行（`Sync\PushIntentRepository::find_unresolved()`の行に
 * `PushIntentPresenter::describe()`の結果をmergeしたもの）。D21-B（issue #73）: 作成結果が
 * 不明なままの実体を表す「送信中の印」。
 */
export interface PushIntent {
	id: number;
	entity_type: EntityType;
	local_id: number;
	run_id: string | null;
	job_id: number | null;
	reason: string | null;
	created_at: string;
	updated_at: string;
	exists: boolean;
	edit_url: string | null;
	details: PushIntentDetails;
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
 * 県コード修復（`Woo\Tools\PrefStateRepair`）の判定区分。エンティティ（顧客1人・受注1件）単位で数える。
 * Scan（GET）では `fixed` が「補正が必要な件数」、Repair（POST）では「補正した件数」を表す。
 */
export type StateRepairBucket =
	| 'fixed'
	| 'already_correct'
	| 'unverified'
	| 'unavailable'
	| 'skipped';

export type StateRepairEntity = 'customer' | 'order';

export type StateRepairCounts = Record<
	StateRepairEntity,
	Record< StateRepairBucket, number >
>;

/**
 * `GET|POST /tools/repair-states` の応答（1バッチ分）。`cursor` が null になるまで繰り返し呼ぶ。
 * ASP への照会に失敗して中断した場合（503/409/502）は、エラー応答の `data` に処理済みの `counts` と
 * 失敗した行を指す `cursor`（`interruption` = 理由）が入り、同じ位置から再開できる（処理は冪等）。
 */
export interface StateRepairResult {
	platform: string;
	apply: boolean;
	counts: StateRepairCounts;
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
	/** 店舗通貨（Woo 側の金額の通貨）。 */
	currency: string;
	/** ASP 側の金額の通貨（対応 ASP はすべて JPY）。 */
	platform_currency: string;
	/** true のとき金額は数値上一致しても同じ金額ではないため、金額突合は「不可」として扱う。 */
	currency_mismatch: boolean;
	entities: VerificationEntity[];
}

export type MappingKey = 'category' | 'payment' | 'shipping' | 'status';

export interface MappingCandidate {
	id: string;
	name: string;
}

/**
 * `GET/PUT /settings/mappings/{platform}`の応答本体（`Admin\RestController`）。`category_map`/
 * `payment_map`/`shipping_map`/`status_map`は保存済みマッピング（キー・値とも不透明な文字列ID）。
 * `category`のみ向きがWoo→ASPで他3キーはASP→Wooだが、型としては同じ形。
 */
export interface SettingsMappingValues {
	category_map: Record< string, string >;
	payment_map: Record< string, string >;
	shipping_map: Record< string, string >;
	status_map: Record< string, string >;
}

/**
 * `GET /settings/mappings/{platform}`のみが追加で返す、UIが選択肢を描画するための候補一覧
 * （E2-1・D19）。`PUT`は候補一覧を返さない（保存操作そのものでは候補が変化しないうえ、
 * ColorMe側は候補取得のたびに`categories.json`等への追加APIコールが発生し、実行中のジョブと
 * レート制限を奪い合うため。`RestController::save_settings_mappings()`参照）。
 */
export interface SettingsMappings extends SettingsMappingValues {
	asp_candidates: Record< MappingKey, MappingCandidate[] >;
	woo_candidates: Record< MappingKey, MappingCandidate[] >;
}

/**
 * `GET/PUT /settings/export-options/{platform}`（D24）。プラットフォーム単位のエクスポート設定。
 * `push_images`は「商品画像をアップロードする」（既定オフ。プレミアムプラン限定でベータ版）。
 */
export interface ExportOptions {
	push_images: boolean;
}
