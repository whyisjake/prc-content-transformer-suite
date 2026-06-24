import { createSettingsClient } from '@prc/components';
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';

import { store } from './store';
import type { ApiResponse } from './types';

const REST_PATH = '/prc-markdown-for-agents/v1/settings';

export const { fetchSettings } = createSettingsClient({
	restPath: REST_PATH,
	store,
	successMessage: '',
});

async function persistSettings(successMessage: string): Promise<ApiResponse> {
	const { setFromResponse } = dispatch(store);
	const settings = select(store).getSettings();
	const response = (await apiFetch({
		path: REST_PATH,
		method: 'POST',
		data: settings,
	})) as ApiResponse;
	setFromResponse(response);
	dispatch(noticesStore).createSuccessNotice(successMessage, {
		type: 'snackbar',
	});
	return response;
}

export async function saveFeaturedPosts(): Promise<ApiResponse> {
	return persistSettings(
		__('Featured posts saved.', 'prc-markdown-for-agents')
	);
}

export async function saveAdditionalResources(): Promise<ApiResponse> {
	return persistSettings(
		__('Additional resources saved.', 'prc-markdown-for-agents')
	);
}

export async function saveAbout(): Promise<ApiResponse> {
	return persistSettings(
		__('About section saved.', 'prc-markdown-for-agents')
	);
}

export async function saveCategories(): Promise<ApiResponse> {
	return persistSettings(
		__('Categories section saved.', 'prc-markdown-for-agents')
	);
}
