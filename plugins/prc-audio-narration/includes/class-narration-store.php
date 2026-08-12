<?php
/**
 * Narration Store
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

/**
 * Owns the lifecycle of generated narration audio.
 *
 * Audio lives in the Media Library as an attachment parented to its source
 * post. The content hash stored alongside it mirrors the transformer's cache
 * invalidation, so staleness is a cheap comparison rather than a second
 * source of truth.
 */
class Narration_Store {

	/**
	 * Meta key prefix for all narration metadata.
	 */
	const META_PREFIX = '_prc_audio_';

	/**
	 * Attachment ID meta key.
	 */
	const META_ATTACHMENT = self::META_PREFIX . 'attachment_id';

	/**
	 * Source content hash meta key.
	 */
	const META_HASH = self::META_PREFIX . 'hash';

	/**
	 * Provider name meta key.
	 */
	const META_PROVIDER = self::META_PREFIX . 'provider';

	/**
	 * Voice identifier meta key.
	 */
	const META_VOICE = self::META_PREFIX . 'voice';

	/**
	 * Duration meta key.
	 */
	const META_DURATION = self::META_PREFIX . 'duration';

	/**
	 * Generation timestamp meta key.
	 */
	const META_GENERATED = self::META_PREFIX . 'generated';

	/**
	 * Character count meta key.
	 */
	const META_CHARACTERS = self::META_PREFIX . 'characters';

	/**
	 * Hash the content that narration was generated from.
	 *
	 * @param \WP_Post|int $post The post or post ID.
	 * @return string Empty string when the post does not exist.
	 */
	public static function content_hash( $post ): string {
		$post = get_post( $post );

		if ( ! $post ) {
			return '';
		}

		// The title is narrated too, so a title-only edit must invalidate.
		return md5( $post->post_title . '|' . $post->post_content );
	}

	/**
	 * Whether a post has narration audio.
	 *
	 * @param int $post_id The post ID.
	 * @return bool
	 */
	public function has_narration( int $post_id ): bool {
		return null !== $this->get( $post_id );
	}

	/**
	 * Get the stored narration record for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return array|null Null when there is no usable narration.
	 */
	public function get( int $post_id ): ?array {
		$attachment_id = (int) get_post_meta( $post_id, self::META_ATTACHMENT, true );

		if ( $attachment_id <= 0 ) {
			return null;
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			// The attachment was deleted from the Media Library directly.
			// Treat the post as un-narrated rather than surfacing a dead URL.
			return null;
		}

		$duration = get_post_meta( $post_id, self::META_DURATION, true );

		return array(
			'attachment_id' => $attachment_id,
			'url'           => (string) wp_get_attachment_url( $attachment_id ),
			'mime_type'     => (string) get_post_mime_type( $attachment_id ),
			'hash'          => (string) get_post_meta( $post_id, self::META_HASH, true ),
			'provider'      => (string) get_post_meta( $post_id, self::META_PROVIDER, true ),
			'voice'         => (string) get_post_meta( $post_id, self::META_VOICE, true ),
			'characters'    => (int) get_post_meta( $post_id, self::META_CHARACTERS, true ),
			'duration'      => '' === $duration ? null : (float) $duration,
			'generated'     => (string) get_post_meta( $post_id, self::META_GENERATED, true ),
			'byte_length'   => $this->byte_length( $attachment_id ),
			'is_stale'      => $this->is_stale( $post_id ),
		);
	}

	/**
	 * Whether stored narration no longer matches the post content.
	 *
	 * @param int $post_id The post ID.
	 * @return bool False when there is no narration to be stale.
	 */
	public function is_stale( int $post_id ): bool {
		$stored_hash = (string) get_post_meta( $post_id, self::META_HASH, true );

		if ( '' === $stored_hash ) {
			return false;
		}

		return $stored_hash !== self::content_hash( $post_id );
	}

	/**
	 * Byte length of the stored audio file.
	 *
	 * Read from the file itself because the podcast feed's enclosure length
	 * must match exactly or clients mis-scrub and some reject the item.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return int Zero when the file is missing.
	 */
	public function byte_length( int $attachment_id ): int {
		$path = get_attached_file( $attachment_id );

		if ( ! $path || ! file_exists( $path ) ) {
			return 0;
		}

		return (int) filesize( $path );
	}

	/**
	 * Store narration audio for a post.
	 *
	 * Replaces any existing narration, deleting the previous attachment so
	 * regeneration does not accumulate orphaned files in the Media Library.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $audio   Raw audio payload.
	 * @param array  $meta    Metadata: provider, voice, duration, characters,
	 *                        mime_type.
	 * @return int|\WP_Error The new attachment ID, or an error.
	 */
	public function store( int $post_id, string $audio, array $meta = array() ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error( 'prc_audio_narration_missing_post', 'The post no longer exists.' );
		}

