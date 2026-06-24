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
import { saveAdditionalResources } from '../api';
import type { AdditionalResourcesBlock } from '../types';

function createBlock(): AdditionalResourcesBlock {
	return {
		id: `block-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
		title: '',
		body: '',
	};
}

export default function AdditionalResourcesSection() {
	const { blocks } = useSelect((select) => {
		const storeSelect = select(settingsStore);
		return {
			blocks: storeSelect.getSettings().additional_resources_blocks,
		};
	}, []);
	const { setAdditionalResourcesBlocks } = useDispatch(settingsStore);
	const [draftBlocks, setDraftBlocks] =
		useState<AdditionalResourcesBlock[]>(blocks);
	const [isSaving, setIsSaving] = useState(false);

	useEffect(() => {
		setDraftBlocks(blocks);
	}, [blocks]);

	const updateBlock = (
		index: number,
		updates: Partial<AdditionalResourcesBlock>
	) => {
		setDraftBlocks((current) =>
			current.map((block, blockIndex) =>
				blockIndex === index ? { ...block, ...updates } : block
			)
		);
	};

	const moveBlock = (index: number, direction: -1 | 1) => {
		const nextIndex = index + direction;
		if (nextIndex < 0 || nextIndex >= draftBlocks.length) {
			return;
		}
		setDraftBlocks((current) => {
			const next = [...current];
			const [item] = next.splice(index, 1);
			next.splice(nextIndex, 0, item);
			return next;
		});
	};

	const removeBlock = (index: number) => {
		setDraftBlocks((current) =>
			current.filter((_, blockIndex) => blockIndex !== index)
		);
	};

	const handleSave = async () => {
		setIsSaving(true);
		const blocksToSave = draftBlocks.filter(
			(block) => block.title.trim() !== ''
		);
		setAdditionalResourcesBlocks(blocksToSave);
		try {
			await saveAdditionalResources();
		} finally {
			setIsSaving(false);
		}
	};

	return (
		<VStack spacing={4} className="markdown-for-agents-settings__resources">
			<Text>
				{__(
					'Each block becomes a subsection under Additional Resources in /llms.txt.',
					'prc-markdown-for-agents'
				)}
			</Text>
			{draftBlocks.length === 0 ? (
				<Text>
					{__(
						'No additional resource blocks yet.',
						'prc-markdown-for-agents'
					)}
				</Text>
			) : (
				<VStack spacing={4}>
					{draftBlocks.map((block, index) => (
						<div
							key={block.id}
							className="markdown-for-agents-settings__resources-block"
						>
							<div className="markdown-for-agents-settings__resources-block-header">
								<TextControl
									label={__(
										'Subsection title',
										'prc-markdown-for-agents'
									)}
									value={block.title}
									onChange={(title) =>
										updateBlock(index, { title })
									}
								/>
								<div className="markdown-for-agents-settings__resources-block-actions">
									<Button
										icon={arrowUp}
										label={sprintfMoveUp(block.title)}
										onClick={() => moveBlock(index, -1)}
										disabled={index === 0}
										size="small"
									/>
									<Button
										icon={arrowDown}
										label={sprintfMoveDown(block.title)}
										onClick={() => moveBlock(index, 1)}
										disabled={
											index === draftBlocks.length - 1
										}
										size="small"
									/>
									<Button
										icon={trash}
										label={sprintfRemove(block.title)}
										onClick={() => removeBlock(index)}
										isDestructive
										size="small"
									/>
								</div>
							</div>
							<TextareaControl
								label={__(
									'Subsection content',
									'prc-markdown-for-agents'
								)}
								value={block.body}
								onChange={(body) =>
									updateBlock(index, { body })
								}
								rows={8}
								help={__(
									'Plain text or markdown. Rendered under ### in /llms.txt.',
									'prc-markdown-for-agents'
								)}
							/>
						</div>
					))}
				</VStack>
			)}
			<Button
				variant="secondary"
				onClick={() => setDraftBlocks([...draftBlocks, createBlock()])}
			>
				{__('Add block', 'prc-markdown-for-agents')}
			</Button>
			<Button variant="primary" onClick={handleSave} isBusy={isSaving}>
				{__('Save additional resources', 'prc-markdown-for-agents')}
			</Button>
		</VStack>
	);
}

function sprintfMoveUp(title: string): string {
	const label =
		title.trim() || __('Untitled subsection', 'prc-markdown-for-agents');
	return `${__('Move up', 'prc-markdown-for-agents')}: ${label}`;
}

function sprintfMoveDown(title: string): string {
	const label =
		title.trim() || __('Untitled subsection', 'prc-markdown-for-agents');
	return `${__('Move down', 'prc-markdown-for-agents')}: ${label}`;
}

function sprintfRemove(title: string): string {
	const label =
		title.trim() || __('Untitled subsection', 'prc-markdown-for-agents');
	return `${__('Remove', 'prc-markdown-for-agents')}: ${label}`;
}
