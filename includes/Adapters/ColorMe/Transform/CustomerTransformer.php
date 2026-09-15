<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe\Transform;

use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Woo\Support\AddressMapper;

/**
 * `GET /v1/customers.json` `GET /v1/customers/{id}.json` の1要素を `CanonicalCustomer` へ変換する。
 *
 * `hojin`（法人名）・`busho`（部署名）は管理画面の標準「顧客登録」フォームに入力欄が無いが、
 * CSV一括登録等の別経路で設定されている場合がある。null でも異常とはしない
 * （`docs/03-design-decisions.md` §9 補足）。
 */
final class CustomerTransformer {

	/**
	 * `member`（swagger: 「会員登録済みであるか否か」）が `false` の顧客データは、
	 * ログイン用アカウントを持たないゲスト購入時のスナップショットであり、Woo顧客アカウントとして
	 * 作成する対象ではない（受注側の請求先は `OrderTransformer::customer_snapshot()` が別途保持する）。
	 * `mail` が欠損した会員データも同様に除外する（`docs/01-plan-colorme.md` の通りemailがWoo突合の
	 * キーであり、空文字のまま通すと複数の顧客が同一の空emailで誤って同一WPユーザーに突合されたり、
	 * アカウント作成に失敗したりする）。いずれも `null` を返し、呼び出し側（顧客の全量スキャン等）で
	 * スキップさせる。
	 *
	 * @param array<string,mixed> $raw `customers[]` の1要素、または `customer` 単体。
	 */
	public function transform( array $raw ): ?CanonicalCustomer {
		if ( true !== ( $raw['member'] ?? null ) ) {
			return null;
		}

		$email = Cast::to_string_or_null( $raw['mail'] ?? null );

		if ( null === $email ) {
			return null;
		}

		$remote_id = Cast::to_string_or_null( $raw['id'] ?? null ) ?? '';

		return new CanonicalCustomer(
			$email,
			Cast::to_string_or_null( $raw['name'] ?? null ) ?? '',
			Cast::to_string_or_null( $raw['furigana'] ?? null ),
			Cast::to_string_or_null( $raw['hojin'] ?? null ),
			Cast::to_string_or_null( $raw['busho'] ?? null ),
			$this->address( $raw ),
			Cast::first_non_empty( $raw['tel'] ?? null, $raw['tel_mobile'] ?? null ),
			Cast::to_string_or_null( $raw['birthday'] ?? null ),
			Cast::to_bool_or_null( $raw['receive_mail_magazine'] ?? null ),
			Cast::to_string_or_null( $raw['other'] ?? null ),
			$this->extras( $raw, $remote_id )
		);
	}

	/**
	 * ColorMeの`name`はPOST/PUTともswaggerで`maxLength: 50`。ネイティブWooの表示名・会社名は
	 * この制限を保証しないため、超過分をそのまま送ると確実に422になる（G1ゲートで判明,
	 * Codex, P2）。
	 */
	private const NAME_MAX_LENGTH = 50;

	/**
	 * `POST /v1/customers`（新規作成）向けのペイロード。必須フィールド（`name`/`mail`/`pref_id`/
	 * `postal`/`address1`/`tel`）のうち`pref_id`/`postal`/`address1`/`tel`はWoo顧客の請求先住所・
	 * 電話番号から解決できない場合がある（`Woo\Reader\CustomerReader`はWooネイティブの住所を
	 * そのまま運ぶだけで、ColorMe固有スキームへの変換はここが責務を持つ）。`name`はWooの表示名が
	 * swaggerの`maxLength: 50`を超えうる。いずれも解決できなければ`null`を返し、呼び出し元
	 * （`ColorMeAdapter::push_customer()`）にフェイルクローズさせる（送信すると確実に422になる
	 * ため。理由を問わず`WarningCode::CUSTOMER_REQUIRED_FIELD_MISSING`で一律に警告する。
	 * 呼び出し元は`to_create_payload()`が`null`を返した理由を区別しない）。
	 * `add_member: true`を常に付与し、ColorMeの`member`（会員登録済みフラグ）を立てる
	 * （`transform()`が`member === true`の行のみWoo顧客として取り込む契約と対称。付けないと
	 * 作成した顧客がColorMe側でログイン不可のゲスト相当になり、往復インポートで再度取り込めない）。
	 *
	 * @return ?array<string,mixed>
	 */
	public function to_create_payload( CanonicalCustomer $customer ): ?array {
		$address = AddressMapper::to_asp_address_payload( 'colorme', $customer->address );
		$tel     = Cast::normalize_tel( $customer->phone );

		if ( ! isset( $address['pref_id'], $address['postal'], $address['address1'] )
			|| null === $tel
			|| mb_strlen( $customer->name ) > self::NAME_MAX_LENGTH
		) {
			return null;
		}

		return array_merge(
			$this->base_payload( $customer, $address, $tel ),
			[ 'add_member' => true ]
		);
	}