		if ( '' === $audio ) {
			return new \WP_Error( 'prc_audio_narration_empty_audio', 'Refusing to store an empty audio file.' );
		}

		$meta = wp_parse_args(
			$meta,
			array(
				'provider'   => '',
				'voice'      => '',
				'duration'   => null,
				'characters' => 0,
				'mime_type'  => 'audio/mpeg',
			)
		);

		$filename = $this->filename( $post );
		$upload   = wp_upload_bits( $filename, null, $audio );

		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'prc_audio_narration_upload_failed', (string) $upload['error'] );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $meta['mime_type'],
				'post_title'     => sprintf(
					/* translators: %s: post title */
					__( 'Narration: %s', 'prc-audio-narration' ),
					$post->post_title
				),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$post_id
		);

		if ( is_wp_error( $attachment_id ) ) {
			// Do not leave the uploaded file behind when the attachment row
			// could not be created.
			wp_delete_file( $upload['file'] );
			return $attachment_id;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $upload['file'] )
		);

		// Only remove the previous attachment once the replacement exists, so
		// a failure above cannot leave the post with no audio at all.
		$this->delete_attachment( $post_id );

		update_post_meta( $post_id, self::META_ATTACHMENT, $attachment_id );
		update_post_meta( $post_id, self::META_HASH, self::content_hash( $post ) );
		update_post_meta( $post_id, self::META_PROVIDER, $meta['provider'] );
		update_post_meta( $post_id, self::META_VOICE, $meta['voice'] );
		update_post_meta( $post_id, self::META_CHARACTERS, (int) $meta['characters'] );
		update_post_meta( $post_id, self::META_GENERATED, current_time( 'mysql', true ) );

		if ( null === $meta['duration'] ) {
			delete_post_meta( $post_id, self::META_DURATION );
		} else {
			update_post_meta( $post_id, self::META_DURATION, (float) $meta['duration'] );
		}

		/**
		 * Fires after narration audio is stored for a post.
		 *
		 * @param int $post_id       The post ID.
		 * @param int $attachment_id The stored attachment ID.
		 */
		do_action( 'prc_audio_narration_stored', $post_id, $attachment_id );

		return $attachment_id;
	}

	/**
	 * Delete a post's narration and all of its metadata.
	 *
	 * @param int $post_id The post ID.
	 * @return bool Whether anything was removed.
	 */
	public function delete( int $post_id ): bool {
		$removed = $this->delete_attachment( $post_id );

		foreach ( array(
			self::META_ATTACHMENT,
			self::META_HASH,
			self::META_PROVIDER,
			self::META_VOICE,
			self::META_CHARACTERS,
			self::META_DURATION,
			self::META_GENERATED,
		) as $key ) {
			delete_post_meta( $post_id, $key );
		}

		/**
		 * Fires after narration is removed from a post.
		 *
		 * @param int $post_id The post ID.
		 */
		do_action( 'prc_audio_narration_deleted', $post_id );

		return $removed;
	}

	/**
	 * Delete the attachment currently referenced by a post.
	 *
	 * @param int $post_id The post ID.
	 * @return bool Whether an attachment was deleted.
	 */
	private function delete_attachment( int $post_id ): bool {
		$attachment_id = (int) get_post_meta( $post_id, self::META_ATTACHMENT, true );

		if ( $attachment_id <= 0 ) {
			return false;
		}

		return (bool) wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Build a filename for a post's narration.
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	private function filename( \WP_Post $post ): string {
		$slug = $post->post_name ? $post->post_name : (string) $post->ID;

		return sprintf( 'narration-%s-%d.mp3', sanitize_file_name( $slug ), $post->ID );
	}

	/**
	 * Post IDs that currently have narration, newest first.
	 *
	 * @param array $args Optional WP_Query overrides.
	 * @return int[]
	 */
	public function get_narrated_post_ids( array $args = array() ): array {
		$query = new \WP_Query(
			wp_parse_args(
				$args,
				array(
					'post_type'              => 'any',
					'post_status'            => 'publish',
					'posts_per_page'         => 100,
					'fields'                 => 'ids',
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'meta_query'             => array(
						array(
							'key'     => self::META_ATTACHMENT,
							'compare' => 'EXISTS',
						),
					),
				)
			)
		);

		return array_map( 'intval', $query->posts );
	}
}
