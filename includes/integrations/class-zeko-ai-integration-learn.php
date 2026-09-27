<?php
/**
 * Zeko Learn integration for Zeko AI.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Integration_Learn. */
class Zeko_AI_Integration_Learn extends Zeko_AI_Integration {

	/**
	 * Hooks.
	 */
	protected function hooks(): void {
		add_action( 'zeko_learn_course_created', array( $this, 'on_course_created' ), 10, 3 );
	}

	/**
	 * Moderate a freshly-created course.
	 *
	 * @param int   $course_id     Course id.
	 * @param int   $instructor_id Instructor id.
	 * @param array $data          Insert data.
	 */
	public function on_course_created( int $course_id, int $instructor_id, array $data ): void {
		$text = trim( (string) ( $data['title'] ?? '' ) . ' ' . wp_strip_all_tags( (string) ( $data['description'] ?? '' ) ) );
		if ( '' === $text ) {
			return;
		}
		$this->moderate( $instructor_id, 'learn', 'course', $course_id, $text );
	}

	/**
	 * Whether the Learn module is active.
	 */
	private function module_active(): bool {
		return function_exists( 'zeko_learn' );
	}

	/**
	 * Db (null-safe for inactive module).
	 */
	private function db() {
		if ( ! $this->module_active() ) {
			return null;
		}
		return zeko_learn()->get_db();
	}

	/**
	 * Course url.
	 *
	 * @param array $course Course.
	 */
	private function course_url( array $course ): string {
		return home_url( '/courses/' . rawurlencode( (string) ( $course['slug'] ?? '' ) ) . '/' );
	}

	/**
	 * Context.
	 *
	 * @param array $blocks Blocks.
	 */
	public function context( array $blocks ): array {
		if ( ! is_user_logged_in() || ! $this->module_active() ) {
			return $blocks;
		}
		$user_id = get_current_user_id();

		$courses = $this->db()->get_user_enrolled_courses( $user_id, array( 'limit' => 50 ) );
		$items   = array();
		foreach ( $courses as $course ) {
			$pct     = isset( $course['completion_pct'] ) ? (int) $course['completion_pct'] : 0;
			$items[] = array(
				'title'   => (string) $course['title'],
				'url'     => $this->course_url( $course ),
				'snippet' => sprintf( '%d%% complete', $pct ),
			);
		}
		if ( $items ) {
			$blocks[] = $this->context_block( 'learn', 'Your courses in progress', $items );
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
			'id'          => 'course_description',
			'module'      => 'learn',
			'label'       => __( 'Course description', 'zeko-ai' ),
			'description' => __( 'Draft a compelling course description from rough notes.', 'zeko-ai' ),
			'prompt'      => "Write an engaging course description. Include what students will learn, who it is for, and what they will be able to do by the end.\n\nNotes from the author:\n{notes}",
			'fields'      => array(
				array(
					'key'         => 'notes',
					'label'       => __( 'Your notes', 'zeko-ai' ),
					'type'        => 'textarea',
					'placeholder' => __( 'Topic, audience, outcomes…', 'zeko-ai' ),
				),
			),
			'target'      => 'textarea[name="description"]',
		);

		$presets[] = array(
			'id'          => 'what_you_learn',
			'module'      => 'learn',
			'label'       => __( '"What you will learn" list', 'zeko-ai' ),
			'description' => __( 'Generate a bulleted learning-outcomes list.', 'zeko-ai' ),
			'prompt'      => "Turn these topics into a clear, bulleted list of learning outcomes for a course. One skill per bullet, using plain language.\n\nTopics: {topics}",
			'fields'      => array(
				array(
					'key'         => 'topics',
					'label'       => __( 'Topics', 'zeko-ai' ),
					'type'        => 'textarea',
					'placeholder' => __( 'Separate each topic with a new line', 'zeko-ai' ),
				),
			),
			'target'      => 'textarea[name="what_you_learn"]',
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
			return $this->resolve_course( $query );
		};
		return $resolvers;
	}

