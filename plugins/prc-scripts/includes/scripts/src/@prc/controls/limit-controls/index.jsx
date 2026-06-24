import { useSelect } from '@wordpress/data';

// Check if the block is a child of a passed parent
function useLimitOnParents(args) {
	const { blockEditorStore, parentList, clientId } = args;
	const foundParentBlock = useSelect(
		(select) => {
			if (!blockEditorStore || !parentList || !clientId) {
				return false;
			}
			const { getBlockRootClientId, getBlockName } =
				select(blockEditorStore);
			const parentBlock = getBlockRootClientId(clientId);
			return parentList.includes(getBlockName(parentBlock));
		},
		[blockEditorStore, parentList, clientId]
	);
	return foundParentBlock;
}

export default function LimitControls({ children, checkParents = {} }) {
	let limited = false;
	// Check for parents
	limited = useLimitOnParents(checkParents);
	// Check if the controls were limited, if not return the enclosed group.
	return limited ? null : children;
}
