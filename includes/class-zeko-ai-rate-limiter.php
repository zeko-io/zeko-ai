<?php
/**
 * Zeko AI request rate limiter.
 *
 * Sliding-window limiter backed by WordPress transients. Keys are built from
 * the current user id and a client IP so both authenticated and anonymous
 * callers (unified search) are throttled per client. Provider-calling
 * endpoints (chat, content generation, moderation, recommendations, search)
 * are gated through this so a single client cannot burn unbounded provider
 * quota. Per-feature limits can be tuned with the `zeko_ai_rate_limits`
 * filter (map of feature => requests per minute).
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Rate_Limiter. */
class Zeko_AI_Rate_Limiter {

	/**
	 * WINDOW.
	 *
	 * @var mixed
	 */
	const WINDOW = 60;

	/**
	 * Best-effort client IP, falling back to a stable placeholder so
	 * CLI/proxied requests still get a deterministic key.
	 */
	private function client_ip(): string {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '' !== $ip ? $ip : 'unknown';
	}

	/**
	 * Key.
	 *
	 * @param string $feature Feature.
	 * @param int    $user_id User id.
	 */
	private function key( string $feature, int $user_id ): string {
		return 'zeko_ai_rl_' . $feature . '_' . $user_id . '_' . md5( $this->client_ip() );
	}

	/**
	 * Check whether a request for the given feature may proceed.
	 * must wait before retrying.
	 *
	 * @return int 0 when allowed, otherwise the number of seconds the caller
	 * @param string $feature Feature bucket: chat|generate|moderate|search|recommend.
	 * @param int    $user_id Current user id (0 for anonymous callers).
	 */
	public function limit( string $feature, int $user_id = 0 ): int {
		$limits = apply_filters(
			'zeko_ai_rate_limits',
			array(
				'chat'      => 20,
				'generate'  => 15,
				'moderate'  => 30,
				'search'    => 60,
				'recommend' => 5,
			)
		);
		$max    = isset( $limits[ $feature ] ) ? max( 1, (int) $limits[ $feature ] ) : 30;

		$key   = $this->key( $feature, $user_id );
		$state = get_transient( $key );
		if ( ! is_array( $state ) ) {
			$state = array(
				'start' => time(),
				'count' => 0,
			);
		}

		$elapsed = time() - (int) $state['start'];
		if ( $elapsed >= self::WINDOW ) {
			$state = array(
				'start' => time(),
				'count' => 0,
			);
		}

		++$state['count'];
		set_transient( $key, $state, self::WINDOW );

		if ( $state['count'] > $max ) {
			return max( 1, self::WINDOW - min( $elapsed, self::WINDOW ) );
		}

		return 0;
	}
}
