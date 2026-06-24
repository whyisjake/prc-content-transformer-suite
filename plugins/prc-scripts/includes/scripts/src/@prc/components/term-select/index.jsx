/* eslint-disable react/forbid-prop-types */
/**
 * External Dependencies
 */
import PropTypes from 'prop-types';
import styled from '@emotion/styled';

/**
 * WordPress Dependencies
 */
import { FormTokenField, Spinner } from '@wordpress/components';
import { useState, useMemo } from '@wordpress/element';
import { useEntityRecords } from '@wordpress/core-data';
import { useDebounce } from '@wordpress/compose';
import { decodeEntities } from '@wordpress/html-entities';

const TermSelectControl = styled('div')`
	position: relative;
	& .components-spinner {
		position: absolute;
		right: 0;
		bottom: 0.5em;
	}
`;

function TermSelect({ className, onChange, taxonomy, value, maxTerms, label }) {
	const l = label !== undefined ? label : `Select a ${taxonomy} term`;

	const [searchTerm, setSearchTerm] = useState('');
	const debounceSearchTerm = useDebounce(setSearchTerm, 500);

	const { records, isResolving, hasResolved } = useEntityRecords(
		'taxonomy',
		taxonomy,
		{
			per_page: 10,
			context: 'view',
			search: searchTerm,
		}
	);

	const suggestions = useMemo(() => {
		if (hasResolved && records) {
			return records.map((record) => record.name);
		}
		return [];
	}, [records, hasResolved]);

	return (
		<TermSelectControl className={className}>
			<FormTokenField
				value={value}
				suggestions={suggestions}
				onInputChange={debounceSearchTerm}
				displayTransform={(token) => decodeEntities(token)}
				onChange={(tokens) => {
					if (!tokens || tokens.length === 0) {
						onChange(null);
						return;
					}
					const newToken = tokens[tokens.length - 1];
					const selectedTerm = records?.find(
						(record) => record.name === newToken
					);
					if (selectedTerm) {
						const {
							id,
							name,
							slug,
							taxonomy: tax,
							parent,
							link,
						} = selectedTerm;
						onChange({
							id,
							name,
							slug,
							taxonomy: tax,
							parent,
							link,
						});
					}
				}}
				label={l}
				maxLength={maxTerms}
				__experimentalShowHowTo={false}
			/>
			{isResolving && <Spinner />}
		</TermSelectControl>
	);
}

TermSelect.defaultProps = {
	className: '',
	maxTerms: 1,
	onChange: (term) => {
		console.log('Selected Term: ', term);
	},
	taxonomy: 'topic',
	value: [],
};

TermSelect.propTypes = {
	className: PropTypes.string,
	maxTerms: PropTypes.number,
	onChange: PropTypes.func,
	taxonomy: PropTypes.string,
	value: PropTypes.array,
};

export default TermSelect;
