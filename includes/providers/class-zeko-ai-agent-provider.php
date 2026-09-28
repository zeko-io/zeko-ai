<?php
/**
 * Self-training community agent provider.
 *
 * Works fully offline with no API key: it answers questions by retrieving
 * real Zeko-community data through the same corpus search sources the rest
 * of the module uses, reinforced by a learned knowledge table (FAQ-style
 * Q&A entries taught from the admin). Every query is logged so admins can
 * see gaps and teach new answers; a private world-knowledge lookup via the
 * free Wikipedia summary endpoint is available behind a setting that is off
 * by default and always fail-soft.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Agent_Provider. */
class Zeko_AI_Agent_Provider extends Zeko_AI_Provider {

	/**
	 * SIMILARITY MIN.
	 *
	 * @var mixed
	 */
	const SIMILARITY_MIN = 0.35;

	/**
	 * CORPUS MARKER.
	 *
	 * @var mixed
	 */
	const CORPUS_MARKER = 'I found a few things in the Zeko ecosystem';

	/**
	 * ENTITY SCORE MIN.
	 *
	 * @var mixed
	 */
	const ENTITY_SCORE_MIN = 0.6;

	/**
	 * CLARIFY PROMPT.
	 *
	 * @var mixed
	 */
	const CLARIFY_PROMPT = "\n\nWas that what you were looking for? If not, tell me a little more and I'll refine my answer.";

	/**
	 * Db.
	 *
	 * @var mixed Db.
	 */
	private $db;

	/**
	 * Web.
	 *
	 * @var mixed Web.
	 */
	private $web;

	/**
	 * Intents.
	 *
	 * @var mixed Intents.
	 */
	private $intents = array(
		'greeting'   => array( 'hi', 'hello', 'hey', 'help' ),
		'recommend'  => array( 'recommend', 'suggest', 'match me' ),
		'jobs'       => array( 'job', 'career', 'careers', 'hiring', 'vacancy', 'resume', 'interview', 'application', 'applications', 'employer', 'salary' ),
		'learn'      => array( 'course', 'learn', 'lesson', 'quiz', 'certificate', 'class', 'training', 'tutorial', 'tutor', 'study', 'enroll' ),
		'qa'         => array( 'question', 'q&a', 'answer', 'ask', 'discussion', 'forum', 'thread' ),
		'business'   => array( 'business', 'businesses', 'company', 'restaurant', 'restaurants', 'cafe', 'café', 'bistro', 'gym', 'salon', 'directory', 'barber', 'plumber', 'hotel', 'pharmacy', 'supermarket', 'clinic', 'dentist', 'mechanic', 'spa', 'bakery', 'laundromat', 'locksmith', 'photographer', 'contractor', 'tattoo', 'venue', 'menu', 'takeout', 'delivery', 'opening hours', 'business hours', 'hours of operation', 'book a service', 'book a table', 'appointment', 'booking', 'near me', 'nearby', 'storefront', 'store hours', 'hours', 'listing', 'open now', 'bank', 'accountant', 'florist', 'tailor', 'hardware store', 'electronics store', 'furniture store', 'shoe store', 'car wash', 'dry cleaning', 'butcher', 'travel agent' ),
		'freelance'  => array( 'freelanc', 'project', 'bid', 'gig', 'proposal', 'contract', 'portfolio', 'invoice', 'milestone' ),
		'shop'       => array( 'shop', 'product', 'buy', 'cart', 'order', 'store' ),
		'mentor'     => array( 'mentor', 'session', 'coach', 'guidance', 'career advice', 'life coaching' ),
		'love'       => array( 'date', 'dating', 'match', 'love', 'relationship', 'crush', 'partner', 'boyfriend', 'girlfriend', 'romance', 'suitor', 'matchmaking' ),
		'wallet'     => array( 'wallet', 'pay', 'balance', 'transaction', 'payment', 'account number', 'deposit', 'refund', 'payout', 'receive money', 'fund' ),
		'profile'    => array( 'profile' ),
		'rewards'    => array( 'reward', 'badge', 'point', 'redeem', 'milestone', 'achievement', 'cashback', 'loyalty', 'tier' ),
		'moderation' => array( 'moderat', 'safe', 'report', 'abuse', 'spam', 'harmful', 'inappropriate', 'harass', 'bully', 'flag' ),
		'search'     => array( 'search', 'find' ),
	);

	/**
	 * Flagged words.
	 *
	 * @var mixed Flagged words.
	 */
	private $flagged_words = array(
		'violence'   => 'Violence',
		'hate'       => 'Hate speech',
		'harass'     => 'Harassment',
		'harassment' => 'Harassment',
		'spam'       => 'Spam',
		'sex'        => 'Sexual content',
		'porn'       => 'Sexual content',
		'scam'       => 'Scam / fraud',
		'fraud'      => 'Scam / fraud',
		'gambling'   => 'Gambling',
		'weapon'     => 'Weapons',
		'kill'       => 'Violence',
		'murder'     => 'Violence',
	);

	/**
	 * Construct.
	 *
	 * @param array       $settings Settings.
	 * @param ?Zeko_AI_DB $db Db.
	 */
	public function __construct( array $settings, ?Zeko_AI_DB $db = null ) {
		parent::__construct( $settings );
		$this->db = $db;
	}

	/**
	 * Name.
	 */
	public function name(): string {
		return 'agent';
	}

	/**
	 * Model.
	 */
	public function model(): string {
		return 'zeko-agent-0.5.1';
	}

	/**
	 * Answer a conversation turn from a named Zeko item (entity card), then
	 * learned knowledge, then the live ecosystem corpus, then (optional,
	 * fail-soft) Wikipedia, then (optional, fail-soft, keyless-first) web
	 * search, then an intent-based module intro. Multi-turn: a follow-up turn
	 * is resolved against the previous user question so "what about it?"
	 * still hits the right entity/knowledge/corpus. Every query is logged so
	 * admins can see gaps and teach new answers; web search runs only when
	 * nothing on Zeko matched, so ecosystem answers always win and outbound
	 * queries are kept minimal.
	 *
	 * @param array $messages Messages.
	 * @param array $opts Opts.
	 */
	public function chat( array $messages, array $opts = array() ): array {
		$user_text  = $this->last_user_message( $messages );
		$user_id    = isset( $opts['user_id'] ) ? (int) $opts['user_id'] : 0;
		$learning   = ! empty( $this->settings['agent_learning'] );
		$auto_learn = $learning && ! empty( $this->settings['agent_auto_learn'] );

		// Input guardrail: refuse clearly unsafe requests instead of searching.
		// the corpus for them. Moderation/report questions stay answerable.
		$input_verdict = $this->moderate( $user_text );
		if ( 'flagged' === $input_verdict['decision'] && ! $this->is_moderation_question( $user_text ) ) {
			$reply  = "I'm not able to help with that. If you've seen something that breaks the community guidelines, use the report button on the post or profile and a human moderator will review it.";
			$intent = $this->detect_intent( $user_text );
			$this->log_query( $user_id, $user_text, $intent, true, 'moderated', $learning );
			return array(
				'content'    => $reply,
				'tokens_in'  => $this->estimate_tokens( $this->flatten( $messages ) ),
				'tokens_out' => $this->estimate_tokens( $reply ),
				'model'      => $this->model(),
				'raw'        => array(
					'intent'       => $intent,
					'source'       => 'moderated',
					'answered'     => true,
					'knowledge_id' => 0,
					'confidence'   => 0.9,
					'guarded'      => true,
					'follow_up'    => false,
					'suggestions'  => array( 'How does content moderation work?', 'How do I report a problem?' ),
					'actions'      => $this->actions_for_intent( $intent, $user_id ),
				),
			);
		}

		// Social openers ("Hello", "hey there", "how are you?") are small
		// talk, not a question. Without this guardrail they were treated as a
		// search term: the corpus returned whatever happened to contain the
		// word "hello" and the web search fired an outbound query for it, so
		// a greeting came back as unrelated listings plus search-engine noise.
		// Answer it directly and never let it reach retrieval.
		if ( $this->is_social_open( $user_text ) ) {
			$intent = 'greeting';
			$reply  = $this->greeting_reply( $user_id );

			// Remember durable facts the member volunteers this turn.
			if ( $learning && $user_id > 0 && $this->get_db() && function_exists( 'zeko_ai' ) && method_exists( zeko_ai(), 'get_memory' ) ) {
				zeko_ai()->get_memory()->capture( $user_id, $user_text );
			}

			$this->log_query( $user_id, $user_text, $intent, true, 'greeting', $learning );

			return array(
				'content'    => $reply,
				'tokens_in'  => $this->estimate_tokens( $this->flatten( $messages ) ),
				'tokens_out' => $this->estimate_tokens( $reply ),
				'model'      => $this->model(),
				'raw'        => array(
					'intent'        => $intent,
					'source'        => 'greeting',
					'answered'      => true,
					'knowledge_id'  => 0,
					'module'        => '',
					'confidence'    => 0.95,
					'guarded'       => false,
					'follow_up'     => false,
					'corpus_titles' => array(),
					'web_results'   => array(),
					'entity'        => array(),
					'suggestions'   => $this->filter_suggestions( $this->intent_prompts( 'greeting' ), $user_text ),
					'actions'       => $this->actions_for_intent( $intent, $user_id ),
					'memory'        => ( $user_id > 0 && $learning && function_exists( 'zeko_ai' ) && method_exists( zeko_ai(), 'get_memory' ) )
						? zeko_ai()->get_memory()->facts( $user_id )
						: array(),
				),
			);
		}

		// Multi-turn context: a follow-up turn inherits the previous user.
		// question so retrieval and intent detection stay on-topic.
		$query     = $this->resolve_query_text( $messages, $user_text );
		$intent    = $this->detect_intent( $query );
		$follow_up = ( $query !== $user_text );

		// When auto-learn is on, a plain "thanks/that helped" after a corpus.
		// answer records the question as learned knowledge so future turns.
		// answer directly (affirmation auto-learning).
		if ( $auto_learn && $this->get_db() ) {
			$this->maybe_auto_learn( $messages, $user_text, $user_id );
		}

		$reply         = '';
		$source        = '';
		$answered      = false;
		$corpus_titles = array();

		// Named Zeko records run first: when the member names a specific item.
		// (a business, job, course…), open it into a rich card built from the.
		// live record. A confident record card beats a generic seeded answer —.
		// "what time does the café open?" should show the café's actual.
		// opening hours, not a pointer to its page — and beats a shallow.
		// corpus line. Modules vote through the zeko_ai_entity_resolvers.
		// filter; the strongest confident match wins, so "find me a cafe".
		// never hijacks a listing.
		//
		// Static site pages (type "site": help pages, articles) are held back:.
		// an admin-taught knowledge answer must still win for "how do I…?".
		// questions even when a page shares the title. Help pages resolve.
		// only when knowledge has no answer at all.
		$entity      = array();
		$site_entity = array();
		$resolved    = $this->resolve_entity( $query );
		if ( $resolved && 'site' === (string) ( $resolved['type'] ?? '' ) ) {
			$site_entity = $resolved;
			$resolved    = array();
		}
		if ( $resolved ) {
			$entity        = $resolved;
			$entity['web'] = $this->augment_entity_web( $entity );
			$reply         = $this->format_entity_card( $entity );
			$source        = 'entity';
			$answered      = true;
		}

		$knowledge = null;
		if ( ! $answered ) {
			$knowledge = $this->find_best_knowledge( $query, $intent );
			if ( $knowledge ) {
				$reply    = (string) $knowledge->answer;
				$source   = 'knowledge';
				$answered = true;
				if ( $learning && $this->get_db() ) {
					$this->get_db()->record_knowledge_use( (int) $knowledge->id );
				}
			}
		}

		if ( ! $answered && $site_entity ) {
			$entity        = $site_entity;
			$entity['web'] = $this->augment_entity_web( $entity );
			$reply         = $this->format_entity_card( $entity );
			$source        = 'entity';
			$answered      = true;
		}

		if ( ! $answered ) {
			$corpus = $this->search_corpus( $this->with_memory( $query, $user_id, $intent ), 5, $intent );
			if ( $corpus ) {
				$corpus_titles = array_column( $corpus, 'title' );
				$reply         = $this->format_corpus( $corpus );
				$source        = 'corpus';
				$answered      = true;
			}
		}

		// Personal RAG: when the member asks about their own account.
		// ("my portfolio", "what did I publish?"), answer from their real.
		// ecosystem profiles with links instead of searching public content.
		if ( ! $answered && $user_id > 0 ) {
			$personal_module = $this->personal_intent( $query );
			if ( '' !== $personal_module ) {
				$reply    = $this->personal_summary( $user_id, $personal_module );
				$source   = 'personal';
				$answered = true;
			}
		}

		if ( ! $answered && ! empty( $this->settings['agent_world_knowledge'] ) && ! $this->is_ecosystem_request( $query, $intent ) ) {
			$world = $this->fetch_world_knowledge( $query );
			if ( '' !== $world ) {
				$reply    = $world;
				$source   = 'world';
				$answered = true;
			}
		}

		// Web search: only reached when the ecosystem knew nothing, so it is.
		// the right moment to look beyond Zeko. Keyless DuckDuckGo works out.
		// of the box; every provider is fail-soft and cached.
		$web = array();
		if ( ! $answered && $this->web_search()->enabled() ) {
			$web = $this->web_search()->search(
				$query,
				(int) ( $this->settings['web_search_max_results'] ?? 5 )
			);
			if ( $web ) {
				// Before leaving Zeko entirely, point the member at the module.
				// that owns this topic ("go to Dating for matches") and, when.
				// that module has live content matching the intent, list a few.
				// of its records right here. The outbound results still follow,.
				// so the answer grows Zeko-first plus web.
				$module_area = $this->module_area( $intent, $query );
				if ( '' !== $module_area ) {
					$reply = $module_area . "\n\n" . $this->format_web( $web, true );
				} else {
					$reply = $this->format_web( $web );
				}
				$source   = 'web';
				$answered = true;
			}
		}

		// Site-first, web-after: on-site answers carry the main reply; when.
		// web search is switched on, top those answers up with a short extra.
		// set of engine results. Never repeats when the web already became.
		// the sole answer above, and stays silent when the client is off-line.
		if ( 'corpus' === $source && $this->web_search()->enabled() ) {
			$extra = $this->web_search()->search(
				$query,
				min( 3, (int) ( $this->settings['web_search_max_results'] ?? 5 ) )
			);
			if ( $extra ) {
				$web    = $extra;
				$reply .= "\n\n" . $this->format_web( $extra, true );
			}
		}

		if ( ! $answered ) {
			$reply    = $this->intent_fallback( $intent );
			$source   = 'fallback';
			$answered = ( 'general' !== $intent );
		}

		$confidence = $this->confidence_for( $source, $answered );
		if ( $confidence < 0.6 ) {
			$reply .= self::CLARIFY_PROMPT;
		}

		// Output guardrail: never surface a reply that trips the offline.
		// moderation wordlist, even from learned knowledge. Moderation/report.
		// questions already passed the input guardrail as an allowed topic,.
		// and their answers legitimately discuss that vocabulary (web snippets.
		// about reporting scams mention the flagged words), so they are not.
		// re-guarded here.
		$guarded       = false;
		$guarded_reply = $this->is_moderation_question( $query ) ? $reply : $this->guard_reply( $reply );
		if ( $guarded_reply !== $reply ) {
			$reply      = $guarded_reply;
			$guarded    = true;
			$confidence = min( $confidence, 0.5 );
		}

		// Remember durable facts the member volunteers this turn.
		if ( $learning && $user_id > 0 && $this->get_db() && function_exists( 'zeko_ai' ) && method_exists( zeko_ai(), 'get_memory' ) ) {
			zeko_ai()->get_memory()->capture( $user_id, $user_text );
		}

		$raw = array(
			'intent'        => $intent,
			'source'        => $source,
			'answered'      => $answered,
			'knowledge_id'  => $knowledge ? (int) $knowledge->id : 0,
			'module'        => $knowledge ? (string) $knowledge->module : '',
			'confidence'    => $confidence,
			'guarded'       => $guarded,
			'follow_up'     => $follow_up,
			'corpus_titles' => $corpus_titles,
			'web_results'   => ! empty( $web ) ? $web : array(),
			'entity'        => $entity,
			'suggestions'   => $this->filter_suggestions(
				$this->suggest_followups(
					array(
						'source'        => $source,
						'intent'        => $intent,
						'knowledge_id'  => $knowledge ? (int) $knowledge->id : 0,
						'module'        => $knowledge ? (string) $knowledge->module : '',
						'corpus_titles' => $corpus_titles,
						'entity'        => $entity,
					)
				),
				$user_text
			),
			'actions'       => $entity ? (array) ( $entity['actions'] ?? array() ) : $this->actions_for_intent( $intent, $user_id ),
			'memory'        => ( $user_id > 0 && $learning && function_exists( 'zeko_ai' ) && method_exists( zeko_ai(), 'get_memory' ) )
				? zeko_ai()->get_memory()->facts( $user_id )
				: array(),
		);

		$this->log_query( $user_id, $user_text, $intent, $answered, $source, $learning );

		return array(
			'content'    => $reply,
			'tokens_in'  => $this->estimate_tokens( $this->flatten( $messages ) ),
			'tokens_out' => $this->estimate_tokens( $reply ),
			'model'      => $this->model(),
			'raw'        => $raw,
		);
	}

