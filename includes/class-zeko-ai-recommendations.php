<?php
/**
 * Zeko AI recommendations.
 *
 * Content-based recommendations built from the user's own ecosystem activity
 * (the same per-user context the assistant sees). Interests are derived by
 * tokenizing the titles/snippets of the user's jobs, courses, questions,
 * projects, orders, sessions and profile; candidates from every module are
 * then scored by keyword overlap. Works fully offline.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Recommendations. */
class Zeko_AI_Recommendations {

	/**
	 * Db.
	 *
	 * @var Zeko_AI_DB Db.
	 */
	private Zeko_AI_DB $db;

	/**
	 * Stopwords.
	 *
	 * @var mixed Stopwords.
	 */
	private static $stopwords = array(
		'a',
		'an',
		'the',
		'and',
		'or',
		'but',
		'for',
		'with',
		'your',
		'you',
		'my',
		'to',
		'of',
		'in',
		'on',
		'at',
		'by',
		'is',
		'are',
		'was',
		'be',
		'it',
		'this',
		'that',
		'from',
		'as',
		'i',
		'we',
		'they',
		'he',
		'she',
		'how',
		'what',
		'can',
		'for',
		'new',
		'any',
		'all',
		'will',
		'not',
		'no',
		'so',
		'get',
		'make',
		'use',
		'help',
		'want',
		'need',
		'like',
		'good',
		'great',
		'our',
		'their',
		'has',
		'have',
	);

	/**
	 * Construct.
	 *
	 * @param Zeko_AI_DB $db Db.
	 */
	public function __construct( Zeko_AI_DB $db ) {
		$this->db = $db;
	}

	/**
	 * All registered recommendation sources.
	 *
	 * @return array<int,array{type:string,label:string,items:callable}>
	 */
	public function get_sources(): array {
		return apply_filters( 'zeko_ai_recommendation_sources', array() );
	}

	/**
	 * Count sources.
	 */
	public function count_sources(): int {
		return count( $this->get_sources() );
	}

