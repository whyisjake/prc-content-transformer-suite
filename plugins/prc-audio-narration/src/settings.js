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
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis -- no stable equivalent; pinned to WP 6.8+.
	__experimentalDivider as Divider,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis -- no stable equivalent; pinned to WP 6.8+.
	__experimentalHeading as Heading,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis -- no stable equivalent; pinned to WP 6.8+.
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis -- no stable equivalent; pinned to WP 6.8+.
	__experimentalVStack as VStack,
} from '@wordpress/components';

const NAMESPACE = '/prc-audio-narration/v1';

/**
 * Stand-in shown in place of a configured key.
 *
 * The real key is never sent to the browser, so this is decoration that
 * signals "something is set" rather than a truncation of the actual value.
 */
const KEY_MASK = '••••••••••••••••••••';

/**
 * Short note explaining where a configured key comes from.
 *
 * Shown as field help rather than a notice: the fact is about this input, and
 * an admin who can see the field is disabled mostly needs to know why.
 *
 * @param {string} source One of constant, connector, option.
 * @return {string} Help text.
 */
function keySourceHelp( source ) {
	switch ( source ) {
		case 'constant':
			return __( 'Set by a server constant.', 'prc-audio-narration' );
		case 'connector':
			return __(
				'Set on the AI connectors screen.',
				'prc-audio-narration'
			);
		default:
			return __( 'Saved on this site.', 'prc-audio-narration' );
	}
}

/**
 * Help text naming the value a blank field will fall back to.
 *
 * Only shown while the field is empty; repeating the fallback next to a
 * filled-in field reads as though the entered value is being ignored.
 *
 * @param {string} value    Current field value.
 * @param {string} fallback Value used when the field is blank.
 * @return {string|undefined} Help text, or undefined when the field is set.
 */
function fallbackHelp( value, fallback ) {
	if ( value || ! fallback ) {
		return undefined;
	}

	return sprintf(
		/* translators: %s: fallback value */
		__( 'Defaults to %s.', 'prc-audio-narration' ),
		fallback
	);
}

