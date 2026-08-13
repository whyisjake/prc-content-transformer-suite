/**
 * Public API for @prc/components.
 * Re-exports all components from their modules.
 */

// Detach Blocks Toolbar Control
export { default as DetachBlocksToolbarControl } from './detach-blocks-toolbar-control';

// Entity Create New Modal
export { default as EntityCreateNewModal } from './entity-create-new-modal';

// Entity Pattern Modal
export { default as EntityPatternModal } from './entity-pattern-modal';

// Heading Level Toolbar
export { default as HeadingLevelToolbar } from './heading-level-toolbar';

// Icon Picker
export { default as IconPicker } from './icon-picker';
export type {
	IconPickerProps,
	IconPickerValue,
	IconPosition,
} from './icon-picker';

// InnerBlocks utilities
export {
	InnerBlocksAsContextTemplate,
	useInnerBlocksContextAsQuery,
	InnerBlocksAsSyncedContent,
} from './innerblocks';

// Inspector Popout Panel
export { default as InspectorPopoutPanel } from './inspector-popout-panel';

// List Store
export {
	registerListStore,
	ListStoreItem,
	actions as listStoreActions,
	reducer as listStoreReducer,
} from './list-store';

// Loading Indicator
export { default as LoadingIndicator } from './loading-indicator';

// Mailchimp Segment List
export { default as MailchimpSegmentList } from './mailchimp-segment-list';

// Mailchimp Segment Select
export { default as MailchimpSegmentSelect } from './mailchimp-segment-select';

// Marked Range Control
export { default as MarkedRangeControl } from './marked-range-control';

// Media Dropzone
export { default as MediaDropZone } from './media-dropzone';

// Media Image Slot
export { default as MediaImageSlot, Overlay } from './media-image-slot';

// Placeholder
export { default as Placeholder } from './placeholder';

// Responsive Image
export { default as ResponsiveImage } from './responsive-image';

// Social Image Generator
export {
	SocialImageGenerator,
	renderToCanvas,
	generateImage,
	generateImageFile,
	DEFAULT_PLATFORM_SIZES,
	PLATFORM_NAMES,
	DEFAULT_FONT_FAMILY_OPTIONS,
	ColorPickerButton,
} from './social-image-generator';

// Social Preview (wrapper + individual previews + types)
export {
	SocialPreview,
	FacebookPreview,
	TwitterPreview,
	ThreadsPreview,
	BlueskyPreview,
	SlackPreview,
	DiscordPreview,
	GooglePreview,
	LinkedInPreview,
	TeamsPreview,
	InstagramStoryPreview,
	InstagramReelPreview,
	InstagramPostPreview,
} from './social-preview';

// Styled Component Context
export { default as StyledComponentContext } from './styled-component-context';

// Taxonomy Select
export { default as TaxonomySelect } from './taxonomy-select';

// Term Select
export { default as TermSelect } from './term-select';

// Transition
export { default as Transition } from './transition';

// URL Search
export { URLSearchField, URLSearchToolbar } from './url-search';

// WP Entity Search
export { default as WPEntitySearch } from './wp-entity-search';

// AI Components
export {
	useAISuggest,
	AISuggestButton,
	AISuggestToolbarButton,
	AILoadingIndicator,
	AISuggestModal,
	AISuggestionPreview,
	AISuggestionsList,
} from './ai';

// Editable Text
export { EditableText } from './social-preview/editable-text';
export { EditableMedia } from './social-preview/editable-media';
export type {
	EditablePreviewProps,
	EditablePreviewCallbacks,
} from './social-preview/types';

// Browser Chrome
export { MobileSafariChrome, DesktopSafariChrome } from './browser-chrome';

// Character Counter
export { default as CharacterCounter } from './character-counter';
export type { CharacterCounterProps } from './character-counter';

// Settings Page (WP Admin)
export {
	SettingsAccordion,
	SettingsSubSection,
	SettingsPage,
	ConnectionBadge,
	SettingsSectionFooter,
	createSettingsStore,
	createSettingsClient,
	createPartialSaveClient,
	useSettingsDraft,
	mountSettingsPage,
} from './settings-page';
export type {
	SettingsApiResponse,
	SettingsStoreState,
	SettingsAccordionProps,
	SettingsSubSectionProps,
	SettingsSectionConfig,
	SettingsPageProps,
	ConnectionBadgeProps,
	SettingsSectionFooterProps,
	CreateSettingsStoreConfig,
	CreateSettingsClientConfig,
	CreatePartialSaveClientConfig,
} from './settings-page';
