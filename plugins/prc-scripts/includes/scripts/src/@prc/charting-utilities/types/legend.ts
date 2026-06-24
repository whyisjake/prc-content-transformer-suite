export type LegendItemCustomization = {
	/** Override the display label for this item. */
	text?: string;

	/**
	 * Pixels from the left edge of the chart container.
	 * Meaningful when `Legend.variation` is `'detached'` or `'direct'` (on-line series labels).
	 */
	offsetX?: number;

	/**
	 * Pixels from the top edge of the chart container.
	 * Meaningful when `Legend.variation` is `'detached'` or `'direct'` (on-line series labels).
	 */
	offsetY?: number;

	/** Per-item font size override (px). */
	fontSize?: number;

	/** Per-item text/marker color override. */
	color?: string;

	/** Per-item font weight override. */
	fontWeight?: string;

	/** Per-item font style override ('normal' | 'italic'). */
	fontStyle?: string;

	/** Per-item font family override. */
	fontFamily?: string;

	/** Max width for text wrapping (px; 0 = no limit). */
	maxWidth?: number;

	/** Adds a contrasting paint-server outline behind the label text. */
	textOutline?: boolean;

	/**
	 * Per-item marker shape override.
	 * `'none'` hides the swatch entirely.
	 */
	markerStyle?: 'rect' | 'circle' | 'line' | 'none';

	/**
	 * Per-item marker fill style.
	 * `'solid'` (default): filled with the series color.
	 * `'outline'`: transparent fill, series-color stroke at 3px.
	 */
	markerFill?: 'solid' | 'outline';
};

export type Legend = {
	active: boolean;
	orientation: 'row' | 'column' | 'row-reverse' | 'column-reverse';
	title: string;
	offsetX: number;
	offsetY: number;
	alignment: 'flex-start' | 'flex-end' | 'center' | 'none';
	/**
	 * Controls how legend swatches are rendered.
	 * - `'rect' | 'circle' | 'line'`: standard swatch shapes.
	 * - `'none'`: hides all swatches.
	 * - `'label'`: hides all swatches and renders each label in its category's color.
	 */
	markerStyle: 'rect' | 'circle' | 'line' | 'none' | 'label';
	/**
	 * Legend-wide marker fill style.
	 * `'solid'` (default): filled with each item's series color.
	 * `'outline'`: transparent fill, series-color stroke at 3px.
	 */
	markerFill: 'solid' | 'outline';
	borderStroke: string;
	fill: string;
	fontSize: number;
	fontWeight: string;
	margin: {
		top: number;
		right: number;
		bottom: number;
		left: number;
	}; // CSS margin string (computed from margin object in chart-builder)
	categories: string[];
	labelDelimiter: string;
	labelLower: string;
	labelUpper: string;
	/** Per-item custom label text + (when detached) position and style overrides, keyed by category value. */
	customLabels: Record<string, LegendItemCustomization>;
	// Bubble-legend config — consumed when dataRender.mapStyle === 'bubble'.
	// Replaces the threshold/ordinal/linear legend with a proportional-symbol
	// (nested-circle) legend. See MapBubbleLegend.tsx.
	// Format is auto-derived from data: values 0–1 → '.0%', otherwise ',.0f'.
	bubbleLegend?: {
		/** 'category' fills circles with the first category color; 'none' = outlines only. */
		fill: 'none' | 'category';
		/** Specific values to render as legend circles. Empty = auto = [min, median, max]. */
		refValues: number[];
		/**
		 * Visual arrangement of the legend circles.
		 * - `'stacked'` (default): nested concentric circles sharing a bottom baseline, labels
		 *   sit inside the top of each ring. Best when nesting visually communicates the
		 *   proportional relationship.
		 * - `'spread'`: circles laid out side-by-side with labels below. Better when nesting
		 *   would overcrowd (many ref values, small chart, etc.).
		 */
		layout: 'stacked' | 'spread';
		/**
		 * Where to position each value label relative to its bubble.
		 * - `'outside'` (default): labels sit above each ring (stacked) or below each circle
		 *   (spread). Keeps text from overlapping the bubble fill.
		 * - `'inside'`: labels sit just inside the top edge of each circle. Compact but can
		 *   be hard to read on small bubbles or busy fills.
		 */
		labelPosition: 'inside' | 'outside';
	};

	/**
	 * Layout mode for the legend as a whole.
	 *
	 * - `'grouped'` (default): all items render together in a single flex container.
	 *   `offsetX` / `offsetY` position the whole legend block; whole-legend drag is
	 *   handled by `wpEditorFunctions.legend.*`.
	 * - `'detached'`: each item becomes a free-floating element positioned via its
	 *   own `customLabels[k].offsetX` / `customLabels[k].offsetY`. The legend-level
	 *   `offsetX` / `offsetY` are inert in this mode. Per-item drag is handled by
	 *   `wpEditorFunctions.legendItems.onItemDrag*`
	 * - `'direct'` (line charts only): suppresses the legend block and renders one
	 *   series-colored label on each line. Per-item text/style and drag offsets use
	 *   `customLabels[k]`; declutter separates overlapping labels.
	 */
	variation: 'grouped' | 'detached' | 'direct';
};
