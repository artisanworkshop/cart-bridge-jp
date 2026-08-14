export interface Capabilities {
	can_create_category: boolean;
	can_create_order: boolean;
	can_fetch_customers: boolean;
	can_update_customer: boolean;
	can_push_images: boolean;
	can_create_coupon: boolean;
	has_coupons: boolean;
	has_tags: boolean;
	has_reviews: boolean;
	has_variants: boolean;
	rate_limit_per_minute: number;
}

export interface ConnectionField {
	key: string;
	label: string;
	type: 'text' | 'password' | 'oauth_button';
	required: boolean;
	help: string | null;
}

export interface Connection {
	platform: string;
	label: string;
	connected: boolean;
	needs_reconnect: boolean;
	has_settings: boolean;
	masked_token: string | null;
	capabilities: Capabilities;
	callback_url: string | null;
	connection_fields: ConnectionField[];
}

export interface AuthorizeUrlResponse {
	url: string;
	redirect_uri: string;
}

export interface TestConnectionResult {
	ok: boolean;
	shop_name: string | null;
	message: string | null;
}
