/**
 * Preview in Apple News button and full-screen modal.
 */

import { useState, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import {
	Button,
	Modal,
	Notice,
	PanelRow,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { seen } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

import AnfRenderer from './anf-renderer';
import type { PreviewResponse } from './types';

interface PreviewButtonProps {
	postId: number;
}

export default function PreviewButton({ postId }: PreviewButtonProps) {
	const [isOpen, setIsOpen] = useState(false);
	const [isLoading, setIsLoading] = useState(false);
	const [preview, setPreview] = useState<PreviewResponse | null>(null);
	const [fetchError, setFetchError] = useState<string | null>(null);

	const isDirty = useSelect(
		(select) =>
			(
				select(editorStore) as {
					isEditedPostDirty: () => boolean;
				}
			).isEditedPostDirty(),
		[]
	);

	const { savePost } = useDispatch(editorStore);

	const handleOpen = useCallback(async () => {
		setIsOpen(true);
		setIsLoading(true);
		setFetchError(null);
		setPreview(null);

		try {
			if (isDirty) {
				await savePost();
			}

			const response = await apiFetch<PreviewResponse>({
				path: `/prc-apple-news/v1/preview?post_id=${postId}`,
			});

			setPreview(response);
		} catch (error) {
			const fallback = __(
				'Failed to load Apple News preview.',
				'prc-apple-news'
			);
			let message = fallback;
			if (error instanceof Error) {
				message = error.message;
			} else if (
				typeof error === 'object' &&
				error !== null &&
				'message' in error &&
				typeof error.message === 'string'
			) {
				message = error.message;
			}
			setFetchError(message);
		} finally {
			setIsLoading(false);
		}
	}, [postId, isDirty, savePost]);

	const handleClose = useCallback(() => {
		setIsOpen(false);
		setPreview(null);
		setFetchError(null);
	}, []);

	const validationMessage = preview?.validation?.message ?? '';
	const validationErrors = preview?.validation?.errors ?? [];
	const hasValidationWarnings = preview?.validation?.valid === false;

	return (
		<>
			<PanelRow>
				<Button
					__next40pxDefaultSize
					style={{ width: '100%', justifyContent: 'center' }}
					variant="secondary"
					icon={seen}
					onClick={handleOpen}
				>
					{__('Preview in Apple News', 'prc-apple-news')}
				</Button>
			</PanelRow>

			{isOpen && (
				<Modal
					title={__('Apple News Preview', 'prc-apple-news')}
					onRequestClose={handleClose}
					isFullScreen
					className="prc-anf-preview-modal"
				>
					{isLoading && (
						<div className="prc-anf-preview-modal__loading">
							<Spinner />
							<p>
								{__('Building ANF preview…', 'prc-apple-news')}
							</p>
						</div>
					)}

					{fetchError && (
						<Notice status="error" isDismissible={false}>
							{fetchError}
						</Notice>
					)}

					{hasValidationWarnings && (
						<Notice status="warning" isDismissible={false}>
							<strong>
								{__(
									'ANF validation warnings:',
									'prc-apple-news'
								)}
							</strong>
							{validationErrors.length > 0 ? (
								<ul className="prc-anf-preview-modal__validation-list">
									{validationErrors.map((err, index) => (
										<li
											key={`${err.property ?? 'doc'}-${index}`}
										>
											[{err.property ?? 'document'}]{' '}
											{err.message}
										</li>
									))}
								</ul>
							) : (
								validationMessage && <p>{validationMessage}</p>
							)}
						</Notice>
					)}

					{preview?.document && !isLoading && (
						<AnfRenderer document={preview.document} />
					)}
				</Modal>
			)}
		</>
	);
}
