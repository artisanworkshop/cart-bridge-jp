<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\License;

use CartBridgeJP\Pro\Admin\LicensePage;
use WP_Error;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- WP のフィルター・アクションの引数の並びに合わせて受ける（使わない引数がある）。

/**
 * Pro の更新を販売サイト（WooCommerce API Manager）から配る（R3-8c）。
 *
 * - WP 5.8 からの `Update URI` ヘッダーの仕組みを使う: ヘッダーの host の `update_plugins_{host}` フィルターで最新の版と package を返す。
 *   ヘッダーが wordpress.org 以外を指すので、wordpress.org に同じスラッグのプラグインが出ても更新として上書きされない（backlog `r3-7-monorepo/R1-L4`）。
 *   ヘッダーは販売サイトの URL が決まったときに入れる（それまで WP はこのフィルターを呼ばない。`LicenseConfigTest` が版 1.0.0 以上でヘッダーの欠けを止める）
 * - **キーを有効化していないサイトは問い合わせない**（D30: 更新は届かない。キーを入れていないサイトから外へ通信しない。2026-10-12 ユーザー決定）
 * - 期限後は、サーバーが update の時点でこのサイトの有効化を消し、package を付けずに返す（3.8.1 で実測）。WP は「自動更新は利用できません」と出すので、
 *   その下に理由を添える。ローカルの有効化は変えない（D14。`LicenseManager`）
 * - 「詳細を表示」（`plugins_api`）は、このスラッグの問い合わせを必ずここで答える（false を返すと WP が wordpress.org に同じスラッグを問い合わせ、
 *   別のプラグインの詳細が出うる）。有効化していない・取得できないときは `WP_Error`
 *
 * 公式のクライアントの `plugininformation`（応答を `maybe_unserialize()` する）は使わず、JSON の `information` を使う（リモートの値を unserialize しない）。
 */
final class UpdateChecker {

	public function __construct(
		private readonly LicenseStore $store,
		private readonly LicenseClient $client,
		private readonly string $update_uri,
		private readonly string $plugin_file
	) {}

	public static function create(): self {
		$headers = get_file_data( CBJP_PRO_FILE, [ 'UpdateURI' => 'Update URI' ] );

		return new self(
			new LicenseStore(),
			new LicenseClient( LicenseConfig::from_constants() ),
			$headers['UpdateURI'],
			plugin_basename( CBJP_PRO_FILE )
		);
	}

	/**
	 * ヘッダーの `Update URI` の host（WP の `wp_update_plugins()` と同じ求め方。フィルターの名前になる）。ヘッダーが無ければ null。
	 */
	public function update_host(): ?string {
		if ( '' === trim( $this->update_uri ) ) {
			return null;
		}

		$host = wp_parse_url( sanitize_url( $this->update_uri ), PHP_URL_HOST );

		return is_string( $host ) && '' !== $host ? $host : null;
	}

	public function register(): void {
		$host = $this->update_host();

		if ( null !== $host ) {
			add_filter( 'update_plugins_' . $host, [ $this, 'filter_update' ], 10, 3 );
		}

		add_filter( 'plugins_api', [ $this, 'filter_plugin_information' ], 10, 3 );
		add_action( 'in_plugin_update_message-' . $this->plugin_file, [ $this, 'render_update_message' ], 10, 2 );
	}

	/**
	 * `update_plugins_{host}`。このプラグインで、有効化済みのときだけ販売サイトに問い合わせる。失敗したら受け取った値をそのまま返す
	 * （WP はこの回の更新の情報を持たない）。
	 *
	 * @param mixed $update      ほかのフィルターが返した値（既定 false）。
	 * @param mixed $plugin_data プラグインのヘッダー。
	 * @param mixed $plugin_file プラグインのファイル（`cart-bridge-jp-pro/cart-bridge-jp-pro.php`）。
	 * @return mixed
	 */
	public function filter_update( mixed $update, mixed $plugin_data, mixed $plugin_file ): mixed {
		if ( $this->plugin_file !== $plugin_file ) {
			return $update;
		}

		$credentials = $this->credentials();

		if ( null === $credentials ) {
			return $update;
		}

		$result = $this->client->update( $credentials['key'], $credentials['instance'] );

		if ( ! $result->is_success() ) {
			return $update;
		}

		// 新しい版が無くても返す（WP は `no_update` に入れ、自動更新の切り替えを出す）。`id`・`plugin` は WP が埋める。
		$update = [
			'slug'           => LicenseClient::PLUGIN_SLUG,
			'version'        => $result->data['version'],
			'url'            => $result->data['url'],
			'package'        => $result->data['package'],
			'tested'         => $result->data['tested'],
			'upgrade_notice' => $result->data['upgrade_notice'],
		];

		// package の無い更新（期限後など）は自動更新させない（自動更新を有効にしていると、WP が新しい版ごとに取得を試みて失敗し、失敗のメールを送る。
		// `WP_Automatic_Updater::should_update()` の `disable_autoupdate`。R3-8c の review-loop R1-4）。
		if ( '' === $update['package'] ) {
			$update['disable_autoupdate'] = true;
		}

		return $update;
	}

