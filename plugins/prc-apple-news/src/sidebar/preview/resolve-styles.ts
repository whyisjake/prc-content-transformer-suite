/**
 * Resolve ANF named-or-inline layout, textStyle, and style references.
 */

import type {
	AnfComponent,
	AnfComponentLayout,
	AnfComponentStyle,
	AnfDocument,
	AnfTextStyle,
} from './types';

export function resolveLayout(
	document: AnfDocument,
	layout: AnfComponent['layout']
): AnfComponentLayout | undefined {
	if (!layout) {
		return undefined;
	}

	if (typeof layout === 'string') {
		return document.componentLayouts?.[layout];
	}

	return layout;
}

export function resolveTextStyle(
	document: AnfDocument,
	textStyle: AnfComponent['textStyle']
): AnfTextStyle | undefined {
	if (!textStyle) {
		return undefined;
	}

	if (typeof textStyle === 'string') {
		return (
			document.componentTextStyles?.[textStyle] ??
			document.textStyles?.[textStyle]
		);
	}

	return textStyle;
}

export function resolveComponentStyle(
	document: AnfDocument,
	style: AnfComponent['style']
): AnfComponentStyle | undefined {
	if (!style) {
		return undefined;
	}

	if (typeof style === 'string') {
		return document.componentStyles?.[style];
	}

	return style;
}

export function applyConditionalLayout(
	layout: AnfComponentLayout | undefined
): AnfComponentLayout | undefined {
	if (!layout?.conditional?.length) {
		return layout;
	}

	const previewViewport = 1024;
	const matching = [...layout.conditional]
		.filter((branch) => {
			const minWidth = branch.conditions?.[0]?.minViewportWidth ?? 0;
			return previewViewport >= minWidth;
		})
		.sort(
			(a, b) =>
				(b.conditions?.[0]?.minViewportWidth ?? 0) -
				(a.conditions?.[0]?.minViewportWidth ?? 0)
		)[0];

	if (!matching) {
		return layout;
	}

	return {
		...layout,
		columnStart: matching.columnStart ?? layout.columnStart,
		columnSpan: matching.columnSpan ?? layout.columnSpan,
		margin: matching.margin ?? layout.margin,
	};
}
