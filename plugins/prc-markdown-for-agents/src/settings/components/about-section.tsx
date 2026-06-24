import { useEffect, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import {
	Button,
	TextControl,
	TextareaControl,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { arrowDown, arrowUp, trash } from '@wordpress/icons';

import { store as settingsStore } from '../store';
import { saveAbout } from '../api';
import type { AboutLink, AboutSettings } from '../types';

function createLink(): AboutLink {
	return {
		id: `about-link-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
		title: '',
		url: '',
		description: '',
	};
}

export default function AboutSection() {
	const { aboutSettings } = useSelect((select) => {
		const settings = select(settingsStore).getSettings();
		return {
			aboutSettings: {
				site_summary: settings.site_summary,
				about_description: settings.about_description,
				about_links: settings.about_links,
			},
		};
	}, []);
	const { setAboutSettings } = useDispatch(settingsStore);
	const [draft, setDraft] = useState<AboutSettings>(aboutSettings);
	const [isSaving, setIsSaving] = useState(false);

	useEffect(() => {
		setDraft(aboutSettings);
	}, [aboutSettings]);

	const updateLink = (index: number, updates: Partial<AboutLink>) => {
		setDraft((current) => ({
			...current,
			about_links: current.about_links.map((link, linkIndex) =>
				linkIndex === index ? { ...link, ...updates } : link
			),
		}));
	};

	const moveLink = (index: number, direction: -1 | 1) => {
		const nextIndex = index + direction;
		if (nextIndex < 0 || nextIndex >= draft.about_links.length) {
			return;
		}
		setDraft((current) => {
			const nextLinks = [...current.about_links];
			const [item] = nextLinks.splice(index, 1);
			nextLinks.splice(nextIndex, 0, item);
			return { ...current, about_links: nextLinks };
		});
	};

	const removeLink = (index: number) => {
		setDraft((current) => ({
			...current,
			about_links: current.about_links.filter(
				(_, linkIndex) => linkIndex !== index
			),
		}));
	};

	const handleSave = async () => {
		setIsSaving(true);
		const linksToSave = draft.about_links.filter(
			(link) => link.title.trim() !== '' && link.url.trim() !== ''
		);
		const settingsToSave: AboutSettings = {
			...draft,
			about_links: linksToSave,
		};
		setAboutSettings(settingsToSave);
		try {
			await saveAbout();
		} finally {
			setIsSaving(false);
		}
	};

	return (
		<VStack spacing={4} className="markdown-for-agents-settings__about">
			<TextareaControl
				label={__('Site summary', 'prc-markdown-for-agents')}
				value={draft.site_summary}
				onChange={(site_summary) =>
					setDraft((current) => ({ ...current, site_summary }))
				}
				rows={3}
				help={__(
					'Rendered as the blockquote under the site title in /llms.txt.',
					'prc-markdown-for-agents'
				)}
			/>
			<TextareaControl
				label={__('About description', 'prc-markdown-for-agents')}
				value={draft.about_description}
				onChange={(about_description) =>
					setDraft((current) => ({ ...current, about_description }))
				}
				rows={3}
				help={__(
					'Introductory paragraph under ## About in /llms.txt.',
					'prc-markdown-for-agents'
				)}
			/>
			<Text>
				{__(
					'Link bullets under ## About in /llms.txt.',
					'prc-markdown-for-agents'
				)}
			</Text>
			{draft.about_links.length === 0 ? (
				<Text>
					{__('No About links yet.', 'prc-markdown-for-agents')}
				</Text>
			) : (
				<VStack spacing={4}>
					{draft.about_links.map((link, index) => (
						<div
							key={link.id}
							className="markdown-for-agents-settings__resources-block"
						>
							<div className="markdown-for-agents-settings__resources-block-header">
								<TextControl
									label={__(
										'Link title',
										'prc-markdown-for-agents'
									)}
									value={link.title}
									onChange={(title) =>
										updateLink(index, { title })
									}
								/>
								<div className="markdown-for-agents-settings__resources-block-actions">
									<Button
										icon={arrowUp}
										label={sprintfMoveUp(link.title)}
										onClick={() => moveLink(index, -1)}
										disabled={index === 0}
										size="small"
									/>
									<Button
										icon={arrowDown}
										label={sprintfMoveDown(link.title)}
										onClick={() => moveLink(index, 1)}
										disabled={
											index ===
											draft.about_links.length - 1
										}
										size="small"
									/>
									<Button
										icon={trash}
										label={sprintfRemove(link.title)}
										onClick={() => removeLink(index)}
										isDestructive
										size="small"
									/>
								</div>
							</div>
							<TextControl
								label={__('URL', 'prc-markdown-for-agents')}
								value={link.url}
								onChange={(url) => updateLink(index, { url })}
								type="url"
							/>
							<TextControl
								label={__(
									'Link description',
									'prc-markdown-for-agents'
								)}
								value={link.description}
								onChange={(description) =>
									updateLink(index, { description })
								}
								help={__(
									'Optional suffix after the link in /llms.txt.',
									'prc-markdown-for-agents'
								)}
							/>
						</div>
					))}
				</VStack>
			)}
			<Button
				variant="secondary"
				onClick={() =>
					setDraft((current) => ({
						...current,
						about_links: [...current.about_links, createLink()],
					}))
				}
			>
				{__('Add link', 'prc-markdown-for-agents')}
			</Button>
			<Button variant="primary" onClick={handleSave} isBusy={isSaving}>
				{__('Save About section', 'prc-markdown-for-agents')}
			</Button>
		</VStack>
	);
}

function sprintfMoveUp(title: string): string {
	const label =
		title.trim() || __('Untitled link', 'prc-markdown-for-agents');
	return `${__('Move up', 'prc-markdown-for-agents')}: ${label}`;
}

function sprintfMoveDown(title: string): string {
	const label =
		title.trim() || __('Untitled link', 'prc-markdown-for-agents');
	return `${__('Move down', 'prc-markdown-for-agents')}: ${label}`;
}

function sprintfRemove(title: string): string {
	const label =
		title.trim() || __('Untitled link', 'prc-markdown-for-agents');
	return `${__('Remove', 'prc-markdown-for-agents')}: ${label}`;
}
