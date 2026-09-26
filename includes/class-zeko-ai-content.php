<?php
/**
 * Zeko AI content generation.
 *
 * Per-module presets (contributed by the integrations) produce writing for
 * job descriptions, course descriptions, questions, product descriptions,
 * project briefs, mentor bios and dating bios. Generation runs through the
 * content-favored provider chain and every call is recorded for analytics.
 *
 * When the writer is served by an offline provider (keyless Mock or the
 * self-training community agent) the deterministic draft composer is used in
 * its place, so sites without an API key still get structured, useful copy.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Content. */
class Zeko_AI_Content {

	/**
	 * BLUEPRINTS.
	 *
	 * @var array<string,string[]>
	 */
	private const BLUEPRINTS = array(
		'job_description'      => array(
			'We are hiring!',
			'',
			'About the role',
			'{notes|bullets}',
			'',
			'What we offer',
			'- A friendly, flexible team where quality work is valued.',
			'- Real impact on the community and room to grow.',
			'- Support and feedback from people who care.',
			'',
			'If this sounds like the right fit for you, use the form below to apply and tell us why.',
		),
		'cover_letter'         => array(
			'Subject: Application for {job_title}',
			'',
			'Dear hiring team,',
			'',
			'I am writing to apply for the {job_title} position.',
			'',
			'What I bring, in one line',
			'{background|bullets}',
			'',
			'I would welcome the chance to discuss how I can contribute to your team.',
			'',
			'Best regards,',
			'[Your name]',
		),
		'dating_bio'           => array(
			"Hi, I'm [Your name]!",
			'',
			"I'm the kind of person who {notes|inline}.",
			'',
			'When I am not busy with that, you will usually find me learning something new or exploring the community. I believe in making time for good conversation and building connections that go a little deeper.',
			'',
			'If that sounds like your kind of energy, say hi and let us start a conversation.',
		),
		'date_proposal'        => array(
			"Hey! I hope you're doing well.",
			'',
			'I came across your profile and noticed we share an interest in {interest|inline}. I had an idea that felt like the perfect excuse to reach out.',
			'',
			'How about we {idea|inline} sometime soon? I would love to hear your side of the story.',
			'',
			'Let me know what you think — and if you have a favorite spot for this kind of plan, I am all ears.',
		),
		'business_description' => array(
			'Welcome to our business!',
			'',
			'Our starting point',
			'{notes|bullets}',
			'',
			'We are building a business powered by the community, so every client and partner is part of how we grow and improve.',
			'',
			'Browse our services below or reach out — we would love to hear from you.',
		),
		'service_description'  => array(
			'{service|inline}',
			'',
			'We offer {service|inline} designed around your needs.',
			'',
			'What defines us',
			'{notes|bullets}',
			'',
			'Contact us to get started — we respond quickly and would be glad to tailor the work to your goals.',
		),
		'course_description'   => array(
			'About this course',
			'',
			'{notes|bullets}',
			'',
			'Who it is for',
			'- Beginners and anyone curious about the topic.',
			'- People who learn best by doing.',
			'',
			'Join a supportive learning community with feedback at every step.',
		),
		'what_you_learn'       => array(
			'By the end of this course you will be able to:',
			'{topics|bullets}',
			'',
			'You will practice with real examples, get feedback, and leave with work you can show.',
		),
		'project_brief'        => array(
			'Project overview',
			'',
			'{notes|bullets}',
			'',
			'Timeline and budget can be discussed with the right person. If this sounds like you, place a bid and let us talk.',
		),
		'bid_proposal'         => array(
			"Hi, I'd love to work on {project|inline}.",
			'',
			'About me',
			'{experience|bullets}',
			'',
			'Please let me know if more details or examples would help.',
			'',
			'Looking forward to your thoughts.',
		),
		'product_description'  => array(
			'{product_name|inline}',
			'',
			'Meet {product_name|inline} — built to earn its place in your day.',
			'',
			'Highlights',
			'{notes|bullets}',
			'',
			'Ordering is easy and support is human. Try {product_name|inline} and feel the difference.',
		),
		'question_draft'       => array(
			"I have a question I can't seem to answer on my own.",
			'',
			'The question, in a few words',
			'{notes|bullets}',
			'',
			'If you have any pointers or a shared experience, I would really appreciate your answer.',
		),
		'answer_draft'         => array(
			'Great question!',
			'',
			'The short answer to "{question|inline}" is:',
			'{notes|bullets}',
			'',
			'I hope that clears things up — happy to go deeper if you have follow-ups.',
		),
		'mentor_bio'           => array(
			"I'm a mentor who loves helping people grow.",
			'',
			'A little about me',
			'{background|bullets}',
			'',
			'This is a space to ask anything honestly, get clear next steps, and learn by doing. Book a session and let us get started.',
		),
		'mentor_goal'          => array(
			'My mentoring goal',
			'{notes|bullets}',
			'',
			'I would like a clear plan, honest feedback, and accountability along the way. My goal is simple — make real progress we can both measure.',
		),
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
	 * All registered presets, keyed by id.
	 *
	 * @return array<string,array>
	 */
	public function get_presets(): array {
		$presets = apply_filters( 'zeko_ai_content_presets', array() );

		$indexed = array();
		foreach ( $presets as $preset ) {
			if ( is_array( $preset ) && ! empty( $preset['id'] ) ) {
				$indexed[ (string) $preset['id'] ] = $preset;
			}
		}

		return $indexed;
	}

	/**
	 * Preset.
	 *
	 * @param string $id Id.
	 */
	public function get_preset( string $id ): ?array {
		$presets = $this->get_presets();
		return $presets[ $id ] ?? null;
	}

	/**
	 * Count presets.
	 */
	public function count_presets(): int {
		return count( $this->get_presets() );
	}

	/**
	 * Generate content for a preset.
	 *
	 * @return array{content:string, preset_id:string, module:string, provider:string, model:string}
	 * @param int    $user_id * @param string               $preset_id.
	 * @param string $preset_id Preset id.
	 * @param array  $values * @return array{content:string, preset_id:string, module:string, provider:string, model:string}.
	 * @throws InvalidArgumentException When an error occurs.
	 */
	public function generate( int $user_id, string $preset_id, array $values = array() ): array {
		$preset = $this->get_preset( $preset_id );
		if ( ! $preset ) {
			throw new InvalidArgumentException( 'Unknown content preset: ' . esc_html( $preset_id ) );
		}

		$prompt = $this->prompt_for( $preset, $values );

		$result = zeko_ai()->get_writer()->complete(
			$prompt,
			array(
				'max_tokens'  => 600,
				'temperature' => 0.7,
			)
		);

		// Offline providers (keyless Mock and the self-training agent) cannot.
		// write novel prose, so substitute the deterministic draft composer.
		// Sites with a keyed LLM always get real generated copy instead, and.
		// an empty answer falls back to the composer as a safety net.
		$served  = strtolower( (string) ( $result['provider'] ?? '' ) );
		$content = in_array( $served, array( 'mock', 'agent' ), true )
			? $this->compose_draft( $preset_id, $values )
			: (string) $result['content'];

		if ( '' === trim( $content ) ) {
			$content = $this->compose_draft( $preset_id, $values );
		}

		zeko_ai()->record_usage( $user_id, 'content', $result );

		return array(
			'content'   => $content,
			'preset_id' => $preset_id,
			'module'    => (string) ( $preset['module'] ?? '' ),
			'provider'  => (string) ( $result['provider'] ?? '' ) !== '' ? (string) $result['provider'] : zeko_ai()->get_writer()->name(),
			'model'     => (string) $result['model'],
		);
	}

	/**
	 * Deterministic, offline draft for a preset. Prefers an explicit
	 * 'draft' blueprint on the preset itself, then the built-in map, then a
	 * generic fallback built from the preset's own labels and fields.
	 *
	 * @param string $preset_id Preset id.
	 * @param array  $values Values.
	 * @throws InvalidArgumentException When an error occurs.
	 */
	public function compose_draft( string $preset_id, array $values = array() ): string {
		$preset = $this->get_preset( $preset_id );
		if ( ! $preset ) {
			throw new InvalidArgumentException( 'Unknown content preset: ' . esc_html( $preset_id ) );
		}

		$lines = array();
		foreach ( $this->blueprint_for( $preset ) as $template ) {
			$line = $this->interpolate( (string) $template, $values );
			if ( '' === trim( $line ) ) {
				continue;
			}
			$lines[] = $line;
		}

		$draft = implode( "\n\n", $lines );

		return '' !== trim( $draft )
			? trim( $draft )
			: (string) ( $preset['label'] ?? $preset_id );
	}

	/**
	 * Resolve the draft blueprint for a preset: its own 'draft' key wins
	 * (letting third-party presets customize offline copy), then the built-in
	 * map, then a generic fallback built from the preset structure.
	 *
	 * @return string[]
	 * @param array $preset * @return string[].
	 */
	private function blueprint_for( array $preset ): array {
		$draft = (array) ( $preset['draft'] ?? array() );
		if ( $draft ) {
			return $draft;
		}

		$id = (string) ( $preset['id'] ?? '' );
		if ( isset( self::BLUEPRINTS[ $id ] ) ) {
			return self::BLUEPRINTS[ $id ];
		}

		$lines   = array();
		$lines[] = (string) ( $preset['label'] ?? __( 'Draft', 'zeko-ai' ) );
		if ( ! empty( $preset['description'] ) ) {
			$lines[] = (string) $preset['description'];
		}
		foreach ( (array) ( $preset['fields'] ?? array() ) as $field ) {
			$key = (string) ( $field['key'] ?? '' );
			if ( '' !== $key ) {
				$lines[] = '- {' . $key . '|bullets}';
			}
		}

		return array_filter( $lines );
	}

	/**
	 * Interpolate one draft template line. {key} becomes the trimmed inline
	 * value (newlines collapsed); {key|bullets} becomes one "- item" line per
	 * non-empty line of the value. Unknown keys and empty bullets disappear.
	 *
	 * @return string
	 * @param string $template * @param array<string,string> $values.
	 * @param array  $values Values.
	 */
	private function interpolate( string $template, array $values ): string {
		$line = preg_replace_callback(
			'/\{([a-z_][a-z0-9_]*)(?:\|(bullets|inline))?\}/i',
			static function ( array $matches ) use ( $values ): string {
				$key   = $matches[1];
				$value = isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
				$mode  = $matches[2] ?? 'inline';

				if ( 'bullets' === $mode ) {
					$items = array();
					foreach ( preg_split( '/\R/', $value ) as $chunk ) {
						$chunk = trim( (string) $chunk );
						if ( '' !== $chunk ) {
							$items[] = '- ' . $chunk;
						}
					}
					return implode( "\n", $items );
				}

				return trim( preg_replace( '/\s+/', ' ', $value ) ?? '' );
			},
			$template
		);

		return is_string( $line ) ? trim( $line ) : '';
	}

	/**
	 * Fill {field} placeholders in a preset's LLM prompt.
	 *
	 * @return string
	 * @param array $preset * @param array<string,string> $values.
	 * @param array $values Values.
	 */
	private function prompt_for( array $preset, array $values ): string {
		$prompt = (string) ( $preset['prompt'] ?? '' );
		foreach ( $values as $key => $value ) {
			$prompt = str_replace( '{' . $key . '}', (string) $value, $prompt );
		}
		return $prompt;
	}
}