	/**
	 * Persist one agent query row when learning is enabled.
	 *
	 * @param int    $user_id User id.
	 * @param string $text Text.
	 * @param string $intent Intent.
	 * @param bool   $answered Answered.
	 * @param string $mode Mode.
	 * @param bool   $learning Learning.
	 */
	private function log_query( int $user_id, string $text, string $intent, bool $answered, string $mode, bool $learning ): void {
		if ( ! $learning || ! $this->get_db() ) {
			return;
		}
		$this->get_db()->insert_agent_query(
			array(
				'user_id'  => $user_id,
				'query'    => mb_substr( $text, 0, 500 ),
				'intent'   => $intent,
				'answered' => $answered ? 1 : 0,
				'mode'     => $mode,
			)
		);
	}

	/**
	 * Structured actions a member can take from an intent (rendered as
	 * buttons by the chat UI). Filterable per intent for overrides.
	 *
	 * @return array<int,array{key:string,label:string,url:string}>
	 * @param string $intent Intent.
	 * @param int    $user_id User id.
	 */
	public function actions_for_intent( string $intent, int $user_id = 0 ): array {
		$actions = array(
			'jobs'      => array(
				array(
					'key'   => 'jobs',
					'label' => __( 'Browse jobs', 'zeko-ai' ),
					'url'   => $this->module_url( '/jobs/' ),
				),
			),
			'learn'     => array(
				array(
					'key'   => 'learn',
					'label' => __( 'Browse courses', 'zeko-ai' ),
					'url'   => $this->module_url( '/courses/' ),
				),
			),
			'qa'        => array(
				array(
					'key'   => 'qa',
					'label' => __( 'Ask on Q&A', 'zeko-ai' ),
					'url'   => $this->module_url( '/questions/' ),
				),
			),
			'business'  => array(
				array(
					'key'   => 'business-directory',
					'label' => __( 'Browse the directory', 'zeko-ai' ),
					'url'   => $this->business_page_url( 'directory' ),
				),
			),
			'freelance' => array(
				array(
					'key'   => 'freelance',
					'label' => __( 'Browse projects', 'zeko-ai' ),
					'url'   => $this->module_url( '/freelance/' ),
				),
			),
			'shop'      => array(
				array(
					'key'   => 'shop',
					'label' => __( 'Visit the shop', 'zeko-ai' ),
					'url'   => $this->module_url( '/shop/' ),
				),
			),
			'mentor'    => array(
				array(
					'key'   => 'mentor',
					'label' => __( 'Find a mentor', 'zeko-ai' ),
					'url'   => $this->module_url( '/mentors/' ),
				),
			),
			'love'      => array(
				array(
					'key'   => 'love',
					'label' => __( 'Open your matches', 'zeko-ai' ),
					'url'   => $this->module_url( '/dating-matches/' ),
				),
			),
			'wallet'    => array(
				array(
					'key'   => 'wallet',
					'label' => __( 'Open your wallet', 'zeko-ai' ),
					'url'   => $this->module_url( '/wallet/' ),
				),
			),
			'profile'   => array(
				array(
					'key'   => 'profile',
					'label' => __( 'Open your profile', 'zeko-ai' ),
					'url'   => $this->profile_url(),
				),
			),
			'rewards'   => array(
				array(
					'key'   => 'rewards',
					'label' => __( 'Check your rewards', 'zeko-ai' ),
					'url'   => $this->module_url( '/rewards/' ),
				),
			),
			'search'    => array(
				array(
					'key'   => 'search',
					'label' => __( 'Open AI Search', 'zeko-ai' ),
					'url'   => $this->module_url( '/ai-search/' ),
				),
			),
			'recommend' => array(
				array(
					'key'   => 'recommend',
					'label' => __( 'Open recommendations', 'zeko-ai' ),
					'url'   => $this->module_url( '/ai-recommendations/' ),
				),
			),
		);

		$set = (array) ( $actions[ $intent ] ?? array() );
		return apply_filters( 'zeko_ai_agent_actions', $set, $intent, $user_id );
	}

	/**
	 * Deterministic follow-up suggestions for a completed answer.
	 *
	 * @return string[]
	 * @param array $raw Source metadata from the chat raw payload.
	 */
	private function suggest_followups( array $raw ): array {
		$source      = (string) ( $raw['source'] ?? '' );
		$intent      = (string) ( $raw['intent'] ?? 'general' );
		$suggestions = array();

		if ( 'knowledge' === $source && $this->get_db() ) {
			$module = (string) ( $raw['module'] ?? '' );
			if ( '' !== $module ) {
				foreach ( $this->get_db()->get_knowledge_by_module( $module, (int) ( $raw['knowledge_id'] ?? 0 ), 3 ) as $row ) {
					$suggestions[] = (string) $row->question;
				}
			}
			if ( empty( $suggestions ) ) {
				$suggestions = $this->intent_prompts( $intent );
			}
		} elseif ( 'corpus' === $source ) {
			foreach ( array_slice( (array) ( $raw['corpus_titles'] ?? array() ), 0, 3 ) as $title ) {
				$title = trim( (string) $title );
				if ( '' !== $title ) {
					$suggestions[] = 'Tell me more about ' . $this->shorten_phrase( $title );
				}
			}
			if ( empty( $suggestions ) ) {
				$suggestions = $this->intent_prompts( $intent );
			}
		} elseif ( 'world' === $source ) {
			$suggestions = array( 'Tell me more', 'Find something about this on Zeko' );
		} elseif ( 'web' === $source ) {
			$suggestions = array( 'Find this on Zeko', 'Tell me more about the first result' );
		} elseif ( 'entity' === $source ) {
			$title = (string) ( $raw['entity']['title'] ?? '' );
			$label = mb_strtolower( (string) ( $raw['entity']['label'] ?? 'it' ) );
			if ( '' !== $title ) {
				$suggestions = array(
					sprintf( 'What are the reviews for %s?', $this->shorten_phrase( $title ) ),
					sprintf( 'Tell me more about %s', $this->shorten_phrase( $title ) ),
					sprintf( 'Show me another %s', $label ),
				);
				if ( 'hours' === (string) ( $raw['entity']['focus'] ?? '' ) ) {
					array_unshift(
						$suggestions,
						sprintf( 'Is %s open right now?', $this->shorten_phrase( $title ) )
					);
				}
			}
			if ( empty( $suggestions ) ) {
				$suggestions = $this->intent_prompts( $intent );
			}
		} else {
			$suggestions = $this->intent_prompts( $intent );
		}

		return array_values( array_slice( array_unique( array_filter( $suggestions ) ), 0, 3 ) );
	}

