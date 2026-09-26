<?php
/**
 * Provider factory: settings -> concrete provider.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Provider_Factory. */
class Zeko_AI_Provider_Factory {

	/**
	 * Build the provider selected by settings.
	 * Falls back to the deterministic Mock provider whenever a configured
	 * external provider is missing its API key, so the assistant and content
	 * tools keep working (offline) instead of failing.
	 *
	 * @return Zeko_AI_Provider
	 * @param array $settings * @return Zeko_AI_Provider.
	 */
	public static function create( array $settings ): Zeko_AI_Provider {
		$provider = isset( $settings['provider'] ) ? sanitize_key( (string) $settings['provider'] ) : 'mock';

		switch ( $provider ) {
			case 'openai':
				if ( empty( $settings['openai_key'] ) ) {
					$provider = 'mock';
				}
				break;
			case 'anthropic':
				if ( empty( $settings['anthropic_key'] ) ) {
					$provider = 'mock';
				}
				break;
			case 'openrouter':
				if ( empty( $settings['openrouter_key'] ) ) {
					$provider = 'mock';
				}
				break;
			case 'gemini':
				if ( empty( $settings['gemini_key'] ) ) {
					$provider = 'mock';
				}
				break;
			case 'groq':
				if ( empty( $settings['groq_key'] ) ) {
					$provider = 'mock';
				}
				break;
			case 'deepseek':
				if ( empty( $settings['deepseek_key'] ) ) {
					$provider = 'mock';
				}
				break;
			case 'cloud':
				// Hosted Zeko Cloud requires an active license with AI credits.
				// (or an explicit test override). Without one it degrades to.
				// Mock exactly like a missing external API key.
				if ( ! self::cloud_ready( $settings ) ) {
					$provider = 'mock';
				}
				break;
			case 'agent':
				// No API key required: fully offline, community-driven.
				break;
			case 'mock':
			default:
				break;
		}

		switch ( $provider ) {
			case 'openai':
				return new Zeko_AI_OpenAI_Provider( $settings );
			case 'anthropic':
				return new Zeko_AI_Anthropic_Provider( $settings );
			case 'openrouter':
				return new Zeko_AI_OpenRouter_Provider( $settings );
			case 'gemini':
				return new Zeko_AI_Gemini_Provider( $settings );
			case 'groq':
				return new Zeko_AI_Groq_Provider( $settings );
			case 'deepseek':
				return new Zeko_AI_DeepSeek_Provider( $settings );
			case 'cloud':
				return new Zeko_AI_Cloud_Provider( $settings );
			case 'agent':
				return new Zeko_AI_Agent_Provider( $settings );
			case 'mock':
			default:
				return new Zeko_AI_Mock_Provider( $settings );
		}
	}

	/**
	 * Ordered failover chain for the request's active provider: the
	 * configured provider first, then every other external provider that has
	 * an API key (in a fixed order), then the keyless community agent, then
	 * Mock as the final safety net. Duplicate classes are skipped.
	 *
	 * @return Zeko_AI_Provider[]
	 * @param array $settings * @return Zeko_AI_Provider[].
	 */
	public static function create_chain( array $settings ): array {
		$chain = array();
		$seen  = array();

		$add = static function ( Zeko_AI_Provider $provider ) use ( &$chain, &$seen ) {
			$class = get_class( $provider );
			if ( isset( $seen[ $class ] ) ) {
				return;
			}
			$seen[ $class ] = true;
			$chain[]        = $provider;
		};

		$configured = isset( $settings['provider'] ) ? sanitize_key( (string) $settings['provider'] ) : 'mock';
		$external   = array( 'openai', 'anthropic', 'openrouter', 'gemini', 'groq', 'deepseek' );

		// Only mount the configured provider when it is actually usable. A.
		// keyless external provider, or a Zeko Cloud configured without an.
		// active license, would otherwise degrade into Mock and take the head.
		// of the chain, hijacking every request from the agent.
		if ( 'cloud' === $configured ) {
			if ( self::cloud_ready( $settings ) ) {
				$add( self::create( $settings ) );
			}
		} elseif ( ! in_array( $configured, $external, true ) || ! empty( $settings[ $configured . '_key' ] ) ) {
			$add( self::create( $settings ) );
		}

		foreach ( array( 'openai', 'anthropic', 'openrouter', 'gemini', 'groq', 'deepseek' ) as $slug ) {
			if ( ! empty( $settings[ $slug . '_key' ] ) && ( $settings['provider'] ?? '' ) !== $slug ) {
				$add( self::create( array_merge( $settings, array( 'provider' => $slug ) ) ) );
			}
		}

		$add( self::create( array_merge( $settings, array( 'provider' => 'agent' ) ) ) );
		$add( self::create( array_merge( $settings, array( 'provider' => 'mock' ) ) ) );

		return $chain;
	}

