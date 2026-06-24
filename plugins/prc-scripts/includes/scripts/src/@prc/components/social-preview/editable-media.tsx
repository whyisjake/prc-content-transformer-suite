import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { edit as editIcon, closeSmall } from '@wordpress/icons';

interface EditableMediaProps {
	mediaUrl: string;
	mediaId: number;
	onSelect: (media: { id: number; url: string; type?: string }) => void;
	onRemove: () => void;
	isEditable: boolean;
	allowedTypes?: string[];
	className?: string;
	children?: React.ReactNode;
}

export function EditableMedia({
	mediaUrl,
	mediaId,
	onSelect,
	onRemove,
	isEditable,
	allowedTypes = ['image'],
	className,
	children,
}: EditableMediaProps) {
	if (!isEditable) {
		return <>{children}</>;
	}

	if (mediaUrl) {
		return (
			<div className={className} style={{ position: 'relative' }}>
				{children}
				<div
					style={{
						position: 'absolute',
						inset: 0,
						background: 'rgba(0, 0, 0, 0.4)',
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center',
						gap: 8,
						opacity: 0,
						transition: 'opacity 150ms ease',
					}}
					onMouseEnter={(e) => {
						(e.currentTarget as HTMLElement).style.opacity = '1';
					}}
					onMouseLeave={(e) => {
						(e.currentTarget as HTMLElement).style.opacity = '0';
					}}
				>
					<MediaUploadCheck>
						<MediaUpload
							onSelect={(media: {
								id: number;
								url: string;
								type?: string;
							}) =>
								onSelect({
									id: media.id,
									url: media.url,
									type: media.type,
								})
							}
							allowedTypes={allowedTypes}
							value={mediaId}
							render={({ open }: { open: () => void }) => (
								<Button
									icon={editIcon}
									label={__('Change', 'prc-social-builder')}
									onClick={open}
									variant="primary"
									size="small"
								/>
							)}
						/>
					</MediaUploadCheck>
					<Button
						icon={closeSmall}
						label={__('Remove', 'prc-social-builder')}
						onClick={onRemove}
						isDestructive
						size="small"
					/>
				</div>
			</div>
		);
	}

	return (
		<MediaUploadCheck>
			<MediaUpload
				onSelect={(media: { id: number; url: string; type?: string }) =>
					onSelect({
						id: media.id,
						url: media.url,
						type: media.type,
					})
				}
				allowedTypes={allowedTypes}
				value={mediaId}
				render={({ open }: { open: () => void }) => (
					<div
						className={className}
						onClick={open}
						role="button"
						tabIndex={0}
						onKeyDown={(e: React.KeyboardEvent) =>
							e.key === 'Enter' && open()
						}
						style={{
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'center',
							minHeight: 120,
							border: '2px dashed #d1d5db',
							borderRadius: 8,
							cursor: 'pointer',
							color: '#6b7280',
							fontSize: 14,
						}}
					>
						{__('+ Add Media', 'prc-social-builder')}
					</div>
				)}
			/>
		</MediaUploadCheck>
	);
}