	/**
	 * `plugins_api`。このスラッグの `plugin_information` だけを答える。
	 *
	 * @param mixed $result ほかのフィルターが返した値（既定 false）。
	 * @param mixed $action 問い合わせの種類。
	 * @param mixed $args   問い合わせの引数（`slug` を持つオブジェクト）。
	 * @return mixed
	 */
	public function filter_plugin_information( mixed $result, mixed $action, mixed $args ): mixed {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$slug = is_object( $args ) ? ( $args->slug ?? null ) : ( is_array( $args ) ? ( $args['slug'] ?? null ) : null );

		if ( LicenseClient::PLUGIN_SLUG !== $slug ) {
			return $result;
		}

		$credentials = $this->credentials();

		if ( null === $credentials ) {
			return new WP_Error(
				'cbjp_pro_plugin_information_unavailable',
				__( 'Details of Cart Bridge JP Pro are available after a license key is activated on this site.', 'cart-bridge-jp-pro' )
			);
		}

		$information = $this->client->information( $credentials['key'], $credentials['instance'] );

		if ( ! $information->is_success() ) {
			return new WP_Error(
				'cbjp_pro_plugin_information_failed',
				__( 'Details of Cart Bridge JP Pro could not be loaded from the license server.', 'cart-bridge-jp-pro' )
			);
		}

		$data = $information->data;

		return (object) [
			'name'         => '' !== $data['name'] ? $data['name'] : 'Cart Bridge JP Pro',
			'slug'         => LicenseClient::PLUGIN_SLUG,
			'version'      => $data['version'],
			'author'       => $data['author'],
			'homepage'     => $data['homepage'],
			'requires'     => $data['requires'],
			'tested'       => $data['tested'],
			'requires_php' => $data['requires_php'],
			'last_updated' => $data['last_updated'],
			'sections'     => $data['sections'],
		];
	}

	/**
	 * `in_plugin_update_message-{file}`。package の無い更新（期限後など）に、自動更新が使えない理由を添える。
	 *
	 * @param mixed $plugin_data プラグインのヘッダーと更新の情報。
	 * @param mixed $response    更新の情報（`filter_update()` の戻り値をもとに WP が作るオブジェクト）。
	 */
	public function render_update_message( mixed $plugin_data, mixed $response ): void {
		$package = is_object( $response ) ? ( $response->package ?? '' ) : '';

		if ( is_string( $package ) && '' !== $package ) {
			return;
		}

		// 期限後とは限らない（購入者が販売サイトのアカウントから有効化を外した場合も package は付かない）ので、設定画面での確認を案内する（R3-8c の review-loop R1-9）。
		printf(
			'<br />%1$s <a href="%2$s">%3$s</a>',
			esc_html__( 'Updates of Cart Bridge JP Pro are delivered while the license key activated on this site is within its update period.', 'cart-bridge-jp-pro' ),
			esc_url( LicensePage::page_url() ),
			esc_html__( 'Check the license status', 'cart-bridge-jp-pro' )
		);
	}

	/**
	 * 販売サイトへ送るキーと instance。有効化していない・キーを読めない・instance を用意できないときは null（問い合わせない）。
	 *
	 * @return array{key:string,instance:string}|null
	 */
	private function credentials(): ?array {
		if ( ! $this->store->is_activated() ) {
			return null;
		}

		$key = $this->store->key();

		if ( null === $key ) {
			return null;
		}

		$instance = $this->store->instance();

		return null === $instance ? null : [
			'key'      => $key,
			'instance' => $instance,
		];
	}
}
