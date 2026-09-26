<?php
/**
 * Zeko Jobs integration for Zeko AI.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Integration_Jobs. */
class Zeko_AI_Integration_Jobs extends Zeko_AI_Integration {

	/**
	 * Hooks.
	 */
	protected function hooks(): void {
		if ( ! class_exists( 'Zeko_Jobs_DB' ) ) {
			return;
		}
		add_action( 'zeko_jobs_job_posted', array( $this, 'on_job_posted' ), 10, 2 );
		add_action( 'zeko_job_created', array( $this, 'on_job_created' ), 10, 2 );
	}

	/**
	 * Db.
	 */
	private function db() {
		return Zeko_Jobs_DB::get_instance();
	}

	/**
	 * Job url.
	 *
	 * @param array $job Job.
	 */
	private function job_url( array $job ): string {
		return home_url( '/jobs/' . rawurlencode( (string) ( $job['slug'] ?? '' ) ) . '/' );
	}

	// ── Auto moderation ────────────────────────────────────────────────.

	/**
	 * On job posted.
	 *
	 * @param int $job_id Job id.
	 * @param int $user_id User id.
	 */
	public function on_job_posted( int $job_id, int $user_id ): void {
		$job = $this->db()->get_job( $job_id );
		if ( ! $job ) {
			return;
		}
		$text = (string) ( $job['title'] ?? '' ) . "\n\n" . (string) ( $job['description'] ?? '' );
		$this->moderate( $user_id, 'jobs', 'job', $job_id, $text );
	}

	/**
	 * On job created.
	 *
	 * @param int $job_id Job id.
	 * @param int $user_id User id.
	 */
	public function on_job_created( int $job_id, int $user_id ): void {
		$this->on_job_posted( $job_id, $user_id );
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
		$db      = $this->db();

		$apps  = $db->get_user_applications( $user_id );
		$items = array();
		foreach ( $apps as $app ) {
			$items[] = array(
				'title'   => (string) ( $app['title'] ?? '' ),
				'url'     => $this->job_url( $app ),
				'snippet' => 'Application status: ' . (string) ( $app['status'] ?? '' ),
			);
		}
		if ( $items ) {
			$blocks[] = $this->context_block( 'jobs', 'Your job applications', $items );
		}

		$posted = $db->get_employer_all_jobs( $user_id );
		$items  = array();
		foreach ( $posted as $job ) {
			$items[] = array(
				'title'   => (string) ( $job['title'] ?? '' ),
				'url'     => $this->job_url( $job ),
				'snippet' => 'Status: ' . (string) ( $job['status'] ?? '' ),
			);
		}
		if ( $items ) {
			$blocks[] = $this->context_block( 'jobs', 'Your posted jobs', $items );
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
			'id'          => 'job_description',
			'module'      => 'jobs',
			'label'       => __( 'Job description', 'zeko-ai' ),
			'description' => __( 'Turn rough notes into a polished job description.', 'zeko-ai' ),
			'prompt'      => "Write a clear, professional job description in a welcoming community voice. Include an overview, key responsibilities, requirements, and a line about the team culture.\n\nNotes from the author:\n{notes}",
			'fields'      => array(
				array(
					'key'         => 'notes',
					'label'       => __( 'Your notes', 'zeko-ai' ),
					'type'        => 'textarea',
					'placeholder' => __( 'Role title, what the person will do, who it is for…', 'zeko-ai' ),
				),
			),
			'target'      => 'textarea[name="description"]',
		);

		$presets[] = array(
			'id'          => 'cover_letter',
			'module'      => 'jobs',
			'label'       => __( 'Cover letter', 'zeko-ai' ),
			'description' => __( 'Draft a tailored cover letter for a job application.', 'zeko-ai' ),
			'prompt'      => "Write a warm, professional cover letter. Mention the specific role, highlight the candidate's relevant strengths, and close with a call to action.\n\nJob title: {job_title}\nCandidate background: {background}",
			'fields'      => array(
				array(
					'key'         => 'job_title',
					'label'       => __( 'Job title', 'zeko-ai' ),
					'type'        => 'text',
					'placeholder' => __( 'e.g. Junior Web Developer', 'zeko-ai' ),
				),
				array(
					'key'         => 'background',
					'label'       => __( 'Candidate background', 'zeko-ai' ),
					'type'        => 'textarea',
					'placeholder' => __( 'Skills, experience, projects…', 'zeko-ai' ),
				),
			),
			'target'      => 'textarea[name="cover_letter"], textarea[name="message"]',
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
			return $this->resolve_job( $query );
		};
		return $resolvers;
	}

