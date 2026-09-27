<?php
/**
 * Provider contract for Zeko AI.
 *
 * A provider turns chat prompts / completions / moderation requests into
 * normalized responses. Implementations never auto-call the network during
 * a normal request unless actually asked to (the Mock provider is fully
 * offline; HTTP providers only fire on explicit invocation).
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Provider. */
abstract class Zeko_AI_Provider {

	/**
	 * Settings.
	 *
	 * @var mixed Settings.
	 */
	protected $settings;

	/**
	 * Construct.
	 *
	 * @param array $settings Settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Human-friendly provider name.
	 *
	 * @return string
	 */
	abstract public function name(): string;

	/**
	 * Active model identifier.
	 *
	 * @return string
	 */
	abstract public function model(): string;

	/**
	 * Whether this provider can answer moderation requests.
	 *
	 * @return bool
	 */
	public function supports_moderation(): bool {
		return true;
	}

	/**
	 * Multi-turn chat completion.
	 *
	 * @return array{content:string, tokens_in:int, tokens_out:int, model:string, raw:mixed}
	 * @param array $messages * @param array                                        $opts Extra options (temperature, max_tokens...).
	 * @param array $opts Opts.
	 */
	abstract public function chat( array $messages, array $opts = array() ): array;

	/**
	 * Whether this provider can emit the answer incrementally (SSE token
	 * deltas). HTTP providers that read the upstream stream return true;
	 * buffered providers (Mock, the offline agent) do not but still accept
	 * stream() via the fallthrough below.
	 *
	 * @return bool
	 */
	public function supports_streaming(): bool {
		return false;
	}

	/**
	 * Streamed multi-turn chat completion. Default implementation buffers
	 * through chat() and emits the full answer as a single chunk, so every
	 * provider (agent, mock, cloud...) is stream-safe. Providers that read a
	 * live upstream stream override this and emit text deltas as they arrive.
	 *
	 * When the callback is invoked the chunk is emitted to the client, so a
	 * provider must not throw after its first callback unless it wants the
	 * partial answer to stand.
	 *
	 * @return array{content:string, tokens_in:int, tokens_out:int, model:string, raw:mixed}
	 * @param array    $messages Messages.
	 * @param array    $opts Opts.
	 * @param callable $on_chunk Chunk callback ($delta).
	 */
	public function stream( array $messages, array $opts, callable $on_chunk ): array {
		$result = $this->chat( $messages, $opts );

		$content = (string) ( $result['content'] ?? '' );
		if ( '' !== $content ) {
			$on_chunk( $content );
		}

		return $result;
	}

	/**
	 * Pull a raw SSE byte stream from an upstream endpoint, emitting raw bytes
	 * through $on_raw as they arrive and returning the full accumulated body.
	 * Uses cURL with a write callback because wp_remote_post buffers the whole
	 * response: token-level streaming needs each upstream chunk flushed as it
	 * lands. Tests (and proxies) can short-circuit the network by filtering
	 * 'zeko_ai_stream_raw' and returning a literal SSE body instead.
	 *
	 * @param string   $endpoint Endpoint URL.
	 * @param array    $headers  Headers.
	 * @param string   $body     JSON body.
	 * @param callable $on_raw   Raw byte callback ($bytes).
	 * @return string
	 * @throws RuntimeException When an error occurs.
	 */
	protected function stream_request( string $endpoint, array $headers, string $body, callable $on_raw ): string {
		$raw = apply_filters( 'zeko_ai_stream_raw', null, $endpoint, $headers, $body );
		if ( is_string( $raw ) ) {
			$on_raw( $raw );
			return $raw;
		}

		if ( ! function_exists( 'curl_init' ) ) {
			throw new RuntimeException( esc_html__( 'Streaming requires the PHP cURL extension.', 'zeko-ai' ) );
		}

		$curl_headers = array();
		foreach ( $headers as $key => $value ) {
			$curl_headers[] = $key . ': ' . $value;
		}

		$received = '';
		$ch       = curl_init( $endpoint ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		curl_setopt_array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_HTTPHEADER     => $curl_headers,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_WRITEFUNCTION  => static function ( $curl, $bytes ) use ( &$received, $on_raw ): int {
					$received .= $bytes;
					$on_raw( $bytes );
					return strlen( $bytes );
				},
				CURLOPT_TIMEOUT        => isset( $this->settings['timeout'] ) ? max( 1, (int) $this->settings['timeout'] * 4 ) : 120,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_SSL_VERIFYPEER => true,
			)
		);

		$ok         = curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$status     = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		$curl_error = curl_error( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
		curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close

		if ( false === $ok ) {
			throw new RuntimeException( esc_html( $curl_error ? $curl_error : __( 'The upstream stream failed.', 'zeko-ai' ) ) );
		}
		if ( $status >= 400 ) {
			throw new RuntimeException( esc_html( 'Streaming request failed with status ' . $status ) );
		}

		return $received;
	}

	/**
	 * Append raw SSE bytes to a per-request line buffer and forward complete
	 * 'data:' payloads to $on_event as they arrive. SSE frames are separated
	 * by a blank line; each frame may span several TCP chunks, so the buffer
	 * lives across calls until the whole frame has landed.
	 *
	 * @param string   $bytes    Incoming bytes.
	 * @param string   $buffer   Buffer (by reference).
	 * @param callable $on_event Payload callback ($payload string).
	 */
	protected function consume_sse( string $bytes, string &$buffer, callable $on_event ): void {
		$buffer .= $bytes;

		$separator = strpos( $buffer, "\n\n" );
		while ( false !== $separator ) {
			$block  = substr( $buffer, 0, $separator );
			$buffer = substr( $buffer, $separator + 2 );

			foreach ( preg_split( '/\r?\n/', $block ) as $line ) {
				if ( 0 === strpos( $line, 'data:' ) ) {
					$payload = trim( substr( $line, 5 ) );
					if ( '' !== $payload ) {
						$on_event( $payload );
					}
				}
			}

			$separator = strpos( $buffer, "\n\n" );
		}
	}

	/**
	 * Single-turn text generation.
	 *
	 * @return array{content:string, tokens_in:int, tokens_out:int, model:string, raw:mixed}
	 * @param string $prompt * @param array  $opts Extra options.
	 * @param array  $opts Opts.
	 */
	public function complete( string $prompt, array $opts = array() ): array {
		return $this->chat(
			array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			$opts
		);
	}

	/**
	 * Content moderation check.
	 *
	 * @return array{decision:string, reasons:string[], score:float}
	 * @param string $text * @return array{decision:string, reasons:string[], score:float}.
	 */
	abstract public function moderate( string $text ): array;
}
