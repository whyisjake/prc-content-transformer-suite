/**
 * Animation config — additive block introduced in PRC-17 slice 3b and
 * expanded in the slice 3c animation rollout.
 *
 * Distinct from the older, dormant `Animate` block (`types/animate.ts`),
 * which was scaffolded for a different intent and never wired up. The
 * `animation` block here is consumed by the `animated/` chart primitives
 * (`AnimatedBar`, and — as the rollout fans out — `AnimatedCircle`,
 * `AnimatedLinePath`, `AnimatedArc`, etc.) via the `useAnimationConfig`
 * hook, and by the chart-builder Inspector's `AnimationControls` panel.
 *
 * The *type* is defined fully up front (cheap, one file); the Inspector
 * *UI* exposes fields incrementally per slice. Every field beyond the
 * required `enabled`/`duration` pair is optional and backwards compatible.
 *
 * Resolution hierarchy (implemented once in `useAnimationConfig`):
 *   section value (`initial.*` / `update.*`) ?? top-level value ?? hard default.
 * A section animates when `enabled !== false && (section.enabled ?? true)`.
 * `prefers-reduced-motion: reduce` and the editor context always force
 * instant updates (accessibility / editor win — no opt-out).
 *
 * Defaults assume editorial content: a predictable duration-based easing
 * rather than spring physics. Animation is DISABLED by default
 * (`baseConfig.animation.enabled === false`) and opt-in per chart via the
 * Inspector toggle.
 */

/**
 * Per-family entrance (first-mount) animation. `'auto'` resolves to the
 * family's natural entrance in `useAnimationConfig`:
 *   bars → `grow`, circles → `pop`, lines/areas → `draw`, pie → `sweep`.
 *   Pie also supports `clockwise` (full-ring wipe 0° → 360°) via Inspector.
 * `'none'` renders the entrance instantly (no first-mount transition).
 *
 * Not every family implements every type yet; the rollout adds `pop`,
 * `draw`, and `sweep` consumers in later slices. `grow`/`fade`/`none`
 * are the slice 3c surface.
 */
export type AnimationEntranceType =
	| 'auto'
	| 'grow'
	| 'draw'
	| 'pop'
	| 'sweep'
	| 'clockwise'
	| 'fade'
	| 'none';

/**
 * Curated subset of `@react-spring/web`'s `easings` dictionary
 * (option A — expandable later to more easings or opt-in physics
 * presets without a breaking change). The hook maps each name to the
 * corresponding `easings[name]` function so components never import
 * easing fns directly.
 */
export type AnimationEasing =
	| 'linear'
	| 'easeInOutQuad'
	| 'easeInOutCubic'
	| 'easeInOutSine' // smooth, editorial
	| 'easeOutCubic'
	| 'easeOutQuint' // decelerate
	| 'easeOutBack'
	| 'easeOutBounce'
	| 'easeOutElastic'; // playful (opt-in)

/**
 * Follow (secondary) entrance — a generic dependent animation that plays
 * AFTER the family's primary entrance completes. The timing relationship
 * ("wait for the primary to finish, then run for `duration`") is identical
 * wherever a secondary element rides a primary:
 *
 *   - line / area: the markers (nodes) wait for the path to finish drawing.
 *   - dot plot: the connector line waits for the dots to finish popping in.
 *   - future composite marks: any dependent primitive can reuse this.
 *
 * What the secondary element actually IS (a circle, a line, …) is a
 * component-wiring concern — the host forwards the resolved follow timing
 * to whatever dependent primitive it owns via that primitive's generic
 * `entranceDelay` / `entranceDuration` props. The config carries only the
 * timing, so adding a new dependent animation never needs a new schema key.
 *
 * The sequencing math (absolute delay = primary delay + primary duration +
 * `delay` offset) is resolved once in `useAnimationConfig`; hosts never
 * recompute it. On data updates the secondary glides with the primary, so
 * `follow` only shapes the first-mount entrance.
 */
export type AnimationFollow = {
	/**
	 * Whether the secondary plays its sequenced entrance. Default `true`.
	 * When `false`, the secondary animates with the primary (no wait) rather
	 * than being suppressed — turn the whole entrance off via
	 * `initial.enabled` instead.
	 */
	enabled?: boolean;
	/**
	 * How long the secondary entrance runs (ms). Falls back to the entrance
	 * `duration`, then top-level, then the hard default — so a slow line draw
	 * (e.g. 400ms) can be topped with a snappy node pop (e.g. 100ms).
	 */
	duration?: number;
	/**
	 * EXTRA delay (ms) inserted between the primary finishing and the
	 * secondary starting. Default `0` (secondary starts the instant the
	 * primary completes). Adds to the resolved absolute delay; it is not the
	 * absolute delay itself.
	 */
	delay?: number;
};

/**
 * Entrance (first-mount) overrides. Falls back to the top-level
 * `Animation` fields, then to hard defaults.
 */
export type AnimationInitial = {
	enabled?: boolean;
	/** `'auto'` resolves per chart family. */
	type?: AnimationEntranceType;
	duration?: number;
	easing?: AnimationEasing;
	delay?: number;
	/**
	 * RESERVED — schema space only, not implemented in this rollout.
	 * Per-element entrance stagger (ms between successive elements).
	 */
	stagger?: number;
	/**
	 * Secondary (dependent) entrance that plays after the primary finishes.
	 * See `AnimationFollow`. Covers line/area markers and the dot-plot
	 * connector with one generic key.
	 */
	follow?: AnimationFollow;
};

/**
 * Data-change (update) overrides. There is no geometric `type` here —
 * the element already exists; its values simply interpolate old → new.
 * Its only "type" is the `easing` curve.
 */
export type AnimationUpdate = {
	enabled?: boolean;
	duration?: number;
	easing?: AnimationEasing;
	delay?: number;
};

export type Animation = {
	/**
	 * Master switch. When `false`, consumers short-circuit to static
	 * rendering so reduced-motion users and snapshot tests opt out at
	 * the config level. Disabled by default; opt-in per chart.
	 */
	enabled: boolean;
	/**
	 * Shared default duration in milliseconds. Drives the `duration`
	 * field of `@react-spring/web`'s `useSpring` config (a duration-based
	 * tween, NOT tension/friction physics).
	 */
	duration: number;
	/** Shared default timing curve. */
	easing?: AnimationEasing;
	/** Shared default delay (ms) before the animation starts. */
	delay?: number;
	/** Entrance (first-mount) section overrides. */
	initial?: AnimationInitial;
	/** Data-change (update) section overrides. */
	update?: AnimationUpdate;
};
