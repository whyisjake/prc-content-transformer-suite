import type { DataRender } from '../types/dataRender';

type CategoryHighlightDataRender = Pick<
	DataRender,
	| 'highlightedCategories'
	| 'highlightColor'
	| 'deselectedColor'
	| 'deselectedOpacity'
>;

type ResolveCategoryColorArgs = {
	category: string;
	fallback: string;
	dataRender: CategoryHighlightDataRender;
};

type ResolveCategoryOpacityArgs = {
	category: string;
	dataRender: CategoryHighlightDataRender;
};

type LegendLabel = { datum: string };

export function resolveCategoryColor({
	category,
	fallback,
	dataRender,
}: ResolveCategoryColorArgs): string {
	const list = dataRender.highlightedCategories ?? [];
	if (list.length === 0) {
		return fallback;
	}

	return list.includes(category)
		? dataRender.highlightColor || fallback
		: dataRender.deselectedColor || fallback;
}

export function resolveCategoryOpacity({
	category,
	dataRender,
}: ResolveCategoryOpacityArgs): number {
	const list = dataRender.highlightedCategories ?? [];
	if (list.length === 0) {
		return 1;
	}

	return list.includes(category) ? 1 : (dataRender.deselectedOpacity ?? 1);
}

export function withCategoryOpacity(
	customOpacity: number | undefined,
	category: string,
	dataRender: CategoryHighlightDataRender
): number {
	return (
		(customOpacity ?? 1) * resolveCategoryOpacity({ category, dataRender })
	);
}

export function legendCategoryShapeStyle(
	label: LegendLabel,
	dataRender: CategoryHighlightDataRender,
	baseShapeStyle?: (label: LegendLabel) => Record<string, unknown>
): Record<string, unknown> {
	const base = baseShapeStyle?.(label) ?? {};
	const categoryOpacity = resolveCategoryOpacity({
		category: String(label.datum),
		dataRender,
	});
	const baseOpacity = typeof base.opacity === 'number' ? base.opacity : 1;

	return {
		...base,
		opacity: baseOpacity * categoryOpacity,
	};
}
