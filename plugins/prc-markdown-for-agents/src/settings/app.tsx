import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';
import { SettingsPage } from '@prc/components';

import './style.scss';
import './store';
import { fetchSettings } from './api';
import AboutSection from './components/about-section';
import CategoriesSection from './components/categories-section';
import FeaturedPostsSection from './components/featured-posts-section';
import AdditionalResourcesSection from './components/additional-resources-section';

const TEXT_DOMAIN = 'prc-markdown-for-agents';

function getLlmsTxtUrl(): string {
	const settings = (
		window as Window & {
			prcMarkdownForAgentsSettings?: { llmsTxtUrl?: string };
		}
	).prcMarkdownForAgentsSettings;

	return settings?.llmsTxtUrl ?? '/llms.txt';
}

export default function SettingsApp() {
	const llmsTxtUrl = getLlmsTxtUrl();

	return (
		<SettingsPage
			title={__('Markdown for Agents Settings', TEXT_DOMAIN)}
			description={
				<>
					{__(
						'Curate /llms.txt content for AI agents and crawlers.',
						TEXT_DOMAIN
					)}{' '}
					<ExternalLink href={llmsTxtUrl}>
						{__('View /llms.txt', TEXT_DOMAIN)}
					</ExternalLink>
				</>
			}
			textDomain={TEXT_DOMAIN}
			idPrefix="prc-markdown-for-agents-settings"
			sections={[
				{
					slug: 'about',
					title: __('About', TEXT_DOMAIN),
					description: __(
						'Edit the site summary, About description, and link bullets in /llms.txt.',
						TEXT_DOMAIN
					),
					render: () => <AboutSection />,
				},
				{
					slug: 'categories',
					title: __('Categories', TEXT_DOMAIN),
					description: __(
						'Choose which top-level categories appear under ## Categories in /llms.txt.',
						TEXT_DOMAIN
					),
					render: () => <CategoriesSection />,
				},
				{
					slug: 'featured-posts',
					title: __('Featured Posts', TEXT_DOMAIN),
					description: __(
						'Choose and order posts to feature in the /llms.txt directory.',
						TEXT_DOMAIN
					),
					render: () => <FeaturedPostsSection />,
				},
				{
					slug: 'additional-resources',
					title: __('Additional Resources', TEXT_DOMAIN),
					description: __(
						'Add custom subsections under Additional Resources in /llms.txt.',
						TEXT_DOMAIN
					),
					render: () => <AdditionalResourcesSection />,
				},
			]}
			onLoad={fetchSettings}
		/>
	);
}
