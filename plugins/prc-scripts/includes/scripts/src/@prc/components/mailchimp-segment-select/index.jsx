/**
 * External Dependencies
 */

/**
 * WordPress Dependencies
 */
import {
	SelectControl,
	Spinner,
	ToolbarDropdownMenu,
	ToolbarButton,
	ToolbarGroup,
} from '@wordpress/components';
import { useEffect, useState, useMemo } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { tag } from '@wordpress/icons';

export default function MailchimpSegmentSelect({
	label = 'Select a MailChimp Segment',
	className,
	value,
	onChange,
	apiKey = 'mailchimp-form',
	renderAs = 'select',
}) {
	const [currentValue, setCurrentValue] = useState(value);
	const [records, setRecords] = useState([]);

	useEffect(() => {
		apiFetch({
			path: addQueryArgs('/prc-api/v3/mailchimp/get-segments', {
				api_key: apiKey,
			}),
		}).then((response) => {
			setRecords(response);
		});
	}, [apiKey]);

	// Sync internal state when the value prop changes externally
	// (e.g. toolbar updates the attribute and the inspector needs to follow).
	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect(() => {
		setCurrentValue(value);
	}, [value]);

	useEffect(() => {
		if (currentValue) {
			onChange(currentValue);
		}
	}, [currentValue]); // eslint-disable-line react-hooks/exhaustive-deps

	const segments = useMemo(() => {
		if (!records) {
			return [];
		}
		return Object.keys(records).map((key) => ({
			label: records[key].name,
			value: records[key].interest_id,
		}));
	}, [records]);

	const hasSegments = segments.length > 0;

	// Derive the active label from the value prop (source of truth) so both
	// toolbar and inspector always reflect the current block attribute.
	const activeLabel = useMemo(() => {
		if (!value || !hasSegments) {
			return label;
		}
		const match = segments.find((s) => s.value === value);
		return match ? match.label : label;
	}, [value, segments, label, hasSegments]);

	// Options for the default select rendering (with loading placeholder at top).
	const selectOptions = useMemo(
		() => [
			{ label: 'Loading MailChimp segments...', value: '' },
			...segments,
		],
		[segments]
	);

	if (renderAs === 'toolbar-dropdown') {
		if (!hasSegments) {
			return (
				<ToolbarButton>
					<Spinner />
				</ToolbarButton>
			);
		}
		const controls = segments.map((segment) => ({
			title: segment.label,
			isActive: segment.value === value,
			onClick: () => onChange(segment.value),
		}));
		return (
			<ToolbarDropdownMenu
				icon={tag}
				label={activeLabel}
				text={activeLabel}
				controls={controls}
			/>
		);
	}

	// Default 'select' rendering — preserves original SelectControl behavior.
	const hasOptions = selectOptions.length > 0;

	return (
		<div className={className}>
			{!hasOptions && <Spinner />}
			{hasOptions && (
				<SelectControl
					label={label}
					value={currentValue}
					options={selectOptions}
					onChange={(newValue) => {
						setCurrentValue(newValue);
					}}
					__nextHasNoMarginBottom
				/>
			)}
		</div>
	);
}
