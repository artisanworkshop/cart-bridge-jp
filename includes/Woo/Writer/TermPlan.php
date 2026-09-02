<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalTag;

/**
 * `TermWriter::plan()` の戻り値。
 */
final readonly class TermPlan {

	/**
	 * @param array{description:string,parent:int} $args
	 * @param array<int,string>                     $warnings
	 */
	public function __construct(
		public CanonicalCategory|CanonicalTag $item,
		public string $name,
		public array $args,
		public array $warnings,
		public ?int $preresolved_conflict_term_id,
		public bool $existing_term_exists
	) {}
}
