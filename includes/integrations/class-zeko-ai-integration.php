<?php
/**
 * Base class for Zeko AI cross-module integrations.
 *
 * Every integration contributes six things to the AI layer:
 *   - assistant context blocks  (filter: zeko_ai_assistant_context)
 *   - content-generation presets (filter: zeko_ai_content_presets)
 *   - unified search sources     (filter: zeko_ai_search_sources)
 *   - recommendation sources     (filter: zeko_ai_recommendation_sources)
 *   - moderation review sources  (filter: zeko_ai_moderation_sources)
 *   - entity resolvers           (filter: zeko_ai_entity_resolvers)
 *
 * Subclasses also hook module actions for post-create auto-moderation.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Integration. */
abstract class Zeko_AI_Integration {

	/**
	 * Construct.
	 */
	public function __construct() {
		add_filter( 'zeko_ai_assistant_context', array( $this, 'context' ), 10, 1 );
		add_filter( 'zeko_ai_content_presets', array( $this, 'presets' ), 10, 1 );
		add_filter( 'zeko_ai_search_sources', array( $this, 'search_sources' ), 10, 1 );
		add_filter( 'zeko_ai_recommendation_sources', array( $this, 'recommendation_sources' ), 10, 1 );
		add_filter( 'zeko_ai_moderation_sources', array( $this, 'moderation_sources' ), 10, 1 );
		add_filter( 'zeko_ai_entity_resolvers', array( $this, 'entity_resolvers' ), 10, 1 );

		$this->hooks();
	}

	/**
	 * Module-specific hooks (auto-moderation on create, etc.).
	 */
	protected function hooks(): void {
	}

	/**
	 * Context.
	 *
	 * @return array<int,array{module:string,label:string,items:array}>
	 * @param array $blocks * @return array<int,array{module:string,label:string,items:array}>.
	 */
	public function context( array $blocks ): array {
		return $blocks;
	}

	/**
	 * Presets.
	 *
	 * @return array
	 * @param array $presets * @return array.
	 */
	public function presets( array $presets ): array {
		return $presets;
	}

	/**
	 * Search sources.
	 *
	 * @return array
	 * @param array $sources * @return array.
	 */
	public function search_sources( array $sources ): array {
		return $sources;
	}

	/**
	 * Recommendation sources.
	 *
	 * @return array
	 * @param array $sources * @return array.
	 */
	public function recommendation_sources( array $sources ): array {
		return $sources;
	}

	/**
	 * Moderation sources.
	 *
	 * @return array
	 * @param array $sources * @return array.
	 */
	public function moderation_sources( array $sources ): array {
		return $sources;
	}

	/**
	 * Entity resolvers let the assistant open a specific item (a business, a
	 * job, a course…) into a rich detail card instead of a shallow corpus
	 * line. Each resolver receives the resolved query text and returns a
	 * card array (see `entity_card()` for the shape) or null when the query
	 * does not confidently name one of this module's items.
	 *
	 * @return array
	 * @param array $resolvers * @return array.
	 */
	public function entity_resolvers( array $resolvers ): array {
		return $resolvers;
	}

