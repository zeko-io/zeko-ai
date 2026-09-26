<?php
/**
 * Per-user agent memory.
 *
 * Lets the Community Agent remember durable facts members tell it ("my name
 * is Ada", "I'm learning PHP", "I prefer remote work"). Facts are extracted
 * from chat turns with lightweight patterns, stored per user, and re-injected
 * into later corpus searches so answers get personal ("recommend something"
 * surfaces web-development rows for a user learning web development).
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Memory. */
class Zeko_AI_Memory {

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
	 * Extract durable facts from a chat turn.
	 *
	 * @return array<string,string> fact_key => value (already guarded).
	 * @param string $text Text.
	 */
	public function extract( string $text ): array {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}

		$facts = array();
		$low   = mb_strtolower( $text );

		// Name: "my name is Ada" / "call me Ada" / "I am Ada".
		if ( preg_match( '/my name is\s+([a-z0-9][a-z0-9 .\'-]{1,39})/i', $text, $m ) ) {
			$facts['name'] = $this->clean_name( $m[1] );
		} elseif ( preg_match( '/call me\s+([a-z0-9][a-z0-9 .\'-]{1,39})/i', $text, $m ) ) {
			$facts['name'] = $this->clean_name( $m[1] );
		} elseif ( preg_match( '/\bi am\s+(?!a[n]?\s)([a-z][a-z .\'-]{1,39})/i', $text, $m ) && ! preg_match( '/(looking|asking|trying|learning|working|new here)/i', $m[1] ) ) {
			$facts['name'] = $this->clean_name( $m[1] );
		}

		// Learning / goals: "I'm learning PHP" / "I want to learn Laravel".
		foreach ( array( '/\bi(\'m| am) learning\s+([a-z0-9 .\'-]{2,80})/i', '/\bi want to learn\s+([a-z0-9 .\'-]{2,80})/i' ) as $pattern ) {
			if ( preg_match( $pattern, $text, $m ) ) {
				$value = $this->clean_value( $m[2] ?? $m[1] );
				if ( '' !== $value ) {
					$facts['interest'] = $value;
				}
				break;
			}
		}

		// Preferences: "I prefer remote work" / "I like PHP".
		foreach ( array( '/\bi prefer\s+([a-z0-9 .\'-]{2,80})/i', '/\bi like\s+([a-z0-9 .\'-]{2,80})/i' ) as $pattern ) {
			if ( preg_match( $pattern, $text, $m ) ) {
				$value = $this->clean_value( $m[1] );
				if ( '' !== $value && ! in_array( $value, array( 'that', 'it', 'this', 'those', 'you', 'learning' ), true ) ) {
					$facts['preference'] = $value;
				}
				break;
			}
		}

		// Role: "I am a student" / "I'm a web developer".
		if ( preg_match( '/\bi(\'m| am) a(?:n)?\s+([a-z][a-z .\'-]{2,60})/i', $text, $m ) && ! preg_match( '/(looking|asking|trying|starting|new)/i', $m[2] ) ) {
			$value = $this->clean_value( $m[2] );
			if ( '' !== $value ) {
				$facts['role'] = $value;
			}
		}

		// Goal: "I want a remote job" / "I'm looking for freelance work".
		if ( preg_match( '/\bi(\'m| am) looking for\s+([a-z0-9 .\'-]{2,80})/i', $text, $m ) ) {
			$value = $this->clean_value( $m[2] ?? $m[1] );
			if ( '' !== $value ) {
				$facts['goal'] = $value;
			}
		}

