import { createSettingsStore } from '@prc/components';
import type {
	AboutSettings,
	AdditionalResourcesBlock,
	ApiResponse,
	Settings,
	SettingsStoreState,
} from './types';

export const STORE_NAME = 'prc/markdown-for-agents-settings';

export const store = createSettingsStore<
	Settings,
	SettingsStoreState,
	ApiResponse
>({
	name: STORE_NAME,
	defaultState: {
		settings: {
			site_summary: '',
			about_description: '',
			about_links: [],
			category_ids: [],
			featured_posts: [],
			additional_resources_blocks: [],
		},
		featuredPostsResolved: [],
		categoriesAvailable: [],
		isLoaded: false,
	},
	mapResponseToState: (_state, response) => ({
		featuredPostsResolved: response.featured_posts_resolved,
		categoriesAvailable: response.categories_available ?? [],
	}),
	extraActions: {
		setFeaturedPosts(ids: number[]) {
			return { type: 'SET_FEATURED_POSTS', payload: ids };
		},
		setCategoryIds(ids: number[]) {
			return { type: 'SET_CATEGORY_IDS', payload: ids };
		},
		setAdditionalResourcesBlocks(blocks: AdditionalResourcesBlock[]) {
			return {
				type: 'SET_ADDITIONAL_RESOURCES_BLOCKS',
				payload: blocks,
			};
		},
		setAboutSettings(about: AboutSettings) {
			return { type: 'SET_ABOUT_SETTINGS', payload: about };
		},
	},
	extraReducer: (state, action) => {
		switch (action.type) {
			case 'SET_FEATURED_POSTS':
				return {
					...state,
					settings: {
						...state.settings,
						featured_posts: action.payload as number[],
					},
				};
			case 'SET_CATEGORY_IDS':
				return {
					...state,
					settings: {
						...state.settings,
						category_ids: action.payload as number[],
					},
				};
			case 'SET_ADDITIONAL_RESOURCES_BLOCKS':
				return {
					...state,
					settings: {
						...state.settings,
						additional_resources_blocks:
							action.payload as AdditionalResourcesBlock[],
					},
				};
			case 'SET_ABOUT_SETTINGS': {
				const about = action.payload as AboutSettings;
				return {
					...state,
					settings: {
						...state.settings,
						site_summary: about.site_summary,
						about_description: about.about_description,
						about_links: about.about_links,
					},
				};
			}
			default:
				return null;
		}
	},
	extraSelectors: {
		getFeaturedPostsResolved(state: SettingsStoreState) {
			return state.featuredPostsResolved;
		},
		getCategoriesAvailable(state: SettingsStoreState) {
			return state.categoriesAvailable;
		},
	},
});
