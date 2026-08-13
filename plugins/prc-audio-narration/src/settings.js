/**
 * Settings screen for audio narration.
 *
 * Rendered client-side with @wordpress/components so the screen matches
 * current admin UI conventions rather than the classic form-table layout.
 */

import { createRoot, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Flex,
	FlexItem,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	__experimentalHeading as Heading,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';

const NAMESPACE = '/prc-audio-narration/v1';

/**
 * Describe where the active key comes from, so an admin editing a field that
 * is being overridden by a constant is told rather than left confused.
 *
 * @param {string} source One of constant, connector, option, none.
 * @return {{status: string, message: string}|null} Notice content.
 */
function keySourceNotice( source ) {
	switch ( source ) {
		case 'constant':
			return {
				status: 'info',
				message: __(
					'The API key is set by a server constant. It takes precedence over anything entered here.',
					'prc-audio-narration'
				),
			};
		case 'connector':
			return {
				status: 'info',
				message: __(
					'The API key comes from the AI connectors screen. It takes precedence over the field below.',
					'prc-audio-narration'
				),
			};
		case 'none':
			return {
				status: 'warning',
				message: __(
					'No API key is configured. Narration cannot be generated until one is set.',
					'prc-audio-narration'
				),
			};
		default:
			return null;
	}
}

function SettingsPage() {
	const [ settings, setSettings ] = useState( null );
	const [ voices, setVoices ] = useState( [] );
	const [ apiKey, setApiKey ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		apiFetch( { path: `${ NAMESPACE }/settings` } )
			.then( setSettings )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			);

		apiFetch( { path: `${ NAMESPACE }/voices` } )
			.then( ( data ) => setVoices( data.voices || [] ) )
			.catch( () => setVoices( [] ) );
	}, [] );

	if ( ! settings ) {
		return (
			<Flex justify="flex-start" gap={ 2 }>
				<Spinner />
				<Text>{ __( 'Loading settings…', 'prc-audio-narration' ) }</Text>
			</Flex>
		);
	}

	const save = () => {
		setSaving( true );
		setNotice( null );

		apiFetch( {
			path: `${ NAMESPACE }/settings`,
			method: 'POST',
			data: {
				voice_id: settings.voice_id,
				model_id: settings.model_id,
				// Only sent when the admin actually typed one, so saving the
				// form does not wipe a stored key.
				...( apiKey ? { api_key: apiKey } : {} ),
			},
		} )
			.then( ( updated ) => {
				setSettings( updated );
				setApiKey( '' );
				setNotice( {
					status: 'success',
					message: __( 'Settings saved.', 'prc-audio-narration' ),
				} );
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			)
			.finally( () => setSaving( false ) );
	};

	const sourceNotice = keySourceNotice( settings.key_source );

	const voiceOptions = [
		{ value: '', label: __( 'Select a voice…', 'prc-audio-narration' ) },
		...voices.map( ( voice ) => ( {
			value: voice.id,
			label: voice.description
				? `${ voice.name } — ${ voice.description }`
				: voice.name,
		} ) ),
	];

	const modelOptions = settings.models.map( ( model ) => ( {
		value: model.id,
		label: sprintf(
			/* translators: 1: model identifier, 2: character ceiling */
			__( '%1$s (up to %2$s characters per request)', 'prc-audio-narration' ),
			model.id,
			model.max_characters.toLocaleString()
		),
	} ) );

	return (
		<VStack spacing={ 4 }>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			{ sourceNotice && (
				<Notice status={ sourceNotice.status } isDismissible={ false }>
					{ sourceNotice.message }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<Heading level={ 3 }>
						{ __( 'Speech provider', 'prc-audio-narration' ) }
					</Heading>
				</CardHeader>
				<CardBody>
					<VStack spacing={ 4 }>
						<TextControl
							__nextHasNoMarginBottom
							type="password"
							autoComplete="off"
							label={ __( 'ElevenLabs API key', 'prc-audio-narration' ) }
							help={
								settings.key_locked
									? __(
											'Overridden by a server constant.',
											'prc-audio-narration'
									  )
									: __(
											'Leave blank to keep the saved key.',
											'prc-audio-narration'
									  )
							}
							value={ apiKey }
							disabled={ settings.key_locked }
							onChange={ setApiKey }
						/>

						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Model', 'prc-audio-narration' ) }
							help={ __(
								'The character ceiling determines how long articles are split for synthesis. Fewer splits means fewer audible seams.',
								'prc-audio-narration'
							) }
							value={ settings.model_id }
							options={ modelOptions }
							onChange={ ( model_id ) =>
								setSettings( { ...settings, model_id } )
							}
						/>
					</VStack>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<Heading level={ 3 }>
						{ __( 'Voice', 'prc-audio-narration' ) }
					</Heading>
				</CardHeader>
				<CardBody>
					<VStack spacing={ 4 }>
						{ voices.length > 0 ? (
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Default voice', 'prc-audio-narration' ) }
								help={ __(
									'Used for all narration unless a post overrides it.',
									'prc-audio-narration'
								) }
								value={ settings.voice_id }
								options={ voiceOptions }
								onChange={ ( voice_id ) =>
									setSettings( { ...settings, voice_id } )
								}
							/>
						) : (
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Default voice ID', 'prc-audio-narration' ) }
								help={ __(
									'Voices could not be loaded. Enter a voice ID manually.',
									'prc-audio-narration'
								) }
								value={ settings.voice_id }
								onChange={ ( voice_id ) =>
									setSettings( { ...settings, voice_id } )
								}
							/>
						) }

						<Text variant="muted">
							{ __(
								'Narration is only generated when an editor asks for it, because speech synthesis is billed per character.',
								'prc-audio-narration'
							) }{ ' ' }
							<ExternalLink href="https://elevenlabs.io/app/voice-library">
								{ __( 'Browse voices', 'prc-audio-narration' ) }
							</ExternalLink>
						</Text>
					</VStack>
				</CardBody>
			</Card>

			<FlexItem>
				<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ saving }>
					{ saving
						? __( 'Saving…', 'prc-audio-narration' )
						: __( 'Save settings', 'prc-audio-narration' ) }
				</Button>
			</FlexItem>
		</VStack>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'prc-audio-narration-settings' );

	if ( root ) {
		createRoot( root ).render( <SettingsPage /> );
	}
} );
