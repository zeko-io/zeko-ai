<?php
/**
 * Anthropic provider (Messages API) over HTTPS.
 *
 * Uses the configured API key and model. All network traffic goes through
 * wp_remote_post so the test suite can stub it.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Anthropic_Provider. */
class Zeko_AI_Anthropic_Provider extends Zeko_AI_Provider {

	/**
	 * Name.
	 */
	public function name(): string {
		return 'anthropic';
	}

	/**
	 * Model.
	 */
	public function model(): string {
		return ! empty( $this->settings['anthropic_model'] ) ? (string) $this->settings['anthropic_model'] : 'claude-3-5-haiku-latest';
	}

	/**
	 * Api key.
	 */
	private function api_key(): string {
		return ! empty( $this->settings['anthropic_key'] ) ? (string) $this->settings['anthropic_key'] : '';
	}

	/**
	 * Chat.
	 *
	 * @param array $messages Messages.
	 * @param array $opts Opts.
	 */
	public function chat( array $messages, array $opts = array() ): array {
		$body = $this->request_body( $messages, $opts, false );

		$response = $this->request( $body );

		$content = '';
		foreach ( (array) ( $response['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$content .= (string) ( $block['text'] ?? '' );
			}
		}

		$usage      = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array();
		$tokens_in  = (int) ( $usage['input_tokens'] ?? $this->estimate_tokens( $this->flatten( $messages ) ) );
		$tokens_out = (int) ( $usage['output_tokens'] ?? $this->estimate_tokens( $content ) );

		return array(
			'content'    => $content,
			'tokens_in'  => $tokens_in,
			'tokens_out' => $tokens_out,
			'model'      => $this->model(),
			'raw'        => $response,
		);
	}

	/**
	 * Normalize multi-turn messages into the Anthropic message shape shared
	 * by chat() and stream(): a single system message plus user/assistant
	 * turns.
	 *
	 * @param array $messages Messages.
	 * @param array $opts Opts.
	 * @param bool  $stream Stream flag in body.
	 */
	private function request_body( array $messages, array $opts, bool $stream ): array {
		$system = '';
		$user   = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			if ( 'system' === ( $message['role'] ?? '' ) ) {
				$system = (string) ( $message['content'] ?? '' );
				continue;
			}
			$user[] = array(
				'role'    => 'user' === ( $message['role'] ?? '' ) ? 'user' : 'assistant',
				'content' => (string) ( $message['content'] ?? '' ),
			);
		}

		$body = array(
			'model'      => $this->model(),
			'max_tokens' => isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : 1024,
			'messages'   => $user,
		);
		if ( $stream ) {
			$body['stream'] = true;
		}
		if ( '' !== $system ) {
			$body['system'] = $system;
		}
		if ( isset( $opts['temperature'] ) ) {
			$body['temperature'] = (float) $opts['temperature'];
		}

		return $body;
	}

	/**
	 * Whether this provider can stream.
	 */
	public function supports_streaming(): bool {
		return true;
	}

	/**
	 * Streamed chat completion. Reads the live Anthropic SSE stream and emits
	 * text_delta payloads as they arrive; usage is aggregated from the
	 * message_start / message_delta events.
	 *
	 * @param array    $messages Messages.
	 * @param array    $opts Opts.
	 * @param callable $on_chunk Chunk callback.
	 */
	public function stream( array $messages, array $opts, callable $on_chunk ): array {
		$endpoint = apply_filters( 'zeko_ai_anthropic_endpoint', 'https://api.anthropic.com/v1/messages' );
		$headers  = array(
			'x-api-key'         => $this->api_key(),
			'anthropic-version' => '2023-06-01',
			'Content-Type'      => 'application/json',
		);

		$content = '';
		$usage   = array();
		$buffer  = '';

		$this->stream_request(
			$endpoint,
			$headers,
			wp_json_encode( $this->request_body( $messages, $opts, true ) ),
			function ( string $bytes ) use ( &$buffer, &$content, &$usage, $on_chunk ): void {
				$this->consume_sse(
					$bytes,
					$buffer,
					function ( string $payload ) use ( &$content, &$usage, $on_chunk ): void {
						$json = json_decode( $payload, true );
						if ( ! is_array( $json ) ) {
							return;
						}
						$type = $json['type'] ?? '';
						if ( 'content_block_delta' === $type ) {
							$delta = $json['delta'] ?? array();
							if ( is_array( $delta ) && 'text_delta' === ( $delta['type'] ?? '' ) && isset( $delta['text'] ) ) {
								$text = (string) $delta['text'];
								if ( '' !== $text ) {
									$content .= $text;
									$on_chunk( $text );
								}
							}
						}

						// message_start nests usage under message.usage; message_delta
						// sends it top-level. Merge whichever the event carried.
						$usage_block = isset( $json['usage'] ) && is_array( $json['usage'] )
							? $json['usage']
							: ( isset( $json['message']['usage'] ) && is_array( $json['message']['usage'] )
								? $json['message']['usage']
								: array() );
						if ( $usage_block ) {
							$usage = array_merge( $usage, $usage_block );
						}
					}
				);
			}
		);

		return array(
			'content'    => $content,
			'tokens_in'  => (int) ( $usage['input_tokens'] ?? $this->estimate_tokens( $this->flatten( $messages ) ) ),
			'tokens_out' => (int) ( $usage['output_tokens'] ?? $this->estimate_tokens( $content ) ),
			'model'      => $this->model(),
			'raw'        => array(
				'stream' => true,
				'usage'  => $usage,
			),
		);
	}

	/**
	 * Anthropic has no public moderation endpoint; reuse the chat model with
	 * a strict classification prompt so the admin flow stays uniform.
	 *
	 * @param string $text Text.
	 */
	public function moderate( string $text ): array {
		$prompt = "You are a content moderation classifier. Classify the following text.\n\n"
			. "Return only JSON like {\"flagged\":true,\"reasons\":[\"Harassment\"],\"score\":0.85} or {\"flagged\":false,\"reasons\":[],\"score\":0.0}.\n\n"
			. 'TEXT: ' . $text;

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

		$data = json_decode( trim( $result['content'] ), true );
		if ( ! is_array( $data ) ) {
			return array(
				'decision' => 'approved',
				'reasons'  => array(),
				'score'    => 0.0,
			);
		}

		$flagged = ! empty( $data['flagged'] );

		return array(
			'decision' => $flagged ? 'flagged' : 'approved',
			'reasons'  => array_values( array_map( 'sanitize_text_field', (array) ( $data['reasons'] ?? array() ) ) ),
			'score'    => (float) ( $data['score'] ?? 0 ),
		);
	}

	/**
	 * Request.
	 *
	 * @param array $body Body.
	 * @throws RuntimeException When an error occurs.
	 */
	private function request( array $body ): array {
		$endpoint = apply_filters( 'zeko_ai_anthropic_endpoint', 'https://api.anthropic.com/v1/messages' );
		$args     = array(
			'timeout' => isset( $this->settings['timeout'] ) ? (int) $this->settings['timeout'] : 30,
			'headers' => array(
				'x-api-key'         => $this->api_key(),
				'anthropic-version' => '2023-06-01',
				'Content-Type'      => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		);

		$response = apply_filters( 'zeko_ai_anthropic_remote_request', null, $endpoint, $args );

		if ( null === $response ) {
			$response = wp_remote_post( $endpoint, $args );
		}

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( (string) $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 || ! is_array( $data ) ) {
			$message = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'Anthropic request failed with status ' . $status;
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
