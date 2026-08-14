<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe\Transform;

use CartBridgeJP\Canonical\CanonicalCustomer;

/**
 * `GET /v1/customers.json` `GET /v1/customers/{id}.json` の1要素を `CanonicalCustomer` へ変換する。
 *
 * `hojin`（法人名）・`busho`（部署名）は管理画面の標準「顧客登録」フォームに入力欄が無いが、
 * CSV一括登録等の別経路で設定されている場合がある。null でも異常とはしない
 * （`docs/03-design-decisions.md` §9 補足）。
 */
final class CustomerTransformer {

	/**
	 * @param array<string,mixed> $raw `customers[]` の1要素、または `customer` 単体。
	 */
	public function transform( array $raw ): CanonicalCustomer {
		$remote_id = Cast::to_string_or_null( $raw['id'] ?? null ) ?? '';

		return new CanonicalCustomer(
			Cast::to_string_or_null( $raw['mail'] ?? null ) ?? '',
			Cast::to_string_or_null( $raw['name'] ?? null ) ?? '',
			Cast::to_string_or_null( $raw['furigana'] ?? null ),
			Cast::to_string_or_null( $raw['hojin'] ?? null ),
			Cast::to_string_or_null( $raw['busho'] ?? null ),
			$this->address( $raw ),
			Cast::to_string_or_null( $raw['tel'] ?? null ),
			Cast::to_string_or_null( $raw['birthday'] ?? null ),
			Cast::to_bool_or_null( $raw['receive_mail_magazine'] ?? null ),
			Cast::to_string_or_null( $raw['other'] ?? null ),
			$this->extras( $raw, $remote_id )
		);
	}

	/**
	 * F1-4（WooRepository）が読むキー契約をここで確定する。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function address( array $raw ): array {
		return [
			'postal'    => Cast::to_string_or_null( $raw['postal'] ?? null ),
			'pref_id'   => Cast::to_int_or_null( $raw['pref_id'] ?? null ),
			'pref_name' => Cast::to_string_or_null( $raw['pref_name'] ?? null ),
			'address1'  => Cast::to_string_or_null( $raw['address1'] ?? null ),
			'address2'  => Cast::to_string_or_null( $raw['address2'] ?? null ),
			'country'   => 'JP',
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
