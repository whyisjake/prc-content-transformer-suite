/**
 * WordPress Dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { update } from '@wordpress/icons';

/**
 * Internal Dependencies
 */
import { useMinWordGate } from '@prc/hooks';

interface AISuggestButtonProps {
	/** Button label text. Defaults to "Suggest with AI". */
	label?: string;

	/** Button text. Defaults to "Suggest with AI". */
	text?: string;

	/** Click handler to trigger the AI suggestion. */
	onClick: () => void;

	/** When true the button is disabled to indicate work in progress. */
	isLoading?: boolean;

	/** Explicitly disable the button regardless of loading state. */
	disabled?: boolean;

	/** Button visual variant. Defaults to 'secondary'. */
	variant?: 'secondary' | 'tertiary';

	/** Button size. Defaults to 'compact'. */
	size?: 'default' | 'compact' | 'small';

	/** Whether the button stretches to fill its container. Defaults to true. */
	fullWidth?: boolean;

	/**
	 * Minimum word count required in the post content before the button is
	 * enabled. When omitted or 0 the check is skipped and the button behaves
	 * as it always has. When provided, the button is disabled and a hint
	 * message is shown until the threshold is met.
	 */
	minWords?: number;
}

/**
 * Standardised AI trigger button with the update icon.
 *
 * Replaces the hand-rolled buttons previously duplicated across
 * prc-schema-seo, prc-related-posts, prc-social, and prc-block-library.
 * @param root0
 * @param root0.label
 * @param root0.text
 * @param root0.onClick
 * @param root0.isLoading
 * @param root0.disabled
 * @param root0.variant
 * @param root0.size
 * @param root0.fullWidth
 */
export default function AISuggestButton({
	label = __('Suggest with AI', 'prc-platform-core'),
	text = __('Suggest with AI', 'prc-platform-core'),
	onClick,
	isLoading = false,
	disabled = false,
	variant = 'secondary',
	size = 'default',
	fullWidth = true,
	minWords,
}: AISuggestButtonProps) {
	const { gateActive, hasEnoughContent } = useMinWordGate(minWords);
	const buttonSize = useMemo(() => {
		if (fullWidth) {
			return 'default';
		}
		return size;
	}, [fullWidth, size]);
	return (
		<>
			<Button
				className="prc-ai-suggest-button"
				variant={variant}
				size={buttonSize}
				isBusy={isLoading}
				icon={update}
				onClick={onClick}
				disabled={isLoading || disabled || !hasEnoughContent}
				style={
					fullWidth
						? { width: '100%', justifyContent: 'center' }
						: undefined
				}
				label={label}
				__next40pxDefaultSize={!fullWidth}
			>
				{text}
			</Button>
			{gateActive && !hasEnoughContent && (
				<p className="components-base-control__help">
					{sprintf(
						/* translators: %d: minimum word count */
						__(
							'Add more content to enable AI suggestions (approximately %d words).',
							'prc-platform-core'
						),
						minWords
					)}
				</p>
			)}
		</>
	);
}
