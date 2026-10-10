import { __, sprintf } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import { missingScopes } from '../connection-scopes';
import { tabHref } from '../hash-route';
import type { Connection } from '../types';

interface MissingScopesNoticeProps {
	connection: Connection | null;
}

/**
 * Import／Export タブの案内（R3-6c2）: 選んだ接続のトークンに、拡張（Pro アドオンなど）が要求するスコープが付与されていないとき、
 * その拡張が足す種類は選択肢に出ない（サーバーが出さない）ので、理由と Connections タブ（再接続）へのリンクを出す。
 * どの種類が出ないかはサーバーの宣言に無いので、種類の名前は挙げない。
 * @param root0
 * @param root0.connection 選んだ接続
 */
export default function MissingScopesNotice( {
	connection,
}: MissingScopesNoticeProps ) {
	if ( null === connection || 0 === missingScopes( connection ).length ) {
		return null;
	}

	return (
		<Notice status="warning" isDismissible={ false }>
			<p>
				{ sprintf(
					/* translators: %s: platform label, e.g. "Color Me Shop" */
					__(
						'The connection to %s does not have the permissions that some kinds of records need, so those kinds are not listed.',
						'cart-bridge-jp'
					),
					connection.label
				) }
			</p>
			<p>
				<a href={ tabHref( 'connections' ) }>
					{ __(
						'Reconnect on the Connections tab',
						'cart-bridge-jp'
					) }
				</a>
			</p>
		</Notice>
	);
}
