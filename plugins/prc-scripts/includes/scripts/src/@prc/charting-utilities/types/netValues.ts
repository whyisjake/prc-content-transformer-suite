import type { LabelCustomizations } from './labels';
/** Configuration for one side (positive or negative) of net value labels */
export type NetValueSide = {
	active?: boolean;
	category: string;
	color: 'inherit' | 'contrast' | 'black' | 'white';
	fontWeight: number;
	fontSize: number;
	fontFamily: string;
	textAnchor: 'start' | 'middle' | 'end';
	margin: number;
	labelPositionDX: number;
	labelPositionDY: number;
	abbreviateValue: boolean;
	absoluteValue: boolean;
	truncateDecimal: boolean;
	toFixedDecimal: number;
	toLocaleString: boolean;
	labelUnit: string;
	labelUnitPosition: 'start' | 'end';
	customLabelFormat?:
		| ((value: number | Date | string, category: string) => string)
		| null;
} & LabelCustomizations;

/** Top-level net values block attribute */
export type NetValues = {
	active: boolean;
	positive: NetValueSide;
	negative: NetValueSide;
};

/** Reserved DraggableLabel category keys for net value customizations */
export const NET_VALUE_CATEGORY_POSITIVE = '__net_positive' as const;
export const NET_VALUE_CATEGORY_NEGATIVE = '__net_negative' as const;
