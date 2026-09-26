<?php
/**
 * Zeko AI REST API (zeko-ai/v1).
 *
 * Exposes the assistant, unified search, recommendations, knowledge and
 * feedback endpoints for headless front-ends. Chat/feedback/knowledge/
 * recommendations/conversations require a logged-in member; search is public
 * (mirroring the AJAX handler). All JSON, no admin nonce required.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_REST. */
class Zeko_AI_REST {

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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'zeko-ai/v1',
			'/search',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'term'  => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ) {
								return is_string( $value ) && strlen( trim( $value ) ) >= 2;
						},
					),
					'limit' => array(
						'sanitize_callback' => 'absint',
						'default'           => 10,
					),
				),
			)
		);

		register_rest_route(
			'zeko-ai/v1',
			'/chat',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_chat' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
				'args'                => array(
					'message'         => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'conversation_id' => array(
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
				),
			)
		);

		register_rest_route(
			'zeko-ai/v1',
			'/conversations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_conversations' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			'zeko-ai/v1',
			'/conversations/(?P<id>\d+)',
			array(
				'methods'             => array( \WP_REST_Server::READABLE, \WP_REST_Server::DELETABLE ),
				'callback'            => array( $this, 'handle_conversation' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);

		register_rest_route(
			'zeko-ai/v1',
			'/recommendations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_recommendations' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			'zeko-ai/v1',
			'/knowledge',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_knowledge' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			'zeko-ai/v1',
			'/feedback',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_feedback' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
				'args'                => array(
					'knowledge_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'vote'         => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static function ( $value ) {
							return in_array( $value, array( 'positive', 'negative' ), true );
						},
					),
				),
			)
		);

		register_rest_route(
			'zeko-ai/v1',
			'/memory',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_memory' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * Search.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_search( \WP_REST_Request $request ): \WP_REST_Response {
		$term  = (string) $request['term'];
		$limit = (int) ( $request['limit'] ?? 10 );

		$wait = zeko_ai()->get_limiter()->limit( 'search', get_current_user_id() );
		if ( $wait > 0 ) {
			return new \WP_REST_Response(
				array(
					'error'       => __( 'Too many requests. Please try again in a moment.', 'zeko-ai' ),
					'retry_after' => $wait,
				),
				429
			);
		}

		$result = zeko_ai()->get_search()->search_with_web(
			$term,
			array(
				'per_type' => 5,
				'limit'    => $limit,
				'web'      => 3,
			)
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'term'    => $term,
				'total'   => $result['total'],
				'results' => $result['results'],
				'web'     => $result['web'],
			)
		);
	}

	/**
	 * Post chat.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function post_chat( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id         = get_current_user_id();
		$message         = (string) $request['message'];
		$conversation_id = (int) ( $request['conversation_id'] ?? 0 );

		if ( '' === trim( $message ) ) {
			return new \WP_REST_Response( array( 'error' => __( 'Please type a message.', 'zeko-ai' ) ), 400 );
		}

		$wait = zeko_ai()->get_limiter()->limit( 'chat', $user_id );
		if ( $wait > 0 ) {
			return new \WP_REST_Response(
				array(
					'error'       => __( 'Too many requests. Please try again in a moment.', 'zeko-ai' ),
					'retry_after' => $wait,
				),
				429
			);
		}

		if ( ! $conversation_id ) {
			$conversation_id = zeko_ai()->get_assistant()->start_conversation( $user_id );
		}

		try {
			$result = zeko_ai()->get_assistant()->send_message( $user_id, $conversation_id, $message );
		} catch ( InvalidArgumentException $e ) {
			return new \WP_REST_Response( array( 'error' => $e->getMessage() ), 404 );
		} catch ( \Throwable $e ) {
			error_log( 'Zeko AI REST chat: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return new \WP_REST_Response( array( 'error' => __( 'The AI service is unavailable right now.', 'zeko-ai' ) ), 500 );
		}

		return rest_ensure_response(
			array(
				'success'         => true,
				'reply'           => $result['reply'],
				'conversation_id' => $result['conversation_id'],
				'provider'        => $result['provider'],
				'model'           => $result['model'],
				'knowledge_id'    => (int) ( $result['knowledge_id'] ?? 0 ),
				'confidence'      => (float) ( $result['confidence'] ?? 0 ),
				'suggestions'     => (array) ( $result['suggestions'] ?? array() ),
				'actions'         => (array) ( $result['actions'] ?? array() ),
				'follow_up'       => ! empty( $result['follow_up'] ),
			)
		);
	}

	/**
	 * Conversations.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_conversations( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		$list = array();
		foreach ( $this->db->get_conversations(
			array(
				'user_id' => get_current_user_id(),
				'limit'   => 50,
			)
		) as $row ) {
			$list[] = array(
				'id'         => (int) $row->id,
				'title'      => (string) $row->title,
				'created_at' => (string) $row->created_at,
			);
		}
		return rest_ensure_response(
			array(
				'success'       => true,
				'conversations' => $list,
			)
		);
	}

	/**
	 * Handle conversation.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function handle_conversation( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id         = get_current_user_id();
		$conversation_id = (int) ( $request['id'] ?? 0 );
		$conversation    = $this->db->get_conversation( $conversation_id );

		if ( ! $conversation || (int) $conversation->user_id !== $user_id ) {
			return new \WP_REST_Response( array( 'error' => __( 'Conversation not found.', 'zeko-ai' ) ), 404 );
		}

		if ( \WP_REST_Server::DELETABLE === $request->get_method() ) {
			$this->db->delete_conversation( $conversation_id );
			return rest_ensure_response(
				array(
					'success'         => true,
					'conversation_id' => $conversation_id,
				)
			);
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

		return rest_ensure_response(
			array(
				'success'         => true,
				'conversation_id' => $conversation_id,
				'messages'        => $messages,
			)
		);
	}

	/**
	 * Recommendations.
	 *
	 * @param \WP_REST_Request $_request request.
	 */
	public function get_recommendations( \WP_REST_Request $_request ): \WP_REST_Response {
		$user_id = get_current_user_id();

		$wait = zeko_ai()->get_limiter()->limit( 'recommend', $user_id );
		if ( $wait > 0 ) {
			return new \WP_REST_Response(
				array(
					'error'       => __( 'Too many requests. Please try again in a moment.', 'zeko-ai' ),
					'retry_after' => $wait,
				),
				429
			);
		}

		$service = zeko_ai()->get_recommendations();
		$count   = $service->refresh( $user_id );

		$items = array();
		foreach ( $service->last() as $rec ) {
			$items[] = array(
				'item_type' => (string) $rec['type'],
				'item_id'   => (int) $rec['id'],
				'title'     => (string) $rec['title'],
				'url'       => esc_url_raw( (string) $rec['url'] ),
				'score'     => round( (float) $rec['score'], 2 ),
				'reason'    => (string) $rec['reason'],
			);
		}

		return rest_ensure_response(
			array(
				'success'         => true,
				'refresh'         => $count,
				'recommendations' => $items,
			)
		);
	}

	/**
	 * Knowledge.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_knowledge( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		$entries = array();
		foreach ( $this->db->get_knowledge_entries( array( 'limit' => 100 ) ) as $row ) {
			$entries[] = array(
				'id'       => (int) $row->id,
				'question' => (string) $row->question,
				'answer'   => (string) $row->answer,
				'module'   => (string) $row->module,
				'source'   => (string) $row->source,
				'used'     => (int) $row->use_count,
				'positive' => (int) $row->positive,
				'negative' => (int) $row->negative,
			);
		}
		return rest_ensure_response(
			array(
				'success'   => true,
				'total'     => count( $entries ),
				'knowledge' => $entries,
			)
		);
	}

	/**
	 * Post feedback.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function post_feedback( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id      = get_current_user_id();
		$knowledge_id = (int) $request['knowledge_id'];
		$vote         = (string) $request['vote'];

		$row = $this->db->vote_knowledge( $knowledge_id, $vote, $user_id );
		if ( null === $row ) {
			return new \WP_REST_Response( array( 'error' => __( 'Knowledge entry not found.', 'zeko-ai' ) ), 404 );
		}
		if ( false === $row ) {
			return new \WP_REST_Response( array( 'error' => __( 'You already rated this answer.', 'zeko-ai' ) ), 409 );
		}

		// Negative feedback requeues the question as a self-training gap.
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

		return rest_ensure_response(
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
	 * Memory.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_memory( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		$memory = zeko_ai()->get_memory()->facts( get_current_user_id() );
		return rest_ensure_response(
			array(
				'success' => true,
				'memory'  => $memory,
			)
		);
	}
}
