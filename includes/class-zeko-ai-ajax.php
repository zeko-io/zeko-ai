<?php
/**
 * Zeko AI front-end AJAX handlers.
 *
 * Powers the public assistant chat, content writer, unified search,
 * recommendations and moderation checker. All authenticated handlers require
 * a logged-in user and verify the shared AI nonce; search is public.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}



require_once __DIR__ . '/class-zeko-ai-ajax-halt.php';

/** Class Zeko_AI_Ajax. */
class Zeko_AI_Ajax {

	/**
	 * Db.
	 *
	 * @var Zeko_AI_DB Db.
	 */
	private Zeko_AI_DB $db;

	/**
	 * Construct.
	 *
	 * @param Zeko_AI_DB $db Db.
	 */
	public function __construct( Zeko_AI_DB $db ) {
		$this->db = $db;

		add_action( 'wp_ajax_zeko_ai_chat', array( $this, 'handle_chat' ) );
		add_action( 'wp_ajax_nopriv_zeko_ai_chat', array( $this, 'denied' ) );

		add_action( 'wp_ajax_zeko_ai_new_conversation', array( $this, 'handle_new_conversation' ) );
		add_action( 'wp_ajax_nopriv_zeko_ai_new_conversation', array( $this, 'denied' ) );

		add_action( 'wp_ajax_zeko_ai_conversations', array( $this, 'handle_conversations' ) );
		add_action( 'wp_ajax_nopriv_zeko_ai_conversations', array( $this, 'denied' ) );

		add_action( 'wp_ajax_zeko_ai_messages', array( $this, 'handle_messages' ) );
		add_action( 'wp_ajax_nopriv_zeko_ai_messages', array( $this, 'denied' ) );

		add_action( 'wp_ajax_zeko_ai_delete_conversation', array( $this, 'handle_delete_conversation' ) );
		add_action( 'wp_ajax_nopriv_zeko_ai_delete_conversation', array( $this, 'denied' ) );

		add_action( 'wp_ajax_zeko_ai_generate', array( $this, 'handle_generate' ) );
		add_action( 'wp_ajax_nopriv_zeko_ai_generate', array( $this, 'denied' ) );

		add_action( 'wp_ajax_zeko_ai_presets', array( $this, 'handle_presets' ) );
		add_action( 'wp_ajax_nopriv_zeko_ai_presets', array( $this, 'denied' ) );

		add_action( 'wp_ajax_zeko_ai_search', array( $this, 'handle_search' ) );
		add_action( 'wp_ajax_nopriv_zeko_ai_search', array( $this, 'handle_search' ) );

		add_action( 'wp_ajax_zeko_ai_recommend', array( $this, 'handle_recommend' ) );
		add_action( 'wp_ajax_nopriv_zeko_ai_recommend', array( $this, 'denied' ) );

		add_action( 'wp_ajax_zeko_ai_moderate', array( $this, 'handle_moderate' ) );
		add_action( 'wp_ajax_nopriv_zeko_ai_moderate', array( $this, 'denied' ) );

		add_action( 'wp_ajax_zeko_ai_vote', array( $this, 'handle_vote' ) );
		add_action( 'wp_ajax_nopriv_zeko_ai_vote', array( $this, 'denied' ) );
	}

	/**
	 * Register the shared public nonce key + action used by the front-end.
	 */
	public static function nonce_action(): string {
		return 'zeko_ai_public';
	}

	// ═══════════════════════════════════════════════════════════════.
	// ASSISTANT.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Handle chat.
	 */
	public function handle_chat(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$this->respond( array( 'error' => __( 'You must be logged in to chat.', 'zeko-ai' ) ), 401 );
		}
		check_ajax_referer( self::nonce_action(), 'nonce' );
		$this->throttle( 'chat', $user_id );

		$message         = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$conversation_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;

		if ( '' === trim( $message ) ) {
			$this->respond( array( 'error' => __( 'Please type a message.', 'zeko-ai' ) ), 400 );
		}

		if ( ! $conversation_id ) {
			$conversation_id = zeko_ai()->get_assistant()->start_conversation( $user_id );
		}

		$stream = ! empty( $_POST['stream'] )
			&& ! empty( zeko_ai_get_settings()['streaming_enabled'] )
			&& zeko_ai()->get_provider()->supports_streaming();

