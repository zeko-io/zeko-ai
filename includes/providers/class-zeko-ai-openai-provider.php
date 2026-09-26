<?php
/**
 * OpenAI provider (Chat Completions + Moderation) over HTTPS.
 *
 * Uses the configured API key, model and base URL. All network traffic goes
 * through wp_remote_post so the test suite can stub it.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_OpenAI_Provider. */
class Zeko_AI_OpenAI_Provider extends Zeko_AI_Provider {

	/**
	 * Name.
	 */
	public function name(): string {
		return 'openai';
	}

	/**
	 * Model.
	 */
	public function model(): string {
		return ! empty( $this->settings['openai_model'] ) ? (string) $this->settings['openai_model'] : 'gpt-4o-mini';
	}

	/**
	 * Base url.
	 */
	private function base_url(): string {
		$url = ! empty( $this->settings['openai_base_url'] ) ? (string) $this->settings['openai_base_url'] : 'https://api.openai.com/v1';
		return untrailingslashit( $url );
	}

	/**
	 * Api key.
	 */
	private function api_key(): string {
		return ! empty( $this->settings['openai_key'] ) ? (string) $this->settings['openai_key'] : '';
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
	 * Moderate.
	 *
	 * @param string $text Text.
	 */
	public function moderate( string $text ): array {
		$response = $this->request(
			'/moderations',
			array(
				'model' => 'omni-moderation-latest',
				'input' => (string) $text,
			)
		);

		$result  = $response['results'][0] ?? array();
		$flagged = ! empty( $result['flagged'] );
		$cats    = isset( $result['categories'] ) && is_array( $result['categories'] ) ? $result['categories'] : array();
		$scores  = isset( $result['category_scores'] ) && is_array( $result['category_scores'] ) ? $result['category_scores'] : array();

		$reasons   = array();
		$max_score = 0.0;
		foreach ( $cats as $category => $active ) {
			if ( $active ) {
				$reasons[] = ucwords( str_replace( '_', ' ', $category ) );
			}
			$max_score = max( $max_score, (float) ( $scores[ $category ] ?? 0 ) );
		}

		return array(
			'decision' => $flagged ? 'flagged' : 'approved',
			'reasons'  => $reasons,
			'score'    => $max_score,
		);
	}

	/**
	 * Perform the HTTP request. Filterable endpoint + timeout so tests can
	 * stub transport behaviour deterministically.
	 *
	 * @param string $path Path.
	 * @param array  $body Body.
	 * @throws RuntimeException When an error occurs.
	 */
	private function request( string $path, array $body ): array {
		$endpoint = apply_filters( 'zeko_ai_openai_endpoint', $this->base_url() . $path );
		$args     = array(
			'timeout' => isset( $this->settings['timeout'] ) ? (int) $this->settings['timeout'] : 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key(),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		);

		$response = apply_filters( 'zeko_ai_openai_remote_request', null, $endpoint, $args );

		if ( null === $response ) {
			$response = wp_remote_post( $endpoint, $args );
		}

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( (string) $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 || ! is_array( $data ) ) {
			$message = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'OpenAI request failed with status ' . $status;
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
