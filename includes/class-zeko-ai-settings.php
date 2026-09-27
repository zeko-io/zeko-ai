<?php
/**
 * Typed settings helpers for Zeko AI.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Settings. */
class Zeko_AI_Settings {

	/**
	 * Settings.
	 *
	 * @var mixed Settings.
	 */
	private $settings;

	/**
	 * Construct.
	 *
	 * @param array $settings Settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Defaults.
	 */
	public static function defaults(): array {
		return array(
			'provider'                => 'mock',
			'openai_key'              => '',
			'openai_model'            => 'gpt-4o-mini',
			'openai_base_url'         => 'https://api.openai.com/v1',
			'anthropic_key'           => '',
			'anthropic_model'         => 'claude-3-5-haiku-latest',
			'openrouter_key'          => '',
			'openrouter_model'        => 'openai/gpt-4o-mini',
			'openrouter_base_url'     => 'https://openrouter.ai/api/v1',
			'gemini_key'              => '',
			'gemini_model'            => 'gemini-2.0-flash',
			'gemini_base_url'         => 'https://generativelanguage.googleapis.com/v1beta/openai',
			'groq_key'                => '',
			'groq_model'              => 'llama-3.3-70b-versatile',
			'groq_base_url'           => 'https://api.groq.com/openai/v1',
			'deepseek_key'            => '',
			'deepseek_model'          => 'deepseek-chat',
			'deepseek_base_url'       => 'https://api.deepseek.com/v1',
			'cloud_model'             => 'zeko-cloud',
			'moderation_enabled'      => 1,
			'moderation_threshold'    => 0.7,
			'feature_assistant'       => 1,
			'feature_content'         => 1,
			'feature_search'          => 1,
			'feature_recommendations' => 1,
			'feature_moderation'      => 1,
			'feature_analytics'       => 0,
			'floating_widget'         => 1,
			'streaming_enabled'       => 1,
			'agent_world_knowledge'   => 0,
			'agent_learning'          => 1,
			'agent_auto_learn'        => 0,
			'agent_email_alerts'      => 0,
			'agent_radar_digest'      => 0,
			'web_search_enabled'      => 0,
			'web_search_provider'     => 'duckduckgo',
			'google_search_api_key'   => '',
			'google_search_engine_id' => '',
			'bing_search_key'         => '',
			'brave_search_key'        => '',
			'serper_search_key'       => '',
			'web_search_max_results'  => 5,
		);
	}

