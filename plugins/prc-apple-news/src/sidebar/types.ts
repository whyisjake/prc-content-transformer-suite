/**
 * Apple News Sidebar — TypeScript interfaces.
 */

export interface StatusResponse {
	post_id: number;
	article_id: string | null;
	share_url: string | null;
	pending: string | null;
	error: string | null;
	published: boolean;
}

export type AppleNewsState =
	| 'error'
	| 'pending'
	| 'published'
	| 'not-published';
