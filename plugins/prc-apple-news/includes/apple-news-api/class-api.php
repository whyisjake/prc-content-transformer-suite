<?php
/**
 * PRC Apple News: API class
 *
 * @package PRC\Platform\Apple_News
 * @subpackage Apple_News_API
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\Apple_News_API;

use WP_Error;

/**
 * High-level Apple News API operations: create, update, delete, get articles,
 * and get channel info.
 *
 * All methods return a decoded stdClass on success or WP_Error on failure.
 * No exceptions are thrown.
 *
 * @since 1.0.0
 */
class API {

	/**
	 * Apple News REST API base URL.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const BASE_URL = 'https://news-api.apple.com';

	/**
	 * The credentials used to authenticate requests.
	 *
	 * @var Credentials
	 * @since 1.0.0
	 */
	private Credentials $credentials;

	/**
	 * The HTTP request handler.
	 *
	 * @var Request
	 * @since 1.0.0
	 */
	private Request $request;

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials The API credentials.
	 */
	public function __construct( Credentials $credentials ) {
		$this->credentials = $credentials;
		$this->request     = new Request( $credentials );
	}

	/**
	 * Publish a new article to an Apple News channel.
	 *
	 * @param string $article_json The Apple News Format article JSON.
	 * @param string $channel_uuid The channel UUID to publish to.
	 * @param array  $meta         Optional. Additional metadata. Defaults to [].
	 * @param int    $post_id      Optional. WordPress post ID. Defaults to 0.
	 * @return \stdClass|WP_Error Decoded response object on success, WP_Error on failure.
	 */
	public function post_article_to_channel( string $article_json, string $channel_uuid, array $meta = [], int $post_id = 0 ): \stdClass|WP_Error {
		$url = self::BASE_URL . '/channels/' . $channel_uuid . '/articles';
		return $this->request->post( $url, $article_json, [], $meta, $post_id );
	}

	/**
	 * Update an existing Apple News article.
	 *
	 * Apple News uses POST for updates; the revision token must be included
	 * in the metadata to prevent conflicts.
	 *
	 * @param string $uid          The Apple News article UID.
	 * @param string $revision     The current revision token for the article.
	 * @param string $article_json The updated Apple News Format article JSON.
	 * @param array  $meta         Optional. Additional metadata. Defaults to [].
	 * @param int    $post_id      Optional. WordPress post ID. Defaults to 0.
	 * @return \stdClass|WP_Error Decoded response object on success, WP_Error on failure.
	 */
	public function update_article( string $uid, string $revision, string $article_json, array $meta = [], int $post_id = 0 ): \stdClass|WP_Error {
		$url = self::BASE_URL . '/articles/' . $uid;

		// Always include the revision in the metadata data payload.
		if ( empty( $meta['data'] ) || ! is_array( $meta['data'] ) ) {
			$meta['data'] = [];
		}
		$meta['data']['revision'] = $revision;

		return $this->request->post( $url, $article_json, [], $meta, $post_id );
	}

	/**
	 * Delete an Apple News article.
	 *
	 * @param string $uid The Apple News article UID.
	 * @return bool|WP_Error True on success (204 No Content), WP_Error on failure.
	 */
	public function delete_article( string $uid ): bool|WP_Error {
		$url = self::BASE_URL . '/articles/' . $uid;
		return $this->request->delete( $url );
	}

	/**
	 * Get metadata for an existing Apple News article.
	 *
	 * @param string $uid The Apple News article UID.
	 * @return \stdClass|WP_Error Decoded response object on success, WP_Error on failure.
	 */
	public function get_article( string $uid ): \stdClass|WP_Error {
		$url = self::BASE_URL . '/articles/' . $uid;
		return $this->request->get( $url );
	}

	/**
	 * Get metadata for an Apple News channel.
	 *
	 * Used by the Test Connection REST route to verify credentials are valid.
	 *
	 * @param string $channel_uuid The channel UUID to look up.
	 * @return \stdClass|WP_Error Decoded response object on success, WP_Error on failure.
	 */
	public function get_channel( string $channel_uuid ): \stdClass|WP_Error {
		$url = self::BASE_URL . '/channels/' . $channel_uuid;
		return $this->request->get( $url );
	}
}
