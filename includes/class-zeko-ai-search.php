<?php
/**
 * Zeko AI unified search.
 *
 * Queries every module's search through the registered sources and ranks the
 * merged results by relevance. Works fully offline (deterministic keyword
 * scoring); an AI rerank pass can be enabled via a filter for real providers.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Search. */
class Zeko_AI_Search {

	/**
	 * GENERIC TERMS.
	 *
	 * Filler and content-type words that carry no topic information. They are
	 * skipped when ranking a paraphrase match, but may still be searched on
	 * their own as a last resort ("jobs" is the entire query).
	 *
	 * @var mixed
	 */
	const GENERIC_TERMS = array(
		'job',
		'jobs',
		'find',
		'search',
		'look',
		'course',
		'courses',
		'learn',
		'lesson',
		'quiz',
		'question',
		'answer',
		'ask',
		'recommend',
		'suggest',
		'need',
		'want',
		'please',
		'help',
		'something',
		'any',
		'best',
		'good',
		'great',
		'new',
		'like',
		'things',
	);

	/**
	 * PLATFORM TERMS.
	 *
	 * The platform's own name. Unlike filler, it is never a topic and never
	 * worth searching on: almost every member query says "on Zeko", so letting
	 * it decide a match meant any record titled "Zeko …" satisfied a query it
	 * had nothing to do with ("can I find a date on zeko?" answered with a
	 * cafe, "what is zeko?" answered with a cafe).
	 *
	 * @var mixed
	 */
	const PLATFORM_TERMS = array(
		'zeko',
	);

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
	 * Lowercase, stopword-free tokens of a text. Mirrors the agent's query
	 * tokenizer so paraphrase search stays consistent across the plugin.
	 *
	 * @return array<int,string>
	 * @param string $text Text.
	 */
	public static function tokens( string $text ): array {
		$text = self::fold( mb_strtolower( (string) $text ) );

		$stopwords = array(
			'a',
			'an',
			'the',
			'of',
			'is',
			'are',
			'was',
			'were',
			'to',
			'for',
			'on',
			'in',
			'with',
			'how',
			'what',
			'when',
			'where',
			'why',
			'do',
			'does',
			'can',
			'could',
			'tell',
			'explain',
			'define',
			'please',
			'i',
			'me',
			'my',
			'you',
			'your',
			'it',
			'this',
			'that',
			'and',
			'or',
			'as',
			'at',
			'from',
			'by',
			'about',
		);

		$tokens = preg_split( '/[^a-z0-9]+/i', mb_strtolower( (string) $text ) );
		$tokens = array_values( array_filter( array_map( 'trim', $tokens ) ) );

		$out = array();
		foreach ( $tokens as $token ) {
			$token = mb_strtolower( $token );
			if ( mb_strlen( $token ) < 3 || in_array( $token, $stopwords, true ) ) {
				continue;
			}
			$out[] = $token;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Fold Latin-1 / Extended-Latin diacritics to their ASCII base form so
	 * accent-insensitive matching works the same way MySQL's unicode
	 * collations already do ("cafe" matches "Café", "münchen" -> "munchen").
	 *
	 * @param string $text Text.
	 */
	public static function fold( string $text ): string {
		$map = array(
			'à' => 'a',
			'á' => 'a',
			'â' => 'a',
			'ã' => 'a',
			'ä' => 'a',
			'å' => 'a',
			'æ' => 'ae',
			'ç' => 'c',
			'è' => 'e',
			'é' => 'e',
			'ê' => 'e',
			'ë' => 'e',
			'ì' => 'i',
			'í' => 'i',
			'î' => 'i',
			'ï' => 'i',
			'ñ' => 'n',
			'ò' => 'o',
			'ó' => 'o',
			'ô' => 'o',
			'õ' => 'o',
			'ö' => 'o',
			'ø' => 'o',
			'œ' => 'oe',
			'ù' => 'u',
			'ú' => 'u',
			'û' => 'u',
			'ü' => 'u',
			'ý' => 'y',
			'ÿ' => 'y',
			'À' => 'a',
			'Á' => 'a',
			'Â' => 'a',
			'Ã' => 'a',
			'Ä' => 'a',
			'Å' => 'a',
			'Æ' => 'ae',
			'Ç' => 'c',
			'È' => 'e',
			'É' => 'e',
			'Ê' => 'e',
			'Ë' => 'e',
			'Ì' => 'i',
			'Í' => 'i',
			'Î' => 'i',
			'Ï' => 'i',
			'Ñ' => 'n',
			'Ò' => 'o',
			'Ó' => 'o',
			'Ô' => 'o',
			'Õ' => 'o',
			'Ö' => 'o',
			'Ø' => 'o',
			'Œ' => 'oe',
			'Ù' => 'u',
			'Ú' => 'u',
			'Û' => 'u',
			'Ü' => 'u',
			'Ý' => 'y',
			'Ÿ' => 'y',
		);
		return strtr( $text, $map );
	}

	/**
	 * Paraphrase-tolerant search: each term is run against every source (so a
	 * paraphrased request still recalls the right module rows), then results
	 * are deduped and ranked by how many distinctive query terms they match.
	 * Used by the community agent when a verbatim phrase misses the corpus.
	 *
	 * @return array<int,array{type:string,label:string,icon:string,id:int,title:string,excerpt:string,url:string,score:float}>
	 * @param array $terms * @param array             $opts Supports: per_type (int) results per term per source, limit (int) overall.
	 * @param array $opts Opts.
	 */
	public function search_tokens( array $terms, array $opts = array() ): array {
		$per_type = isset( $opts['per_type'] ) ? max( 1, min( 50, (int) $opts['per_type'] ) ) : 3;
		$limit    = isset( $opts['limit'] ) ? max( 1, min( 200, (int) $opts['limit'] ) ) : 8;

		$terms = array_values( array_filter( array_map( 'strval', $terms ) ) );
		$terms = array_values( array_unique( $terms ) );
		if ( empty( $terms ) ) {
			return array();
		}
		$terms = array_slice( $terms, 0, 4 );

		// The platform's own name is never a ranking term and never a search
		// term. Filler is: when every term is filler ("find jobs") it is still
		// the whole question, so fall back to it. When the only term is the
		// platform name ("what is zeko?") there is nothing to look up, and a
		// record titled "Zeko …" must not hijack the answer.
		$ranking = array_values( array_unique( array_diff( $terms, self::GENERIC_TERMS, self::PLATFORM_TERMS ) ) );
		if ( ! $ranking ) {
			$ranking = array_values( array_unique( array_diff( $terms, self::PLATFORM_TERMS ) ) );
		}
		if ( ! $ranking ) {
			return array();
		}

		$seen  = array();
		$items = array();
		foreach ( $this->get_sources() as $source ) {
			if ( empty( $source['search'] ) || ! is_callable( $source['search'] ) ) {
				continue;
			}
			$type  = (string) ( $source['type'] ?? '' );
			$label = (string) ( $source['label'] ?? '' );
			$icon  = (string) ( $source['icon'] ?? 'dashicons-search' );

			foreach ( $terms as $term ) {
				$found = call_user_func( $source['search'], $term, $per_type );
				if ( ! is_array( $found ) ) {
					continue;
				}
				foreach ( $found as $item ) {
					$key = $type . ':' . (int) ( $item['id'] ?? 0 );
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					$seen[ $key ] = true;
					$items[]      = array(
						'type'    => $type,
						'label'   => $label,
						'icon'    => $icon,
						'id'      => (int) ( $item['id'] ?? 0 ),
						'title'   => (string) ( $item['title'] ?? '' ),
						'excerpt' => (string) ( $item['excerpt'] ?? '' ),
						'url'     => (string) ( $item['url'] ?? '' ),
						'score'   => 0.0,
					);
				}
			}
		}

		$scored = array();
		foreach ( $items as $item ) {
			$score = $this->token_score( $ranking, $item['title'], $item['excerpt'] );
			if ( $score <= 0.0 ) {
				continue;
			}
			$item['score'] = $score;
			$scored[]      = $item;
		}

		usort(
			$scored,
			static function ( array $a, array $b ): int {
				if ( $a['score'] === $b['score'] ) {
					return $a['title'] <=> $b['title'];
				}
				return $a['score'] < $b['score'] ? 1 : -1;
			}
		);

		return array_slice( $scored, 0, $limit );
	}

	/**
	 * Relevance for a multi-term paraphrase search: how many distinctive
	 * terms appear in the item text, weighted by token overlap so that items
	 * sharing several meaningful words rank above single-word matches.
	 * Loose matches are dropped unless the query is a single topic word
	 * (where a lone hit is meaningful), the item shares a broad overlap, or
	 * the item's title carries at least half of the distinctive query terms
	 * ("find me a cafe or restaurant" still surfaces a business actually
	 * named "Cafe" even though it only matches one of the two words).
	 *
	 * @param array  $terms Terms.
	 * @param string $title Title.
	 * @param string $excerpt Excerpt.
	 */
	private function token_score( array $terms, string $title, string $excerpt ): float {
		$terms       = array_map( array( __CLASS__, 'fold' ), $terms );
		$title_lower = self::fold( mb_strtolower( $title ) );
		$text        = $title_lower . ' ' . self::fold( mb_strtolower( $excerpt ) );
		$tokens      = self::tokens( $title . ' ' . $excerpt );

		$hits       = 0;
		$title_hits = 0;
		foreach ( $terms as $term ) {
			if ( false !== mb_strpos( $text, $term ) ) {
				++$hits;
			}
			if ( false !== mb_strpos( $title_lower, $term ) ) {
				++$title_hits;
			}
		}
		if ( 0 === $hits ) {
			return 0.0;
		}

		$intersection = count( array_intersect( $terms, $tokens ) );
		$union        = count( array_unique( array_merge( $terms, $tokens ) ) );
		$overlap      = $union > 0 ? $intersection / $union : 0.0;

		$passes = $hits >= 2
			|| $overlap >= 0.2
			|| ( 1 === count( $terms ) && $hits >= 1 )
			|| ( 0 < count( $terms ) && $title_hits / count( $terms ) >= 0.5 );
		if ( ! $passes ) {
			return 0.0;
		}

		// Title hits weigh far more than excerpt hits so a business actually.
		// named after the query term wins over rows that merely mention it.
		return $overlap * 100.0 + (float) min( 8, $hits ) * 10.0 + (float) min( 6, $title_hits ) * 50.0;
	}

	/**
	 * All registered search sources.
	 *
	 * @return array<int,array{type:string,label:string,icon:string,search:callable}>
	 */
	public function get_sources(): array {
		return apply_filters( 'zeko_ai_search_sources', array() );
	}

	/**
	 * Count sources.
	 */
	public function count_sources(): int {
		return count( $this->get_sources() );
	}

	/**
	 * Search every source and return a merged, ranked result set.
	 *
	 * @return array<int,array{type:string,label:string,icon:string,id:int,title:string,excerpt:string,url:string,score:float}>
	 * @param string $term * @param array  $opts Supports: per_type (int) results per source, limit (int) overall.
	 * @param array  $opts Opts.
	 */
	public function search( string $term, array $opts = array() ): array {
		$term = trim( (string) $term );
		if ( '' === $term ) {
			return array();
		}

		$per_type = isset( $opts['per_type'] ) ? max( 1, min( 50, (int) $opts['per_type'] ) ) : 5;
		$limit    = isset( $opts['limit'] ) ? max( 1, min( 200, (int) $opts['limit'] ) ) : 40;

		$results = array();
		foreach ( $this->get_sources() as $source ) {
			if ( empty( $source['search'] ) || ! is_callable( $source['search'] ) ) {
				continue;
			}
			$items = call_user_func( $source['search'], $term, $per_type );
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				$results[] = array(
					'type'    => (string) ( $source['type'] ?? '' ),
					'label'   => (string) ( $source['label'] ?? '' ),
					'icon'    => (string) ( $source['icon'] ?? 'dashicons-search' ),
					'id'      => (int) ( $item['id'] ?? 0 ),
					'title'   => (string) ( $item['title'] ?? '' ),
					'excerpt' => (string) ( $item['excerpt'] ?? '' ),
					'url'     => (string) ( $item['url'] ?? '' ),
					'score'   => $this->score( $term, (string) ( $item['title'] ?? '' ), (string) ( $item['excerpt'] ?? '' ) ),
				);
			}
		}

		usort(
			$results,
			static function ( array $a, array $b ): int {
				if ( $a['score'] === $b['score'] ) {
					return $a['title'] <=> $b['title'];
				}
				return $a['score'] < $b['score'] ? 1 : -1;
			}
		);

		return array_slice( $results, 0, $limit );
	}

	/**
	 * Site-first, web-after search. Returns the normal on-site merged results
	 * plus a short set of extra results from the web engine, so search
	 * surfaces mirror the chat agent: Zeko results come first, then a clearly
	 * labelled web section. Web rows carry the pseudo-type 'web' (id 0) and
	 * are appended after the ranked on-site rows, so any renderer that groups
	 * by type shows them as one trailing section with no rework.
	 * The web client always runs under the zeko_ai_web_search_http gate (off
	 * by default in tests and for privacy-conscious sites) and fails soft, so
	 * a provider outage never breaks the on-site search.
	 * web (int) number of extra web rows to append.
	 *
	 * @return array{results:array<int,array>,web:array<int,array>,total:int}
	 * @param string $term Query term.
	 * @param array  $opts Supports: per_type (int), limit (int) on-site cap,.
	 */
	public function search_with_web( string $term, array $opts = array() ): array {
		$results = $this->search(
			$term,
			array(
				'per_type' => isset( $opts['per_type'] ) ? (int) $opts['per_type'] : 5,
				'limit'    => isset( $opts['limit'] ) ? (int) $opts['limit'] : 30,
			)
		);

		$web    = array();
		$client = new Zeko_AI_Web_Search( zeko_ai_get_settings() );
		if ( $client->enabled() ) {
			$count = isset( $opts['web'] ) ? max( 1, min( 10, (int) $opts['web'] ) ) : 3;
			try {
				$web = (array) $client->search( $term, $count );
			} catch ( Exception $e ) {
				$web = array();
			}
			foreach ( $web as &$row ) {
				$row['type']  = 'web';
				$row['label'] = __( 'Web', 'zeko-ai' );
				$row['icon']  = 'dashicons-admin-site';
				$row['id']    = 0;
			}
			unset( $row );
		}

		$merged = array_merge( $results, $web );

		return array(
			'results' => $merged,
			'web'     => $web,
			'total'   => count( $merged ),
		);
	}

	/**
	 * Deterministic relevance score: exact/prefix title matches weigh far
	 * more than a single keyword hit in the excerpt.
	 *
	 * Filler words and the platform's own name are not evidence of a match, so
	 * they are skipped: otherwise every "… on Zeko" query scored a hit on any
	 * record titled "Zeko …" and unrelated rows outranked real ones.
	 *
	 * @param string $term Term.
	 * @param string $title Title.
	 * @param string $excerpt Excerpt.
	 */
	private function score( string $term, string $title, string $excerpt ): float {
		$term    = self::fold( mb_strtolower( trim( $term ) ) );
		$title   = self::fold( mb_strtolower( $title ) );
		$excerpt = self::fold( mb_strtolower( $excerpt ) );
		$score   = 0.0;

		if ( '' === $term || '' === $title ) {
			return $score;
		}

		if ( $title === $term ) {
			$score += 100.0;
		} elseif ( 0 === mb_strpos( $title, $term ) ) {
			$score += 60.0;
		} elseif ( false !== mb_strpos( $title, $term ) ) {
			$score += 40.0;
		}

		$tokens = array_values( array_filter( explode( ' ', $term ) ) );
		foreach ( $tokens as $token ) {
			if ( mb_strlen( $token ) < 3 || in_array( $token, self::GENERIC_TERMS, true ) || in_array( $token, self::PLATFORM_TERMS, true ) ) {
				continue;
			}
			if ( false !== mb_strpos( $title, $token ) ) {
				$score += 15.0;
			}
			if ( false !== mb_strpos( $excerpt, $token ) ) {
				$score += 5.0;
			}
		}

		return $score;
	}
}
