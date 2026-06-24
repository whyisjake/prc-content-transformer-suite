<?php
/**
 * Transformation Pipeline.
 *
 * @package PRC\Platform\Content_Transformer\Pipeline
 */

namespace PRC\Platform\Content_Transformer\Pipeline;

use PRC\Platform\Content_Transformer\Providers\Provider;
use PRC\Platform\Content_Transformer\Providers\Provider_Registry;
use PRC\Platform\Content_Transformer\Cache\Transformation_Cache;
use PRC\Platform\Markdown_For_Agents\Markdown_Converter;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use function WordPress\AI\get_ai_service;

/**
 * Orchestrates the full content transformation flow:
 * post -> markdown -> prompt -> AI -> validate -> cache -> result.
 */
class Transformation_Pipeline {

	/**
	 * Maximum number of retry attempts after validation failure.
	 */
	const MAX_RETRIES = 1;

	/**
	 * Transform a post's content into the target provider format.
	 *
	 * @param int    $post_id       The post ID.
	 * @param string $provider_slug The provider slug.
	 * @param bool   $force         Whether to bypass the cache.
	 * @return Transformation_Result
	 */
	public static function transform( int $post_id, string $provider_slug, bool $force = false ): Transformation_Result {
		$provider = Provider_Registry::get( $provider_slug );
		if ( ! $provider ) {
			return new Transformation_Result(
				'failed',
				'',
				$provider_slug,
				0,
				0,
				sprintf( 'Provider "%s" is not registered.', $provider_slug )
			);
		}

		if ( ! $provider->is_available() ) {
			return new Transformation_Result(
				'failed',
				'',
				$provider_slug,
				0,
				0,
				sprintf( 'Provider "%s" is not available.', $provider_slug )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new Transformation_Result( 'failed', '', $provider_slug, 0, 0, 'Post not found.' );
		}

		// Check cache unless forced.
		if ( ! $force ) {
			$cached = Transformation_Cache::get( $post_id, $provider_slug );
			if ( null !== $cached ) {
				return $cached;
			}
		}

		// Verify prc-markdown-for-agents is available.
		if ( ! class_exists( Markdown_Converter::class ) ) {
			return new Transformation_Result(
				'failed',
				'',
				$provider_slug,
				0,
				0,
				'The prc-markdown-for-agents plugin is required but not active.'
			);
		}

		// Verify the AI client is available.
		if ( ! class_exists( AiClient::class ) ) {
			return new Transformation_Result(
				'failed',
				'',
				$provider_slug,
				0,
				0,
				'The WordPress AI plugin is required but not active.'
			);
		}

		// In some local environments, connector bootstrapping may be unavailable.
		// Ensure provider API keys from connector options are bound to the registry.
		self::ensure_provider_authentication();

		// Step 1: Convert post to markdown.
		// Signal the current provider slug to block-markdown callbacks so they
		// can branch their output (e.g. charts emit a PNG image in email context
		// rather than a data table).
		do_action( 'prc_markdown_for_agents_set_context', $provider->get_slug() );

		$converter = new Markdown_Converter();
		$markdown  = $converter->post_to_markdown( $post );

		do_action( 'prc_markdown_for_agents_clear_context' );

		if ( empty( $markdown ) ) {
			return new Transformation_Result(
				'failed',
				'',
				$provider_slug,
				0,
				0,
				'Post content could not be converted to markdown. Ensure the post type supports prc-markdown-for-agents.'
			);
		}

		$tokens_used = Markdown_Converter::estimate_tokens( $markdown );

		// Step 2: Build prompts.
		$system_instruction = Prompt_Builder::build_system_instruction( $provider );
		$user_prompt        = Prompt_Builder::build_user_prompt( $markdown, $provider );

		// Step 3: Call AI.
		$result = self::call_ai( $system_instruction, $user_prompt, $provider, $tokens_used );

		if ( $result->is_success() ) {
			Transformation_Cache::set( $post_id, $provider_slug, $post->post_content, $result );
		}

		return $result;
	}

	/**
	 * Transform a markdown fragment into provider output (used for per-block ANF fallback).
	 *
	 * Unlike transform(), this does not read post content or use the transformation cache.
	 *
	 * @param int    $post_id       The post ID (for logging/context only).
	 * @param string $markdown      Markdown fragment to transform.
	 * @param string $provider_slug The provider slug.
	 * @return Transformation_Result
	 */
	public static function transform_fragment( int $post_id, string $markdown, string $provider_slug = 'apple-news' ): Transformation_Result {
		$provider = Provider_Registry::get( $provider_slug );
		if ( ! $provider ) {
			return new Transformation_Result(
				'failed',
				'',
				$provider_slug,
				0,
				0,
				sprintf( 'Provider "%s" is not registered.', $provider_slug )
			);
		}

		if ( ! $provider->is_available() ) {
			return new Transformation_Result(
				'failed',
				'',
				$provider_slug,
				0,
				0,
				sprintf( 'Provider "%s" is not available.', $provider_slug )
			);
		}

		$markdown = trim( $markdown );
		if ( '' === $markdown ) {
			return new Transformation_Result(
				'failed',
				'',
				$provider_slug,
				0,
				0,
				'Markdown fragment is empty.'
			);
		}

		if ( ! class_exists( AiClient::class ) ) {
			return new Transformation_Result(
				'failed',
				'',
				$provider_slug,
				0,
				0,
				'The WordPress AI plugin is required but not active.'
			);
		}

		self::ensure_provider_authentication();

		$tokens_used        = Markdown_Converter::estimate_tokens( $markdown );
		$system_instruction = Prompt_Builder::build_system_instruction( $provider );
		$user_prompt        = Prompt_Builder::build_fragment_prompt( $markdown, $provider );

		$result = self::call_ai_fragment( $system_instruction, $user_prompt, $provider, $tokens_used );

		if ( $result->is_success() ) {
			$components = self::parse_fragment_components( $result->get_output() );
			if ( null === $components ) {
				return new Transformation_Result(
					'failed',
					$result->get_output(),
					$provider_slug,
					0,
					$tokens_used,
					'Fragment output is not a valid JSON array of ANF components.'
				);
			}

			return new Transformation_Result(
				'success',
				wp_json_encode( $components ) ?: '[]',
				$provider_slug,
				0,
				$tokens_used
			);
		}

		return $result;
	}

	/**
	 * Call the AI model for a fragment transform with validation/retry logic.
	 *
	 * @param string   $system_instruction The system instruction.
	 * @param string   $user_prompt        The user prompt.
	 * @param Provider $provider           The target provider.
	 * @param int      $tokens_estimated   Estimated input tokens.
	 * @param int      $attempt            Current attempt number.
	 * @return Transformation_Result
	 */
	private static function call_ai_fragment(
		string $system_instruction,
		string $user_prompt,
		Provider $provider,
		int $tokens_estimated,
		int $attempt = 0
	): Transformation_Result {
		$output = self::generate_ai_output( $system_instruction, $user_prompt, $provider, $tokens_estimated );
		if ( is_wp_error( $output ) ) {
			return new Transformation_Result(
				'failed',
				'',
				$provider->get_slug(),
				0,
				$tokens_estimated,
				$output->get_error_message()
			);
		}

		$components = self::parse_fragment_components( $output );
		if ( null !== $components ) {
			return new Transformation_Result(
				'success',
				$output,
				$provider->get_slug(),
				0,
				$tokens_estimated
			);
		}

		if ( $attempt < self::MAX_RETRIES ) {
			$retry_prompt = Prompt_Builder::build_retry_prompt(
				$user_prompt,
				$output,
				'Output must be a JSON array of ANF component objects, each with a role field.'
			);
			return self::call_ai_fragment( $system_instruction, $retry_prompt, $provider, $tokens_estimated, $attempt + 1 );
		}

		return new Transformation_Result(
			'failed',
			$output,
			$provider->get_slug(),
			0,
			$tokens_estimated,
			'Fragment output failed validation after ' . ( $attempt + 1 ) . ' attempt(s).'
		);
	}

	/**
	 * Parse and validate a fragment AI response as an array of ANF components.
	 *
	 * @param string $output Raw provider output.
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function parse_fragment_components( string $output ): ?array {
		$output = trim( $output );

		if ( preg_match( '/^```(?:json)?\s*\n?(.*?)\n?```$/s', $output, $matches ) ) {
			$output = trim( $matches[1] );
		}

		$decoded = json_decode( $output, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return null;
		}

		// Accept either a bare array of components or a single component object.
		if ( array_is_list( $decoded ) ) {
			$components = $decoded;
		} elseif ( isset( $decoded['role'] ) ) {
			$components = array( $decoded );
		} else {
			return null;
		}

		foreach ( $components as $component ) {
			if ( ! is_array( $component ) || ! isset( $component['role'] ) || ! is_string( $component['role'] ) ) {
				return null;
			}
		}

		return $components;
	}

	/**
	 * Call the AI model and handle validation/retry logic.
	 *
	 * @param string   $system_instruction The system instruction.
	 * @param string   $user_prompt        The user prompt.
	 * @param Provider $provider           The target provider.
	 * @param int      $tokens_estimated   Estimated input tokens.
	 * @param int      $attempt            Current attempt number.
	 * @return Transformation_Result
	 */
	private static function call_ai(
		string $system_instruction,
		string $user_prompt,
		Provider $provider,
		int $tokens_estimated,
		int $attempt = 0
	): Transformation_Result {
		$output = self::generate_ai_output( $system_instruction, $user_prompt, $provider, $tokens_estimated );
		if ( is_wp_error( $output ) ) {
			return new Transformation_Result(
				'failed',
				'',
				$provider->get_slug(),
				0,
				$tokens_estimated,
				$output->get_error_message()
			);
		}

		// Step 5: Validate.
		// Use validate_with_reason() when available for a specific error description
		// in the retry prompt; fall back to validate() for providers that don't implement it.
		$validation_result = method_exists( $provider, 'validate_with_reason' )
			? $provider->validate_with_reason( $output )
			: ( $provider->validate( $output ) ? true : 'Output failed format validation for provider: ' . $provider->get_name() );

		if ( true !== $validation_result ) {
			if ( $attempt < self::MAX_RETRIES ) {
				$retry_prompt = Prompt_Builder::build_retry_prompt(
					$user_prompt,
					$output,
					is_string( $validation_result ) ? $validation_result : 'Output failed format validation for provider: ' . $provider->get_name()
				);
				return self::call_ai( $system_instruction, $retry_prompt, $provider, $tokens_estimated, $attempt + 1 );
			}

			$reason = is_string( $validation_result ) ? ' Reason: ' . $validation_result : '';
			return new Transformation_Result(
				'failed',
				$output,
				$provider->get_slug(),
				0,
				$tokens_estimated,
				'Output failed validation after ' . ( $attempt + 1 ) . ' attempt(s).' . $reason
			);
		}

		return new Transformation_Result(
			'success',
			$output,
			$provider->get_slug(),
			0,
			$tokens_estimated
		);
	}

	/**
	 * Generate provider output from the AI model without document validation.
	 *
	 * @param string   $system_instruction The system instruction.
	 * @param string   $user_prompt        The user prompt.
	 * @param Provider $provider           The target provider.
	 * @param int      $tokens_estimated   Estimated input tokens (for error results).
	 * @return string|\WP_Error Post-processed output or error.
	 */
	private static function generate_ai_output(
		string $system_instruction,
		string $user_prompt,
		Provider $provider,
		int $tokens_estimated
	): string|\WP_Error {
		$timeout_filter = static function ( $timeout, $url ) {
			if ( is_string( $url ) && false !== strpos( $url, 'api.anthropic.com' ) ) {
				return 45;
			}
			return $timeout;
		};

		add_filter( 'http_request_timeout', $timeout_filter, 10, 2 );

		try {
			if ( function_exists( '\WordPress\AI\get_ai_service' ) ) {
				$response = get_ai_service()
					->create_textgen_prompt(
						$user_prompt,
						array(
							'system_instruction' => $system_instruction,
							'temperature'        => 0.0,
							'max_tokens'         => 8192,
						)
					)
					->generate_text();
			} else {
				$response = AiClient::prompt( $user_prompt )
					->usingSystemInstruction( $system_instruction )
					->usingTemperature( 0.0 )
					->usingMaxTokens( 8192 )
					->generateText();
			}

			if ( is_wp_error( $response ) ) {
				throw new \RuntimeException( $response->get_error_message() );
			}
		} catch ( \Throwable $e ) {
			remove_filter( 'http_request_timeout', $timeout_filter, 10 );

			$fallback_result = self::fallback_generate_via_anthropic( $system_instruction, $user_prompt, $e->getMessage() );
			if ( isset( $fallback_result['text'] ) && is_string( $fallback_result['text'] ) && '' !== trim( $fallback_result['text'] ) ) {
				$response = $fallback_result['text'];
			} else {
				$fallback_error = '';
				if ( isset( $fallback_result['error'] ) && is_string( $fallback_result['error'] ) && '' !== trim( $fallback_result['error'] ) ) {
					$fallback_error = ' (anthropic fallback: ' . $fallback_result['error'] . ')';
				}
				return new \WP_Error(
					'ai_generation_failed',
					'AI generation failed: ' . $e->getMessage() . $fallback_error
				);
			}
		}

		remove_filter( 'http_request_timeout', $timeout_filter, 10 );

		return $provider->post_process( is_string( $response ) ? $response : (string) $response );
	}

	/**
	 * Ensure provider API keys are bound to the AI client registry.
	 *
	 * Fallback for environments where the Connectors API is unavailable and
	 * credentials are not auto-wired into the AI provider registry.
	 */
	private static function ensure_provider_authentication(): void {
		if ( ! class_exists( ApiKeyRequestAuthentication::class ) ) {
			return;
		}

		$registry = AiClient::defaultRegistry();

		$provider_option_map = array(
			'anthropic' => array( 'ais_anthropic_api_key', 'connectors_ai_anthropic_api_key' ),
			'google'    => array( 'ais_google_api_key', 'connectors_ai_google_api_key' ),
			'openai'    => array( 'connectors_ai_openai_api_key' ),
		);

		foreach ( $provider_option_map as $provider_id => $option_names ) {
			if ( ! $registry->hasProvider( $provider_id ) ) {
				continue;
			}

			// Skip if this provider is already configured.
			if ( $registry->isProviderConfigured( $provider_id ) ) {
				continue;
			}

			$api_key = '';

			$provider_constant_map = array(
				'anthropic' => 'ANTHROPIC_API_KEY',
				'google'    => 'GOOGLE_API_KEY',
				'openai'    => 'OPENAI_API_KEY',
			);

			$constant_name = $provider_constant_map[ $provider_id ] ?? null;
			if ( is_string( $constant_name ) && defined( $constant_name ) ) {
				$constant_value = constant( $constant_name );
				if ( is_string( $constant_value ) && '' !== trim( $constant_value ) ) {
					$api_key = trim( $constant_value );
				}
			}

			if ( '' !== $api_key ) {
				$registry->setProviderRequestAuthentication( $provider_id, self::make_request_auth( $provider_id, $api_key ) );
				continue;
			}

			foreach ( $option_names as $option_name ) {
				$candidate = get_option( $option_name, '' );
				if ( ! is_string( $candidate ) ) {
					continue;
				}

				$candidate = trim( $candidate );
				// Connector options may be encrypted (`enc::...`) when connector
				// decryption APIs are unavailable in local bootstrap.
				if ( '' === $candidate || str_starts_with( $candidate, 'enc::' ) ) {
					continue;
				}

				$api_key = $candidate;
				break;
			}

			if ( '' === $api_key ) {
				continue;
			}

			$registry->setProviderRequestAuthentication(
				$provider_id,
				self::make_request_auth( $provider_id, $api_key )
			);
		}
	}

	/**
	 * Build request-auth object for a provider.
	 *
	 * Anthropic provider requires a specific auth class to add required
	 * version headers. Fall back to generic API-key auth otherwise.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $api_key API key.
	 * @return ApiKeyRequestAuthentication
	 */
	private static function make_request_auth( string $provider_id, string $api_key ): ApiKeyRequestAuthentication {
		if (
			'anthropic' === $provider_id
			&& class_exists( '\WordPress\AnthropicAiProvider\Authentication\AnthropicApiKeyRequestAuthentication' )
		) {
			return new \WordPress\AnthropicAiProvider\Authentication\AnthropicApiKeyRequestAuthentication( $api_key );
		}

		return new ApiKeyRequestAuthentication( $api_key );
	}

	/**
	 * Fallback text generation through Anthropic direct API request.
	 *
	 * This runs only when model discovery failed in the AI plugin runtime, which
	 * can happen in local environments with incomplete connector bootstrapping.
	 *
	 * @param string $system_instruction System instruction.
	 * @param string $user_prompt User prompt.
	 * @param string $error_message Original AI error message.
	 * @return array{text:string,error:string}
	 */
	private static function fallback_generate_via_anthropic( string $system_instruction, string $user_prompt, string $error_message ): array {
		if ( ! defined( 'ANTHROPIC_API_KEY' ) || ! is_string( ANTHROPIC_API_KEY ) || '' === trim( ANTHROPIC_API_KEY ) ) {
			return array(
				'text'  => '',
				'error' => 'ANTHROPIC_API_KEY missing',
			);
		}

		$models = array(
			'claude-3-5-haiku-latest',
			'claude-sonnet-4-6',
		);
		$models = apply_filters( 'prc_content_transformer_anthropic_fallback_models', $models, $error_message );
		if ( ! is_array( $models ) || empty( $models ) ) {
			$models = array( 'claude-3-5-haiku-latest' );
		}

		$last_error = 'unknown fallback error';
		foreach ( $models as $model ) {
			if ( ! is_string( $model ) || '' === trim( $model ) ) {
				continue;
			}

			$request_body = array(
				'model'       => trim( $model ),
				'max_tokens'  => 8192,
				'temperature' => 0.0,
				'system'      => $system_instruction,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => $user_prompt,
					),
				),
			);

			$response = wp_remote_post(
				'https://api.anthropic.com/v1/messages',
				array(
					'timeout' => 120,
					'headers' => array(
						'Content-Type'      => 'application/json',
						'x-api-key'         => ANTHROPIC_API_KEY,
						'anthropic-version' => '2023-06-01',
					),
					'body'    => wp_json_encode( $request_body ),
				),
			);

			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				$last_error = 'HTTP ' . $code;
				continue;
			}

			$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $payload ) || empty( $payload['content'] ) || ! is_array( $payload['content'] ) ) {
				$last_error = 'Invalid response body';
				continue;
			}

			$parts = array();
			foreach ( $payload['content'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( 'text' !== ( $item['type'] ?? '' ) ) {
					continue;
				}
				$text = $item['text'] ?? '';
				if ( is_string( $text ) && '' !== trim( $text ) ) {
					$parts[] = $text;
				}
			}

			if ( empty( $parts ) ) {
				$last_error = 'No text parts returned';
				continue;
			}

			return array(
				'text'  => trim( implode( "\n\n", $parts ) ),
				'error' => '',
			);
		}

		return array(
			'text'  => '',
			'error' => $last_error,
		);
	}
}
