<?php
/**
 * Groq provider (OpenAI-compatible inference) over HTTPS.
 *
 * Uses the configured API key, model and base URL. All network traffic goes
 * through the shared compat base (filterable for tests).
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Groq_Provider. */
class Zeko_AI_Groq_Provider extends Zeko_AI_OpenAI_Compat_Provider {

	/**
	 * Slug.
	 *
	 * @var mixed Slug.
	 */
	protected $slug = 'groq';

	/**
	 * Default base url.
	 */
	protected function default_base_url(): string {
		return 'https://api.groq.com/openai/v1';
	}

	/**
	 * Default model.
	 */
	protected function default_model(): string {
		return 'llama-3.3-70b-versatile';
	}
}
