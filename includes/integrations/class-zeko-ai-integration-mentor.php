<?php
/**
 * Zeko Mentor integration for Zeko AI.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Integration_Mentor. */
class Zeko_AI_Integration_Mentor extends Zeko_AI_Integration {

	/**
	 * Hooks.
	 */
	protected function hooks(): void {
		if ( ! function_exists( 'zeko_mentor' ) ) {
			return;
		}
		add_action( 'zeko_mentor_review_posted', array( $this, 'on_review_posted' ), 10, 2 );
	}

	/**
	 * Db.
	 */
	private function db() {
		return zeko_mentor()->get_db();
	}

	/**
	 * Mentor url.
	 */
	private function mentor_url(): string {
		return home_url( '/mentors/' );
	}

	/**
	 * Session url.
	 */
	private function session_url(): string {
		return home_url( '/mentor-dashboard/' );
	}

	// ── Auto moderation ────────────────────────────────────────────────.

	/**
	 * On review posted.
	 *
	 * @param int $session_id Session id.
	 * @param int $reviewer_id Reviewer id.
	 */
	public function on_review_posted( int $session_id, int $reviewer_id ): void {
		global $wpdb;
		$reviews = $wpdb->prefix . 'zeko_mentor_reviews';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $reviews ) ) !== $reviews ) {
			return;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$reviews} WHERE session_id = %d ORDER BY id DESC LIMIT 1",
				$session_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( ! $row ) {
			return;
		}
		$text = (string) ( $row->comment ?? $row->content ?? '' );
		if ( '' === trim( $text ) ) {
			return;
		}
		$this->moderate( $reviewer_id, 'mentor', 'review', (int) $row->id, $text );
	}

	// ── Assistant context ──────────────────────────────────────────────.

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

		$sessions = $this->db()->get_user_sessions( $user_id, 'any', 'confirmed', 50 );
		$items    = array();
		foreach ( $sessions as $session ) {
			$title   = isset( $session['mentor_name'] ) ? (string) $session['mentor_name'] : sprintf( 'Session #%d', (int) $session['id'] );
			$items[] = array(
				'title'   => $title,
				'url'     => $this->session_url(),
				'snippet' => 'Status: ' . (string) ( $session['status'] ?? '' ),
			);
		}
		if ( $items ) {
			$blocks[] = $this->context_block( 'mentor', 'Your mentorship sessions', $items );
		}

		return $blocks;
	}

	// ── Content presets ────────────────────────────────────────────────.

	/**
	 * Presets.
	 *
	 * @param array $presets Presets.
	 */
	public function presets( array $presets ): array {
		$presets[] = array(
			'id'          => 'mentor_bio',
			'module'      => 'mentor',
			'label'       => __( 'Mentor bio', 'zeko-ai' ),
			'description' => __( 'Write a friendly mentor bio from your background.', 'zeko-ai' ),
			'prompt'      => "Write a warm, professional mentor bio. Introduce who the mentor is, what they have done, what they love mentoring, and the best way to learn with them.\n\nBackground:\n{background}",
			'fields'      => array(
				array(
					'key'         => 'background',
					'label'       => __( 'Your background', 'zeko-ai' ),
					'type'        => 'textarea',
					'placeholder' => __( 'Career, expertise, mentoring style…', 'zeko-ai' ),
				),
			),
			'target'      => 'textarea[name="bio"], textarea[name="profile_bio"]',
		);

		$presets[] = array(
			'id'          => 'mentor_goal',
			'module'      => 'mentor',
			'label'       => __( 'Mentorship goal', 'zeko-ai' ),
			'description' => __( 'Draft a clear learning goal for a mentorship session.', 'zeko-ai' ),
			'prompt'      => "Write a clear, measurable mentorship goal. State the outcome, the steps, and how progress will be checked.\n\nRough idea:\n{notes}",
			'fields'      => array(
				array(
					'key'   => 'notes',
					'label' => __( 'Rough idea', 'zeko-ai' ),
					'type'  => 'textarea',
				),
			),
			'target'      => 'input[name="title"], textarea[name="description"]',
		);

		return $presets;
	}

	// ── Entity resolution ──────────────────────────────────────────────.

	/**
	 * Entity resolvers.
	 *
	 * @param array $resolvers Resolvers.
	 */
	public function entity_resolvers( array $resolvers ): array {
		$resolvers[] = function ( string $query ): ?array {
			return $this->resolve_mentor( $query );
		};
		return $resolvers;
	}

	/**
	 * Resolve a query naming a specific mentor into a detail card.
	 *
	 * @param string $query Query.
	 */
	private function resolve_mentor( string $query ): ?array {
		if ( ! function_exists( 'zeko_mentor' ) ) {
			return null;
		}

		$mentors = $this->db()->get_mentors(
			array(
				'is_active' => 1,
				'limit'     => 300,
			)
		);

		$best    = null;
		$bestrow = null;
		$bestfit = 0.0;
		foreach ( $mentors as $mentor ) {
			$fit = $this->entity_match_score( $query, (string) ( $mentor['mentor_name'] ?? '' ) );
			if ( $fit > $bestfit ) {
				$bestfit = $fit;
				$bestrow = $mentor;
			}
			if ( $fit >= 1.0 ) {
				break;
			}
		}

		if ( null === $bestrow || $bestfit < 0.6 ) {
			return null;
		}

		$title     = (string) ( $bestrow['mentor_name'] ?? '' );
		$url       = $this->mentor_url();
		$expertise = is_array( $bestrow['expertise_areas'] ?? null )
			? implode( ', ', (array) $bestrow['expertise_areas'] )
			: (string) ( $bestrow['expertise_areas'] ?? '' );

		$facts = array();
		if ( '' !== trim( $expertise ) ) {
			$facts[] = array(
				'label' => __( 'Expertise', 'zeko-ai' ),
				'value' => $expertise,
			);
		}
		if ( isset( $bestrow['hourly_rate'] ) && (float) $bestrow['hourly_rate'] > 0 ) {
			$facts[] = array(
				'label' => __( 'Hourly rate', 'zeko-ai' ),
				'value' => (string) $bestrow['hourly_rate'],
			);
		}

		$sections = array();
		$bio      = trim( (string) ( $bestrow['bio'] ?? '' ) );
		if ( '' !== $bio ) {
			$sections[] = array(
				'heading' => __( 'Bio', 'zeko-ai' ),
				'lines'   => array( wp_trim_words( wp_strip_all_tags( $bio ), 40 ) ),
			);
		}

		$actions = array(
			$this->entity_action( 'mentor_view', __( 'View profile', 'zeko-ai' ), $url ),
			$this->entity_action( 'mentor_session', __( 'Book a session', 'zeko-ai' ), $this->session_url() ),
			$this->entity_action( 'mentor_browse', __( 'Browse mentors', 'zeko-ai' ), $url ),
		);

		return $this->entity_card(
			'mentor',
			__( 'Mentor', 'zeko-ai' ),
			$title,
			$url,
			$bestfit,
			array(
				'facts'     => $facts,
				'sections'  => $sections,
				'actions'   => $actions,
				'web_terms' => array( $title ),
			)
		);
	}

	// ── Search ─────────────────────────────────────────────────────────.

	/**
	 * Search sources.
	 *
	 * @param array $sources Sources.
	 */
	public function search_sources( array $sources ): array {
		$sources[] = array(
			'type'   => 'mentor',
			'label'  => __( 'Mentors', 'zeko-ai' ),
			'icon'   => 'dashicons-groups',
			'search' => function ( string $term, int $limit ): array {
				$mentors = $this->db()->get_mentors(
					array(
						'search'    => $term,
						'is_active' => 1,
						'limit'     => $limit,
					)
				);
				$out     = array();
				foreach ( $mentors as $mentor ) {
					$expertise = is_array( $mentor['expertise_areas'] ?? null ) ? implode( ', ', (array) $mentor['expertise_areas'] ) : (string) ( $mentor['expertise_areas'] ?? '' );
					$out[] = array(
						'id'      => (int) $mentor['user_id'],
						'title'   => (string) ( $mentor['mentor_name'] ?? '' ),
						'excerpt' => wp_trim_words( (string) ( $mentor['bio'] ?? $expertise ), 20 ),
						'url'     => $this->mentor_url(),
					);
				}
				return $out;
			},
		);
		return $sources;
	}

	// ── Recommendations ────────────────────────────────────────────────.

	/**
	 * Recommendation sources.
	 *
	 * @param array $sources Sources.
	 */
	public function recommendation_sources( array $sources ): array {
		$sources[] = array(
			'type'  => 'mentor',
			'label' => __( 'Mentors', 'zeko-ai' ),
			'items' => function ( int $limit ): array {
				$mentors = $this->db()->get_mentors(
					array(
						'is_active' => 1,
						'limit'     => min( 100, $limit * 5 ),
					)
				);
				$out     = array();
				foreach ( $mentors as $mentor ) {
					$expertise = is_array( $mentor['expertise_areas'] ?? null ) ? implode( ', ', (array) $mentor['expertise_areas'] ) : (string) ( $mentor['expertise_areas'] ?? '' );
					$out[] = array(
						'id'      => (int) $mentor['user_id'],
						'title'   => (string) ( $mentor['mentor_name'] ?? '' ),
						'excerpt' => wp_trim_words( (string) ( $mentor['bio'] ?? $expertise ), 30 ),
						'url'     => $this->mentor_url(),
					);
				}
				return $out;
			},
		);
		return $sources;
	}

	// ── Moderation review sources ──────────────────────────────────────.

	/**
	 * Moderation sources.
	 *
	 * @param array $sources Sources.
	 */
	public function moderation_sources( array $sources ): array {
		$sources[] = array(
			'source' => 'mentor',
			'type'   => 'mentor',
			'label'  => __( 'Mentor bios', 'zeko-ai' ),
			'fetch'  => function ( int $limit ): array {
				$mentors = $this->db()->get_mentors(
					array(
						'is_active' => 1,
						'limit'     => $limit,
					)
				);
				$out     = array();
				foreach ( $mentors as $mentor ) {
					$out[] = array(
						'content_id' => (int) $mentor['user_id'],
						'user_id'    => (int) $mentor['user_id'],
						'title'      => (string) ( $mentor['mentor_name'] ?? '' ),
						'content'    => (string) ( $mentor['bio'] ?? '' ),
					);
				}
				return $out;
			},
		);
		return $sources;
	}
}
