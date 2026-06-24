/* eslint-disable @wordpress/i18n-text-domain -- textDomain is supplied by consumer plugins */
import { __ } from '@wordpress/i18n';
import {
	__experimentalHStack as HStack,
	__experimentalText as Text,
} from '@wordpress/components';

import type { ConnectionBadgeProps } from './types';

export default function ConnectionBadge({
	connected,
	connectedLabel,
	disconnectedLabel,
	textDomain = 'default',
}: ConnectionBadgeProps) {
	return (
		<HStack spacing={1} style={{ display: 'inline-flex', width: 'auto' }}>
			<span
				style={{
					display: 'inline-block',
					width: 8,
					height: 8,
					borderRadius: '50%',
					background: connected ? '#00a32a' : '#d63638',
					flexShrink: 0,
					marginTop: 2,
				}}
			/>
			<Text size={12} color={connected ? '#00a32a' : '#d63638'}>
				{connected
					? (connectedLabel ?? __('Connected', textDomain))
					: (disconnectedLabel ?? __('Not connected', textDomain))}
			</Text>
		</HStack>
	);
}