	/**
	 * `PUT /v1/customers/{id}`（更新）向けのペイロード。swagger上必須フィールドが無い部分更新の
	 * ため、`to_create_payload()`と異なり解決できなかった項目は単に省略する
	 * （ColorMe側の既存値をそのまま残す。`ProductTransformer::to_update_payload()`と同じ方針）。
	 *
	 * @return array<string,mixed>
	 */
	public function to_update_payload( CanonicalCustomer $customer ): array {
		return $this->base_payload( $customer, AddressMapper::to_asp_address_payload( 'colorme', $customer->address ), Cast::normalize_tel( $customer->phone ) );
	}

	/**
	 * 作成・更新で共通の任意項目。`CanonicalCustomer`が運ばない項目（`fax`/`sex`/`tel_mobile`/
	 * `answer_free_form1-3`）は`Woo\Reader\CustomerReader`が`extras`を常に空配列で構築するため
	 * 送信できない（往復時のデータ欠損はE2-4の往復E2Eで扱う既知の制限。`docs/03-design-decisions.md`
	 * §10.2「E2-3 PR-B」参照）。
	 *
	 * @param array<string,mixed> $address `address_payload()`の戻り値。
	 * @return array<string,mixed>
	 */
	private function base_payload( CanonicalCustomer $customer, array $address, ?string $tel ): array {
		$payload = array_merge(
			[
				'name' => $customer->name,
				'mail' => $customer->email,
			],
			$address
		);

		if ( null !== $tel ) {
			$payload['tel'] = $tel;
		}

		$furigana = Cast::to_string_or_null( $customer->kana );

		if ( null !== $furigana ) {
			$payload['furigana'] = $furigana;
		}

		$hojin = Cast::to_string_or_null( $customer->company );

		if ( null !== $hojin ) {
			$payload['hojin'] = $hojin;
		}

		$busho = Cast::to_string_or_null( $customer->department );

		if ( null !== $busho ) {
			$payload['busho'] = $busho;
		}

		$birthday = Cast::to_string_or_null( $customer->birthday );

		if ( null !== $birthday ) {
			$payload['birthday'] = $birthday;
		}

		$other = Cast::to_string_or_null( $customer->note );

		if ( null !== $other ) {
			$payload['other'] = $other;
		}

		// 未知（null）を`false`（メルマガ拒否）と決め打ちしない。既存のColorMe側設定を
		// 誤って上書きしないよう、値が判明している場合のみ送信する。
		if ( null !== $customer->mailmag_opt_in ) {
			$payload['receive_mail_magazine'] = $customer->mailmag_opt_in;
		}

		return $payload;
	}

	/**
	 * F1-4（WooRepository）が読むキー契約をここで確定する。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function address( array $raw ): array {
		$pref_id = Cast::to_int_or_null( $raw['pref_id'] ?? null );

		return [
			'postal'    => Cast::to_string_or_null( $raw['postal'] ?? null ),
			'pref_id'   => $pref_id,
			'pref_name' => Cast::to_string_or_null( $raw['pref_name'] ?? null ),
			'address1'  => Cast::to_string_or_null( $raw['address1'] ?? null ),
			'address2'  => Cast::to_string_or_null( $raw['address2'] ?? null ),
			// pref_id=48は「海外」を表す特別値（swagger customerスキーマ）。実際の国は
			// ColorMe側から提供されないため、JP固定にせずnullのまま残す。
			'country'   => 48 === $pref_id ? null : 'JP',
		];
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function extras( array $raw, string $remote_id ): array {
		return [
			'remote_id'         => $remote_id,
			'fax'               => Cast::to_string_or_null( $raw['fax'] ?? null ),
			'tel_mobile'        => Cast::to_string_or_null( $raw['tel_mobile'] ?? null ),
			'sex'               => Cast::to_string_or_null( $raw['sex'] ?? null ),
			'points'            => Cast::to_int_or_null( $raw['points'] ?? null ),
			'member'            => Cast::to_bool_or_null( $raw['member'] ?? null ),
			'sales_count'       => Cast::to_int_or_null( $raw['sales_count'] ?? null ),
			'answer_free_form1' => Cast::to_string_or_null( $raw['answer_free_form1'] ?? null ),
			'answer_free_form2' => Cast::to_string_or_null( $raw['answer_free_form2'] ?? null ),
			'answer_free_form3' => Cast::to_string_or_null( $raw['answer_free_form3'] ?? null ),
			'memo'              => Cast::to_string_or_null( $raw['memo'] ?? null ),
			'membership'        => is_array( $raw['membership'] ?? null ) ? $raw['membership'] : null,
		];
	}
}
