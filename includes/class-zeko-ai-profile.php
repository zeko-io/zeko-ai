<?php
/**
 * Zeko AI member profile hub.
 *
 * Renders a single member profile page that aggregates the profiles a user
 * has across the ecosystem: Q&A, mentoring, dating, freelance and courses.
 * Each module gets its own tab with a summary card and a link to the module's
 * real profile page — nothing is re-implemented, only surfaced.
 *
 * Registers:
 *   [zeko_ai_profile]   the hub (takes optional user_id="N").
 *   [zeko_public_profile] alias so the theme's existing /profile/ page and
 *                         zeko-profile-template.php stop printing raw
 *                         shortcodes and render the hub instead.
 *   /profile/{slug}/    rewrite so pretty profile URLs resolve to the page.
 *
 * Also keeps the pretty slug alive: writes the `zeko_profile_slug` meta the
 * core helper reads (on register/profile_update and lazily when a profile is
 * viewed) so ecosystem-wide profile links upgrade from ?user_id= to /profile/.
 *
 * Everything lives inside Zeko AI (no edits to other plugins). Module cards
 * are filterable via `zeko_ai_profile_modules`, tabs via
 * `zeko_ai_profile_tabs`, and per-module visibility per user via
 * `zeko_ai_profile_show_module`; the Dating tab also respects the member's
 * own "show in search" privacy toggle. Module detection is best-effort and
 * fail-soft.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Profile. */
class Zeko_AI_Profile {

	/**
	 * QUERY VAR.
	 *
	 * @var mixed
	 */
	const QUERY_VAR = 'zeko_profile_slug';

	/**
	 * MODULE KEYS.
	 *
	 * @var mixed
	 */
	const MODULE_KEYS = array( 'qa', 'mentor', 'dating', 'freelance', 'courses' );

