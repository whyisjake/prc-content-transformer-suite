/**
 * Hook for fetching and polling Apple News status for a post.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import type { StatusResponse, AppleNewsState } from './types';

const POLL_INTERVAL_MS = 5000;
const STALE_PENDING_MS = 10 * 60 * 1000; // 10 minutes

interface UseAppleNewsStatusReturn extends StatusResponse {
	state: AppleNewsState;
	isStalePending: boolean;
	isLoading: boolean;
	refetch: () => void;
}

const defaultStatus: StatusResponse = {
	post_id: 0,
	article_id: null,
	share_url: null,
	pending: null,
	error: null,
	published: false,
};

function deriveState(status: StatusResponse): AppleNewsState {
	if (status.error) {
		return 'error';
	}
	if (status.pending) {
		return 'pending';
	}
	if (status.published) {
		return 'published';
	}
	return 'not-published';
}

function isStalePendingTimestamp(pending: string | null): boolean {
	if (!pending) {
		return false;
	}
	const lockAge = Date.now() - new Date(pending).getTime();
	return lockAge > STALE_PENDING_MS;
}

export default function useAppleNewsStatus(
	postId: number
): UseAppleNewsStatusReturn {
	const [status, setStatus] = useState<StatusResponse>(defaultStatus);
	const [isLoading, setIsLoading] = useState(true);

	const fetchStatus = useCallback(async () => {
		if (!postId) {
			return;
		}
		try {
			const data = await apiFetch<StatusResponse>({
				path: `/prc-apple-news/v1/status?post_id=${postId}`,
			});
			setStatus(data);
		} catch {
			// Silently ignore transient fetch errors during polling.
		} finally {
			setIsLoading(false);
		}
	}, [postId]);

	// Initial fetch.
	useEffect(() => {
		void fetchStatus();
	}, [fetchStatus]);

	// 5-second polling while a push is pending.
	useEffect(() => {
		if (!status.pending) {
			return;
		}
		const intervalId = setInterval(() => {
			void fetchStatus();
		}, POLL_INTERVAL_MS);
		return () => clearInterval(intervalId);
	}, [status.pending, fetchStatus]);

	return {
		...status,
		state: deriveState(status),
		isStalePending: isStalePendingTimestamp(status.pending),
		isLoading,
		refetch: fetchStatus,
	};
}
