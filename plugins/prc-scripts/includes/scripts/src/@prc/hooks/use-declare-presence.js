/**
 * WordPress Dependencies
 */
import { useEffect, useMemo, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal Dependencies
 */
import { isPresenceApiEnabled } from './presence-utils';

/**
 * Default heartbeat interval (ms) for re-declaring presence.
 *
 * Presence entries TTL after 60 seconds (WP_PRESENCE_DEFAULT_TTL in presence-api),
 * so we re-declare at 30 s to keep our entry alive with a comfortable safety margin.
 */
const DEFAULT_INTERVAL_MS = 30000;

/**
 * Generates a stable client id for the lifetime of the hook instance.
 *
 * @param {string} prefix Client id prefix.
 * @return {string} `${prefix}-${uuid}`
 */
function generateClientId(prefix) {
	const uuid =
		typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
			? crypto.randomUUID()
			: `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
	return `${prefix}-${uuid}`;
}

/**
 * Declares this client's presence (and activity attributes) into a Presence API
 * room and keeps it alive.
 *
 * Think of this as a "mixin" on top of baseline presence: each call adds another
 * entry for the current user in the room, carrying a `data` payload. Observers
 * using `usePresenceUsers` see all entries for a user shallow-merged into a
 * single record (newest-wins on key collision), so independent components can
 * contribute activity attributes without coordinating with each other.
 *
 * Use as a companion to `usePresenceUsers` when a consumer wants to *declare*
 * activity (e.g. "editing the data table") rather than just observe presence.
 * The hook manages a stable `client_id` for the mount, an immediate POST on
 * mount and data change, a heartbeat re-POST on an interval (to outrun the
 * 60 s server-side TTL), and a DELETE on unmount or room change.
 *
 * Multiple hook instances in the same component (or across blocks) each hold
 * their own `client_id` and appear as separate entries in the room — this is
 * intentional and how the Presence API's editor-ping and post-lock bridge
 * coexist in the same room.
 *
 * The `data` payload must be a plain object ≤ 10 KB and ≤ 3 levels deep
 * (enforced by the REST controller). Anything else is coerced to `{}`.
 *
 * When `room` is null/undefined or `enabled` is false, the hook performs no
 * network calls and, if previously declaring, cleans up its entry.
 *
 * @param {string|null|undefined} room                              Presence room id (e.g. `postType/chart:123`).
 * @param {Object}                [data]                            Arbitrary presence state (≤ 10 KB, ≤ 3 levels deep).
 * @param {Object}                [options]                         Hook options.
 * @param {boolean}               [options.enabled=true]            When false, the hook is fully inert.
 * @param {number}                [options.interval=30000]          Heartbeat interval in ms.
 * @param {string}                [options.clientIdPrefix='client'] Prefix for the generated client id.
 * @param {string|false}          [options.debug=false]             When a string, logs verbose diagnostics under that tag. When false, silent.
 * @return {{ clientId: string }} The stable client id for this hook instance.
 */
export default function useDeclarePresence(room, data, options = {}) {
	const {
		enabled = true,
		interval = DEFAULT_INTERVAL_MS,
		clientIdPrefix = 'client',
		debug = false,
	} = options;
	const debugLog = (...args) => {
		if (!debug) return;
		// eslint-disable-next-line no-console
		console.log(`[${debug}]`, ...args);
	};
	const debugWarn = (...args) => {
		if (!debug) return;
		// eslint-disable-next-line no-console
		console.warn(`[${debug}]`, ...args);
	};

	const clientIdRef = useRef(null);
	if (clientIdRef.current === null) {
		clientIdRef.current = generateClientId(clientIdPrefix);
	}

	// Compare data by content, not reference, so inline object literals don't thrash.
	const dataKey = useMemo(() => {
		try {
			return JSON.stringify(data ?? {});
		} catch {
			return '{}';
		}
	}, [data]);

	// Stable ref to the latest data so the heartbeat always POSTs current state
	// without restarting the interval on every render.
	const dataRef = useRef(data);
	useEffect(() => {
		dataRef.current = data;
	}, [data]);

	// Lifecycle: DELETE on room/enabled change or unmount.
	// POST is handled by the heartbeat effect below; this one only owns cleanup.
	useEffect(() => {
		if (!room || !enabled || !isPresenceApiEnabled()) {
			debugLog('lifecycle inert', { room, enabled });
			return undefined;
		}
		const clientId = clientIdRef.current;
		return () => {
			debugLog('DELETE presence', { room, clientId });
			apiFetch({
				path: `/wp-presence/v1/presence?room=${encodeURIComponent(
					room
				)}&client_id=${encodeURIComponent(clientId)}`,
				method: 'DELETE',
			}).catch((err) => {
				debugWarn('DELETE failed (non-critical)', err);
			});
		};
	}, [room, enabled]);

	// Heartbeat: POST on mount, data change, and interval.
	useEffect(() => {
		if (!room || !enabled || !isPresenceApiEnabled()) {
			return undefined;
		}

		const clientId = clientIdRef.current;

		const declare = () => {
			debugLog('POST presence', {
				room,
				clientId,
				data: dataRef.current ?? {},
			});
			apiFetch({
				path: '/wp-presence/v1/presence',
				method: 'POST',
				data: {
					room,
					client_id: clientId,
					data: dataRef.current ?? {},
				},
			})
				.then((res) => {
					debugLog('POST ok', { room, clientId, response: res });
				})
				.catch((err) => {
					debugWarn('POST failed (will retry on heartbeat)', err);
				});
		};

		declare();
		const id = setInterval(declare, interval);

		return () => clearInterval(id);
	}, [room, enabled, interval, dataKey]);

	return {
		clientId: clientIdRef.current,
	};
}
