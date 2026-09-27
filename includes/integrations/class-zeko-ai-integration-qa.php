<?php
/**
 * Zeko Q&A integration for Zeko AI.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Integration_QA. */
class Zeko_AI_Integration_QA extends Zeko_AI_Integration {

	/**
	 * Hooks.
	 */
	protected function hooks(): void {
		add_action( 'zeko_qa_question_created', array( $this, 'on_question_created' ), 10, 3 );
	}

	/**
	 * Moderate a freshly-created question.
	 *
	 * @param int   $question_id Question id.
	 * @param int   $user_id     Author id.
	 * @param array $data        Insert data.
	 */
	public function on_question_created( int $question_id, int $user_id, array $data ): void {
		$text = trim( (string) ( $data['title'] ?? '' ) . ' ' . wp_strip_all_tags( (string) ( $data['content'] ?? '' ) ) );
		if ( '' === $text ) {
			return;
		}
		$this->moderate( $user_id, 'qa', 'question', $question_id, $text );
	}

	/**
	 * Whether the Q&A module is active.
	 */
	private function module_active(): bool {
		return function_exists( 'zeko_qa' );
	}

	/**
	 * Db (null-safe for inactive module).
	 */
	private function db() {
		if ( ! $this->module_active() ) {
			return null;
		}
		return zeko_qa()->get_db();
	}

	/**
	 * Question url.
	 *
	 * @param mixed $question Question.
	 */
	private function question_url( $question ): string {
		return home_url( '/questions/' . rawurlencode( (string) $question->slug ) . '/' );
	}

	/**
	 * User questions.
	 *
	 * @param int $user_id User id.
	 * @param int $limit Limit.
	 */
	private function user_questions( int $user_id, int $limit ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->db()->get_table_questions()} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
				$user_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
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

		$questions = $this->user_questions( $user_id, 50 );
		$items     = array();
		foreach ( $questions as $question ) {
			$items[] = array(
				'title'   => (string) $question->title,
				'url'     => $this->question_url( $question ),
				'snippet' => wp_trim_words( (string) $question->content, 20 ),
			);
		}
		if ( $items ) {
			$blocks[] = $this->context_block( 'qa', 'Your questions', $items );
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
			'id'          => 'question_draft',
			'module'      => 'qa',
			'label'       => __( 'Question draft', 'zeko-ai' ),
			'description' => __( 'Turn rough notes into a clear, answerable question.', 'zeko-ai' ),
			'prompt'      => "Write a clear, specific question for a community Q&A. Provide context first, then the exact question, and finish with what the asker has already tried.\n\nNotes from the author:\n{notes}",
			'fields'      => array(
				array(
					'key'         => 'notes',
					'label'       => __( 'Your notes', 'zeko-ai' ),
					'type'        => 'textarea',
					'placeholder' => __( 'What are you trying to do? What have you tried?', 'zeko-ai' ),
				),
			),
			'target'      => 'textarea[name="content"], textarea[name="question"]',
		);