	/**
	 * Derive weighted interest keywords for a user from their ecosystem
	 * context blocks.
	 *
	 * @return array<string,int>
	 * @param int $user_id * @return array<string,int>.
	 */
	public function user_interests( int $user_id ): array {
		$blocks = apply_filters( 'zeko_ai_assistant_context', array() );

		$weights = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['items'] ) ) {
				continue;
			}
			foreach ( (array) $block['items'] as $item ) {
				$text = (string) ( $item['title'] ?? '' ) . ' ' . (string) ( $item['snippet'] ?? '' );
				foreach ( $this->tokenize( $text ) as $token ) {
					$weights[ $token ] = ( $weights[ $token ] ?? 0 ) + 1;
				}
			}
		}

		// Let other modules contribute explicit interests (e.g. profile skills).
		$extra = apply_filters( 'zeko_ai_user_interests', array(), $user_id );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $keyword ) {
				$word = mb_strtolower( trim( (string) $keyword ) );
				if ( '' !== $word ) {
					$weights[ $word ] = ( $weights[ $word ] ?? 0 ) + 2;
				}
			}
		}

		return $weights;
	}

	/**
	 * Compute recommendations for a user without persisting them.
	 *
	 * @return array<int,array{type:string,label:string,id:int,title:string,url:string,score:float,reason:string}>
	 * @param int   $user_id * @param array $opts per_type (int) candidates per source, limit (int) overall.
	 * @param array $opts Opts.
	 */
	public function recommend( int $user_id, array $opts = array() ): array {
		$interests = $this->user_interests( $user_id );
		if ( empty( $interests ) ) {
			return array();
		}

		$per_type = isset( $opts['per_type'] ) ? max( 1, min( 100, (int) $opts['per_type'] ) ) : 10;
		$limit    = isset( $opts['limit'] ) ? max( 1, min( 100, (int) $opts['limit'] ) ) : 20;

		$results = array();
		foreach ( $this->get_sources() as $source ) {
			if ( empty( $source['items'] ) || ! is_callable( $source['items'] ) ) {
				continue;
			}
			$items = call_user_func( $source['items'], $per_type );
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				$score = $this->score_item( $interests, (string) ( $item['title'] ?? '' ), (string) ( $item['excerpt'] ?? '' ) );
				if ( $score <= 0 ) {
					continue;
				}
				$results[] = array(
					'type'   => (string) ( $source['type'] ?? '' ),
					'label'  => (string) ( $source['label'] ?? '' ),
					'id'     => (int) ( $item['id'] ?? 0 ),
					'title'  => (string) ( $item['title'] ?? '' ),
					'url'    => (string) ( $item['url'] ?? '' ),
					'score'  => $score,
					'reason' => $this->build_reason( $interests, (string) ( $item['title'] ?? '' ), (string) ( $item['excerpt'] ?? '' ) ),
				);
			}
		}

		usort(
			$results,
			static function ( array $a, array $b ): int {
				return $a['score'] < $b['score'] ? 1 : ( $a['score'] > $b['score'] ? -1 : strcmp( $a['title'], $b['title'] ) );
			}
		);

		return array_slice( $results, 0, $limit );
	}

	/**
	 * Last.
	 *
	 * @var array Last.
	 */
	private array $last = array();

	/**
	 * Refresh the persisted recommendations for a user.
	 *
	 * @param int $user_id User id.
	 */
	public function refresh( int $user_id ): int {
		$this->db->clear_recommendations( $user_id );

		$recommendations = $this->recommend(
			$user_id,
			array(
				'per_type' => 10,
				'limit'    => 20,
			)
		);
		$this->last      = $recommendations;
		$count           = 0;
		foreach ( $recommendations as $rec ) {
			$this->db->insert_recommendation(
				array(
					'user_id'   => $user_id,
					'item_type' => (string) $rec['type'],
					'item_id'   => (int) $rec['id'],
					'score'     => (float) $rec['score'],
					'reason'    => (string) $rec['reason'],
				)
			);
			++$count;
		}
		return $count;
	}

	/**
	 * The most recently computed recommendation items (with title/url).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function last(): array {
		return $this->last;
	}

	/**
	 * Score item.
	 *
	 * @param array  $interests Interests.
	 * @param string $title Title.
	 * @param string $excerpt Excerpt.
	 */
	private function score_item( array $interests, string $title, string $excerpt ): float {
		$title_text   = mb_strtolower( $title );
		$excerpt_text = mb_strtolower( $excerpt );
		$score        = 0.0;

		foreach ( $interests as $word => $weight ) {
			if ( false !== mb_strpos( $title_text, $word ) ) {
				$score += 2.0 * $weight;
			} elseif ( false !== mb_strpos( $excerpt_text, $word ) ) {
				$score += 1.0 * $weight;
			}
		}

		return round( $score, 4 );
	}

	/**
	 * Build reason.
	 *
	 * @param array  $interests Interests.
	 * @param string $title Title.
	 * @param string $excerpt Excerpt.
	 */
	private function build_reason( array $interests, string $title, string $excerpt ): string {
		$title_text   = mb_strtolower( $title );
		$excerpt_text = mb_strtolower( $excerpt );
		$matched      = array();
		foreach ( $interests as $word => $weight ) {
			if ( false !== mb_strpos( $title_text, $word ) || false !== mb_strpos( $excerpt_text, $word ) ) {
				$matched[] = $word;
			}
		}
		if ( empty( $matched ) ) {
			return '';
		}
		usort(
			$matched,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		return 'Because you are interested in: ' . implode( ', ', array_slice( array_unique( $matched ), 0, 4 ) );
	}

	/**
	 * Lowercase tokens minus stopwords, min length 3.
	 *
	 * @return string[]
	 * @param string $text Text.
	 */
	private function tokenize( string $text ): array {
		$words  = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$tokens = array();
		foreach ( (array) $words as $word ) {
			if ( strlen( $word ) < 3 ) {
				continue;
			}
			if ( in_array( $word, self::$stopwords, true ) ) {
				continue;
			}
			$tokens[] = $word;
		}
		return $tokens;
	}
}
