/**
 * ANF preview renderer — shared TypeScript types.
 */

export interface AnfLayout {
	columns?: number;
	width?: number;
	margin?: number;
	gutter?: number;
}

export interface AnfDocumentStyle {
	backgroundColor?: string;
}

export interface AnfMargin {
	top?: number;
	bottom?: number;
	left?: number;
	right?: number;
}

export interface AnfComponentLayout {
	columnStart?: number;
	columnSpan?: number;
	margin?: AnfMargin;
	ignoreDocumentMargin?: boolean;
	contentInset?: boolean | Record<string, boolean>;
	conditional?: AnfConditionalLayout[];
}

export interface AnfConditionalLayout {
	columnStart?: number;
	columnSpan?: number;
	margin?: AnfMargin;
	conditions?: Array<{ minViewportWidth?: number }>;
}

export interface AnfLinkStyle {
	textColor?: string;
	underline?: boolean;
}

export interface AnfTextStyle {
	fontName?: string;
	fontSize?: number;
	lineHeight?: number;
	tracking?: number;
	textColor?: string;
	textAlignment?: string;
	textTransform?: string;
	linkStyle?: AnfLinkStyle;
	paragraphSpacingBefore?: number;
	paragraphSpacingAfter?: number;
	hyphenation?: boolean;
	fontScaling?: boolean;
	dropCapStyle?: Record<string, unknown>;
	conditional?: AnfConditionalTextStyle[];
}

export interface AnfConditionalTextStyle {
	fontSize?: number;
	lineHeight?: number;
	conditions?: Array<{ minViewportWidth?: number }>;
}

export interface AnfComponentStyle {
	backgroundColor?: string;
	border?: Record<string, unknown>;
	stroke?: {
		color?: string;
		width?: number;
		style?: string;
	};
}

export interface AnfComponent {
	role: string;
	text?: string;
	format?: string;
	URL?: string;
	layout?: string | AnfComponentLayout;
	textStyle?: string | AnfTextStyle;
	style?: string | AnfComponentStyle;
	components?: AnfComponent[];
	identifier?: string;
}

export interface AnfDocument {
	version?: string;
	identifier?: string;
	title?: string;
	layout?: AnfLayout;
	documentStyle?: AnfDocumentStyle;
	components?: AnfComponent[];
	componentLayouts?: Record<string, AnfComponentLayout>;
	componentTextStyles?: Record<string, AnfTextStyle>;
	componentStyles?: Record<string, AnfComponentStyle>;
	textStyles?: Record<string, AnfTextStyle>;
}

export interface PreviewValidationError {
	property?: string;
	message?: string;
}

export interface PreviewValidation {
	valid: boolean;
	message?: string;
	errors?: PreviewValidationError[];
}

export interface PreviewResponse {
	document: AnfDocument;
	validation: PreviewValidation;
}

export interface ResolvedBox {
	left: number;
	width: number;
	marginTop: number;
	marginBottom: number;
	ignoreDocumentMargin: boolean;
}
