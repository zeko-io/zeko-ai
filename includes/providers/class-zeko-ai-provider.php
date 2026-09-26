<?php
/**
 * Provider contract for Zeko AI.
 *
 * A provider turns chat prompts / completions / moderation requests into
 * normalized responses. Implementations never auto-call the network during
 * a normal request unless actually asked to (the Mock provider is fully
 * offline; HTTP providers only fire on explicit invocation).
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Provider. */
abstract class Zeko_AI_Provider {

	/**
	 * Settings.
	 *
	 * @var mixed Settings.
	 */
	protected $settings;

	/**
	 * Construct.
	 *
	 * @param array $settings Settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Human-friendly provider name.
	 *
	 * @return string
	 */
	abstract public function name(): string;

	/**
	 * Active model identifier.
	 *
	 * @return string
	 */
	abstract public function model(): string;

	/**
	 * Whether this provider can answer moderation requests.
	 *
	 * @return bool
	 */
	public function supports_moderation(): bool {
		return true;
	}

	/**
	 * Multi-turn chat completion.
	 *
	 * @return array{content:string, tokens_in:int, tokens_out:int, model:string, raw:mixed}
	 * @param array $messages * @param array                                        $opts Extra options (temperature, max_tokens...).
	 * @param array $opts Opts.
	 */
	abstract public function chat( array $messages, array $opts = array() ): array;

	/**
	 * Single-turn text generation.
	 *
	 * @return array{content:string, tokens_in:int, tokens_out:int, model:string, raw:mixed}
	 * @param string $prompt * @param array  $opts Extra options.
	 * @param array  $opts Opts.
	 */
	public function complete( string $prompt, array $opts = array() ): array {
		return $this->chat(
			array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			$opts
		);
	}

	/**
	 * Content moderation check.
	 *
	 * @return array{decision:string, reasons:string[], score:float}
	 * @param string $text * @return array{decision:string, reasons:string[], score:float}.
	 */
	abstract public function moderate( string $text ): array;
}
