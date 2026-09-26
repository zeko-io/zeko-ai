<?php
/**
 * Zeko AI public shortcodes and floating widget.
 *
 * Provides:
 *   [zeko_ai_assistant]      chat with the AI assistant
 *   [zeko_ai_search]         unified cross-module search
 *   [zeko_ai_recommendations] personalized recommendations
 *   [zeko_ai_writer]         content generation presets
 * plus the optional floating chat widget.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Shortcodes. */
class Zeko_AI_Shortcodes {

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

		add_shortcode( 'zeko_ai_assistant', array( $this, 'assistant_shortcode' ) );
		add_shortcode( 'zeko_ai_search', array( $this, 'search_shortcode' ) );
		add_shortcode( 'zeko_ai_recommendations', array( $this, 'recommendations_shortcode' ) );
		add_shortcode( 'zeko_ai_writer', array( $this, 'writer_shortcode' ) );
		add_shortcode( 'zeko_ai_generate', array( 'Zeko_AI_Writer_UI', 'shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_footer', array( $this, 'maybe_render_widget' ) );
	}

	/**
	 * Assets.
	 */
	public function register_assets(): void {
		wp_register_style( 'zeko-ai', ZEKO_AI_PLUGIN_URL . 'assets/css/zeko-ai.css', array( 'zeko-core' ), ZEKO_AI_VERSION );
		wp_register_script( 'zeko-ai', ZEKO_AI_PLUGIN_URL . 'assets/js/zeko-ai.js', array(), ZEKO_AI_VERSION, true );
		wp_localize_script(
			'zeko-ai',
			'ZekoAI',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Zeko_AI_Ajax::nonce_action() ),
				'i18n'    => array(
					'sending'        => __( 'Thinking…', 'zeko-ai' ),
					'error'          => __( 'Something went wrong. Please try again.', 'zeko-ai' ),
					'typeHere'       => __( 'Ask Zeko…', 'zeko-ai' ),
					'generating'     => __( 'Generating…', 'zeko-ai' ),
					'inlineGenerate' => __( 'Generate draft', 'zeko-ai' ),
					'inlineInsert'   => __( 'Insert into field', 'zeko-ai' ),
					'inlineInserted' => __( 'Inserted into the field.', 'zeko-ai' ),
					'inlineCopied'   => __( 'Inserted and copied to clipboard — paste it into the editor.', 'zeko-ai' ),
				),
			)
		);
	}

	/**
	 * Shortcode shell for the assistant chat.
	 *
	 * @param array  $atts Atts.
	 * @param string $_content content.
	 */
	public function assistant_shortcode( array $atts = array(), string $_content = '' ): string {
		$atts = shortcode_atts( array( 'height' => '480px' ), $atts, 'zeko_ai_assistant' );

		if ( ! is_user_logged_in() ) {
			return '<p class="zeko-ai-gate">' . esc_html__( 'Please log in to chat with the Zeko assistant.', 'zeko-ai' ) . '</p>';
		}
		if ( ! zeko_ai()->get_settings()->is_enabled( 'assistant' ) ) {
			return '';
		}

		wp_enqueue_style( 'zeko-ai' );
		wp_enqueue_script( 'zeko-ai' );

		return $this->render_chat_shell(
			'ai-assistant',
			array(
				'data-zeko-ai-chat'      => '',
				'data-zeko-ai-height'    => esc_attr( $atts['height'] ),
				'data-zeko-ai-new-label' => esc_attr__( 'New chat', 'zeko-ai' ),
			)
		);
	}

	/**
	 * Shortcode shell for unified search.
	 *
	 * @param array  $atts Atts.
	 * @param string $_content content.
	 */
	public function search_shortcode( array $atts = array(), string $_content = '' ): string {
		$atts = shortcode_atts( array(), $atts, 'zeko_ai_search' );

		if ( ! zeko_ai()->get_settings()->is_enabled( 'search' ) ) {
			return '';
		}

		wp_enqueue_style( 'zeko-ai' );
		wp_enqueue_script( 'zeko-ai' );

		return $this->render_shell(
			'ai-search',
			array(
				'data-zeko-ai-search'      => '',
				'data-zeko-ai-placeholder' => esc_attr__( 'Search jobs, courses, questions, products, projects and mentors…', 'zeko-ai' ),
			)
		);
	}

	/**
	 * Shortcode shell for personalized recommendations.
	 *
	 * @param array  $atts Atts.
	 * @param string $_content content.
	 */
	public function recommendations_shortcode( array $atts = array(), string $_content = '' ): string {
		$atts = shortcode_atts( array(), $atts, 'zeko_ai_recommendations' );

		if ( ! is_user_logged_in() ) {
			return '<p class="zeko-ai-gate">' . esc_html__( 'Please log in to see personalized recommendations.', 'zeko-ai' ) . '</p>';
		}
		if ( ! zeko_ai()->get_settings()->is_enabled( 'recommendations' ) ) {
			return '';
		}

		wp_enqueue_style( 'zeko-ai' );
		wp_enqueue_script( 'zeko-ai' );

		return $this->render_shell(
			'ai-recommendations',
			array(
				'data-zeko-ai-recommendations' => '',
				'data-zeko-ai-refresh-label'   => esc_attr__( 'Refresh recommendations', 'zeko-ai' ),
			)
		);
	}

	/**
	 * Shortcode shell for the content writer.
	 *
	 * @param array  $atts Atts.
	 * @param string $content Content.
	 */
	public function writer_shortcode( array $atts = array(), string $content = '' ): string {
		unset( $content );
		$atts = shortcode_atts( array(), $atts, 'zeko_ai_writer' );

		if ( ! is_user_logged_in() ) {
			return '<p class="zeko-ai-gate">' . esc_html__( 'Please log in to use the AI writer.', 'zeko-ai' ) . '</p>';
		}
		if ( ! zeko_ai()->get_settings()->is_enabled( 'content' ) ) {
			return '';
		}

		wp_enqueue_style( 'zeko-ai' );
		wp_enqueue_script( 'zeko-ai' );

		return $this->render_shell(
			'ai-writer',
			array(
				'data-zeko-ai-writer'   => '',
				'data-zeko-ai-generate' => esc_attr__( 'Generate', 'zeko-ai' ),
			)
		);
	}

	/**
	 * Floating assistant button + popup.
	 */
	public function maybe_render_widget(): void {
		if ( ! zeko_ai()->get_settings()->is_enabled( 'assistant' ) ) {
			return;
		}
		if ( empty( zeko_ai()->get_settings()->get( 'floating_widget', 1 ) ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( apply_filters( 'zeko_ai_widget_hide', false ) ) {
			return;
		}

		wp_enqueue_style( 'zeko-ai' );
		wp_enqueue_script( 'zeko-ai' );

		echo '<div class="zeko-ai-widget" data-zeko-ai-chat="widget" data-zeko-ai-widget="'
			. esc_attr__( 'Zeko', 'zeko-ai' ) . '"></div>';
	}

	/**
	 * Render shell.
	 *
	 * @param string $id Id.
	 * @param array  $attrs Attrs.
	 */
	private function render_shell( string $id, array $attrs ): string {
		$attr_html = '';
		foreach ( $attrs as $key => $value ) {
			$attr_html .= ' ' . $key . '="' . esc_attr( $value ) . '"';
		}
		return '<div class="zeko-ai" id="' . esc_attr( $id ) . '"' . $attr_html . '>'
			. '<noscript>' . esc_html__( 'JavaScript is required for Zeko AI features.', 'zeko-ai' ) . '</noscript>'
			. '</div>';
	}

	/**
	 * Render chat shell.
	 *
	 * @param string $id Id.
	 * @param array  $attrs Attrs.
	 */
	private function render_chat_shell( string $id, array $attrs ): string {
		$attr_html = '';
		foreach ( $attrs as $key => $value ) {
			$attr_html .= ' ' . $key . '="' . esc_attr( $value ) . '"';
		}
		return '<div class="zeko-ai zeko-ai-chat" id="' . esc_attr( $id ) . '"' . $attr_html . '>'
			. '<div class="zeko-ai-chat-log" aria-live="polite"></div>'
			. '<div class="zeko-ai-chat-compose">'
			. '<textarea class="zeko-ai-chat-input" rows="1" placeholder="' . esc_attr__( 'Ask Zeko…', 'zeko-ai' ) . '" aria-label="' . esc_attr__( 'Message', 'zeko-ai' ) . '"></textarea>'
			. '<button type="button" class="zeko-ai-chat-send btn">' . esc_html__( 'Send', 'zeko-ai' ) . '</button>'
			. '</div>'
			. '</div>';
	}
}