	/**
	 * Resolve a query naming a specific job posting into a detail card.
	 *
	 * @param string $query Query.
	 */
	private function resolve_job( string $query ): ?array {
		$db = $this->db();
		if ( ! class_exists( 'Zeko_Jobs_DB' ) ) {
			return null;
		}

		$jobs = $db->get_jobs(
			array(
				'status' => 'publish',
				'limit'  => 500,
			)
		);

		$best    = null;
		$bestrow = null;
		$bestfit = 0.0;
		foreach ( $jobs as $job ) {
			$fit = $this->entity_match_score( $query, (string) ( $job['title'] ?? '' ) );
			if ( $fit > $bestfit ) {
				$bestfit = $fit;
				$bestrow = $job;
			}
			if ( $fit >= 1.0 ) {
				break;
			}
		}

		if ( null === $bestrow || $bestfit < 0.6 ) {
			return null;
		}

		$title = (string) ( $bestrow['title'] ?? '' );
		$url   = $this->job_url( $bestrow );

		$facts = array();
		foreach ( array(
			__( 'Company', 'zeko-ai' )  => $bestrow['company'] ?? ( $bestrow['company_name'] ?? '' ),
			__( 'Location', 'zeko-ai' ) => $bestrow['location'] ?? '',
			__( 'Type', 'zeko-ai' )     => $bestrow['employment_type'] ?? ( $bestrow['type'] ?? '' ),
			__( 'Posted', 'zeko-ai' )   => $bestrow['created_at'] ?? ( $bestrow['posted_at'] ?? ( $bestrow['date_created'] ?? '' ) ),
		) as $label => $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$facts[] = array(
					'label' => $label,
					'value' => $value,
				);
			}
		}
		$salary = $this->job_salary( $bestrow );
		if ( '' !== $salary ) {
			$facts[] = array(
				'label' => __( 'Salary', 'zeko-ai' ),
				'value' => $salary,
			);
		}

		$sections    = array();
		$description = trim( (string) ( $bestrow['description'] ?? '' ) );
		if ( '' !== $description ) {
			$sections[] = array(
				'heading' => __( 'About this job', 'zeko-ai' ),
				'lines'   => array( wp_trim_words( wp_strip_all_tags( $description ), 40 ) ),
			);
		}

		$actions = array(
			$this->entity_action( 'job_view', __( 'View job', 'zeko-ai' ), $url ),
			$this->entity_action( 'job_apply', __( 'Apply now', 'zeko-ai' ), $url ),
			$this->entity_action( 'job_browse', __( 'Browse jobs', 'zeko-ai' ), $this->module_url( '/jobs/' ) ),
		);

		return $this->entity_card(
			'job',
			__( 'Job', 'zeko-ai' ),
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
	 * Best-effort salary line from whichever format the job row uses.
	 *
	 * @param array $job Job.
	 */
	private function job_salary( array $job ): string {
		if ( isset( $job['salary'] ) && '' !== (string) $job['salary'] ) {
			return (string) $job['salary'];
		}
		$min = isset( $job['salary_min'] ) ? (string) $job['salary_min'] : '';
		$max = isset( $job['salary_max'] ) ? (string) $job['salary_max'] : '';
		if ( '' !== $min || '' !== $max ) {
			return trim( $min . ' – ' . $max );
		}
		return '';
	}

	// ── Search ─────────────────────────────────────────────────────────.

	/**
	 * Search sources.
	 *
	 * @param array $sources Sources.
	 */
	public function search_sources( array $sources ): array {
		$sources[] = array(
			'type'   => 'job',
			'label'  => __( 'Jobs', 'zeko-ai' ),
			'icon'   => 'dashicons-briefcase',
			'search' => function ( string $term, int $limit ): array {
				$jobs = $this->db()->get_jobs_matching_search( array( 'search_term' => $term ), 3650, $limit );
				$out  = array();
				foreach ( $jobs as $job ) {
					$out[] = array(
						'id'      => (int) ( $job['id'] ?? 0 ),
						'title'   => (string) ( $job['title'] ?? '' ),
						'excerpt' => (string) ( $job['location'] ?? '' ),
						'url'     => $this->job_url( $job ),
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
			'type'  => 'job',
			'label' => __( 'Jobs', 'zeko-ai' ),
			'items' => function ( int $limit ): array {
				$jobs = $this->db()->get_jobs(
					array(
						'status' => 'publish',
						'limit'  => min( 200, $limit * 5 ),
					)
				);
				$out  = array();
				foreach ( $jobs as $job ) {
					$out[] = array(
						'id'      => (int) $job['id'],
						'title'   => (string) $job['title'],
						'excerpt' => wp_trim_words( (string) $job['description'], 30 ),
						'url'     => $this->job_url( $job ),
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
			'source' => 'jobs',
			'type'   => 'job',
			'label'  => __( 'Jobs', 'zeko-ai' ),
			'fetch'  => function ( int $limit ): array {
				$jobs = $this->db()->get_jobs(
					array(
						'status' => 'publish',
						'limit'  => $limit,
					)
				);
				$out  = array();
				foreach ( $jobs as $job ) {
					$out[] = array(
						'content_id' => (int) $job['id'],
						'user_id'    => (int) ( $job['employer_id'] ?? 0 ),
						'title'      => (string) $job['title'],
						'content'    => (string) ( $job['title'] ?? '' ) . "\n\n" . (string) ( $job['description'] ?? '' ),
					);
				}
				return $out;
			},
		);
		return $sources;
	}
}
