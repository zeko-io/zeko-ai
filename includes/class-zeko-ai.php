<?php
/**
 * Zeko AI main class.
 *
 * Owns the module lifecycle: DB layer, provider factory, feature services
 * (assistant, content, search, recommendations, moderation, analytics),
 * ecosystem integration, admin page and shortcodes.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI. */
class Zeko_AI {

	/**
	 * Instance.
	 *
	 * @var ?Zeko_AI Instance.
	 */
	private static ?Zeko_AI $instance = null;

	/**
	 * Db.
	 *
	 * @var ?Zeko_AI_DB Db.
	 */
	private ?Zeko_AI_DB $db = null;
	/**
	 * Provider.
	 *
	 * @var ?Zeko_AI_Provider Provider.
	 */
	private ?Zeko_AI_Provider $provider = null;
	/**
	 * Writer.
	 *
	 * @var ?Zeko_AI_Provider Writer.
	 */
	private ?Zeko_AI_Provider $writer = null;

	/**
	 * Assistant.
	 *
	 * @var ?Zeko_AI_Assistant Assistant.
	 */
	private ?Zeko_AI_Assistant $assistant = null;
	/**
	 * Content.
	 *
	 * @var ?Zeko_AI_Content Content.
	 */
	private ?Zeko_AI_Content $content = null;
	/**
	 * Search.
	 *
	 * @var ?Zeko_AI_Search Search.
	 */
	private ?Zeko_AI_Search $search = null;
	/**
	 * Recommendations.
	 *
	 * @var ?Zeko_AI_Recommendations Recommendations.
	 */
	private ?Zeko_AI_Recommendations $recommendations = null;
	/**
	 * Moderation.
	 *
	 * @var ?Zeko_AI_Moderation Moderation.
	 */
	private ?Zeko_AI_Moderation $moderation = null;
	/**
	 * Analytics.
	 *
	 * @var ?Zeko_AI_Analytics Analytics.
	 */
	private ?Zeko_AI_Analytics $analytics = null;
	/**
	 * Ecosystem.
	 *
	 * @var ?Zeko_AI_Ecosystem Ecosystem.
	 */
	private ?Zeko_AI_Ecosystem $ecosystem = null;
	/**
	 * Shortcodes.
	 *
	 * @var ?Zeko_AI_Shortcodes Shortcodes.
	 */
	private ?Zeko_AI_Shortcodes $shortcodes = null;
	/**
	 * Ajax.
	 *
	 * @var ?Zeko_AI_Ajax Ajax.
	 */
	private ?Zeko_AI_Ajax $ajax = null;
	/**
	 * Memory.
	 *
	 * @var ?Zeko_AI_Memory Memory.
	 */
	private ?Zeko_AI_Memory $memory = null;
	/**
	 * Radar.
	 *
	 * @var ?Zeko_AI_Radar Radar.
	 */
	private ?Zeko_AI_Radar $radar = null;
	/**
	 * Rest.
	 *
	 * @var ?Zeko_AI_REST Rest.
	 */
	private ?Zeko_AI_REST $rest = null;
	/**
	 * Profile.
	 *
	 * @var ?Zeko_AI_Profile Profile.
	 */
	private ?Zeko_AI_Profile $profile = null;
	/**
	 * Limiter.
	 *
	 * @var ?Zeko_AI_Rate_Limiter Limiter.
	 */
	private ?Zeko_AI_Rate_Limiter $limiter = null;

	/**
	 * Instance.
	 */
	public static function instance(): Zeko_AI {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		$this->db      = new Zeko_AI_DB();
		$this->limiter = new Zeko_AI_Rate_Limiter();

		add_action( 'init', array( $this, 'init_components' ), 5 );
	}

