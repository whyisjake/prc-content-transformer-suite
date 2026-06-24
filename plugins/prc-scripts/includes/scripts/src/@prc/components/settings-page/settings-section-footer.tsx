import { __ } from '@wordpress/i18n';
import {
	Button,
	Notice,
	__experimentalHStack as HStack,
} from '@wordpress/components';

import type { SettingsSectionFooterProps } from './types';

export default function SettingsSectionFooter({
	onSave,
	isBusy = false,
	error = null,
	onDismissError,
	saveLabel,
	savingLabel,
}: SettingsSectionFooterProps) {
	return (
		<>
			{error && (
				<Notice
					status="error"
					isDismissible={Boolean(onDismissError)}
					onRemove={onDismissError}
				>
					{error}
				</Notice>
			)}
			<HStack className="prc-settings__form-actions" justify="flex-end">
				<Button
					variant="primary"
					onClick={onSave}
					isBusy={isBusy}
					disabled={isBusy}
				>
					{isBusy && (savingLabel ?? __('Saving…'))}
					{!isBusy && saveLabel}
				</Button>
			</HStack>
		</>
	);
}
