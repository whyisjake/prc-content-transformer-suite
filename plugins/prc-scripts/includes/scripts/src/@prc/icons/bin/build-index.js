// for each svg file in the build directory, create a json file with the same name
// and the following content:
// {
// 	"library": {filename},
// 	"icons": [ ... ]
// }
// where the icons array contains ids of each symbol in the svg file
//

const fs = require('fs');
const path = require('path');

// Extract `id` attributes from every <symbol> element in an SVG sprite.
// Avoids pulling in `jsdom` (and its transitive `tough-cookie`/`url-parse` chain)
// just to read attribute values from a static file.
function extractSymbolIds(svg) {
	const symbolRegex = /<symbol\b([^>]*)>/g;
	const idRegex = /\bid\s*=\s*(?:"([^"]*)"|'([^']*)')/;
	const ids = [];
	for (const match of svg.matchAll(symbolRegex)) {
		const attrs = match[1];
		const idMatch = attrs.match(idRegex);
		if (idMatch) {
			ids.push(idMatch[1] !== undefined ? idMatch[1] : idMatch[2]);
		}
	}
	return ids;
}

// Sprite sources live in prc-icon-library (sibling plugin under plugins/).
// From bin/: seven levels up to plugins/, then prc-icon-library/build/icons/sprites.
const buildDir = path.join(
	__dirname,
	'../../../../../../../prc-icon-library/build/icons/sprites'
);
// if the build directory does not exist, log an error and exit
if (!fs.existsSync(buildDir)) {
	console.error(
		'Build directory does not exist. Run `npm run build` in `plugins/prc-icon-library` first.'
	);
	process.exit(1);
}
const files = fs.readdirSync(buildDir);

const outputDir = path.join(__dirname, '../src');
const outputFile = path.join(outputDir, 'icon-library-index.json');

// Ensure output directory exists
if (!fs.existsSync(outputDir)) {
	fs.mkdirSync(outputDir, { recursive: true });
}

// Read existing icons if file exists, otherwise start fresh
let icons = {};
if (fs.existsSync(outputFile)) {
	try {
		icons = JSON.parse(fs.readFileSync(outputFile, 'utf8'));
	} catch (e) {
		console.warn(
			'Could not parse existing icon-library-index.json, starting fresh.'
		);
		icons = {};
	}
}

files.forEach((file) => {
	const filePath = path.join(buildDir, file);
	const svg = fs.readFileSync(filePath, 'utf8');
	const iconNames = extractSymbolIds(svg);
	const library = file.replace('.svg', '');
	icons[library] = iconNames.sort();
});

fs.writeFileSync(outputFile, JSON.stringify(icons, null, 2));
