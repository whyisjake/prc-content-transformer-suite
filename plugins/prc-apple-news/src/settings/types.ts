/**
 * Apple News Settings — TypeScript interfaces for REST API responses.
 */

export interface SettingsResponse {
	api_key: string;
	channel_uuid: string;
	has_secret: boolean;
}

export interface TestResponse {
	success: boolean;
	channel_name: string | null;
	error: string | null;
}
