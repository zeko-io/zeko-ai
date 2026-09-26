<?php
/**
 * Zeko Cloud provider: hosted AI completions billed against license credits.
 *
 * The site sends messages and its license key to the Zeko license server
 * (ozconsultz.com), which validates the key, deducts credits, and calls a
 * server-side LLM with Zeko's own credentials. Nothing about the request is
 * computable client-side, so the model and prompts are safe from piracy.
 *
 * Fails loudly (throws) on any transport or licensing error so the failover
 * facet can fall back to the next keyed provider and finally the offline
 * Mock — a site never breaks because cloud credits ran out.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Cloud_Provider. */
class Zeko_AI_Cloud_Provider extends Zeko_AI_Provider {

	/**
	 * Name.
	 */
	public function name(): string {
		return 'cloud';
	}

	/**
	 * Model.
	 */
	public function model(): string {
		return ! empty( $this->settings['cloud_model'] ) ? (string) $this->settings['cloud_model'] : 'zeko-cloud';
	}

	// ---------------------------------------------------------------------.
	// Capabilities.
	// ---------------------------------------------------------------------.

	/**
	 * Chat.
	 *
	 * @param array $messages Messages.
	 * @param array $opts Opts.
	 */
	public function chat( array $messages, array $opts = array() ): array {
		$payload = array(
			'messages'    => $messages,
			'temperature' => isset( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.7,
		);
		if ( isset( $opts['max_tokens'] ) ) {
			$payload['max_tokens'] = (int) $opts['max_tokens'];
		}
		return $this->run( 'complete', $payload );
	}

	/**
	 * Complete.
	 *
	 * @param string $prompt Prompt.
	 * @param array  $opts Opts.
	 */
	public function complete( string $prompt, array $opts = array() ): array {
		$payload = array(
			'prompt'      => $prompt,
			'temperature' => isset( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.7,
		);
		if ( isset( $opts['max_tokens'] ) ) {
			$payload['max_tokens'] = (int) $opts['max_tokens'];
		}
		return $this->run( 'complete', $payload );
	}

	/**
	 * Moderate.
	 *
	 * @param string $text Text.
	 */
	public function moderate( string $text ): array {
		$data = $this->run( 'moderate', array( 'text' => (string) $text ) );

		$result  = is_array( $data['result'] ?? null ) ? $data['result'] : array();
		$flagged = ! empty( $result['flagged'] );
		$cats    = isset( $result['categories'] ) && is_array( $result['categories'] ) ? $result['categories'] : array();
		$scores  = isset( $result['category_scores'] ) && is_array( $result['category_scores'] ) ? $result['category_scores'] : array();

		$reasons   = array();
		$max_score = 0.0;
		foreach ( $cats as $category => $active ) {
			if ( $active ) {
				$reasons[] = ucwords( str_replace( '_', ' ', (string) $category ) );
			}
			$max_score = max( $max_score, (float) ( $scores[ $category ] ?? 0 ) );
		}

		return array(
			'decision' => $flagged ? 'flagged' : 'approved',
			'reasons'  => $reasons,
			'score'    => $max_score,
		);
	}

	// ---------------------------------------------------------------------.
	// Internals.
	// ---------------------------------------------------------------------.

	/**
	 * Run.
	 *
	 * @param string $task Task.
	 * @param array  $payload Payload.
	 */
	private function run( string $task, array $payload ): array {
		$data = $this->request( $task, $payload );

		if ( 'moderate' === $task ) {
			return $data;
		}

		$content    = (string) ( $data['content'] ?? '' );
		$usage      = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();
		$tokens_in  = (int) ( $usage['prompt_tokens'] ?? $this->estimate_tokens( $this->flatten( $payload['messages'] ?? array() ) ) );
		$tokens_out = (int) ( $usage['completion_tokens'] ?? $this->estimate_tokens( $content ) );

		$result = array(
			'content'    => $content,
			'tokens_in'  => $tokens_in,
			'tokens_out' => $tokens_out,
			'model'      => $this->model(),
			'raw'        => $data,
		);

		if ( isset( $data['credits_remaining'] ) ) {
			$result['credits_remaining'] = (int) $data['credits_remaining'];
		}

		return $result;
	}

	/**
	 * Server root, from settings or (preferred) the Zeko License SDK so a
	 * single source of truth exists on live installs.
	 */
	private function server_url(): string {
		$url = ! empty( $this->settings['cloud_server'] ) ? (string) $this->settings['cloud_server'] : '';
		if ( '' === $url && function_exists( 'zeko_license' ) ) {
			$url = zeko_license()->server_url();
		}
		return untrailingslashit( (string) apply_filters( 'zeko_ai_cloud_server', $url ) );
	}

	/**
	 * License key.
	 */
	private function license_key(): string {
		if ( ! empty( $this->settings['cloud_key'] ) ) {
			return (string) $this->settings['cloud_key'];
		}
		if ( function_exists( 'zeko_license' ) ) {
			return zeko_license()->key();
		}
		return '';
	}

	/**
	 * Site token.
	 */
	private function site_token(): string {
		if ( function_exists( 'zeko_license' ) ) {
			return zeko_license()->site_token();
		}
		$token = (string) get_option( 'zeko_ai_cloud_site_token', '' );
		if ( '' === $token ) {
			$token = function_exists( 'wp_generate_password' ) ? wp_generate_password( 32, false ) : bin2hex( random_bytes( 16 ) );
			update_option( 'zeko_ai_cloud_site_token', $token );
		}
		return $token;
	}

	/**
	 * Perform the server call. Filterable endpoint + transport so the test
	 * suite can stub behaviour deterministically. Any error throws so the
	 * failover facet retries the next provider in the chain.
	 *
	 * @param string $action Action.
	 * @param array  $payload Payload.
	 * @throws RuntimeException When an error occurs.
	 */
	private function request( string $action, array $payload ): array {
		$server   = $this->server_url();
		$endpoint = add_query_arg( 'zeko_license_action', $action, $server ? $server . '/' : home_url( '/' ) );

		$body = array_merge(
			$payload,
			array(
				'key'        => $this->license_key(),
				'site'       => home_url(),
				'site_token' => $this->site_token(),
				'model'      => $this->model(),
			)
		);

		$args = array(
			'timeout' => isset( $this->settings['timeout'] ) ? (int) $this->settings['timeout'] : 30,
			'body'    => $body,
		);

		$response = apply_filters( 'zeko_ai_cloud_remote_request', null, $endpoint, $args );
		if ( null === $response ) {
			$response = wp_remote_post( $endpoint, $args );
		}

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( (string) $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 || ! is_array( $data ) ) {
			$message = is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : 'Zeko Cloud request failed with status ' . $status;
			throw new RuntimeException( esc_html( $message ) );
		}

		if ( empty( $data['success'] ) ) {
			throw new RuntimeException( esc_html( (string) ( $data['error'] ?? 'Zeko Cloud request was refused.' ) ) );
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
