<?php
/**
 * Zeko AI — WordPress personal-data exporter and eraser.
 *
 * Registers with Tools > Export Personal Data / Erase Personal Data so site
 * owners can fulfil data-protection requests for AI conversations, messages,
 * remembered facts, usage, recommendations and agent query logs, and
 * contributes its age-based retention policy to the shared Zeko Core
 * retention registry (filter zeko_core_privacy_retention_tables).
 *
 * Table schemas byte-verified 2026-09-23 against class-zeko-ai-db.php DDL:
 *   {prefix}zeko_ai_conversations  user_id / created_at / title
 *   {prefix}zeko_ai_messages       conversation_id (no user_id) / created_at / content LONGTEXT
 *   {prefix}zeko_ai_memory         user_id / created_at / fact_value
 *   {prefix}zeko_ai_usage          user_id / created_at / feature
 *   {prefix}zeko_ai_provider_log   NO user linkage / created_at / detail
 *   {prefix}zeko_ai_recommendations user_id / created_at / reason
 *   {prefix}zeko_ai_agent_queries  user_id / created_at / query
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the exporter, eraser and retention-table callbacks.
 */
function zeko_ai_privacy_register(): void {
	add_filter( 'wp_privacy_personal_data_exporters', 'zeko_ai_privacy_register_exporter' );
	add_filter( 'wp_privacy_personal_data_erasers', 'zeko_ai_privacy_register_eraser' );
	add_filter( 'zeko_core_privacy_retention_tables', 'zeko_ai_privacy_retention_tables' );
}
add_action( 'init', 'zeko_ai_privacy_register', 11 );

/**
 * Register the personal-data exporter.
 *
 * @param array $exporters Exporters.
 */
function zeko_ai_privacy_register_exporter( array $exporters ): array {
	$exporters['zeko-ai'] = array(
		'exporter_friendly_name' => __( 'Zeko AI data', 'zeko-ai' ),
		'callback'               => 'zeko_ai_privacy_export',
	);
	return $exporters;
}

/**
 * Register the personal-data eraser.
 *
 * @param array $erasers Erasers.
 */
function zeko_ai_privacy_register_eraser( array $erasers ): array {
	$erasers['zeko-ai'] = array(
		'eraser_friendly_name' => __( 'Zeko AI data', 'zeko-ai' ),
		'callback'             => 'zeko_ai_privacy_erase',
	);
	return $erasers;
}

/**
 * Get a prepared DB instance (null when the plugin is not active).
 */
function zeko_ai_privacy_db(): ?Zeko_AI_DB {
	if ( ! class_exists( 'Zeko_AI_DB' ) ) {
		return null;
	}
	return new Zeko_AI_DB();
}

/**
 * Whether one of the AI tables exists (guards every touch of a table).
 *
 * @param string $table Table.
 */
