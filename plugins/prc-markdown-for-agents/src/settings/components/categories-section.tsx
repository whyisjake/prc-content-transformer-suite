import { useEffect, useMemo, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import {
	Button,
	CheckboxControl,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';

import { store as settingsStore } from '../store';
import { saveCategories } from '../api';
import type { CategoryAvailable } from '../types';

function getAutomaticCategoryIds(categories: CategoryAvailable[]): number[] {
	return categories
		.filter((category) => category.count > 0)
		.map((category) => category.id);
}

function getCheckedCategoryIds(
	categoryIds: number[],
	categories: CategoryAvailable[]
): number[] {
	if (categoryIds.length > 0) {
		return categoryIds;
	}

	return getAutomaticCategoryIds(categories);
}

export default function CategoriesSection() {
	const { categoryIds, categoriesAvailable } = useSelect((select) => {
		const settings = select(settingsStore).getSettings();
		const response = select(settingsStore).getCategoriesAvailable();
		return {
			categoryIds: settings.category_ids,
			categoriesAvailable: response,
		};
	}, []);
	const { setCategoryIds } = useDispatch(settingsStore);
	const [draftIds, setDraftIds] = useState<number[]>(categoryIds);
	const [isSaving, setIsSaving] = useState(false);

	const isAutomatic = draftIds.length === 0;
	const checkedIds = useMemo(
		() => getCheckedCategoryIds(draftIds, categoriesAvailable),
		[draftIds, categoriesAvailable]
	);
	const checkedSet = useMemo(() => new Set(checkedIds), [checkedIds]);

	useEffect(() => {
		setDraftIds(categoryIds);
	}, [categoryIds]);

	const handleToggle = (categoryId: number, isChecked: boolean) => {
		const baseIds = isAutomatic
			? getAutomaticCategoryIds(categoriesAvailable)
			: draftIds;
		const nextSet = new Set(baseIds);

		if (isChecked) {
			nextSet.add(categoryId);
		} else {
			nextSet.delete(categoryId);
		}

		const orderedIds = categoriesAvailable
			.filter((category) => nextSet.has(category.id))
			.map((category) => category.id);

		setDraftIds(orderedIds);
	};

	const handleResetToAutomatic = () => {
		setDraftIds([]);
	};

	const handleSave = async () => {
		setIsSaving(true);
		setCategoryIds(draftIds);
		try {
			await saveCategories();
		} finally {
			setIsSaving(false);
		}
	};

	return (
		<VStack
			spacing={4}
			className="markdown-for-agents-settings__categories"
		>
			<Text>
				{__(
					'Choose which top-level categories appear under ## Categories in /llms.txt. Leave automatic to include every category with published posts.',
					'prc-markdown-for-agents'
				)}
			</Text>
			{isAutomatic ? (
				<Text className="markdown-for-agents-settings__categories-mode">
					{__(
						'Automatic: categories with published posts are included.',
						'prc-markdown-for-agents'
					)}
				</Text>
			) : (
				<Text className="markdown-for-agents-settings__categories-mode">
					{__(
						'Custom selection: only checked categories are included.',
						'prc-markdown-for-agents'
					)}
				</Text>
			)}
			{categoriesAvailable.length === 0 ? (
				<Text>
					{__(
						'No top-level categories found.',
						'prc-markdown-for-agents'
					)}
				</Text>
			) : (
				<VStack
					spacing={2}
					className="markdown-for-agents-settings__categories-list"
				>
					{categoriesAvailable.map((category) => (
						<CheckboxControl
							key={category.id}
							label={`${category.name} (${category.count})`}
							checked={checkedSet.has(category.id)}
							onChange={(isChecked) =>
								handleToggle(category.id, isChecked)
							}
						/>
					))}
				</VStack>
			)}
			<div className="markdown-for-agents-settings__categories-actions">
				<Button
					variant="secondary"
					onClick={handleResetToAutomatic}
					disabled={isAutomatic}
				>
					{__('Reset to automatic', 'prc-markdown-for-agents')}
				</Button>
				<Button
					variant="primary"
					onClick={handleSave}
					isBusy={isSaving}
				>
					{__('Save Categories section', 'prc-markdown-for-agents')}
				</Button>
			</div>
		</VStack>
	);
}
