<?php
/**
 * OpenRouter provider (OpenAI-compatible aggregator) over HTTPS.
 *
 * Uses the configured API key, model and base URL. Sends an HTTP-Referer and
 * X-Title header so OpenRouter can attribute usage to the site. All network
 * traffic goes through the shared compat base (filterable for tests).
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_OpenRouter_Provider. */
class Zeko_AI_OpenRouter_Provider extends Zeko_AI_OpenAI_Compat_Provider {

	/**
	 * Slug.
	 *
	 * @var mixed Slug.
	 */
	protected $slug = 'openrouter';

	/**
	 * Default base url.
	 */
	protected function default_base_url(): string {
		return 'https://openrouter.ai/api/v1';
	}

	/**
	 * Default model.
	 */
	protected function default_model(): string {
		return 'openai/gpt-4o-mini';
	}

	/**
	 * Extra headers.
	 */
	protected function extra_headers(): array {
		return array(
			'HTTP-Referer' => home_url( '/' ),
			'X-Title'      => get_bloginfo( 'name' ),
		);
	}
}
