<?php
/**
 * PRC Apple News: Credentials class
 *
 * @package PRC\Platform\Apple_News
 * @subpackage Apple_News_API
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\Apple_News_API;

/**
 * Holds the API credentials required to authenticate with Apple News API.
 *
 * @since 1.0.0
 */
class Credentials {

	/**
	 * The API key provided by Apple News.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $key;

	/**
	 * The API secret provided by Apple News.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $secret;

	/**
	 * The channel UUID for the Apple News channel.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $channel_uuid;

	/**
	 * Constructor.
	 *
	 * @param string $key          The API key.
	 * @param string $secret       The API secret (base64-encoded).
	 * @param string $channel_uuid The Apple News channel UUID.
	 */
	public function __construct( string $key, string $secret, string $channel_uuid ) {
		$this->key          = $key;
		$this->secret       = $secret;
		$this->channel_uuid = $channel_uuid;
	}

	/**
	 * Get the API key.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Get the API secret.
	 *
	 * @return string
	 */
	public function secret(): string {
		return $this->secret;
	}

	/**
	 * Get the channel UUID.
	 *
	 * @return string
	 */
	public function channel_uuid(): string {
		return $this->channel_uuid;
	}
}
