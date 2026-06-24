import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

import type {
	CreatePartialSaveClientConfig,
	CreateSettingsClientConfig,
	SettingsApiResponse,
} from './types';

export function createSettingsClient<
	TSettings,
	TResponse extends SettingsApiResponse<TSettings> =
		SettingsApiResponse<TSettings>,
>(config: CreateSettingsClientConfig<TSettings, TResponse>) {
	const { restPath, store, successMessage, getSaveData, onResponse } = config;

	async function fetchSettings(): Promise<TResponse> {
		const { setFromResponse } = dispatch(store);
		const response = (await apiFetch({ path: restPath })) as TResponse;
		setFromResponse(response);
		if (onResponse) {
			onResponse(response);
		}
		return response;
	}

	async function saveSettings(): Promise<TResponse> {
		const { setFromResponse } = dispatch(store);
		const { createSuccessNotice } = dispatch(noticesStore);
		const settings = getSaveData
			? getSaveData()
			: (select(store).getSettings() as TSettings);

		const response = (await apiFetch({
			path: restPath,
			method: 'POST',
			data: settings,
		})) as TResponse;

		setFromResponse(response);
		if (onResponse) {
			onResponse(response);
		}

		createSuccessNotice(successMessage, { type: 'snackbar' });

		return response;
	}

	return { fetchSettings, saveSettings };
}

export function createPartialSaveClient<
	TResponse extends SettingsApiResponse<unknown> =
		SettingsApiResponse<unknown>,
>(config: CreatePartialSaveClientConfig<TResponse>) {
	const { restPath, successMessage, onResponse } = config;

	async function savePartial(
		data: Record<string, unknown>
	): Promise<TResponse> {
		const { createSuccessNotice } = dispatch(noticesStore);

		const response = (await apiFetch({
			path: restPath,
			method: 'POST',
			data,
		})) as TResponse;

		onResponse(response);

		createSuccessNotice(successMessage, { type: 'snackbar' });

		return response;
	}

	return { savePartial };
}
