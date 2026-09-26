<?php
/**
 * Deterministic offline provider.
 *
 * The Mock provider makes every Zeko AI feature testable without keys or a
 * network. It never makes HTTP requests. Answers are keyword-driven and
 * seeded by the incoming text so the same input always yields the same
 * output (important for the test suite).
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Mock_Provider. */
class Zeko_AI_Mock_Provider extends Zeko_AI_Provider {

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
	 * Name.
	 */
	public function name(): string {
		return 'mock';
	}

	/**
	 * Model.
	 */
	public function model(): string {
		return 'mock-1';
	}

	/**
	 * Chat.
	 *
	 * @param array $messages Messages.
	 * @param array $opts Opts.
	 */
	public function chat( array $messages, array $opts = array() ): array {
		$user_text = '';
		foreach ( $messages as $message ) {
			if ( is_array( $message ) && 'user' === ( $message['role'] ?? '' ) ) {
				$user_text .= ' ' . (string) ( $message['content'] ?? '' );
			}
		}
		$user_text = trim( $user_text );
		if ( '' === $user_text ) {
			$user_text = 'help';
		}

		$seeded = $this->seed( $user_text );
		$reply  = $this->answer( $user_text );

		$tokens_in  = $this->estimate_tokens( $this->flatten( $messages ) );
		$tokens_out = $this->estimate_tokens( $reply );

		return array(
			'content'    => $reply,
			'tokens_in'  => $tokens_in,
			'tokens_out' => $tokens_out,
			'model'      => $this->model(),
			'raw'        => array( 'seeded' => $seeded ),
		);
	}

	/**
	 * For writing tasks the mock returns the fully-expanded prompt as the
	 * "draft". This keeps content generation deterministic and lets tests
	 * verify prompt interpolation end-to-end.
	 *
	 * @param string $prompt Prompt.
	 * @param array  $opts Opts.
	 */
	public function complete( string $prompt, array $opts = array() ): array {
		$prompt = (string) $prompt;
		return array(
			'content'    => $prompt,
			'tokens_in'  => $this->estimate_tokens( $prompt ),
			'tokens_out' => $this->estimate_tokens( $prompt ),
			'model'      => $this->model(),
			'raw'        => array(),
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
			'score'    => $reasons ? $this->score( count( $reasons ) ) : 0.0,
		);
	}

	/**
	 * Deterministic keyword answer. Scans for module-related keywords and
	 * replies with a contextual canned response.
	 *
	 * @param string $text Text.
	 */
	private function answer( string $text ): string {
		$text = mb_strtolower( $text );
		$tone = isset( $this->settings['mock_tone'] ) ? (string) $this->settings['mock_tone'] : 'friendly';

		if ( false !== mb_strpos( $text, 'job' ) || false !== mb_strpos( $text, 'career' ) ) {
			return 'Here are a few ways Zeko Jobs can help: search open listings on the Marketplace, save searches for alerts, and post your own role. Tailor each application with the requirements listed on the job card.';
		}
		if ( false !== mb_strpos( $text, 'course' ) || false !== mb_strpos( $text, 'learn' ) || false !== mb_strpos( $text, 'lesson' ) ) {
			return 'Zeko Learn has a full library of courses with lessons, quizzes and certificates. Pick a course, track your progress, and earn rewards as you complete milestones.';
		}
		if ( false !== mb_strpos( $text, 'question' ) || false !== mb_strpos( $text, 'q&a' ) || false !== mb_strpos( $text, 'answer' ) ) {
			return 'Ask a clear question on Zeko Q&A, add the right tags and topics, and upvote helpful answers. You earn reputation and badges for high-quality contributions.';
		}
		if ( false !== mb_strpos( $text, 'freelanc' ) || false !== mb_strpos( $text, 'project' ) || false !== mb_strpos( $text, 'bid' ) ) {
			return 'On Zeko Freelance you can post a project brief, compare bids, and manage milestones with escrow protection. Keep briefs specific so freelancers can quote accurately.';
		}
		if ( false !== mb_strpos( $text, 'shop' ) || false !== mb_strpos( $text, 'product' ) || false !== mb_strpos( $text, 'buy' ) ) {
			return 'Zeko Shop lists digital and physical products. Add items to your cart, check out with Zeko Pay, and track orders from your dashboard.';
		}
		if ( false !== mb_strpos( $text, 'mentor' ) || false !== mb_strpos( $text, 'session' ) ) {
			return 'Zeko Mentor matches you with mentors who match your goals. Book sessions, set goals, and log progress as you grow.';
		}
		if ( false !== mb_strpos( $text, 'date' ) || false !== mb_strpos( $text, 'dating' ) || false !== mb_strpos( $text, 'match' ) ) {
			return 'On Zeko Dating you can browse verified profiles, like and match, and schedule dates through the platform. Keep your bio honest — compatibility starts there.';
		}
		if ( false !== mb_strpos( $text, 'wallet' ) || false !== mb_strpos( $text, 'pay' ) || false !== mb_strpos( $text, 'balance' ) ) {
			return 'Zeko Pay is your unified wallet. Add funds, pay across the ecosystem, and track every transaction in your wallet tab.';
		}
		if ( false !== mb_strpos( $text, 'reward' ) || false !== mb_strpos( $text, 'badge' ) || false !== mb_strpos( $text, 'point' ) ) {
			return 'Zeko Rewards awards points and badges as you participate across the ecosystem. Redeem points from the catalog once you have enough.';
		}
		if ( false !== mb_strpos( $text, 'search' ) ) {
			return 'Use AI Search to find jobs, courses, questions, products, freelance projects and mentors from one box. Results are ranked by relevance.';
		}
		if ( false !== mb_strpos( $text, 'recommend' ) ) {
			return 'AI Recommendations look at your activity across the ecosystem and surface jobs, courses, questions, projects and products you are likely to enjoy.';
		}
		if ( false !== mb_strpos( $text, 'moderat' ) || false !== mb_strpos( $text, 'safe' ) || false !== mb_strpos( $text, 'report' ) ) {
			return 'Zeko AI reviews community content for harmful, abusive or spammy material. Anything flagged lands in the moderation queue for a human to confirm.';
		}
		if ( false !== mb_strpos( $text, 'help' ) || false !== mb_strpos( $text, 'hi' ) || false !== mb_strpos( $text, 'hello' ) ) {
			return "I'm Zeko AI, your ecosystem assistant. I can help you navigate jobs, courses, Q&A, shopping, freelancing, mentoring, dating and rewards. Try asking about any of those.";
		}

		return "Here's the short version: I found no specific match for that, but I can point you around the ecosystem. Ask me about jobs, courses, Q&A, freelance, shop, mentoring, dating, wallet, or rewards and I'll take it from there. (Tone: {$tone})";
	}

	/**
	 * Deterministic seed derived from the input so identical prompts produce
	 * identical raw payloads.
	 *
	 * @param string $text Text.
	 */
	private function seed( string $text ): int {
		$hash = 0;
		$len  = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$hash = ( $hash * 31 + ord( $text[ $i ] ) ) % 2147483647;
		}
		return $hash;
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
		return $out;
	}

	/**
	 * Estimate tokens.
	 *
	 * @param string $text Text.
	 */
	private function estimate_tokens( string $text ): int {
		return max( 1, (int) ceil( mb_strlen( $text ) / 4 ) );
	}

	/**
	 * Score.
	 *
	 * @param int $reason_count Reason count.
	 */
	private function score( int $reason_count ): float {
		// Deterministic severity based on how many categories tripped.
		return min( 0.99, 0.5 + 0.1 * $reason_count );
	}
}
