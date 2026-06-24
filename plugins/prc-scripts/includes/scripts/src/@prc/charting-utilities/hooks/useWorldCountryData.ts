import { useMemo } from 'react';

import type { FlatData } from '../types/flatData';
import type { FeatureShape } from '../types/featureShape';
import isoAlpha3 from '../data/iso-alpha3.json';

// ISO alpha-3 code (uppercase) -> ISO numeric code, used to resolve data rows
// whose `x` value is an alpha-3 country code (e.g. "USA") to the topology's
// numeric feature id (e.g. "840").
const alpha3ToNumeric = isoAlpha3 as Record<string, string>;

export interface UseWorldCountryDataArgs {
	/** Country features from the world topology (e.g. `topojson.feature(...).features`). */
	features: FeatureShape[];
	/** Flattened FlatData rows from the chart's table. */
	flattenedData: FlatData[];
	/**
	 * Optional category column. When provided, `mergedAndFilteredData` is the
	 * subset of countries that carry a value for this category.
	 */
	category?: string;
	/**
	 * Feature ids to drop before merging. Used by regional topologies that
	 * include neighbouring countries only for border arcs (e.g. Russia in the
	 * East Asia topology).
	 */
	excludeIds?: string[];
}

export interface UseWorldCountryDataResult {
	/** Every (non-excluded) country feature with any matching data row merged into `properties`. */
	mergedData: FeatureShape[];
	/** `mergedData` filtered to countries that have a value for `category` (or `mergedData` when no category). */
	mergedAndFilteredData: FeatureShape[];
}

/**
 * Join FlatData rows onto world-country topology features.
 *
 * Matching priority (per country feature):
 *   1. `d.ISO` / `d.iso` equals the feature's numeric id.
 *   2. `d.x` equals the feature's name or numeric id.
 *   3. `d.x` is an ISO alpha-3 code that maps to the feature's numeric id.
 *
 * This is the shared implementation consumed by both the Robinson `World` map
 * and the orthographic globe so the country join stays in one place.
 */
export function useWorldCountryData({
	features,
	flattenedData,
	category,
	excludeIds,
}: UseWorldCountryDataArgs): UseWorldCountryDataResult {
	// Stable dependency key so passing a fresh `excludeIds` array each render
	// (e.g. `EXCLUDED[region] || []`) does not needlessly rebuild the merge.
	const excludeKey = (excludeIds ?? []).join('|');

	const mergedData = useMemo(() => {
		const excluded = excludeIds ?? [];
		return features
			.filter((country) => !excluded.includes(country.id))
			.map((country) => {
				const countryName = country.properties.name;
				const countryId = country.id?.toString();
				const countryData = flattenedData.find((d: any) => {
					// Priority 1: explicit ISO numeric field (most reliable)
					if (d.ISO && d.ISO.toString() === countryId) return true;
					if (d.iso && d.iso.toString() === countryId) return true;

					// Priority 2: x value as country name or ISO numeric code
					const dataValue = d.x?.toString();
					if (dataValue === countryName || dataValue === countryId)
						return true;

					// Priority 3: x value as ISO alpha-3 code
					if (dataValue) {
						const numericFromAlpha3 =
							alpha3ToNumeric[dataValue.toUpperCase()];
						if (
							numericFromAlpha3 &&
							numericFromAlpha3 === countryId
						)
							return true;
					}

					return false;
				});
				return {
					...country,
					properties: {
						...country.properties,
						...countryData,
					},
				};
			});
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [features, flattenedData, excludeKey]);

	const mergedAndFilteredData = useMemo(() => {
		if (!category) return mergedData;
		return mergedData.filter((country) => country.properties?.[category]);
	}, [mergedData, category]);

	return { mergedData, mergedAndFilteredData };
}
