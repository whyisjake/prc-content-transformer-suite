/**
 * WordPress Dependencies
 */
import { __ } from '@wordpress/i18n';
import { BlockControls } from '@wordpress/block-editor';
import { ToolbarButton } from '@wordpress/components';
import { update } from '@wordpress/icons';

/**
 * Internal Dependencies
 */
import { useMinWordGate } from '@prc/hooks';

interface AISuggestToolbarButtonProps {
	/** Toolbar button label / tooltip. Defaults to "Suggest with AI". */
	label?: string;

	/** Click handler, typically opens a modal. */
	onClick: () => void;

	/** BlockControls toolbar group. Defaults to 'other'. */
	group?: string;

	/**
	 * Minimum word count required in the post content before the toolbar
	 * button is enabled. When omitted or 0 the check is skipped.
	 */
	minWords?: number;
}

/**
 * Block toolbar button with the update icon for AI features.
 *
 * Wraps `BlockControls` and `ToolbarButton` so consumers only need
 * to provide an `onClick` handler (usually to open a modal).
 * @param root0
 * @param root0.label
 * @param root0.onClick
 * @param root0.group
 */
export default function AISuggestToolbarButton({
	label,
	onClick,
	group = 'other',
	minWords,
}: AISuggestToolbarButtonProps) {
	const { hasEnoughContent } = useMinWordGate(minWords);
	return (
		<BlockControls group={group}>
			<ToolbarButton
				icon={update}
				label={label || __('Suggest with AI', 'prc-platform-core')}
				onClick={onClick}
				isDisabled={!hasEnoughContent}
			/>
		</BlockControls>
	);
}
