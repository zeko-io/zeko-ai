<?php
/**
 * Google Gemini provider (OpenAI-compatible endpoint) over HTTPS.
 *
 * Uses the configured API key, model and base URL. Sends the API key both as
 * the standard Authorization header (accepted by the OpenAI-compat surface)
 * and as the native x-goog-api-key header for maximum compatibility. All
 * network traffic goes through the shared compat base (filterable for tests).
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Gemini_Provider. */
class Zeko_AI_Gemini_Provider extends Zeko_AI_OpenAI_Compat_Provider {

	/**
	 * Slug.
	 *
	 * @var mixed Slug.
	 */
	protected $slug = 'gemini';

	/**
	 * Default base url.
	 */
	protected function default_base_url(): string {
		return 'https://generativelanguage.googleapis.com/v1beta/openai';
	}

	/**
	 * Default model.
	 */
	protected function default_model(): string {
		return 'gemini-2.0-flash';
	}

	/**
	 * Extra headers.
	 */
	protected function extra_headers(): array {
		return array(
			'x-goog-api-key' => $this->api_key(),
		);
	}
}
