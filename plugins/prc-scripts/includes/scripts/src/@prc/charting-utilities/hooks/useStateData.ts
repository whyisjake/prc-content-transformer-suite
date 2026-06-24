import { useMemo } from 'react';

import type { FlatData } from '../types/flatData';
import type { FeatureShape } from '../types/featureShape';
import stateAbbreviations from '../data/abbreviations.json';

// FIPS code -> two-letter abbreviation (e.g. "06" -> "CA").
export const fipsToStateAbbr = stateAbbreviations as Record<string, string>;

const fipsToAbbr = fipsToStateAbbr;

// Reverse lookup built once at module load: abbreviation -> FIPS.
const abbrToFIPS = Object.entries(fipsToAbbr).reduce(
	(acc, [fips, abbr]) => {
		acc[abbr] = fips;
		return acc;
	},
	{} as Record<string, string>
);

export interface StateGridBin {
	fips?: string | number;
	name?: string;
	id?: string;
	[key: string]: unknown;
}

export interface StateGridColumn {
	bins: StateGridBin[];
	[key: string]: unknown;
}

export interface UseStateDataArgs {
	/** State features from the USA topology (e.g. `topojson.feature(...).features`). */
	features: FeatureShape[];
	/** Flattened FlatData rows from the chart's table. */
	flattenedData: FlatData[];
	/**
	 * Optional category column. When provided, `mergedAndFilteredData` is the
	 * subset of states that carry a value for this category.
	 */
	category?: string;
}

export interface UseStateDataResult {
	/** Every state feature with any matching data row merged into `properties`. */
	mergedData: FeatureShape[];
	/** `mergedData` filtered to states that have a value for `category` (or `mergedData` when no category). */
	mergedAndFilteredData: FeatureShape[];
}

export interface UseStateGridDataArgs {
	/** Block/hex grid columns from heatmap.json. */
	grid: StateGridColumn[];
	/** Chart table rows (passed through as-is — same as BlockUSA / HexUSA). */
	rows: FlatData[];
}

/**
 * Find the FlatData row that matches a US state by FIPS, name, or abbreviation.
 *
 * Matching priority:
 *   1. `d.FIPS` / `d.fips` equals the state's FIPS code.
 *   2. `d.x` equals the state name or FIPS code.
 *   3. `d.x` is a two-letter abbreviation that maps to the state's FIPS code.
 */
export function findStateDataRow(
	rows: FlatData[],
	stateFips: string | undefined,
	stateName: string | undefined
): FlatData | undefined {
	return rows.find((d: FlatData) => {
		const row = d as FlatData & {
			FIPS?: string | number;
			fips?: string | number;
		};

		if (row.FIPS && row.FIPS.toString() === stateFips) return true;
		if (row.fips && row.fips.toString() === stateFips) return true;

		const dataValue = row.x?.toString();
		if (dataValue === stateName || dataValue === stateFips) return true;

		if (dataValue) {
			const fipsFromAbbr = abbrToFIPS[dataValue.toUpperCase()];
			if (fipsFromAbbr && fipsFromAbbr === stateFips) return true;
		}

		return false;
	});
}

/**
 * Join FlatData rows onto US-state topology features.
 *
 * Shared by `AlbersUSA` so the state join stays in one place alongside
 * `useWorldCountryData` for world maps.
 */
export function useStateData({
	features,
	flattenedData,
	category,
}: UseStateDataArgs): UseStateDataResult {
	const mergedData = useMemo(
		() =>
			features.map((state) => {
				const stateName = state.properties.name;
				const stateFIPS = state.id?.toString();
				const stateData = findStateDataRow(
					flattenedData,
					stateFIPS,
					stateName
				);
				return {
					...state,
					properties: {
						...state.properties,
						...stateData,
					},
				};
			}),
		[features, flattenedData]
	);

	const mergedAndFilteredData = useMemo(() => {
		if (!category) return mergedData;
		return mergedData.filter((state) => state.properties?.[category]);
	}, [mergedData, category]);

	return { mergedData, mergedAndFilteredData };
}

/**
 * Join FlatData rows onto block/hex grid bins (heatmap.json layout).
 *
 * Shared by `BlockUSA` and `HexUSA`.
 */
export function useStateGridData({ grid, rows }: UseStateGridDataArgs) {
	return useMemo(
		() =>
			grid.map((column) => ({
				...column,
				bins: column.bins.map((bin) => {
					const binFIPS = bin.fips?.toString();
					const binName = bin.name;
					const binData = findStateDataRow(rows, binFIPS, binName);
					return { ...bin, ...binData };
				}),
			})),
		[grid, rows]
	);
}