	/**
	 * Construct.
	 */
	public function __construct() {
		add_shortcode( 'zeko_ai_profile', array( $this, 'shortcode' ) );
		add_shortcode( 'zeko_public_profile', array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_rewrite' ), 20 );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		// Keep the pretty /profile/{slug}/ URL alive: write the custom slug.
		// meta the core helper reads, so the whole ecosystem (agent links,.
		// activity feed, friendships, dashboard) emits pretty URLs.
		add_action( 'user_register', array( $this, 'ensure_profile_slug' ) );
		add_action( 'profile_update', array( $this, 'ensure_profile_slug' ) );

		// Deep-link the module profiles from the agent's "profile" intent.
		add_filter( 'zeko_ai_agent_actions', array( $this, 'agent_profile_actions' ), 10, 3 );
	}

	/**
	 * Assets.
	 */
	public function register_assets(): void {
		wp_register_script( 'zeko-ai-profile', ZEKO_AI_PLUGIN_URL . 'assets/js/zeko-ai-profile.js', array(), ZEKO_AI_VERSION, true );
	}

	// ---------------------------------------------------------------------.
	// Rewrites.
	// ---------------------------------------------------------------------.

	/**
	 * Query var.
	 *
	 * @param array $vars Vars.
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Rewrite.
	 */
	public function register_rewrite(): void {
		add_rewrite_rule( '^profile/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Make sure the member has a custom `zeko_profile_slug` meta value so the
	 * pretty /profile/{slug}/ URL is stable and the core helper
	 * (Zeko_Core_Helpers::get_user_profile_url) stops emitting ?user_id= links.
	 * Written on user_register/profile_update and lazily backfilled for any
	 * member whose profile gets viewed. A manually-set custom slug is kept.
	 *
	 * @return string The active slug ('' when the user does not exist).
	 * @param int $user_id * @return string The active slug ('' when the user does not exist).
	 */
	public function ensure_profile_slug( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}

		$meta = get_user_meta( $user_id, 'zeko_profile_slug', true );
		if ( is_string( $meta ) && '' !== $meta ) {
			return $meta;
		}

		$slug = (string) ( $user->user_nicename ? $user->user_nicename : sanitize_title( (string) $user->user_login ) );
		if ( '' === $slug ) {
			return '';
		}
		update_user_meta( $user_id, 'zeko_profile_slug', $slug );
		return $slug;
	}

	// ---------------------------------------------------------------------.
	// Shortcode.
	// ---------------------------------------------------------------------.

	/**
	 * Render the hub for a resolved user (attr user_id, pretty slug query var,
	 * ?user_id= query arg, or the current user as a last resort).
	 *
	 * @param array  $atts Atts.
	 * @param string $content Content.
	 */
	public function shortcode( array $atts = array(), string $content = '' ): string {
		unset( $content );
		if ( wp_style_is( 'zeko-ai', 'registered' ) ) {
			wp_enqueue_style( 'zeko-ai' );
		}

		$atts    = shortcode_atts( array( 'user_id' => 0 ), $atts, 'zeko_ai_profile' );
		$user_id = $this->resolve_user_id( (int) $atts['user_id'] );

		$user = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $user ) {
			return '<div class="zeko-ai-profile zeko-ai-profile--empty"><p>' . esc_html__( 'Profile not found.', 'zeko-ai' ) . '</p></div>';
		}

		// Backfill the pretty slug meta for any visited member so profile URLs.
		// ecosystem-wide upgrade from ?user_id=N to /profile/{slug}/.
		$this->ensure_profile_slug( $user_id );

		if ( wp_script_is( 'zeko-ai-profile', 'registered' ) ) {
			wp_enqueue_script( 'zeko-ai-profile' );
		}

		$cards  = $this->module_cards( $user_id );
		$base   = $this->profile_base_url( $user_id );
		$active = $this->active_tab( $cards );

		$html  = $this->render_header( $user, $cards, $base );
		$html .= $this->render_tabs( $user_id, $base, $active );
		$html .= $this->render_panels( $user, $cards, $base, $active );

		return '<div class="zeko-ai-profile">' . $html . '</div>';
	}

	// ---------------------------------------------------------------------.
	// User resolution.
	// ---------------------------------------------------------------------.

	/**
	 * Resolve user id.
	 *
	 * @param int $attr_user_id Attr user id.
	 */
	private function resolve_user_id( int $attr_user_id ): int {
		if ( $attr_user_id > 0 ) {
			return $attr_user_id;
		}

		$slug = (string) get_query_var( self::QUERY_VAR, '' );
		if ( '' !== $slug ) {
			$user = $this->resolve_slug_user( $slug );
			if ( $user ) {
				return (int) $user->ID;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['user_id'] ) && absint( $_GET['user_id'] ) > 0 ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return absint( $_GET['user_id'] );
		}

		return get_current_user_id();
	}

	/**
	 * Resolve slug user.
	 *
	 * @param string $slug Slug.
	 */
	private function resolve_slug_user( string $slug ): ?\WP_User {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// Prefer the custom profile slug the core helper generates URLs with.
		$by_meta = get_users(
			array(
				'meta_key'   => 'zeko_profile_slug',
				'meta_value' => $slug,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( ! empty( $by_meta ) ) {
			return get_userdata( (int) $by_meta[0] );
		}

		$user = get_user_by( 'login', $slug );
		if ( $user ) {
			return $user;
		}
		$user = get_user_by( 'slug', $slug );
		if ( $user ) {
			return $user;
		}
		$user = get_user_by( 'nicename', $slug );
		return $user ? $user : null;
	}

	/**
	 * Canonical URL for the member's profile page on this site.
	 *
	 * @param int $user_id User id.
	 */
	private function profile_base_url( int $user_id ): string {
		$this->ensure_profile_slug( $user_id );
		if ( class_exists( 'Zeko_Core_Helpers' ) ) {
			$url = Zeko_Core_Helpers::get_instance()->get_user_profile_url( $user_id );
		} else {
			$url = home_url( '/profile/?user_id=' . $user_id );
		}
		return (string) $url;
	}

	/**
	 * Active tab.
	 *
	 * @param array $cards Cards.
	 */
	private function active_tab( array $cards ): string {
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'overview' === $requested || isset( $cards[ $requested ] ) ) {
			return $requested;
		}
		return 'overview';
	}

	// ---------------------------------------------------------------------.
	// Module cards + tabs.
	// ---------------------------------------------------------------------.

	/**
	 * Presence/summary cards for every module profile the member has.
	 * Public so the agent can deep-link the same set as action buttons.
	 *
	 * @return array<string,array{key:string,label:string,url:string,summary:array<int,string>}>
	 * @param int $user_id * @return array<string,array{key:string,label:string,url:string,summary:array<int,string>}>.
	 */
	public function module_cards( int $user_id ): array {
		$cards = array();

		foreach ( array(
			'qa'        => $this->qa_card( $user_id ),
			'mentor'    => $this->mentor_card( $user_id ),
			'dating'    => $this->dating_card( $user_id ),
			'freelance' => $this->freelance_card( $user_id ),
			'courses'   => $this->courses_card( $user_id ),
		) as $key => $card ) {
			if ( $card && $this->module_visible( (string) $key, $user_id ) ) {
				$cards[ $key ] = $card;
			}
		}

		return (array) apply_filters( 'zeko_ai_profile_modules', $cards, $user_id );
	}

	/**
	 * Per-module visibility gate. Defaults to on; sites can opt individual
	 * modules out per user via the `zeko_ai_profile_show_module` filter, and
	 * the Dating tab additionally respects the member's own "show in search"
	 * privacy toggle so a hidden dating profile is never surfaced publicly.
	 *
	 * @return bool
	 * @param string $key Module key (qa/mentor/dating/freelance/courses).
	 * @param int    $user_id * @return bool.
	 */
	public function module_visible( string $key, int $user_id ): bool {
		$visible = true;

		if ( 'dating' === $key ) {
			$settings = get_user_meta( $user_id, 'zeko_love_user_settings', true );
			if ( is_array( $settings ) && array_key_exists( 'show_in_search', $settings ) && empty( $settings['show_in_search'] ) ) {
				$visible = false;
			}
		}

		return (bool) apply_filters( 'zeko_ai_profile_show_module', $visible, $key, $user_id );
	}

	/**
	 * Owner-facing onboarding checklist: which tracked modules are missing on
	 * this member's account, with a direct "get started" link for each.
	 * Only modules whose plugin is active are listed; items whose profile
	 * exists (or, for Q&A, has any contribution) are marked done.
	 *
	 * @return array<string,array{key:string,label:string,description:string,url:string,done:bool}>
	 * @param int $user_id User id.
	 */
	public function profile_checklist( int $user_id ): array {
		$cards = $this->module_cards( $user_id );
		$user  = get_userdata( $user_id );
		$items = array();

		if ( function_exists( 'zeko_qa' ) ) {
			$items['qa'] = array(
				'key'         => 'qa',
				'label'       => __( 'Q&A', 'zeko-ai' ),
				'description' => __( 'Ask or answer your first question to start building reputation.', 'zeko-ai' ),
				'url'         => home_url( '/questions/' ),
				'done'        => (int) ( $cards['qa']['stat']['n'] ?? 0 ) > 0,
			);
		}

		if ( function_exists( 'zeko_mentor' ) ) {
			$items['mentor'] = array(
				'key'         => 'mentor',
				'label'       => __( 'Mentoring', 'zeko-ai' ),
				'description' => __( 'Create a mentor profile and start booking sessions.', 'zeko-ai' ),
				'url'         => home_url( '/mentors/' ),
				'done'        => isset( $cards['mentor'] ),
			);
		}

		if ( function_exists( 'zeko_love' ) ) {
			$url = home_url( '/dating-settings/' );
			try {
				if ( function_exists( 'zeko_love_page_url' ) ) {
					$url = (string) zeko_love_page_url( 'dating-settings' );
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
			$items['dating'] = array(
				'key'         => 'dating',
				'label'       => __( 'Dating', 'zeko-ai' ),
				'description' => __( 'Set up your dating profile so matches can find you.', 'zeko-ai' ),
				'url'         => $url,
				'done'        => isset( $cards['dating'] ),
			);
		}

		if ( function_exists( 'zeko_freelance' ) ) {
			$url = home_url( '/freelance-profile/' );
			try {
				if ( function_exists( 'zeko_freelance_page_url' ) ) {
					$url = (string) zeko_freelance_page_url( 'freelance-profile' );
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
			$items['freelance'] = array(
				'key'         => 'freelance',
				'label'       => __( 'Freelance', 'zeko-ai' ),
				'description' => __( 'Publish your portfolio to start receiving project bids.', 'zeko-ai' ),
				'url'         => $url,
				'done'        => isset( $cards['freelance'] ),
			);
		}

		if ( function_exists( 'zeko_learn' ) ) {
			$url      = home_url( '/instructors/' );
			$nicename = $user ? (string) $user->user_nicename : '';
			if ( '' !== $nicename ) {
				$url = home_url( '/instructors/' . $nicename . '/' );
			}
			$items['courses'] = array(
				'key'         => 'courses',
				'label'       => __( 'Courses', 'zeko-ai' ),
				'description' => __( 'Publish your first course to teach the community.', 'zeko-ai' ),
				'url'         => $url,
				'done'        => isset( $cards['courses'] ),
			);
		}

		return (array) apply_filters( 'zeko_ai_profile_checklist', $items, $user_id );
	}

	/**
	 * The member's recent public actions from the theme activity feed
	 * (`wp_zeko_user_activity`), newest first. Fail-soft: empty when the
	 * table is missing or the query fails.
	 *
	 * @return array<int,array{label:string,meta:string,date:string}>
	 * @param int $user_id User id.
	 * @param int $limit Limit.
	 */
	public function recent_activity( int $user_id, int $limit = 8 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_user_activity';

		try {
			if ( $table !== (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				return array();
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			}
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT activity_type, activity_module, activity_content, activity_date
				FROM {$table}
				WHERE user_id = %d AND activity_status = 'published'
				ORDER BY activity_date DESC
				LIMIT %d",
					$user_id,
					$limit
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return array();
		}

		if ( empty( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'label' => $this->activity_label( (string) $row->activity_type, (string) ( $row->activity_module ?? '' ) ),
				'meta'  => (string) ( $row->activity_content ?? '' ),
				'date'  => (string) $row->activity_date,
			);
		}

		return (array) apply_filters( 'zeko_ai_profile_activity', $out, $user_id );
	}

	/**
	 * The member's rewards standing when the Rewards plugin is live
	 * (points, tier, badge count, dashboard link). Fail-soft and filterable.
	 *
	 * @return array{active:bool,points:int,badges:int,tier:string,tier_key:string,url:string}
	 * @param int $user_id User id.
	 */
	public function rewards_summary( int $user_id ): array {
		$summary = array(
			'active'   => false,
			'points'   => 0,
			'badges'   => 0,
			'tier'     => '',
			'tier_key' => '',
			'url'      => '',
		);

		try {
			if ( function_exists( 'zeko_rewards' ) && method_exists( zeko_rewards(), 'get_db' ) ) {
				$db      = zeko_rewards()->get_db();
				$tiers   = function_exists( 'zeko_rewards_get_tiers' ) ? (array) zeko_rewards_get_tiers() : array();
				$tier    = (string) get_user_meta( $user_id, 'zeko_rewards_tier', true );
				$summary = array(
					'active'   => true,
					'points'   => (int) $db->user_points( $user_id ),
					'badges'   => count( (array) $db->get_user_badges( $user_id ) ),
					'tier'     => $tiers[ $tier ]['label'] ?? ( '' !== $tier ? $tier : 'bronze' ),
					'tier_key' => '' !== $tier ? $tier : 'bronze',
					'url'      => function_exists( 'zeko_rewards_page_url' ) ? (string) zeko_rewards_page_url( 'rewards' ) : home_url( '/rewards/' ),
				);
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		return (array) apply_filters( 'zeko_ai_profile_rewards', $summary, $user_id );
	}

	/**
	 * Activity label.
	 *
	 * @param string $type Type.
	 * @param string $module Module.
	 */
	private function activity_label( string $type, string $module ): string {
		$map = array(
			'registered'  => __( 'Joined Zeko', 'zeko-ai' ),
			'signup'      => __( 'Joined Zeko', 'zeko-ai' ),
			'login'       => __( 'Logged in', 'zeko-ai' ),
			'question'    => __( 'Asked a question', 'zeko-ai' ),
			'answer'      => __( 'Answered a question', 'zeko-ai' ),
			'job'         => __( 'Posted a job', 'zeko-ai' ),
			'application' => __( 'Applied to a job', 'zeko-ai' ),
			'enrollment'  => __( 'Enrolled in a course', 'zeko-ai' ),
			'course'      => __( 'Completed a course', 'zeko-ai' ),
			'bid'         => __( 'Placed a bid on a project', 'zeko-ai' ),
			'project'     => __( 'Started a project', 'zeko-ai' ),
			'friend'      => __( 'Made a friend', 'zeko-ai' ),
			'review'      => __( 'Left a review', 'zeko-ai' ),
			'achievement' => __( 'Earned an achievement', 'zeko-ai' ),
		);

		if ( isset( $map[ $type ] ) ) {
			return $map[ $type ];
		}
		if ( '' !== $module ) {
			/* translators: %s: module name, e.g. qa */
			return sprintf( __( '%s activity', 'zeko-ai' ), ucfirst( (string) $module ) );
		}
		return ucwords( str_replace( '_', ' ', (string) $type ) );
	}

	/**
	 * Ordered tab list for the hub (Overview + one per module card).
	 *
	 * @return array<int,array{key:string,label:string,url:string}>
	 * @param int $user_id User id.
	 */
	public function tabs( int $user_id ): array {
		$cards = $this->module_cards( $user_id );
		$base  = $this->profile_base_url( $user_id );

		$tabs = array(
			array(
				'key'   => 'overview',
				'label' => __( 'Overview', 'zeko-ai' ),
				'url'   => $base,
			),
		);
		foreach ( $cards as $card ) {
			$tabs[] = array(
				'key'   => (string) $card['key'],
				'label' => (string) $card['label'],
				'url'   => add_query_arg( 'tab', (string) $card['key'], $base ),
			);
		}

		return (array) apply_filters( 'zeko_ai_profile_tabs', $tabs, $user_id );
	}

	// ---------------------------------------------------------------------.
	// Per-module cards (all fail-soft).
	// ---------------------------------------------------------------------.

	/**
	 * Qa card.
	 *
	 * @param int $user_id User id.
	 */
	private function qa_card( int $user_id ): array {
		$user  = get_userdata( $user_id );
		$stats = array();
		try {
			if ( $user && function_exists( 'zeko_qa' ) ) {
				$stats = (array) zeko_qa()->get_db()->get_user_qa_stats( $user_id );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		$summary = array(
			sprintf(
				/* translators: %d: question count */
				_n( '%d question', '%d questions', (int) ( $stats['question_count'] ?? 0 ), 'zeko-ai' ),
				(int) ( $stats['question_count'] ?? 0 )
			),
			sprintf(
				/* translators: %d: answer count */
				_n( '%d answer', '%d answers', (int) ( $stats['answer_count'] ?? 0 ), 'zeko-ai' ),
				(int) ( $stats['answer_count'] ?? 0 )
			),
			sprintf(
				/* translators: %d: reputation points */
				__( '%d reputation', 'zeko-ai' ),
				(int) ( $stats['reputation'] ?? 0 )
			),
		);

		return array(
			'key'     => 'qa',
			'label'   => __( 'Q&A', 'zeko-ai' ),
			'url'     => $user ? home_url( '/users/' . $user->user_nicename . '/' ) : '',
			'summary' => $summary,
			'stat'    => array(
				'n'     => (int) ( $stats['question_count'] ?? 0 ) + (int) ( $stats['answer_count'] ?? 0 ),
				'label' => __( 'questions & answers', 'zeko-ai' ),
			),
		);
	}

	/**
	 * Mentor card.
	 *
	 * @param int $user_id User id.
	 */
	private function mentor_card( int $user_id ): ?array {
		$user = get_userdata( $user_id );
		if ( ! $user || ! function_exists( 'zeko_mentor' ) ) {
			return null;
		}
		try {
			$profile = zeko_mentor()->get_db()->get_profile( $user_id );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return null;
		}
		if ( ! $profile ) {
			return null;
		}

		return array(
			'key'     => 'mentor',
			'label'   => __( 'Mentoring', 'zeko-ai' ),
			'url'     => home_url( '/mentors/' . $user->user_nicename . '/' ),
			'summary' => array( __( 'Published mentor profile', 'zeko-ai' ) ),
		);
	}

	/**
	 * Dating card.
	 *
	 * @param int $user_id User id.
	 */
	private function dating_card( int $user_id ): ?array {
		if ( ! function_exists( 'zeko_love' ) || ! zeko_love() ) {
			return null;
		}
		try {
			$profile = zeko_love()->get_db()->get_profile( $user_id );
			if ( ! $profile ) {
				return null;
			}
			$url = (string) zeko_love()->get_public()->profile_view_url( $user_id );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return null;
		}

		return array(
			'key'     => 'dating',
			'label'   => __( 'Dating', 'zeko-ai' ),
			'url'     => $url,
			'summary' => array( __( 'Active dating profile', 'zeko-ai' ) ),
		);
	}

	/**
	 * Freelance card.
	 *
	 * @param int $user_id User id.
	 */
	private function freelance_card( int $user_id ): ?array {
		if ( ! function_exists( 'zeko_freelance' ) || ! function_exists( 'zeko_freelance_page_url' ) ) {
			return null;
		}
		$count = 0;
		try {
			if ( is_callable( array( zeko_freelance()->get_db(), 'portfolio_count' ) ) ) {
				$count = (int) zeko_freelance()->get_db()->portfolio_count( $user_id );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		return array(
			'key'     => 'freelance',
			'label'   => __( 'Freelance', 'zeko-ai' ),
			'url'     => add_query_arg( 'zf_uid', (int) $user_id, zeko_freelance_page_url( 'freelance-profile' ) ),
			'summary' => array(
				sprintf(
					/* translators: %d: portfolio item count */
					_n( '%d portfolio item', '%d portfolio items', $count, 'zeko-ai' ),
					$count
				),
			),
			'stat'    => array(
				'n'     => $count,
				'label' => __( 'portfolio items', 'zeko-ai' ),
			),
		);
	}

	/**
	 * Courses card.
	 *
	 * @param int $user_id User id.
	 */
	private function courses_card( int $user_id ): ?array {
		$user = get_userdata( $user_id );
		if ( ! $user || ! function_exists( 'zeko_learn' ) ) {
			return null;
		}
		$stats = array();
		try {
			$stats = (array) zeko_learn()->get_db()->get_instructor_stats( $user_id );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return null;
		}
		if ( empty( $stats['total_courses'] ) ) {
			return null;
		}

		return array(
			'key'     => 'courses',
			'label'   => __( 'Courses', 'zeko-ai' ),
			'url'     => home_url( '/instructors/' . $user->user_nicename . '/' ),
			'summary' => array(
				sprintf(
					/* translators: %d: published course count */
					_n( '%d published course', '%d published courses', (int) $stats['total_courses'], 'zeko-ai' ),
					(int) $stats['total_courses']
				),
				sprintf(
					/* translators: %d: student count */
					_n( '%d student', '%d students', (int) ( $stats['total_students'] ?? 0 ), 'zeko-ai' ),
					(int) ( $stats['total_students'] ?? 0 )
				),
			),
			'stat'    => array(
				'n'     => (int) $stats['total_courses'],
				'label' => __( 'published courses', 'zeko-ai' ),
			),
		);
	}

	// ---------------------------------------------------------------------.
	// Rendering.
	// ---------------------------------------------------------------------.

	/**
	 * Render header.
	 *
	 * @param \WP_User $user User.
	 * @param array    $cards Cards.
	 * @param string   $base Base.
	 */
	private function render_header( \WP_User $user, array $cards, string $base ): string {
		$avatar = '';
		if ( class_exists( 'Zeko_Core_Helpers' ) ) {
			$avatar = Zeko_Core_Helpers::get_instance()->get_user_avatar( (int) $user->ID, 96 );
		} else {
			$avatar = get_avatar( (int) $user->ID, 96 );
		}

		$score = $this->presence_score( $cards );

		$html  = '<header class="zeko-ai-profile-head">';
		$html .= '<div class="zeko-ai-profile-avatar">' . $avatar . '</div>';
		$html .= '<div class="zeko-ai-profile-meta">';
		$html .= '<h1 class="zeko-ai-profile-name">' . esc_html( $user->display_name ) . '</h1>';
		if ( $user->description ) {
			$html .= '<p class="zeko-ai-profile-bio">' . esc_html( $user->description ) . '</p>';
		}
		$registered = $user->user_registered ? mysql2date( get_option( 'date_format' ), $user->user_registered ) : '';
		if ( $registered ) {
			$html .= '<p class="zeko-ai-profile-meta-line">' . sprintf(
			/* translators: %s: registration date */
				esc_html__( 'Member since %s', 'zeko-ai' ),
				esc_html( $registered )
			) . '</p>';
		}

		// Presence meter: how many of the tracked modules the member uses.
		$html .= '<div class="zeko-ai-profile-strength">';
		$html .= '<div class="zeko-ai-profile-strength-top">';
		$html .= '<span class="zeko-ai-profile-strength-label">' . esc_html( $this->presence_label( $score ) ) . '</span>';
		$html .= '<span class="zeko-ai-profile-strength-count">' . sprintf(
			/* translators: 1: active profile count, 2: tracked module count */
			esc_html__( '%1$d of %2$d profiles', 'zeko-ai' ),
			count( $cards ),
			count( self::MODULE_KEYS )
		) . '</span>';
		$html .= '</div>';
		$html .= '<div class="zeko-ai-profile-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $score . '">';
		$html .= '<div class="zeko-ai-profile-meter-bar" style="width:' . (int) $score . '%"></div>';
		$html .= '</div></div>';

		$stats = $this->render_stat_chips( $cards );
		if ( $stats ) {
			$html .= '<div class="zeko-ai-profile-stats">' . $stats . '</div>';
		}

		$rewards = $this->rewards_summary( (int) $user->ID );
		if ( ! empty( $rewards['active'] ) ) {
			$html .= '<div class="zeko-ai-profile-rewards">';
			$html .= '<span class="zeko-ai-profile-rewards-tier">' . esc_html( (string) ( $rewards['tier'] ?? '' ) ) . '</span>';
			$html .= '<span class="zeko-ai-profile-rewards-points">' . sprintf(
				/* translators: %d: reward points */
				esc_html__( '%d points', 'zeko-ai' ),
				(int) ( $rewards['points'] ?? 0 )
			) . '</span>';
			if ( (int) ( $rewards['badges'] ?? 0 ) > 0 ) {
				$html .= '<span class="zeko-ai-profile-rewards-badges">' . sprintf(
					/* translators: %d: badge count */
					esc_html__( '%d badges', 'zeko-ai' ),
					(int) ( $rewards['badges'] ?? 0 )
				) . '</span>';
			}
			if ( ! empty( $rewards['url'] ) ) {
				$html .= '<a class="zeko-ai-profile-rewards-link" href="' . esc_url( (string) $rewards['url'] ) . '">' . esc_html__( 'Rewards', 'zeko-ai' ) . '</a>';
			}
			$html .= '</div>';
		}

		$html .= '<button type="button" class="zeko-ai-profile-share" data-zeko-share="' . esc_url( $base ) . '" data-zeko-share-copied="' . esc_attr__( 'Link copied!', 'zeko-ai' ) . '">'
			. esc_html__( 'Copy link', 'zeko-ai' ) . '</button>';

		$html .= '</div></header>';

		return $html;
	}

	/**
	 * Presence score.
	 *
	 * @param array $cards Cards.
	 */
	private function presence_score( array $cards ): int {
		$total = count( self::MODULE_KEYS );
		if ( $total <= 0 ) {
			return 0;
		}
		return (int) round( ( count( $cards ) / $total ) * 100 );
	}

	/**
	 * Presence label.
	 *
	 * @param int $score Score.
	 */
	private function presence_label( int $score ): string {
		if ( $score >= 80 ) {
			return __( 'All-round', 'zeko-ai' );
		}
		if ( $score >= 60 ) {
			return __( 'Active', 'zeko-ai' );
		}
		if ( $score >= 40 ) {
			return __( 'Growing', 'zeko-ai' );
		}
		if ( $score >= 20 ) {
			return __( 'Building', 'zeko-ai' );
		}
		return __( 'Getting started', 'zeko-ai' );
	}

	/**
	 * Render stat chips.
	 *
	 * @param array $cards Cards.
	 */
	private function render_stat_chips( array $cards ): string {
		$html = '';
		foreach ( $cards as $card ) {
			if ( empty( $card['stat'] ) ) {
				continue;
			}
			$label = (string) ( $card['stat']['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$html .= '<div class="zeko-ai-profile-stat">'
				. '<strong>' . esc_html( number_format_i18n( (int) ( $card['stat']['n'] ?? 0 ) ) ) . '</strong>'
				. '<span>' . esc_html( $label ) . '</span></div>';
		}
		return $html;
	}

	/**
	 * Render tabs.
	 *
	 * @param int    $user_id User id.
	 * @param string $base Base.
	 * @param string $active Active.
	 */
	private function render_tabs( int $user_id, string $base, string $active ): string {
		$html = '<nav class="zeko-ai-profile-tabs" role="tablist" aria-label="' . esc_attr__( 'Profile sections', 'zeko-ai' ) . '">';
		foreach ( $this->tabs( $user_id ) as $tab ) {
			$html .= '<a href="' . esc_url( $tab['url'] ) . '" class="zeko-ai-profile-tab' . ( $tab['key'] === $active ? ' is-active' : '' ) . '" data-zeko-tab="' . esc_attr( $tab['key'] ) . '" role="tab" aria-selected="' . ( $tab['key'] === $active ? 'true' : 'false' ) . '">'
				. esc_html( $tab['label'] ) . '</a>';
		}
		$html .= '</nav>';
		return $html;
	}

	/**
	 * Render every tab panel into the DOM; the active one is visible, the rest
	 * carry the `hidden` attribute so tab switching needs no server round-trip
	 * when JS is available and still works (full page load) without it.
	 *
	 * @param \WP_User $user User.
	 * @param array    $cards Cards.
	 * @param string   $base Base.
	 * @param string   $active Active.
	 */
	private function render_panels( \WP_User $user, array $cards, string $base, string $active ): string {
		$html  = '<div class="zeko-ai-profile-panels">';
		$html .= $this->render_panel( $user, $cards, $base, 'overview', ( 'overview' === $active || ! isset( $cards[ $active ] ) ) );
		foreach ( $cards as $key => $card ) {
			$html .= $this->render_panel( $user, $cards, $base, (string) $key, (string) $key === $active );
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Render panel.
	 *
	 * @param \WP_User $user User.
	 * @param array    $cards Cards.
	 * @param string   $base Base.
	 * @param string   $key Key.
	 * @param bool     $active Active.
	 */
	private function render_panel( \WP_User $user, array $cards, string $base, string $key, bool $active ): string {
		$content = ( 'overview' === $key )
			? $this->render_overview( $user, $cards, $base )
			: $this->render_module_panel( $cards[ $key ] );

		return '<section class="zeko-ai-profile-panel' . ( $active ? ' is-active' : '' ) . '" data-zeko-tabpanel="' . esc_attr( $key ) . '" role="tabpanel"' . ( $active ? '' : ' hidden' ) . '>'
			. $content . '</section>';
	}

	/**
	 * Render overview.
	 *
	 * @param \WP_User $user User.
	 * @param array    $cards Cards.
	 * @param string   $base Base.
	 */
	private function render_overview( \WP_User $user, array $cards, string $base ): string {
		unset( $base );
		$html = '<div class="zeko-ai-profile-overview">';

		if ( empty( $cards ) ) {
			$html .= '<p class="zeko-ai-profile-empty">' . esc_html__( 'This member has no public module profiles yet.', 'zeko-ai' ) . '</p>';
		} else {
			foreach ( $cards as $card ) {
				$html .= $this->render_card( $card );
			}
		}

		$html .= $this->render_recent_activity( (int) $user->ID );

		if ( get_current_user_id() === (int) $user->ID ) {
			$html .= $this->render_checklist( (int) $user->ID );
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Render recent activity.
	 *
	 * @param int $user_id User id.
	 */
	private function render_recent_activity( int $user_id ): string {
		$activity = $this->recent_activity( $user_id, 8 );
		if ( empty( $activity ) ) {
			return '';
		}

		$html  = '<section class="zeko-ai-profile-activity">';
		$html .= '<h3 class="zeko-ai-profile-activity-title">' . esc_html__( 'Recently active', 'zeko-ai' ) . '</h3>';
		$html .= '<ul class="zeko-ai-profile-activity-list">';
		foreach ( $activity as $row ) {
			$label = (string) ( $row['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$date  = (string) ( $row['date'] ?? '' );
			$ago   = '' !== $date
				? human_time_diff( strtotime( $date ), time() ) . ' ' . __( 'ago', 'zeko-ai' )
				: '';
			$html .= '<li class="zeko-ai-profile-activity-item">';
			$html .= '<span class="zeko-ai-profile-activity-label">' . esc_html( $label ) . '</span>';
			if ( ! empty( $row['meta'] ) ) {
				$html .= '<span class="zeko-ai-profile-activity-meta">' . esc_html( mb_substr( (string) $row['meta'], 0, 80 ) ) . '</span>';
			}
			if ( '' !== $ago ) {
				$html .= '<span class="zeko-ai-profile-activity-time">' . esc_html( $ago ) . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ul></section>';

		return $html;
	}

	/**
	 * Render checklist.
	 *
	 * @param int $user_id User id.
	 */
	private function render_checklist( int $user_id ): string {
		$items = $this->profile_checklist( $user_id );
		if ( empty( $items ) ) {
			return '';
		}

		$done = 0;
		foreach ( $items as $item ) {
			if ( ! empty( $item['done'] ) ) {
				++$done;
			}
		}
		$all_done = count( $items ) === $done;

		$html  = '<section class="zeko-ai-profile-checklist">';
		$html .= '<h3 class="zeko-ai-profile-checklist-title">'
			. esc_html( $all_done ? __( 'Your profile is complete', 'zeko-ai' ) : __( 'Complete your profile', 'zeko-ai' ) )
			. '</h3>';
		$html .= '<ul class="zeko-ai-profile-checklist-items">';
		foreach ( $items as $item ) {
			$item_done = ! empty( $item['done'] );
			$html     .= '<li class="zeko-ai-profile-checklist-item' . ( $item_done ? ' is-done' : '' ) . '">';
			$html     .= '<span class="zeko-ai-profile-checklist-state" aria-hidden="true">' . ( $item_done ? '&#10003;' : '&bull;' ) . '</span>';
			$html     .= '<span class="zeko-ai-profile-checklist-text">';
			$html     .= '<strong>' . esc_html( (string) ( $item['label'] ?? '' ) ) . '</strong>';
			if ( ! empty( $item['description'] ) ) {
				$html .= '<span class="zeko-ai-profile-checklist-desc">' . esc_html( (string) $item['description'] ) . '</span>';
			}
			$html .= '</span>';
			if ( ! $item_done && ! empty( $item['url'] ) ) {
				$html .= '<a class="btn zeko-ai-profile-checklist-link" href="' . esc_url( (string) $item['url'] ) . '">' . esc_html__( 'Get started', 'zeko-ai' ) . '</a>';
			}
			$html .= '</li>';
		}
		$html .= '</ul></section>';

		return $html;
	}

	/**
	 * Render module panel.
	 *
	 * @param array $card Card.
	 */
	private function render_module_panel( array $card ): string {
		$html  = '<div class="zeko-ai-profile-panel">';
		$html .= $this->render_card( $card, true );
		$html .= '</div>';
		return $html;
	}

	/**
	 * Render card.
	 *
	 * @param array $card Card.
	 * @param bool  $highlight Highlight.
	 */
	private function render_card( array $card, bool $highlight = false ): string {
		$summary = '';
		foreach ( (array) ( $card['summary'] ?? array() ) as $line ) {
			$summary .= '<li>' . esc_html( $line ) . '</li>';
		}

		$html  = '<article class="zeko-ai-profile-card' . ( $highlight ? ' zeko-ai-profile-card--featured' : '' ) . '">';
		$html .= '<h2 class="zeko-ai-profile-card-title">' . esc_html( (string) ( $card['label'] ?? '' ) ) . '</h2>';
		if ( $summary ) {
			$html .= '<ul class="zeko-ai-profile-card-stats">' . $summary . '</ul>';
		}
		if ( ! empty( $card['url'] ) ) {
			$html .= '<a class="btn zeko-ai-profile-card-link" href="' . esc_url( $card['url'] ) . '">'
				. sprintf(
					/* translators: %s: module label, e.g. Q&A */
					esc_html__( 'Open your %s profile', 'zeko-ai' ),
					esc_html( (string) ( $card['label'] ?? '' ) )
				) . '</a>';
		}
		$html .= '</article>';
		return $html;
	}

	// ---------------------------------------------------------------------.
	// Agent deep links.
	// ---------------------------------------------------------------------.

	/**
	 * Append the member's module-profile links to the agent's "profile"
	 * intent actions so the chat can deep-link each profile directly.
	 *
	 * @return array
	 * @param array  $actions Existing action list.
	 * @param string $intent Detected intent.
	 * @param int    $user_id Target user id.
	 */
	public function agent_profile_actions( array $actions, string $intent, int $user_id ): array {
		if ( 'profile' !== $intent || $user_id <= 0 ) {
			return $actions;
		}
		foreach ( $this->module_cards( $user_id ) as $card ) {
			$actions[] = array(
				'key'   => 'profile_' . $card['key'],
				'label' => $card['label'],
				'url'   => $card['url'],
			);
		}
		return $actions;
	}
}
