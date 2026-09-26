<?php
/**
 * Zeko Love (dating) integration for Zeko AI.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Integration_Love. */
class Zeko_AI_Integration_Love extends Zeko_AI_Integration {

	/**
	 * Hooks.
	 */
	protected function hooks(): void {
		if ( ! function_exists( 'zeko_love' ) ) {
			return;
		}
		add_action( 'zeko_love_profile_saved', array( $this, 'on_profile_saved' ), 10, 1 );
	}

	/**
	 * Db.
	 */
	private function db() {
		return zeko_love()->get_db();
	}

	/**
	 * Browse url.
	 */
	private function browse_url(): string {
		return zeko_love_page_url( 'dating-browse' );
	}

	/**
	 * Profile url.
	 */
	private function profile_url(): string {
		return zeko_love_page_url( 'dating-profile' );
	}

	// ── Auto moderation ────────────────────────────────────────────────.

	/**
	 * On profile saved.
	 *
	 * @param int $user_id User id.
	 */
	public function on_profile_saved( int $user_id ): void {
		$profile = $this->db()->get_profile( $user_id );
		if ( ! $profile ) {
			return;
		}
		$text = (string) ( $profile['display_name'] ?? '' ) . "\n\n" . (string) ( $profile['bio'] ?? '' );
		$this->moderate( $user_id, 'love', 'profile', $user_id, $text );
	}

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

		$profile = $this->db()->get_profile( $user_id );
		if ( $profile ) {
			$blocks[] = $this->context_block(
				'love',
				'Your dating profile',
				array(
					array(
						'title'   => (string) ( $profile['display_name'] ?? '' ),
						'url'     => $this->profile_url(),
						'snippet' => wp_trim_words( (string) ( $profile['bio'] ?? '' ), 20 ),
					),
				)
			);
		}

		return $blocks;
	}

	/**
	 * Presets.
	 *
	 * @param array $presets Presets.
	 */
	public function presets( array $presets ): array {
		$presets[] = array(
			'id'          => 'dating_bio',
			'module'      => 'love',
			'label'       => __( 'Dating profile bio', 'zeko-ai' ),
			'description' => __( 'Write an authentic, friendly dating bio.', 'zeko-ai' ),
			'prompt'      => "Write an authentic, friendly dating profile bio. Share a bit about who they are, what they enjoy, and what kind of connection they are looking for. Keep it warm and specific, not generic.\n\nAbout them:\n{notes}",
			'fields'      => array(
				array(
					'key'         => 'notes',
					'label'       => __( 'About them', 'zeko-ai' ),
					'type'        => 'textarea',
					'placeholder' => __( 'Interests, lifestyle, what they are looking for…', 'zeko-ai' ),
				),
			),
			'target'      => 'textarea[name="bio"], textarea[name="profile_bio"]',
		);

		$presets[] = array(
			'id'          => 'date_proposal',
			'module'      => 'love',
			'label'       => __( 'Date proposal message', 'zeko-ai' ),
			'description' => __( 'Draft a friendly message to propose a date.', 'zeko-ai' ),
			'prompt'      => "Write a friendly, low-pressure message proposing a date. Reference a shared interest, suggest a concrete activity and timeframe, and invite a response.\n\nShared interest: {interest}\nIdea for the date: {idea}",
			'fields'      => array(
				array(
					'key'   => 'interest',
					'label' => __( 'Shared interest', 'zeko-ai' ),
					'type'  => 'text',
				),
				array(
					'key'   => 'idea',
					'label' => __( 'Date idea', 'zeko-ai' ),
					'type'  => 'text',
				),
			),
			'target'      => 'textarea[name="message"]',
		);

		return $presets;
	}

	/**
	 * Search sources.
	 *
	 * @param array $sources Sources.
	 */
	public function search_sources( array $sources ): array {
		$sources[] = array(
			'type'   => 'profile',
			'label'  => __( 'Dating profiles', 'zeko-ai' ),
			'icon'   => 'dashicons-heart',
			'search' => function ( string $term, int $limit ): array {
				$profiles = $this->db()->search_profiles( array( 'search' => $term ), 1, $limit );
				$out      = array();
				foreach ( $profiles as $profile ) {
					$out[] = array(
						'id'      => (int) $profile['user_id'],
						'title'   => (string) ( $profile['user_display_name'] ?? $profile['display_name'] ?? '' ),
						'excerpt' => wp_trim_words( (string) ( $profile['bio'] ?? '' ), 20 ),
						'url'     => $this->browse_url(),
					);
				}
				return $out;
			},
		);
		return $sources;
	}

	/**
	 * Recommendation sources.
	 *
	 * @param array $sources Sources.
	 */
	public function recommendation_sources( array $sources ): array {
		$sources[] = array(
			'type'  => 'profile',
			'label' => __( 'Dating profiles', 'zeko-ai' ),
			'items' => function ( int $limit ): array {
				$profiles = $this->db()->search_profiles( array(), 1, $limit );
				$out      = array();
				foreach ( $profiles as $profile ) {
					$out[] = array(
						'id'      => (int) $profile['user_id'],
						'title'   => (string) ( $profile['user_display_name'] ?? $profile['display_name'] ?? '' ),
						'excerpt' => wp_trim_words( (string) ( $profile['bio'] ?? '' ), 30 ),
						'url'     => $this->browse_url(),
					);
				}
				return $out;
			},
		);
		return $sources;
	}

	/**
	 * Moderation sources.
	 *
	 * @param array $sources Sources.
	 */
	public function moderation_sources( array $sources ): array {
		$sources[] = array(
			'source' => 'love',
			'type'   => 'profile',
			'label'  => __( 'Dating profiles', 'zeko-ai' ),
			'fetch'  => function ( int $limit ): array {
				global $wpdb;
				$table = $this->db()->get_table_profiles();
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT user_id, display_name, bio FROM {$table} WHERE is_active = 1 ORDER BY updated_at DESC LIMIT %d",
						$limit
					),
					ARRAY_A
				) ?: array();
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
				$out = array();
				foreach ( $rows as $row ) {
					$out[] = array(
						'content_id' => (int) $row['user_id'],
						'user_id'    => (int) $row['user_id'],
						'title'      => (string) $row['display_name'],
						'content'    => (string) ( $row['bio'] ?? '' ),
					);
				}
				return $out;
			},
		);
		return $sources;
	}
}