	/**
	 * Resolve a query naming a specific course into a detail card.
	 *
	 * @param string $query Query.
	 */
	private function resolve_course( string $query ): ?array {
		if ( ! function_exists( 'zeko_learn' ) ) {
			return null;
		}

		$courses = $this->db()->get_courses(
			array(
				'status' => 'published',
				'limit'  => 500,
			)
		);

		$best    = null;
		$bestrow = null;
		$bestfit = 0.0;
		foreach ( $courses as $course ) {
			$fit = $this->entity_match_score( $query, (string) ( $course['title'] ?? '' ) );
			if ( $fit > $bestfit ) {
				$bestfit = $fit;
				$bestrow = $course;
			}
			if ( $fit >= 1.0 ) {
				break;
			}
		}

		if ( null === $bestrow || $bestfit < 0.6 ) {
			return null;
		}

		$title = (string) ( $bestrow['title'] ?? '' );
		$url   = $this->course_url( $bestrow );

		$facts = array();
		foreach ( array(
			__( 'Instructor', 'zeko-ai' ) => $bestrow['instructor_name'] ?? '',
			__( 'Level', 'zeko-ai' )      => $bestrow['level'] ?? '',
			__( 'Duration', 'zeko-ai' )   => $bestrow['duration'] ?? '',
			__( 'Price', 'zeko-ai' )      => isset( $bestrow['price'] ) && '' !== (string) $bestrow['price'] ? (string) $bestrow['price'] : '',
		) as $label => $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$facts[] = array(
					'label' => $label,
					'value' => $value,
				);
			}
		}

		$sections    = array();
		$description = trim( (string) ( $bestrow['description'] ?? ( $bestrow['subtitle'] ?? '' ) ) );
		if ( '' !== $description ) {
			$sections[] = array(
				'heading' => __( 'About this course', 'zeko-ai' ),
				'lines'   => array( wp_trim_words( wp_strip_all_tags( $description ), 40 ) ),
			);
		}

		$actions = array(
			$this->entity_action( 'course_view', __( 'View course', 'zeko-ai' ), $url ),
			$this->entity_action( 'course_enroll', __( 'Enroll', 'zeko-ai' ), $url ),
			$this->entity_action( 'course_browse', __( 'Browse courses', 'zeko-ai' ), $this->module_url( '/courses/' ) ),
		);

		return $this->entity_card(
			'course',
			__( 'Course', 'zeko-ai' ),
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

	/**
	 * Search sources.
	 *
	 * @param array $sources Sources.
	 */
	public function search_sources( array $sources ): array {
		if ( ! $this->module_active() ) {
			return $sources;
		}
		$sources[] = array(
			'type'   => 'course',
			'label'  => __( 'Courses', 'zeko-ai' ),
			'icon'   => 'dashicons-welcome-learn-more',
			'search' => function ( string $term, int $limit ): array {
				$courses = $this->db()->get_courses(
					array(
						'search' => $term,
						'status' => 'published',
						'limit'  => $limit,
					)
				);
				$out     = array();
				foreach ( $courses as $course ) {
					$out[] = array(
						'id'      => (int) $course['id'],
						'title'   => (string) $course['title'],
						'excerpt' => wp_trim_words( (string) ( $course['description'] ?? $course['subtitle'] ?? '' ), 20 ),
						'url'     => $this->course_url( $course ),
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
		if ( ! $this->module_active() ) {
			return $sources;
		}
		$sources[] = array(
			'type'  => 'course',
			'label' => __( 'Courses', 'zeko-ai' ),
			'items' => function ( int $limit ): array {
				$courses = $this->db()->get_courses(
					array(
						'status' => 'published',
						'limit'  => min( 200, $limit * 5 ),
					)
				);
				$out     = array();
				foreach ( $courses as $course ) {
					$out[] = array(
						'id'      => (int) $course['id'],
						'title'   => (string) $course['title'],
						'excerpt' => wp_trim_words( (string) ( $course['description'] ?? $course['subtitle'] ?? '' ), 30 ),
						'url'     => $this->course_url( $course ),
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
		if ( ! $this->module_active() ) {
			return $sources;
		}
		$sources[] = array(
			'source' => 'learn',
			'type'   => 'course',
			'label'  => __( 'Courses', 'zeko-ai' ),
			'fetch'  => function ( int $limit ): array {
				$courses = $this->db()->get_courses(
					array(
						'status' => 'published',
						'limit'  => $limit,
					)
				);
				$out     = array();
				foreach ( $courses as $course ) {
					$out[] = array(
						'content_id' => (int) $course['id'],
						'user_id'    => (int) ( $course['instructor_id'] ?? 0 ),
						'title'      => (string) $course['title'],
						'content'    => (string) ( $course['title'] ?? '' ) . "\n\n" . (string) ( $course['description'] ?? '' ),
					);
				}
				return $out;
			},
		);
		return $sources;
	}
}
