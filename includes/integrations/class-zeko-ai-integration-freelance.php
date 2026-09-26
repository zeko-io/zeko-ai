<?php
/**
 * Zeko Freelance integration for Zeko AI.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Integration_Freelance. */
class Zeko_AI_Integration_Freelance extends Zeko_AI_Integration {

	/**
	 * Hooks.
	 */
	protected function hooks(): void {
		if ( ! function_exists( 'zeko_freelance' ) ) {
			return;
		}
		add_action( 'zeko_freelance_project_created', array( $this, 'on_project_created' ), 10, 2 );
	}

	/**
	 * Db.
	 */
	private function db() {
		return zeko_freelance()->get_db();
	}

	/**
	 * Project url.
	 *
	 * @param int $project_id Project id.
	 */
	private function project_url( int $project_id ): string {
		return add_query_arg( 'zf_pid', $project_id, zeko_freelance_page_url( 'freelance-project' ) );
	}

	// ── Auto moderation ────────────────────────────────────────────────.

	/**
	 * On project created.
	 *
	 * @param int $project_id Project id.
	 * @param int $user_id User id.
	 */
	public function on_project_created( int $project_id, int $user_id ): void {
		$project = $this->db()->get_project( $project_id );
		if ( ! $project ) {
			return;
		}
		$text = (string) $project->title . "\n\n" . (string) $project->description;
		$this->moderate( $user_id, 'freelance', 'project', $project_id, $text );
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

		$projects = $this->db()->get_projects(
			array(
				'user_id'  => $user_id,
				'per_page' => 50,
			)
		);
		$items    = array();
		foreach ( $projects as $project ) {
			$items[] = array(
				'title'   => (string) $project->title,
				'url'     => $this->project_url( (int) $project->id ),
				'snippet' => 'Status: ' . (string) $project->status,
			);
		}
		if ( $items ) {
			$blocks[] = $this->context_block( 'freelance', 'Your freelance projects', $items );
		}

		$bids  = $this->db()->get_user_bids( $user_id );
		$items = array();
		foreach ( $bids as $bid ) {
			$items[] = array(
				'title'   => sprintf( 'Bid #%d', (int) $bid->id ),
				'url'     => $this->project_url( (int) $bid->project_id ),
				'snippet' => 'Status: ' . (string) $bid->status,
			);
		}
		if ( $items ) {
			$blocks[] = $this->context_block( 'freelance', 'Your bids', $items );
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
			'id'          => 'project_brief',
			'module'      => 'freelance',
			'label'       => __( 'Project brief', 'zeko-ai' ),
			'description' => __( 'Turn rough ideas into a clear freelance project brief.', 'zeko-ai' ),
			'prompt'      => "Write a clear freelance project brief. Describe the goal, the deliverables, the audience, and any constraints so freelancers can quote accurately.\n\nNotes from the client:\n{notes}",
			'fields'      => array(
				array(
					'key'         => 'notes',
					'label'       => __( 'Your notes', 'zeko-ai' ),
					'type'        => 'textarea',
					'placeholder' => __( 'Goal, deliverables, budget range, timeline…', 'zeko-ai' ),
				),
			),
			'target'      => 'textarea[name="description"]',
		);

		$presets[] = array(
			'id'          => 'bid_proposal',
			'module'      => 'freelance',
			'label'       => __( 'Bid proposal', 'zeko-ai' ),
			'description' => __( 'Draft a persuasive proposal for a project.', 'zeko-ai' ),
			'prompt'      => "Write a confident, specific freelance proposal. Restate your understanding of the project, outline your approach, and explain why you are the right fit.\n\nProject summary: {project}\nYour experience: {experience}",
			'fields'      => array(
				array(
					'key'   => 'project',
					'label' => __( 'Project summary', 'zeko-ai' ),
					'type'  => 'textarea',
				),
				array(
					'key'         => 'experience',
					'label'       => __( 'Your experience', 'zeko-ai' ),
					'type'        => 'textarea',
					'placeholder' => __( 'Relevant skills and past work…', 'zeko-ai' ),
				),
			),
			'target'      => 'textarea[name="proposal"]',
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
			return $this->resolve_project( $query );
		};
		return $resolvers;
	}

