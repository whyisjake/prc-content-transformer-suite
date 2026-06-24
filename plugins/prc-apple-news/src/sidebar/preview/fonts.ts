/**
 * Map ANF fontName values to CSS font properties.
 */

import type { CSSProperties } from 'react';

import type { AnfLinkStyle, AnfTextStyle } from './types';

/** Preview renders at desktop viewport (1024px) — use wide conditional branch. */
const PREVIEW_VIEWPORT = 1024;

interface FontMapping {
	fontFamily: string;
	fontWeight: number | string;
	fontStyle: string;
}

const FONT_MAP: Record<string, FontMapping> = {
	'Marion-Bold': {
		fontFamily:
			'"Lora", "Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif',
		fontWeight: 700,
		fontStyle: 'normal',
	},
	'Marion-Regular': {
		fontFamily:
			'"Lora", "Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif',
		fontWeight: 400,
		fontStyle: 'normal',
	},
	Georgia: {
		fontFamily: 'Georgia, "Times New Roman", Times, serif',
		fontWeight: 400,
		fontStyle: 'normal',
	},
	'Georgia-Italic': {
		fontFamily: 'Georgia, "Times New Roman", Times, serif',
		fontWeight: 400,
		fontStyle: 'italic',
	},
	'Georgia-Bold': {
		fontFamily: 'Georgia, "Times New Roman", Times, serif',
		fontWeight: 700,
		fontStyle: 'normal',
	},
	Helvetica: {
		fontFamily: 'Helvetica, Arial, sans-serif',
		fontWeight: 400,
		fontStyle: 'normal',
	},
	'Helvetica-Bold': {
		fontFamily: 'Helvetica, Arial, sans-serif',
		fontWeight: 700,
		fontStyle: 'normal',
	},
};

export function fontNameToCss(fontName: string | undefined): FontMapping {
	if (!fontName) {
		return FONT_MAP.Georgia;
	}

	return (
		FONT_MAP[fontName] ?? {
			fontFamily: fontName,
			fontWeight: 400,
			fontStyle: 'normal',
		}
	);
}

export function applyConditionalTextStyle(
	textStyle: AnfTextStyle
): AnfTextStyle {
	if (!textStyle.conditional?.length) {
		return textStyle;
	}

	const matching = [...textStyle.conditional]
		.filter((branch) => {
			const minWidth = branch.conditions?.[0]?.minViewportWidth ?? 0;
			return PREVIEW_VIEWPORT >= minWidth;
		})
		.sort(
			(a, b) =>
				(b.conditions?.[0]?.minViewportWidth ?? 0) -
				(a.conditions?.[0]?.minViewportWidth ?? 0)
		)[0];

	if (!matching) {
		return textStyle;
	}

	return {
		...textStyle,
		fontSize: matching.fontSize ?? textStyle.fontSize,
		lineHeight: matching.lineHeight ?? textStyle.lineHeight,
	};
}

export function linkStyleToCss(
	linkStyle: AnfLinkStyle | undefined
): CSSProperties {
	if (!linkStyle) {
		return {};
	}

	return {
		'--anf-link-color': linkStyle.textColor ?? '#346ead',
		'--anf-link-underline':
			linkStyle.underline === false ? 'none' : 'underline',
	} as CSSProperties;
}

export function textStyleToCss(textStyle: AnfTextStyle): CSSProperties {
	const resolved = applyConditionalTextStyle(textStyle);
	const font = fontNameToCss(resolved.fontName);

	return {
		fontFamily: font.fontFamily,
		fontWeight: font.fontWeight,
		fontStyle: font.fontStyle,
		fontSize: resolved.fontSize ? `${resolved.fontSize}px` : undefined,
		lineHeight: resolved.lineHeight
			? `${resolved.lineHeight}px`
			: undefined,
		letterSpacing: resolved.tracking ? `${resolved.tracking}px` : undefined,
		color: resolved.textColor,
		textAlign: resolved.textAlignment as CSSProperties['textAlign'],
		textTransform: resolved.textTransform as CSSProperties['textTransform'],
		...linkStyleToCss(resolved.linkStyle),
	};
}