		if ( $stream ) {
			$this->handle_chat_stream( $user_id, $conversation_id, $message );
			return;
		}

		try {
			$result = zeko_ai()->get_assistant()->send_message( $user_id, $conversation_id, $message );
		} catch ( InvalidArgumentException $e ) {
			$this->respond( array( 'error' => $e->getMessage() ), 404 );
		} catch ( \Throwable $e ) {
			$this->fail( $e );
		}

		$this->respond(
			array(
				'success'         => true,
				'reply'           => $result['reply'],
				'conversation_id' => $result['conversation_id'],
				'provider'        => $result['provider'],
				'model'           => $result['model'],
				'knowledge_id'    => (int) ( $result['knowledge_id'] ?? 0 ),
				'confidence'      => (float) ( $result['confidence'] ?? 0 ),
				'intent'          => (string) ( $result['intent'] ?? '' ),
				'source'          => (string) ( $result['source'] ?? '' ),
				'guarded'         => ! empty( $result['guarded'] ),
				'follow_up'       => ! empty( $result['follow_up'] ),
				'suggestions'     => (array) ( $result['suggestions'] ?? array() ),
				'actions'         => (array) ( $result['actions'] ?? array() ),
			)
		);
	}

	/**
	 * Stream a chat reply over Server-Sent Events. Emits one 'delta' event per
	 * upstream chunk, then a single 'done' event carrying the full reply and
	 * metadata, all terminated by the standard [DONE] marker. Failures that
	 * happen before any delta surface as an 'error' event; a mid-stream error
	 * is reported too, but only the user message is kept in the log — a reply
	 * is only persisted once the stream has finished cleanly.
	 *
	 * @param int    $user_id User id.
	 * @param int    $conversation_id Conversation id.
	 * @param string $message Message.
	 */
	private function handle_chat_stream( int $user_id, int $conversation_id, string $message ): void {
		header( 'Content-Type: text/event-stream; charset=' . get_option( 'blog_charset' ) );
		header( 'Cache-Control: no-cache, no-transform' );
		header( 'X-Accel-Buffering: no' );

		if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		while ( ob_get_level() ) {
			ob_end_flush(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		flush(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		try {
			$result = zeko_ai()->get_assistant()->send_message_stream(
				$user_id,
				$conversation_id,
				$message,
				function ( string $delta ): void {
					$this->push_stream(
						array(
							'type' => 'delta',
							'text' => $delta,
						)
					);
				}
			);
		} catch ( InvalidArgumentException $e ) {
			$this->push_stream(
				array(
					'type'  => 'error',
					'error' => $e->getMessage(),
				)
			);
			$this->finish_stream();
			return;
		} catch ( \Throwable $e ) {
			error_log( 'Zeko AI stream: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			$this->push_stream(
				array(
					'type'  => 'error',
					'error' => __( 'The AI service is unavailable right now. Please try again in a moment.', 'zeko-ai' ),
				)
			);
			$this->finish_stream();
			return;
		}

		$this->push_stream(
			array(
				'type'            => 'done',
				'success'         => true,
				'reply'           => $result['reply'],
				'conversation_id' => $result['conversation_id'],
				'provider'        => $result['provider'],
				'model'           => $result['model'],
				'knowledge_id'    => (int) ( $result['knowledge_id'] ?? 0 ),
				'confidence'      => (float) ( $result['confidence'] ?? 0 ),
				'intent'          => (string) ( $result['intent'] ?? '' ),
				'source'          => (string) ( $result['source'] ?? '' ),
				'guarded'         => ! empty( $result['guarded'] ),
				'follow_up'       => ! empty( $result['follow_up'] ),
				'suggestions'     => (array) ( $result['suggestions'] ?? array() ),
				'actions'         => (array) ( $result['actions'] ?? array() ),
			)
		);
		$this->finish_stream();
	}

	/**
	 * Emit one SSE event with a JSON payload.
	 *
	 * @param array $payload Payload.
	 */
	private function push_stream( array $payload ): void {
		echo 'data: ' . wp_json_encode( $payload ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		flush(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Close an SSE response with the [DONE] marker and stop the request.
	 *
	 * @throws Zeko_AI_Ajax_Halt When an error occurs.
	 */
	private function finish_stream(): void {
		echo "data: [DONE]\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		flush(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		if ( apply_filters( 'zeko_ai_ajax_exit', true ) ) {
			exit;
		}
		throw new Zeko_AI_Ajax_Halt();
	}

	/**
	 * Handle new conversation.
	 */
	public function handle_new_conversation(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$this->respond( array( 'error' => __( 'You must be logged in.', 'zeko-ai' ) ), 401 );
		}
		check_ajax_referer( self::nonce_action(), 'nonce' );

		$conversation_id = zeko_ai()->get_assistant()->start_conversation( $user_id );
		$this->respond(
			array(
				'success'         => true,
				'conversation_id' => $conversation_id,
			)
		);
	}

	/**
	 * Handle conversations.
	 */
	public function handle_conversations(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$this->respond( array( 'error' => __( 'You must be logged in.', 'zeko-ai' ) ), 401 );
		}
		check_ajax_referer( self::nonce_action(), 'nonce' );

		$list = array();
		foreach ( $this->db->get_conversations(
			array(
				'user_id' => $user_id,
				'limit'   => 50,
			)
		) as $row ) {
			$list[] = array(
				'id'         => (int) $row->id,
				'title'      => (string) $row->title,
				'created_at' => (string) $row->created_at,
			);
		}
		$this->respond(
			array(
				'success'       => true,
				'conversations' => $list,
			)
		);
	}

	/**
	 * Handle delete conversation.
	 */
	public function handle_delete_conversation(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$this->respond( array( 'error' => __( 'You must be logged in.', 'zeko-ai' ) ), 401 );
		}
		check_ajax_referer( self::nonce_action(), 'nonce' );

		$conversation_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;
		$conversation    = $this->db->get_conversation( $conversation_id );
		if ( ! $conversation || (int) $conversation->user_id !== $user_id ) {
			$this->respond( array( 'error' => __( 'Conversation not found.', 'zeko-ai' ) ), 404 );
		}

		$this->db->delete_conversation( $conversation_id );
		$this->respond(
			array(
				'success'         => true,
				'conversation_id' => $conversation_id,
			)
		);
	}

	/**
	 * Handle messages.
	 */
	public function handle_messages(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$this->respond( array( 'error' => __( 'You must be logged in.', 'zeko-ai' ) ), 401 );
		}
		check_ajax_referer( self::nonce_action(), 'nonce' );

		$conversation_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;
		$conversation    = $this->db->get_conversation( $conversation_id );
		if ( ! $conversation || (int) $conversation->user_id !== $user_id ) {
			$this->respond( array( 'error' => __( 'Conversation not found.', 'zeko-ai' ) ), 404 );
		}

		$messages = array();
		foreach ( $this->db->get_messages( $conversation_id ) as $row ) {
			$messages[] = array(
				'id'         => (int) $row->id,
				'role'       => (string) $row->role,
				'content'    => (string) $row->content,
				'provider'   => (string) $row->provider,
				'created_at' => (string) $row->created_at,
			);
		}
		$this->respond(
			array(
				'success'         => true,
				'conversation_id' => $conversation_id,
				'messages'        => $messages,
			)
		);
	}

	// ═══════════════════════════════════════════════════════════════.
	// CONTENT GENERATION.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Handle generate.
	 */
	public function handle_generate(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$this->respond( array( 'error' => __( 'You must be logged in.', 'zeko-ai' ) ), 401 );
		}
		check_ajax_referer( self::nonce_action(), 'nonce' );
		$this->throttle( 'generate', $user_id );

		$preset_id = isset( $_POST['preset_id'] ) ? sanitize_key( $_POST['preset_id'] ) : '';
		$values    = isset( $_POST['values'] ) && is_array( $_POST['values'] )
			? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['values'] ) )
			: array();

		if ( '' === $preset_id ) {
			$this->respond( array( 'error' => __( 'Missing preset.', 'zeko-ai' ) ), 400 );
		}

		try {
			$result = zeko_ai()->get_content()->generate( $user_id, $preset_id, $values );
		} catch ( InvalidArgumentException $e ) {
			$this->respond( array( 'error' => $e->getMessage() ), 404 );
		} catch ( \Throwable $e ) {
			$this->fail( $e );
		}

		$this->respond(
			array(
				'success'   => true,
				'content'   => $result['content'],
				'preset_id' => $result['preset_id'],
				'module'    => $result['module'],
				'provider'  => $result['provider'],
				'model'     => $result['model'],
			)
		);
	}

	/**
	 * Handle presets.
	 */
	public function handle_presets(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$this->respond( array( 'error' => __( 'You must be logged in.', 'zeko-ai' ) ), 401 );
		}
		check_ajax_referer( self::nonce_action(), 'nonce' );

		$list = array();
		foreach ( zeko_ai()->get_content()->get_presets() as $id => $preset ) {
			$list[] = array(
				'id'          => $id,
				'label'       => (string) ( $preset['label'] ?? $id ),
				'module'      => (string) ( $preset['module'] ?? '' ),
				'description' => (string) ( $preset['description'] ?? '' ),
				'fields'      => (array) ( $preset['fields'] ?? array() ),
				'sample'      => (string) ( $preset['sample'] ?? '' ),
			);
		}
		$this->respond(
			array(
				'success' => true,
				'presets' => $list,
			)
		);
	}

	// ═══════════════════════════════════════════════════════════════.
	// SEARCH / RECOMMENDATIONS / MODERATION.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Handle search.
	 */
	public function handle_search(): void {
		check_ajax_referer( self::nonce_action(), 'nonce' );
		$this->throttle( 'search', get_current_user_id() );

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		if ( strlen( trim( $term ) ) < 2 ) {
			$this->respond( array( 'error' => __( 'Search term is too short.', 'zeko-ai' ) ), 400 );
		}

		$result  = zeko_ai()->get_search()->search_with_web(
			$term,
			array(
				'per_type' => 5,
				'limit'    => 30,
				'web'      => 3,
			)
		);
		$results = $result['results'];

		$by_type = array();
		foreach ( $results as $row ) {
			$by_type[ $row['type'] ][] = array(
				'id'      => $row['id'],
				'title'   => $row['title'],
				'url'     => $row['url'],
				'excerpt' => $row['excerpt'],
			);
		}

		$this->respond(
			array(
				'success' => true,
				'term'    => $term,
				'results' => $results,
				'by_type' => $by_type,
				'web'     => $result['web'],
				'total'   => $result['total'],
			)
		);
	}

	/**
	 * Handle recommend.
	 */
	public function handle_recommend(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$this->respond( array( 'error' => __( 'You must be logged in.', 'zeko-ai' ) ), 401 );
		}
		check_ajax_referer( self::nonce_action(), 'nonce' );
		$this->throttle( 'recommend', $user_id );

		$service = zeko_ai()->get_recommendations();
		$count   = $service->refresh( $user_id );

		$items = array();
		foreach ( $service->last() as $rec ) {
			$items[] = array(
				'id'        => (int) $rec['id'],
				'item_type' => (string) $rec['type'],
				'item_id'   => (int) $rec['id'],
				'title'     => (string) $rec['title'],
				'url'       => esc_url_raw( (string) $rec['url'] ),
				'score'     => round( (float) $rec['score'], 2 ),
				'reason'    => (string) $rec['reason'],
			);
		}

		$this->respond(
			array(
				'success'         => true,
				'refresh'         => $count,
				'recommendations' => $items,
			)
		);
	}

	/**
	 * Handle moderate.
	 */
	public function handle_moderate(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$this->respond( array( 'error' => __( 'You must be logged in.', 'zeko-ai' ) ), 401 );
		}
		check_ajax_referer( self::nonce_action(), 'nonce' );
		$this->throttle( 'moderate', $user_id );

		$content = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';
		if ( '' === trim( $content ) ) {
			$this->respond( array( 'error' => __( 'Nothing to check.', 'zeko-ai' ) ), 400 );
		}

		try {
			$verdict = zeko_ai()->get_provider()->moderate( $content );
		} catch ( \Throwable $e ) {
			$this->fail( $e );
		}
		$this->respond(
			array(
				'success'  => true,
				'decision' => $verdict['decision'],
				'score'    => (float) $verdict['score'],
				'reasons'  => (array) $verdict['reasons'],
			)
		);
	}

	/**
	 * Thumbs feedback on a knowledge answer. Increments the entry's positive
	 * or negative counter (and nudges its weight) so low-rated auto-learned
	 * rows can be pruned during maintenance.
	 */
	public function handle_vote(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$this->respond( array( 'error' => __( 'You must be logged in.', 'zeko-ai' ) ), 401 );
		}
		check_ajax_referer( self::nonce_action(), 'nonce' );

		$knowledge_id = isset( $_POST['knowledge_id'] ) ? absint( $_POST['knowledge_id'] ) : 0;
		$vote         = isset( $_POST['vote'] ) ? sanitize_key( $_POST['vote'] ) : '';

		if ( ! $knowledge_id ) {
			$this->respond( array( 'error' => __( 'Missing knowledge entry.', 'zeko-ai' ) ), 400 );
		}
		if ( ! in_array( $vote, array( 'positive', 'negative' ), true ) ) {
			$this->respond( array( 'error' => __( 'Invalid vote.', 'zeko-ai' ) ), 400 );
		}

		$row = $this->db->vote_knowledge( $knowledge_id, $vote, $user_id );
		if ( null === $row ) {
			$this->respond( array( 'error' => __( 'Knowledge entry not found.', 'zeko-ai' ) ), 404 );
		}
		if ( false === $row ) {
			$this->respond( array( 'error' => __( 'You already rated this answer.', 'zeko-ai' ) ), 409 );
		}

		// Negative feedback requeues the question as a self-training gap so.
		// admins can teach a better answer.
		if ( 'negative' === $vote ) {
			$this->db->insert_agent_query(
				array(
					'user_id'  => $user_id,
					'query'    => mb_substr( (string) $row->question, 0, 500 ),
					'intent'   => 'general',
					'answered' => 0,
					'mode'     => 'demoted',
				)
			);
		}

		$this->respond(
			array(
				'success'      => true,
				'knowledge_id' => $knowledge_id,
				'vote'         => $vote,
				'positive'     => (int) $row->positive,
				'negative'     => (int) $row->negative,
			)
		);
	}

	/**
	 * Respond.
	 *
	 * @param array $payload Payload.
	 * @param int   $status Status.
	 * @throws Zeko_AI_Ajax_Halt When an error occurs.
	 */
	private function respond( array $payload, int $status = 200 ): void {
		if ( apply_filters( 'zeko_ai_ajax_exit', true ) ) {
			wp_send_json( $payload, $status );
		}
		echo wp_json_encode( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput
		throw new Zeko_AI_Ajax_Halt();
	}

	/**
	 * Convert a provider failure into a friendly JSON error instead of a raw
	 * fatal, so the UI can show a helpful message. Validation errors keep
	 * their message; runtime/provider errors are logged and masked.
	 *
	 * @param \Throwable $e E.
	 * @throws \Throwable When an error occurs.
	 */
	private function fail( \Throwable $e ): void {
		if ( $e instanceof Zeko_AI_Ajax_Halt ) {
			throw $e;
		}
		if ( $e instanceof InvalidArgumentException ) {
			$this->respond( array( 'error' => $e->getMessage() ), 404 );
		}
		error_log( 'Zeko AI: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions

		if ( function_exists( 'zeko_ai' ) && zeko_ai() ) {
			zeko_ai()->notify_admin(
				'AI provider error',
				sprintf(
					"The AI provider failed while handling a request:\n%s\n\nCheck the provider settings and test the connection.",
					$e->getMessage()
				),
				'provider_error'
			);
		}

		$this->respond( array( 'error' => __( 'The AI service is unavailable right now. Please try again in a moment.', 'zeko-ai' ) ), 500 );
	}

	/**
	 * Nopriv fallback for guests.
	 */
	public function denied(): void {
		$this->respond( array( 'error' => __( 'You must be logged in.', 'zeko-ai' ) ), 401 );
	}

	/**
	 * Enforce the per-feature rate limit and abort with 429 when the caller
	 * exceeded it. Keeps provider calls (chat/generate/moderate/search/
	 * recommend) from being spammed into unbounded cost.
	 *
	 * @param string $feature Rate-limit bucket.
	 * @param int    $user_id User id.
	 */
	private function throttle( string $feature, int $user_id ): void {
		$wait = zeko_ai()->get_limiter()->limit( $feature, $user_id );
		if ( $wait > 0 ) {
			$this->respond(
				array(
					'error'       => sprintf(
					/* translators: %d: number of seconds to wait. */
						__( 'Too many requests. Please try again in %d second(s).', 'zeko-ai' ),
						$wait
					),
					'retry_after' => $wait,
				),
				429
			);
		}
	}
}
