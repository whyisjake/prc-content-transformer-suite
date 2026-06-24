import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { arrowUp, arrowDown, trash } from '@wordpress/icons';
import { WPEntitySearch } from '@prc/components';

import type { ResolvedFeaturedPost } from '../types';

interface OrderedPostPickerProps {
	ids: number[];
	resolved: ResolvedFeaturedPost[];
	onChange: (ids: number[]) => void;
}

export default function OrderedPostPicker({
	ids,
	resolved,
	onChange,
}: OrderedPostPickerProps) {
	const resolvedById = useMemo(() => {
		const map = new Map<number, ResolvedFeaturedPost>();
		resolved.forEach((item) => {
			map.set(item.id, item);
		});
		return map;
	}, [resolved]);

	const handleAdd = (record: { entityId?: number | string }) => {
		const id = Number(record?.entityId);
		if (!id || ids.includes(id)) {
			return;
		}
		onChange([...ids, id]);
	};

	const moveItem = (index: number, direction: -1 | 1) => {
		const nextIndex = index + direction;
		if (nextIndex < 0 || nextIndex >= ids.length) {
			return;
		}
		const next = [...ids];
		const [item] = next.splice(index, 1);
		next.splice(nextIndex, 0, item);
		onChange(next);
	};

	const removeItem = (index: number) => {
		onChange(ids.filter((_, itemIndex) => itemIndex !== index));
	};

	return (
		<VStack spacing={4} className="markdown-for-agents-settings__picker">
			<WPEntitySearch
				placeholder={__('Search posts…', 'prc-markdown-for-agents')}
				entityType="postType"
				entitySubType="post"
				entityStatus={['publish']}
				onSelect={handleAdd}
				clearOnSelect
				showExcerpt
			/>
			{ids.length === 0 ? (
				<Text>
					{__(
						'No featured posts selected — recent posts will be shown by default.',
						'prc-markdown-for-agents'
					)}
				</Text>
			) : (
				<ol className="markdown-for-agents-settings__picker-list">
					{ids.map((id, index) => {
						const post = resolvedById.get(id);
						const title =
							post?.title ||
							__('Untitled post', 'prc-markdown-for-agents');
						return (
							<li
								key={id}
								className="markdown-for-agents-settings__picker-item"
							>
								<div className="markdown-for-agents-settings__picker-item-row">
									<Text className="markdown-for-agents-settings__picker-item-title">
										{title}
									</Text>
									<div className="markdown-for-agents-settings__picker-item-actions">
										<Button
											icon={arrowUp}
											label={sprintfMoveUp(title)}
											onClick={() => moveItem(index, -1)}
											disabled={index === 0}
											size="small"
										/>
										<Button
											icon={arrowDown}
											label={sprintfMoveDown(title)}
											onClick={() => moveItem(index, 1)}
											disabled={index === ids.length - 1}
											size="small"
										/>
										<Button
											icon={trash}
											label={sprintfRemove(title)}
											onClick={() => removeItem(index)}
											size="small"
											isDestructive
										/>
									</div>
								</div>
							</li>
						);
					})}
				</ol>
			)}
		</VStack>
	);
}

function sprintfMoveUp(title: string): string {
	return `${__('Move up', 'prc-markdown-for-agents')}: ${title}`;
}

function sprintfMoveDown(title: string): string {
	return `${__('Move down', 'prc-markdown-for-agents')}: ${title}`;
}

function sprintfRemove(title: string): string {
	return `${__('Remove', 'prc-markdown-for-agents')}: ${title}`;
}