	/**
	 * Normalize a string for tolerant entity-name matching (lowercase,
	 * punctuation stripped, whitespace collapsed, common accented letters
	 * folded to ASCII so "cafe" matches "Café").
	 *
	 * @param string $text Text.
	 */
	protected function entity_norm( string $text ): string {
		$accents = array(
			'á' => 'a',
			'à' => 'a',
			'â' => 'a',
			'ä' => 'a',
			'ã' => 'a',
			'å' => 'a',
			'é' => 'e',
			'è' => 'e',
			'ê' => 'e',
			'ë' => 'e',
			'í' => 'i',
			'ì' => 'i',
			'î' => 'i',
			'ï' => 'i',
			'ó' => 'o',
			'ò' => 'o',
			'ô' => 'o',
			'ö' => 'o',
			'õ' => 'o',
			'ø' => 'o',
			'ú' => 'u',
			'ù' => 'u',
			'û' => 'u',
			'ü' => 'u',
			'ç' => 'c',
			'ñ' => 'n',
			'ý' => 'y',
			'ÿ' => 'y',
			'æ' => 'ae',
			'œ' => 'oe',
			'ß' => 'ss',
		);
		$text    = mb_strtolower( strtr( (string) $text, $accents ) );
		return preg_replace( '/\s+/u', ' ', trim( preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text ) ) );
	}

	/**
	 * Tokens that carry no name signal. Ignored when counting overlap so a
	 * query like "find me a cafe" never matches a "Find a Mentor" page just
	 * because both contain the word "a".
	 *
	 * @return string[]
	 */
	protected function entity_stop_tokens(): array {
		return array(
			'the',
			'and',
			'for',
			'are',
			'you',
			'your',
			'how',
			'who',
			'what',
			'when',
			'where',
			'why',
			'that',
			'this',
			'with',
			'they',
			'were',
			'been',
			'have',
			'had',
			'from',
			'but',
			'not',
			'all',
			'can',
			'did',
			'out',
			'per',
			'via',
			'its',
			'our',
			'her',
			'him',
			'one',
			'two',
			'into',
			'over',
			'very',
			'which',
			'their',
			'there',
			'about',
			'get',
		);
	}

	/**
	 * Pairs a normalized string into signal tokens (stop words and one/two
	 * letter fragments removed, duplicates collapsed).
	 *
	 * @return string[]
	 * @param string $text Text.
	 */
	protected function entity_tokens( string $text ): array {
		$tokens = array_values( array_filter( preg_split( '/\s+/u', $text ) ) );
		$stop   = $this->entity_stop_tokens();
		return array_values(
			array_unique(
				array_filter(
					$tokens,
					static function ( string $token ) use ( $stop ): bool {
						return mb_strlen( $token ) >= 3 && ! in_array( $token, $stop, true );
					}
				)
			)
		);
	}

	/**
	 * Tokens that describe *what the member wants from* an entity rather than
	 * which* entity. A query like "what time does codecraft open?" names the
	 * business with its distinctive brand token and adds intent words around
	 * it ("time", "open"); those words must not dilute the match. Conversely a
	 * bare category word ("cafe", "mentor") must never promote one random
	 * listing, so leading title tokens that are category words stay on the
	 * plain recall path and never trigger the brand-hit boost.
	 *
	 * @return string[]
	 */
	protected function entity_intent_tokens(): array {
		$tokens = array(
			'open',
			'close',
			'closed',
			'opened',
			'opens',
			'closes',
			'closing',
			'time',
			'times',
			'hours',
			'today',
			'tomorrow',
			'now',
			'later',
			'price',
			'prices',
			'cost',
			'costs',
			'rate',
			'rates',
			'fee',
			'fees',
			'address',
			'phone',
			'contact',
			'location',
			'directions',
			'near',
			'find',
			'found',
			'search',
			'look',
			'looking',
			'show',
			'tell',
			'about',
			'info',
			'information',
			'details',
			'review',
			'reviews',
			'rating',
			'website',
			'whatsapp',
			'offer',
			'offers',
			'have',
			'does',
			'do',
			'give',
			'get',
			'please',
			'any',
			'some',
			'cafe',
			'cafes',
			'café',
			'cafés',
			'restaurant',
			'restaurants',
			'store',
			'stores',
			'shop',
			'shops',
			'shopping',
			'mall',
			'bistro',
			'bar',
			'menu',
			'food',
			'drinks',
			'coffee',
			'mentor',
			'mentors',
			'coach',
			'coaching',
			'job',
			'jobs',
			'career',
			'careers',
			'role',
			'roles',
			'position',
			'positions',
			'course',
			'courses',
			'class',
			'classes',
			'lesson',
			'lessons',
			'training',
			'academy',
			'school',
			'institute',
			'university',
			'question',
			'questions',
			'answer',
			'answers',
			'ask',
			'product',
			'products',
			'item',
			'items',
			'buy',
			'sale',
			'sales',
			'project',
			'projects',
			'proposal',
			'proposals',
			'freelance',
			'gig',
			'gigs',
			'service',
			'services',
			'appointment',
			'booking',
			'book',
			'booked',
			'plan',
			'plans',
			'portfolio',
		);

		// Filterable so agent keywords widened via 'zeko_ai_agent_intents'.
		// (or custom module vocabularies) can also be exempted here, keeping.
		// the brand-hit boost honest: a new category word must never promote.
		// one random listing.
		return apply_filters( 'zeko_ai_entity_intent_tokens', $tokens );
	}

	/**
	 * Score how strongly a query names an exact entity title. Full-name
	 * containment scores a perfect 1.0; otherwise the fraction of the
	 * entity-name tokens that appear in the query decides, so broad terms
	 * like "a cafe" never hijack a specific listing.
	 * When the entity's *leading* token is a distinctive name token (not a
	 * category word) and it appears in the query, a brand hit is assumed and
	 * the score is raised: "what time does codecraft open?" then resolves to
	 * "CodeCraft Web Studio" even though the query drops the "Web Studio"
	 * descriptor tokens and adds intent words. Intent words around a brand
	 * token must not dilute a match, so the boost compensates for them.
	 *
	 * @return float 0.0 (no match) .. 1.0 (full name present)
	 * @param string $query Query.
	 * @param string $name Name.
	 */
	protected function entity_match_score( string $query, string $name ): float {
		$q = $this->entity_norm( $query );
		$n = $this->entity_norm( $name );

		if ( '' === $q || '' === $n ) {
			return 0.0;
		}
		if ( false !== mb_strpos( $q, $n ) ) {
			return 1.0;
		}

		$qtokens = $this->entity_tokens( $q );
		$ntokens = $this->entity_tokens( $n );

		if ( empty( $ntokens ) ) {
			return 0.0;
		}

		$hits = 0;
		foreach ( $ntokens as $token ) {
			if ( in_array( $token, $qtokens, true ) ) {
				++$hits;
			}
		}

		$recall = (float) ( $hits / count( $ntokens ) );
		if ( $recall >= 0.6 ) {
			return $recall;
		}

		// Brand-hit boost: the query carries the entity's leading distinctive.
		// token. Extra intent words around it no longer dilute the match.
		// Bare category words ("cafe", "mentor") are excluded so a vague.
		// query never hijacks one listing.
		$lead = $ntokens[0];
		if (
			$hits > 0
			&& mb_strlen( $lead ) >= 5
			&& ! in_array( $lead, $this->entity_intent_tokens(), true )
			&& in_array( $lead, $qtokens, true )
		) {
			return min( 1.0, $recall + 0.5 );
		}

		return $recall;
	}

	/**
	 * Standard shape of an entity detail card the assistant can render.
	 *
	 * @param string $type Module key (e.g. 'business').
	 * @param string $label Human module label (e.g. 'Business').
	 * @param string $title Entity title (the matched name).
	 * @param string $url Public page URL for this entity.
	 * @param float  $score Name-match confidence from entity_match_score().
	 * @param array  $data Optional rich data used by the renderer.
	 */
	protected function entity_card( string $type, string $label, string $title, string $url, float $score, array $data = array() ): array {
		return wp_parse_args(
			$data,
			array(
				'type'      => $type,
				'label'     => $label,
				'title'     => $title,
				'url'       => $url,
				'score'     => $score,
				'subtitle'  => '',
				'badges'    => array(),
				'facts'     => array(),
				'sections'  => array(),
				'actions'   => array(),
				'web_terms' => array( $title ),
				'focus'     => '',
			)
		);
	}

	/**
	 * One action button for the chat UI (deep link to a page or tab).
	 *
	 * @return array<int,array>
	 * @param string $key Key.
	 * @param string $label Label.
	 * @param string $url Url.
	 */
	protected function entity_action( string $key, string $label, string $url ): array {
		return array(
			'key'   => $key,
			'label' => $label,
			'url'   => $url,
		);
	}

	/**
	 * Wrap an assistant context item list (deduplicates the item shape).
	 *
	 * @param string $module Module.
	 * @param string $label Label.
	 * @param array  $items Items.
	 */
	protected function context_block( string $module, string $label, array $items ): array {
		$clean = array();
		foreach ( $items as $item ) {
			$clean[] = array(
				'title'   => isset( $item['title'] ) ? (string) $item['title'] : '',
				'url'     => isset( $item['url'] ) ? (string) $item['url'] : '',
				'snippet' => isset( $item['snippet'] ) ? wp_trim_words( (string) $item['snippet'], 24 ) : '',
			);
		}
		return array(
			'module' => $module,
			'label'  => $label,
			'items'  => $clean,
		);
	}

	/**
	 * Run moderation on a freshly-created item, if the feature is enabled.
	 * Integrations call this from their module create actions.
	 *
	 * @return string|null Decision, or null when moderation is disabled.
	 * @param int    $user_id * @param string $source Module slug.
	 * @param string $source Source.
	 * @param string $content_type * @param int    $content_id.
	 * @param int    $content_id Content id.
	 * @param string $content * @return string|null Decision, or null when moderation is disabled.
	 */
	protected function moderate( int $user_id, string $source, string $content_type, int $content_id, string $content ): ?string {
		if ( ! zeko_ai()->get_settings()->is_enabled( 'moderation' ) ) {
			return null;
		}
		return zeko_ai()->get_moderation()->maybe_review( $user_id, $source, $content_type, $content_id, $content );
	}

	/**
	 * Front-end URL for a module page. Modules with their own permalink
	 * helper are resolved through it so links point at the real page; the
	 * rest fall back to a canonical path. Either way the result is filterable
	 * via `zeko_ai_agent_module_url` for overrides and tests.
	 *
	 * @param string $path Path.
	 */
	protected function module_url( string $path ): string {
		$helpers = array(
			'/freelance/'          => array( 'zeko_freelance_page_url', 'freelance-projects' ),
			'/shop/'               => array( 'zeko_shop_page_url', 'shop' ),
			'/dating-matches/'     => array( 'zeko_love_page_url', 'dating-matches' ),
			'/rewards/'            => array( 'zeko_rewards_page_url', 'rewards' ),
			'/ai-search/'          => array( 'zeko_ai_page_url', 'ai-search' ),
			'/ai-recommendations/' => array( 'zeko_ai_page_url', 'ai-recommendations' ),
			'/ai-assistant/'       => array( 'zeko_ai_page_url', 'ai-assistant' ),
		);

		if ( isset( $helpers[ $path ] ) ) {
			list( $function, $slug ) = $helpers[ $path ];
			if ( function_exists( $function ) ) {
				return (string) apply_filters( 'zeko_ai_agent_module_url', $function( $slug ), $path );
			}
		}

		return (string) apply_filters( 'zeko_ai_agent_module_url', home_url( $path ), $path );
	}
}
