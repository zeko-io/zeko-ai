<?php
/**
 * Zeko AI usage analytics.
 *
 * Thin wrapper over the usage table's aggregates: totals, per-feature,
 * per-day, top users, and a simple estimated-cost model for providers that
 * do not report costs.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Analytics. */
class Zeko_AI_Analytics {

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
	 * Totals.
	 */
	public function totals(): array {
		return $this->db->usage_totals();
	}

	/**
	 * By feature.
	 */
	public function by_feature(): array {
		return $this->db->usage_by_feature();
	}

	/**
	 * By day.
	 *
	 * @param int $days Days.
	 */
	public function by_day( int $days = 30 ): array {
		return $this->db->usage_by_day( $days );
	}

	/**
	 * Top users.
	 *
	 * @param int $limit Limit.
	 */
	public function top_users( int $limit = 10 ): array {
		return $this->db->usage_top_users( $limit );
	}

	/**
	 * Recent.
	 *
	 * @param int $limit Limit.
	 */
	public function recent( int $limit = 50 ): array {
		return $this->db->get_usage( array( 'limit' => $limit ) );
	}

	/**
	 * Estimated cost for a provider result (USD). Providers may supply an
	 * actual cost via the 'cost' key; otherwise a simple per-token model is
	 * applied so the analytics page always has a number.
	 *
	 * @return float
	 * @param array $result * @return float.
	 */
	public function estimate_cost( array $result ): float {
		if ( isset( $result['cost'] ) ) {
			return (float) $result['cost'];
		}
		$tokens_in  = (int) ( $result['tokens_in'] ?? 0 );
		$tokens_out = (int) ( $result['tokens_out'] ?? 0 );
		// Rough blended model: $2 / 1M input, $6 / 1M output tokens.
		return ( $tokens_in * 2 + $tokens_out * 6 ) / 1000000;
	}

	/**
	 * Per-feature totals for the admin overview.
	 *
	 * @return array<string,array{requests:int,tokens_in:int,tokens_out:int,cost:float}>
	 */
	public function by_feature_map(): array {
		$map = array();
		foreach ( $this->by_feature() as $row ) {
			$map[ (string) $row->feature ] = array(
				'requests'   => (int) $row->requests,
				'tokens_in'  => (int) $row->tokens_in,
				'tokens_out' => (int) $row->tokens_out,
				'cost'       => (float) $row->cost,
			);
		}
		return $map;
	}
}
