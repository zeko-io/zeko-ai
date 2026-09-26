<?php
/**
 * Recommended-content radar digest.
 *
 * Periodically (weekly cron) emails members a short digest of their freshest
 * AI recommendations. Works purely offline: it reuses the recommendations
 * engine and never calls an external API. Digest delivery is gated by the
 * 'agent_radar_digest' setting and throttled per user so a member never
 * receives more than one digest per week.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Radar. */
class Zeko_AI_Radar {

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
	 * Member IDs who used AI features in the last $days days (candidates for
	 * the digest). Bounded so a busy site never fans out to the whole user
	 * table in one run.
	 *
	 * @return int[]
	 * @param int $days Days.
	 * @param int $limit Limit.
	 */
	public function active_user_ids( int $days = 7, int $limit = 200 ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$this->db->get_table_usage()}
			WHERE user_id > 0 AND created_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
			ORDER BY user_id ASC LIMIT %d",
				max( 1, $days ),
				max( 1, $limit )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$ids = array();
		foreach ( (array) $rows as $row ) {
			$ids[] = (int) $row->user_id;
		}
		return $ids;
	}

	/**
	 * Build a plain-text digest of the user's current recommendations.
	 *
	 * @return array{items:array<int,array{type:string,title:string,url:string,reason:string}>,intro:string}
	 * @param int $user_id * @param int $limit   Max items to include.
	 * @param int $limit Limit.
	 */
	public function build_digest( int $user_id, int $limit = 5 ): array {
		$service = zeko_ai()->get_recommendations();
		$count   = $service->refresh( $user_id );

		$items = array();
		foreach ( array_slice( $service->last(), 0, max( 1, $limit ) ) as $rec ) {
			$items[] = array(
				'type'   => (string) ( $rec['type'] ?? '' ),
				'title'  => (string) ( $rec['title'] ?? '' ),
				'url'    => (string) ( $rec['url'] ?? '' ),
				'reason' => (string) ( $rec['reason'] ?? '' ),
			);
		}

		$intro = sprintf(
			/* translators: %d: number of recommendations. */
			__( "Here is your Zeko radar: %d fresh recommendations based on your activity across the ecosystem.\n\n", 'zeko-ai' ),
			count( $items )
		);

		return array(
			'items'     => $items,
			'intro'     => $intro,
			'refreshed' => $count,
		);
	}

	/**
	 * Format a digest into a plain-text email body.
	 *
	 * @param array $digest Digest.
	 */
	public function render_text( array $digest ): string {
		if ( empty( $digest['items'] ) ) {
			return '';
		}

		$lines = array( rtrim( (string) ( $digest['intro'] ?? '' ) ) );

		foreach ( $digest['items'] as $item ) {
			$line = '• ' . ( '' !== ( $item['type'] ?? '' ) ? '[' . $item['type'] . '] ' : '' ) . $item['title'];
			if ( '' !== ( $item['url'] ?? '' ) ) {
				$line .= ' — ' . $item['url'];
			}
			if ( '' !== ( $item['reason'] ?? '' ) ) {
				$line .= ' (' . $item['reason'] . ')';
			}
			$lines[] = $line;
		}

		$lines[] = '';
		$lines[] = __( 'Open your recommendations anytime to refresh this list.', 'zeko-ai' );

		return implode( "\n", $lines );
	}

	/**
	 * Deliver the weekly digest to recently-active members. Skips members who
	 * received one within the throttle window or who have no fresh items.
	 * Returns the number of digests delivered.
	 *
	 * @param int $limit Limit.
	 */
	public function send_digests( int $limit = 200 ): int {
		if ( empty( zeko_ai_get_settings()['agent_radar_digest'] ) ) {
			return 0;
		}

		$sent = 0;
		foreach ( $this->active_user_ids( 7, $limit ) as $user_id ) {
			if ( get_user_meta( $user_id, 'zeko_ai_radar_last_sent', true ) ) {
				continue;
			}

			$digest = $this->build_digest( $user_id, 5 );
			$body   = $this->render_text( $digest );
			if ( '' === $body ) {
				continue;
			}

			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}

			update_user_meta( $user_id, 'zeko_ai_radar_last_sent', current_time( 'mysql' ) );

			$subject = sprintf(
				/* translators: %s: site name. */
				__( 'Your Zeko radar — %s', 'zeko-ai' ),
				wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
			);

			$body_html = $body;
			$headers   = array();

			if ( class_exists( 'Zeko_Core_Emails' ) ) {
				$emails    = Zeko_Core_Emails::get_instance();
				$body_html = $emails->wrap(
					$emails->plain_to_html( $body ),
					array(
						'brand_name' => __( 'Zeko AI Radar', 'zeko-ai' ),
						'tagline'    => __( 'Fresh opportunities picked just for you', 'zeko-ai' ),
						'preheader'  => $subject,
					)
				);
				$headers   = array( 'Content-Type: text/html; charset=UTF-8' );
			}

			if ( wp_mail( $user->user_email, $subject, $body_html, $headers ) ) {
				++$sent;
			}
		}

		return $sent;
	}
}