		$presets[] = array(
			'id'          => 'answer_draft',
			'module'      => 'qa',
			'label'       => __( 'Answer draft', 'zeko-ai' ),
			'description' => __( 'Draft a helpful, well-structured answer.', 'zeko-ai' ),
			'prompt'      => "Write a helpful, concise answer to the question below. Use short paragraphs or a list, be specific, and include any relevant caveats.\n\nQuestion:\n{question}\n\nDraft notes:\n{notes}",
			'fields'      => array(
				array(
					'key'   => 'question',
					'label' => __( 'The question', 'zeko-ai' ),
					'type'  => 'textarea',
				),
				array(
					'key'         => 'notes',
					'label'       => __( 'Draft notes', 'zeko-ai' ),
					'type'        => 'textarea',
					'placeholder' => __( 'Key points you want to cover…', 'zeko-ai' ),
				),
			),
			'target'      => 'textarea[name="answer"]',
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
			return $this->resolve_question( $query );
		};
		return $resolvers;
	}

	/**
	 * Resolve a query naming a specific community question into a card.
	 *
	 * @param string $query Query.
	 */
	private function resolve_question( string $query ): ?array {
		if ( ! function_exists( 'zeko_qa' ) ) {
			return null;
		}

		$questions = $this->db()->get_questions(
			array(
				'status' => 'open',
				'limit'  => 500,
			)
		);

		$best    = null;
		$bestrow = null;
		$bestfit = 0.0;
		foreach ( $questions as $question ) {
			$fit = $this->entity_match_score( $query, (string) $question->title );
			if ( $fit > $bestfit ) {
				$bestfit = $fit;
				$bestrow = $question;
			}
			if ( $fit >= 1.0 ) {
				break;
			}
		}

		if ( null === $bestrow || $bestfit < 0.6 ) {
			return null;
		}

		$title = (string) $bestrow->title;
		$url   = $this->question_url( $bestrow );

		$facts = array();
		if ( isset( $bestrow->created_at ) && '' !== (string) $bestrow->created_at ) {
			$facts[] = array(
				'label' => __( 'Asked', 'zeko-ai' ),
				'value' => (string) $bestrow->created_at,
			);
		}
		if ( isset( $bestrow->views ) && (int) $bestrow->views > 0 ) {
			$facts[] = array(
				'label' => __( 'Views', 'zeko-ai' ),
				'value' => (string) (int) $bestrow->views,
			);
		}
		if ( isset( $bestrow->answer_count ) && (int) $bestrow->answer_count > 0 ) {
			$facts[] = array(
				'label' => __( 'Answers', 'zeko-ai' ),
				'value' => (string) (int) $bestrow->answer_count,
			);
		}
		if ( isset( $bestrow->upvotes ) && (int) $bestrow->upvotes > 0 ) {
			$facts[] = array(
				'label' => __( 'Upvotes', 'zeko-ai' ),
				'value' => (string) (int) $bestrow->upvotes,
			);
		}

		$sections = array();
		$content  = trim( (string) ( $bestrow->content ?? '' ) );
		if ( '' !== $content ) {
			$sections[] = array(
				'heading' => __( 'Question', 'zeko-ai' ),
				'lines'   => array( wp_trim_words( wp_strip_all_tags( $content ), 40 ) ),
			);
		}

		$actions = array(
			$this->entity_action( 'qa_view', __( 'View question', 'zeko-ai' ), $url ),
			$this->entity_action( 'qa_answer', __( 'Answer it', 'zeko-ai' ), $url ),
			$this->entity_action( 'qa_ask', __( 'Ask a question', 'zeko-ai' ), $this->module_url( '/questions/' ) ),
		);

		return $this->entity_card(
			'question',
			__( 'Question', 'zeko-ai' ),
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
			'type'   => 'question',
			'label'  => __( 'Q&A', 'zeko-ai' ),
			'icon'   => 'dashicons-format-chat',
			'search' => function ( string $term, int $limit ): array {
				$questions = $this->db()->get_questions(
					array(
						'search' => $term,
						'limit'  => $limit,
						'status' => 'open',
					)
				);
				$out       = array();
				foreach ( $questions as $question ) {
					$out[] = array(
						'id'      => (int) $question->id,
						'title'   => (string) $question->title,
						'excerpt' => wp_trim_words( (string) $question->content, 20 ),
						'url'     => $this->question_url( $question ),
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
			'type'  => 'question',
			'label' => __( 'Q&A', 'zeko-ai' ),
			'items' => function ( int $limit ): array {
				$questions = $this->db()->get_questions(
					array(
						'status'  => 'open',
						'orderby' => 'trending_score',
						'limit'   => $limit,
					)
				);
				$out       = array();
				foreach ( $questions as $question ) {
					$out[] = array(
						'id'      => (int) $question->id,
						'title'   => (string) $question->title,
						'excerpt' => wp_trim_words( (string) $question->content, 30 ),
						'url'     => $this->question_url( $question ),
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
			'source' => 'qa',
			'type'   => 'question',
			'label'  => __( 'Q&A questions', 'zeko-ai' ),
			'fetch'  => function ( int $limit ): array {
				$questions = $this->db()->get_questions(
					array(
						'status' => 'open',
						'limit'  => $limit,
					)
				);
				$out       = array();
				foreach ( $questions as $question ) {
					$out[] = array(
						'content_id' => (int) $question->id,
						'user_id'    => (int) $question->user_id,
						'title'      => (string) $question->title,
						'content'    => (string) $question->title . "\n\n" . (string) $question->content,
					);
				}
				return $out;
			},
		);
		return $sources;
	}
}