	/**
	 * Init components.
	 */
	public function init_components(): void {
		$this->ensure_schema();
		zeko_ai_maybe_create_pages();

		// Feature services.
		$this->assistant       = new Zeko_AI_Assistant( $this->db );
		$this->content         = new Zeko_AI_Content( $this->db );
		$this->search          = new Zeko_AI_Search( $this->db );
		$this->recommendations = new Zeko_AI_Recommendations( $this->db );
		$this->moderation      = new Zeko_AI_Moderation( $this->db );
		$this->analytics       = new Zeko_AI_Analytics( $this->db );
		$this->memory          = new Zeko_AI_Memory( $this->db );
		$this->radar           = new Zeko_AI_Radar( $this->db );
		$this->rest            = new Zeko_AI_REST( $this->db );
		$this->profile         = new Zeko_AI_Profile();

		// Cross-module integrations (registered only when their plugin lives).
		$this->load_integrations();

		// AJAX + public shortcodes + ecosystem.
		$this->ajax       = new Zeko_AI_Ajax( $this->db );
		$this->shortcodes = new Zeko_AI_Shortcodes( $this->db );
		$this->ecosystem  = new Zeko_AI_Ecosystem( $this->db );

		// Admin.
		if ( is_admin() ) {
			new Zeko_AI_Admin( $this->db );
		}

		// Agent self-building + maintenance.
		add_action( 'admin_init', array( $this, 'maybe_seed_knowledge' ), 20 );
		if ( ! wp_next_scheduled( 'zeko_ai_agent_maintenance' ) ) {
			wp_schedule_event( time(), 'hourly', 'zeko_ai_agent_maintenance' );
		}
		add_action( 'zeko_ai_agent_maintenance', array( $this, 'maybe_maintain_agent' ) );

		// Weekly radar digest.
		if ( ! wp_next_scheduled( 'zeko_ai_radar_digest' ) ) {
			wp_schedule_event( time(), 'weekly', 'zeko_ai_radar_digest' );
		}
		add_action( 'zeko_ai_radar_digest', array( $this->radar, 'send_digests' ) );

		// Privacy: forget remembered facts when a member is deleted.
		add_action( 'delete_user', array( $this, 'purge_user_data' ), 10, 1 );
	}

