<?php
/**
 * Plugin Name:       Zeko AI
 * Plugin URI:        https://ozconsultz.com/zeko-ai
 * Description:       AI layer for the Zeko ecosystem: assistant/chatbot, content generation, unified search, recommendations, content moderation, and usage analytics. Provider-agnostic (Mock / OpenAI / Anthropic / OpenRouter / Gemini / Groq / DeepSeek).
 * Version:           0.5.1
 * Author:            Zeko Team
 * Author URI:        https://ozconsultz.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zeko-ai
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Tested up to:      7.1.2
 *
 * @package Zeko_ZEKO_AI
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ZEKO_AI_VERSION' ) ) {
	define( 'ZEKO_AI_VERSION', '0.5.1' );
}

if ( ! defined( 'ZEKO_AI_PLUGIN_PATH' ) ) {
	define( 'ZEKO_AI_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'ZEKO_AI_PLUGIN_URL' ) ) {
	define( 'ZEKO_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'ZEKO_AI_URL' ) ) {
	define( 'ZEKO_AI_URL', ZEKO_AI_PLUGIN_URL );
}

if ( ! defined( 'ZEKO_AI_PLUGIN_BASENAME' ) ) {
	define( 'ZEKO_AI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'ZEKO_AI_DB_VERSION' ) ) {
	define( 'ZEKO_AI_DB_VERSION', '0.5.1' );
}

require_once ZEKO_AI_PLUGIN_PATH . 'includes/db/class-zeko-ai-db.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/providers/class-zeko-ai-provider.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/providers/class-zeko-ai-mock-provider.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/providers/class-zeko-ai-openai-provider.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/providers/class-zeko-ai-anthropic-provider.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/providers/class-zeko-ai-openai-compat-provider.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/providers/class-zeko-ai-openrouter-provider.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/providers/class-zeko-ai-gemini-provider.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/providers/class-zeko-ai-groq-provider.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/providers/class-zeko-ai-deepseek-provider.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/providers/class-zeko-ai-cloud-provider.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/providers/class-zeko-ai-agent-provider.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/providers/class-zeko-ai-provider-facet.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/providers/class-zeko-ai-provider-factory.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-settings.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-web-search.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-agent-seeds.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-memory.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-radar.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-ajax.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-assistant.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-content.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-writer-ui.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-search.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-recommendations.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-moderation.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-analytics.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-ecosystem.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-rest.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-profile.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/admin/class-zeko-ai-admin.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/public/class-zeko-ai-shortcodes.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/integrations/class-zeko-ai-integration.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/integrations/class-zeko-ai-integration-jobs.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/integrations/class-zeko-ai-integration-business.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/integrations/class-zeko-ai-integration-learn.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/integrations/class-zeko-ai-integration-qa.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/integrations/class-zeko-ai-integration-shop.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/integrations/class-zeko-ai-integration-freelance.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/integrations/class-zeko-ai-integration-mentor.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/integrations/class-zeko-ai-integration-love.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/integrations/class-zeko-ai-integration-misc.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/integrations/class-zeko-ai-integration-site.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai-rate-limiter.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/class-zeko-ai.php';
require_once ZEKO_AI_PLUGIN_PATH . 'includes/privacy/class-zeko-ai-privacy.php';

/**
 * Zeko ai init.
 */
