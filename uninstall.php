<?php
/**
 * Uninstall script for Zeko AI.
 *
 * Runs when the plugin is deleted via WordPress admin.
 * Cleans up AI-owned tables, user meta, plugin options, and scheduled
 * cron events. Shared ecosystem data (activity log, profile slugs) is kept.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$prefix = $wpdb->prefix;

// Drop AI-owned tables (shared ecosystem tables are never touched).
$tables = array(
	$prefix . 'zeko_ai_conversations',
	$prefix . 'zeko_ai_messages',
	$prefix . 'zeko_ai_moderation',
	$prefix . 'zeko_ai_recommendations',
	$prefix . 'zeko_ai_usage',
	$prefix . 'zeko_ai_knowledge',
	$prefix . 'zeko_ai_knowledge_votes',
	$prefix . 'zeko_ai_agent_queries',
	$prefix . 'zeko_ai_memory',
	$prefix . 'zeko_ai_provider_log',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Delete AI-owned user meta (radar state, auto-learn counters). Never a bare
// `zeko_%` wildcard, which would wipe other ecosystem modules' meta.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	'DELETE FROM ' . $wpdb->usermeta . " WHERE meta_key LIKE 'zeko_ai\_%'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);

// Delete plugin options.
$options = array(
	'zeko_ai_settings',
	'zeko_ai_db_version',
	'zeko_ai_menu_version',
	'zeko_ai_pages_version',
	'zeko_ai_seeded',
	'zeko_ai_seed_version',
	'zeko_ai_cloud_site_token',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear all scheduled cron events.
$cron_hooks = array(
	'zeko_ai_agent_maintenance',
	'zeko_ai_radar_digest',
);

foreach ( $cron_hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}
