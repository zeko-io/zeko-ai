<?php
/**
 * Zeko AI content moderation.
 *
 * Runs provider moderation on community content, stores the verdict for
 * audit/review, and exposes an admin queue with re-review and manual
 * decisions. Integrations trigger it after content creation; the admin page
 * can also pull fresh items from every module's review source.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Moderation. */
class Zeko_AI_Moderation {

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
	 * All registered moderation review sources.
	 *
	 * @return array<int,array{source:string,type:string,label:string,fetch:callable}>
	 */
	public function get_sources(): array {
		return apply_filters( 'zeko_ai_moderation_sources', array() );
	}

	/**
	 * Count sources.
	 */
	public function count_sources(): int {
		return count( $this->get_sources() );
	}

	/**
	 * Moderate one item and record the verdict.
	 *
	 * @return string 'approved'|'flagged'|'skipped'
	 * @param int    $user_id * @param string $source.
	 * @param string $source Source.
	 * @param string $content_type * @param int    $content_id.
	 * @param int    $content_id Content id.
	 * @param string $content * @return string 'approved'|'flagged'|'skipped'.
	 */
	public function maybe_review( int $user_id, string $source, string $content_type, int $content_id, string $content ): string {
		if ( ! zeko_ai()->get_settings()->is_enabled( 'moderation' ) ) {
			return 'skipped';
		}

		$content = trim( (string) $content );
		if ( '' === $content ) {
			return 'skipped';
		}

		$verdict = zeko_ai()->get_provider()->moderate( $content );

		$decision = 'approved';
		if ( 'flagged' === $verdict['decision'] ) {
			$score    = (float) $verdict['score'];
			$decision = $score >= zeko_ai()->get_settings()->moderation_threshold() ? 'flagged' : 'approved';
		}

		$this->record_moderation_usage( $user_id, $content, $verdict );

		$this->db->insert_moderation(
			array(
				'user_id'      => $user_id,
				'source'       => $source,
				'content_type' => $content_type,
				'content_id'   => $content_id,
				'content'      => $content,
				'decision'     => $decision,
				'reasons'      => (array) $verdict['reasons'],
				'score'        => (float) $verdict['score'],
			)
		);

		return $decision;
	}

	/**
	 * Re-run AI review on a stored entry.
	 *
	 * @return array{id:int, decision:string, reasons:array, score:float}|null
	 * @param int $moderation_id * @param int $reviewer_id.
	 * @param int $reviewer_id Reviewer id.
	 */
	public function review( int $moderation_id, int $reviewer_id = 0 ): ?array {
		$entry = $this->db->get_moderation( $moderation_id );
		if ( ! $entry ) {
			return null;
		}

		$verdict  = zeko_ai()->get_provider()->moderate( (string) $entry->content );
		$decision = 'approved';
		if ( 'flagged' === $verdict['decision'] ) {
			$decision = (float) $verdict['score'] >= zeko_ai()->get_settings()->moderation_threshold() ? 'flagged' : 'approved';
		}

		$this->record_moderation_usage( (int) $entry->user_id, (string) $entry->content, $verdict );

		$this->db->update_moderation(
			$moderation_id,
			array(
				'decision'    => $decision,
				'reviewed_by' => $reviewer_id,
				'reviewed_at' => current_time( 'mysql' ),
			)
		);
		$this->db->insert_moderation(
			array(
				'user_id'      => (int) $entry->user_id,
				'source'       => (string) $entry->source,
				'content_type' => (string) $entry->content_type,
				'content_id'   => (int) $entry->content_id,
				'content'      => (string) $entry->content,
				'decision'     => $decision,
				'reasons'      => (array) $verdict['reasons'],
				'score'        => (float) $verdict['score'],
			)
		);

		return array(
			'id'       => $moderation_id,
			'decision' => $decision,
			'reasons'  => (array) $verdict['reasons'],
			'score'    => (float) $verdict['score'],
		);
	}

	/**
	 * Manually set a decision on an entry.
	 *
	 * @param int    $moderation_id Moderation id.
	 * @param string $decision Decision.
	 * @param int    $reviewer_id Reviewer id.
	 */
	public function set_decision( int $moderation_id, string $decision, int $reviewer_id = 0 ): bool {
		if ( ! in_array( $decision, array( 'approved', 'flagged', 'dismissed' ), true ) ) {
			return false;
		}
		return $this->db->update_moderation(
			$moderation_id,
			array(
				'decision'    => $decision,
				'reviewed_by' => $reviewer_id,
				'reviewed_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Pending count.
	 */
	public function pending_count(): int {
		return $this->db->count_moderation( array( 'decision' => 'flagged' ) );
	}

	/**
	 * Log a moderation provider call in the usage analytics. Verdicts carry
	 * no token/usage metadata, so a rough char/4 token estimate is recorded.
	 *
	 * @param int    $user_id User id.
	 * @param string $content Content.
	 * @param array  $verdict Verdict.
	 */
	private function record_moderation_usage( int $user_id, string $content, array $verdict ): void {
		$chars = max( 1, mb_strlen( (string) $content ) );
		zeko_ai()->record_usage(
			$user_id,
			'moderation',
			array_merge(
				$verdict,
				array(
					'provider'   => zeko_ai()->get_provider()->name(),
					'model'      => zeko_ai()->get_provider()->model(),
					'tokens_in'  => (int) ceil( $chars / 4 ),
					'tokens_out' => (int) ceil( ( $chars / 4 ) / 4 ),
				)
			)
		);
	}

	/**
	 * Fetch recent items from a review source (for on-demand admin review).
	 *
	 * @return array<int,array{content_id:int,user_id:int,title:string,content:string}>
	 * @param string $source * @param int    $limit.
	 * @param int    $limit Limit.
	 */
	public function fetch_source_items( string $source, int $limit = 20 ): array {
		foreach ( $this->get_sources() as $src ) {
			if ( (string) $src['source'] === $source && is_callable( $src['fetch'] ) ) {
				$items = call_user_func( $src['fetch'], max( 1, min( 100, $limit ) ) );
				return is_array( $items ) ? $items : array();
			}
		}
		return array();
	}
}
