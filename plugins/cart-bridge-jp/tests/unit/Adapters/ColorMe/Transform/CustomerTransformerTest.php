<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe\Transform;

use CartBridgeJP\Adapters\ColorMe\Transform\CustomerTransformer;
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Tests\Fixtures\FixtureLoader;
use WP_UnitTestCase;

final class CustomerTransformerTest extends WP_UnitTestCase {

	/**
	 * 住所3点（`pref_id`/`postal`/`address1`）を解決できる国内の請求先住所。
	 */
	private const JP_ADDRESS = [
		'address_1' => '千代田区千代田1-1-1',
		'state'     => 'JP13',
		'postcode'  => '1000001',
		'country'   => 'JP',
	];

	private CustomerTransformer $transformer;

	public function set_up(): void {
		parent::set_up();
		$this->transformer = new CustomerTransformer();
	}

	public function test_guest_customer_without_member_registration_is_excluded(): void {
		// フィクスチャの顧客は実APIレスポンスをそのまま保持しており、いずれも `member: false`
		// （会員登録前のゲスト購入スナップショット）。ログイン用アカウントを持たないため
		// Woo顧客アカウントとして作成する対象ではない。
		$raw = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];

		$this->assertFalse( $raw['member'] );
		$this->assertNull( $this->transformer->transform( $raw ) );
	}

	public function test_member_without_a_usable_email_is_excluded(): void {
		// docs/01-plan-colorme.mdの通りemailがWoo突合の唯一のキーであり、`mail`が欠損した
		// まま空文字を通すと複数の会員が同一の空emailで誤って同一WPユーザーに突合されたり、
		// アカウント作成に失敗したりする。swaggerのcustomerスキーマはmail: nullを許容する。
		$raw           = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];
		$raw['member'] = true;
		$raw['mail']   = null;

		$this->assertNull( $this->transformer->transform( $raw ) );
	}

	public function test_transforms_individual_customer(): void {
		$raw           = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];
		$raw['member'] = true;

		$customer = $this->transformer->transform( $raw );

		$this->assertSame( 'taro@example.com', $customer->email );
		$this->assertSame( '山田 太郎', $customer->name );
		$this->assertSame( 'ヤマダ タロウ', $customer->kana );
		$this->assertNull( $customer->company );
		$this->assertTrue( $customer->mailmag_opt_in );
		$this->assertSame( '175271257', $customer->extras['remote_id'] );
		$this->assertSame(
			[
				'postal'    => '1000001',
				'pref_id'   => 13,
				'pref_name' => '東京都',
				'address1'  => '千代田区千代田1-1-1',
				'address2'  => '株式会社サンプル サンプルマンション101',
				'country'   => 'JP',
			],
			$customer->address
		);
	}

	public function test_null_mailmag_opt_in_is_preserved_as_null_not_false(): void {
		$raw           = FixtureLoader::load( 'colorme', 'customers' )['customers'][2];
		$raw['member'] = true;

		$this->assertNull( $raw['receive_mail_magazine'] );

		$customer = $this->transformer->transform( $raw );

		$this->assertNull( $customer->mailmag_opt_in );
	}

	public function test_corporate_fields_are_mapped_even_when_absent_from_the_standard_form(): void {
		$raw           = FixtureLoader::load( 'colorme', 'customer_corporate_detail' )['customer'];
		$raw['member'] = true;

		$customer = $this->transformer->transform( $raw );

		$this->assertSame( '株式会社サンプル', $customer->company );
		$this->assertSame( '営業部', $customer->department );
	}

	public function test_null_hojin_and_busho_are_not_treated_as_an_error(): void {
		$raw           = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];
		$raw['member'] = true;

		$this->assertNull( $raw['hojin'] );
		$this->assertNull( $raw['busho'] );

		$customer = $this->transformer->transform( $raw );

		$this->assertNull( $customer->company );
		$this->assertNull( $customer->department );
	}

	public function test_other_field_maps_to_note(): void {
		$raw           = FixtureLoader::load( 'colorme', 'customers' )['customers'][2];
		$raw['member'] = true;

		$this->assertSame( 'テスト備考', $raw['other'] );

		$customer = $this->transformer->transform( $raw );

		$this->assertSame( 'テスト備考', $customer->note );
	}

	public function test_phone_falls_back_to_mobile_when_tel_is_absent(): void {
		$raw               = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];
		$raw['member']     = true;
		$raw['tel']        = null;
		$raw['tel_mobile'] = '09000000002';

		$customer = $this->transformer->transform( $raw );

		$this->assertSame( '09000000002', $customer->phone );
	}

	public function test_phone_prefers_tel_over_mobile_when_both_present(): void {
		$raw               = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];
		$raw['member']     = true;
		$raw['tel_mobile'] = '09000000002';

		$customer = $this->transformer->transform( $raw );

		$this->assertSame( '0300000001', $customer->phone );
	}

	public function test_overseas_pref_id_leaves_country_null_instead_of_forcing_jp(): void {
		// swagger customerスキーマ: pref_id=48は「海外」を表す特別値。
		$raw            = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];
		$raw['member']  = true;
		$raw['pref_id'] = 48;

		$customer = $this->transformer->transform( $raw );

		$this->assertNull( $customer->address['country'] );
		$this->assertSame( 48, $customer->address['pref_id'] );
	}

	/**
	 * @param array<string,mixed> $address `Woo\Reader\CustomerReader::address()`と同じキー
	 *   （`city`/`address_1`/`address_2`/`state`/`postcode`/`country`）。
	 */
	private static function exported_customer( array $address = [], ?string $phone = null ): CanonicalCustomer {
		return new CanonicalCustomer(
			'taro@example.com',
			'山田 太郎',
			'ヤマダ タロウ',
			'株式会社サンプル',
			'営業部',
			$address,
			$phone,
			'1990-01-01',
			true,
			'テスト備考'
		);
	}

	/**
	 * ネイティブWoo顧客（WCのJPロケールで`billing_city`/`billing_address_1`が別必須項目）の住所を
	 * ColorMeの単一`address1`（市区町村・番地）へ`city`+`address_1`の連結で組み立てることを確認する
	 * （R1レビューで判明: `city`を無視すると市区町村がまるごと欠落する）。
	 */
	public function test_to_create_payload_includes_required_and_optional_fields_when_resolvable(): void {
		$customer = self::exported_customer(
			[
				'city'      => '千代田区',
				'address_1' => '千代田1-1-1',
				'address_2' => 'サンプルマンション101',
				'state'     => 'JP13',
				'postcode'  => '1000001',
				'country'   => 'JP',
			],
			'0300000001'
		);

		$payload = $this->transformer->to_create_payload( $customer );

		$this->assertSame(
			[
				'name'                  => '山田 太郎',
				'mail'                  => 'taro@example.com',
				'postal'                => '1000001',
				'address1'              => '千代田区千代田1-1-1',
				'pref_id'               => 13,
				'address2'              => 'サンプルマンション101',
				'tel'                   => '0300000001',
				'furigana'              => 'ヤマダ タロウ',
				'hojin'                 => '株式会社サンプル',
				'busho'                 => '営業部',
				'birthday'              => '1990-01-01',
				'other'                 => 'テスト備考',
				'receive_mail_magazine' => true,
				'add_member'            => true,
			],
			$payload
		);
	}

	/**
	 * ColorMe由来の往復顧客は`Woo\Support\AddressMapper::to_woo()`が`city`を常に空文字列にする
	 * ため、連結しても`address_1`（元の単一文字列）がそのまま使われることを確認する。
	 */
	public function test_to_create_payload_leaves_address1_unchanged_when_city_is_absent(): void {
		$customer = self::exported_customer(
			[
				'address_1' => '千代田区千代田1-1-1',
				'state'     => 'JP13',
				'postcode'  => '1000001',
				'country'   => 'JP',
			],
			'0300000001'
		);

		$payload = $this->transformer->to_create_payload( $customer );

		$this->assertSame( '千代田区千代田1-1-1', $payload['address1'] );
	}

	public function test_to_create_payload_returns_null_when_pref_id_cannot_be_resolved(): void {
		// stateがColorMeのpref_idスキームに一致せず、countryも日本のため海外扱いとも
		// 断定できない状況。AddressMapper::pref_id_from_state 参照。
		$customer = self::exported_customer(
			[
				'address_1' => '千代田区千代田1-1-1',
				'state'     => '',
				'postcode'  => '1000001',
				'country'   => 'JP',
			],
			'0300000001'
		);

		$this->assertNull( $this->transformer->to_create_payload( $customer ) );
	}

	public function test_to_create_payload_returns_null_when_tel_is_missing(): void {
		$customer = self::exported_customer(
			[
				'address_1' => '千代田区千代田1-1-1',
				'state'     => 'JP13',
				'postcode'  => '1000001',
				'country'   => 'JP',
			],
			null
		);

		$this->assertNull( $this->transformer->to_create_payload( $customer ) );
	}

	/**
	 * ColorMeの`name`はPOST/PUTともswaggerで`maxLength: 50`。Wooの表示名・会社名はこの制限を
	 * 保証しないため、超過分をそのまま送ると確実に422になる（G1ゲートで判明, Codex, P2）。
	 */
	public function test_to_create_payload_returns_null_when_name_exceeds_the_api_length_limit(): void {
		$customer = new CanonicalCustomer(
			'taro@example.com',
			str_repeat( '山', 51 ),
			null,
			null,
			null,
			[
				'address_1' => '千代田区千代田1-1-1',
				'state'     => 'JP13',
				'postcode'  => '1000001',
				'country'   => 'JP',
			],
			'0300000001',
			null,
			null,
			null
		);

		$this->assertNull( $this->transformer->to_create_payload( $customer ) );
	}

	public function test_to_create_payload_resolves_overseas_pref_id_from_country(): void {
		$customer = self::exported_customer(
			[
				'address_1' => '123 Main St',
				'state'     => 'CA',
				'postcode'  => '90001',
				'country'   => 'US',
			],
			'1-555-0100'
		);

		$payload = $this->transformer->to_create_payload( $customer );

		$this->assertSame( 48, $payload['pref_id'] );
	}

	/**
	 * `city`+`address_1`の連結は区切り無しが日本語住所として正しいが、海外住所（`pref_id=48`）に
	 * 同じ規則を適用すると単語がくっつく（例: `'Los Angeles' . '123 Main St'`）。海外のみ半角
	 * スペースを挟むことを確認する（R2レビューで判明）。
	 */
	public function test_to_create_payload_separates_city_and_street_with_a_space_for_overseas_addresses(): void {
		$customer = self::exported_customer(
			[
				'city'      => 'Los Angeles',
				'address_1' => '123 Main St',
				'state'     => 'CA',
				'postcode'  => '90001',
				'country'   => 'US',
			],
			'1-555-0100'
		);

		$payload = $this->transformer->to_create_payload( $customer );

		$this->assertSame( 'Los Angeles 123 Main St, CA, US', $payload['address1'] );
	}

	/**
	 * ColorMeの顧客スキームには国・地域専用のフィールドが無いため、`state`/`country`を
	 * `address1`へ付記しないと海外顧客の州・国がどこにも残らず、市区町村・番地だけの
	 * 不完全な住所として無警告でexportされてしまう（G1ゲートで判明, Codex, P1）。
	 */
	public function test_to_create_payload_appends_state_and_country_for_overseas_addresses(): void {
		$customer = self::exported_customer(
			[
				'address_1' => '123 Main St',
				'state'     => 'CA',
				'postcode'  => '90001',
				'country'   => 'US',
			],
			'1-555-0100'
		);

		$payload = $this->transformer->to_create_payload( $customer );

		$this->assertSame( '123 Main St, CA, US', $payload['address1'] );
	}

	/**
	 * `country`が変更され海外(48)と判定された後も、古い`JPxx`形式の`state`（ColorMe内部の
	 * 都道府県コード）が陳腐化した値として残っている場合がある。これをそのまま地域情報として
	 * 付記すると、無意味なColorMe内部コードが海外住所に紛れ込む（G2ゲートで判明, Copilot）。
	 */
	public function test_to_create_payload_does_not_append_a_stale_colorme_prefecture_code_as_region(): void {
		$customer = self::exported_customer(
			[
				'address_1' => '123 Main St',
				'state'     => 'JP13',
				'postcode'  => '90001',
				'country'   => 'US',
			],
			'1-555-0100'
		);

		$payload = $this->transformer->to_create_payload( $customer );

		$this->assertSame( '123 Main St, US', $payload['address1'] );
	}

	/**
	 * 日本国内・往復顧客（`pref_id`が1-47）ではColorMeの`pref_id`自体が都道府県を表すため、
	 * `address1`へ`state`/`country`を付記しない（従来の往復文字列を変えないため）。
	 */
	public function test_to_create_payload_does_not_append_region_for_domestic_addresses(): void {
		$customer = self::exported_customer(
			[
				'address_1' => '千代田区千代田1-1-1',
				'state'     => 'JP13',
				'postcode'  => '1000001',
				'country'   => 'JP',
			],
			'0300000001'
		);

		$payload = $this->transformer->to_create_payload( $customer );

		$this->assertSame( '千代田区千代田1-1-1', $payload['address1'] );
	}

	/**
	 * swaggerの`tel`は`pattern: "^[\d-]+$"`だが、Wooの`billing_phone`は装飾目的の空白・括弧を
	 * 含みうる。明らかに装飾目的の文字だけを除去してから送ることを確認する（R1レビューで判明）。
	 */
	public function test_to_create_payload_normalizes_tel_by_stripping_spaces_and_parentheses(): void {
		$customer = self::exported_customer(
			[
				'address_1' => '千代田区千代田1-1-1',
				'state'     => 'JP13',
				'postcode'  => '1000001',
				'country'   => 'JP',
			],
			'090 (1234) 5678'
		);

		$payload = $this->transformer->to_create_payload( $customer );

		$this->assertSame( '09012345678', $payload['tel'] );
	}

	/**
	 * 除去してもパターンに一致しない値（国際番号の`+`付き等）は解決不能として扱い、新規作成を
	 * スキップする（`+`を機械的に取り除くと国番号が消えた別の番号に化けるため、桁を落とす変換は
	 * しない）。
	 */
	public function test_to_create_payload_returns_null_when_tel_cannot_be_normalized_to_the_required_pattern(): void {
		$customer = self::exported_customer(
			[
				'address_1' => '千代田区千代田1-1-1',
				'state'     => 'JP13',
				'postcode'  => '1000001',
				'country'   => 'JP',
			],
			'+1-555-0100'
		);

		$this->assertNull( $this->transformer->to_create_payload( $customer ) );
	}

	/**
	 * PCREの`$`は末尾改行の直前にもマッチするため、`^[0-9\-]+$`のままだと末尾に`\n`が混入した
	 * 値（外部データの改行混入等）を誤って「パターン適合」として通してしまい、確実に422になる
	 * 値をそのまま送ってしまう（R2レビューで判明）。`\z`で終端を固定し弾くことを確認する。
	 */
	public function test_to_create_payload_returns_null_when_tel_has_a_trailing_newline(): void {
		$customer = self::exported_customer(
			[
				'address_1' => '千代田区千代田1-1-1',
				'state'     => 'JP13',
				'postcode'  => '1000001',
				'country'   => 'JP',
			],
			"0300000001\n"
		);

		$this->assertNull( $this->transformer->to_create_payload( $customer ) );
	}

	public function test_to_create_payload_omits_receive_mail_magazine_when_unknown(): void {
		$customer = new CanonicalCustomer(
			'taro@example.com',
			'山田 太郎',
			null,
			null,
			null,
			[
				'address_1' => '千代田区千代田1-1-1',
				'state'     => 'JP13',
				'postcode'  => '1000001',
				'country'   => 'JP',
			],
			'0300000001',
			null,
			null,
			null
		);

		$payload = $this->transformer->to_create_payload( $customer );

		$this->assertArrayNotHasKey( 'receive_mail_magazine', $payload );
	}

	/**
	 * `PUT`はswagger上必須フィールドが無いが、実際は名前と住所（市区町村・番地）が無いと422になる（テストショップで実測。
	 * issue #100）。住所を1つも解決できない顧客の更新は、住所を省いて送らずに`null`（送信しない）にする。
	 */
	public function test_to_update_payload_returns_null_when_no_address_can_be_resolved(): void {
		$this->assertNull( $this->transformer->to_update_payload( self::exported_customer( [], '0300000001' ) ) );
	}

	/**
	 * `postal`/`address1`/`pref_id`は3点セットで解決できた場合のみ送る（1つだけ送ると「新しい郵便番号＋ColorMe側に残った
	 * 古い都道府県・住所」という内部矛盾した住所になりうる。R1レビューで判明）。そろわなければ更新自体を送らない
	 * （住所を省いて送ると422。issue #100）。`address2`だけがあっても送らない。
	 */
	public function test_to_update_payload_returns_null_when_the_address_is_only_partially_resolvable(): void {
		$customer = self::exported_customer(
			[
				'postcode'  => '1000001',
				'address_2' => 'サンプルマンション101',
				'state'     => '',
				'country'   => 'JP',
			],
			null
		);

		$this->assertNull( $this->transformer->to_update_payload( $customer ) );
	}

	/**
	 * 海外の顧客は`pref_id=48`になるが、郵便番号が無いと住所3点がそろわない（R3-1 で海外の会員の更新が毎回422になった形）。
	 */
	public function test_to_update_payload_returns_null_for_an_overseas_customer_without_a_postcode(): void {
		$customer = self::exported_customer(
			[
				'city'      => 'Hong Kong',
				'address_1' => '1 Example Road',
				'country'   => 'HK',
			],
			'0300000001'
		);

		$this->assertNull( $this->transformer->to_update_payload( $customer ) );
	}

	/**
	 * 住所3点がそろえば、海外の顧客も送る（電話番号のように解決できない任意の項目は省く）。
	 */
	public function test_to_update_payload_sends_an_overseas_customer_with_a_complete_address(): void {
		$customer = self::exported_customer(
			[
				'city'      => 'Los Angeles',
				'address_1' => '123 Main St',
				'state'     => 'CA',
				'postcode'  => '90001',
				'country'   => 'US',
			],
			'+1-555-0100'
		);

		$payload = $this->transformer->to_update_payload( $customer );

		$this->assertNotNull( $payload );
		$this->assertSame( 48, $payload['pref_id'] );
		$this->assertSame( '90001', $payload['postal'] );
		$this->assertSame( 'Los Angeles 123 Main St, CA, US', $payload['address1'] );
		$this->assertArrayNotHasKey( 'tel', $payload );
		$this->assertArrayNotHasKey( 'add_member', $payload );
	}

	/**
	 * 電話番号は作成だけの必須項目のまま（更新で必須かは未実測）。解決できなければ省いて送る。
	 */
	public function test_to_update_payload_omits_an_unresolvable_tel_but_still_sends(): void {
		$customer = self::exported_customer( self::JP_ADDRESS, null );

		$payload = $this->transformer->to_update_payload( $customer );

		$this->assertSame(
			[
				'name'                  => '山田 太郎',
				'mail'                  => 'taro@example.com',
				'postal'                => '1000001',
				'address1'              => '千代田区千代田1-1-1',
				'pref_id'               => 13,
				'furigana'              => 'ヤマダ タロウ',
				'hojin'                 => '株式会社サンプル',
				'busho'                 => '営業部',
				'birthday'              => '1990-01-01',
				'other'                 => 'テスト備考',
				'receive_mail_magazine' => true,
			],
			$payload
		);
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function names_for_the_api(): array {
		return [
			'empty'           => [ '', false ],
			'spaces only'     => [ "  \t", false ],
			'51 characters'   => [ str_repeat( '山', 51 ), false ],
			'50 characters'   => [ str_repeat( '山', 50 ), true ],
			'a single letter' => [ '山', true ],
		];
	}

	/**
	 * 名前は作成・更新とも必須で50文字以内（swagger の`maxLength: 50`。空なら「名前を入力してください」で422。
	 * 更新で51文字以上を送ると毎回422になっていた。backlog `e2-3-push-customer/G1-name-length-on-update`）。
	 *
	 * @dataProvider names_for_the_api
	 */
	public function test_update_and_create_require_a_name_the_api_accepts( string $name, bool $sendable ): void {
		$customer = new CanonicalCustomer( 'taro@example.com', $name, null, null, null, self::JP_ADDRESS, '0300000001', null, null, null );

		$this->assertSame( $sendable, null !== $this->transformer->to_update_payload( $customer ), 'update' );
		$this->assertSame( $sendable, null !== $this->transformer->to_create_payload( $customer ), 'create' );
	}
}