function zeko_ai_init() {
	load_plugin_textdomain( 'zeko-ai', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	$instance = Zeko_AI::instance();

	// One-time schema upgrade (new installs, or upgrades after a version bump).
	$installed = get_option( 'zeko_ai_db_version', '0' );
	if ( version_compare( $installed, ZEKO_AI_DB_VERSION, '<' ) ) {
		$instance->get_db()->create_tables();
		update_option( 'zeko_ai_db_version', ZEKO_AI_DB_VERSION );
	}

	return $instance;
}
add_action( 'plugins_loaded', 'zeko_ai_init' );

/**
 * Zeko ai.
 */
function zeko_ai() {
	return Zeko_AI::instance();
}

/**
 * Zeko ai activate.
 */
function zeko_ai_activate() {
	require_once ZEKO_AI_PLUGIN_PATH . 'includes/db/class-zeko-ai-db.php';
	$db = new Zeko_AI_DB();
	$db->create_tables();

	zeko_ai_create_shortcode_pages();
	flush_rewrite_rules();
}

/**
 * Zeko ai deactivate.
 */
function zeko_ai_deactivate() {
	foreach ( array( 'zeko_ai_agent_maintenance', 'zeko_ai_radar_digest' ) as $hook ) {
		$ts = wp_next_scheduled( $hook );
		if ( $ts ) {
			wp_unschedule_event( $ts, $hook );
		}
	}
}

/**
 * Zeko ai uninstall.
 */
function zeko_ai_uninstall() {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		return;
	}

	require_once ZEKO_AI_PLUGIN_PATH . 'includes/db/class-zeko-ai-db.php';
	$db = new Zeko_AI_DB();
	$db->drop_tables();

	// Clear the scheduled crons.
	foreach ( array( 'zeko_ai_agent_maintenance', 'zeko_ai_radar_digest' ) as $hook ) {
		$ts = wp_next_scheduled( $hook );
		if ( $ts ) {
			wp_unschedule_event( $ts, $hook );
		}
	}

	// Remove plugin options.
	$options = array(
		'zeko_ai_db_version',
		'zeko_ai_settings',
		'zeko_ai_pages_version',
		'zeko_ai_menu_version',
		'zeko_ai_seeded',
		'zeko_ai_seed_version',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Remove shortcode pages (only pages this plugin created — never any.
	// arbitrary page matched by slug alone).
	if ( function_exists( 'zeko_delete_plugin_pages' ) ) {
		zeko_delete_plugin_pages( 'ai', array( 'ai-assistant', 'ai-search', 'ai-recommendations' ) );
	}

	// Remove AI-specific user meta only (never a blanket zeko_% wipe — core.
	// and other modules share that namespace, e.g. zeko_profile_slug).
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'zeko_ai_%'" );
}

/**
 * Zeko ai create shortcode pages.
 */
function zeko_ai_create_shortcode_pages() {
	$pages = array(
		'ai-assistant'       => array(
			'title'   => __( 'AI Assistant', 'zeko-ai' ),
			'content' => '[zeko_ai_assistant]',
		),
		'ai-search'          => array(
			'title'   => __( 'AI Search', 'zeko-ai' ),
			'content' => '[zeko_ai_search]',
		),
		'ai-recommendations' => array(
			'title'   => __( 'AI Recommendations', 'zeko-ai' ),
			'content' => '[zeko_ai_recommendations]',
		),
	);

	foreach ( $pages as $slug => $page ) {
		$existing = class_exists( 'Zeko_Core_Helpers' )
			? Zeko_Core_Helpers::get_instance()->get_page_by_slug( $slug )
			: get_page_by_path( $slug );
		if ( ! $existing ) {
			$result = wp_insert_post(
				array(
					'post_title'   => $page['title'],
					'post_content' => $page['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_name'    => $slug,
				)
			);
			if ( is_wp_error( $result ) ) {
				error_log( 'Zeko AI: Failed to create page "' . $slug . '": ' . $result->get_error_message() );
				continue;
			}
			if ( function_exists( 'zeko_mark_plugin_page' ) ) {
				zeko_mark_plugin_page( $result, 'ai' );
			}
			$existing = get_post( $result );
		}

		if ( $existing ) {
			update_option( 'zeko_ai_' . $slug . '_page_id', (int) $existing->ID );
		}
	}
}

/**
 * Resolve the permalink for a Zeko AI page by its slug.
 * Delegates to the ecosystem page-URL registry (Zeko Core) when present;
 * the local resolution is the fallback so ai still works without it.
 *
 * @return string
 * @param string $slug Page slug (e.g. 'ai-assistant').
 */
function zeko_ai_page_url( string $slug ): string {
	if ( class_exists( 'Zeko_Core_Helpers' ) && method_exists( 'Zeko_Core_Helpers', 'get_page_url' ) ) {
		return Zeko_Core_Helpers::get_instance()->get_page_url( 'ai', $slug );
	}

	$page_id = (int) get_option( 'zeko_ai_' . $slug . '_page_id', 0 );

	if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
		return get_permalink( $page_id );
	}

	$page = class_exists( 'Zeko_Core_Helpers' )
		? Zeko_Core_Helpers::get_instance()->get_page_by_slug( $slug )
		: get_page_by_path( $slug );
	if ( $page ) {
		update_option( 'zeko_ai_' . $slug . '_page_id', (int) $page->ID );
		return get_permalink( $page );
	}

	return home_url( '/' . $slug . '/' );
}

/**
 * Zeko ai maybe create pages.
 */
function zeko_ai_maybe_create_pages() {
	$created = get_option( 'zeko_ai_pages_version', '0' );
	if ( version_compare( $created, ZEKO_AI_VERSION, '<' ) ) {
		zeko_ai_create_shortcode_pages();
		update_option( 'zeko_ai_pages_version', ZEKO_AI_VERSION );
	}
}

/**
 * Zeko AI settings.
 * Stored in the zeko_ai_settings option, filterable via 'zeko_ai_settings'.
 * Falls back to defaults when unset.
 *
 * @return array
 */
function zeko_ai_get_settings(): array {
	$settings = get_option( 'zeko_ai_settings', array() );

	$defaults = array(
		'provider'                => 'mock',
		'openai_key'              => '',
		'openai_model'            => 'gpt-4o-mini',
		'openai_base_url'         => 'https://api.openai.com/v1',
		'anthropic_key'           => '',
		'anthropic_model'         => 'claude-3-5-haiku-latest',
		'openrouter_key'          => '',
		'openrouter_model'        => 'openai/gpt-4o-mini',
		'openrouter_base_url'     => 'https://openrouter.ai/api/v1',
		'gemini_key'              => '',
		'gemini_model'            => 'gemini-2.0-flash',
		'gemini_base_url'         => 'https://generativelanguage.googleapis.com/v1beta/openai',
		'groq_key'                => '',
		'groq_model'              => 'llama-3.3-70b-versatile',
		'groq_base_url'           => 'https://api.groq.com/openai/v1',
		'deepseek_key'            => '',
		'deepseek_model'          => 'deepseek-chat',
		'deepseek_base_url'       => 'https://api.deepseek.com/v1',
		'cloud_model'             => 'zeko-cloud',
		'moderation_enabled'      => 1,
		'moderation_threshold'    => 0.7,
		'feature_assistant'       => 1,
		'feature_content'         => 1,
		'feature_search'          => 1,
		'feature_recommendations' => 1,
		'feature_moderation'      => 1,
		'feature_analytics'       => 0,
		'floating_widget'         => 1,
		'streaming_enabled'       => 1,
		'agent_world_knowledge'   => 0,
		'agent_learning'          => 1,
		'agent_auto_learn'        => 0,
		'agent_email_alerts'      => 0,
		'agent_radar_digest'      => 0,
		'web_search_enabled'      => 0,
		'web_search_provider'     => 'duckduckgo',
		'google_search_api_key'   => '',
		'google_search_engine_id' => '',
		'bing_search_key'         => '',
		'brave_search_key'        => '',
		'serper_search_key'       => '',
		'web_search_max_results'  => 5,
	);

	return apply_filters( 'zeko_ai_settings', wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults ) );
}

register_activation_hook( __FILE__, 'zeko_ai_activate' );
register_deactivation_hook( __FILE__, 'zeko_ai_deactivate' );
register_uninstall_hook( __FILE__, 'zeko_ai_uninstall' );

// The radar digest uses a 'weekly' recurrence that WordPress does not ship.
// with; register it so the scheduled event is valid and actually runs.
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'zeko-ai' ),
			);
		}
		return $schedules;
	}
);
