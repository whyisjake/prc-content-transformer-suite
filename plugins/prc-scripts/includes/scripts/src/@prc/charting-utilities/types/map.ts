export type MapProjectionPreset =
	| 'default'
	// Broadest2019
	| 'americas'
	| 'asia'
	| 'europe'
	| 'middle-east-north-africa'
	| 'sub-saharan-africa'
	// Broad2019
	| 'africa'
	| 'asia-pacific'
	| 'latin-america-and-the-caribbean'
	| 'middle-east'
	| 'north-america'
	// Sub2019
	| 'caribbean'
	| 'central-america'
	| 'central-asia'
	| 'east-asia'
	| 'eastern-europe'
	| 'north-africa'
	| 'oceania'
	| 'south-america'
	| 'south-asia'
	| 'western-europe'
	// Continent (true-geographic)
	| 'continent-africa'
	| 'continent-asia'
	| 'continent-europe'
	| 'continent-north-america'
	| 'continent-south-america'
	| 'continent-oceania'
	| 'custom';

export type MapTopologyRegion = MapProjectionPreset; // Same options but controls which topology file to load

export type Map = {
	ignoreSmallStateLabels: boolean;
	ignoredLabels: string[];
	abbreviateLabels: boolean;
	blockRectSize: number;
	pathBackgroundFill: string;
	pathStroke: string;
	pathStrokeWidth: number;
	showCountyBoundaries: boolean;
	showStateBoundaries: boolean;
	zoomActive: boolean;
	// Projection controls for custom map views
	projectionPreset: MapProjectionPreset; // Which preset projection to use (or 'custom')
	topologyRegion: MapTopologyRegion; // Which regional topology file to load (independent of projection)
	centerLongitude: number;
	centerLatitude: number;
	rotateLambda: number; // yaw
	rotatePhi: number; // pitch
	rotateGamma: number; // roll
	customScale: number; // scale multiplier (1 = default fitSize)
	// Bubble-mode styling — consumed when dataRender.mapStyle === 'bubble'.
	bubble: {
		minRadius: number;
		maxRadius: number;
		opacity: number;
		stroke: string;
		strokeWidth: number;
	};
	// Globe styling/interaction — consumed by the orthographic world map
	// (layout.type === 'map-world-orthographic').
	globe: {
		sphereFill: string; // ocean / background disc fill
		showGraticule: boolean; // lat/long grid lines
		graticuleStroke: string;
		autoSpin: boolean; // continuous rotation animation
		spinSpeed: number; // degrees of longitude per animation frame
		dragToRotate: boolean; // pointer drag rotates the globe
		showPlayPause: boolean; // overlay play/pause control for rotation
		playPausePosition: 'bottom-left' | 'bottom-right';
	};
};
