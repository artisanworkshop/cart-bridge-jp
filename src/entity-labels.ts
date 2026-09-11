import { __ } from '@wordpress/i18n';
import type { EntityType } from './types';

/**
 * エンティティの表示名。Import タブ・Tools タブ・検証レポートで共有する。
 */
export const ENTITY_LABELS: Record< EntityType, string > = {
	category: __( 'Categories', 'cart-bridge-jp' ),
	tag: __( 'Tags', 'cart-bridge-jp' ),
	product: __( 'Products', 'cart-bridge-jp' ),
	customer: __( 'Customers', 'cart-bridge-jp' ),
	order: __( 'Orders', 'cart-bridge-jp' ),
	stock: __( 'Stock', 'cart-bridge-jp' ),
	coupon: __( 'Coupons', 'cart-bridge-jp' ),
	review: __( 'Reviews', 'cart-bridge-jp' ),
};