function SettingsPage() {
	const [ settings, setSettings ] = useState( null );
	const [ voices, setVoices ] = useState( [] );
	const [ apiKey, setApiKey ] = useState( '' );
	const [ replacingKey, setReplacingKey ] = useState( false );
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
				<Text>
					{ __( 'Loading settings…', 'prc-audio-narration' ) }
				</Text>
			</Flex>
		);
	}

	const setPodcast = ( key, value ) =>
		setSettings( {
			...settings,
			podcast: { ...settings.podcast, [ key ]: value },
		} );

	const save = () => {
		setSaving( true );
		setNotice( null );

		apiFetch( {
			path: `${ NAMESPACE }/settings`,
			method: 'POST',
			data: {
				voice_id: settings.voice_id,
				model_id: settings.model_id,
				podcast: settings.podcast,
				// Only sent when the admin actually typed one, so saving the
				// form does not wipe a stored key.
				...( apiKey ? { api_key: apiKey } : {} ),
			},
		} )
			.then( ( updated ) => {
				setSettings( updated );
				setApiKey( '' );
				setReplacingKey( false );
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

	// A key can be present without being editable here: constants and the
	// connectors screen both win over the stored option.
	const hasKey = settings.has_key;
	const keyLocked =
		'constant' === settings.key_source ||
		'connector' === settings.key_source;
	const showMaskedKey = hasKey && ! replacingKey;

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
			__(
				'%1$s (up to %2$s characters per request)',
				'prc-audio-narration'
			),
			model.id,
			model.max_characters.toLocaleString()
		),
	} ) );

	return (
		<VStack spacing={ 4 }>
			<VStack spacing={ 1 }>
				<Heading level={ 2 }>
					{ __( 'Audio Narration', 'prc-audio-narration' ) }
				</Heading>
				<Text variant="muted">
					{ __(
						'Choose the voice and model used to read articles aloud. Narration is generated only when an editor asks for it, because speech synthesis is billed per character.',
						'prc-audio-narration'
					) }
				</Text>
			</VStack>

			<Divider margin={ 0 } />

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			{ ! hasKey && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'No API key is configured. Narration cannot be generated until one is set.',
						'prc-audio-narration'
					) }
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
						{ showMaskedKey ? (
							<Flex
								align="flex-end"
								gap={ 3 }
								justify="flex-start"
							>
								<FlexItem style={ { flexGrow: 1 } }>
									<TextControl
										__nextHasNoMarginBottom
										readOnly
										disabled
										label={ __(
											'ElevenLabs API key',
											'prc-audio-narration'
										) }
										help={ keySourceHelp(
											settings.key_source
										) }
										value={ KEY_MASK }
										onChange={ () => {} }
									/>
								</FlexItem>
								{ ! keyLocked && (
									<FlexItem>
										<Button
											variant="secondary"
											onClick={ () =>
												setReplacingKey( true )
											}
										>
											{ __(
												'Replace',
												'prc-audio-narration'
											) }
										</Button>
									</FlexItem>
								) }
							</Flex>
						) : (
							<Flex
								align="flex-end"
								gap={ 3 }
								justify="flex-start"
							>
								<FlexItem style={ { flexGrow: 1 } }>
									<TextControl
										__nextHasNoMarginBottom
										type="password"
										autoComplete="off"
										label={ __(
											'ElevenLabs API key',
											'prc-audio-narration'
										) }
										help={ __(
											'Saved when you save settings. It is never shown again.',
											'prc-audio-narration'
										) }
										value={ apiKey }
										onChange={ setApiKey }
									/>
								</FlexItem>
								{ replacingKey && (
									<FlexItem>
										<Button
											variant="tertiary"
											onClick={ () => {
												setReplacingKey( false );
												setApiKey( '' );
											} }
										>
											{ __(
												'Cancel',
												'prc-audio-narration'
											) }
										</Button>
									</FlexItem>
								) }
							</Flex>
						) }

						<SelectControl
							__nextHasNoMarginBottom
							label={ __(
								'When an article is edited',
								'prc-audio-narration'
							) }
							help={ __(
								'Editing an article does not change its audio. Choose whether the existing narration keeps playing until someone regenerates it.',
								'prc-audio-narration'
							) }
							value={ settings.stale_behavior }
							options={ [
								{
									value: 'keep',
									label: __(
										'Keep playing the existing audio',
										'prc-audio-narration'
									),
								},
								{
									value: 'hide',
									label: __(
										'Hide the audio until it is regenerated',
										'prc-audio-narration'
									),
								},
							] }
							onChange={ ( staleBehavior ) =>
								setSettings( {
									...settings,
									stale_behavior: staleBehavior,
								} )
							}
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
							onChange={ ( modelId ) =>
								setSettings( {
									...settings,
									model_id: modelId,
								} )
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
								label={ __(
									'Default voice',
									'prc-audio-narration'
								) }
								help={ __(
									'Used for all narration unless a post overrides it.',
									'prc-audio-narration'
								) }
								value={ settings.voice_id }
								options={ voiceOptions }
								onChange={ ( voiceId ) =>
									setSettings( {
										...settings,
										voice_id: voiceId,
									} )
								}
							/>
						) : (
							<TextControl
								__nextHasNoMarginBottom
								label={ __(
									'Default voice ID',
									'prc-audio-narration'
								) }
								help={ __(
									'Voices could not be loaded. Enter a voice ID manually.',
									'prc-audio-narration'
								) }
								value={ settings.voice_id }
								onChange={ ( voiceId ) =>
									setSettings( {
										...settings,
										voice_id: voiceId,
									} )
								}
							/>
						) }

						<Text variant="muted">
							<ExternalLink href="https://elevenlabs.io/app/voice-library">
								{ __(
									'Browse the full voice library',
									'prc-audio-narration'
								) }
							</ExternalLink>
						</Text>
					</VStack>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<Heading level={ 3 }>
						{ __( 'Podcast feed', 'prc-audio-narration' ) }
					</Heading>
				</CardHeader>
				<CardBody>
					<VStack spacing={ 4 }>
						<Text variant="muted">
							{ __(
								'Narrated articles are published at',
								'prc-audio-narration'
							) }{ ' ' }
							<ExternalLink href={ settings.podcast_feed_url }>
								{ settings.podcast_feed_url }
							</ExternalLink>
						</Text>

						{ settings.podcast_artwork_issues?.length > 0 && (
							<Notice status="warning" isDismissible={ false }>
								<strong>
									{ __(
										'Apple Podcasts will reject this feed:',
										'prc-audio-narration'
									) }
								</strong>
								<ul
									style={ {
										margin: '4px 0 0',
										paddingLeft: '18px',
									} }
								>
									{ settings.podcast_artwork_issues.map(
										( issue ) => (
											<li key={ issue }>{ issue }</li>
										)
									) }
								</ul>
							</Notice>
						) }

						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Show title', 'prc-audio-narration' ) }
							help={ fallbackHelp(
								settings.podcast.podcast_title,
								settings.podcast_resolved.title
							) }
							value={ settings.podcast.podcast_title }
							onChange={ ( value ) =>
								setPodcast( 'podcast_title', value )
							}
						/>

						<TextControl
							__nextHasNoMarginBottom
							label={ __(
								'Show description',
								'prc-audio-narration'
							) }
							value={ settings.podcast.podcast_description }
							onChange={ ( value ) =>
								setPodcast( 'podcast_description', value )
							}
						/>

						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Author', 'prc-audio-narration' ) }
							help={ fallbackHelp(
								settings.podcast.podcast_author,
								settings.podcast_resolved.author
							) }
							value={ settings.podcast.podcast_author }
							onChange={ ( value ) =>
								setPodcast( 'podcast_author', value )
							}
						/>

						<TextControl
							__nextHasNoMarginBottom
							type="email"
							label={ __( 'Owner email', 'prc-audio-narration' ) }
							help={ __(
								'Required by Apple Podcasts. Not shown publicly in most clients.',
								'prc-audio-narration'
							) }
							value={ settings.podcast.podcast_owner_email }
							onChange={ ( value ) =>
								setPodcast( 'podcast_owner_email', value )
							}
						/>

						<TextControl
							__nextHasNoMarginBottom
							type="url"
							label={ __( 'Artwork URL', 'prc-audio-narration' ) }
							help={ __(
								'Square JPEG or PNG, at least 1400 by 1400 pixels.',
								'prc-audio-narration'
							) }
							value={ settings.podcast.podcast_image }
							onChange={ ( value ) =>
								setPodcast( 'podcast_image', value )
							}
						/>

						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Category', 'prc-audio-narration' ) }
							help={ __(
								'An Apple Podcasts category, such as News or Science.',
								'prc-audio-narration'
							) }
							value={ settings.podcast.podcast_category }
							onChange={ ( value ) =>
								setPodcast( 'podcast_category', value )
							}
						/>

						<SelectControl
							__nextHasNoMarginBottom
							label={ __(
								'Explicit content',
								'prc-audio-narration'
							) }
							value={ settings.podcast.podcast_explicit }
							options={ [
								{
									value: 'false',
									label: __( 'No', 'prc-audio-narration' ),
								},
								{
									value: 'true',
									label: __( 'Yes', 'prc-audio-narration' ),
								},
							] }
							onChange={ ( value ) =>
								setPodcast( 'podcast_explicit', value )
							}
						/>
					</VStack>
				</CardBody>
			</Card>

			<FlexItem>
				<Button
					variant="primary"
					onClick={ save }
					isBusy={ saving }
					disabled={ saving }
				>
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
