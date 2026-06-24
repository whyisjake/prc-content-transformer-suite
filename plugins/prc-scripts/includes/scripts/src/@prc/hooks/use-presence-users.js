/**
 * WordPress Dependencies
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal Dependencies
 */
import { isPresenceApiEnabled } from './presence-utils';

/**
 * Default interval (ms) for Presence API REST fetches.
 * Matches the default WordPress Heartbeat interval (15 s).
 */
const DEFAULT_INTERVAL_MS = 15000;

/**
 * Polls the WordPress Presence API for occupants of a room and resolves display names.
 *
 * When `room` is null or undefined, no requests run and the hook reports empty presence
 * (nothing to observe).
 *
 * A single user can hold multiple entries in one room (e.g. the editor-ping, a
 * post-lock bridge, or a custom `useDeclarePresence` client). Their `data`
 * payloads are merged per user, with newer entries overwriting older ones on
 * key collision.
 *
 * @param {string|null|undefined} room                        Presence room id (e.g. `postType/chart:123`).
 * @param {Object}                [options]                   Hook options.
 * @param {boolean}               [options.includeSelf=false] When false, the current user is excluded from `users`.
 * @param {number}                [options.interval=15000]    Fetch interval in ms.
 * @param {string|false}          [options.debug=false]       When a string, logs verbose diagnostics under that tag. When false, silent.
 * @return {{
 *   isPresent: boolean,
 *   users: Array<{ userId: number, displayName: string, data: Object }>
 * }} Presence state: whether anyone is in the room and the list of occupants.
 */
export default function usePresenceUsers(room, options = {}) {
	const {
		includeSelf = false,
		interval = DEFAULT_INTERVAL_MS,
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

	const [userIds, setUserIds] = useState([]);

	const currentUserId = useSelect(
		(select) => select('core').getCurrentUser()?.id,
		[]
	);

	const userNameCache = useRef({});
	const dataByUserIdRef = useRef({});
	const prevStateKey = useRef('');
	const intervalRef = useRef(null);

	const fetchPresence = useCallback(async () => {
		if (!room) {
			debugLog('skip fetch: no room');
			return;
		}
		if (!isPresenceApiEnabled()) {
			debugLog('skip fetch: presence api disabled');
			return;
		}

		try {
			debugLog('fetch start', { room, currentUserId, includeSelf });
			const entries = await apiFetch({
				path: `/wp-presence/v1/presence?room=${encodeURIComponent(
					room
				)}`,
			});

			debugLog('fetch response', {
				room,
				entryCount: Array.isArray(entries) ? entries.length : 'n/a',
				entries,
			});

			if (!Array.isArray(entries)) {
				debugWarn('non-array response, bailing', entries);
				return;
			}

			// Group entries by user id, respecting the `includeSelf` filter.
			// The REST endpoint returns newest-first; we keep that order and
			// merge oldest-to-newest so the most recent entry wins on key
			// collision.
			const entriesByUserId = {};
			entries.forEach((entry) => {
				const userId = Number(entry.user_id);
				if (!userId) {
					debugWarn('entry missing user_id', entry);
					return;
				}
				if (!includeSelf && userId === currentUserId) {
					debugLog('filtering self', { userId, currentUserId });
					return;
				}
				if (!entriesByUserId[userId]) {
					entriesByUserId[userId] = [];
				}
				entriesByUserId[userId].push(entry);
			});

			const presentUserIds = Object.keys(entriesByUserId).map(Number);
			debugLog('grouped users', {
				presentUserIds,
				entriesByUserId,
			});

			const nextDataByUserId = {};
			presentUserIds.forEach((userId) => {
				const merged = entriesByUserId[userId]
					.slice()
					.reverse()
					.reduce(
						(acc, entry) => ({ ...acc, ...(entry.data || {}) }),
						{}
					);
				nextDataByUserId[userId] = merged;
			});

			const idKey = [...presentUserIds].sort().join(',');
			const dataKey = JSON.stringify(nextDataByUserId);
			const stateKey = `${idKey}|${dataKey}`;

			if (stateKey === prevStateKey.current) {
				return;
			}
			prevStateKey.current = stateKey;

			const missing = presentUserIds.filter(
				(id) => !userNameCache.current[id]
			);
			if (missing.length) {
				await Promise.all(
					missing.map(async (userId) => {
						try {
							const user = await apiFetch({
								path: `/wp/v2/users/${userId}`,
							});
							userNameCache.current[userId] = user.name;
						} catch {
							userNameCache.current[userId] = `User ${userId}`;
						}
					})
				);
			}

			dataByUserIdRef.current = nextDataByUserId;
			setUserIds(presentUserIds);
			debugLog('state updated', {
				userIds: presentUserIds,
				dataByUserId: nextDataByUserId,
				userNameCache: { ...userNameCache.current },
			});
		} catch (err) {
			debugWarn('fetch error', err);
			setUserIds([]);
		}
	}, [room, currentUserId, includeSelf]);

	useEffect(() => {
		if (!room || !isPresenceApiEnabled()) {
			setUserIds([]);
			dataByUserIdRef.current = {};
			prevStateKey.current = '';
			return;
		}

		fetchPresence();
		intervalRef.current = setInterval(fetchPresence, interval);

		return () => {
			clearInterval(intervalRef.current);
		};
	}, [room, interval, fetchPresence]);

	const users = userIds.map((userId) => ({
		userId,
		displayName: userNameCache.current[userId] || `User ${userId}`,
		data: dataByUserIdRef.current[userId] || {},
	}));

	return {
		isPresent: users.length > 0,
		users,
	};
}
