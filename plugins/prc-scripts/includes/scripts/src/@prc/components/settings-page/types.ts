import type { ReactNode } from 'react';
import type { StoreDescriptor } from '@wordpress/data';

export interface SettingsApiResponse<TSettings> {
	settings?: TSettings;
	[key: string]: unknown;
}

export interface SettingsStoreState<TSettings> {
	settings: TSettings;
	isLoaded: boolean;
	[key: string]: unknown;
}

export interface SettingsAccordionProps {
	title: string;
	description: string;
	children: ReactNode;
	textDomain: string;
	contentId?: string;
	headingId?: string;
	descriptionId?: string;
}

export interface SettingsSubSectionProps {
	title: string;
	children: ReactNode;
	textDomain: string;
	isOpen: boolean;
	onToggle: () => void;
	contentId?: string;
}

export interface SettingsSectionConfig {
	slug: string;
	title: string;
	description: string;
	render: () => ReactNode;
}

export interface SettingsPageProps {
	title: string;
	description: ReactNode;
	textDomain: string;
	sections: SettingsSectionConfig[];
	onLoad: () => Promise<unknown>;
	errorLoadingLabel?: string;
	errorRetryLabel?: string;
	className?: string;
	idPrefix?: string;
}

export interface ConnectionBadgeProps {
	connected: boolean;
	connectedLabel?: string;
	disconnectedLabel?: string;
	textDomain?: string;
}

export interface SettingsSectionFooterProps {
	onSave: () => void | Promise<void>;
	isBusy?: boolean;
	error?: string | null;
	onDismissError?: () => void;
	saveLabel: string;
	savingLabel?: string;
}

export interface CreateSettingsStoreConfig<
	TSettings,
	TState extends SettingsStoreState<TSettings>,
	TResponse extends SettingsApiResponse<TSettings> =
		SettingsApiResponse<TSettings>,
> {
	name: string;
	defaultState: TState;
	getSettingsFromResponse?: (response: TResponse) => TSettings;
	mapResponseToState?: (
		state: TState,
		response: TResponse
	) => Partial<TState>;
	extraActions?: Record<
		string,
		(...args: never[]) => { type: string; [key: string]: unknown }
	>;
	extraReducer?: (
		state: TState,
		action: { type: string; [key: string]: unknown }
	) => TState | null;
	extraSelectors?: Record<string, (state: TState) => unknown>;
}

export interface CreateSettingsClientConfig<
	TSettings,
	TResponse extends SettingsApiResponse<TSettings> =
		SettingsApiResponse<TSettings>,
> {
	restPath: string;
	store: StoreDescriptor;
	successMessage: string;
	getSaveData?: () => TSettings;
	onResponse?: (response: TResponse) => void;
}

export interface CreatePartialSaveClientConfig<
	TResponse extends SettingsApiResponse<unknown> =
		SettingsApiResponse<unknown>,
> {
	restPath: string;
	successMessage: string;
	onResponse: (response: TResponse) => void;
}
