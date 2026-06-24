/**
 * WordPress Dependencies
 */
import { useSelect } from '@wordpress/data';
import { count } from '@wordpress/wordcount';

interface UseMinWordGateReturn {
	/** Whether the gate is active (i.e. minWords was provided and > 0). */
	gateActive: boolean;
	/** Whether the post content meets the minimum word threshold. Always true when gate is inactive. */
	hasEnoughContent: boolean;
}

/**
 * Gates a UI control based on whether the current post content meets a
 * minimum word count. When `minWords` is omitted or 0 the gate is inactive
 * and `hasEnoughContent` is always `true`, preserving default behaviour.
 *
 * @param minWords Minimum number of words required. Omit or pass 0 to disable.
 * @return `{ gateActive, hasEnoughContent }`
 *
 * @example
 * ```tsx
 * const { gateActive, hasEnoughContent } = useMinWordGate(150);
 * <Button disabled={!hasEnoughContent}>Suggest</Button>
 * {gateActive && !hasEnoughContent && <p>Add more content (150 words).</p>}
 * ```
 */
export default function useMinWordGate(
	minWords?: number
): UseMinWordGateReturn {
	const content = useSelect(
		(select) =>
			// @ts-ignore -- `core/editor` types are not always complete
			select('core/editor').getEditedPostContent(),
		[]
	);

	if (!minWords || minWords <= 0) {
		return { gateActive: false, hasEnoughContent: true };
	}

	const wordCount = count(content || '', 'words');
	return { gateActive: true, hasEnoughContent: wordCount >= minWords };
}
