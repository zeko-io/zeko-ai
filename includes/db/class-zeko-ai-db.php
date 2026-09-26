<?php
/**
 * Database access for Zeko AI.
 *
 * Owns the AI tables: conversations, messages, moderation, recommendations
 * and usage. Every method uses prepared statements via $wpdb.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_DB. */
class Zeko_AI_DB {

	/**
	 * Prefix.
	 *
	 * @var mixed Prefix.
	 */
	private $prefix;

	/**
	 * Has fulltext.
	 *
	 * @var mixed Has fulltext.
	 */
	private static $has_fulltext = null;

	/**
	 * Construct.
	 */
	public function __construct() {
		global $wpdb;
		$this->prefix = $wpdb->prefix;
	}

	// ---------------------------------------------------------------------.
	// Schema.
	// ---------------------------------------------------------------------.

	/**
	 * Create tables.
	 */
	public function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$conversations   = $this->get_table_conversations();
		$messages        = $this->get_table_messages();
		$moderation      = $this->get_table_moderation();
		$recommendations = $this->get_table_recommendations();
		$usage           = $this->get_table_usage();
		$knowledge       = $this->get_table_knowledge();
		$knowledge_votes = $this->get_table_knowledge_votes();
		$agent_queries   = $this->get_table_agent_queries();
		$memory          = $this->get_table_memory();
		$provider_log    = $this->get_table_provider_log();

