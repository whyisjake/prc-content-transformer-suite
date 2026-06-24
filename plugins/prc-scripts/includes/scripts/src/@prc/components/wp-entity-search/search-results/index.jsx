/**
 * WordPress Dependencies
 */
import { useMemo } from '@wordpress/element';
import { Button, __experimentalVStack as VStack } from '@wordpress/components';

/**
 * Internal Dependencies
 */
import LoadingIndicator from '../../loading-indicator';
import NoResults from './no-results';
import SearchItem from './search-item';
import { useWPEntitySearch } from '../context';

export default function SearchResults({}) {
	const {
		records,
		isLoading,
		searchString,
		hasNothingFound,
		hasSearchRecords,
		createNew,
		onUpdateURL,
	} = useWPEntitySearch();

	const loadingLabel = useMemo(() => {
		return `Searching for "${searchString}"...`;
	}, [searchString]);

	return (
		<VStack>
			<LoadingIndicator enabled={isLoading} label={loadingLabel} />

			{hasNothingFound && !!searchString && (
				<NoResults createNew={createNew} />
			)}

			{hasSearchRecords && (
				<div
					style={{
						maxHeight: '50vh', // We never want the sidebar panel to be more than 50% of the viewport height
						minWidth: '240px',
						overflowY: 'auto',
						paddingBottom: '0.5em',
						paddingTop: '0.5em',
					}}
				>
					<VStack spacing="2">
						{records.filter(Boolean).map((item) => (
							<SearchItem key={item?.entityId} item={item} />
						))}
					</VStack>
				</div>
			)}

			{false !== onUpdateURL && (
				<div
					style={{
						display: 'flex',
						justifyContent: 'center',
						alignItems: 'center',
						marginTop: '20px',
						flexDirection: 'column',
						gap: '1em',
					}}
				>
					<div> ~ or ~ </div>
					<Button
						variant="secondary"
						onClick={() => {
							onUpdateURL();
						}}
					>
						Update URL
					</Button>
				</div>
			)}
		</VStack>
	);
}
