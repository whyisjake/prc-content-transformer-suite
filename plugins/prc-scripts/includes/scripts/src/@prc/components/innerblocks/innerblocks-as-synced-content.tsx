/* eslint-disable @wordpress/no-unsafe-wp-apis */
/* eslint-disable jsdoc/require-param -- Props are documented via InnerBlocksAsSyncedContentProps */
/**
 * WordPress Dependencies
 */
import type { ReactNode, Ref } from 'react';
import { useMemo, useEffect, useRef } from 'react';
import { useEntityBlockEditor, useEntityRecord } from '@wordpress/core-data';
import {
	useInnerBlocksProps,
	RecursionProvider,
	useHasRecursion,
	InnerBlocks,
	Warning,
} from '@wordpress/block-editor';
import { useMergeRefs } from '@wordpress/compose';

/**
 * Internal Dependencies
 */
import DetachBlocksToolbarControl from '../detach-blocks-toolbar-control';
import LoadingIndicator from '../loading-indicator';

export interface InnerBlocksAsSyncedContentProps {
	postId: number | null | undefined;
	postType?: string;
	postTypeLabel?: string;
	blockProps?: Record<string, unknown>;
	clientId: string;
	allowDetach?: boolean;
	/** Rendered when the entity record cannot be loaded. */
	renderMissing?: () => ReactNode;
	/** Called when the entity record resolves or updates. */
	onRecordChange?: (record: unknown) => void;
	children?: ReactNode;
	ref?: Ref<HTMLDivElement>;
}

/**
 * A version of InnerBlocks that will render the blocks from a post type given a postId and postType. Any changes to inner blocks will be saved back to the post type.
 *
 * This component supports @wordpress/sync for real-time multi-user editing.
 */
export default function InnerBlocksAsSyncedContent({
	postId,
	postType = 'post',
	postTypeLabel = '',
	blockProps = {},
	clientId,
	allowDetach = false,
	renderMissing,
	onRecordChange,
	children,
	ref: forwardedRef,
}: InnerBlocksAsSyncedContentProps) {
	const internalRef = useRef<HTMLDivElement>(null);
	const componentRef = forwardedRef ?? internalRef;
	// Preserve the useBlockProps() ref (needed for click-to-select) alongside our own.
	const ref = useMergeRefs([
		blockProps.ref as Ref<HTMLDivElement>,
		componentRef,
	]);

	const mergedBlockProps: Record<string, unknown> = {
		...blockProps,
		ref,
	};

	// core-data requires a numeric/string id; 0 is unused when postId is unset (record stays unresolved).
	const entityRecordId = postId ?? 0;
	const { record, isResolving, hasResolved } = useEntityRecord(
		'postType',
		postType,
		entityRecordId
	);
	const lookup = {
		id:
			postId === null || postId === undefined
				? undefined
				: String(postId),
	};
	const [blocks, onInput, onChange] = useEntityBlockEditor(
		'postType',
		postType,
		lookup
	);

	const isMissing =
		true === hasResolved && false === isResolving && undefined === record;

	const recursionKey = useMemo(() => {
		return `${postType}:${String(postId ?? '')}`;
	}, [postId, postType]);

	useEffect(() => {
		if (typeof onRecordChange !== 'function') {
			return;
		}
		onRecordChange(record);
	}, [record, onRecordChange]);

	const hasAlreadyRendered = useHasRecursion(recursionKey);

	const innerBlocksProps = useInnerBlocksProps(mergedBlockProps, {
		value: blocks,
		onInput,
		onChange,
		renderAppender: blocks?.length
			? undefined
			: InnerBlocks.ButtonBlockAppender,
	});

	if (hasAlreadyRendered) {
		return (
			<div {...mergedBlockProps}>
				<Warning>
					{`${postTypeLabel} cannot be rendered inside itself.`}
				</Warning>
			</div>
		);
	}

	if (isResolving || !hasResolved) {
		return (
			<div {...mergedBlockProps}>
				<Warning>
					<LoadingIndicator
						enabled={true}
						label={
							// LoadingIndicator default label is i18n-typed; dynamic string is intentional.
							`Loading ${postTypeLabel} …` as never
						}
					/>
				</Warning>
			</div>
		);
	}

	if (isMissing) {
		return (
			<div {...mergedBlockProps}>
				<Warning>
					{`A matching ${postTypeLabel.toLocaleLowerCase()} could not be found. It may be unavailable at this time.`}
					{renderMissing && (
						<div style={{ marginTop: '1em' }}>
							{renderMissing()}
						</div>
					)}
				</Warning>
			</div>
		);
	}

	return (
		<RecursionProvider uniqueId={recursionKey}>
			<div {...innerBlocksProps} />
			{allowDetach && (
				<DetachBlocksToolbarControl
					{...{
						blocks,
						clientId,
						label: `Detach %s blocks from ${postTypeLabel}`,
					}}
				/>
			)}
			{children}
		</RecursionProvider>
	);
}
