/**
 * Apple News sidebar — block editor PluginDocumentSettingPanel.
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

import AppleNewsPanel from './panel';

import './style.scss';

function AppleNewsDocumentPanel() {
	return (
		<PluginDocumentSettingPanel
			name="prc-apple-news"
			title={__('Apple News', 'prc-apple-news')}
			className="prc-apple-news-document-panel"
		>
			<AppleNewsPanel />
		</PluginDocumentSettingPanel>
	);
}

registerPlugin('prc-apple-news', {
	render: AppleNewsDocumentPanel,
});
