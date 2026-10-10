export interface Capabilities {
	can_create_category: boolean;
	can_push_images: boolean;
	has_tags: boolean;
	has_reviews: boolean;
	has_variants: boolean;
	rate_limit_per_minute: number;
	supports_per_variant_stock_management: boolean;
	/**
	 * 実店舗で未検証のベータ機能の識別子（`Adapters\Capabilities::BETA_*`。D24）。UI は「Beta」表示と、既定で
	 * 選択しない扱いにだけ使う（可否そのものは`can_push_images`が決める。受注のエクスポートのベータは R3-6c1 から Pro の
	 * 実体の種類の宣言〔`entities[].export.beta`〕で届く）。
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

/**
 * 取込みの選択肢（`GET /connections` の `entities.import`。R3-6b1・R3-6b2）。並びは実行順。
 */
export interface ImportEntityOption {
	key: EntityType;
	label: string;
	/** この種類が、取込みの前に未設定の数を案内するマッピングを持つ（Import タブが候補を取得して数える）。 */
	mapping_notice: boolean;
}

/**
 * エクスポートの選択肢（`entities.export`）。`beta` は既定で選ばない（D24）。`description` は空なら出さない。
 */
export interface ExportEntityOption {
	key: EntityType;
	label: string;
	beta: boolean;
	description: string;
}

export interface EntityOptions {
	import: ImportEntityOption[];
	export: ExportEntityOption[];
}

export interface Connection {
	platform: string;
	label: string;
	connected: boolean;
	needs_reconnect: boolean;
	has_settings: boolean;
	masked_token: string | null;
	capabilities: Capabilities;
	/** この接続先で取り込める・エクスポートできる実体の種類（`POST /runs` と同じ判定）。 */
	entities: EntityOptions;
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
 * 実体の種類のキー（`Entities\EntityType::key()`）。Pro アドオンが種類を足すので、画面は特定のキーを知らない（R3-6b2）。
 * 選択肢・表示名・並びはサーバーの宣言（`Connection.entities`・`cbjpAdmin.entityLabels`）から得る。
 */
export type EntityType = string;

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
	 * `skipped` の内訳: 既に移行済みで、今回は書かなかった・送らなかった件数（checksum 一致など。issue #55）。
	 * R3-6a で削除した無料版の Pro 案内が使っていた。画面では今は使っていない。
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
	/**
	 * 店舗が ASP の管理画面で実体を探す手がかりの 1 行（`Entities\EntityType::describe_local()`。R3-6b2）。実体が無いときは空。
	 * 元の値の `details` も返るが、画面は使わない（種類ごとに形が違う）。
	 */
	summary: string;
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
 * `POST /tools/rebuild-mappings` の応答（1バッチ分）。`cursor` が null になるまで繰り返し呼ぶ。
 */
export interface RebuildResult {
	/** 種類のキー => 結び直した件数。キーの順はサーバーの走査順。 */
	counts: Record< string, number >;
	/** 走査に失敗して飛ばした種類のキー（理由は Logs タブ。R3-6b2）。 */
	skipped?: string[];
	cursor: string | null;
}

/**
 * `GET /runs/{run_id}/verification` の1行（`Sync\VerificationReport`）。金額は `"1234.00"` 形式の
 * 10進文字列で、金額を突合しない種類は null。
 */
export interface VerificationEntity {
	entity: EntityType;
	status: JobStatus;
	processed: number;
	written: number;
	skipped: number;
	warned: number;
	linked: number;
	/** Woo 側に実在する件数。null は確かめられない（その種類がこのサイトに登録されていない。R3-6c1）。 */
	existing: number | null;
	missing: number | null;
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

export interface MappingCandidate {
	id: string;
	name: string;
}

/**
 * マッピングの 1 種類（`GET /settings/mappings` の `kinds`。`Entities\MappingKind`。R3-6b1・R3-6b2）。文言はサーバーが翻訳して返す。
 * `source_side` は行に並べる側（`woo`: Woo の値ごとに ASP の値を選ぶ。`asp`: その逆）。`applies` が偽の種類は節を出さない。
 */
export interface MappingKindInfo {
	key: string;
	/** 保存済みのマップのキー（`{key}_map`）。 */
	map_key: string;
	/** このマッピングを持つ実体の種類。 */
	entity: EntityType;
	source_side: 'asp' | 'woo';
	applies: boolean;
	/** 取込みの前に未設定の数を案内する（Import タブ）。 */
	import_notice: boolean;
	label: string;
	description: string;
	source_heading: string;
	target_heading: string;
	unmapped_label: string;
	no_targets_help: string;
}

/**
 * `GET/PUT /settings/mappings/{platform}`の応答本体（`Admin\RestController`）。登録されたマッピングの種類ごとに `{key}_map`
 * （保存済みのマッピング。キー・値とも不透明な文字列ID）を返す。向き（`category` は Woo→ASP、ほかは ASP→Woo）は `MappingKindInfo`。
 */
export type SettingsMappingValues = Record< string, Record< string, string > >;

/**
 * `GET /settings/mappings/{platform}`の応答（E2-1・D19）。保存済みのマップに加えて、UIが選択肢を描画するための候補一覧と種類の一覧を返す。
 * `PUT`は候補一覧を返さない（保存操作そのものでは候補が変化しないうえ、ColorMe側は候補取得のたびに`categories.json`等への追加APIコールが
 * 発生し、実行中のジョブとレート制限を奪い合うため。`RestController::save_settings_mappings()`参照）。
 */
export interface SettingsMappings {
	asp_candidates: Record< string, MappingCandidate[] >;
	woo_candidates: Record< string, MappingCandidate[] >;
	kinds: MappingKindInfo[];
	[ mapKey: string ]: unknown;
}

/**
 * `GET/PUT /settings/export-options/{platform}`（D24）。プラットフォーム単位のエクスポート設定。
 * `push_images`は「商品画像をアップロードする」（既定オフ。プレミアムプラン限定でベータ版）。
 */
export interface ExportOptions {
	push_images: boolean;
}