	/**
	 * Build the request provider as a failover facet wrapping the full chain.
	 *
	 * @return Zeko_AI_Provider
	 * @param array $settings * @return Zeko_AI_Provider.
	 */
	public static function create_failover( array $settings ): Zeko_AI_Provider {
		return new Zeko_AI_Provider_Facet( self::create_chain( $settings ) );
	}

	/**
	 * Failover chain for content generation (the "writer"). Unlike the chat
	 * chain this always leads with a keyed external LLM when one is available,
	 * so writing powers up automatically even when the chat assistant has been
	 * configured to use the keyless community agent. Offline the chain ends
	 * with Mock only: keyless content is produced deterministically by the
	 * draft composer in Zeko_AI_Content, so a retrieval pass adds nothing.
	 *
	 * @return Zeko_AI_Provider[]
	 * @param array $settings * @return Zeko_AI_Provider[].
	 */
	public static function create_content_chain( array $settings ): array {
		$chain = array();
		$seen  = array();

		$add = static function ( Zeko_AI_Provider $provider ) use ( &$chain, &$seen ) {
			$class = get_class( $provider );
			if ( isset( $seen[ $class ] ) ) {
				return;
			}
			$seen[ $class ] = true;
			$chain[]        = $provider;
		};

		$external   = array( 'openai', 'anthropic', 'openrouter', 'gemini', 'groq', 'deepseek' );
		$configured = isset( $settings['provider'] ) ? sanitize_key( (string) $settings['provider'] ) : 'mock';

		// Zeko Cloud is the primary configured provider: let it lead the.
		// content chain just like a keyed external LLM would.
		if ( 'cloud' === $configured && self::cloud_ready( $settings ) ) {
			$add( self::create( $settings ) );
		}

		foreach ( $external as $slug ) {
			if ( empty( $settings[ $slug . '_key' ] ) ) {
				continue;
			}
			if ( $slug === $configured ) {
				$add( self::create( $settings ) );
			} else {
				$add( self::create( array_merge( $settings, array( 'provider' => $slug ) ) ) );
			}
		}

		// Offline safety net: the deterministic Mock provider. (The composer.
		// in Zeko_AI_Content replaces Mock/Agent output with structured drafts.).
		$add( self::create( array_merge( $settings, array( 'provider' => 'mock' ) ) ) );

		return $chain;
	}

	/**
	 * Content-favored failover facet (see create_content_chain()).
	 *
	 * @return Zeko_AI_Provider
	 * @param array $settings * @return Zeko_AI_Provider.
	 */
	public static function create_content_failover( array $settings ): Zeko_AI_Provider {
		return new Zeko_AI_Provider_Facet( self::create_content_chain( $settings ) );
	}

	/**
	 * Ordered names of the failover chain for the provider health dashboard
	 * (no provider instances are built, so this is safe to call anywhere).
	 *
	 * @return string[]
	 * @param array $settings * @return string[].
	 */
	public static function chain_names( array $settings ): array {
		$names = array();
		$add   = static function ( string $name ) use ( &$names ) {
			if ( ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		};

		$configured = isset( $settings['provider'] ) ? sanitize_key( (string) $settings['provider'] ) : 'mock';
		$external   = array( 'openai', 'anthropic', 'openrouter', 'gemini', 'groq', 'deepseek' );

		if ( 'cloud' === $configured ) {
			if ( self::cloud_ready( $settings ) ) {
				$add( 'cloud' );
			}
		} elseif ( ! in_array( $configured, $external, true ) || ! empty( $settings[ $configured . '_key' ] ) ) {
			$add( $configured );
		}
		foreach ( $external as $slug ) {
			if ( ! empty( $settings[ $slug . '_key' ] ) && ( $settings['provider'] ?? '' ) !== $slug ) {
				$add( $slug );
			}
		}
		$add( 'agent' );
		$add( 'mock' );

		return $names;
	}

	/**
	 * Whether the hosted Zeko Cloud provider may be mounted.
	 * On live installs this is true only when Zeko Core reports a valid license
	 * key that grants the 'ai_cloud' capability, so a lapsed subscription turns
	 * Zeko Cloud off by itself. Tests (and headless installs without Zeko Core)
	 * can force it via the 'zeko_ai_cloud_ready' filter.
	 *
	 * @return bool
	 * @param array $settings * @return bool.
	 */
	public static function cloud_ready( array $settings ): bool {
		$ready = false;

		if ( function_exists( 'zeko_license' ) ) {
			$license = zeko_license();
			$ready   = $license->has_key() && $license->is_valid() && $license->can( 'ai_cloud' );
		}

		return (bool) apply_filters( 'zeko_ai_cloud_ready', $ready, $settings );
	}
}
