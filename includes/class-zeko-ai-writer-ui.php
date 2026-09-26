<?php
/**
 * Zeko AI "Generate with AI" inline button.
 *
 * A small, dependency-free helper any Zeko module template can render next
 * to a textarea/editor field. It reuses the content presets and AJAX
 * endpoints: the button exposes a preset and a target CSS selector, and the
 * front-end script fetches the preset's prompt fields, generates a draft,
 * and inserts it into the target field (falling back to copy-to-clipboard
 * when the field is a rich editor the script cannot drive safely).
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Writer_UI. */
class Zeko_AI_Writer_UI {

	/**
	 * Make sure the shared AI script + styles are on the page.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( 'zeko-ai' );
		wp_enqueue_script( 'zeko-ai' );
	}

	/**
	 * Render one inline writer button for a content preset.
	 *
	 * @type string $preset Preset id (see Zeko_AI_Content presets).
	 * @type string $target CSS selector of the field to fill.
	 * @type string $label  Button label.
	 * }
	 *
	 * @return string
	 * @param array $args {.
	 */
	public static function button( array $args = array() ): string {
		if ( ! is_user_logged_in() || ! zeko_ai()->get_settings()->is_enabled( 'content' ) ) {
			return '';
		}

		$args   = wp_parse_args(
			$args,
			array(
				'preset' => '',
				'target' => '',
				'label'  => __( 'Generate with AI', 'zeko-ai' ),
			)
		);
		$preset = (string) $args['preset'];

		if ( '' === $preset ) {
			return '';
		}

		self::enqueue();

		return '<div class="zeko-ai-writer-inline" data-zeko-ai-preset="' . esc_attr( $preset ) . '" data-zeko-ai-target="' . esc_attr( (string) $args['target'] ) . '">'
			. '<button type="button" class="zeko-ai-writer-inline-go">' . esc_html( (string) $args['label'] ) . '</button>'
			. '<div class="zeko-ai-writer-inline-body"></div>'
			. '<div class="zeko-ai-writer-inline-status" role="status" aria-live="polite"></div>'
			. '</div>';
	}

	/**
	 * Shortcode wrapper: [zeko_ai_generate preset="job_description" target="#zf-description" label="..."]
	 *
	 * @return string
	 * @param array $atts * @return string.
	 */
	public static function shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'preset' => '',
				'target' => '',
				'label'  => '',
			),
			$atts,
			'zeko_ai_generate'
		);

		$args = array(
			'preset' => (string) $atts['preset'],
			'target' => (string) $atts['target'],
		);
		if ( '' !== (string) $atts['label'] ) {
			$args['label'] = (string) $atts['label'];
		}

		return self::button( $args );
	}
}