		return $facts;
	}

	/**
	 * Store newly discovered facts for a user (auto-learned, gated by the
	 * caller's learning setting). Returns the number stored.
	 *
	 * @param int    $user_id User id.
	 * @param string $text Text.
	 */
	public function capture( int $user_id, string $text ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		$stored = 0;
		foreach ( $this->extract( $text ) as $key => $value ) {
			$existing = $this->db->get_user_memory( $user_id );
			if ( isset( $existing[ $key ] ) && $existing[ $key ] === $value ) {
				continue;
			}
			$this->db->insert_memory(
				array(
					'user_id'    => $user_id,
					'fact_key'   => $key,
					'fact_value' => $value,
					'source'     => 'auto',
				)
			);
			++$stored;
		}

		return $stored;
	}

	/**
	 * Latest remembered facts for a user.
	 *
	 * @return array<string,string>
	 * @param int $user_id User id.
	 */
	public function facts( int $user_id ): array {
		return $this->db->get_user_memory( $user_id );
	}

	/**
	 * Store what the member last discussed so a brand-new conversation can
	 * resume ("you were looking for a remote Laravel role last time").
	 * A new row is inserted per topic; get_user_memory already returns the
	 * latest value per key, so repeated calls simply replace the recap.
	 *
	 * @return int 1 when stored, 0 when empty/skipped.
	 * @param int    $user_id * @param string $topic  Short summary of the last conversation subject.
	 * @param string $topic Topic.
	 */
	public function recap( int $user_id, string $topic ): int {
		$topic = trim( (string) $topic );
		if ( $user_id <= 0 || '' === $topic ) {
			return 0;
		}

		$existing = $this->db->get_user_memory( $user_id );
		if ( isset( $existing['recap'] ) && $existing['recap'] === $topic ) {
			return 0;
		}

		return $this->db->insert_memory(
			array(
				'user_id'    => $user_id,
				'fact_key'   => 'recap',
				'fact_value' => mb_substr( $topic, 0, 120 ),
				'source'     => 'auto',
			)
		) > 0 ? 1 : 0;
	}

	/**
	 * The last conversation topic remembered for a user ('' when none).
	 *
	 * @param int $user_id User id.
	 */
	public function latest_recap( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$facts = $this->facts( $user_id );
		return (string) ( $facts['recap'] ?? '' );
	}

	/**
	 * Remembered values joined into a plain string for query enrichment
	 * ("web development PHP remote").
	 *
	 * @param int $user_id User id.
	 */
	public function keywords( int $user_id ): string {
		return implode( ' ', array_values( $this->facts( $user_id ) ) );
	}

	/**
	 * Guard an extracted value: cut at sentence boundaries and trailing
	 * clause connectors, then strip filler and enforce a min length.
	 *
	 * @param string $value Value.
	 */
	private function clean_value( string $value ): string {
		$value = trim( $value );
		// Cut at the first sentence end ("PHP. I prefer remote work" -> "PHP").
		$value = preg_split( '/[.!?]+(?:\s+|$)/', $value )[0];
		// Cut at clause connectors that introduce a new subject ("PHP and I.
		// prefer..." -> "PHP"), while keeping list-style subjects ("PHP and.
		// JavaScript").
		$value = preg_split( '/\s+(?:and|but|so|while|because|then)\s+(?=(?:i|i\'m|i\s+am|im|my|you|we|they|it|that|this|there)\b)/i', $value )[0];
		$value = preg_replace( '/[,.!?]+$/u', '', trim( $value ) );
		$value = preg_replace( '/\s+/u', ' ', $value );
		return mb_strlen( $value ) < 3 ? '' : $value;
	}

	/**
	 * Normalize a captured name: cut at trailing connectors/filler and keep
	 * only a short alpha label so "Ada and I am learning PHP" becomes "Ada".
	 *
	 * @param string $value Value.
	 */
	private function clean_name( string $value ): string {
		$value = preg_split( '/\s+(?:and|but|so|because|then|my|im|i)\b/i', trim( $value ) )[0];
		$words = preg_split( '/\s+/', trim( (string) $value ) );
		while ( $words && in_array( mb_strtolower( end( $words ) ), array( 'please', 'thanks', 'thank', 'you', 'so', 'then', 'and' ), true ) ) {
			array_pop( $words );
		}
		$value = trim( implode( ' ', $words ) );
		$value = preg_replace( '/[,.!?]+$/u', '', $value );
		return ( '' === $value || mb_strlen( $value ) < 2 ) ? '' : $value;
	}
}
