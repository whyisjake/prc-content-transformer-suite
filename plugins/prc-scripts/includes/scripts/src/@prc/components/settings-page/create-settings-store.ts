import { createReduxStore, register } from '@wordpress/data';

import type {
	CreateSettingsStoreConfig,
	SettingsApiResponse,
	SettingsStoreState,
} from './types';

const SET_FROM_RESPONSE = 'SET_FROM_RESPONSE';

export function createSettingsStore<
	TSettings,
	TState extends SettingsStoreState<TSettings>,
	TResponse extends SettingsApiResponse<TSettings> =
		SettingsApiResponse<TSettings>,
>(config: CreateSettingsStoreConfig<TSettings, TState, TResponse>) {
	const {
		name,
		defaultState,
		getSettingsFromResponse,
		mapResponseToState,
		extraActions = {},
		extraReducer,
		extraSelectors = {},
	} = config;

	const baseActions = {
		setFromResponse(response: TResponse) {
			return {
				type: SET_FROM_RESPONSE,
				payload: response,
			};
		},
		...extraActions,
	};

	type BaseAction = ReturnType<typeof baseActions.setFromResponse>;
	type ExtraAction = ReturnType<
		(typeof extraActions)[keyof typeof extraActions]
	>;
	type Action = BaseAction | ExtraAction;

	const reducer = (state: TState = defaultState, action: Action): TState => {
		if (action.type === SET_FROM_RESPONSE) {
			const response = action.payload as TResponse;
			const settings = getSettingsFromResponse
				? getSettingsFromResponse(response)
				: response.settings;
			const nextState = {
				...state,
				settings,
				isLoaded: true,
			} as TState;

			if (mapResponseToState) {
				return {
					...nextState,
					...mapResponseToState(nextState, response),
				};
			}

			return nextState;
		}

		if (extraReducer) {
			const result = extraReducer(state, action);
			if (result !== null) {
				return result;
			}
		}

		return state;
	};

	const baseSelectors = {
		getSettings(s: TState): TSettings {
			return s.settings;
		},
		isLoaded(s: TState): boolean {
			return s.isLoaded;
		},
		...extraSelectors,
	};

	const store = createReduxStore(name, {
		reducer,
		actions: baseActions,
		selectors: baseSelectors,
	});

	register(store);

	return store;
}
