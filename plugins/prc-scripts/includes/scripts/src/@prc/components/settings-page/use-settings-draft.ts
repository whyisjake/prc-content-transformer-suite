import { useEffect, useState } from '@wordpress/element';

/**
 * Keeps local draft state in sync with a store-backed value.
 *
 * @param storeValue Current value from the settings store.
 */
export function useSettingsDraft<T>(storeValue: T): [T, (value: T) => void] {
	const [draft, setDraft] = useState<T>(storeValue);

	useEffect(() => {
		setDraft(storeValue);
	}, [storeValue]);

	return [draft, setDraft];
}