function zeko_ai_privacy_table_exists( string $table ): bool {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

/**
 * Export a user's Zeko AI data, 20 rows per table per page.
 *
 * @return array{data: array, done: bool}
 * @param string $email_address User who requested the export.
 * @param int    $page Export page (batching).
 */
function zeko_ai_privacy_export( string $email_address, int $page = 1 ): array {
	$user = get_user_by( 'email', $email_address );
	if ( ! $user ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	$db = zeko_ai_privacy_db();
	if ( ! $db ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	global $wpdb;

	$user_id   = (int) $user->ID;
	$per_page  = 20;
	$offset    = ( max( 1, (int) $page ) - 1 ) * $per_page;
	$data      = array();
	$tables    = 0;
	$exhausted = 0;

	if ( zeko_ai_privacy_table_exists( $db->get_table_conversations() ) ) {
		++$tables;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, context, status, created_at, updated_at FROM {$db->get_table_conversations()} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-ai-conversations',
				'group_label' => __( 'Zeko AI — Conversations', 'zeko-ai' ),
				'item_id'     => 'zeko-ai-conversation-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Title', 'zeko-ai' ),
						'value' => (string) $row->title,
					),
					array(
						'name'  => __( 'Context', 'zeko-ai' ),
						'value' => (string) $row->context,
					),
					array(
						'name'  => __( 'Created at', 'zeko-ai' ),
						'value' => (string) $row->created_at,
					),
					array(
						'name'  => __( 'Updated at', 'zeko-ai' ),
						'value' => (string) $row->updated_at,
					),
				),
			);
		}
		if ( count( $rows ) < $per_page ) {
			++$exhausted;
		}
	}

	if ( zeko_ai_privacy_table_exists( $db->get_table_messages() ) && zeko_ai_privacy_table_exists( $db->get_table_conversations() ) ) {
		++$tables;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.role, m.content, m.created_at FROM {$db->get_table_messages()} m INNER JOIN {$db->get_table_conversations()} c ON c.id = m.conversation_id WHERE c.user_id = %d ORDER BY m.id ASC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-ai-messages',
				'group_label' => __( 'Zeko AI — Messages', 'zeko-ai' ),
				'item_id'     => 'zeko-ai-message-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Role', 'zeko-ai' ),
						'value' => (string) $row->role,
					),
					array(
						'name'  => __( 'Content', 'zeko-ai' ),
						'value' => (string) $row->content,
					),
					array(
						'name'  => __( 'Created at', 'zeko-ai' ),
						'value' => (string) $row->created_at,
					),
				),
			);
		}
		if ( count( $rows ) < $per_page ) {
			++$exhausted;
		}
	}

	if ( zeko_ai_privacy_table_exists( $db->get_table_memory() ) ) {
		++$tables;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, fact_key, fact_value, source, created_at FROM {$db->get_table_memory()} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-ai-memory',
				'group_label' => __( 'Zeko AI — Memory', 'zeko-ai' ),
				'item_id'     => 'zeko-ai-memory-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Key', 'zeko-ai' ),
						'value' => (string) $row->fact_key,
					),
					array(
						'name'  => __( 'Value', 'zeko-ai' ),
						'value' => (string) $row->fact_value,
					),
					array(
						'name'  => __( 'Source', 'zeko-ai' ),
						'value' => (string) $row->source,
					),
					array(
						'name'  => __( 'Created at', 'zeko-ai' ),
						'value' => (string) $row->created_at,
					),
				),
			);
		}
		if ( count( $rows ) < $per_page ) {
			++$exhausted;
		}
	}

	if ( zeko_ai_privacy_table_exists( $db->get_table_usage() ) ) {
		++$tables;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, feature, provider, model, tokens_in, tokens_out, cost, created_at FROM {$db->get_table_usage()} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-ai-usage',
				'group_label' => __( 'Zeko AI — Usage', 'zeko-ai' ),
				'item_id'     => 'zeko-ai-usage-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Feature', 'zeko-ai' ),
						'value' => (string) $row->feature,
					),
					array(
						'name'  => __( 'Provider', 'zeko-ai' ),
						'value' => (string) $row->provider,
					),
					array(
						'name'  => __( 'Model', 'zeko-ai' ),
						'value' => (string) $row->model,
					),
					array(
						'name'  => __( 'Tokens in', 'zeko-ai' ),
						'value' => (string) $row->tokens_in,
					),
					array(
						'name'  => __( 'Tokens out', 'zeko-ai' ),
						'value' => (string) $row->tokens_out,
					),
					array(
						'name'  => __( 'Cost', 'zeko-ai' ),
						'value' => (string) $row->cost,
					),
					array(
						'name'  => __( 'Created at', 'zeko-ai' ),
						'value' => (string) $row->created_at,
					),
				),
			);
		}
		if ( count( $rows ) < $per_page ) {
			++$exhausted;
		}
	}

	if ( zeko_ai_privacy_table_exists( $db->get_table_recommendations() ) ) {
		++$tables;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, item_type, item_id, score, reason, created_at FROM {$db->get_table_recommendations()} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-ai-recommendations',
				'group_label' => __( 'Zeko AI — Recommendations', 'zeko-ai' ),
				'item_id'     => 'zeko-ai-recommendation-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Item type', 'zeko-ai' ),
						'value' => (string) $row->item_type,
					),
					array(
						'name'  => __( 'Item ID', 'zeko-ai' ),
						'value' => (string) $row->item_id,
					),
					array(
						'name'  => __( 'Score', 'zeko-ai' ),
						'value' => (string) $row->score,
					),
					array(
						'name'  => __( 'Reason', 'zeko-ai' ),
						'value' => (string) $row->reason,
					),
					array(
						'name'  => __( 'Created at', 'zeko-ai' ),
						'value' => (string) $row->created_at,
					),
				),
			);
		}
		if ( count( $rows ) < $per_page ) {
			++$exhausted;
		}
	}

	if ( zeko_ai_privacy_table_exists( $db->get_table_agent_queries() ) ) {
		++$tables;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, query, intent, answered, mode, created_at FROM {$db->get_table_agent_queries()} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-ai-agent-queries',
				'group_label' => __( 'Zeko AI — Agent queries', 'zeko-ai' ),
				'item_id'     => 'zeko-ai-agent-query-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Query', 'zeko-ai' ),
						'value' => (string) $row->query,
					),
					array(
						'name'  => __( 'Intent', 'zeko-ai' ),
						'value' => (string) $row->intent,
					),
					array(
						'name'  => __( 'Answered', 'zeko-ai' ),
						'value' => (string) $row->answered,
					),
					array(
						'name'  => __( 'Mode', 'zeko-ai' ),
						'value' => (string) $row->mode,
					),
					array(
						'name'  => __( 'Created at', 'zeko-ai' ),
						'value' => (string) $row->created_at,
					),
				),
			);
		}
		if ( count( $rows ) < $per_page ) {
			++$exhausted;
		}
	}

	// Provider log is intentionally absent: it has no user linkage (verified.
	// in the schema above), so no rows can be attributed to this user.
	// Site-wide scrub of its operational detail column is handled by retention.
	// (see zeko_ai_privacy_retention_tables()).

	return array(
		'data' => $data,
		'done' => $exhausted === $tables,
	);
}