	/**
	 * Forget per-user AI data (conversations/messages, moderation verdicts,
	 * recommendations, usage, self-training log, remembered facts) when an
	 * account is deleted.
	 *
	 * @param int $user_id User id.
	 */
	public function purge_user_data( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// Messages live under conversations (they carry no user_id of their.
		// own), so resolve the user's conversation ids first and purge both.
		$conversation_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$this->db->get_table_conversations()} WHERE user_id = %d", $user_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $conversation_ids ) {
			$ids          = array_map( 'intval', $conversation_ids );
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->get_table_messages()} WHERE conversation_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->get_table_conversations()} WHERE user_id = %d", $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->get_table_moderation()} WHERE user_id = %d", $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->get_table_recommendations()} WHERE user_id = %d", $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->get_table_usage()} WHERE user_id = %d", $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->get_table_knowledge()} WHERE user_id = %d", $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->get_table_knowledge_votes()} WHERE user_id = %d", $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->get_table_agent_queries()} WHERE user_id = %d", $user_id ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$this->db->delete_user_memory( $user_id );
	}

	/**
	 * Instantiate every cross-module integration. Each guards its own
	 * dependencies, so modules that are not installed are simply skipped.
	 */
	private function load_integrations(): void {
		foreach ( array(
			'jobs'      => 'Zeko_AI_Integration_Jobs',
			'business'  => 'Zeko_AI_Integration_Business',
			'learn'     => 'Zeko_AI_Integration_Learn',
			'qa'        => 'Zeko_AI_Integration_QA',
			'shop'      => 'Zeko_AI_Integration_Shop',
			'freelance' => 'Zeko_AI_Integration_Freelance',
			'mentor'    => 'Zeko_AI_Integration_Mentor',
			'love'      => 'Zeko_AI_Integration_Love',
			'misc'      => 'Zeko_AI_Integration_Misc',
			'site'      => 'Zeko_AI_Integration_Site',
		) as $file => $class ) {
			if ( class_exists( $class ) ) {
				new $class();
			}
		}
	}

	/**
	 * Create/upgrade tables when the stored schema version is behind the
	 * code version (idempotent, mirrors the plugins_loaded hook).
	 */
	public function ensure_schema(): void {
		$installed = get_option( 'zeko_ai_db_version', '0' );
		if ( version_compare( $installed, ZEKO_AI_DB_VERSION, '<' ) ) {
			$this->db->create_tables();
			update_option( 'zeko_ai_db_version', ZEKO_AI_DB_VERSION );
		}
	}

	// ---------------------------------------------------------------------.
	// Accessors.
	// ---------------------------------------------------------------------.

	/**
	 * Db.
	 */
	public function get_db(): Zeko_AI_DB {
		return $this->db;
	}

	/**
	 * Settings.
	 */
	public function get_settings(): Zeko_AI_Settings {
		// Rebuilt on every call so tests and admin saves always see the.
		// current option value (WP caches the option read itself).
		return new Zeko_AI_Settings( zeko_ai_get_settings() );
	}

	/**
	 * The active provider (cached for the request). Wrapped in a failover
	 * facet so the configured provider can fall through to other keyed
	 * providers, then the keyless community agent, then Mock — the site
	 * never breaks when an upstream API is down or unconfigured.
	 */
	public function get_provider(): Zeko_AI_Provider {
		if ( null === $this->provider ) {
			$this->provider = Zeko_AI_Provider_Factory::create_failover( $this->get_settings()->all() );
		}
		return $this->provider;
	}

	/**
	 * The content-generation provider ("writer"), cached for the request.
	 * Built from a content-favored chain so a keyed LLM powers writing even
	 * when the chat assistant is set to the keyless community agent; offline
	 * sites naturally land on the Agent or Mock fallbacks. Kept separate from
	 * get_provider() because chat and writing want a different precedence.
	 */
	public function get_writer(): Zeko_AI_Provider {
		if ( null === $this->writer ) {
			$this->writer = Zeko_AI_Provider_Factory::create_content_failover( $this->get_settings()->all() );
		}
		return $this->writer;
	}

	/**
	 * Drop the cached providers so the next get_provider()/get_writer() call
	 * rebuilds them from the current settings (needed by tests after a
	 * settings change).
	 */
	public function reset_provider(): void {
		$this->provider = null;
		$this->writer   = null;
	}

	/**
	 * The self-training community agent, built directly so it is always
	 * available regardless of the active chat provider. No API key needed.
	 */
	public function get_agent(): Zeko_AI_Agent_Provider {
		$settings             = $this->get_settings()->all();
		$settings['provider'] = 'agent';
		return new Zeko_AI_Agent_Provider( $settings, $this->db );
	}

	/**
	 * Assistant.
	 */
	public function get_assistant(): Zeko_AI_Assistant {
		if ( null === $this->assistant ) {
			$this->assistant = new Zeko_AI_Assistant( $this->db );
		}
		return $this->assistant;
	}

	/**
	 * Content.
	 */
	public function get_content(): Zeko_AI_Content {
		if ( null === $this->content ) {
			$this->content = new Zeko_AI_Content( $this->db );
		}
		return $this->content;
	}

	/**
	 * Search.
	 */
	public function get_search(): Zeko_AI_Search {
		if ( null === $this->search ) {
			$this->search = new Zeko_AI_Search( $this->db );
		}
		return $this->search;
	}

	/**
	 * Recommendations.
	 */
	public function get_recommendations(): Zeko_AI_Recommendations {
		if ( null === $this->recommendations ) {
			$this->recommendations = new Zeko_AI_Recommendations( $this->db );
		}
		return $this->recommendations;
	}

	/**
	 * Moderation.
	 */
	public function get_moderation(): Zeko_AI_Moderation {
		if ( null === $this->moderation ) {
			$this->moderation = new Zeko_AI_Moderation( $this->db );
		}
		return $this->moderation;
	}

	/**
	 * Analytics.
	 */
	public function get_analytics(): Zeko_AI_Analytics {
		if ( null === $this->analytics ) {
			$this->analytics = new Zeko_AI_Analytics( $this->db );
		}
		return $this->analytics;
	}

	/**
	 * Ecosystem.
	 */
	public function get_ecosystem(): Zeko_AI_Ecosystem {
		if ( null === $this->ecosystem ) {
			$this->ecosystem = new Zeko_AI_Ecosystem( $this->db );
		}
		return $this->ecosystem;
	}

	/**
	 * Shortcodes.
	 */
	public function get_shortcodes(): ?Zeko_AI_Shortcodes {
		return $this->shortcodes;
	}

	/**
	 * Ajax.
	 */
	public function get_ajax(): ?Zeko_AI_Ajax {
		return $this->ajax;
	}

	/**
	 * Memory.
	 */
	public function get_memory(): Zeko_AI_Memory {
		if ( null === $this->memory ) {
			$this->memory = new Zeko_AI_Memory( $this->db );
		}
		return $this->memory;
	}

	/**
	 * Radar.
	 */
	public function get_radar(): Zeko_AI_Radar {
		if ( null === $this->radar ) {
			$this->radar = new Zeko_AI_Radar( $this->db );
		}
		return $this->radar;
	}

	/**
	 * Rest.
	 */
	public function get_rest(): ?Zeko_AI_REST {
		return $this->rest;
	}

	/**
	 * Profile.
	 */
	public function get_profile(): Zeko_AI_Profile {
		if ( null === $this->profile ) {
			$this->profile = new Zeko_AI_Profile();
		}
		return $this->profile;
	}

	/**
	 * Limiter.
	 */
	public function get_limiter(): Zeko_AI_Rate_Limiter {
		return $this->limiter;
	}

	/**
	 * Record one usage row for the active provider call.
	 *
	 * @return int
	 * @param int    $user_id * @param string $feature.
	 * @param string $feature Feature.
	 * @param array  $result Result from a provider chat/complete call.
	 * @param float  $cost * @return int.
	 */
	public function record_usage( int $user_id, string $feature, array $result, float $cost = 0.0 ): int {
		// Providers rarely report a real cost; fall back to the token-based.
		// estimate so the admin analytics always show a meaningful number.
		if ( $cost <= 0.0 ) {
			$cost = $this->analytics->estimate_cost( $result );
		}

		return $this->db->insert_usage(
			array(
				'user_id'    => $user_id,
				'feature'    => $feature,
				'provider'   => isset( $result['provider'] ) ? (string) $result['provider'] : $this->get_provider()->name(),
				'model'      => isset( $result['model'] ) ? (string) $result['model'] : $this->get_provider()->model(),
				'tokens_in'  => (int) ( $result['tokens_in'] ?? 0 ),
				'tokens_out' => (int) ( $result['tokens_out'] ?? 0 ),
				'cost'       => $cost,
			)
		);
	}

	// ---------------------------------------------------------------------.
	// Agent self-building + admin alerts.
	// ---------------------------------------------------------------------.

	/**
	 * Throttled email to the site admin. At most one email per event per hour.
	 * Skipped entirely during tests (no real mail should be sent).
	 *
	 * @return bool Whether a mail was sent.
	 * @param string $subject Email subject.
	 * @param string $body Plain-text body.
	 * @param string $event Throttle key (also used in the subject when empty).
	 */
	public function notify_admin( string $subject, string $body, string $event = '' ): bool {
		if ( defined( 'PHPUNIT_RUNNING' ) && PHPUNIT_RUNNING ) {
			return false;
		}
		if ( empty( $this->get_settings()->all()['agent_email_alerts'] ) ) {
			return false;
		}

		$event = $event ?: sanitize_key( $subject );

		if ( get_transient( "zeko_ai_email_{$event}" ) ) {
			return false;
		}

		set_transient( "zeko_ai_email_{$event}", 1, HOUR_IN_SECONDS );

		$to        = get_option( 'admin_email' );
		$headers   = class_exists( 'Zeko_Core_Emails' ) ? array( 'Content-Type: text/html; charset=UTF-8' ) : array( 'Content-Type: text/plain; charset=UTF-8' );
		$body_html = $body;

		if ( class_exists( 'Zeko_Core_Emails' ) ) {
			$emails    = Zeko_Core_Emails::get_instance();
			$body_html = $emails->wrap(
				$emails->plain_to_html( $body ),
				array(
					'brand_name' => 'Zeko AI',
					'tagline'    => __( 'The assistant that knows the whole site', 'zeko-ai' ),
					'preheader'  => $subject,
				)
			);
		}

		return wp_mail( $to, '[Zeko AI] ' . $subject, $body_html, $headers );
	}

	/**
	 * Seed the agent's starter knowledge when agent learning is enabled.
	 * Reruns are safe: seed_knowledge() skips existing exact questions. The
	 * seed version option forces a reseed whenever the starter set grows, so
	 * upgraded installs pick up newly added FAQ rows too.
	 */
	public function maybe_seed_knowledge(): void {
		$settings = $this->get_settings()->all();
		if ( empty( $settings['agent_learning'] ) ) {
			return;
		}

		$seed_version = (int) get_option( 'zeko_ai_seed_version', 0 );
		if ( $seed_version >= Zeko_AI_Agent_Seeds::SEED_VERSION && get_option( 'zeko_ai_seeded' ) ) {
			return;
		}

		$this->db->seed_knowledge( Zeko_AI_Agent_Seeds::get() );

		// Stamp the attempt (not just inserts) so a site where every seed.
		// already exists still records the version bump and stops re-running.
		// the seeding check on every admin page load.
		update_option( 'zeko_ai_seeded', time() );
		update_option( 'zeko_ai_seed_version', Zeko_AI_Agent_Seeds::SEED_VERSION );
	}

	/**
	 * Hourly self-repair: heal duplicated/blank knowledge rows, recreate
	 * missing tables, and surface a summary to the admin when something
	 * actually broke (throttled to once per day per event).
	 */
	public function maybe_maintain_agent(): void {
		$report = $this->db->heal_agent_data();
		$pruned = $this->db->prune_knowledge();

		$fixed = $report['fixed_keys'] + $report['removed_empty'] + $report['removed_duplicates'];
		if ( $fixed > 0 || $pruned > 0 ) {
			$this->notify_admin(
				'Agent self-repair applied',
				sprintf(
					"Zeko AI fixed the following while maintaining agent data:\n- fixed keys: %d\n- removed empty rows: %d\n- removed duplicates: %d\n- pruned low-rated auto-learned rows: %d",
					$report['fixed_keys'],
					$report['removed_empty'],
					$report['removed_duplicates'],
					$pruned
				),
				'maintenance_repair'
			);
		}
	}
}
