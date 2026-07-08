import { __ } from '@wordpress/i18n';

export default function ImportTab() {
	return (
		<p>
			{ __(
				'Import will be available once a platform adapter is connected (Phase 1).',
				'cart-bridge-jp'
			) }
		</p>
	);
}
