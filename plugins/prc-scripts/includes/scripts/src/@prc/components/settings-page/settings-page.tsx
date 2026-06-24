/* eslint-disable @wordpress/i18n-text-domain -- textDomain is supplied by consumer plugins */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Spinner,
	Notice,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';

import SettingsAccordion from './settings-accordion';
import type { SettingsPageProps } from './types';

import './style.scss';

export default function SettingsPage({
	title,
	description,
	textDomain,
	sections,
	onLoad,
	errorLoadingLabel,
	errorRetryLabel,
	className = 'prc-settings',
	idPrefix = 'prc-settings',
}: SettingsPageProps) {
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);

	useEffect(() => {
		onLoad()
			.then(() => setError(null))
			.catch((e: Error) => setError(e.message))
			.finally(() => setLoading(false));
	}, [onLoad]);

	return (
		<div className={className}>
			{error && (
				<Notice status="error" isDismissible={false}>
					<VStack spacing={2}>
						<span>
							{errorLoadingLabel ??
								__('Error loading settings:', textDomain)}{' '}
							{error}
						</span>
						{errorRetryLabel && <span>{errorRetryLabel}</span>}
					</VStack>
				</Notice>
			)}
			<VStack spacing={2} className="prc-settings__header">
				<h1>{title}</h1>
				<Text className="prc-settings__header-description">
					{description}
				</Text>
			</VStack>
			{loading ? (
				<div className="prc-settings__loading">
					<Spinner />
				</div>
			) : (
				!error && (
					<VStack spacing={4} className="prc-settings__content">
						<ul className="prc-settings__list">
							{sections.map((section) => {
								const contentId = `${idPrefix}-${section.slug}`;
								const headingId = `${idPrefix}-${section.slug}-heading`;
								const descriptionId = `${idPrefix}-${section.slug}-description`;

								return (
									<li
										key={section.slug}
										className="prc-settings__list-item"
									>
										<SettingsAccordion
											title={section.title}
											description={section.description}
											textDomain={textDomain}
											contentId={contentId}
											headingId={headingId}
											descriptionId={descriptionId}
										>
											{section.render()}
										</SettingsAccordion>
									</li>
								);
							})}
						</ul>
					</VStack>
				)
			)}
		</div>
	);
}
