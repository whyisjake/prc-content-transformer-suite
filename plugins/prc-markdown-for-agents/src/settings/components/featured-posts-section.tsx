import { useEffect, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Button, __experimentalVStack as VStack } from '@wordpress/components';

import { store as settingsStore } from '../store';
import { saveFeaturedPosts } from '../api';
import OrderedPostPicker from './ordered-post-picker';

export default function FeaturedPostsSection() {
	const { settings, resolved } = useSelect((select) => {
		const storeSelect = select(settingsStore);
		return {
			settings: storeSelect.getSettings(),
			resolved: storeSelect.getFeaturedPostsResolved(),
		};
	}, []);
	const { setFeaturedPosts } = useDispatch(settingsStore);
	const [draftIds, setDraftIds] = useState<number[]>(settings.featured_posts);
	const [isSaving, setIsSaving] = useState(false);

	useEffect(() => {
		setDraftIds(settings.featured_posts);
	}, [settings.featured_posts]);

	const handleSave = async () => {
		setIsSaving(true);
		setFeaturedPosts(draftIds);
		try {
			await saveFeaturedPosts();
		} finally {
			setIsSaving(false);
		}
	};

	return (
		<VStack spacing={4}>
			<OrderedPostPicker
				ids={draftIds}
				resolved={resolved}
				onChange={setDraftIds}
			/>
			<Button variant="primary" onClick={handleSave} isBusy={isSaving}>
				{__('Save featured posts', 'prc-markdown-for-agents')}
			</Button>
		</VStack>
	);
}
