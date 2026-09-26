<?php
/**
 * Base class for OpenAI-compatible chat providers.
 *
 * Subclasses only declare a slug (which doubles as the settings key prefix),
 * a default base URL, a default model and any extra headers. All network
 * traffic goes through wp_remote_post and every request is filterable via
 * zeko_ai_{slug}_endpoint / zeko_ai_{slug}_remote_request so the test suite
 * can stub transport behaviour deterministically.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_OpenAI_Compat_Provider. */
abstract class Zeko_AI_OpenAI_Compat_Provider extends Zeko_AI_Provider {

	/**
	 * Slug.
	 *
	 * @var mixed Slug.
	 */
	protected $slug = '';

	/**
	 * /** @return string Fallback base URL (may be overridden in settings). */

	/**
	 * /** @return string Fallback base URL (may be overridden in settings).
	 */
	abstract protected function default_base_url(): string;

	/**
	 * /** @return string Fallback model (may be overridden in settings). */

	/**
	 * /** @return string Fallback model (may be overridden in settings).
	 */
	abstract protected function default_model(): string;

	/**
	 * Name.
	 */
	public function name(): string {
		return $this->slug;
	}

	/**
	 * Model.
	 */
	public function model(): string {
		return ! empty( $this->settings[ $this->slug . '_model' ] )
			? (string) $this->settings[ $this->slug . '_model' ]
			: $this->default_model();
	}

	/**
	 * Base url.
	 */
	protected function base_url(): string {
		$url = ! empty( $this->settings[ $this->slug . '_base_url' ] )
			? (string) $this->settings[ $this->slug . '_base_url' ]
			: $this->default_base_url();
		return untrailingslashit( $url );
	}

	/**
	 * Api key.
	 */
	protected function api_key(): string {
		return ! empty( $this->settings[ $this->slug . '_key' ] ) ? (string) $this->settings[ $this->slug . '_key' ] : '';
	}

	/**
	 * Provider-specific HTTP headers (Authorization and Content-Type are
	 * always added by request()). OpenRouter sends a referer, Gemini its
	 * API-key header, etc.
	 *
	 * @return array<string,string>
	 */
	protected function extra_headers(): array {
		return array();
	}

	/**
	 * Chat.
	 *
	 * @param array $messages Messages.
	 * @param array $opts Opts.
	 */
	public function chat( array $messages, array $opts = array() ): array {
		$body = array(
			'model'       => $this->model(),
			'messages'    => $messages,
			'temperature' => isset( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.7,
		);
		if ( isset( $opts['max_tokens'] ) ) {
			$body['max_tokens'] = (int) $opts['max_tokens'];
		}

		$response = $this->request( '/chat/completions', $body );

		$content    = (string) ( $response['choices'][0]['message']['content'] ?? '' );
		$usage      = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array();
		$tokens_in  = (int) ( $usage['prompt_tokens'] ?? $this->estimate_tokens( $this->flatten( $messages ) ) );
		$tokens_out = (int) ( $usage['completion_tokens'] ?? $this->estimate_tokens( $content ) );

		return array(
			'content'    => $content,
			'tokens_in'  => $tokens_in,
			'tokens_out' => $tokens_out,
			'model'      => $this->model(),
			'raw'        => $response,
		);
	}

	/**
	 * These providers have no dedicated moderation endpoint, so the chat
	 * model classifies with a strict JSON prompt (mirrors the Anthropic
	 * provider) to keep the admin flow uniform. Failures degrade to approved.
	 *
	 * @param string $text Text.
	 */
	public function moderate( string $text ): array {
		$prompt = "You are a content moderation classifier. Classify the following text.\n\n"
			. "Return only JSON like {\"flagged\":true,\"reasons\":[\"Harassment\"],\"score\":0.85} or {\"flagged\":false,\"reasons\":[],\"score\":0.0}.\n\n"
			. 'TEXT: ' . $text;

		try {
			$result = $this->chat(
				array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				array(
					'max_tokens'  => 200,
					'temperature' => 0,
				)
			);
		} catch ( \Throwable $e ) {
			return array(
				'decision' => 'approved',
				'reasons'  => array(),
				'score'    => 0.0,
			);
		}

		$data = json_decode( trim( (string) $result['content'] ), true );
		if ( ! is_array( $data ) ) {
			return array(
				'decision' => 'approved',
				'reasons'  => array(),
				'score'    => 0.0,
			);
		}

		return array(
			'decision' => ! empty( $data['flagged'] ) ? 'flagged' : 'approved',
			'reasons'  => array_values( array_map( 'sanitize_text_field', (array) ( $data['reasons'] ?? array() ) ) ),
			'score'    => (float) ( $data['score'] ?? 0 ),
		);
	}

	/**
	 * Perform the HTTP request. Endpoint and transport are filterable so
	 * tests can stub behaviour deterministically.
	 *
	 * @param string $path Path.
	 * @param array  $body Body.
	 * @throws RuntimeException When an error occurs.
	 */
	protected function request( string $path, array $body ): array {
		$endpoint = apply_filters( 'zeko_ai_' . $this->slug . '_endpoint', $this->base_url() . $path );

		$headers = array_merge(
			array(
				'Authorization' => 'Bearer ' . $this->api_key(),
				'Content-Type'  => 'application/json',
			),
			$this->extra_headers()
		);

		$args = array(
			'timeout' => isset( $this->settings['timeout'] ) ? (int) $this->settings['timeout'] : 30,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		);

		$response = apply_filters( 'zeko_ai_' . $this->slug . '_remote_request', null, $endpoint, $args );

		if ( null === $response ) {
			$response = wp_remote_post( $endpoint, $args );
		}

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( (string) $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 || ! is_array( $data ) ) {
			$label   = ucfirst( $this->slug );
			$message = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : $label . ' request failed with status ' . $status;
			throw new RuntimeException( esc_html( $message ) );
		}

		return $data;
	}

	/**
	 * Flatten.
	 *
	 * @param array $messages Messages.
	 */
	private function flatten( array $messages ): string {
		$out = '';
		foreach ( $messages as $message ) {
			if ( is_array( $message ) ) {
				$out .= ' ' . (string) ( $message['content'] ?? '' );
			}
		}
		return $out;
	}

	/**
	 * Estimate tokens.
	 *
	 * @param string $text Text.
	 */
	private function estimate_tokens( string $text ): int {
		return max( 1, (int) ceil( mb_strlen( $text ) / 4 ) );
	}
}