	/**
	 * Drop empty/duplicate chips and never re-suggest the exact question the
	 * member just asked ("How do I find a mentor?" must not echo itself back
	 * as a follow-up chip).
	 *
	 * @return string[]
	 * @param array  $suggestions Raw follow-up chips.
	 * @param string $asked The current user message.
	 */
	private function filter_suggestions( array $suggestions, string $asked ): array {
		$asked_low = mb_strtolower( trim( $asked ) );
		$out       = array();
		foreach ( $suggestions as $suggestion ) {
			$suggestion = trim( (string) $suggestion );
			if ( '' === $suggestion ) {
				continue;
			}
			if ( '' !== $asked_low && mb_strtolower( $suggestion ) === $asked_low ) {
				continue;
			}
			$out[] = $suggestion;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Preset follow-up questions per intent (used for fallback/suggestions).
	 *
	 * @return string[]
	 * @param string $intent Intent.
	 */
	private function intent_prompts( string $intent ): array {
		$prompts = array(
			'jobs'       => array( 'How do I apply for a job?', 'How do I post a job?' ),
			'learn'      => array( 'How do I get a certificate?', 'How do I earn rewards while learning?' ),
			'qa'         => array( 'How do I ask a question?', 'How do I answer a question?' ),
			'business'   => array( 'How do I find a business?', 'How do I add my business?', 'How do I book a service?', 'What are business hours?' ),
			'freelance'  => array( 'How do I hire a freelancer?', 'How do I get hired as a freelancer?' ),
			'shop'       => array( 'How do I buy a product?', 'How do I sell on Zeko?' ),
			'mentor'     => array( 'How do I find a mentor?', 'How do I book a session?' ),
			'love'       => array( 'How does dating work?', 'How do I verify my dating profile?' ),
			'wallet'     => array( 'How do I add money to my wallet?', 'How do I withdraw my earnings?' ),
			'profile'    => array( 'How do I verify my profile?', 'How do I edit my profile?' ),
			'rewards'    => array( 'How do I earn rewards?', 'How do I redeem my points?' ),
			'moderation' => array( 'How does content moderation work?', 'How do I report a problem?' ),
			'search'     => array( 'How do I search everything at once?', 'What can AI Search find?' ),
			'recommend'  => array( 'How do recommendations work?', 'Why am I seeing these recommendations?' ),
			'greeting'   => array( 'What is Zeko?', 'How do I find a job?' ),
		);

		return $prompts[ $intent ] ?? array( 'What is Zeko?', 'How do I talk to the AI assistant?' );
	}

	/**
	 * Shorten phrase.
	 *
	 * @param string $text Text.
	 */
	private function shorten_phrase( string $text ): string {
		$text = trim( $text );
		return mb_strlen( $text ) > 50 ? mb_substr( $text, 0, 50 ) . '…' : $text;
	}

	/**
	 * Resolve a follow-up turn against the previous user question. A short or
	 * explicitly referential turn ("what about it?", "tell me more") inherits
	 * the prior question so intent and retrieval stay on-topic.
	 *
	 * @param array  $messages Messages.
	 * @param string $user_text User text.
	 */
	private function resolve_query_text( array $messages, string $user_text ): string {
		if ( ! $this->is_follow_up( $user_text ) ) {
			return $user_text;
		}

		$previous = $this->previous_user_message( $messages );
		if ( '' === $previous ) {
			return $user_text;
		}

		// A clearly different topic is a fresh question, not a continuation.
		// "I want to find a date" after "what time does a business open?".
		// must not inherit the business context (that produced the wrong.
		// business card again). A follow-up that itself names a concrete.
		// topic ("how do I post a job?" after dating talk) starts fresh too;.
		// referential turns ("tell me more", "what about it?") stay.
		// general-intent and keep inheriting the context.
		$current_intent  = $this->detect_intent( $user_text );
		$previous_intent = $this->detect_intent( $previous );
		if ( 'general' !== $current_intent && $current_intent !== $previous_intent ) {
			return $user_text;
		}

		return mb_substr( trim( $previous . ' ' . $user_text ), 0, 400 );
	}

	/**
	 * True for turns that read as a continuation of a previous question.
	 *
	 * @param string $text Text.
	 */
	private function is_follow_up( string $text ): bool {
		if ( count( $this->parse_query_tokens( $text ) ) <= 3 ) {
			return true;
		}

		$low = mb_strtolower( (string) $text );
		foreach ( array( 'what about', 'how about', 'tell me more', 'more about', 'another', 'examples', 'details', 'same', 'such as', 'others', 'go on', 'that ' ) as $needle ) {
			if ( false !== mb_strpos( $low, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The user message before the current one ('' when there is none).
	 *
	 * @param array $messages Messages.
	 */
	private function previous_user_message( array $messages ): string {
		$users = array();
		foreach ( $messages as $message ) {
			if ( is_array( $message ) && 'user' === ( $message['role'] ?? '' ) ) {
				$content = trim( (string) ( $message['content'] ?? '' ) );
				if ( '' !== $content ) {
					$users[] = $content;
				}
			}
		}

		$count = count( $users );
		return $count >= 2 ? (string) $users[ $count - 2 ] : '';
	}

	/**
	 * Append remembered facts to a query for interest-driven intents so
	 * "recommend something" surfaces rows matching what the member told us
	 * they care about.
	 *
	 * @param string $query Query.
	 * @param int    $user_id User id.
	 * @param string $intent Intent.
	 */
	private function with_memory( string $query, int $user_id, string $intent ): string {
		if ( $user_id <= 0 || ! in_array( $intent, array( 'search', 'recommend', 'jobs', 'learn', 'freelance' ), true ) ) {
			return $query;
		}
		if ( ! function_exists( 'zeko_ai' ) || ! method_exists( zeko_ai(), 'get_memory' ) ) {
			return $query;
		}

		$memory = zeko_ai()->get_memory();
		$bits   = array();

		$keywords = $memory->keywords( $user_id );
		if ( '' !== $keywords ) {
			$bits[] = $keywords;
		}

		// Resume continuity: the last conversation topic is folded in too, so.
		// a fresh "recommend something" continues what the member last asked.
		$recap = $memory->latest_recap( $user_id );
		if ( '' !== $recap ) {
			$bits[] = $recap;
		}

		if ( empty( $bits ) ) {
			return $query;
		}

		return trim( $query . ' ' . implode( ' ', $bits ) );
	}

	/**
	 * True when a message is only a social opener ("hello", "hey there",
	 * "how are you?", "good morning") rather than a real question.
	 *
	 * Deliberately strict, because the cost of a false positive is a member's
	 * question being answered with a greeting. A message counts as small talk
	 * only when it is short AND made entirely of social words AND carries at
	 * least one opener. So "hello" and "how are you doing today?" match,
	 * while "hello, I need help with my portfolio" and "help me find a job"
	 * do not, and neither does anything long enough to be a real request.
	 *
	 * @param string $text Text.
	 */
	private function is_social_open( string $text ): bool {
		$low = mb_strtolower( trim( (string) $text ) );
		if ( '' === $low ) {
			return false;
		}

		// Normalize contractions before punctuation is dropped, so "what's up"
		// becomes "whats up" instead of leaking a stray "s" token.
		$low = strtr(
			$low,
			array(
				"what's" => 'whats',
				"how's"  => 'hows',
				"how're" => 'howre',
				"who's"  => 'whos',
				"i'm"    => 'im',
				"you're" => 'youre',
				"let's"  => 'lets',
			)
		);

		$tokens = preg_split( '/[^\p{L}\p{N}]+/u', $low );
		$tokens = array_values( array_filter( array_map( 'trim', (array) $tokens ) ) );
		if ( empty( $tokens ) || count( $tokens ) > 6 ) {
			return false;
		}

		$openers = array(
			'hi',
			'hello',
			'hey',
			'hiya',
			'heya',
			'howdy',
			'hola',
			'yo',
			'sup',
			'wassup',
			'greetings',
			'greeting',
			'morning',
			'afternoon',
			'evening',
			'how',
			'whats',
			'hows',
			'howre',
			'whos',
		);

		// Every other word allowed in a short opener: pronouns, question words
		// and the names the member may address the assistant by.
		$social = array(
			'hi',
			'hello',
			'hey',
			'hiya',
			'heya',
			'howdy',
			'hola',
			'yo',
			'sup',
			'wassup',
			'greetings',
			'greeting',
			'morning',
			'afternoon',
			'evening',
			'night',
			'today',
			'tonight',
			'good',
			'well',
			'hope',
			'feel',
			'feeling',
			'day',
			'am',
			'doing',
			'how',
			'are',
			'is',
			'was',
			'do',
			'does',
			'did',
			'going',
			'goes',
			'up',
			'whats',
			'hows',
			'howre',
			'whos',
			'what',
			'you',
			'your',
			'youve',
			'youre',
			'u',
			'im',
			'i',
			'm',
			'me',
			'mine',
			'my',
			'we',
			'it',
			'its',
			'this',
			'that',
			'there',
			'here',
			'all',
			'everything',
			'anything',
			'going',
			'been',
			'be',
			'and',
			'to',
			'too',
			'so',
			'just',
			'now',
			'alright',
			'ok',
			'okay',
			'fine',
			'yes',
			'no',
			'zeko',
			'ai',
			'assistant',
			'bot',
			'friend',
			'folks',
			'guys',
			'everyone',
			'name',
			'first',
			'let',
			'lets',
		);

		$has_opener = false;
		foreach ( $tokens as $token ) {
			$token = mb_strtolower( $token );
			if ( in_array( $token, $openers, true ) ) {
				$has_opener = true;
				continue;
			}
			if ( ! in_array( $token, $social, true ) ) {
				return false;
			}
		}

		return $has_opener;
	}

	/**
	 * The reply for a social opener: a short, personalized orientation plus
	 * concrete starting points, never a search result.
	 *
	 * @param int $user_id User id.
	 */
	private function greeting_reply( int $user_id ): string {
		$name = '';
		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$name = (string) ( '' !== (string) $user->first_name ? $user->first_name : $user->display_name );
				$name = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $name ) ) );
				if ( mb_strlen( $name ) > 40 ) {
					$name = mb_substr( $name, 0, 40 );
				}
			}
		}

		$hello = '' !== $name ? sprintf( 'Hi %s, ', $name ) : 'Hi there, ';

		return $hello . "I'm Zeko AI, your ecosystem assistant. I can help you navigate jobs, courses, Q&A, shopping, freelancing, mentoring, dating, wallet and rewards. Ask me about any of those, or tell me what you are trying to do and I'll point you to the right place.";
	}

	/**
	 * True when the message is asking about moderation/safety itself (those
	 * stay answerable even though they may trip the wordlist).
	 *
	 * @param string $text Text.
	 */
	private function is_moderation_question( string $text ): bool {
		$low = mb_strtolower( (string) $text );
		foreach ( array( 'report', 'moderat', 'guideline', 'policy', 'rules', 'safe' ) as $needle ) {
			if ( false !== mb_strpos( $low, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replace a reply that trips the offline moderation wordlist with a safe
	 * neutral response. Returns the original reply when it is clean.
	 *
	 * @param string $reply Reply.
	 */
	private function guard_reply( string $reply ): string {
		$verdict = $this->moderate( $reply );
		if ( 'flagged' !== $verdict['decision'] ) {
			return $reply;
		}

		return "I couldn't put together a safe answer for that just now. Ask me about jobs, courses, Q&A, shopping, freelancing, mentoring, dating, your wallet or rewards and I'll help.";
	}

	/**
	 * Optional semantic scoring seam for knowledge matching. Defaults to 0
	 * (no embeddings configured); a site can hook 'zeko_ai_knowledge_semantic_score'
	 * to plug in embeddings/similarity and return 0..1.
	 *
	 * @param string $text Text.
	 * @param object $candidate A knowledge row.
	 */
	private function semantic_score( string $text, $candidate ): float {
		$score = apply_filters( 'zeko_ai_knowledge_semantic_score', 0.0, $text, $candidate );
		return max( 0.0, min( 1.0, (float) $score ) );
	}

	/**
	 * Confidence in the chosen answer path, used to decide when to append a
	 * clarifying prompt and to let the front-end surface feedback controls.
	 * Knowledge hits are most reliable; intent/general fallbacks least.
	 *
	 * @param string $source Source.
	 * @param bool   $answered Answered.
	 */
	private function confidence_for( string $source, bool $answered ): float {
		switch ( $source ) {
			case 'knowledge':
				return 0.95;
			case 'entity':
				return 0.9;
			case 'personal':
				return 0.8;
			case 'corpus':
				return 0.75;
			case 'world':
				return 0.7;
			case 'web':
				return 0.6;
			case 'fallback':
				return $answered ? 0.55 : 0.3;
			default:
				return $answered ? 0.5 : 0.3;
		}
	}

	/**
	 * Single-turn text generation. The agent is chat-first, so writing tasks
	 * resolve through the same retrieval path as a normal turn.
	 *
	 * @param string $prompt Prompt.
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
	 * Moderate.
	 *
	 * @param string $text Text.
	 */
	public function moderate( string $text ): array {
		$text    = mb_strtolower( (string) $text );
		$reasons = array();
		foreach ( $this->flagged_words as $needle => $label ) {
			if ( false !== mb_strpos( $text, $needle ) ) {
				$reasons[] = $label;
			}
		}

		$reasons = array_values( array_unique( $reasons ) );

		return array(
			'decision' => $reasons ? 'flagged' : 'approved',
			'reasons'  => $reasons,
			'score'    => $reasons ? min( 0.99, 0.5 + 0.1 * count( $reasons ) ) : 0.0,
		);
	}

	/**
	 * Paraphrase-tolerant retrieval: exact normalized question first (fast
	 * repeat path), then candidates from FULLTEXT + LIKE, then a strict
	 * token-AND scan across every learned row as a final net. The strongest
	 * candidate by token overlap wins. The token-AND net always runs (even
	 * when a FULLTEXT index exists): InnoDB FULLTEXT indexes can silently go
	 * stale on some MySQL setups, and without the net that would leave short
	 * paraphrases ("can you tell me how to find a job?") unresolvable. Only
	 * returns a row when the match is strong enough, so weak hits still fall
	 * through to the corpus.
	 *
	 * @param string $text   Text.
	 * @param string $intent Detected intent.
	 */
	private function find_best_knowledge( string $text, string $intent = '' ) {
		if ( ! $this->get_db() ) {
			return null;
		}
		$db = $this->get_db();

		$scope = $this->knowledge_module_scope( $intent );

		$row = $db->find_knowledge( $text );
		if ( $row && $this->knowledge_in_scope( $row, $scope ) ) {
			return $row;
		}

		$candidates = array_merge(
			$db->search_knowledge_fulltext( $text, 10 ),
			$db->search_knowledge( $text, 10 )
		);

		$text_tokens = $this->parse_query_tokens( $text );

		// Final net: token-AND scan across every learned row. Strict (every.
		// meaningful query word must appear in question or answer), so it is.
		// safe to run unconditionally as a safety net for stale FULLTEXT.
		if ( count( $text_tokens ) >= 2 ) {
			$candidates = array_merge( $candidates, $db->search_knowledge_tokens( $text_tokens, 30 ) );
		}

		$best        = null;
		$best_score  = self::SIMILARITY_MIN;
		$best_q      = -1.0;
		$best_weight = -1.0;
		$seen        = array();

		foreach ( $candidates as $candidate ) {
			$id = (int) $candidate->id;
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			// A module question must not be answered by another module's
			// answer. "Where can I find a partner" once came back with a course
			// certificate, because a love question and a learn answer shared
			// the word "profile". Rows scoped to the intent, plus the
			// cross-cutting "general" ones, are the only candidates.
			if ( ! $this->knowledge_in_scope( $candidate, $scope ) ) {
				continue;
			}

			$question_score = $this->token_similarity( $text_tokens, $candidate->question );
			$score          = $question_score;

			// The answer counts as evidence, but only in two narrow cases,
			// because a broad answer matches broad words by accident.
			//
			// First, the row has to belong to the module being asked about (or
			// the question is not about any one module). A cross-cutting row
			// like "Zeko is a community platform that brings jobs, courses,
			// dating…" contains most of the ecosystem's vocabulary, so token
			// overlap with it proves nothing.
			//
			// Second, EVERY meaningful query word has to be there. Half a match
			// is not evidence: "where can I find a partner" shares "Zeko" with
			// any row that mentions the platform, and that used to be enough to
			// answer a dating question with a course certificate.
			$module     = (string) ( $candidate->module ?? '' );
			$own_module = '' !== $module && 'general' !== $module;
			$may_answer = null === $scope || ( $own_module && $module === $intent );
			if ( $may_answer && count( $text_tokens ) >= 2 ) {
				$coverage = $this->token_coverage( $text_tokens, (string) $candidate->answer );
				if ( $coverage >= 1.0 && $coverage > $score ) {
					$score = $coverage;
				}
			}

			// A row that belongs to the module outranks a cross-cutting row
			// that asked a more similar question.
			if ( null !== $scope && ! $own_module ) {
				$score *= 0.9;
			}

			// Ties go to the closer question, then to the row an admin weighted
			// highest, then to the first candidate. Without that last part the
			// answer depended on which row happened to be scanned last, and two
			// equally good dating answers swapped places between runs.
			$weight = (float) ( $candidate->weight ?? 0.0 );
			if ( $score > $best_score
				|| ( $score === $best_score && $question_score > $best_q )
				|| ( $score === $best_score && $question_score === $best_q && $weight > $best_weight ) ) {
				$best_score  = $score;
				$best_q      = $question_score;
				$best_weight = $weight;
				$best        = $candidate;
			}
		}

		return $best;
	}

	/**
	 * Lowercase, stopword-free, lightly stemmed tokens of a question. A shared
	 * stopword list makes paraphrase matching tolerant of filler words ("how do
	 * I", "can you", "please tell me"), and hub verbs that carry no topic
	 * ("find", "need", "show") are stopwords too: they used to sink an
	 * otherwise perfect match, because every one of them had to be found in the
	 * stored row. The platform name is deliberately NOT a stopword here, unlike
	 * in corpus ranking: stored answers really do say "On Zeko Dating…", so it
	 * is evidence here, and dropping it left a bare "zeko" answerable only by
	 * Wikipedia's disambiguation page.
	 *
	 * @return array<int,string>
	 * @param string $text Text.
	 */
	private function parse_query_tokens( string $text ): array {
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
			// Hub verbs and hedges: no topic, and they were every bit as
			// disqualifying as "the" when a stored answer was scored.
			'find',
			'search',
			'show',
			'get',
			'need',
			'want',
			'give',
			'list',
			'know',
			'check',
			'look',
			'looking',
			'use',
			'using',
			'really',
			'something',
			'someone',
			'anything',
			'stuff',
		);

		$tokens = preg_split( '/[^a-z0-9]+/i', mb_strtolower( (string) $text ) );
		$tokens = array_values( array_filter( array_map( 'trim', (array) $tokens ) ) );

		$out = array();
		foreach ( $tokens as $token ) {
			$token = mb_strtolower( $token );
			if ( mb_strlen( $token ) < 3 || in_array( $token, $stopwords, true ) ) {
				continue;
			}
			$out[] = $this->stem_token( $token );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Deliberately crude suffix stripping, applied to both sides of a
	 * comparison so that a member's phrasing and a stored answer can meet in
	 * the middle: "date"/"dating", "job"/"jobs", "business"/"businesses",
	 * "work"/"working". It is not a linguistics project, and it must stay
	 * prefix-shaped, because the database-side token scan matches with
	 * LIKE '%token%' and a stem that is not a prefix of the original word
	 * would stop the row from being found at all.
	 *
	 * @param string $token Token.
	 */
	private function stem_token( string $token ): string {
		$length = mb_strlen( $token );
		if ( $length > 5 && mb_substr( $token, -3 ) === 'ing' ) {
			$token = mb_substr( $token, 0, -3 );
		} elseif ( $length > 4 && mb_substr( $token, -2 ) === 'ed' ) {
			$token = mb_substr( $token, 0, -2 );
		} elseif ( $length > 4 && mb_substr( $token, -2 ) === 'es' ) {
			$token = mb_substr( $token, 0, -2 );
		} elseif ( $length > 3 && mb_substr( $token, -1 ) === 's' ) {
			$token = mb_substr( $token, 0, -1 );
		}
		if ( mb_strlen( $token ) > 3 && mb_substr( $token, -1 ) === 'e' ) {
			$token = mb_substr( $token, 0, -1 );
		}

		return $token;
	}

	/**
	 * Which knowledge modules may answer an intent. Seeded and learned rows
	 * carry the module they belong to, so a question about dating is answered
	 * by dating (or cross-cutting) knowledge and nothing else. Intents that are
	 * not modules of their own - a greeting, a plain "search for", or a general
	 * question - impose no scope, because they are not asking about one module.
	 *
	 * @param string $intent Detected intent.
	 * @return array<int,string>|null Null means "any module may answer".
	 */
	private function knowledge_module_scope( string $intent ): ?array {
		$modules = array( 'business', 'jobs', 'learn', 'freelance', 'wallet', 'shop', 'qa', 'love', 'mentor', 'rewards', 'moderation' );
		if ( ! in_array( $intent, $modules, true ) ) {
			return null;
		}

		// 'general' is the cross-cutting bucket: blank, "general", or the
		// legacy value the seeder writes for rows that belong to no module.
		return array( $intent, 'general', '' );
	}

	/**
	 * Whether a knowledge row is allowed to answer within a scope.
	 *
	 * @param object             $row   Knowledge row.
	 * @param array<int,string>|null $scope Scope from knowledge_module_scope().
	 */
	private function knowledge_in_scope( $row, ?array $scope ): bool {
		if ( null === $scope ) {
			return true;
		}

		$module = (string) ( $row->module ?? '' );

		return in_array( '' === $module ? '' : $module, $scope, true );
	}

	/**
	 * Jaccard-style overlap between the query tokens and a candidate question.
	 * Returns 0..1 (0.4+ means roughly half of the meaningful words matched).
	 *
	 * @param array  $query_tokens Query tokens.
	 * @param string $question Question.
	 */
	private function token_similarity( array $query_tokens, string $question ): float {
		$question_tokens = $this->parse_query_tokens( $question );
		if ( empty( $query_tokens ) || empty( $question_tokens ) ) {
			return 0.0;
		}

		$union        = array_unique( array_merge( $query_tokens, $question_tokens ) );
		$intersection = array_intersect( $query_tokens, $question_tokens );

		return count( $intersection ) / count( $union );
	}

	/**
	 * Share of the query's own tokens that appear anywhere in a row's text.
	 * Unlike Jaccard this is not diluted by how long the row is, so it answers
	 * a different question: not "is this the same question" but "does this row
	 * actually contain what the member asked for".
	 *
	 * @param array  $query_tokens Query tokens.
	 * @param string $text         Question or answer.
	 */
	private function token_coverage( array $query_tokens, string $text ): float {
		$haystack = $this->parse_query_tokens( $text );
		if ( empty( $query_tokens ) || empty( $haystack ) ) {
			return 0.0;
		}

		return count( array_intersect( $query_tokens, $haystack ) ) / count( $query_tokens );
	}

	/**
	 * Affirmation auto-learning: when the current turn is an affirmation
	 * ("thanks", "that helped") and the previous assistant message was a
	 * corpus answer, store the user's original question as learned knowledge.
	 * Quality guards keep junk out of the knowledge base: only real,
	 * module-bearing questions of at least two meaningful words are learned,
	 * greeting turns are skipped, and logged-in users are capped at a few
	 * learned rows per day (filterable via
	 * 'zeko_ai_agent_auto_learn_daily_cap', default 5; 0 = unlimited).
	 *
	 * @param array  $messages Messages.
	 * @param string $user_text User text.
	 * @param int    $user_id User id.
	 */
	private function maybe_auto_learn( array $messages, string $user_text, int $user_id ): void {
		if ( ! $this->is_affirmation( $user_text ) ) {
			return;
		}

		$history = array_values( $messages );
		for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
			$msg = $history[ $i ];
			if ( ! is_array( $msg ) || 'assistant' !== ( $msg['role'] ?? '' ) ) {
				continue;
			}
			$content = (string) ( $msg['content'] ?? '' );
			if ( false === strpos( $content, self::CORPUS_MARKER ) ) {
				continue;
			}
			$question = $this->previous_user_question( $history, $i );
			if ( '' === $question ) {
				return;
			}
			if ( count( $this->parse_query_tokens( $question ) ) < 2 ) {
				return;
			}
			if ( 'greeting' === $this->detect_intent( $question ) ) {
				return;
			}
			if ( $this->get_db()->find_knowledge( $question ) ) {
				return;
			}
			if ( $user_id > 0 && $this->auto_learn_capped( $user_id ) ) {
				return;
			}
			$this->get_db()->insert_knowledge(
				array(
					'user_id'  => $user_id,
					'question' => $question,
					'answer'   => mb_substr( $content, 0, 1000 ),
					'module'   => 'learned',
					'source'   => 'auto',
					'weight'   => 1,
				)
			);
			return;
		}
	}

	/**
	 * True for short, clearly affirmative turns. Gratitude and strong
	 * positives always count ("thanks", "that helped", "awesome", "works");
	 * mild positives ("good", "nice", "cool", "great") only count when they
	 * clearly refer to the previous answer ("that's good", "this is nice").
	 * Requests ("help") and bare one-word compliments never trigger learning.
	 *
	 * @param string $text Text.
	 */
	private function is_affirmation( string $text ): bool {
		$tokens = $this->parse_query_tokens( $text );
		if ( count( $tokens ) > 5 ) {
			return false;
		}
		$plain = mb_strtolower( trim( $text ) );
		if ( preg_match( '/(thank|appreciate|awesome|perfect|helped|helpful|useful|solved|fixed|worked|works\b)/', $plain ) ) {
			return true;
		}
		if ( preg_match( '/\b(that|this|it)\b/', $plain ) && preg_match( '/(good|nice|cool|great)\b/', $plain ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Per-user daily cap on auto-learned rows. Enforced only for real
	 * (logged-in, non-anonymous) chat sessions.
	 *
	 * @param int $user_id User id.
	 */
	private function auto_learn_capped( int $user_id ): bool {
		$cap = (int) apply_filters( 'zeko_ai_agent_auto_learn_daily_cap', 5 );
		if ( $cap <= 0 ) {
			return false;
		}
		$day   = gmdate( 'Y-m-d' );
		$stamp = (string) get_user_meta( $user_id, 'zeko_ai_auto_learn_day', true );
		$count = (int) get_user_meta( $user_id, 'zeko_ai_auto_learn_count', true );
		if ( $stamp !== $day ) {
			$count = 0;
		}
		if ( $count >= $cap ) {
			return true;
		}
		update_user_meta( $user_id, 'zeko_ai_auto_learn_day', $day );
		update_user_meta( $user_id, 'zeko_ai_auto_learn_count', $count + 1 );
		return false;
	}

	/**
	 * The last user question before an assistant corpus answer at $index.
	 *
	 * @param array $history History.
	 * @param int   $index Index.
	 */
	private function previous_user_question( array $history, int $index ): string {
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			$msg = $history[ $i ];
			if ( is_array( $msg ) && 'user' === ( $msg['role'] ?? '' ) ) {
				$question = trim( (string) ( $msg['content'] ?? '' ) );
				if ( '' !== $question ) {
					return mb_substr( $question, 0, 500 );
				}
			}
		}
		return '';
	}

	/**
	 * Query the shared ecosystem corpus (same sources as AI Search).
	 * Results are cached per query (short TTL) so repeated or similar turns
	 * never re-run the same module DB queries.
	 *
	 * @return array<int,array{type:string,label:string,icon:string,id:int,title:string,excerpt:string,url:string,score:float}>
	 * @param string $text Text.
	 * @param int    $limit Limit.
	 */
	/**
	 * Which corpus source type owns an intent. A module plugin registers its
	 * source under its own type slug (zeko-jobs -> "job", zeko-learn ->
	 * "course"), so an intent that belongs to a module also names the one
	 * source type a browse answer is allowed to come from.
	 *
	 * @return array<string,string>
	 */
	private function module_source_types(): array {
		return array(
			'business'  => 'business',
			'jobs'      => 'job',
			'learn'     => 'course',
			'love'      => 'profile',
			'mentor'    => 'mentor',
			'freelance' => 'project',
			'shop'      => 'product',
			'qa'        => 'question',
		);
	}

	/**
	 * The words that name a module's own content, so a query that consists of
	 * nothing else really is "show me jobs" rather than a failed search. Kept
	 * in step with the intent keywords so both agree on what a module is.
	 *
	 * @return array<string,array<int,string>>
	 */
	private function module_nouns(): array {
		return array(
			'jobs'      => array( 'job', 'jobs' ),
			'learn'     => array( 'course', 'courses', 'class', 'classes', 'training', 'tutorial' ),
			'business'  => array( 'business', 'businesses', 'listing', 'listings', 'store', 'stores' ),
			'love'      => array( 'dating', 'date', 'dates', 'profile', 'profiles', 'match', 'matches' ),
			'mentor'    => array( 'mentor', 'mentors', 'coach', 'coaches', 'session', 'sessions' ),
			'freelance' => array( 'project', 'projects', 'gig', 'gigs', 'freelance' ),
			'shop'      => array( 'product', 'products', 'item', 'items' ),
			'qa'        => array( 'question', 'questions', 'answer', 'answers' ),
		);
	}

	/**
	 * Whether a member who got nothing back was actually asking to browse a
	 * module. "Show me jobs" and "any mentors?" name a module and nothing else,
	 * so the honest answer is that module's latest content. "I need a bank"
	 * names something specific, and listing every business instead would be
	 * worse than saying there is no match, so that keeps the no-match answer.
	 *
	 * @param string $text   User text.
	 * @param string $intent Detected intent.
	 */
	private function wants_module_browse( string $text, string $intent ): bool {
		$nouns = $this->module_nouns();
		if ( ! isset( $nouns[ $intent ] ) ) {
			return false;
		}

		$tokens = Zeko_AI_Search::tokens( $text );
		if ( ! $tokens ) {
			return false;
		}

		// Anything left over that is not filler and not the module's own noun
		// is a real constraint, and a real constraint must be honoured.
		$left = array_diff( $tokens, Zeko_AI_Search::GENERIC_TERMS, $nouns[ $intent ] );

		return array() === array_values( $left );
	}

	/**
	 * Search the corpus for an answer, falling back to the exact source
	 * callbacks, then to individual tokens, then - only when the member named
	 * no specific thing and asked for a module by its own noun - to that
	 * module's listing.
	 *
	 * @param string $text   Text.
	 * @param int    $limit  Limit.
	 * @param string $intent Detected intent.
	 */
	private function search_corpus( string $text, int $limit, string $intent = '' ): array {
		$ttl = $this->cache_ttl();
		$key = 'zeko_ai_agent_corpus_' . md5( $text . '|' . $limit . '|' . $intent );

		if ( $ttl > 0 ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		if ( ! class_exists( 'Zeko_AI' ) ) {
			return array();
		}
		$search = Zeko_AI::instance()->get_search();
		if ( ! $search ) {
			return array();
		}

		$results = $search->search(
			$text,
			array(
				'per_type' => 3,
				'limit'    => $limit,
			)
		);

		// Relevance floor. A source that returns rows for a term it matched in
		// a field the reply never shows (or a source that simply returns its
		// whole set) would otherwise surface as an answer, and the chat would
		// confidently present a record that has nothing to do with the
		// question. search_tokens() already applies the same rule, so both.
		// paths now agree on what counts as a match. Rows tagged browse=true
		// are exempt: see the browse fallback below for why that is not a hole.
		$results = array_values(
			array_filter(
				$results,
				static function ( array $result ): bool {
					return ! empty( $result['browse'] ) || (float) ( $result['score'] ?? 0.0 ) > 0.0;
				}
			)
		);

		// Paraphrase fallback: when the verbatim phrase misses the corpus, run.
		// each meaningful token across the same sources so requests like.
		// "I'd like to learn web development" still surface the right rows.
		if ( count( $results ) < 2 && method_exists( $search, 'search_tokens' ) ) {
			$token_results = $search->search_tokens(
				Zeko_AI_Search::tokens( $text ),
				array(
					'per_type' => 3,
					'limit'    => $limit,
				)
			);
			if ( count( $token_results ) > count( $results ) ) {
				$results = $token_results;
			}
		}

		// Browse fallback: "show me jobs" leaves the search with nothing to work
		// with, because the module's own noun is all it says. That is a request
		// to see the module, so show it - and only that one module.
		if ( ! $results && '' !== $intent && method_exists( $search, 'browse' ) && $this->wants_module_browse( $text, $intent ) ) {
			$types = $this->module_source_types();
			$rows  = $search->browse(
				isset( $types[ $intent ] ) ? array( $types[ $intent ] ) : array(),
				array(
					'per_type' => 3,
					'limit'    => $limit,
				)
			);
			if ( $rows ) {
				$results = $rows;
			}
		}

		if ( $ttl > 0 ) {
			set_transient( $key, $results, $ttl );
		}

		return $results;
	}

	/**
	 * Format corpus.
	 *
	 * @param array $results Results.
	 */
	private function format_corpus( array $results ): string {
		$lines = array( 'I found a few things in the Zeko ecosystem for you:' );
		foreach ( $results as $result ) {
			$title = (string) ( $result['title'] ?? '' );
			if ( '' === $title ) {
				continue;
			}
			$label = (string) ( $result['label'] ?? '' );
			$url   = (string) ( $result['url'] ?? '' );
			$line  = '• ' . ( '' !== $label ? '[' . $label . '] ' : '' ) . $title;
			if ( '' !== $url ) {
				$line .= ' — ' . $url;
			}
			$lines[] = $line;
		}
		$lines[] = 'Want me to dig into any of these? Just ask.';
		return implode( "\n", $lines );
	}

	/**
	 * Ask every module's entity resolver whether the query names a specific
	 * item, and keep the strongest confident match. Returns an empty array
	 * when nothing on Zeko is confidently named.
	 *
	 * @return array<int,mixed>
	 * @param string $query Query.
	 */
	private function resolve_entity( string $query ): array {
		$best      = array();
		$bestscore = 0.0;

		foreach ( (array) apply_filters( 'zeko_ai_entity_resolvers', array() ) as $resolver ) {
			if ( ! is_callable( $resolver ) ) {
				continue;
			}
			$card = call_user_func( $resolver, $query );
			if ( ! is_array( $card ) || empty( $card['title'] ) ) {
				continue;
			}
			$fit = (float) ( $card['score'] ?? 0.0 );
			if ( $fit > $bestscore ) {
				$bestscore = $fit;
				$best      = $card;
			}
		}

		return $bestscore >= self::ENTITY_SCORE_MIN ? $best : array();
	}

	/**
	 * Optionally enrich an entity card with a short web tell-me-more block.
	 * Only runs when web search is enabled, always fail-soft and cached by
	 * the web client, and never substitutes for the on-site record.
	 *
	 * @return array<int,array{title:string,excerpt:string,url:string}>
	 * @param array $entity Entity.
	 */
	private function augment_entity_web( array $entity ): array {
		if ( empty( $entity['web_terms'] ) || ! $this->web_search()->enabled() ) {
			return array();
		}
		$term = (string) reset( $entity['web_terms'] );
		if ( '' === trim( $term ) ) {
			return array();
		}
		try {
			return (array) $this->web_search()->search( $term, 3 );
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Render a rich entity detail card as chat markdown: title line, quick
	 * subtitle, badges, facts, sections, a call-to-action list and (when the
	 * web augmentation found something) a short web note.
	 *
	 * @param array $card Card.
	 */
	private function format_entity_card( array $card ): string {
		$title = trim( (string) ( $card['title'] ?? '' ) );
		if ( '' === $title ) {
			return '';
		}

		$label = (string) ( $card['label'] ?? _x( 'item', 'entity type label', 'zeko-ai' ) );

		$lines   = array();
		$lines[] = '**' . $title . '**';

		$subtitle = trim( (string) ( $card['subtitle'] ?? '' ) );
		$badges   = (array) ( $card['badges'] ?? array() );
		if ( '' !== $subtitle || $badges ) {
			$bits = array();
			if ( '' !== $subtitle ) {
				$bits[] = $subtitle;
			}
			foreach ( $badges as $badge ) {
				$badge = trim( (string) $badge );
				if ( '' !== $badge ) {
					$bits[] = '`' . $badge . '`';
				}
			}
			if ( $bits ) {
				$lines[] = implode( ' • ', array_unique( $bits ) );
			}
		}

		$lines[] = '';
		$lines[] = sprintf( 'Here is what I found for this %s:', mb_strtolower( $label ) );

		$focus = (string) ( $card['focus'] ?? '' );
		if ( 'hours' === $focus ) {
			foreach ( (array) ( $card['facts'] ?? array() ) as $fact ) {
				if ( 'Opening hours' === (string) ( $fact['label'] ?? '' ) ) {
					$lines[] = '';
					/* translators: %s: opening hours text for the business */
					$lines[] = sprintf( __( '**Opening hours:** %s', 'zeko-ai' ), (string) ( $fact['value'] ?? '' ) );
				}
			}
		}

		$lines[] = '';
		foreach ( (array) ( $card['facts'] ?? array() ) as $fact ) {
			$label_f = (string) ( $fact['label'] ?? '' );
			$value   = (string) ( $fact['value'] ?? '' );
			if ( '' === $label_f || '' === $value ) {
				continue;
			}
			/* translators: 1: fact label. 2: linked fact value */
			$lines[] = sprintf( __( '• **%1$s:** %2$s', 'zeko-ai' ), $label_f, $this->entity_linkify( $value ) );
		}

		$description = trim( (string) ( $card['description'] ?? '' ) );
		if ( '' !== $description ) {
			$lines[] = '';
			$lines[] = $description;
		}

		foreach ( (array) ( $card['sections'] ?? array() ) as $section ) {
			$heading       = trim( (string) ( $section['heading'] ?? '' ) );
			$section_lines = (array) ( $section['lines'] ?? array() );
			if ( '' === $heading || empty( $section_lines ) ) {
				continue;
			}
			$lines[] = '';
			$lines[] = '**' . $heading . '**';
			$lines[] = '';
			foreach ( array_slice( $section_lines, 0, 8 ) as $section_line ) {
				$section_line = trim( (string) $section_line );
				if ( '' !== $section_line ) {
					$lines[] = '- ' . $this->entity_linkify( $section_line );
				}
			}
		}

		$actions = array_values( array_filter( (array) ( $card['actions'] ?? array() ) ) );
		if ( $actions ) {
			$lines[] = '';
			$lines[] = __( '**Things you can do from here:**', 'zeko-ai' );
			$lines[] = '';
			foreach ( array_slice( $actions, 0, 8 ) as $action ) {
				$label_a = (string) ( $action['label'] ?? '' );
				$url_a   = (string) ( $action['url'] ?? '' );
				if ( '' === $label_a ) {
					continue;
				}
				$lines[] = '- ' . ( '' !== $url_a ? '[' . $label_a . '](' . $url_a . ')' : $label_a );
			}
		}

		$web = (array) ( $card['web'] ?? array() );
		if ( $web ) {
			$lines[] = '';
			$lines[] = __( '**A quick look beyond Zeko:**', 'zeko-ai' );
			$lines[] = '';
			foreach ( array_slice( $web, 0, 3 ) as $result ) {
				$wtitle   = trim( (string) ( $result['title'] ?? ( $result['url'] ?? '' ) ) );
				$wurl     = trim( (string) ( $result['url'] ?? '' ) );
				$wexcerpt = trim( (string) ( $result['excerpt'] ?? '' ) );
				if ( '' === $wtitle && '' === $wurl ) {
					continue;
				}
				$line    = '- ' . ( '' !== $wurl ? '[' . $wtitle . '](' . $wurl . ')' : $wtitle );
				$lines[] = $line;
				if ( '' !== $wexcerpt ) {
					$lines[] = '  ' . $this->shorten_phrase( $wexcerpt );
				}
			}
			$lines[] = '';
			$lines[] = __( 'These come from an outside search engine, so please verify them before relying on them.', 'zeko-ai' );
		}

		$lines[] = '';
		/* translators: %s: name of the suggested page */
		$lines[] = sprintf( __( 'Anything else — open the %s page to explore more, or pick an action above.', 'zeko-ai' ), $label );

		return implode( "\n", $lines );
	}

	/**
	 * Turn common fact values (websites, emails, phones) into tapable links
	 * while leaving free text untouched.
	 *
	 * @param string $value Value.
	 */
	private function entity_linkify( string $value ): string {
		$trimmed = trim( $value );

		if ( 0 === strpos( $trimmed, 'http://' ) || 0 === strpos( $trimmed, 'https://' ) ) {
			return '[' . $this->shorten_phrase( $trimmed ) . '](' . $trimmed . ')';
		}
		if ( false !== mb_strpos( $trimmed, '@' ) && is_email( $trimmed ) ) {
			return '[' . $trimmed . '](mailto:' . $trimmed . ')';
		}
		if ( '' !== preg_replace( '/[^\d+]/', '', $trimmed ) && preg_match( '/^[+()\d][\d ().-]{6,19}$/', $trimmed ) ) {
			$tel = preg_replace( '/[^\d+]/', '', $trimmed );
			return '[' . $trimmed . '](tel:' . $tel . ')';
		}

		return $value;
	}

	/**
	 * Build a web-search answer with short excerpts and clickable source
	 * links, plus a gentle reminder that the results come from an external
	 * engine.
	 * appended to an existing on-site answer instead of being the sole
	 * response.
	 *
	 * @param array $results * @param bool                                                     $supplement When true the block is.
	 * @param bool  $supplement Supplement.
	 */
	private function format_web( array $results, bool $supplement = false ): string {
		$lines = array(
			$supplement
							? 'Also from around the web:'
							: 'That is not something I found on Zeko, so I checked the web:',
		);
		foreach ( $results as $result ) {
			$title   = trim( (string) ( $result['title'] ?? '' ) );
			$excerpt = trim( (string) ( $result['excerpt'] ?? '' ) );
			$url     = trim( (string) ( $result['url'] ?? '' ) );
			if ( '' === $title && '' === $url ) {
				continue;
			}
			$line = '• ' . ( '' !== $title ? $title : $url );
			if ( '' !== $url && $url !== $title ) {
				// Markdown link so the chat UI renders tappable sources.
				$line .= ' — [' . $url . '](' . $url . ')';
			}
			$lines[] = $line;
			if ( '' !== $excerpt ) {
				$lines[] = '  ' . $this->shorten_phrase( $excerpt );
			}
		}
		$lines[] = $supplement
			? 'These extras are from an outside search engine — please verify the sources.'
			: 'These come from an outside search engine, so please verify the source before relying on them.';
		return implode( "\n", $lines );
	}

	/**
	 * Lazily-built (and cached) web-search client wired to these settings.
	 */
	private function web_search(): Zeko_AI_Web_Search {
		if ( null === $this->web ) {
			$this->web = new Zeko_AI_Web_Search( $this->settings );
		}
		return $this->web;
	}

	/**
	 * Whether the member is asking the ecosystem for something rather than
	 * asking a general-knowledge question. "I need a bank" is a request for a
	 * listing, not a request for an encyclopedia entry, and Wikipedia's search
	 * API fuzzy-matches such a sentence to something unrelated (it once
	 * answered "I need a bank" with a song). When the corpus has no match the
	 * module's own fallback is a far better answer. Definitional questions
	 * ("what is a bank?") are left alone, so world knowledge still works where
	 * it is genuinely useful.
	 *
	 * @param string $text   User text.
	 * @param string $intent Detected intent.
	 */
	private function is_ecosystem_request( string $text, string $intent ): bool {
		if ( 'general' === $intent ) {
			return false;
		}

		$text = mb_strtolower( trim( wp_strip_all_tags( (string) $text ) ) );

		$pattern = '/\b(i need|i want|find me|find a|show me|list|search for|looking for|get me|book|order|buy|recommend)\b/';

		return 1 === preg_match( $pattern, $text );
	}

	/**
	 * Optional world-knowledge lookup. Free, keyless and off by default;
	 * every failure degrades silently to a plain fallback answer. Successes
	 * and misses are cached in transients so a busy site never hammers the
	 * Wikipedia endpoint for the same question.
	 *
	 * @param string $text Text.
	 */
	private function fetch_world_knowledge( string $text ): string {
		$title = $this->wikipedia_title( $text );
		if ( '' === $title ) {
			return '';
		}

		$ttl = $this->cache_ttl();
		$key = 'zeko_ai_agent_wiki_' . md5( mb_strtolower( $title ) );

		if ( $ttl > 0 ) {
			$cached = get_transient( $key );
			if ( false !== $cached ) {
				return is_string( $cached ) ? $cached : '';
			}
		}

		$request = apply_filters( 'zeko_ai_agent_wikipedia_request', 'wp_remote_get' );
		if ( ! is_callable( $request ) ) {
			return '';
		}

		// The REST summary endpoint matches the exact page title ("Mark.
		// Zuckerberg"), so title-case the guess first. A genuine 404 then.
		// resolves the real title through the search API before giving up.
		$summary = $this->fetch_wiki_summary( $request, $this->title_case( $title ) );
		if ( '' === $summary && 404 === $this->last_wiki_status ) {
			$resolved = $this->wikipedia_search_title( $request, $this->title_case( $title ) );
			if ( '' !== $resolved ) {
				$summary = $this->fetch_wiki_summary( $request, $resolved );
			}
		}

		if ( '' === $summary ) {
			$this->cache_wiki_miss( $key, $ttl );
			return '';
		}

		if ( $ttl > 0 ) {
			set_transient( $key, $summary, $ttl );
		}

		return $summary;
	}

	/**
	 * Last wiki status.
	 *
	 * @var int Last wiki status.
	 */
	private int $last_wiki_status = 0;

	/**
	 * Fetch and format a Wikipedia REST summary for one page title. Failures
	 * (offline, non-200, no extract) return '' and leave $last_wiki_status
	 * with the HTTP code so callers can decide whether to retry.
	 *
	 * @return string
	 * @param callable $request * @param string   $title.
	 * @param string   $title Title.
	 */
	private function fetch_wiki_summary( callable $request, string $title ): string {
		$this->last_wiki_status = 0;
		$url                    = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode( $title );

		try {
			$response = call_user_func( $request, $url );
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code                   = (int) wp_remote_retrieve_response_code( $response );
		$this->last_wiki_status = $code;
		if ( 200 !== $code ) {
			return '';
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['extract'] ) ) {
			return '';
		}

		$name = isset( $body['title'] ) ? (string) $body['title'] : $title;
		$more = (string) ( $body['content_urls']['desktop']['page'] ?? ( $body['content_urls']['mobile']['page'] ?? '' ) );

		return $name . ': ' . wp_strip_all_tags( (string) $body['extract'] ) . ( '' !== $more ? "\nMore: " . $more : '' );
	}

	/**
	 * Resolve the canonical Wikipedia title for a guess via opensearch.
	 * Returns '' when nothing is found or the lookup fails.
	 *
	 * @return string
	 * @param callable $request * @param string   $title.
	 * @param string   $title Title.
	 */
	private function wikipedia_search_title( callable $request, string $title ): string {
		$url = 'https://en.wikipedia.org/w/api.php?action=opensearch&search=' . rawurlencode( $title ) . '&limit=1&format=json';

		try {
			$response = call_user_func( $request, $url );
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body[1][0] ) ) {
			return '';
		}

		return (string) $body[1][0];
	}

	/**
	 * Title-case a guess so Wikipedia's case-sensitive summary endpoint can
	 * find it ("mark zuckerberg" -> "Mark Zuckerberg").
	 *
	 * @param string $text Text.
	 */
	private function title_case( string $text ): string {
		return (string) preg_replace_callback(
			'/(?<=\A|\s)\p{L}/u',
			static function ( array $m ) {
				return mb_strtoupper( $m[0] );
			},
			mb_strtolower( trim( $text ) )
		);
	}

	/**
	 * Cache a failed Wikipedia lookup as a short-lived negative result so a
	 * page that does not exist is not re-fetched on every identical turn.
	 *
	 * @param string $key Key.
	 * @param int    $ttl Ttl.
	 */
	private function cache_wiki_miss( string $key, int $ttl ): void {
		if ( $ttl <= 0 ) {
			return;
		}
		set_transient( $key, '', $ttl );
	}

	/**
	 * Result cache lifetime in seconds (0 disables caching). Filterable so
	 * tests and admins can tune or disable it.
	 */
	private function cache_ttl(): int {
		return max( 0, (int) apply_filters( 'zeko_ai_agent_cache_ttl', 300 ) );
	}

	/**
	 * Best-effort title extraction: drop leading question words, trailing
	 * question marks, then cap length.
	 *
	 * @param string $text Text.
	 */
	private function wikipedia_title( string $text ): string {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		$text = preg_replace( '/^((what|who|when|where|how|why|is|are|was|were|do|does|tell me about|explain|define)\s+)+/i', '', $text );
		$text = preg_replace( '/[?]+$/', '', trim( $text ) );
		return mb_substr( $text, 0, 120 );
	}

	/**
	 * Detect a question's topic intent. Intents are scored instead of matched
	 * first-come-first-served so shared qualifier words cannot override the
	 * real topic: in "connect me with a career mentor", "career" is a jobs
	 * qualifier but "mentor" is the subject, so mentor wins.
	 * Scoring:
	 * - each matched keyword contributes weight; short generic words
	 * ("job", "find", "date", "help") cap at 2, longer words count by
	 * length ("mentor", "career" weigh more than "job"), and multi-word
	 * phrases ("career advice", "book a table") are unambiguous and get
	 * an extra bonus.
	 * - same total score → the intent whose keyword stands furthest into
	 * the sentence wins ("find a job" reads the job, not the finding).
	 * - final tie → configured intent order.
	 *
	 * @return string One of the intent keys, or 'general' when nothing matches.
	 * @param string $text Text.
	 */
	private function detect_intent( string $text ): string {
		$text = mb_strtolower( (string) $text );

		$best_intent = 'general';
		$best_score  = 0;
		$best_end    = -1;
		foreach ( $this->get_intents() as $intent => $keywords ) {
			// Greetings are only ever a short turn. "Hey, can you list the
			// cafes near me?" is a real question that happens to open with a
			// hello, and scoring it as small talk used to answer it with a
			// generic greeting instead of business cards.
			if ( 'greeting' === $intent ) {
				$words = preg_split( '/[^\p{L}\p{N}\']+/u', $text );
				if ( count( array_filter( (array) $words ) ) > 6 ) {
					continue;
				}
			}
			$score = 0;
			$end   = -1;
			foreach ( $keywords as $keyword ) {
				if ( ! $this->keyword_matches( $keyword, $text ) ) {
					continue;
				}
				$length = mb_strlen( $keyword );
				// "search" and "find" are hub verbs, not topics: "search for a.
				// job" is a jobs question and must stay that way. They weigh.
				// like the short generic words, so the concrete subject wins.
				if ( in_array( $keyword, array( 'search', 'find' ), true ) ) {
					$weight = 2;
				} else {
					$weight = $length < 5 ? 2 : $length;
				}
				// A keyword sitting inside a prepositional phrase names what the
				// question is *about*, not what it is about: in "I need a mentor
				// for my career" the subject is the mentor and "career" only
				// qualifies it. Without this, both score the same length and the
				// later "career" wins the tie on position, which sent members to
				// the jobs module asking about mentors.
				if ( $this->is_modifier_match( $text, $keyword ) ) {
					$weight = max( 1, (int) floor( $weight / 2 ) );
				}
				$score += $weight;
				if ( false !== mb_strpos( $keyword, ' ' ) ) {
					$score += 6;
				}
				$at = mb_strpos( $text, $keyword );
				if ( false !== $at && $at > $end ) {
					$end = $at;
				}
			}
			if ( 0 === $score ) {
				continue;
			}
			if ( $score > $best_score || ( $score === $best_score && $end > $best_end ) ) {
				$best_score  = $score;
				$best_end    = $end;
				$best_intent = $intent;
			}
		}

		return $best_intent;
	}

	/**
	 * Whether a matched keyword is only qualifying the real subject, i.e. it
	 * appears inside a prepositional phrase introduced by a possessive
	 * ("a mentor for my career", "help with my portfolio"). A possessive marks
	 * what the question is *about*; the module word the member is actually
	 * asking about sits outside the phrase. An article does not: in "search for
	 * a job" the job is the object of the verb, not a modifier, so only
	 * possessives qualify here.
	 *
	 * @param string $text    Lowercased query.
	 * @param string $keyword Matched keyword.
	 */
	private function is_modifier_match( string $text, string $keyword ): bool {
		$at = mb_strpos( $text, $keyword );
		if ( false === $at || 0 === $at ) {
			return false;
		}

		$before = mb_substr( $text, max( 0, $at - 24 ), $at );
		if ( ! preg_match( '/\b(for|about|with|in|on|of|from)\s+(my|our|me|their|his|her)\s*$/i', $before ) ) {
			return false;
		}

		// A multi-word keyword is its own phrase ("career advice"), so it names
		// the topic even when it trails a preposition.
		return false === mb_strpos( $keyword, ' ' );
	}

	/**
	 * The intent → keyword map, filterable via 'zeko_ai_agent_intents' so
	 * sites can add intents or widen/override keywords without editing the
	 * plugin. Defaults when the filter returns something malformed.
	 *
	 * @return array<string,array<int,string>>
	 */
	private function get_intents(): array {
		$filtered = apply_filters( 'zeko_ai_agent_intents', $this->intents );
		if ( ! is_array( $filtered ) ) {
			return $this->intents;
		}
		$out = array();
		foreach ( $filtered as $intent => $keywords ) {
			if ( ! is_array( $keywords ) ) {
				continue;
			}
			$clean = array();
			foreach ( $keywords as $keyword ) {
				$keyword = mb_strtolower( trim( (string) $keyword ) );
				if ( '' !== $keyword ) {
					$clean[] = $keyword;
				}
			}
			if ( ! empty( $clean ) ) {
				$out[ (string) $intent ] = $clean;
			}
		}
		return $out;
	}

	/**
	 * Keyword match against lowercased text. Ambiguous short openers match
	 * only as whole words so filler like "something" or "they" never trips
	 * the greeting intent, and a word that merely contains an opener
	 * ("I said hello to my neighbour") is not a greeting at all; longer
	 * keywords match as substrings so plurals ("jobs", "payments",
	 * "sessions") still hit their intent.
	 *
	 * @param string $keyword Keyword.
	 * @param string $text Text.
	 */
	private function keyword_matches( string $keyword, string $text ): bool {
		if ( in_array( $keyword, array( 'hi', 'hey', 'hello', 'help' ), true ) ) {
			return (bool) preg_match( '/\b' . preg_quote( $keyword, '/' ) . '\b/', $text );
		}
		return false !== mb_strpos( $text, $keyword );
	}

	/**
	 * Detect when a question is about the member's own account/profiles
	 * ("my portfolio", "what did I publish?") so the agent can answer from
	 * their real ecosystem data instead of public content.
	 *
	 * @return string A personal module key, or '' when not personal.
	 * @param string $text Text.
	 */
	private function personal_intent( string $text ): string {
		$patterns = array(
			'profile'   => array( 'what do i have on zeko', 'my profile', 'my activity', 'my contributions', 'my public profile' ),
			'qa'        => array( 'my questions', 'my answers', 'my q&a', 'questions i', 'answers i', 'what did i ask', 'what did i answer' ),
			'courses'   => array( 'my courses', 'my learning', 'my enrolled courses', 'what am i learning', 'courses i' ),
			'freelance' => array( 'my portfolio', 'my projects', 'my freelance', 'my bids', 'what did i publish on freelance' ),
			'mentor'    => array( 'my mentor', 'my mentoring', 'my sessions' ),
			'dating'    => array( 'my dating', 'my matches', 'my likes' ),
			'rewards'   => array( 'my rewards', 'my points', 'my badges', 'my tier' ),
			'wallet'    => array( 'my wallet', 'my balance', 'my account number' ),
			'jobs'      => array( 'my jobs', 'my applications', 'what did i post', 'what did i apply' ),
		);

		$low = mb_strtolower( (string) $text );
		foreach ( $patterns as $module => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== mb_strpos( $low, $needle ) ) {
					return $module;
				}
			}
		}

		return '';
	}

	/**
	 * Build a personal, link-rich answer about the member's own ecosystem
	 * presence. Reuses the profile hub's module cards so it always points at
	 * the real profile URLs, and degrades to a "get started" CTA when the
	 * module profile does not exist yet.
	 *
	 * @param int    $user_id User id.
	 * @param string $module Module.
	 */
	private function personal_summary( int $user_id, string $module ): string {
		if ( 'wallet' === $module ) {
			return $this->wallet_intro();
		}

		if ( 'rewards' === $module ) {
			return $this->rewards_summary_text( $user_id );
		}

		$profile = null;
		if ( function_exists( 'zeko_ai' ) && method_exists( zeko_ai(), 'get_profile' ) ) {
			$profile = zeko_ai()->get_profile();
		}
		$cards = $profile ? (array) $profile->module_cards( $user_id ) : array();

		$base = home_url( '/profile/?user_id=' . $user_id );
		if ( class_exists( 'Zeko_Core_Helpers' ) ) {
			$base = Zeko_Core_Helpers::get_instance()->get_user_profile_url( $user_id );
		}

		if ( 'profile' === $module || '' === $module ) {
			if ( empty( $cards ) ) {
				return "I can't see any public profiles on your account yet. Set one up from your dashboard and it will show up here.\n\nOpen your profile: " . $base;
			}
			$lines = array( 'Here is what is on your Zeko profile:' );
			foreach ( $cards as $card ) {
				$line = '• ' . (string) ( $card['label'] ?? '' );
				$url  = (string) ( $card['url'] ?? '' );
				if ( '' !== $url ) {
					$line .= ' — ' . $url;
				}
				$lines[] = $line;
			}
			$lines[] = 'Full profile: ' . $base;
			return implode( "\n", $lines );
		}

		if ( isset( $cards[ $module ] ) ) {
			$card  = $cards[ $module ];
			$lines = array( 'Yes — you have an active ' . (string) ( $card['label'] ?? $module ) . ' profile.' );
			foreach ( (array) ( $card['summary'] ?? array() ) as $summary ) {
				$lines[] = '• ' . (string) $summary;
			}
			$url = (string) ( $card['url'] ?? '' );
			if ( '' !== $url ) {
				$lines[] = 'Open it: ' . $url;
			}
			return implode( "\n", $lines );
		}

		$cta = $this->personal_cta_url( $module, $user_id );
		return 'I could not find an active ' . $module . ' profile for your account yet.'
			. ( '' !== $cta ? ' Get started: ' . $cta : '' )
			. "\n\nOpen your profile: " . $base;
	}

	/**
	 * Personal cta url.
	 *
	 * @param string $module Module.
	 * @param int    $user_id User id.
	 */
	private function personal_cta_url( string $module, int $user_id ): string {
		$user     = get_userdata( $user_id );
		$nicename = $user ? (string) $user->user_nicename : '';
		switch ( $module ) {
			case 'qa':
				return $this->module_url( '/questions/' );
			case 'courses':
				return '' !== $nicename ? home_url( '/instructors/' . $nicename . '/' ) : $this->module_url( '/courses/' );
			case 'freelance':
				return function_exists( 'zeko_freelance_page_url' ) ? (string) zeko_freelance_page_url( 'freelance-profile' ) : $this->module_url( '/freelance/' );
			case 'mentor':
				return $this->module_url( '/mentors/' );
			case 'dating':
				return function_exists( 'zeko_love_page_url' ) ? (string) zeko_love_page_url( 'dating-settings' ) : $this->module_url( '/dating-matches/' );
			case 'jobs':
				return $this->module_url( '/jobs/' );
			case 'rewards':
				return $this->module_url( '/rewards/' );
			default:
				return home_url( '/profile/?user_id=' . $user_id );
		}
	}

	/**
	 * The member's actual rewards standing (points, tier, badges) with a link
	 * to their rewards dashboard. Fail-soft: falls back to a plain link.
	 *
	 * @param int $user_id User id.
	 */
	private function rewards_summary_text( int $user_id ): string {
		$url = function_exists( 'zeko_rewards_page_url' ) ? (string) zeko_rewards_page_url( 'rewards' ) : $this->module_url( '/rewards/' );
		try {
			if ( function_exists( 'zeko_rewards' ) && method_exists( zeko_rewards(), 'get_db' ) ) {
				$db     = zeko_rewards()->get_db();
				$points = (int) $db->user_points( $user_id );
				$badges = (array) $db->get_user_badges( $user_id );
				$tier   = (string) get_user_meta( $user_id, 'zeko_rewards_tier', true );
				$tiers  = function_exists( 'zeko_rewards_get_tiers' ) ? (array) zeko_rewards_get_tiers() : array();
				$label  = $tiers[ $tier ]['label'] ?? ( '' !== $tier ? $tier : 'bronze' );
				return sprintf(
					'You have %d points, %d badge(s) and a %s tier in Zeko Rewards.',
					$points,
					count( $badges ),
					$label
				) . "\n\nCheck your rewards: " . $url;
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}
		return "Your rewards activity lives on the Rewards dashboard.\n\nCheck your rewards: " . $url;
	}

	/**
	 * Intent fallback.
	 *
	 * @param string $intent Intent.
	 */
	private function intent_fallback( string $intent ): string {
		$messages = array(
			'greeting'   => "I'm Zeko AI, your ecosystem assistant. I can help you navigate jobs, courses, Q&A, shopping, freelancing, mentoring, dating, wallet and rewards. Try asking about any of those.",
			'jobs'       => "Zeko Jobs can help: search open listings on the Marketplace, save searches for alerts, and post your own role. Tailor each application to the requirements on the job card.\n\nBrowse jobs: " . $this->module_url( '/jobs/' ),
			'learn'      => "Zeko Learn has a full library of courses with lessons, quizzes and certificates. Pick a course, track your progress, and earn rewards as you complete milestones.\n\nBrowse courses: " . $this->module_url( '/courses/' ),
			'qa'         => "Ask a clear question on Zeko Q&A, add the right tags and topics, and upvote helpful answers. You earn reputation and badges for high-quality contributions.\n\nAsk on Q&A: " . $this->module_url( '/questions/' ),
			'business'   => "The Business Directory lists companies and stores around you with reviews, ratings and business hours. Search it for anything from cafes and restaurants to barbers, plumbers, clinics and gyms. Every business has its own page under /businesses/slug/ with its services and contact information.\n\nBrowse the directory: " . $this->business_page_url( 'directory' ) . "\n\nTo add your own business, use the Add your business form: " . $this->business_page_url( 'submit' ),
			'freelance'  => "On Zeko Freelance you can post a project brief, compare bids, and manage milestones with escrow protection. Keep briefs specific so freelancers can quote accurately.\n\nBrowse freelance projects: " . $this->module_url( '/freelance/' ),
			'shop'       => "Zeko Shop lists digital and physical products. Add items to your cart, check out with Zeko Pay, and track orders from your dashboard.\n\nVisit the shop: " . $this->module_url( '/shop/' ),
			'mentor'     => "Zeko Mentor matches you with mentors who fit your goals. Book sessions, set goals, and log progress as you grow.\n\nFind a mentor: " . $this->module_url( '/mentors/' ),
			'love'       => "On Zeko Dating you can browse verified profiles, like and match, and schedule dates through the platform. Keep your bio honest — compatibility starts there.\n\nOpen your matches: " . $this->module_url( '/dating-matches/' ),
			'wallet'     => $this->wallet_intro(),
			'profile'    => "Your public profile collects your activity, posts and contributions across the ecosystem.\n\nOpen your profile: " . $this->profile_url(),
			'rewards'    => "Zeko Rewards awards points and badges as you participate across the ecosystem. Redeem points from the catalog once you have enough.\n\nCheck your rewards: " . $this->module_url( '/rewards/' ),
			'moderation' => 'Zeko AI reviews community content against the ecosystem guidelines. Anything sent to the moderation queue is confirmed by a human before it is actioned.',
			'search'     => "Use AI Search to find jobs, courses, questions, products, freelance projects and mentors from one box. Results are ranked by relevance.\n\nSearch the ecosystem: " . $this->module_url( '/ai-search/' ),
			'recommend'  => "AI Recommendations look at your activity across the ecosystem and surface jobs, courses, questions, projects and products you are likely to enjoy.\n\nOpen your recommendations: " . $this->module_url( '/ai-recommendations/' ),
		);

		if ( isset( $messages[ $intent ] ) ) {
			return $messages[ $intent ];
		}

		return "I couldn't find a direct match for that yet. Try asking about jobs, courses, Q&A, shopping, freelance projects, mentoring, dating, wallet, rewards or your profile — or ask a more specific question and I'll keep learning.";
	}

	/**
	 * Module intents that own a real area of Zeko (a module page to open and
	 * content to search). Greeting/general never get a module block.
	 */
	private function module_area_intents(): array {
		return array(
			'jobs',
			'learn',
			'qa',
			'business',
			'freelance',
			'shop',
			'mentor',
			'love',
			'wallet',
			'profile',
			'rewards',
			'moderation',
			'search',
			'recommend',
		);
	}

	/**
	 * Which module areas can show live records in the web-fallback block
	 * (dater -> profile source, jobs -> job source, …). Filterable through
	 * 'zeko_ai_agent_module_area_specs' so sites can add modules, mirroring
	 * 'zeko_ai_agent_intents': keys are intents, each entry is
	 * array{types:string[], label:string}.
	 *
	 * @return array<string,array{types:array<int,string>,label:string}>
	 */
	private function module_area_specs(): array {
		$specs = array(
			'love'      => array(
				'types' => array( 'profile' ),
				'label' => 'dating profiles',
			),
			'jobs'      => array(
				'types' => array( 'job' ),
				'label' => 'jobs',
			),
			'learn'     => array(
				'types' => array( 'course' ),
				'label' => 'courses',
			),
			'qa'        => array(
				'types' => array( 'question' ),
				'label' => 'questions',
			),
			'business'  => array(
				'types' => array( 'business' ),
				'label' => 'businesses',
			),
			'freelance' => array(
				'types' => array( 'project' ),
				'label' => 'freelance projects',
			),
			'shop'      => array(
				'types' => array( 'product' ),
				'label' => 'products',
			),
			'mentor'    => array(
				'types' => array( 'mentor' ),
				'label' => 'mentors',
			),
		);

		$filtered = apply_filters( 'zeko_ai_agent_module_area_specs', $specs );
		if ( ! is_array( $filtered ) ) {
			return $specs;
		}

		$out = array();
		foreach ( $filtered as $intent => $spec ) {
			if ( ! is_array( $spec ) ) {
				continue;
			}
			$out[ (string) $intent ] = array(
				'types' => array_values( array_filter( array_map( 'strval', (array) ( $spec['types'] ?? array() ) ) ) ),
				'label' => (string) ( $spec['label'] ?? 'records' ),
			);
		}
		return $out;
	}

	/**
	 * Short label for live module records shown in the module-area block.
	 *
	 * @param string $intent Intent.
	 */
	private function module_item_label( string $intent ): string {
		$specs = $this->module_area_specs();
		return $specs[ $intent ]['label'] ?? 'records';
	}

	/**
	 * A topic-owned module area for a member whose question left Zeko. Runs
	 * only on the web-fallback path so it never repeats an on-site answer:
	 * the module intro points at the right corner of Zeko, and live module
	 * records that match the intent (dating profiles, jobs, courses… top up
	 * the answer from the areas/modules that own the topic) — all still in
	 * addition to the outbound web results that follow.
	 *
	 * @param string $intent Intent.
	 * @param string $query Query.
	 */
	private function module_area( string $intent, string $query ): string {
		$specs = $this->module_area_specs();
		if ( ! isset( $specs[ $intent ] ) && ! in_array( $intent, $this->module_area_intents(), true ) ) {
			return '';
		}

		$intro = $this->intent_fallback( $intent );
		if ( '' === $intro ) {
			return '';
		}

		$matches = $this->module_match_results( $intent, $query );
		if ( empty( $matches ) ) {
			return $intro;
		}

		$lines = array( $intro, '', sprintf( 'Here are matching %s on Zeko right now:', $this->module_item_label( $intent ) ) );
		foreach ( $matches as $match ) {
			$line = '• ' . (string) $match['title'];
			if ( '' !== (string) $match['url'] ) {
				$line .= ' — ' . (string) $match['url'];
			}
			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Fetch live records from the module's own search sources. The general
	 * corpus pass already searched every source with the member's full
	 * sentence and missed; here the module's sources are re-searched with the
	 * intent's topic keywords ("love", "dating"…) so a genuinely topical
	 * module ("I want to find a date") still surfaces its content instead of
	 * falling straight out to the web.
	 *
	 * @return array<int,array{title:string,url:string}>
	 * @param string $intent Intent.
	 * @param string $query Query.
	 */
	private function module_match_results( string $intent, string $query ): array {
		$specs = $this->module_area_specs();
		if ( ! isset( $specs[ $intent ] ) ) {
			return array();
		}
		$types = $specs[ $intent ]['types'];

		$scoped = array();
		foreach ( (array) apply_filters( 'zeko_ai_search_sources', array() ) as $source ) {
			if (
				is_array( $source )
				&& isset( $source['search'] )
				&& is_callable( $source['search'] )
				&& in_array( (string) ( $source['type'] ?? '' ), $types, true )
			) {
				$scoped[] = $source;
			}
		}
		if ( empty( $scoped ) ) {
			return array();
		}

		$seen = array();
		$out  = array();
		foreach ( $this->module_search_terms( $intent, $query ) as $term ) {
			foreach ( $scoped as $source ) {
				foreach ( (array) call_user_func( $source['search'], $term, 5 ) as $item ) {
					$title = (string) ( $item['title'] ?? '' );
					if ( '' === $title || isset( $seen[ $title ] ) ) {
						continue;
					}
					$seen[ $title ] = true;
					$out[]          = array(
						'title' => $title,
						'url'   => (string) ( $item['url'] ?? '' ),
					);
					if ( count( $out ) >= 3 ) {
						return $out;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Terms to re-search a module's own sources with: the intent's topic
	 * keywords first, then the distinctive tokens of the member's question.
	 *
	 * @param string $intent Intent.
	 * @param string $query Query.
	 */
	private function module_search_terms( string $intent, string $query ): array {
		$keywords = array_values( (array) ( $this->get_intents()[ $intent ] ?? array() ) );
		$keywords = array_values( array_unique( array_map( 'mb_strtolower', array_map( 'strval', $keywords ) ) ) );

		$tokens = array_values( array_diff( $this->parse_query_tokens( $query ), $keywords ) );
		$terms  = array_merge( array_slice( $keywords, 0, 6 ), array_slice( $tokens, 0, 3 ) );
		$terms  = array_values(
			array_unique(
				array_filter(
					$terms,
					static function ( string $t ): bool {
						return mb_strlen( $t ) >= 3;
					}
				)
			)
		);

		return $terms;
	}

	/**
	 * Front-end URL for a module page. Modules with their own permalink
	 * helper are resolved through it so links point at the real page; the
	 * rest fall back to a canonical path. Either way the result is filterable
	 * via `zeko_ai_agent_module_url` for overrides and tests.
	 *
	 * @param string $path Path.
	 */
	private function module_url( string $path ): string {
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

	/**
	 * URL for a business module page, resolved through the business plugin's
	 * own page helper when it is active so links always land on the real page.
	 *
	 * @param string $key Key.
	 */
	private function business_page_url( string $key ): string {
		if ( class_exists( '\ZBE\Plugin' ) && is_callable( array( '\ZBE\Plugin', 'page_url' ) ) ) {
			$url = \ZBE\Plugin::page_url( $key );
			if ( is_string( $url ) && '' !== $url ) {
				return (string) apply_filters( 'zeko_ai_agent_business_page_url', $url, $key );
			}
		}

		$fallbacks = array(
			'directory' => 'business-directory',
			'submit'    => 'add-business',
			'dashboard' => 'my-businesses',
			'portal'    => 'business-portal',
		);

		$page = get_page_by_path( $fallbacks[ $key ] ?? '' );
		if ( $page instanceof WP_Post ) {
			return (string) apply_filters( 'zeko_ai_agent_business_page_url', get_permalink( $page ), $key );
		}

		return (string) apply_filters( 'zeko_ai_agent_business_page_url', home_url( '/?page_id=' . (int) get_option( $key ), '' ), $key );
	}

	/**
	 * Front-end URL for the current user's public profile. Uses the core
	 * helper when available so the link resolves to the member's real page.
	 */
	private function profile_url(): string {
		$url = home_url( '/profile/' );
		if ( class_exists( 'Zeko_Core_Helpers' ) ) {
			$url = Zeko_Core_Helpers::get_instance()->get_user_profile_url();
		}
		return (string) apply_filters( 'zeko_ai_agent_module_url', $url, '/profile/' );
	}

	/**
	 * Wallet intro for the wallet intent. Appends the caller's Zeko account
	 * number when the Pay module can resolve one, so "how do I see my account
	 * number" is answered directly instead of only linking the wallet page.
	 */
	private function wallet_intro(): string {
		$message = "Zeko Pay is your unified wallet. Add funds, pay across the ecosystem, and track every transaction in your wallet tab.\n\nOpen your wallet: " . $this->module_url( '/wallet/' );
		$user_id = get_current_user_id();
		if ( $user_id > 0 && class_exists( 'Zeko_Pay_Accounts' ) ) {
			$number = Zeko_Pay_Accounts::instance()->get_account_number( $user_id );
			if ( '' !== $number ) {
				$message .= "\n\nYour Zeko account number: " . $number;
			}
		}
		return $message;
	}

	/**
	 * Db.
	 */
	private function get_db(): ?Zeko_AI_DB {
		if ( null === $this->db && class_exists( 'Zeko_AI' ) ) {
			$this->db = Zeko_AI::instance()->get_db();
		}
		return $this->db;
	}

	/**
	 * Last user message.
	 *
	 * @param array $messages Messages.
	 */
	private function last_user_message( array $messages ): string {
		$user_text = '';
		foreach ( $messages as $message ) {
			if ( is_array( $message ) && 'user' === ( $message['role'] ?? '' ) ) {
				$user_text = (string) ( $message['content'] ?? '' );
			}
		}
		$user_text = trim( $user_text );
		return '' !== $user_text ? $user_text : 'help';
	}

	/**
	 * Flatten.
	 *
	 * @param array $messages Messages.
	 */
	private function flatten( array $messages ): string {
		$out = '';
		foreach ( $messages as $message ) {
			if ( is_array( $message ) ) {
				$out .= ' ' . (string) ( $message['content'] ?? '' );
			}
		}
		return trim( $out );
	}

	/**
	 * Estimate tokens.
	 *
	 * @param string $text Text.
	 */
	private function estimate_tokens( string $text ): int {
		return max( 1, (int) ceil( mb_strlen( $text ) / 4 ) );
	}
}
