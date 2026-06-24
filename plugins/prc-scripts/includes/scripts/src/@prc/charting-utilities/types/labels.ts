/**
 * Style overrides for individual label customization
 */
export interface LabelStyleOverride {
	color?: string;
	fontWeight?: string | number;
	fontSize?: number;
	fontStyle?: 'normal' | 'italic' | 'underline' | 'strikethrough';
	fontFamily?: string;
	maxWidth?: number;
}

/**
 * Position offset for a label
 */
export interface LabelPositionOffset {
	dx: number;
	dy: number;
}

/**
 * Per-data-point label customizations stored in block attributes.
 * Keys are formatted as "xValue::category" (e.g., "2020::Democrats")
 */
export interface LabelCustomizations {
	customPositions?: Record<string, LabelPositionOffset>;
	customLabels?: Record<string, string>;
	customVisibility?: Record<string, boolean>;
	customStyles?: Record<string, LabelStyleOverride>;
}

export type Labels = {
	active: boolean;
	showFirstLastPointsOnly: boolean;
	color: 'inherit' | 'contrast' | 'black' | 'white';
	// altColor: 'white',
	fontWeight: number;
	fontSize: number;
	fontFamily: string;
	textAnchor: 'start' | 'middle' | 'end';
	labelPositionBar: 'inside' | 'outside' | 'center';
	labelCutoff: number;
	labelCutoffMobile: number;
	labelPositionDX: number;
	labelPositionDY: number;
	pieLabelRadius?: number;
	abbreviateValue: boolean;
	absoluteValue: boolean;
	truncateDecimal: boolean;
	toFixedDecimal: number;
	toLocaleString: boolean;
	labelUnit: string; //'%', '$', '€', '£', '¥'
	labelUnitPosition: 'start' | 'end';
	customLabelFormat?:
		| ((value: number | Date | string, category: string) => string)
		| null;
	/** When true, automatically nudge labels to reduce overlap (opt-in). */
	autoDeclutter?: boolean;
	/** Extra padding (px) between labels during auto-declutter. */
	declutterPadding?: number;
	/** When true, draw leader lines from anchor to displaced decluttered labels. */
	declutterLeaderLines?: boolean;
	/**
	 * When true, draw a contrasting outline (halo) behind every label so it
	 * stays legible when it sits atop a line or shape.
	 */
	textOutline?: boolean;
	/**
	 * How the text-outline halo color is chosen when `textOutline` is enabled.
	 * - `background` — uniform chart-background moat (best for labels over lines)
	 * - `contrast` — halo contrasts with each label's text fill (best inside bars)
	 * When unset, bar/pie/treemap charts default to `contrast`; others to `background`.
	 */
	textOutlineMode?: 'background' | 'contrast';
	// function(d) { return d; },
} & LabelCustomizations;
