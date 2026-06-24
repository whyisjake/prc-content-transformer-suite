/**
 * Apple News column geometry for the in-editor preview.
 */

import type { AnfComponentLayout, AnfLayout, ResolvedBox } from './types';

/** Native ANF document width used for layout calculations. */
export const DOCUMENT_WIDTH = 1024;

/** iPhone frame content width in CSS pixels. */
export const FRAME_WIDTH = 430;

export function getColumnWidth(layout: AnfLayout): number {
	const columns = layout.columns ?? 15;
	const width = layout.width ?? DOCUMENT_WIDTH;
	const margin = layout.margin ?? 100;
	const gutter = layout.gutter ?? 20;

	const usable = width - 2 * margin;
	return (usable - gutter * (columns - 1)) / columns;
}

export function resolveComponentBox(
	componentLayout: AnfComponentLayout | undefined,
	docLayout: AnfLayout
): ResolvedBox {
	const columns = docLayout.columns ?? 15;
	const margin = docLayout.margin ?? 100;
	const gutter = docLayout.gutter ?? 20;
	const colWidth = getColumnWidth(docLayout);

	const columnStart = componentLayout?.columnStart ?? 0;
	const columnSpan = componentLayout?.columnSpan ?? columns;
	const componentMargin = componentLayout?.margin ?? {};

	const left = margin + columnStart * (colWidth + gutter);
	const boxWidth = columnSpan * colWidth + (columnSpan - 1) * gutter;

	return {
		left,
		width: boxWidth,
		marginTop: componentMargin.top ?? 0,
		marginBottom: componentMargin.bottom ?? 0,
		ignoreDocumentMargin: componentLayout?.ignoreDocumentMargin === true,
	};
}

export function getCanvasScale(docLayout: AnfLayout): number {
	const width = docLayout.width ?? DOCUMENT_WIDTH;
	return FRAME_WIDTH / width;
}
