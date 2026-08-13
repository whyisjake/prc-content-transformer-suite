/**
 * Keeps multiple narration players on a page in sync.
 *
 * A long article may carry a player at the top and another near the end. They
 * are separate <audio> elements playing the same file, so without this they
 * drift apart: two copies talking over each other, and a reader who scrolls
 * down finds a player sitting at 0:00 while the one above is eight minutes in.
 *
 * There is no framework here on purpose. The behaviour is a few listeners over
 * the native media element, and the block otherwise ships no front-end code.
 */

const SELECTOR = '.prc-audio-narration-player audio';

// timeupdate fires several times a second; mirroring every one of those across
// N elements is pointless when the visible clock only shows whole seconds.
const MIRROR_INTERVAL = 0.25;

// Below this, the two are showing the same position and writing would only
// provoke another seek.
const CLOSE_ENOUGH = 0.05;

/**
 * Elements whose next `seeked` was caused by us rather than by the reader.
 *
 * Setting currentTime resolves asynchronously, so a plain boolean guard is
 * already back to false by the time the event lands. Marking the element
 * instead survives the gap.
 *
 * @type {WeakSet<HTMLMediaElement>}
 */
const echoes = new WeakSet();

/**
 * Move a player to a position without treating it as a reader-driven seek.
 *
 * @param {HTMLMediaElement} audio Player to move.
 * @param {number}           time  Position in seconds.
 * @return {void}
 */
function mirrorTime( audio, time ) {
	if ( Math.abs( audio.currentTime - time ) < CLOSE_ENOUGH ) {
		return;
	}

	echoes.add( audio );

	try {
		audio.currentTime = time;
	} catch ( e ) {
		// Not seekable yet. The next mirror will land once metadata loads.
		echoes.delete( audio );
	}
}

/**
 * Wire one set of players that share a source.
 *
 * @param {HTMLMediaElement[]} players Players playing the same file.
 * @return {void}
 */
function link( players ) {
	// Which player the reader is driving. Everyone else follows it.
	let active = null;
	let lastMirror = -1;

	const others = ( self ) => players.filter( ( player ) => player !== self );

	const broadcast = ( self ) =>
		others( self ).forEach( ( player ) =>
			mirrorTime( player, self.currentTime )
		);

	players.forEach( ( audio ) => {
		audio.addEventListener( 'play', () => {
			active = audio;
			lastMirror = -1;

			// Two copies of the same narration playing at once is the worst
			// outcome here, so pausing the others comes before syncing them.
			others( audio ).forEach( ( player ) => {
				if ( ! player.paused ) {
					player.pause();
				}
			} );

			broadcast( audio );
		} );

		audio.addEventListener( 'timeupdate', () => {
			if ( active !== audio ) {
				return;
			}

			if (
				Math.abs( audio.currentTime - lastMirror ) < MIRROR_INTERVAL
			) {
				return;
			}

			lastMirror = audio.currentTime;
			broadcast( audio );
		} );

		audio.addEventListener( 'pause', () => {
			if ( active === audio ) {
				broadcast( audio );
			}
		} );

		// Scrubbing a player is a claim on it, even one that is not playing:
		// the reader picked that control, so its position wins.
		audio.addEventListener( 'seeked', () => {
			if ( echoes.has( audio ) ) {
				echoes.delete( audio );
				return;
			}

			active = audio;
			lastMirror = audio.currentTime;
			broadcast( audio );
		} );

		audio.addEventListener( 'ended', () => {
			if ( active === audio ) {
				broadcast( audio );
			}
		} );
	} );
}

/**
 * Find the players and group them by what they play.
 *
 * A query loop can put narrations for several different posts on one page.
 * Those are unrelated recordings and must not be synced to each other.
 *
 * @return {void}
 */
function init() {
	const players = Array.from( document.querySelectorAll( SELECTOR ) );

	if ( players.length < 2 ) {
		return;
	}

	const bySource = new Map();

	players.forEach( ( audio ) => {
		// The attribute, not currentSrc: these preload="none", so the resolved
		// source is empty until something makes them load.
		const source = audio.getAttribute( 'src' );

		if ( ! source ) {
			return;
		}

		if ( ! bySource.has( source ) ) {
			bySource.set( source, [] );
		}

		bySource.get( source ).push( audio );
	} );

	bySource.forEach( ( group ) => {
		if ( group.length > 1 ) {
			link( group );
		}
	} );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
