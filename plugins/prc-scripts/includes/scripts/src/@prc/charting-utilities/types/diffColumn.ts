export type DiffColumnCellCustomization = {
	text?: string;
	fill?: string;
	fontWeight?: 'normal' | 'bold' | 'bolder' | 'lighter' | number | '';
	fontStyle?: 'normal' | 'italic' | 'oblique' | '';
	fontSize?: number | null;
	textOutline?: boolean;
};

export type DiffColumn = {
	active: boolean;
	category: string;
	columnHeader: string;
	customLabels?: Record<string, DiffColumnCellCustomization>;
	dx: number;
	dy: number;
	style: {
		rectStrokeWidth: number;
		rectStrokeColor: string;
		rectFill: string;
		fill: string;
		headerFill: string;
		textOutline: boolean;
		headerTextOutline: boolean;
		fontWeight: 'normal' | 'bold' | 'bolder' | 'lighter' | number;
		fontSize: string;
		fontStyle: 'normal' | 'italic' | 'oblique';
		fontFamily: string;
		headerFontWeight?: 'normal' | 'bold' | 'bolder' | 'lighter' | number;
		headerFontStyle?: 'normal' | 'italic' | 'oblique';
		headerFontFamily?: string;
		headerFontSize: string;
		width: number;
		marginLeft: number;
		heightOffset: number;
	};
};
