<?php
/**
 * Zeko AI engine — evaluation set.
 *
 * One entry per decision the engine makes, with what that decision should be.
 * Each case is a real phrasing a member can type, not a synthetic probe: the
 * point is to catch the engine answering the wrong question, which is the one
 * failure users actually notice.
 *
 * Case keys
 *   id       stable slug, used as the PHPUnit test label.
 *   group    greeting | intent | retrieval | knowledge | entity | ambiguity.
 *   turns    one string, or a list of turns for a multi-turn case.
 *   intent   expected intent(s). A list means both are acceptable answers.
 *   source   expected answering source(s); omit to only assert the intent.
 *   not_source  source(s) that must NOT have answered.
 *   contains substrings the reply must contain.
 *   lacks    substrings the reply must not contain (cross-module leakage).
 *   corpus   fixtures.php corpus key to register as the only search source.
 *   entities fixtures.php entities key to register as the only resolver.
 *   knowledge  seed the knowledge fixture before the turn.
 *   settings per-case agent setting overrides.
 *   known_gap  true = a known weakness. Reported, never failed, so the set
 *              doubles as the prioritised backlog. Flip to false when fixed.
 *   note     why the case exists.
 *
 * @package Zeko_AI
 */

return array(

	// ---------------------------------------------------------------- greeting
	array(
		'id'      => 'greeting-hello',
		'group'   => 'greeting',
		'turns'   => 'hello',
		'intent'  => 'greeting',
		'source'  => 'greeting',
		'note'    => 'Small talk must never trigger retrieval or an outbound web search.',
	),
	array(
		'id'      => 'greeting-hey-there',
		'group'   => 'greeting',
		'turns'   => 'hey there',
		'intent'  => 'greeting',
		'source'  => 'greeting',
	),
	array(
		'id'      => 'greeting-how-are-you',
		'group'   => 'greeting',
		'turns'   => 'how are you?',
		'intent'  => 'greeting',
		'source'  => 'greeting',
	),
	array(
		'id'      => 'greeting-good-morning',
		'group'   => 'greeting',
		'turns'   => 'good morning',
		'intent'  => 'greeting',
		'source'  => 'greeting',
	),
	array(
		'id'      => 'greeting-hi-zeko',
		'group'   => 'greeting',
		'turns'   => 'hi zeko',
		'intent'  => 'greeting',
		'source'  => 'greeting',
	),
	array(
		'id'     => 'greeting-help',
		'group'  => 'greeting',
		'turns'  => 'help',
		'intent' => 'greeting',
		'source' => array( 'greeting', 'fallback' ),
		'note'   => '"help" is a request for help, not small talk: the capability summary is a fine answer, a "hi there" is not. Documents the current split.',
	),
	array(
		'id'         => 'greeting-inside-a-long-question',
		'group'      => 'greeting',
		'turns'      => 'hey, what does the mentor module actually do?',
		'intent'     => 'mentor',
		'not_source' => 'greeting',
		'note'       => 'A greeting word at the front of a real question must not swallow it.',
	),
	array(
		'id'         => 'greeting-inside-a-list-request',
		'group'      => 'greeting',
		'turns'      => 'hello, can you list the cafes near me?',
		'intent'     => array( 'business', 'search' ),
		'not_source' => 'greeting',
	),

	// ------------------------------------------------------------------ intent
	array(
		'id'     => 'intent-jobs-find',
		'group'  => 'intent',
		'turns'  => 'how do I find a job',
		'intent' => 'jobs',
	),
	array(
		'id'     => 'intent-jobs-show',
		'group'  => 'intent',
		'turns'  => 'show me jobs on zeko',
		'intent' => 'jobs',
	),
	array(
		'id'     => 'intent-qa-post',
		'group'  => 'intent',
		'turns'  => 'I want to post a question',
		'intent' => 'qa',
	),
	array(
		'id'     => 'intent-shop-buy',
		'group'  => 'intent',
		'turns'  => 'where can I buy a phone',
		'intent' => 'shop',
	),
	array(
		'id'     => 'intent-freelance-project',
		'group'  => 'intent',
		'turns'  => 'find a freelance project',
		'intent' => 'freelance',
	),
	array(
		'id'     => 'intent-mentor-career',
		'group'  => 'intent',
		'turns'  => 'I need a mentor for my career',
		'intent' => 'mentor',
	),
	array(
		'id'     => 'intent-wallet-balance',
		'group'  => 'intent',
		'turns'  => 'what is my wallet balance',
		'intent' => 'wallet',
	),
	array(
		'id'     => 'intent-learn-wordpress',
		'group'  => 'intent',
		'turns'  => 'where can I learn wordpress',
		'intent' => 'learn',
	),
	array(
		'id'     => 'intent-business-table',
		'group'  => 'intent',
		'turns'  => 'book a table',
		'intent' => 'business',
	),
	array(
		'id'     => 'intent-moderation',
		'group'  => 'intent',
		'turns'  => 'how do I moderate content',
		'intent' => 'moderation',
	),
	array(
		'id'     => 'intent-rewards',
		'group'  => 'intent',
		'turns'  => 'what are my rewards',
		'intent' => 'rewards',
	),
	array(
		'id'     => 'intent-recommend-course',
		'group'  => 'intent',
		'turns'  => 'recommend me a course',
		'intent' => array( 'recommend', 'learn' ),
	),
	array(
		'id'     => 'intent-love-tonight',
		'group'  => 'intent',
		'turns'  => 'I need a date on zeko tonight',
		'intent' => 'love',
	),
	array(
		'id'    => 'intent-love-partner',
		'group' => 'intent',
		'turns' => 'where can I find a partner on zeko',
		'intent' => 'love',
		'note'  => 'Dating vocabulary reaches the dating module instead of the generic search intent. "partner" is also a business word, and "business" (weight 8) has to keep outranking it (weight 7) for "I need a business partner".',
	),
	array(
		'id'    => 'intent-love-partner-does-not-steal-business',
		'group' => 'intent',
		'turns' => 'I need a business partner',
		'intent' => 'business',
		'note'  => '"partner" belongs to dating and to business. The longer, more specific word has to win.',
	),
	array(
		'id'       => 'intent-learn-typos',
		'group'    => 'intent',
		'turns'    => 'methoods for lerning',
		'intent'   => 'learn',
		'known_gap' => true,
		'note'     => 'No edit-distance tolerance: two typos in one query lose the module entirely. Cheapest possible win (levenshtein inside token_similarity).',
	),

	// --------------------------------------------------------------- retrieval
	array(
		'id'     => 'retrieval-date-must-not-be-a-business',
		'group'  => 'retrieval',
		'turns'  => 'can I find a date on zeko?',
		'corpus' => 'business',
		'intent' => 'love',
		'source' => array( 'fallback', 'knowledge' ),
		'lacks'  => array( 'Cafe', 'Bank', 'Barber' ),
		'note'   => 'The reported bug: a cafe named "Zeko ..." answered a dating question because the platform name counted as a topic.',
	),
	array(
		'id'        => 'retrieval-date-with-knowledge',
		'group'     => 'retrieval',
		'turns'     => 'can I find a date on zeko?',
		'corpus'    => 'business',
		'knowledge' => true,
		'intent'    => 'love',
		'source'    => 'knowledge',
		'lacks'     => array( 'Cafe', 'Bank' ),
		'note'      => 'A stored answer ("On Zeko Dating you browse verified profiles, like and match…") is reachable from a question phrased completely differently. Needs the answer counted as evidence, hub words dropped, and "date"/"dating" folded together.',
	),
	array(
		'id'     => 'retrieval-cafe',
		'group'  => 'retrieval',
		'turns'  => 'find me a cafe',
		'corpus' => 'business',
		'intent' => 'business',
		'source' => 'corpus',
		'contains' => array( 'Cafe' ),
		'lacks'  => array( 'Bank', 'Barber' ),
		'note'   => 'A matching business must still be found. Guards the relevance fix against over-correcting.',
	),
	array(
		'id'     => 'retrieval-bank',
		'group'  => 'retrieval',
		'turns'  => 'I need a bank',
		'corpus' => 'business',
		'intent' => 'business',
		'source' => 'corpus',
		'contains' => array( 'Bank' ),
		'lacks'  => array( 'Cafe' ),
		'note'   => 'Category discrimination: a bank query must not return the cafe.',
	),
	array(
		'id'     => 'retrieval-barber',
		'group'  => 'retrieval',
		'turns'  => 'I need a barber',
		'corpus' => 'business',
		'intent' => 'business',
		'source' => 'corpus',
		'contains' => array( 'Barber' ),
		'lacks'  => array( 'Cafe' ),
	),
	array(
		'id'      => 'retrieval-platform-name-alone',
		'group'   => 'retrieval',
		'turns'   => 'zeko',
		'corpus'  => 'business',
		'intent'  => 'general',
		'source'  => array( 'fallback', 'knowledge' ),
		'lacks'   => array( 'Cafe', 'Bank' ),
		'note'    => 'The platform name on its own is not a topic, so nothing should be listed.',
	),
	array(
		'id'      => 'retrieval-what-is-zeko-is-not-a-listing',
		'group'   => 'retrieval',
		'turns'   => 'what is zeko?',
		'corpus'  => 'business',
		'intent'  => 'general',
		'source'  => array( 'fallback', 'knowledge' ),
		'lacks'   => array( 'Cafe', 'Bank' ),
		'note'    => 'Asking what the platform is must never be answered with a business listing.',
	),
	array(
		'id'     => 'retrieval-course',
		'group'  => 'retrieval',
		'turns'  => 'I want to learn wordpress',
		'corpus' => 'courses',
		'intent' => 'learn',
		'source' => 'corpus',
		'contains' => array( 'WordPress for Beginners' ),
		'lacks'  => array( 'Freelance Pricing' ),
	),
	array(
		'id'       => 'retrieval-job-by-title',
		'group'    => 'retrieval',
		'turns'    => 'frontend developer jobs',
		'corpus'   => 'jobs',
		'intent'   => 'jobs',
		'source'   => 'corpus',
		'contains' => array( 'Frontend Developer' ),
	),
	array(
		'id'     => 'retrieval-jobs-by-module-word',
		'group'  => 'retrieval',
		'turns'  => 'show me jobs',
		'corpus' => 'jobs',
		'intent' => 'jobs',
		'source' => 'corpus',
		'contains' => array( 'Frontend Developer' ),
		'note'   => 'A request to browse a module, not a failed search: the only thing said is the module noun, so the module listing is the right answer.',
	),
	array(
		'id'      => 'retrieval-specific-miss-is-not-a-browse',
		'group'   => 'retrieval',
		'turns'   => 'show me jobs in Lagos',
		'corpus'  => 'jobs',
		'intent'  => 'jobs',
		'source'  => 'fallback',
		'lacks'   => array( 'Frontend Developer' ),
		'note'    => 'Adding a real constraint ("in Lagos") means the member is asking about something specific. Listing the whole module would be a worse answer than saying there is no match, so the browse stays off.',
	),

	// --------------------------------------------------------------- knowledge
	array(
		'id'        => 'knowledge-what-is-zeko',
		'group'     => 'knowledge',
		'turns'     => 'what is zeko?',
		'knowledge' => true,
		'corpus'    => 'business',
		'intent'    => 'general',
		'source'    => 'knowledge',
		'contains'  => array( 'community platform' ),
		'lacks'     => array( 'Cafe' ),
	),
	array(
		'id'        => 'knowledge-rewards',
		'group'     => 'knowledge',
		'turns'     => 'how do I earn rewards?',
		'knowledge' => true,
		'intent'    => 'rewards',
		'source'    => 'knowledge',
		'contains'  => array( 'redeem' ),
	),
	array(
		'id'        => 'knowledge-two-token-paraphrase',
		'group'     => 'knowledge',
		'turns'     => 'change my email',
		'knowledge' => true,
		'intent'    => 'general',
		'source'    => 'knowledge',
		'contains'  => array( 'Edit profile' ),
		'note'      => 'Two meaningful tokens are enough for the token-AND safety net.',
	),
	array(
		'id'        => 'knowledge-beats-corpus',
		'group'     => 'knowledge',
		'turns'     => 'how do I change my email?',
		'knowledge' => true,
		'corpus'    => 'business',
		'intent'    => 'general',
		'source'    => 'knowledge',
		'lacks'     => array( 'Cafe' ),
	),
	array(
		'id'        => 'knowledge-single-token-paraphrase',
		'group'     => 'knowledge',
		'turns'     => 'tell me about dating',
		'knowledge' => true,
		'intent'    => 'love',
		'source'    => 'knowledge',
		'note'      => 'One meaningful token is enough when the stored question really is about that token.',
	),
	array(
		'id'        => 'knowledge-module-scope',
		'group'     => 'knowledge',
		'turns'     => 'where can I find a partner on zeko',
		'knowledge' => true,
		'corpus'    => 'business',
		'intent'    => 'love',
		'source'    => 'knowledge',
		'lacks'     => array( 'certificate', 'Remove profile' ),
		'note'      => 'A dating question must not be answered by a learn or love row that merely shares the word "profile". Knowledge rows carry their module, so a module intent only accepts its own rows plus the cross-cutting ones.',
	),

	// ------------------------------------------------------------------ entity
	array(
		'id'        => 'entity-named-business',
		'group'     => 'entity',
		'turns'     => 'what time does Zeko Central Cafe open?',
		'entities'  => 'business',
		'intent'    => 'business',
		'source'    => 'entity',
		'contains'  => array( 'Zeko Central Cafe' ),
		'lacks'     => array( 'Community Bank' ),
	),
	array(
		'id'        => 'entity-named-bank',
		'group'     => 'entity',
		'turns'     => 'do you know the Zeko Community Bank',
		'entities'  => 'business',
		'source'    => 'entity',
		'contains'  => array( 'Community Bank' ),
		'note'      => 'Intent is not asserted: "bank" is not in the business keyword table, so this resolves only because the name is recognised. Worth adding as vocabulary.',
	),
	array(
		'id'        => 'entity-follow-up-inherits-subject',
		'group'     => 'entity',
		'turns'     => array( 'what time does Zeko Central Cafe open?', 'is it open on Sundays?' ),
		'entities'  => 'business',
		'intent'    => 'business',
		'source'    => 'entity',
		'contains'  => array( 'Zeko Central Cafe' ),
		'note'      => 'A follow-up turn has no subject of its own; it must inherit the previous question.',
	),

	// -------------------------------------------------------------- ambiguity
	array(
		'id'     => 'ambiguity-book-a-date',
		'group'  => 'ambiguity',
		'turns'  => 'book a date',
		'intent' => array( 'love', 'business' ),
		'note'   => 'A reservation or a romantic date: the engine currently commits to dating. This case exists to catch that changing in either direction while the disambiguation work lands.',
	),
);
