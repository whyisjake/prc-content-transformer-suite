/**
 * WordPress Dependencies
 */
import { useMemo } from '@wordpress/element';
import {
	Card,
	CardBody,
	ExternalLink,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { date as formatDate } from '@wordpress/date';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal Dependencies
 */
import { useWPEntitySearch } from '../context';

export default function SearchItem({ item }) {
	if (!item || 'object' !== typeof item) {
		return null;
	}

	const {
		selectedId,
		setSelectedId,
		onSelect,
		onClear,
		clearOnSelect,
		showExcerpt,
		showType,
		showUrl,
		showFeaturedImage,
	} = useWPEntitySearch();

	const {
		entityId,
		entityName,
		entityDate,
		entityDescription,
		entityType,
		entitySubType,
		entityUrl,
		entityFeaturedImage,
	} = item;

	const isActive = useMemo(() => {
		return (
			selectedId !== null &&
			selectedId !== undefined &&
			String(selectedId) === String(entityId)
		);
	}, [selectedId, entityId]);

	const handleClick = () => {
		console.log('--------------------------------');
		console.log('item', item);
		console.log('handleClick', entityId);
		console.log('--------------------------------');
		setSelectedId(entityId);
		if (clearOnSelect) {
			onClear();
		}
		if (typeof onSelect === 'function') {
			onSelect(item);
		}
	};

	return (
		<div
			role="button"
			tabIndex="0"
			onClick={handleClick}
			onKeyDown={(e) => {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					handleClick();
				}
			}}
			style={{ cursor: 'pointer' }}
		>
			<Card
				size="small"
				style={{
					boxShadow: 'none',
					border: '1px solid #eee',
					backgroundColor: isActive ? '#f0f0f0' : 'transparent',
				}}
				key={`${entityId}-card`}
			>
				<CardBody key={`${entityId}-cardBody`}>
					<HStack alignment="center" spacing="3" justify="flex-start">
						{showFeaturedImage && entityFeaturedImage && (
							<img
								src={entityFeaturedImage}
								alt=""
								aria-hidden="true"
								style={{
									width: 40,
									height: 40,
									objectFit: 'cover',
									borderRadius: 2,
									flexShrink: 0,
								}}
							/>
						)}
						<VStack spacing="1">
							{null !== entityDate && (
								<div
									style={{
										fontSize: '0.8em',
										color: '#666',
									}}
								>
									{`${formatDate('M j, Y', entityDate)}`}
								</div>
							)}
							<strong>{decodeEntities(entityName)}</strong>
							{true === showType && (
								<div
									style={{
										fontSize: '0.8em',
										color: '#666',
									}}
								>
									Type: {entitySubType}
								</div>
							)}
							{true === showExcerpt && entityDescription && (
								<div
									style={{
										fontSize: '0.8em',
										color: '#666',
										lineHeight: '1.5em',
									}}
								>
									{decodeEntities(entityDescription)}
								</div>
							)}
							{showUrl &&
								'string' === typeof entityUrl &&
								'' !== entityUrl && (
									<ExternalLink
										href={entityUrl}
										target="_blank"
										style={{
											fontSize: '0.8em',
											fontStyle: 'italic',
											lineHeight: '1.5em',
										}}
									>
										{entityUrl}
									</ExternalLink>
								)}
						</VStack>
					</HStack>
				</CardBody>
			</Card>
		</div>
	);
}
