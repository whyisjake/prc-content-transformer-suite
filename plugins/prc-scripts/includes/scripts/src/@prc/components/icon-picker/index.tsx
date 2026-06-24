/**
 * WordPress Dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	SelectControl,
	SearchControl,
	Button,
	BaseControl,
	Flex,
	FlexBlock,
	FlexItem,
	Tooltip,
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { useState, useEffect, useMemo } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';

/**
 * External Dependencies
 */
// @ts-expect-error - @prc/icons is a JS package without published types.
import { IconLibraryIndex, Icon } from '@prc/icons';

import './styles.scss';

export type IconPosition = 'left' | 'right';

export interface IconPickerValue {
	library?: string;
	icon?: string;
	position?: IconPosition;
}

export interface IconPickerProps {
	/** Currently selected icon library (e.g. `solid`, `brands`). */
	library: string;
	/** Currently selected icon name. */
	icon?: string;
	/** Currently selected icon position. Only relevant when `showPosition` is true. */
	position?: IconPosition;
	/** Fires whenever any of the picker's controlled values changes. */
	onChange: (next: IconPickerValue) => void;
	/** Show the position toggle group. Defaults to `true`. */
	showPosition?: boolean;
	/** Show the icon-name search input. Defaults to `true`. */
	showSearch?: boolean;
}

const ICONS_PER_PAGE = 60;

const LIBRARY_OPTIONS = Object.keys(
	IconLibraryIndex as Record<string, string[]>
).map((lib) => ({
	label: lib
		.split('-')
		.map((word) => word.charAt(0).toUpperCase() + word.slice(1))
		.join(' '),
	value: lib,
}));

interface IconGridProps {
	library: string;
	iconName?: string;
	search: string;
	onSelect: (name: string) => void;
}

function IconGrid({ library, iconName, search, onSelect }: IconGridProps) {
	const [page, setPage] = useState(0);

	useEffect(() => {
		setPage(0);
	}, [library, search]);

	const filtered = useMemo<string[]>(() => {
		const all =
			(IconLibraryIndex as Record<string, string[]>)[library] || [];
		if (!search) return all;
		const q = search.toLowerCase();
		return all.filter((n) => n.toLowerCase().includes(q));
	}, [library, search]);

	const totalPages = Math.ceil(filtered.length / ICONS_PER_PAGE);
	const start = page * ICONS_PER_PAGE;
	const visible = filtered.slice(start, start + ICONS_PER_PAGE);

	return (
		<div className="prc-icon-picker__grid-wrapper">
			<div className="prc-icon-picker__grid">
				{visible.map((n) => (
					<Tooltip key={n} text={n}>
						<Button
							isPressed={n === iconName}
							onClick={() => onSelect(n)}
							className="prc-icon-picker__cell"
						>
							<Icon library={library} icon={n} size={1} />
						</Button>
					</Tooltip>
				))}
			</div>
			{totalPages > 1 && (
				<Flex
					justify="space-between"
					className="prc-icon-picker__pagination"
				>
					<FlexItem>
						<Button
							size="small"
							disabled={page === 0}
							onClick={() => setPage((p) => p - 1)}
						>
							{__('← Prev')}
						</Button>
					</FlexItem>
					<FlexBlock className="prc-icon-picker__pagination-status">
						{page + 1} / {totalPages}
					</FlexBlock>
					<FlexItem>
						<Button
							size="small"
							disabled={page === totalPages - 1}
							onClick={() => setPage((p) => p + 1)}
						>
							{__('Next →')}
						</Button>
					</FlexItem>
				</Flex>
			)}
			{filtered.length === 0 && (
				<p className="prc-icon-picker__empty">
					{__('No icons found.')}
				</p>
			)}
		</div>
	);
}

/**
 * Cohesive icon picker — library selector, search, paginated grid, and (optionally)
 * a position toggle. Authored to be reusable by both `core/button`'s inspector and
 * the `prc-block-bits` `icon-span` bit.
 *
 * @param {IconPickerProps} props                Component props.
 * @param {string}          props.library        Currently selected icon library.
 * @param {string}          [props.icon]         Currently selected icon name.
 * @param {IconPosition}    [props.position]     Currently selected icon position.
 * @param {Function}        props.onChange       Fired when any controlled value changes.
 * @param {boolean}         [props.showPosition] Show the position toggle group. Defaults to `true`.
 * @param {boolean}         [props.showSearch]   Show the icon-name search input. Defaults to `true`.
 */
export default function IconPicker({
	library,
	icon,
	position,
	onChange,
	showPosition = true,
	showSearch = true,
}: IconPickerProps) {
	const [browseLibrary, setBrowseLibrary] = useState(library);
	const [search, setSearch] = useState('');
	const baseId = `prc-icon-picker-${useInstanceId(IconPicker)}`;

	useEffect(() => {
		setBrowseLibrary(library);
	}, [library]);

	return (
		<VStack gap={1}>
			<SelectControl
				label={__('Library')}
				value={browseLibrary}
				options={LIBRARY_OPTIONS}
				onChange={(val) => {
					setBrowseLibrary(val);
					setSearch('');
					onChange({ library: val, icon: undefined });
				}}
			/>
			{showSearch && (
				<SearchControl
					label={__('Search icons')}
					value={search}
					onChange={setSearch}
				/>
			)}
			<BaseControl
				id={`${baseId}-grid`}
				label={
					icon ? `${__('Selected')}: ${icon}` : __('Select an icon')
				}
			>
				<IconGrid
					library={browseLibrary}
					iconName={icon}
					search={search}
					onSelect={(selectedName) =>
						onChange({ icon: selectedName })
					}
				/>
			</BaseControl>
			{showPosition && (
				<ToggleGroupControl
					label={__('Icon Position')}
					value={position}
					isBlock
					onChange={(val) =>
						onChange({ position: val as IconPosition })
					}
				>
					<ToggleGroupControlOption value="left" label={__('Left')} />
					<ToggleGroupControlOption
						value="right"
						label={__('Right')}
					/>
				</ToggleGroupControl>
			)}
		</VStack>
	);
}
