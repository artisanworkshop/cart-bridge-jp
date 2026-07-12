import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ConnectionsTab from './tabs/ConnectionsTab';
import ImportTab from './tabs/ImportTab';
import ExportTab from './tabs/ExportTab';
import LogsTab from './tabs/LogsTab';
import ToolsTab from './tabs/ToolsTab';

const TABS = [
	{
		id: 'connections',
		label: __( 'Connections', 'cart-bridge-jp' ),
		Component: ConnectionsTab,
	},
	{
		id: 'import',
		label: __( 'Import', 'cart-bridge-jp' ),
		Component: ImportTab,
	},
	{
		id: 'export',
		label: __( 'Export', 'cart-bridge-jp' ),
		Component: ExportTab,
	},
	{ id: 'logs', label: __( 'Logs', 'cart-bridge-jp' ), Component: LogsTab },
	{
		id: 'tools',
		label: __( 'Tools', 'cart-bridge-jp' ),
		Component: ToolsTab,
	},
] as const;

type TabId = ( typeof TABS )[ number ][ 'id' ];

function currentTabFromHash(): TabId {
	const hash = window.location.hash.replace( /^#\//, '' );

	return ( TABS.find( ( tab ) => tab.id === hash )?.id ??
		TABS[ 0 ].id ) as TabId;
}

export default function App() {
	const [ activeTab, setActiveTab ] = useState< TabId >(
		currentTabFromHash()
	);

	useEffect( () => {
		const onHashChange = () => setActiveTab( currentTabFromHash() );

		window.addEventListener( 'hashchange', onHashChange );

		return () => window.removeEventListener( 'hashchange', onHashChange );
	}, [] );

	const ActiveComponent =
		TABS.find( ( tab ) => tab.id === activeTab )?.Component ??
		ConnectionsTab;

	return (
		<div className="cbjp-admin">
			<h1>{ __( 'Cart Bridge JP', 'cart-bridge-jp' ) }</h1>
			<nav className="cbjp-admin__tabs">
				{ TABS.map( ( tab ) => (
					<a
						key={ tab.id }
						href={ `#/${ tab.id }` }
						className={ tab.id === activeTab ? 'is-active' : '' }
					>
						{ tab.label }
					</a>
				) ) }
			</nav>
			<div className="cbjp-admin__content">
				<ActiveComponent />
			</div>
		</div>
	);
}