/**
 * Erase a user's Zeko AI data.
 * Conversations and their messages are drained 20 conversations per pass;
 * memory, usage, recommendations and agent queries drain 20 rows each. The
 * eraser is called repeatedly with an incremental page until done is true.
 *
 * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
 * @param string $email_address User who requested erasure.
 * @param int    $_page page.
 */
function zeko_ai_privacy_erase( string $email_address, int $_page = 1 ): array {
	$user = get_user_by( 'email', $email_address );
	if ( ! $user ) {
		return array(
			'items_removed'  => 0,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	$db = zeko_ai_privacy_db();
	if ( ! $db ) {
		return array(
			'items_removed'  => 0,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	global $wpdb;

	$user_id   = (int) $user->ID;
	$removed   = 0;
	$remaining = 0;

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	// Messages live under conversations (they carry no user_id), so purge the.
	// user's conversations first and cascade to their messages.
	if ( zeko_ai_privacy_table_exists( $db->get_table_conversations() ) ) {
		$conversation_ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$db->get_table_conversations()} WHERE user_id = %d ORDER BY id ASC LIMIT %d",
					$user_id,
					20
				)
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( $conversation_ids ) {
			$placeholders = implode( ', ', array_fill( 0, count( $conversation_ids ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			if ( zeko_ai_privacy_table_exists( $db->get_table_messages() ) ) {
				$removed += (int) $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$db->get_table_messages()} WHERE conversation_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
						$conversation_ids
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$removed += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$db->get_table_conversations()} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
					$conversation_ids
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$remaining += (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$db->get_table_conversations()} WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	foreach ( array(
		$db->get_table_memory(),
		$db->get_table_usage(),
		$db->get_table_recommendations(),
		$db->get_table_agent_queries(),
	) as $table ) {
		if ( ! zeko_ai_privacy_table_exists( $table ) ) {
			continue;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
		$removed   += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d LIMIT %d",
				$user_id,
				20
			)
		);
		$remaining += (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	$messages = array();
	if ( 0 === $remaining && zeko_ai_privacy_table_exists( $db->get_table_provider_log() ) ) {
		$messages[] = __( 'Zeko AI provider log entries carry no user identifier, so nothing from that log was erased.', 'zeko-ai' );
	}

	return array(
		'items_removed'  => $removed,
		'items_retained' => 0,
		'messages'       => $messages,
		'done'           => 0 === $remaining,
	);
}

/**
 * Age-based retention configs for the shared Zeko Core retention registry.
 * The filter is fired by Zeko Core (if/when present); registering this
 * callback is harmless when the filter never fires. Array shape matches the
 * core contract: table / user_col / type_col / date_col / types / days and
 * the optional scrub_col / scrub_value pair.
 *
 * @param array $tables Tables.
 */
function zeko_ai_privacy_retention_tables( array $tables ): array {
	global $wpdb;
	$prefix = $wpdb->prefix;

	$tables[] = array(
		'table'    => $prefix . 'zeko_ai_usage',
		'user_col' => 'user_id',
		'type_col' => 'feature',
		'date_col' => 'created_at',
		'types'    => array(),
		'days'     => 365,
	);

	// Provider log is not user-scoped (no user column), so the retention job.
	// must scrub its detail column rather than delete rows it cannot own.
	$tables[] = array(
		'table'       => $prefix . 'zeko_ai_provider_log',
		'user_col'    => '',
		'type_col'    => 'event',
		'date_col'    => 'created_at',
		'types'       => array(),
		'days'        => 730,
		'scrub_col'   => 'detail',
		'scrub_value' => '',
	);

	// Remembered facts are user-scoped and age out after a year.
	$tables[] = array(
		'table'    => $prefix . 'zeko_ai_memory',
		'user_col' => 'user_id',
		'type_col' => 'source',
		'date_col' => 'created_at',
		'types'    => array(),
		'days'     => 365,
	);

	return $tables;
}