		dbDelta(
			"
			CREATE TABLE {$conversations} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				title VARCHAR(255) NOT NULL DEFAULT '',
				context VARCHAR(50) NOT NULL DEFAULT 'assistant',
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY context (context),
				KEY updated_at (updated_at)
			) {$charset};
		"
		);

		dbDelta(
			"
			CREATE TABLE {$messages} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				role VARCHAR(20) NOT NULL DEFAULT 'user',
				content LONGTEXT NOT NULL,
				provider VARCHAR(30) NOT NULL DEFAULT '',
				model VARCHAR(100) NOT NULL DEFAULT '',
				tokens_in INT(11) UNSIGNED NOT NULL DEFAULT 0,
				tokens_out INT(11) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY conversation_id (conversation_id),
				KEY role (role),
				KEY created_at (created_at)
			) {$charset};
		"
		);

		dbDelta(
			"
			CREATE TABLE {$moderation} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				source VARCHAR(30) NOT NULL DEFAULT '',
				content_type VARCHAR(50) NOT NULL DEFAULT '',
				content_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				content LONGTEXT NOT NULL,
				decision VARCHAR(20) NOT NULL DEFAULT 'pending',
				reasons TEXT NOT NULL,
				score DECIMAL(6,4) NOT NULL DEFAULT 0,
				reviewed_at DATETIME NULL DEFAULT NULL,
				reviewed_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY source (source),
				KEY decision (decision),
				KEY created_at (created_at)
			) {$charset};
		"
		);

		dbDelta(
			"
			CREATE TABLE {$recommendations} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				item_type VARCHAR(30) NOT NULL DEFAULT '',
				item_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				score DECIMAL(10,4) NOT NULL DEFAULT 0,
				reason VARCHAR(255) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY item_type (item_type),
				KEY created_at (created_at)
			) {$charset};
		"
		);

		dbDelta(
			"
			CREATE TABLE {$usage} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				feature VARCHAR(30) NOT NULL DEFAULT '',
				provider VARCHAR(30) NOT NULL DEFAULT '',
				model VARCHAR(100) NOT NULL DEFAULT '',
				tokens_in INT(11) UNSIGNED NOT NULL DEFAULT 0,
				tokens_out INT(11) UNSIGNED NOT NULL DEFAULT 0,
				cost DECIMAL(12,8) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY feature (feature),
				KEY provider (provider),
				KEY created_at (created_at)
			) 			{$charset};
		"
		);

		dbDelta(
			"
			CREATE TABLE {$knowledge} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				question VARCHAR(500) NOT NULL DEFAULT '',
				question_key VARCHAR(255) NOT NULL DEFAULT '',
				answer LONGTEXT NOT NULL,
				module VARCHAR(30) NOT NULL DEFAULT '',
				source VARCHAR(20) NOT NULL DEFAULT 'admin',
				weight DECIMAL(8,4) NOT NULL DEFAULT 1,
				use_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
				positive INT(11) UNSIGNED NOT NULL DEFAULT 0,
				negative INT(11) UNSIGNED NOT NULL DEFAULT 0,
				last_used DATETIME NULL DEFAULT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY question_key (question_key),
				KEY source (source),
				KEY module (module),
				KEY use_count (use_count)
			) {$charset};
		"
		);

		dbDelta(
			"
			CREATE TABLE {$knowledge_votes} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				knowledge_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				vote VARCHAR(10) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY knowledge_user (knowledge_id, user_id)
			) {$charset};
		"
		);

		dbDelta(
			"
			CREATE TABLE {$agent_queries} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				query VARCHAR(500) NOT NULL DEFAULT '',
				intent VARCHAR(30) NOT NULL DEFAULT '',
				answered TINYINT(1) NOT NULL DEFAULT 0,
				mode VARCHAR(20) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY answered (answered),
				KEY created_at (created_at)
			) {$charset};
		"
		);

		dbDelta(
			"
			CREATE TABLE {$memory} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				fact_key VARCHAR(100) NOT NULL DEFAULT '',
				fact_value VARCHAR(500) NOT NULL DEFAULT '',
				source VARCHAR(20) NOT NULL DEFAULT 'auto',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY fact_key (fact_key)
			) {$charset};
		"
		);

		dbDelta(
			"
			CREATE TABLE {$provider_log} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				event VARCHAR(30) NOT NULL DEFAULT '',
				provider VARCHAR(30) NOT NULL DEFAULT '',
				model VARCHAR(100) NOT NULL DEFAULT '',
				detail TEXT NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY event (event),
				KEY provider (provider),
				KEY created_at (created_at)
			) {$charset};
		"
		);

		// Best-effort FULLTEXT index for fast word-level knowledge matching.
		$this->ensure_knowledge_fulltext();
	}

	/**
	 * Drop every AI table. Called on uninstall.
	 */
	public function drop_tables(): void {
		global $wpdb;

		$tables = array(
			$this->get_table_conversations(),
			$this->get_table_messages(),
			$this->get_table_moderation(),
			$this->get_table_recommendations(),
			$this->get_table_usage(),
			$this->get_table_knowledge(),
			$this->get_table_knowledge_votes(),
			$this->get_table_agent_queries(),
			$this->get_table_memory(),
			$this->get_table_provider_log(),
		);

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
	}

	/**
	 * Table conversations.
	 */
	public function get_table_conversations(): string {
		return $this->prefix . 'zeko_ai_conversations';
	}

	/**
	 * Table messages.
	 */
	public function get_table_messages(): string {
		return $this->prefix . 'zeko_ai_messages';
	}

	/**
	 * Table moderation.
	 */
	public function get_table_moderation(): string {
		return $this->prefix . 'zeko_ai_moderation';
	}

	/**
	 * Table recommendations.
	 */
	public function get_table_recommendations(): string {
		return $this->prefix . 'zeko_ai_recommendations';
	}

	/**
	 * Table usage.
	 */
	public function get_table_usage(): string {
		return $this->prefix . 'zeko_ai_usage';
	}

	/**
	 * Table knowledge.
	 */
	public function get_table_knowledge(): string {
		return $this->prefix . 'zeko_ai_knowledge';
	}

	/**
	 * Table knowledge votes.
	 */
	public function get_table_knowledge_votes(): string {
		return $this->prefix . 'zeko_ai_knowledge_votes';
	}

	/**
	 * Table agent queries.
	 */
	public function get_table_agent_queries(): string {
		return $this->prefix . 'zeko_ai_agent_queries';
	}

	/**
	 * Table memory.
	 */
	public function get_table_memory(): string {
		return $this->prefix . 'zeko_ai_memory';
	}

	/**
	 * Table provider log.
	 */
	public function get_table_provider_log(): string {
		return $this->prefix . 'zeko_ai_provider_log';
	}

	// ---------------------------------------------------------------------.
	// Conversations.
	// ---------------------------------------------------------------------.

	/**
	 * Insert conversation.
	 *
	 * @param array $data Data.
	 */
	public function insert_conversation( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql' );
		$row = array(
			'user_id'    => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
			'title'      => isset( $data['title'] ) ? mb_substr( sanitize_text_field( (string) $data['title'] ), 0, 255 ) : '',
			'context'    => isset( $data['context'] ) ? sanitize_key( (string) $data['context'] ) : 'assistant',
			'status'     => isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'active',
			'created_at' => isset( $data['created_at'] ) ? (string) $data['created_at'] : $now,
			'updated_at' => $now,
		);

		$wpdb->insert( $this->get_table_conversations(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Conversation.
	 *
	 * @param int $conversation_id Conversation id.
	 */
	public function get_conversation( int $conversation_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table_conversations()} WHERE id = %d",
				$conversation_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Conversations.
	 *
	 * @param array $args Args.
	 */
	public function get_conversations( array $args = array() ): array {
		global $wpdb;

		$user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
		$limit   = isset( $args['limit'] ) ? min( 500, max( 1, (int) $args['limit'] ) ) : 100;
		$offset  = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $user_id > 0 ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->get_table_conversations()} WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d OFFSET %d",
					$user_id,
					$limit,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table_conversations()} ORDER BY updated_at DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count conversations.
	 *
	 * @param int $user_id User id.
	 */
	public function count_conversations( int $user_id = 0 ): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $user_id > 0 ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->get_table_conversations()} WHERE user_id = %d",
					$user_id
				)
			);
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_table_conversations()}" );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Update conversation.
	 *
	 * @param int   $conversation_id Conversation id.
	 * @param array $data Data.
	 */
	public function update_conversation( int $conversation_id, array $data ): bool {
		global $wpdb;

		$row = array( 'updated_at' => current_time( 'mysql' ) );
		if ( isset( $data['title'] ) ) {
			$row['title'] = mb_substr( sanitize_text_field( (string) $data['title'] ), 0, 255 );
		}
		if ( isset( $data['status'] ) ) {
			$row['status'] = sanitize_key( (string) $data['status'] );
		}
		if ( isset( $data['context'] ) ) {
			$row['context'] = sanitize_key( (string) $data['context'] );
		}

		return false !== $wpdb->update(
			$this->get_table_conversations(),
			$row,
			array( 'id' => $conversation_id )
		);
	}

	/**
	 * Delete conversation.
	 *
	 * @param int $conversation_id Conversation id.
	 */
	public function delete_conversation( int $conversation_id ): bool {
		global $wpdb;
		$this->delete_messages_for_conversation( $conversation_id );
		return false !== $wpdb->delete( $this->get_table_conversations(), array( 'id' => $conversation_id ) );
	}

	// ---------------------------------------------------------------------.
	// Messages.
	// ---------------------------------------------------------------------.

	/**
	 * Insert message.
	 *
	 * @param array $data Data.
	 */
	public function insert_message( array $data ): int {
		global $wpdb;

		$row = array(
			'conversation_id' => isset( $data['conversation_id'] ) ? (int) $data['conversation_id'] : 0,
			'role'            => isset( $data['role'] ) ? sanitize_key( (string) $data['role'] ) : 'user',
			'content'         => isset( $data['content'] ) ? (string) $data['content'] : '',
			'provider'        => isset( $data['provider'] ) ? sanitize_key( (string) $data['provider'] ) : '',
			'model'           => isset( $data['model'] ) ? mb_substr( sanitize_text_field( (string) $data['model'] ), 0, 100 ) : '',
			'tokens_in'       => isset( $data['tokens_in'] ) ? max( 0, (int) $data['tokens_in'] ) : 0,
			'tokens_out'      => isset( $data['tokens_out'] ) ? max( 0, (int) $data['tokens_out'] ) : 0,
			'created_at'      => current_time( 'mysql' ),
		);

		$wpdb->insert( $this->get_table_messages(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Message.
	 *
	 * @param int $message_id Message id.
	 */
	public function get_message( int $message_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table_messages()} WHERE id = %d",
				$message_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Messages.
	 *
	 * @param int $conversation_id Conversation id.
	 */
	public function get_messages( int $conversation_id ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table_messages()} WHERE conversation_id = %d ORDER BY created_at ASC, id ASC",
				$conversation_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count messages.
	 *
	 * @param int $conversation_id Conversation id.
	 */
	public function count_messages( int $conversation_id ): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->get_table_messages()} WHERE conversation_id = %d",
				$conversation_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Delete messages for conversation.
	 *
	 * @param int $conversation_id Conversation id.
	 */
	public function delete_messages_for_conversation( int $conversation_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->get_table_messages(), array( 'conversation_id' => $conversation_id ) );
	}

	// ---------------------------------------------------------------------.
	// Moderation.
	// ---------------------------------------------------------------------.

	/**
	 * Insert moderation.
	 *
	 * @param array $data Data.
	 */
	public function insert_moderation( array $data ): int {
		global $wpdb;

		$reasons = isset( $data['reasons'] ) ? (array) $data['reasons'] : array();
		$reasons = array_map( 'sanitize_text_field', $reasons );

		$row = array(
			'user_id'      => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
			'source'       => isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : '',
			'content_type' => isset( $data['content_type'] ) ? mb_substr( sanitize_key( (string) $data['content_type'] ), 0, 50 ) : '',
			'content_id'   => isset( $data['content_id'] ) ? (int) $data['content_id'] : 0,
			'content'      => isset( $data['content'] ) ? (string) $data['content'] : '',
			'decision'     => isset( $data['decision'] ) ? sanitize_key( (string) $data['decision'] ) : 'pending',
			'reasons'      => wp_json_encode( $reasons ),
			'score'        => isset( $data['score'] ) ? (float) $data['score'] : 0,
			'created_at'   => current_time( 'mysql' ),
		);

		$wpdb->insert( $this->get_table_moderation(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Moderation.
	 *
	 * @param int $moderation_id Moderation id.
	 */
	public function get_moderation( int $moderation_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table_moderation()} WHERE id = %d",
				$moderation_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Moderation entries.
	 *
	 * @return array<int,object>
	 * @param array $args Args.
	 */
	public function get_moderation_entries( array $args = array() ): array {
		global $wpdb;

		$decision = isset( $args['decision'] ) ? sanitize_key( (string) $args['decision'] ) : '';
		$source   = isset( $args['source'] ) ? sanitize_key( (string) $args['source'] ) : '';
		$limit    = isset( $args['limit'] ) ? min( 500, max( 1, (int) $args['limit'] ) ) : 100;
		$offset   = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$where  = ' WHERE 1=1';
		$params = array();
		if ( '' !== $decision ) {
			$where   .= ' AND decision = %s';
			$params[] = $decision;
		}
		if ( '' !== $source ) {
			$where   .= ' AND source = %s';
			$params[] = $source;
		}

		$params[] = $limit;
		$params[] = $offset;
		$sql      = "SELECT * FROM {$this->get_table_moderation()}{$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count moderation.
	 *
	 * @param array $args Args.
	 */
	public function count_moderation( array $args = array() ): int {
		global $wpdb;

		$decision = isset( $args['decision'] ) ? sanitize_key( (string) $args['decision'] ) : '';
		$source   = isset( $args['source'] ) ? sanitize_key( (string) $args['source'] ) : '';

		$where  = ' WHERE 1=1';
		$params = array();
		if ( '' !== $decision ) {
			$where   .= ' AND decision = %s';
			$params[] = $decision;
		}
		if ( '' !== $source ) {
			$where   .= ' AND source = %s';
			$params[] = $source;
		}

		$sql = "SELECT COUNT(*) FROM {$this->get_table_moderation()}{$where}";

		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Update moderation.
	 *
	 * @param int   $moderation_id Moderation id.
	 * @param array $data Data.
	 */
	public function update_moderation( int $moderation_id, array $data ): bool {
		global $wpdb;

		$row = array();
		if ( isset( $data['decision'] ) ) {
			$row['decision'] = sanitize_key( (string) $data['decision'] );
		}
		if ( isset( $data['reviewed_by'] ) ) {
			$row['reviewed_by'] = (int) $data['reviewed_by'];
		}
		if ( isset( $data['reviewed_at'] ) ) {
			$row['reviewed_at'] = (string) $data['reviewed_at'];
		}
		if ( empty( $row ) ) {
			return false;
		}

		return false !== $wpdb->update(
			$this->get_table_moderation(),
			$row,
			array( 'id' => $moderation_id )
		);
	}

	/**
	 * Delete moderation.
	 *
	 * @param int $moderation_id Moderation id.
	 */
	public function delete_moderation( int $moderation_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->get_table_moderation(), array( 'id' => $moderation_id ) );
	}

	// ---------------------------------------------------------------------.
	// Recommendations.
	// ---------------------------------------------------------------------.

	/**
	 * Insert recommendation.
	 *
	 * @param array $data Data.
	 */
	public function insert_recommendation( array $data ): int {
		global $wpdb;

		$row = array(
			'user_id'    => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
			'item_type'  => isset( $data['item_type'] ) ? sanitize_key( (string) $data['item_type'] ) : '',
			'item_id'    => isset( $data['item_id'] ) ? (int) $data['item_id'] : 0,
			'score'      => isset( $data['score'] ) ? (float) $data['score'] : 0,
			'reason'     => isset( $data['reason'] ) ? mb_substr( sanitize_text_field( (string) $data['reason'] ), 0, 255 ) : '',
			'created_at' => current_time( 'mysql' ),
		);

		$wpdb->insert( $this->get_table_recommendations(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Recommendations.
	 *
	 * @return array<int,object>
	 * @param array $args Args.
	 */
	public function get_recommendations( array $args = array() ): array {
		global $wpdb;

		$user_id   = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
		$item_type = isset( $args['item_type'] ) ? sanitize_key( (string) $args['item_type'] ) : '';
		$limit     = isset( $args['limit'] ) ? min( 500, max( 1, (int) $args['limit'] ) ) : 100;

		$where  = ' WHERE 1=1';
		$params = array();
		if ( $user_id > 0 ) {
			$where   .= ' AND user_id = %d';
			$params[] = $user_id;
		}
		if ( '' !== $item_type ) {
			$where   .= ' AND item_type = %s';
			$params[] = $item_type;
		}
		$params[] = $limit;

		$sql = "SELECT * FROM {$this->get_table_recommendations()}{$where} ORDER BY score DESC LIMIT %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count recommendations.
	 *
	 * @param int $user_id User id.
	 */
	public function count_recommendations( int $user_id = 0 ): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $user_id > 0 ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->get_table_recommendations()} WHERE user_id = %d",
					$user_id
				)
			);
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_table_recommendations()}" );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Clear recommendations.
	 *
	 * @param int $user_id User id.
	 */
	public function clear_recommendations( int $user_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->get_table_recommendations(), array( 'user_id' => $user_id ) );
	}

	/**
	 * Delete recommendation.
	 *
	 * @param int $recommendation_id Recommendation id.
	 */
	public function delete_recommendation( int $recommendation_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->get_table_recommendations(), array( 'id' => $recommendation_id ) );
	}

	// ---------------------------------------------------------------------.
	// Usage.
	// ---------------------------------------------------------------------.

	/**
	 * Insert usage.
	 *
	 * @param array $data Data.
	 */
	public function insert_usage( array $data ): int {
		global $wpdb;

		$row = array(
			'user_id'    => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
			'feature'    => isset( $data['feature'] ) ? sanitize_key( (string) $data['feature'] ) : '',
			'provider'   => isset( $data['provider'] ) ? sanitize_key( (string) $data['provider'] ) : '',
			'model'      => isset( $data['model'] ) ? mb_substr( sanitize_text_field( (string) $data['model'] ), 0, 100 ) : '',
			'tokens_in'  => isset( $data['tokens_in'] ) ? max( 0, (int) $data['tokens_in'] ) : 0,
			'tokens_out' => isset( $data['tokens_out'] ) ? max( 0, (int) $data['tokens_out'] ) : 0,
			'cost'       => isset( $data['cost'] ) ? (float) $data['cost'] : 0,
			'created_at' => isset( $data['created_at'] ) ? (string) $data['created_at'] : current_time( 'mysql' ),
		);

		$wpdb->insert( $this->get_table_usage(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Usage.
	 *
	 * @return array<int,object>
	 * @param array $args Args.
	 */
	public function get_usage( array $args = array() ): array {
		global $wpdb;

		$feature = isset( $args['feature'] ) ? sanitize_key( (string) $args['feature'] ) : '';
		$user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
		$limit   = isset( $args['limit'] ) ? min( 500, max( 1, (int) $args['limit'] ) ) : 100;
		$offset  = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$where  = ' WHERE 1=1';
		$params = array();
		if ( '' !== $feature ) {
			$where   .= ' AND feature = %s';
			$params[] = $feature;
		}
		if ( $user_id > 0 ) {
			$where   .= ' AND user_id = %d';
			$params[] = $user_id;
		}
		$params[] = $limit;
		$params[] = $offset;

		$sql = "SELECT * FROM {$this->get_table_usage()}{$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Aggregate usage totals.
	 *
	 * @return array{requests:int, tokens_in:int, tokens_out:int, cost:float}
	 * @param array $args Args.
	 */
	public function usage_totals( array $args = array() ): array {
		global $wpdb;

		$feature = isset( $args['feature'] ) ? sanitize_key( (string) $args['feature'] ) : '';
		$where   = ' WHERE 1=1';
		$params  = array();
		if ( '' !== $feature ) {
			$where   .= ' AND feature = %s';
			$params[] = $feature;
		}

		$sql = "SELECT COUNT(*) AS requests, COALESCE(SUM(tokens_in),0) AS tokens_in, COALESCE(SUM(tokens_out),0) AS tokens_out, COALESCE(SUM(cost),0) AS cost FROM {$this->get_table_usage()}{$where}";
		$row = $params ? $wpdb->get_row( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		return array(
			'requests'   => (int) $row->requests,
			'tokens_in'  => (int) $row->tokens_in,
			'tokens_out' => (int) $row->tokens_out,
			'cost'       => (float) $row->cost,
		);
	}

	/**
	 * Usage broken down by feature.
	 *
	 * @return array<int,object>
	 */
	public function usage_by_feature(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			"SELECT feature, COUNT(*) AS requests, COALESCE(SUM(tokens_in),0) AS tokens_in, COALESCE(SUM(tokens_out),0) AS tokens_out, COALESCE(SUM(cost),0) AS cost FROM {$this->get_table_usage()} GROUP BY feature ORDER BY requests DESC"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Usage broken down by day.
	 *
	 * @return array<int,object>
	 * @param int $days Days.
	 */
	public function usage_by_day( int $days = 30 ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day, COUNT(*) AS requests, COALESCE(SUM(tokens_in),0) AS tokens_in, COALESCE(SUM(tokens_out),0) AS tokens_out, COALESCE(SUM(cost),0) AS cost FROM {$this->get_table_usage()} WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY) GROUP BY DATE(created_at) ORDER BY day ASC",
				$days
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Top usage users.
	 *
	 * @return array<int,object>
	 * @param int $limit Limit.
	 */
	public function usage_top_users( int $limit = 10 ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COUNT(*) AS requests, COALESCE(SUM(tokens_in),0) AS tokens_in, COALESCE(SUM(tokens_out),0) AS tokens_out, COALESCE(SUM(cost),0) AS cost FROM {$this->get_table_usage()} WHERE user_id > 0 GROUP BY user_id ORDER BY requests DESC LIMIT %d",
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Delete usage.
	 *
	 * @param array $args Args.
	 */
	public function delete_usage( array $args = array() ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( ! empty( $args['before'] ) ) {
			return false !== $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->get_table_usage()} WHERE created_at < %s",
					(string) $args['before']
				)
			);
		}
		return false !== $wpdb->query( "DELETE FROM {$this->get_table_usage()}" );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// ---------------------------------------------------------------------.
	// Agent knowledge (self-training memory).
	// ---------------------------------------------------------------------.

	/**
	 * Normalize a question into a stable lookup key (lowercase, punctuation
	 * stripped, whitespace collapsed).
	 *
	 * @param string $question Question.
	 */
	public function normalize_question( string $question ): string {
		$key = mb_strtolower( trim( (string) $question ) );
		$key = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $key );
		$key = preg_replace( '/\s+/u', ' ', $key );
		return trim( $key );
	}

	/**
	 * Insert knowledge.
	 *
	 * @param array $data Data.
	 */
	public function insert_knowledge( array $data ): int {
		global $wpdb;

		$question = isset( $data['question'] ) ? mb_substr( sanitize_text_field( (string) $data['question'] ), 0, 500 ) : '';

		$row = array(
			'user_id'      => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
			'question'     => $question,
			'question_key' => $this->normalize_question( $question ),
			'answer'       => isset( $data['answer'] ) ? (string) $data['answer'] : '',
			'module'       => isset( $data['module'] ) ? sanitize_key( (string) $data['module'] ) : '',
			'source'       => isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : 'admin',
			'weight'       => isset( $data['weight'] ) ? (float) $data['weight'] : 1,
			'created_at'   => current_time( 'mysql' ),
		);

		$wpdb->insert( $this->get_table_knowledge(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Knowledge.
	 *
	 * @param int $knowledge_id Knowledge id.
	 */
	public function get_knowledge( int $knowledge_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table_knowledge()} WHERE id = %d",
				$knowledge_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Exact normalized-question lookup (fast path for repeat questions).
	 *
	 * @param string $question Question.
	 */
	public function find_knowledge( string $question ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table_knowledge()} WHERE question_key = %s LIMIT 1",
				$this->normalize_question( $question )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Fuzzy lookup: any row whose question or answer matches the text.
	 *
	 * @return array<int,object>
	 * @param string $text Text.
	 * @param int    $limit Limit.
	 */
	public function search_knowledge( string $text, int $limit = 10 ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( mb_substr( $text, 0, 200 ) ) . '%';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table_knowledge()} WHERE question LIKE %s OR answer LIKE %s ORDER BY weight DESC, use_count DESC LIMIT %d",
				$like,
				$like,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		return $rows ?: array();
	}

	/**
	 * Record knowledge use.
	 *
	 * @param int $knowledge_id Knowledge id.
	 * @param int $increment Increment.
	 */
	public function record_knowledge_use( int $knowledge_id, int $increment = 1 ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return false !== $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->get_table_knowledge()} SET use_count = use_count + %d, last_used = %s WHERE id = %d",
				max( 1, $increment ),
				current_time( 'mysql' ),
				$knowledge_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Delete knowledge.
	 *
	 * @param int $knowledge_id Knowledge id.
	 */
	public function delete_knowledge( int $knowledge_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->get_table_knowledge(), array( 'id' => $knowledge_id ) );
	}

	/**
	 * Record a member vote on a knowledge entry. Positive votes nudge the
	 * weight up, negative votes nudge it down so low-rated auto-learned rows
	 * can be pruned later. Returns the updated row, null when the entry is
	 * missing, and false when this member already voted on the entry.
	 *
	 * @return object|null|false Updated row, null (missing), or false (duplicate).
	 * @param int    $knowledge_id * @param string $vote 'positive' | 'negative'.
	 * @param string $vote Vote.
	 * @param int    $user_id Member id (0 keeps the legacy no-dedupe path).
	 */
	public function vote_knowledge( int $knowledge_id, string $vote, int $user_id = 0 ) {
		global $wpdb;
		$table = $this->get_table_knowledge();

		if ( ! $this->get_knowledge( $knowledge_id ) ) {
			return null;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// One vote per member per entry. A UNIQUE key on (knowledge_id,.
		// user_id) makes this race-safe; INSERT IGNORE yields 0 affected rows.
		// for a duplicate.
		if ( $user_id > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$this->get_table_knowledge_votes()} (knowledge_id, user_id, vote, created_at) VALUES (%d, %d, %s, %s)",
					$knowledge_id,
					$user_id,
					$vote,
					current_time( 'mysql' )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			if ( 0 === $wpdb->rows_affected ) {
				return false;
			}
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( 'positive' === $vote ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET positive = positive + 1, weight = LEAST(10, weight + 0.1) WHERE id = %d",
					$knowledge_id
				)
			);
		} elseif ( 'negative' === $vote ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET negative = negative + 1, weight = GREATEST(0.1, weight - 0.1) WHERE id = %d",
					$knowledge_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		} else {
			return null;
		}

		return $this->get_knowledge( $knowledge_id );
	}

	/**
	 * Auto-learned knowledge rows that members rated poorly on net. Only
	 * `source='auto'` rows are candidates (admin-taught and seeded rows are
	 * never pruned automatically).
	 *
	 * @return array<int,object>
	 * @param int $limit Limit.
	 */
	public function get_low_rated( int $limit = 50 ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table_knowledge()}
			WHERE source = 'auto' AND negative > positive AND (negative + positive) >= 2
			ORDER BY (negative - positive) DESC, negative DESC LIMIT %d",
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Remove the worst auto-learned rows (net-negative member ratings).
	 *
	 * @return int Number of rows removed.
	 * @param int $limit Max rows to remove in one pass.
	 */
	public function prune_knowledge( int $limit = 25 ): int {
		$removed = 0;
		foreach ( $this->get_low_rated( $limit ) as $row ) {
			if ( $this->delete_knowledge( (int) $row->id ) ) {
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Knowledge entries.
	 *
	 * @return array<int,object>
	 * @param array $args Args.
	 */
	public function get_knowledge_entries( array $args = array() ): array {
		global $wpdb;

		$source = isset( $args['source'] ) ? sanitize_key( (string) $args['source'] ) : '';
		$limit  = isset( $args['limit'] ) ? min( 500, max( 1, (int) $args['limit'] ) ) : 100;

		$where  = ' WHERE 1=1';
		$params = array();
		if ( '' !== $source ) {
			$where   .= ' AND source = %s';
			$params[] = $source;
		}
		$params[] = $limit;
		$sql      = "SELECT * FROM {$this->get_table_knowledge()}{$where} ORDER BY use_count DESC, created_at DESC LIMIT %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count knowledge.
	 */
	public function count_knowledge(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_table_knowledge()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Word-level knowledge search over the FULLTEXT index (fast candidate
	 * generation for paraphrase-tolerant matching). Empty when no index.
	 *
	 * @return array<int,object>
	 * @param string $text Text.
	 * @param int    $limit Limit.
	 */
	public function search_knowledge_fulltext( string $text, int $limit = 20 ): array {
		global $wpdb;

		if ( ! $this->knowledge_has_fulltext() ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *, (MATCH(question, answer) AGAINST (%s IN NATURAL LANGUAGE MODE)) AS relevance FROM {$this->get_table_knowledge()} WHERE MATCH(question, answer) AGAINST (%s IN NATURAL LANGUAGE MODE) ORDER BY relevance DESC, weight DESC, use_count DESC LIMIT %d",
				$text,
				$text,
				$limit
			)
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Token-based candidate search: every token must appear somewhere in the
	 * question or answer (AND across tokens), so paraphrased questions still
	 * surface rows without a FULLTEXT index.
	 *
	 * @return array<int,object>
	 * @param array $tokens Lowercased, stopword-free tokens.
	 * @param int   $limit Limit.
	 */
	public function search_knowledge_tokens( array $tokens, int $limit = 30 ): array {
		global $wpdb;

		$tokens = array_values( array_filter( array_map( 'mb_strtolower', array_map( 'strval', $tokens ) ) ) );
		if ( empty( $tokens ) ) {
			return array();
		}

		$where  = array();
		$params = array();
		foreach ( $tokens as $token ) {
			$like     = '%' . $wpdb->esc_like( $token ) . '%';
			$where[]  = '(question LIKE %s OR answer LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$params[] = $limit;

		$sql = "SELECT * FROM {$this->get_table_knowledge()} WHERE " . implode( ' AND ', $where ) . ' ORDER BY weight DESC, use_count DESC LIMIT %d';

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Whether the knowledge table has the FULLTEXT index (checked once).
	 */
	public function knowledge_has_fulltext(): bool {
		global $wpdb;

		if ( null !== self::$has_fulltext ) {
			return self::$has_fulltext;
		}

		$found = false;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$this->get_table_knowledge()}" ) as $index ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Key_name is the native column alias of SHOW INDEX results and cannot be renamed.
			if ( 'ft_knowledge' === (string) $index->Key_name ) {
				$found = true;
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
				break;
			}
		}

		self::$has_fulltext = $found;
		return $found;
	}

	/**
	 * Add the FULLTEXT index if missing. Best-effort and non-fatal: on MySQL
	 * versions that reject it the token-LIKE path still works.
	 */
	public function ensure_knowledge_fulltext(): bool {
		global $wpdb;

		if ( $this->knowledge_has_fulltext() ) {
			return true;
		}

		$result = $wpdb->query( "ALTER TABLE {$this->get_table_knowledge()} ADD FULLTEXT ft_knowledge (question, answer)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( false === $result ) {
			error_log( 'Zeko AI: could not add FULLTEXT index to the knowledge table; falling back to token search.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return false;
		}

		self::$has_fulltext = true;
		return true;
	}

	/**
	 * Idempotent bulk seeding of starter knowledge. Rows whose exact question
	 * already exists are skipped, so re-running never duplicates.
	 *
	 * @return int Number of rows inserted.
	 * @param array $items * @return int Number of rows inserted.
	 */
	public function seed_knowledge( array $items ): int {
		$inserted = 0;

		foreach ( $items as $item ) {
			$question = isset( $item['question'] ) ? (string) $item['question'] : '';
			if ( '' === trim( $question ) ) {
				continue;
			}
			if ( $this->find_knowledge( $question ) ) {
				continue;
			}
			$this->insert_knowledge(
				array(
					'user_id'  => 0,
					'question' => $question,
					'answer'   => isset( $item['answer'] ) ? (string) $item['answer'] : '',
					'module'   => isset( $item['module'] ) ? (string) $item['module'] : 'general',
					'source'   => 'seed',
					'weight'   => isset( $item['weight'] ) ? (float) $item['weight'] : 1,
				)
			);
			++$inserted;
		}

		return $inserted;
	}

	/**
	 * Self-repair pass for the agent's data: recreate missing tables, fix
	 * blank question keys, drop empty rows and collapse duplicate questions.
	 *
	 * @return array{fixed_keys:int,removed_empty:int,removed_duplicates:int}
	 */
	public function heal_agent_data(): array {
		global $wpdb;

		$report = array(
			'fixed_keys'         => 0,
			'removed_empty'      => 0,
			'removed_duplicates' => 0,
		);

		$table = $this->get_table_knowledge();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			$this->create_tables();
			$report['fixed_keys'] = 1; // "tables recreated".
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// Blank question keys.
		foreach ( (array) $wpdb->get_results( "SELECT id, question, question_key FROM {$table} WHERE question_key = '' OR question_key IS NULL" ) as $row ) {
			$key = $this->normalize_question( (string) $row->question );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			if ( '' !== $key ) {
				$wpdb->update( $table, array( 'question_key' => $key ), array( 'id' => (int) $row->id ) );
				++$report['fixed_keys'];
			}
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// Empty questions/answers.
		$wpdb->query( "DELETE FROM {$table} WHERE question = '' OR answer = ''" );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$report['removed_empty'] = (int) $wpdb->rows_affected;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// Duplicate questions (keep the most-used row).
		$duplicates = (array) $wpdb->get_col( "SELECT question_key FROM {$table} WHERE question_key <> '' GROUP BY question_key HAVING COUNT(*) > 1" );
		foreach ( $duplicates as $key ) {
			$keep = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE question_key = %s ORDER BY use_count DESC, id ASC LIMIT 1",
					$key
				)
			);
			if ( $keep > 0 ) {
				$report['removed_duplicates'] += (int) $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$table} WHERE question_key = %s AND id <> %d",
						$key,
						$keep
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			}
		}

		return $report;
	}

	// ---------------------------------------------------------------------.
	// Agent query log (self-training gaps).
	// ---------------------------------------------------------------------.

	/**
	 * Insert agent query.
	 *
	 * @param array $data Data.
	 */
	public function insert_agent_query( array $data ): int {
		global $wpdb;

		$row = array(
			'user_id'    => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
			'query'      => isset( $data['query'] ) ? mb_substr( sanitize_text_field( (string) $data['query'] ), 0, 500 ) : '',
			'intent'     => isset( $data['intent'] ) ? sanitize_key( (string) $data['intent'] ) : 'general',
			'answered'   => ! empty( $data['answered'] ) ? 1 : 0,
			'mode'       => isset( $data['mode'] ) ? sanitize_key( (string) $data['mode'] ) : '',
			'created_at' => current_time( 'mysql' ),
		);

		$wpdb->insert( $this->get_table_agent_queries(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update agent query.
	 *
	 * @param int   $query_id Query id.
	 * @param array $data Data.
	 */
	public function update_agent_query( int $query_id, array $data ): bool {
		global $wpdb;

		$row = array();
		if ( isset( $data['answered'] ) ) {
			$row['answered'] = ! empty( $data['answered'] ) ? 1 : 0;
		}
		if ( isset( $data['mode'] ) ) {
			$row['mode'] = sanitize_key( (string) $data['mode'] );
		}
		if ( empty( $row ) ) {
			return false;
		}

		return false !== $wpdb->update( $this->get_table_agent_queries(), $row, array( 'id' => $query_id ) );
	}

	/**
	 * Delete agent query.
	 *
	 * @param int $query_id Query id.
	 */
	public function delete_agent_query( int $query_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->get_table_agent_queries(), array( 'id' => $query_id ) );
	}

	/**
	 * Agent queries.
	 *
	 * @return array<int,object>
	 * @param array $args Args.
	 */
	public function get_agent_queries( array $args = array() ): array {
		global $wpdb;

		$answered = isset( $args['answered'] ) ? (int) $args['answered'] : -1;
		$limit    = isset( $args['limit'] ) ? min( 500, max( 1, (int) $args['limit'] ) ) : 100;

		$where  = '';
		$params = array();
		if ( 0 === $answered || 1 === $answered ) {
			$where    = ' WHERE answered = %d';
			$params[] = $answered;
		}
		$params[] = $limit;
		$sql      = "SELECT * FROM {$this->get_table_agent_queries()}{$where} ORDER BY created_at DESC LIMIT %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count agent queries.
	 *
	 * @param int $answered Answered.
	 */
	public function count_agent_queries( int $answered = -1 ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( 0 === $answered || 1 === $answered ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->get_table_agent_queries()} WHERE answered = %d",
					$answered
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_table_agent_queries()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Cluster unanswered queries by intent so the admin can spot the biggest
	 * teaching gaps first. Groups by intent (or empty-intent bucket) and
	 * returns per-cluster totals with a representative query string.
	 *
	 * @return array<int,array{intent:string,count:int,sample:string}>
	 * @param int $min Minimum unanswered count for a cluster to be suggested.
	 * @param int $limit Max clusters returned.
	 */
	public function suggest_gaps( int $min = 3, int $limit = 10 ): array {
		global $wpdb;
		$table = $this->get_table_agent_queries();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			"SELECT intent, COUNT(*) AS total, MAX(query) AS sample
			FROM {$table}
			WHERE answered = 0
			GROUP BY intent
			ORDER BY total DESC, sample ASC
			LIMIT 50"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$gaps = array();
		foreach ( (array) $rows as $row ) {
			$total = (int) $row->total;
			if ( $total < $min ) {
				continue;
			}
			$gaps[] = array(
				'intent' => (string) $row->intent,
				'count'  => $total,
				'sample' => (string) $row->sample,
			);
			if ( count( $gaps ) >= $limit ) {
				break;
			}
		}

		return $gaps;
	}

	// ---------------------------------------------------------------------.
	// Knowledge import / export.
	// ---------------------------------------------------------------------.

	/**
	 * Portable snapshot of every knowledge entry for backup or bulk import
	 * elsewhere. Auto-learned rows are included but tagged so re-imports can
	 * choose to skip them.
	 *
	 * @return array<int,array{question:string,answer:string,module:string,weight:float,source:string}>
	 * @param array $args source filter ('' = all).
	 */
	public function export_knowledge( array $args = array() ): array {
		$out = array();
		foreach ( $this->get_knowledge_entries( array_merge( array( 'limit' => 500 ), $args ) ) as $row ) {
			$out[] = array(
				'question' => (string) $row->question,
				'answer'   => (string) $row->answer,
				'module'   => (string) $row->module,
				'weight'   => (float) $row->weight,
				'source'   => (string) $row->source,
			);
		}
		return $out;
	}

	/**
	 * Bulk import of knowledge entries from an exported snapshot. Existing
	 * exact questions are updated (when $overwrite) or skipped; new ones are
	 * inserted with source 'admin' unless the item carries an allowed source.
	 *
	 * @return array{inserted:int,updated:int,skipped:int}
	 * @param array $items * @param bool                                                                                        $overwrite Update the answer of rows whose question exists.
	 * @param bool  $overwrite Overwrite.
	 */
	public function import_knowledge( array $items, bool $overwrite = false ): array {
		$report = array(
			'inserted' => 0,
			'updated'  => 0,
			'skipped'  => 0,
		);

		foreach ( $items as $item ) {
			$question = isset( $item['question'] ) ? (string) $item['question'] : '';
			if ( '' === trim( $question ) || ! isset( $item['answer'] ) || '' === trim( (string) $item['answer'] ) ) {
				++$report['skipped'];
				continue;
			}

			$source = isset( $item['source'] ) ? sanitize_key( (string) $item['source'] ) : 'admin';
			if ( ! in_array( $source, array( 'admin', 'seed', 'auto' ), true ) ) {
				$source = 'admin';
			}

			$existing = $this->find_knowledge( $question );
			if ( $existing ) {
				if ( $overwrite ) {
					$wpdb = $this->get_wpdb();
					$wpdb->update(
						$this->get_table_knowledge(),
						array(
							'answer' => (string) $item['answer'],
							'module' => isset( $item['module'] ) ? sanitize_key( (string) $item['module'] ) : (string) $existing->module,
							'weight' => isset( $item['weight'] ) ? (float) $item['weight'] : (float) $existing->weight,
						),
						array( 'id' => (int) $existing->id )
					);
					++$report['updated'];
				} else {
					++$report['skipped'];
				}
				continue;
			}

			$this->insert_knowledge(
				array(
					'user_id'  => 0,
					'question' => $question,
					'answer'   => (string) $item['answer'],
					'module'   => isset( $item['module'] ) ? (string) $item['module'] : 'general',
					'source'   => $source,
					'weight'   => isset( $item['weight'] ) ? (float) $item['weight'] : 1,
				)
			);
			++$report['inserted'];
		}

		return $report;
	}

	/**
	 * Other knowledge rows in the same module, for follow-up suggestions.
	 *
	 * @return array<int,object>
	 * @param string $module * @param int    $exclude_id Skip the row the current answer came from.
	 * @param int    $exclude_id Exclude id.
	 * @param int    $limit * @return array<int,object>.
	 */
	public function get_knowledge_by_module( string $module, int $exclude_id = 0, int $limit = 3 ): array {
		global $wpdb;

		$where  = ' WHERE module = %s';
		$params = array( sanitize_key( $module ) );
		if ( $exclude_id > 0 ) {
			$where   .= ' AND id <> %d';
			$params[] = $exclude_id;
		}
		$params[] = min( 10, max( 1, $limit ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table_knowledge()}{$where} ORDER BY weight DESC, use_count DESC LIMIT %d",
				$params
			)
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// ---------------------------------------------------------------------.
	// Agent memory (per-user remembered facts).
	// ---------------------------------------------------------------------.

	/**
	 * Insert memory.
	 *
	 * @param array $data Data.
	 */
	public function insert_memory( array $data ): int {
		global $wpdb;

		$row = array(
			'user_id'    => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
			'fact_key'   => isset( $data['fact_key'] ) ? mb_substr( sanitize_key( (string) $data['fact_key'] ), 0, 100 ) : '',
			'fact_value' => isset( $data['fact_value'] ) ? mb_substr( sanitize_text_field( (string) $data['fact_value'] ), 0, 500 ) : '',
			'source'     => isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : 'auto',
			'created_at' => current_time( 'mysql' ),
		);

		$wpdb->insert( $this->get_table_memory(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Latest remembered fact per key for a user.
	 *
	 * @return array<string,string> fact_key => value
	 * @param int $user_id * @return array<string,string> fact_key => value.
	 */
	public function get_user_memory( int $user_id ): array {
		global $wpdb;
		$table = $this->get_table_memory();

		if ( $user_id <= 0 ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.fact_key, m.fact_value, m.source
			FROM {$table} m
			INNER JOIN (
				SELECT MAX(id) AS max_id FROM {$table} WHERE user_id = %d GROUP BY fact_key
			) x ON x.max_id = m.id
			ORDER BY m.fact_key ASC",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( '' !== (string) $row->fact_key ) {
				$out[ (string) $row->fact_key ] = (string) $row->fact_value;
			}
		}
		return $out;
	}

	/**
	 * All memory.
	 *
	 * @return array<int,object>
	 * @param array $args Args.
	 */
	public function get_all_memory( array $args = array() ): array {
		global $wpdb;

		$limit   = isset( $args['limit'] ) ? min( 500, max( 1, (int) $args['limit'] ) ) : 100;
		$user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $user_id > 0 ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->get_table_memory()} WHERE user_id = %d ORDER BY id DESC LIMIT %d",
					$user_id,
					$limit
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table_memory()} ORDER BY id DESC LIMIT %d",
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Delete memory.
	 *
	 * @param int $memory_id Memory id.
	 */
	public function delete_memory( int $memory_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->get_table_memory(), array( 'id' => $memory_id ) );
	}

	/**
	 * Delete user memory.
	 *
	 * @param int $user_id User id.
	 */
	public function delete_user_memory( int $user_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->get_table_memory(), array( 'user_id' => $user_id ) );
	}

	/**
	 * Count memory.
	 */
	public function count_memory(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_table_memory()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// ---------------------------------------------------------------------.
	// Provider health / failover log.
	// ---------------------------------------------------------------------.

	/**
	 * Insert provider log.
	 *
	 * @param array $data Data.
	 */
	public function insert_provider_log( array $data ): int {
		global $wpdb;

		$row = array(
			'event'      => isset( $data['event'] ) ? sanitize_key( (string) $data['event'] ) : 'info',
			'provider'   => isset( $data['provider'] ) ? sanitize_key( (string) $data['provider'] ) : '',
			'model'      => isset( $data['model'] ) ? mb_substr( sanitize_text_field( (string) $data['model'] ), 0, 100 ) : '',
			'detail'     => isset( $data['detail'] ) ? mb_substr( (string) $data['detail'], 0, 4000 ) : '',
			'created_at' => current_time( 'mysql' ),
		);

		$wpdb->insert( $this->get_table_provider_log(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Provider logs.
	 *
	 * @return array<int,object>
	 * @param array $args Args.
	 */
	public function get_provider_logs( array $args = array() ): array {
		global $wpdb;

		$event  = isset( $args['event'] ) ? sanitize_key( (string) $args['event'] ) : '';
		$limit  = isset( $args['limit'] ) ? min( 500, max( 1, (int) $args['limit'] ) ) : 100;
		$where  = '';
		$params = array();
		if ( '' !== $event ) {
			$where    = ' WHERE event = %s';
			$params[] = $event;
		}
		$params[] = $limit;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table_provider_log()}{$where} ORDER BY id DESC LIMIT %d",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count provider logs.
	 */
	public function count_provider_logs(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_table_provider_log()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Delete provider logs.
	 */
	public function delete_provider_logs(): bool {
		global $wpdb;
		return false !== $wpdb->query( "DELETE FROM {$this->get_table_provider_log()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Wpdb.
	 *
	 * @internal Shared $wpdb accessor for table-level helpers.
	 */
	private function get_wpdb() {
		global $wpdb;
		return $wpdb;
	}
}
