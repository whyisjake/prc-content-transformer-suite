import { __ } from '@wordpress/i18n';
import { SettingsPage } from '@prc/components';

import './style.scss';
import './store';
import { fetchSettings } from './api';
import MailchimpSection from './components/mailchimp-section';
import MandrillSection from './components/mandrill-section';

const TEXT_DOMAIN = 'prc-email-builder';

export default function SettingsApp() {
	return (
		<SettingsPage
			title={__('Newsletter Builder Settings', TEXT_DOMAIN)}
			description={__(
				'Configure Mailchimp integration and default sender settings for the Newsletter Builder.',
				TEXT_DOMAIN
			)}
			textDomain={TEXT_DOMAIN}
			idPrefix="prc-email-builder-settings"
			sections={[
				{
					slug: 'mailchimp',
					title: __('Mailchimp', TEXT_DOMAIN),
					description: __(
						'API connection, sender name, and reply-to email address.',
						TEXT_DOMAIN
					),
					render: () => <MailchimpSection />,
				},
				{
					slug: 'mandrill',
					title: __('Mandrill Delivery', TEXT_DOMAIN),
					description: __(
						'Open/click tracking, tags, reply-to, and subaccount applied to all Mandrill sends (bulk and system emails).',
						TEXT_DOMAIN
					),
					render: () => <MandrillSection />,
				},
			]}
			onLoad={fetchSettings}
		/>
	);
}
