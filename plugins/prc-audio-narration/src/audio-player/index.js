/**
 * Audio narration player block.
 *
 * Dynamic: the markup is rendered in PHP so the audio URL is resolved at
 * request time. Saving a URL into post content would leave it pointing at a
 * deleted attachment the first time narration is regenerated.
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import {
	PanelBody,
	Placeholder,
	Spinner,
	TextControl,
	ToggleControl,
	__experimentalText as Text,
} from '@wordpress/components';

import metadata from './block.json';

const NAMESPACE = '/prc-audio-narration/v1';

/**
 * Editor preview.
 *
 * Shows the narration state rather than attempting playback. The point of
 * the preview is to tell an editor whether this block will render anything
 * on the front end, which is not obvious from an empty player.
 *
 * @param {Object} props               Block props.
 * @param {Object} props.attributes    Block attributes.
 * @param {Object} props.context       Block context.
 * @param {Function} props.setAttributes Attribute setter.
 * @return {Element} Editor markup.
 */
function Edit( { attributes, context, setAttributes } ) {
	const blockProps = useBlockProps();

	const editorPostId = useSelect(
		( select ) => select( 'core/editor' )?.getCurrentPostId(),
		[]
	);
	const postId = context?.postId || editorPostId;

	const [ data, setData ] = useState( null );
	const [ failed, setFailed ] = useState( false );

	useEffect( () => {
		if ( ! postId ) {
			return;
		}

		apiFetch( { path: `${ NAMESPACE }/posts/${ postId }/narration` } )
			.then( setData )
			.catch( () => setFailed( true ) );
	}, [ postId ] );

	const inspector = (
		<InspectorControls>
			<PanelBody title={ __( 'Player', 'prc-audio-narration' ) }>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Caption', 'prc-audio-narration' ) }
					help={ __(
						'Shown above the player. Leave blank for the default.',
						'prc-audio-narration'
					) }
					value={ attributes.label }
					onChange={ ( label ) => setAttributes( { label } ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Show duration', 'prc-audio-narration' ) }
					checked={ attributes.showDuration }
					onChange={ ( showDuration ) => setAttributes( { showDuration } ) }
				/>
			</PanelBody>
		</InspectorControls>
	);

	let body;

	if ( failed ) {
		body = (
			<Placeholder
				icon="controls-volumeon"
				label={ __( 'Audio Narration', 'prc-audio-narration' ) }
			>
				<Text>
					{ __(
						'Narration status could not be loaded.',
						'prc-audio-narration'
					) }
				</Text>
			</Placeholder>
		);
	} else if ( ! data ) {
		body = (
			<Placeholder icon="controls-volumeon">
				<Spinner />
			</Placeholder>
		);
	} else if ( 'ready' === data.state && data.narration ) {
		body = (
			<figure>
				{ attributes.label && <figcaption>{ attributes.label }</figcaption> }
				{ /* eslint-disable-next-line jsx-a11y/media-has-caption */ }
				<audio
					controls
					preload="none"
					src={ data.narration.url }
					style={ { width: '100%' } }
				/>
			</figure>
		);
	} else {
		// Every non-ready state renders nothing on the front end, so the
		// preview says which one it is rather than showing a dead player.
		const reason =
			'stale' === data.state
				? __(
						'Narration is out of date and will not appear until it is regenerated.',
						'prc-audio-narration'
				  )
				: 'pending' === data.state
				? __( 'Narration is being generated.', 'prc-audio-narration' )
				: __(
						'This article has no narration yet. Generate it from the Audio Narration panel.',
						'prc-audio-narration'
				  );

		body = (
			<Placeholder
				icon="controls-volumeon"
				label={ __( 'Audio Narration', 'prc-audio-narration' ) }
			>
				<Text>{ reason }</Text>
			</Placeholder>
		);
	}

	return (
		<div { ...blockProps }>
			{ inspector }
			{ body }
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	// Dynamic block: nothing is written into post content.
	save: () => null,
} );
