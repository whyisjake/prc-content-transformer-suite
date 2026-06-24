/**
 * Apple News sidebar panel component.
 *
 * Renders inside PluginDocumentSettingPanel with a state machine:
 *   error → pending → published → not-published
 */
import { useState, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import {
	Button,
	Notice,
	Spinner,
	PanelRow,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { copy as copyIcon } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

import useAppleNewsStatus from './use-apple-news-status';
import PreviewButton from './preview/preview-button';

interface AppleNewsMeta {
	apple_news_is_preview?: boolean;
	apple_news_is_hidden?: boolean;
}

interface MetadataTogglesProps {
	meta: AppleNewsMeta | undefined;
	setMeta: (value: AppleNewsMeta) => void;
}

function MetadataToggles({ meta, setMeta }: MetadataTogglesProps) {
	const isPreview = meta?.apple_news_is_preview ?? false;
	const isHidden = meta?.apple_news_is_hidden ?? false;

	return (
		<>
			<PanelRow>
				<ToggleControl
					__nextHasNoMarginBottom
					label={__('Publish as preview (draft)', 'prc-apple-news')}
					checked={isPreview}
					onChange={(value) =>
						setMeta({ ...meta, apple_news_is_preview: value })
					}
				/>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					__nextHasNoMarginBottom
					label={__('Hidden (direct link only)', 'prc-apple-news')}
					checked={isHidden}
					onChange={(value) =>
						setMeta({ ...meta, apple_news_is_hidden: value })
					}
				/>
			</PanelRow>
		</>
	);
}

export default function AppleNewsPanel() {
	const { postId, postType, isDirty } = useSelect((select) => {
		const editor = select(editorStore) as {
			getCurrentPostId: () => number;
			getCurrentPostType: () => string;
			isEditedPostDirty: () => boolean;
		};
		return {
			postId: editor.getCurrentPostId(),
			postType: editor.getCurrentPostType(),
			isDirty: editor.isEditedPostDirty(),
		};
	}, []);

	const { savePost } = useDispatch(editorStore);
	const [meta, setMeta] = useEntityProp('postType', postType, 'meta');

	const {
		state,
		article_id,
		share_url,
		error,
		isStalePending,
		isLoading,
		refetch,
	} = useAppleNewsStatus(postId);

	const [isBusy, setIsBusy] = useState(false);
	const [copyConfirm, setCopyConfirm] = useState(false);

	const handlePush = useCallback(async () => {
		setIsBusy(true);
		try {
			if (isDirty) {
				await savePost();
			}
			await apiFetch({
				path: '/prc-apple-news/v1/push',
				method: 'POST',
				data: { post_id: postId },
			});
		} catch {
			// Error will surface via polling.
		} finally {
			setIsBusy(false);
			refetch();
		}
	}, [postId, refetch, isDirty, savePost]);

	const handleDelete = useCallback(async () => {
		if (
			!window.confirm(
				__(
					'Remove this post from Apple News? This cannot be undone.',
					'prc-apple-news'
				)
			)
		) {
			return;
		}
		setIsBusy(true);
		try {
			await apiFetch({
				path: '/prc-apple-news/v1/delete',
				method: 'POST',
				data: { post_id: postId },
			});
		} catch {
			// Error will surface via polling.
		} finally {
			setIsBusy(false);
			refetch();
		}
	}, [postId, refetch]);

	const handleDismissError = useCallback(async () => {
		try {
			await apiFetch({
				path: `/prc-apple-news/v1/error?post_id=${postId}`,
				method: 'DELETE',
			});
		} catch {
			// Ignore.
		} finally {
			refetch();
		}
	}, [postId, refetch]);

	const handleCopyUrl = useCallback(() => {
		if (!share_url) {
			return;
		}
		void navigator.clipboard.writeText(share_url).then(() => {
			setCopyConfirm(true);
			setTimeout(() => setCopyConfirm(false), 2000);
		});
	}, [share_url]);

	if (isLoading) {
		return (
			<PanelRow>
				<Spinner />
			</PanelRow>
		);
	}

	return (
		<div className="prc-apple-news-panel">
			<PreviewButton postId={postId} />

			{state === 'error' && (
				<>
					<Notice
						status="error"
						isDismissible
						onDismiss={handleDismissError}
					>
						{error}
					</Notice>
					<PanelRow>
						<Button
							__next40pxDefaultSize
							style={{ width: '100%', justifyContent: 'center' }}
							variant="secondary"
							isBusy={isBusy}
							disabled={isBusy}
							onClick={handlePush}
						>
							{__('Retry Push', 'prc-apple-news')}
						</Button>
					</PanelRow>
				</>
			)}

			{state === 'pending' && (
				<PanelRow>
					<div className="prc-apple-news-panel__pending">
						<Spinner />
						<span>
							{__('Pushing to Apple News…', 'prc-apple-news')}
						</span>
					</div>
					{isStalePending && (
						<Notice status="warning" isDismissible={false}>
							{__(
								'This push has been pending for over 10 minutes. It may have stalled.',
								'prc-apple-news'
							)}
						</Notice>
					)}
				</PanelRow>
			)}

			{state === 'published' && (
				<>
					<PanelRow>
						<p className="prc-apple-news-panel__article-id">
							<strong>
								{__('Article ID:', 'prc-apple-news')}
							</strong>{' '}
							<code>{article_id}</code>
						</p>
					</PanelRow>
					{share_url && (
						<PanelRow>
							<Button
								__next40pxDefaultSize
								variant="link"
								href={share_url}
								target="_blank"
								rel="noopener noreferrer"
							>
								{__('View on Apple News', 'prc-apple-news')}
							</Button>
							<Button
								__next40pxDefaultSize
								style={{
									width: '100%',
									justifyContent: 'center',
								}}
								variant="secondary"
								icon={copyIcon}
								onClick={handleCopyUrl}
								label={__('Copy share URL', 'prc-apple-news')}
							>
								{copyConfirm
									? __('Copied!', 'prc-apple-news')
									: __('Copy URL', 'prc-apple-news')}
							</Button>
						</PanelRow>
					)}
					<MetadataToggles meta={meta} setMeta={setMeta} />
					<PanelRow>
						<Button
							__next40pxDefaultSize
							style={{ width: '100%', justifyContent: 'center' }}
							variant="secondary"
							isBusy={isBusy}
							disabled={isBusy}
							onClick={handlePush}
						>
							{__('Re-push to Apple News', 'prc-apple-news')}
						</Button>
					</PanelRow>
					<PanelRow>
						<Button
							__next40pxDefaultSize
							style={{ width: '100%', justifyContent: 'center' }}
							variant="secondary"
							isDestructive
							isBusy={isBusy}
							disabled={isBusy}
							onClick={handleDelete}
						>
							{__('Delete from Apple News', 'prc-apple-news')}
						</Button>
					</PanelRow>
				</>
			)}

			{state === 'not-published' && (
				<>
					<MetadataToggles meta={meta} setMeta={setMeta} />
					<PanelRow>
						<Button
							__next40pxDefaultSize
							style={{ width: '100%', justifyContent: 'center' }}
							variant="primary"
							isBusy={isBusy}
							disabled={isBusy}
							onClick={handlePush}
						>
							{__('Push to Apple News', 'prc-apple-news')}
						</Button>
					</PanelRow>
				</>
			)}
		</div>
	);
}
