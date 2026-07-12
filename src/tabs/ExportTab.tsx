import { __ } from '@wordpress/i18n';

export default function ExportTab() {
	return (
		<p>
			{ __(
				'Export will be available once a platform adapter is connected (Phase 4).',
				'cart-bridge-jp'
			) }
		</p>
	);
}
