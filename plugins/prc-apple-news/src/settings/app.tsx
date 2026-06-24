/**
 * Apple News Settings — main app component.
 *
 * Provides a form to save Apple News API credentials and test the connection.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	TextControl,
	Notice,
	Spinner,
	__experimentalVStack as VStack,
	__experimentalHeading as Heading,
	__experimentalText as Text,
} from '@wordpress/components';
import type { SettingsResponse, TestResponse } from './types';

import './style.scss';

const REST_BASE = '/prc-apple-news/v1';

export default function SettingsApp() {
	// ── Loading / save state ──────────────────────────────────────────────
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isTesting, setIsTesting ] = useState( false );

	// ── Field values ──────────────────────────────────────────────────────
	const [ apiKey, setApiKey ] = useState( '' );
	const [ apiSecret, setApiSecret ] = useState( '' );
	const [ channelUuid, setChannelUuid ] = useState( '' );

	// ── Secret masking ────────────────────────────────────────────────────
	// hasSecret: true means a secret is already stored server-side.
	// When true the input is hidden and replaced with a masked placeholder.
	const [ hasSecret, setHasSecret ] = useState( false );
	const [ isChangingSecret, setIsChangingSecret ] = useState( false );

	// ── Dirty tracking ────────────────────────────────────────────────────
	const [ isDirty, setIsDirty ] = useState( false );

	// ── Notices ───────────────────────────────────────────────────────────
	const [ saveNotice, setSaveNotice ] = useState< {
		status: 'success' | 'error';
		message: string;
	} | null >( null );
	const [ testNotice, setTestNotice ] = useState< {
		status: 'success' | 'error';
		message: string;
	} | null >( null );

	// Warn on unload when there are unsaved changes.
	useEffect( () => {
		if ( ! isDirty ) {
			return;
		}
		const handler = ( e: BeforeUnloadEvent ) => {
			e.preventDefault();
			e.returnValue = '';
		};
		window.addEventListener( 'beforeunload', handler );
		return () => window.removeEventListener( 'beforeunload', handler );
	}, [ isDirty ] );

	// ── Fetch settings on mount ───────────────────────────────────────────
	useEffect( () => {
		apiFetch< SettingsResponse >( {
			path: `${ REST_BASE }/settings`,
		} )
			.then( ( data ) => {
				setApiKey( data.api_key ?? '' );
				setChannelUuid( data.channel_uuid ?? '' );
				setHasSecret( data.has_secret ?? false );
			} )
			.catch( () => {
				setSaveNotice( {
					status: 'error',
					message: __( 'Failed to load settings.', 'prc-apple-news' ),
				} );
			} )
			.finally( () => setIsLoading( false ) );
	}, [] );

	// ── Field change helpers ──────────────────────────────────────────────
	const handleApiKeyChange = useCallback( ( value: string ) => {
		setApiKey( value );
		setIsDirty( true );
	}, [] );

	const handleApiSecretChange = useCallback( ( value: string ) => {
		setApiSecret( value );
		setIsDirty( true );
	}, [] );

	const handleChannelUuidChange = useCallback( ( value: string ) => {
		setChannelUuid( value );
		setIsDirty( true );
	}, [] );

	const handleChangeSecret = useCallback( () => {
		setIsChangingSecret( true );
		setApiSecret( '' );
		setIsDirty( true );
	}, [] );

	// ── Save handler ──────────────────────────────────────────────────────
	const handleSave = useCallback( async () => {
		setIsSaving( true );
		setSaveNotice( null );

		const body: Record< string, string > = {
			api_key: apiKey,
			channel_uuid: channelUuid,
		};

		// Only include the secret if the user has explicitly entered a new one.
		if ( isChangingSecret && apiSecret.trim() ) {
			body.api_secret = apiSecret;
		}

		try {
			const data = await apiFetch< SettingsResponse >( {
				path: `${ REST_BASE }/settings`,
				method: 'POST',
				data: body,
			} );

			setApiKey( data.api_key ?? '' );
			setChannelUuid( data.channel_uuid ?? '' );
			setHasSecret( data.has_secret ?? false );
			setApiSecret( '' );
			setIsChangingSecret( false );
			setIsDirty( false );
			setSaveNotice( {
				status: 'success',
				message: __( 'Settings saved.', 'prc-apple-news' ),
			} );
		} catch ( err: unknown ) {
			const message =
				err instanceof Error
					? err.message
					: __( 'An unknown error occurred.', 'prc-apple-news' );
			setSaveNotice( { status: 'error', message } );
		} finally {
			setIsSaving( false );
		}
	}, [ apiKey, apiSecret, channelUuid, isChangingSecret ] );

	// ── Test connection handler ───────────────────────────────────────────
	const handleTest = useCallback( async () => {
		setIsTesting( true );
		setTestNotice( null );

		try {
			const data = await apiFetch< TestResponse >( {
				path: `${ REST_BASE }/test`,
			} );

			if ( data.success ) {
				setTestNotice( {
					status: 'success',
					message: data.channel_name
						? `${ __( 'Connected to:', 'prc-apple-news' ) } ${ data.channel_name }`
						: __( 'Connection successful.', 'prc-apple-news' ),
				} );
			} else {
				setTestNotice( {
					status: 'error',
					message:
						data.error ??
						__( 'Connection failed.', 'prc-apple-news' ),
				} );
			}
		} catch ( err: unknown ) {
			const message =
				err instanceof Error
					? err.message
					: __( 'Connection test failed.', 'prc-apple-news' );
			setTestNotice( { status: 'error', message } );
		} finally {
			setIsTesting( false );
		}
	}, [] );

	// ── Derived: can we test? (key + uuid must be present) ───────────────
	const canTest = apiKey.trim().length > 0 && channelUuid.trim().length > 0;

	// ── Render ────────────────────────────────────────────────────────────
	if ( isLoading ) {
		return (
			<div className="prc-apple-news-settings__loading">
				<Spinner />
			</div>
		);
	}

	return (
		<div className="prc-apple-news-settings">
			<VStack spacing={ 4 } className="prc-apple-news-settings__header">
				<Heading level={ 1 }>
					{ __( 'Apple News Settings', 'prc-apple-news' ) }
				</Heading>
				<Text className="prc-apple-news-settings__header-description">
					{ __(
						'Configure the Apple News API credentials used to publish PRC content to Apple News.',
						'prc-apple-news'
					) }
				</Text>
			</VStack>

			{ saveNotice && (
				<Notice
					status={ saveNotice.status }
					onRemove={ () => setSaveNotice( null ) }
					className="prc-apple-news-settings__notice"
				>
					{ saveNotice.message }
				</Notice>
			) }

			<VStack
				spacing={ 4 }
				className="prc-apple-news-settings__form"
				as="form"
			>
				{ /* API Key */ }
				<TextControl
					label={ __( 'API Key', 'prc-apple-news' ) }
					value={ apiKey }
					onChange={ handleApiKeyChange }
					__nextHasNoMarginBottom
				/>

				{ /* API Secret — masked when already stored */ }
				<div className="prc-apple-news-settings__secret-field">
					{ hasSecret && ! isChangingSecret ? (
						<div className="prc-apple-news-settings__secret-stored">
							<TextControl
								label={ __( 'API Secret', 'prc-apple-news' ) }
								value="••••••••"
								onChange={ () => {} }
								disabled
								__nextHasNoMarginBottom
							/>
							<Button
								variant="link"
								__next40pxDefaultSize
								onClick={ handleChangeSecret }
								className="prc-apple-news-settings__change-secret"
							>
								{ __( 'Change', 'prc-apple-news' ) }
							</Button>
						</div>
					) : (
						<TextControl
							label={ __( 'API Secret', 'prc-apple-news' ) }
							type="password"
							value={ apiSecret }
							onChange={ handleApiSecretChange }
							help={
								hasSecret
									? __(
											'Enter a new secret to replace the stored one.',
											'prc-apple-news'
									  )
									: __(
											'Your Apple News API secret key.',
											'prc-apple-news'
									  )
							}
							__nextHasNoMarginBottom
						/>
					) }
				</div>

				{ /* Channel UUID */ }
				<TextControl
					label={ __( 'Channel UUID', 'prc-apple-news' ) }
					value={ channelUuid }
					onChange={ handleChannelUuidChange }
					help={ __(
						'The Apple News channel UUID for Pew Research Center.',
						'prc-apple-news'
					) }
					__nextHasNoMarginBottom
				/>

				{ /* Test connection */ }
				<div className="prc-apple-news-settings__test-row">
					<Button
						variant="secondary"
						__next40pxDefaultSize
						style={ { width: '100%', justifyContent: 'center' } }
						onClick={ handleTest }
						isBusy={ isTesting }
						disabled={ ! canTest || isTesting }
					>
						{ __( 'Test Connection', 'prc-apple-news' ) }
					</Button>
					{ testNotice && (
						<Notice
							status={ testNotice.status }
							onRemove={ () => setTestNotice( null ) }
							className="prc-apple-news-settings__test-notice"
						>
							{ testNotice.message }
						</Notice>
					) }
				</div>

				{ /* Save */ }
				<div className="prc-apple-news-settings__actions">
					<Button
						variant="primary"
						__next40pxDefaultSize
						style={ { width: '100%', justifyContent: 'center' } }
						onClick={ handleSave }
						isBusy={ isSaving }
						disabled={ isSaving }
					>
						{ __( 'Save Settings', 'prc-apple-news' ) }
					</Button>
				</div>
			</VStack>
		</div>
	);
}
