/* eslint-disable @wordpress/i18n-text-domain -- textDomain is supplied by consumer plugins */
import {
	Icon,
	__experimentalText as Text,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	Card,
	Button,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { chevronDown } from '@wordpress/icons';
import { useState } from '@wordpress/element';

import type { SettingsAccordionProps } from './types';

export default function SettingsAccordion({
	title,
	description,
	children,
	textDomain,
	contentId,
	headingId,
	descriptionId,
}: SettingsAccordionProps) {
	const [isOpen, setIsOpen] = useState(false);

	return (
		<Card className="prc-settings__accordion">
			<Button
				className="prc-settings__accordion-trigger"
				onClick={() => setIsOpen(!isOpen)}
				aria-expanded={isOpen}
				aria-controls={contentId}
				aria-describedby={descriptionId}
				aria-label={
					isOpen
						? sprintf(
								/* translators: %s: section title */
								__('Collapse %s settings', textDomain),
								title
							)
						: sprintf(
								/* translators: %s: section title */
								__('Expand %s settings', textDomain),
								title
							)
				}
			>
				<HStack alignment="top" justify="space-between">
					<VStack spacing={1}>
						<h3
							className="prc-settings__accordion-header"
							id={headingId}
						>
							{title}
						</h3>
						<Text
							className="prc-settings__accordion-description"
							id={descriptionId}
						>
							{description}
						</Text>
					</VStack>
					<Icon
						className={
							isOpen
								? 'prc-settings__accordion-chevron-up'
								: 'prc-settings__accordion-chevron-down'
						}
						icon={chevronDown}
					/>
				</HStack>
			</Button>
			<div
				className="prc-settings__accordion-form"
				role="region"
				id={contentId}
				aria-labelledby={headingId}
				hidden={!isOpen}
			>
				{children}
			</div>
		</Card>
	);
}
