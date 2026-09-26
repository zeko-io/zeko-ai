<?php
/**
 * Zeko Rewards + Zeko Pay integration for Zeko AI (light touch).
 *
 * Contributes assistant context only; rewards/pay have no free-text content
 * that benefits from generation, unified search or moderation review.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Integration_Misc. */
class Zeko_AI_Integration_Misc extends Zeko_AI_Integration {

	/**
	 * Context.
	 *
	 * @param array $blocks Blocks.
	 */
	public function context( array $blocks ): array {
		if ( ! is_user_logged_in() ) {
			return $blocks;
		}
		$user_id = get_current_user_id();

		if ( function_exists( 'zeko_rewards' ) && zeko_rewards() ) {
			$badges = zeko_rewards()->get_db()->get_user_badges( $user_id );
			$items  = array();
			foreach ( $badges as $badge ) {
				$items[] = array(
					'title'   => (string) ( $badge['name'] ?? $badge['title'] ?? '' ),
					'url'     => zeko_rewards_page_url( 'rewards' ),
					'snippet' => wp_trim_words( (string) ( $badge['description'] ?? $badge['criteria'] ?? '' ), 16 ),
				);
			}
			if ( $items ) {
				$blocks[] = $this->context_block( 'rewards', 'Your badges', $items );
			}
		}

		return $blocks;
	}
}
