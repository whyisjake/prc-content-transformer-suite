/**
 * Editor sidebar panel for audio narration.
 *
 * Replaces the classic meta box. A document setting panel sits with the rest
 * of the post's settings, and unlike a meta box it is present in the site
 * editor and in any block-based editing context.
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Flex,
	FlexItem,
	Notice,
	Spinner,
	__experimentalHStack as HStack,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';

const NAMESPACE = '/prc-audio-narration/v1';
const POLL_INTERVAL = 4000;

/**
 * Human label for each narration state.
 *
 * @param {string} state One of none, pending, ready, stale.
 * @return {string} Label.
 */
function stateLabel( state ) {
	switch ( state ) {
		case 'pending':
			return __( 'Generating…', 'prc-audio-narration' );
		case 'ready':
			return __( 'Ready', 'prc-audio-narration' );
		case 'stale':
			return __( 'Out of date', 'prc-audio-narration' );
		default:
			return __( 'Not generated', 'prc-audio-narration' );
	}
}

function NarrationPanel() {
	const postId = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostId(),
		[]
	);
	const isNewPost = useSelect(
		( select ) => select( 'core/editor' ).isEditedPostNew(),
		[]
	);

	const [ data, setData ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ estimate, setEstimate ] = useState( null );
	const [ error, setError ] = useState( null );
	const timer = useRef( null );

	const path = `${ NAMESPACE }/posts/${ postId }/narration`;

	const stopPolling = () => {
		if ( timer.current ) {
			clearTimeout( timer.current );
			timer.current = null;
		}
	};

	const load = ( { poll = false } = {} ) => {
		apiFetch( { path } )
			.then( ( next ) => {
				setData( next );
				if ( 'pending' === next.state ) {
					timer.current = setTimeout(
						() => load( { poll: true } ),
						POLL_INTERVAL
					);
				} else if ( poll ) {
					stopPolling();
				}
			} )
			.catch( ( err ) => {
				stopPolling();
				setError( err.message );
			} );
	};

	useEffect( () => {
		if ( postId && ! isNewPost ) {
			load();
		}
		return stopPolling;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ postId ] );

	if ( isNewPost ) {
		return (
			<Text variant="muted">
				{ __(
					'Save this post before generating narration.',
					'prc-audio-narration'
				) }
			</Text>
		);
	}

	if ( ! data ) {
		return (
			<Flex justify="flex-start" gap={ 2 }>
				<Spinner />
				<Text>{ __( 'Checking…', 'prc-audio-narration' ) }</Text>
			</Flex>
		);
	}

	const run = ( method ) => {
		setBusy( true );
		setError( null );

		apiFetch( { path, method } )
			.then( ( next ) => {
				setData( next );
				setEstimate( null );
				if ( 'pending' === next.state ) {
					timer.current = setTimeout(
						() => load( { poll: true } ),
						POLL_INTERVAL
					);
				}
			} )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusy( false ) );
	};

	// Estimating resolves the narration script, which is a model call, so it
	// is an explicit action rather than something the panel does on load.
	const loadEstimate = () => {
		setBusy( true );
		apiFetch( { path: `${ path }?estimate=1` } )
			.then( ( next ) => setEstimate( next.estimate ) )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusy( false ) );
	};

	const { state, narration } = data;
	const pending = 'pending' === state;

	return (
		<VStack spacing={ 3 }>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ ! data.provider && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'No speech provider is configured.',
						'prc-audio-narration'
					) }
				</Notice>
			) }

			{ 'stale' === state && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This post changed after the audio was generated. Regenerate to match the current text.',
						'prc-audio-narration'
					) }
				</Notice>
			) }

			<HStack justify="space-between">
				<Text weight={ 600 }>{ __( 'Status', 'prc-audio-narration' ) }</Text>
				<Text variant="muted">{ stateLabel( state ) }</Text>
			</HStack>

			{ narration && (
				<VStack spacing={ 2 }>
					{ /* eslint-disable-next-line jsx-a11y/media-has-caption */ }
					<audio
						controls
						preload="none"
						src={ narration.url }
						style={ { width: '100%' } }
						aria-label={ __( 'Narration preview', 'prc-audio-narration' ) }
					/>
					<Text variant="muted">
						{ sprintf(
							/* translators: 1: duration, 2: voice identifier */
							__( '%1$s · voice %2$s', 'prc-audio-narration' ),
							narration.duration_formatted ||
								__( 'unknown length', 'prc-audio-narration' ),
							narration.voice || '—'
						) }
					</Text>
				</VStack>
			) }

			{ estimate && ! estimate.error && (
				<Notice status="info" isDismissible={ false }>
					{ sprintf(
						/* translators: 1: character count, 2: chunk count, 3: cost */
						__(
							'%1$s characters, %2$d request(s), about $%3$s.',
							'prc-audio-narration'
						),
						estimate.characters.toLocaleString(),
						estimate.chunks,
						estimate.estimated_cost.toFixed( 2 )
					) }
				</Notice>
			) }

			<Flex gap={ 2 } justify="flex-start" wrap>
				<FlexItem>
					<Button
						variant="primary"
						onClick={ () => run( 'POST' ) }
						isBusy={ busy || pending }
						disabled={ busy || pending || ! data.provider }
					>
						{ narration
							? __( 'Regenerate', 'prc-audio-narration' )
							: __( 'Generate audio', 'prc-audio-narration' ) }
					</Button>
				</FlexItem>

				{ ! estimate && (
					<FlexItem>
						<Button
							variant="secondary"
							onClick={ loadEstimate }
							disabled={ busy || pending }
						>
							{ __( 'Estimate cost', 'prc-audio-narration' ) }
						</Button>
					</FlexItem>
				) }

				{ narration && (
					<FlexItem>
						<Button
							isDestructive
							variant="tertiary"
							onClick={ () => run( 'DELETE' ) }
							disabled={ busy }
						>
							{ __( 'Remove', 'prc-audio-narration' ) }
						</Button>
					</FlexItem>
				) }
			</Flex>

			<Text variant="muted">
				{ __(
					'Speech synthesis is billed per character, so audio is only generated when you ask for it.',
					'prc-audio-narration'
				) }
			</Text>
		</VStack>
	);
}

registerPlugin( 'prc-audio-narration', {
	render: () => (
		<PluginDocumentSettingPanel
			name="prc-audio-narration"
			title={ __( 'Audio Narration', 'prc-audio-narration' ) }
			className="prc-audio-narration-panel"
		>
			<NarrationPanel />
		</PluginDocumentSettingPanel>
	),
} );