	/**
	 * Sanitize.
	 *
	 * @param mixed $input Input.
	 */
	public static function sanitize( $input ): array {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();

		$out = array(
			'provider'                => in_array( $input['provider'] ?? '', array( 'mock', 'openai', 'anthropic', 'openrouter', 'gemini', 'groq', 'deepseek', 'cloud', 'agent' ), true ) ? $input['provider'] : $defaults['provider'],
			'openai_key'              => isset( $input['openai_key'] ) ? sanitize_text_field( (string) $input['openai_key'] ) : '',
			'openai_model'            => isset( $input['openai_model'] ) ? mb_substr( sanitize_text_field( (string) $input['openai_model'] ), 0, 100 ) : $defaults['openai_model'],
			'openai_base_url'         => isset( $input['openai_base_url'] ) ? esc_url_raw( (string) $input['openai_base_url'] ) : $defaults['openai_base_url'],
			'anthropic_key'           => isset( $input['anthropic_key'] ) ? sanitize_text_field( (string) $input['anthropic_key'] ) : '',
			'anthropic_model'         => isset( $input['anthropic_model'] ) ? mb_substr( sanitize_text_field( (string) $input['anthropic_model'] ), 0, 100 ) : $defaults['anthropic_model'],
			'cloud_model'             => isset( $input['cloud_model'] ) ? mb_substr( sanitize_text_field( (string) $input['cloud_model'] ), 0, 100 ) : $defaults['cloud_model'],
			'moderation_enabled'      => empty( $input['moderation_enabled'] ) ? 0 : 1,
			'moderation_threshold'    => isset( $input['moderation_threshold'] ) ? min( 1.0, max( 0.0, (float) $input['moderation_threshold'] ) ) : $defaults['moderation_threshold'],
			'feature_assistant'       => empty( $input['feature_assistant'] ) ? 0 : 1,
			'feature_content'         => empty( $input['feature_content'] ) ? 0 : 1,
			'feature_search'          => empty( $input['feature_search'] ) ? 0 : 1,
			'feature_recommendations' => empty( $input['feature_recommendations'] ) ? 0 : 1,
			'feature_moderation'      => empty( $input['feature_moderation'] ) ? 0 : 1,
			'feature_analytics'       => empty( $input['feature_analytics'] ) ? 0 : 1,
			'floating_widget'         => empty( $input['floating_widget'] ) ? 0 : 1,
			'streaming_enabled'       => empty( $input['streaming_enabled'] ) ? 0 : 1,
			'agent_world_knowledge'   => empty( $input['agent_world_knowledge'] ) ? 0 : 1,
			'agent_learning'          => empty( $input['agent_learning'] ) ? 0 : 1,
			'agent_auto_learn'        => empty( $input['agent_auto_learn'] ) ? 0 : 1,
			'agent_email_alerts'      => empty( $input['agent_email_alerts'] ) ? 0 : 1,
			'agent_radar_digest'      => empty( $input['agent_radar_digest'] ) ? 0 : 1,
			'web_search_enabled'      => empty( $input['web_search_enabled'] ) ? 0 : 1,
			'web_search_provider'     => in_array( $input['web_search_provider'] ?? '', array_keys( Zeko_AI_Web_Search::providers() ), true ) ? sanitize_key( (string) $input['web_search_provider'] ) : $defaults['web_search_provider'],
			'google_search_api_key'   => isset( $input['google_search_api_key'] ) ? sanitize_text_field( (string) $input['google_search_api_key'] ) : '',
			'google_search_engine_id' => isset( $input['google_search_engine_id'] ) ? sanitize_text_field( (string) $input['google_search_engine_id'] ) : '',
			'bing_search_key'         => isset( $input['bing_search_key'] ) ? sanitize_text_field( (string) $input['bing_search_key'] ) : '',
			'brave_search_key'        => isset( $input['brave_search_key'] ) ? sanitize_text_field( (string) $input['brave_search_key'] ) : '',
			'serper_search_key'       => isset( $input['serper_search_key'] ) ? sanitize_text_field( (string) $input['serper_search_key'] ) : '',
			'web_search_max_results'  => isset( $input['web_search_max_results'] ) ? max( 1, min( 10, (int) $input['web_search_max_results'] ) ) : (int) $defaults['web_search_max_results'],
		);

		foreach ( array( 'openrouter', 'gemini', 'groq', 'deepseek' ) as $slug ) {
			$out[ $slug . '_key' ]      = isset( $input[ $slug . '_key' ] ) ? sanitize_text_field( (string) $input[ $slug . '_key' ] ) : '';
			$out[ $slug . '_model' ]    = isset( $input[ $slug . '_model' ] ) ? mb_substr( sanitize_text_field( (string) $input[ $slug . '_model' ] ), 0, 100 ) : $defaults[ $slug . '_model' ];
			$out[ $slug . '_base_url' ] = isset( $input[ $slug . '_base_url' ] ) ? esc_url_raw( trim( (string) $input[ $slug . '_base_url' ] ) ) : $defaults[ $slug . '_base_url' ];
		}

		return $out;
	}

	/**
	 * Enabled.
	 *
	 * @param string $feature Feature.
	 */
	public function is_enabled( string $feature ): bool {
		$key = 'feature_' . $feature;
		return ! empty( $this->settings[ $key ] );
	}

	/**
	 * Get.
	 *
	 * @param string $key Key.
	 * @param mixed  $default Default.
	 */
	public function get( string $key, $default = null ) {
		return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $default;
	}

	/**
	 * All.
	 */
	public function all(): array {
		return $this->settings;
	}

	/**
	 * Moderation threshold.
	 */
	public function moderation_threshold(): float {
		return (float) $this->get( 'moderation_threshold', 0.7 );
	}
}
