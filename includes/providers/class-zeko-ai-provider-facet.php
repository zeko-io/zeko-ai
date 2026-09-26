<?php
/**
 * Failover facet for the active provider.
 *
 * Wraps an ordered chain of providers (configured provider -> other keyed
 * providers -> community agent -> Mock) and transparently retries the next
 * provider in the chain whenever the current one throws. Results are
 * annotated with the provider that actually served the request so usage
 * recording stays accurate. Moderation follows the same chain; when every
 * provider fails the last error is rethrown so the AJAX layer can surface a
 * friendly message and alert the admin.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Provider_Facet. */
class Zeko_AI_Provider_Facet extends Zeko_AI_Provider {

	/**
	 * Chain.
	 *
	 * @var mixed Chain.
	 */
	private $chain = array();

	/**
	 * Primary.
	 *
	 * @var mixed Primary.
	 */
	private $primary;

	/**
	 * Construct.
	 *
	 * @param array $chain Chain.
	 */
	public function __construct( array $chain ) {
		parent::__construct( array() );
		$this->chain = array_values( $chain );

		if ( empty( $this->chain ) ) {
			$this->chain = array( new Zeko_AI_Mock_Provider( array() ) );
		}
		$this->primary = $this->chain[0];
	}

	/**
	 * Name.
	 */
	public function name(): string {
		return $this->primary->name();
	}

	/**
	 * Model.
	 */
	public function model(): string {
		return $this->primary->model();
	}

	/**
	 * Supports moderation.
	 */
	public function supports_moderation(): bool {
		return $this->primary->supports_moderation();
	}

	/**
	 * Chat.
	 *
	 * @param array $messages Messages.
	 * @param array $opts Opts.
	 */
	public function chat( array $messages, array $opts = array() ): array {
		return $this->try_chain( 'chat', array( $messages, $opts ) );
	}

	/**
	 * Complete.
	 *
	 * @param string $prompt Prompt.
	 * @param array  $opts Opts.
	 */
	public function complete( string $prompt, array $opts = array() ): array {
		return $this->try_chain( 'complete', array( $prompt, $opts ) );
	}

	/**
	 * Moderate.
	 *
	 * @param string $text Text.
	 */
	public function moderate( string $text ): array {
		return $this->try_chain( 'moderate', array( $text ) );
	}

	/**
	 * Run a provider method across the chain until one succeeds, annotating
	 * the result with the serving provider when not already present.
	 *
	 * @return array
	 * @param string $method * @param array  $args.
	 * @param array  $args Args.
	 * @throws RuntimeException When an error occurs.
	 */
	private function try_chain( string $method, array $args ): array {
		$errors = array();

		foreach ( $this->chain as $provider ) {
			try {
				$result = call_user_func_array( array( $provider, $method ), $args );
				if ( is_array( $result ) ) {
					if ( ! isset( $result['provider'] ) ) {
						$result['provider'] = $provider->name();
					}
					if ( ! isset( $result['model'] ) ) {
						$result['model'] = $provider->model();
					}
				}
				return $result;
			} catch ( \Throwable $e ) {
				$errors[] = $provider->name() . ': ' . $e->getMessage();
				error_log( 'Zeko AI failover: ' . $provider->name() . ' failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				$this->log_failure( $provider->name(), $method, $e );
			}
		}

		throw new RuntimeException( esc_html( implode( ' | ', $errors ) ) );
	}

	/**
	 * Record one provider failure in the provider_log table for the health
	 * dashboard. Best-effort: never breaks the request when the table is
	 * missing or the write fails.
	 *
	 * @param string     $provider Provider.
	 * @param string     $method Method.
	 * @param \Throwable $e E.
	 */
	private function log_failure( string $provider, string $method, \Throwable $e ): void {
		if ( ! class_exists( 'Zeko_AI' ) || ! Zeko_AI::instance()->get_db() ) {
			return;
		}
		$db = Zeko_AI::instance()->get_db();
		if ( ! method_exists( $db, 'insert_provider_log' ) ) {
			return;
		}
		try {
			$db->insert_provider_log(
				array(
					'event'    => 'failover',
					'provider' => $provider,
					'model'    => method_exists( $provider, 'model' ) ? $provider->model() : '',
					'detail'   => mb_substr( $method . ': ' . $e->getMessage(), 0, 500 ),
				)
			);
		} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Logging must never break the failover path.
		}
	}
}
