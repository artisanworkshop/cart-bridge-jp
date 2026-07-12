import { __ } from '@wordpress/i18n';

export default function LogsTab() {
	return (
		<p>
			{ __(
				'Job logs will appear here once a migration run starts.',
				'cart-bridge-jp'
			) }
		</p>
	);
}
