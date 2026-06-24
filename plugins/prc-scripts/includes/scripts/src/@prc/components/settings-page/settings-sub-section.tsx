/* eslint-disable @wordpress/i18n-text-domain -- textDomain is supplied by consumer plugins */
import {
	Icon,
	Button,
	__experimentalText as Text,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { chevronDown } from '@wordpress/icons';

import type { SettingsSubSectionProps } from './types';

export default function SettingsSubSection({
	title,
	children,
	textDomain,
	isOpen,
	onToggle,
	contentId,
}: SettingsSubSectionProps) {
	return (
		<div className="prc-settings__sub-section">
			<Button
				className="prc-settings__sub-section-trigger"
				onClick={onToggle}
				aria-expanded={isOpen}
				aria-controls={contentId}
				aria-label={
					isOpen
						? sprintf(
								/* translators: %s: subsection title */
								__('Collapse %s', textDomain),
								title
							)
						: sprintf(
								/* translators: %s: subsection title */
								__('Expand %s', textDomain),
								title
							)
				}
			>
				<HStack alignment="center" justify="space-between">
					<Text>{title}</Text>
					<Icon
						className={
							isOpen
								? 'prc-settings__sub-section-chevron-up'
								: 'prc-settings__sub-section-chevron-down'
						}
						icon={chevronDown}
					/>
				</HStack>
			</Button>
			{isOpen && (
				<div
					className="prc-settings__sub-section-content"
					id={contentId}
				>
					{children}
				</div>
			)}
		</div>
	);
}
