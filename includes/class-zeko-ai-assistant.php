<?php
/**
 * Zeko AI assistant (chatbot).
 *
 * Builds a personalized system prompt from the user's cross-module context,
 * persists conversations and messages, and delegates generation to the
 * active provider.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Assistant. */
class Zeko_AI_Assistant {

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
	}

	/**
	 * Start (or reuse) a conversation for a user.
	 *
	 * @param int    $user_id User id.
	 * @param string $title Title.
	 */
	public function start_conversation( int $user_id, string $title = '' ): int {
		if ( '' === $title ) {
			$title = __( 'New conversation', 'zeko-ai' );
		}
		return $this->db->insert_conversation(
			array(
				'user_id' => $user_id,
				'title'   => $title,
				'context' => 'assistant',
			)
		);
	}

	/**
	 * Answer a user message inside a conversation.
	 * Persists the user turn and the assistant reply, records usage, and
	 * returns the reply plus metadata.
	 *
	 * @return array{reply:string, conversation_id:int, provider:string, model:string, tokens_in:int, tokens_out:int}
	 * @param int    $user_id * @param int    $conversation_id.
	 * @param int    $conversation_id Conversation id.
	 * @param string $message * @return array{reply:string, conversation_id:int, provider:string, model:string, tokens_in:int, tokens_out:int}.
	 * @throws InvalidArgumentException When an error occurs.
	 */
	public function send_message( int $user_id, int $conversation_id, string $message ): array {
		$conversation = $this->db->get_conversation( $conversation_id );
		if ( ! $conversation || (int) $conversation->user_id !== $user_id ) {
			throw new InvalidArgumentException( 'Conversation not found.' );
		}

		$this->db->insert_message(
			array(
				'conversation_id' => $conversation_id,
				'role'            => 'user',
				'content'         => $message,
			)
		);

		$history  = $this->db->get_messages( $conversation_id );
		$messages = array();
		foreach ( $history as $row ) {
			$messages[] = array(
				'role'    => 'user' === $row->role ? 'user' : 'assistant',
				'content' => (string) $row->content,
			);
		}

		$system = $this->build_system_prompt( $user_id );
		$chat   = array_merge(
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
			),
			$messages
		);

		$result = zeko_ai()->get_provider()->chat(
			$chat,
			array(
				'max_tokens'  => 800,
				'temperature' => 0.7,
				'user_id'     => $user_id,
			)
		);

		$this->db->insert_message(
			array(
				'conversation_id' => $conversation_id,
				'role'            => 'assistant',
				'content'         => (string) $result['content'],
				'provider'        => zeko_ai()->get_provider()->name(),
				'model'           => (string) $result['model'],
				'tokens_in'       => (int) $result['tokens_in'],
				'tokens_out'      => (int) $result['tokens_out'],
			)
		);

		$this->db->update_conversation(
			$conversation_id,
			array(
				'title' => '' === (string) $conversation->title || 'New conversation' === (string) $conversation->title
					? mb_substr( wp_strip_all_tags( $message ), 0, 60 )
					: (string) $conversation->title,
			)
		);

		// Remember what the member last discussed so a future conversation can.
		// resume context (gated by the same learning setting as agent memory).
		if ( ! empty( zeko_ai_get_settings()['agent_learning'] ) && function_exists( 'zeko_ai' ) && method_exists( zeko_ai(), 'get_memory' ) ) {
			zeko_ai()->get_memory()->recap( $user_id, $message );
		}

		zeko_ai()->record_usage( $user_id, 'assistant', $result );

		do_action( 'zeko_ai_conversation_replied', $user_id, $conversation_id );

		return array(
			'reply'           => (string) $result['content'],
			'conversation_id' => $conversation_id,
			'provider'        => zeko_ai()->get_provider()->name(),
			'model'           => (string) $result['model'],
			'tokens_in'       => (int) $result['tokens_in'],
			'tokens_out'      => (int) $result['tokens_out'],
			'knowledge_id'    => (int) ( $result['raw']['knowledge_id'] ?? 0 ),
			'confidence'      => (float) ( $result['raw']['confidence'] ?? 0 ),
			'intent'          => (string) ( $result['raw']['intent'] ?? '' ),
			'source'          => (string) ( $result['raw']['source'] ?? '' ),
			'guarded'         => ! empty( $result['raw']['guarded'] ),
			'follow_up'       => ! empty( $result['raw']['follow_up'] ),
			'suggestions'     => (array) ( $result['raw']['suggestions'] ?? array() ),
			'actions'         => (array) ( $result['raw']['actions'] ?? array() ),
			'memory'          => (array) ( $result['raw']['memory'] ?? array() ),
		);
	}

	/**
	 * Personalized system prompt built from the user's cross-module context
	 * (gathered from every integration via the zeko_ai_assistant_context
	 * filter).
	 *
	 * @param int $user_id User id.
	 */
	public function build_system_prompt( int $user_id ): string {
		unset( $user_id );
		$blocks = apply_filters( 'zeko_ai_assistant_context', array() );

		$prompt = 'You are Zeko AI, the assistant for the Zeko community platform. '
			. 'Users can find jobs, take courses, ask and answer questions, shop, freelance, find mentors and dates, and manage wallets and rewards. '
			. "Be friendly, concise and helpful, and reference the user's own ecosystem activity when relevant.";

		$context = '';
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['items'] ) ) {
				continue;
			}
			$label    = isset( $block['label'] ) ? (string) $block['label'] : (string) ( $block['module'] ?? 'activity' );
			$context .= "\n\n" . $label . ':';
			foreach ( (array) $block['items'] as $item ) {
				$context .= "\n- " . (string) ( $item['title'] ?? '' ) . ' — ' . (string) ( $item['snippet'] ?? '' );
			}
		}

		if ( '' !== $context ) {
			$prompt .= "\n\nThe user's current ecosystem context:\n" . $context;
		}

		$prompt .= "\n\nIf asked about content moderation or safety, explain that Zeko AI reviews community content and flags anything harmful for human review.";

		return $prompt;
	}

	/**
	 * History.
	 *
	 * @param int $conversation_id Conversation id.
	 */
	public function get_history( int $conversation_id ): array {
		return $this->db->get_messages( $conversation_id );
	}
}
