import { createRoot } from '@wordpress/element';
import type { ComponentType } from 'react';

/**
 * Mount a settings page React app into a WP Admin container div.
 *
 * @param containerId DOM id of the mount target rendered by PHP.
 * @param App         Root settings app component.
 */
export function mountSettingsPage(
	containerId: string,
	App: ComponentType
): void {
	document.addEventListener('DOMContentLoaded', () => {
		const container = document.getElementById(containerId);
		if (container) {
			const root = createRoot(container);
			root.render(<App />);
		}
	});
}
