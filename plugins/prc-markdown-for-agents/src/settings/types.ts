export interface AdditionalResourcesBlock {
	id: string;
	title: string;
	body: string;
}

export interface AboutLink {
	id: string;
	title: string;
	url: string;
	description: string;
}

export interface AboutSettings {
	site_summary: string;
	about_description: string;
	about_links: AboutLink[];
}

export interface CategoryAvailable {
	id: number;
	name: string;
	slug: string;
	count: number;
	permalink: string;
}

export interface Settings extends AboutSettings {
	category_ids: number[];
	featured_posts: number[];
	additional_resources_blocks: AdditionalResourcesBlock[];
}

export interface ResolvedFeaturedPost {
	id: number;
	title: string;
	excerpt: string;
	permalink: string;
	edit_link: string;
}

export interface ApiResponse {
	settings: Settings;
	featured_posts_resolved: ResolvedFeaturedPost[];
	categories_available: CategoryAvailable[];
}

export interface SettingsStoreState {
	settings: Settings;
	featuredPostsResolved: ResolvedFeaturedPost[];
	categoriesAvailable: CategoryAvailable[];
	isLoaded: boolean;
}