	/**
	 * Resolve a query naming a specific freelance project into a card.
	 *
	 * @param string $query Query.
	 */
	private function resolve_project( string $query ): ?array {
		if ( ! function_exists( 'zeko_freelance' ) ) {
			return null;
		}

		$projects = $this->db()->get_projects(
			array(
				'status'   => 'open',
				'per_page' => 500,
			)
		);

		$best    = null;
		$bestrow = null;
		$bestfit = 0.0;
		foreach ( $projects as $project ) {
			$fit = $this->entity_match_score( $query, (string) $project->title );
			if ( $fit > $bestfit ) {
				$bestfit = $fit;
				$bestrow = $project;
			}
			if ( $fit >= 1.0 ) {
				break;
			}
		}

		if ( null === $bestrow || $bestfit < 0.6 ) {
			return null;
		}

		$title = (string) $bestrow->title;
		$url   = $this->project_url( (int) $bestrow->id );

		$facts = array();
		if ( isset( $bestrow->budget ) && '' !== (string) $bestrow->budget && (float) $bestrow->budget > 0 ) {
			$facts[] = array(
				'label' => __( 'Budget', 'zeko-ai' ),
				'value' => (string) $bestrow->budget,
			);
		}
		if ( isset( $bestrow->category ) && '' !== (string) $bestrow->category ) {
			$facts[] = array(
				'label' => __( 'Category', 'zeko-ai' ),
				'value' => (string) $bestrow->category,
			);
		}
		if ( isset( $bestrow->status ) && '' !== (string) $bestrow->status ) {
			$facts[] = array(
				'label' => __( 'Status', 'zeko-ai' ),
				'value' => (string) $bestrow->status,
			);
		}

		$sections    = array();
		$description = trim( (string) $bestrow->description );
		if ( '' !== $description ) {
			$sections[] = array(
				'heading' => __( 'About this project', 'zeko-ai' ),
				'lines'   => array( wp_trim_words( wp_strip_all_tags( $description ), 40 ) ),
			);
		}

		$actions = array(
			$this->entity_action( 'freelance_view', __( 'View project', 'zeko-ai' ), $url ),
			$this->entity_action( 'freelance_bid', __( 'Place a bid', 'zeko-ai' ), $url ),
			$this->entity_action( 'freelance_browse', __( 'Browse projects', 'zeko-ai' ), zeko_freelance_page_url( 'freelance' ) ),
		);

		return $this->entity_card(
			'project',
			__( 'Freelance project', 'zeko-ai' ),
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
			'type'   => 'project',
			'label'  => __( 'Freelance projects', 'zeko-ai' ),
			'icon'   => 'dashicons-portfolio',
			'search' => function ( string $term, int $limit ): array {
				$projects = $this->db()->get_projects(
					array(
						'status'   => 'open',
						'search'   => $term,
						'per_page' => $limit,
					)
				);
				$out      = array();
				foreach ( $projects as $project ) {
					$out[] = array(
						'id'      => (int) $project->id,
						'title'   => (string) $project->title,
						'excerpt' => wp_trim_words( (string) $project->description, 20 ),
						'url'     => $this->project_url( (int) $project->id ),
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
			'type'  => 'project',
			'label' => __( 'Freelance projects', 'zeko-ai' ),
			'items' => function ( int $limit ): array {
				$projects = $this->db()->get_projects(
					array(
						'status'   => 'open',
						'per_page' => min( 200, $limit * 5 ),
					)
				);
				$out      = array();
				foreach ( $projects as $project ) {
					$out[] = array(
						'id'      => (int) $project->id,
						'title'   => (string) $project->title,
						'excerpt' => wp_trim_words( (string) $project->description, 30 ),
						'url'     => $this->project_url( (int) $project->id ),
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
			'source' => 'freelance',
			'type'   => 'project',
			'label'  => __( 'Freelance projects', 'zeko-ai' ),
			'fetch'  => function ( int $limit ): array {
				$projects = $this->db()->get_projects(
					array(
						'status'   => 'open',
						'per_page' => $limit,
					)
				);
				$out      = array();
				foreach ( $projects as $project ) {
					$out[] = array(
						'content_id' => (int) $project->id,
						'user_id'    => (int) $project->user_id,
						'title'      => (string) $project->title,
						'content'    => (string) $project->title . "\n\n" . (string) $project->description,
					);
				}
				return $out;
			},
		);
		return $sources;
	}
}
