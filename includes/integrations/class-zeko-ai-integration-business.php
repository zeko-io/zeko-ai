<?php
/**
 * Zeko AI integration for the Business Directory.
 *
 * Feeds live business data into the agent's unified search (so "cafés near
 * me" and "show restaurants" resolve to real directory listings) and the AI
 * recommendation engine (top businesses by the directory's own ranking).
 * Every call is guarded so the integration is inert until the business
 * plugin is active, and it degrades to an empty result set on any error.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Integration_Business. */
class Zeko_AI_Integration_Business extends Zeko_AI_Integration {

	/**
	 * Hooks.
	 */
	protected function hooks(): void {
		add_action( 'zbe_business_created', array( $this, 'on_business_created' ), 10, 2 );
	}

	/**
	 * Moderate a freshly-created business listing.
	 *
	 * @param object $business Business object.
	 * @param int    $owner_id Owner id.
	 */
	public function on_business_created( object $business, int $owner_id ): void {
		$name        = trim( (string) ( $business->name ?? '' ) );
		$description = trim( wp_strip_all_tags( (string) ( $business->extra_data['description'] ?? '' ) ) );
		if ( '' === $name && '' === $description ) {
			return;
		}
		$this->moderate( $owner_id, 'business', 'business', (int) ( $business->id ?? 0 ), trim( $name . "\n\n" . $description ) );
	}

	/**
	 * Whether the business plugin's repository is available.
	 */
	private function available(): bool {
		return class_exists( '\ZBE\Core\Services' ) && is_callable( array( '\ZBE\Core\Services', 'businesses' ) );
	}

	/**
	 * Repo.
	 *
	 * @return \ZBE\Contracts\Repository\BusinessRepositoryInterface|null
	 */
	private function repo() {
		if ( ! $this->available() ) {
			return null;
		}
		return \ZBE\Core\Services::businesses();
	}

	/**
	 * Canonical public URL for a single business page.
	 *
	 * @param object $biz Biz.
	 */
	private function business_url( object $biz ): string {
		return home_url( '/businesses/' . rawurlencode( (string) $biz->slug ) . '/' );
	}

	/**
	 * Short human summary of a business for AI snippets.
	 *
	 * @param object $biz Biz.
	 */
	private function describe( object $biz ): string {
		$bits = array();
		if ( '' !== (string) $biz->city ) {
			$bits[] = (string) $biz->city;
		}
		if ( (float) $biz->avg_rating > 0 ) {
			$bits[] = '★ ' . number_format_i18n( (float) $biz->avg_rating, 1 ) . ' (' . (int) $biz->review_count . ' reviews)';
		}
		if ( $biz->is_verified ) {
			$bits[] = 'verified';
		}
		if ( $biz->is_featured ) {
			$bits[] = 'featured';
		}
		if ( $biz->is_sponsored ) {
			$bits[] = 'sponsored';
		}
		if ( ! empty( $bits ) ) {
			return implode( ' • ', array_unique( $bits ) );
		}
		return (string) $biz->name;
	}

	// ── Assistant context ──────────────────────────────────────────────.

	/**
	 * Context.
	 *
	 * @param array $blocks Blocks.
	 */
	public function context( array $blocks ): array {
		$repo = $this->repo();
		if ( ! $repo || ! is_user_logged_in() ) {
			return $blocks;
		}

		$owned = $repo->get_by_owner( get_current_user_id(), 20 );
		$items = array();
		foreach ( $owned as $biz ) {
			if ( 'active' !== $biz->status ) {
				continue;
			}
			$items[] = array(
				'title'   => (string) $biz->name,
				'url'     => $this->business_url( $biz ),
				'snippet' => $this->describe( $biz ),
			);
		}

		if ( $items ) {
			$blocks[] = $this->context_block( 'business', 'Your businesses', $items );
		}

		return $blocks;
	}

	// ── Content presets ────────────────────────────────────────────────.

	/**
	 * Presets.
	 *
	 * @param array $presets Presets.
	 */
	public function presets( array $presets ): array {
		$presets[] = array(
			'id'          => 'business_description',
			'module'      => 'business',
			'label'       => __( 'Business description', 'zeko-ai' ),
			'description' => __( 'Turn rough notes into a polished business profile description.', 'zeko-ai' ),
			'prompt'      => "Write a warm, welcoming business description in a friendly community voice. Mention what the business is, the experience customers can expect, and why it stands out locally.\n\nNotes from the owner:\n{notes}",
			'fields'      => array(
				array(
					'key'         => 'notes',
					'label'       => __( 'Your notes', 'zeko-ai' ),
					'type'        => 'textarea',
					'placeholder' => __( 'What you offer, who it is for, what makes it special…', 'zeko-ai' ),
				),
			),
			'target'      => 'textarea[name="description"], textarea[name="summary"]',
		);

		$presets[] = array(
			'id'          => 'service_description',
			'module'      => 'business',
			'label'       => __( 'Service description', 'zeko-ai' ),
			'description' => __( 'Describe a single service your business offers.', 'zeko-ai' ),
			'prompt'      => "Write a clear, inviting description for this service. Cover what the customer gets, how long or involved it is, and when to book it.\n\nService name: {service}\nNotes: {notes}",
			'fields'      => array(
				array(
					'key'         => 'service',
					'label'       => __( 'Service name', 'zeko-ai' ),
					'type'        => 'text',
					'placeholder' => __( 'e.g. House call — hair styling', 'zeko-ai' ),
				),
				array(
					'key'         => 'notes',
					'label'       => __( 'Your notes', 'zeko-ai' ),
					'type'        => 'textarea',
					'placeholder' => __( 'What the service includes, duration, inclusions…', 'zeko-ai' ),
				),
			),
			'target'      => 'textarea[name="description"], textarea[name="summary"]',
		);

		return $presets;
	}

	// ── Entity resolution ──────────────────────────────────────────────.

	/**
	 * Entity resolvers.
	 *
	 * @param array $resolvers Resolvers.
	 */
	public function entity_resolvers( array $resolvers ): array {
		$resolvers[] = function ( string $query ): ?array {
			return $this->resolve_business( $query );
		};
		return $resolvers;
	}

	/**
	 * Resolve a query naming a specific active business into a rich entity
	 * card (all the directory data we can read about it) plus tab-linked
	 * actions. Returns null unless the query confidently contains the
	 * business name, so "find me a cafe" stays a corpus search.
	 *
	 * @param string $query Query.
	 */
	private function resolve_business( string $query ): ?array {
		$repo = $this->repo();
		if ( ! $repo ) {
			return null;
		}

		$businesses = $repo->find(
			array(
				'status'   => 'active',
				'per_page' => 200,
				'orderby'  => 'directory',
			)
		);

		$best    = null;
		$bestrow = null;
		$bestfit = 0.0;
		foreach ( $businesses as $biz ) {
			$fit = $this->entity_match_score( $query, (string) $biz->name );
			if ( $fit > $bestfit ) {
				$bestfit = $fit;
				$bestrow = $biz;
			}
			if ( $fit >= 1.0 ) {
				break;
			}
		}

		if ( null === $bestrow || $bestfit < 0.6 ) {
			return null;
		}

		$best = $this->business_card( $bestrow, $query );
		return $best ? $best : null;
	}

	/**
	 * Build the full detail card for one business.
	 *
	 * @param object $biz Biz.
	 * @param string $query Query.
	 */
	private function business_card( object $biz, string $query ): array {
		$url       = $this->business_url( $biz );
		$name      = (string) $biz->name;
		$sub_parts = array_filter(
			array(
				__( 'Business', 'zeko-ai' ),
				(string) $biz->city,
			)
		);
		if ( (float) $biz->avg_rating > 0 ) {
			$sub_parts[] = '★ ' . number_format_i18n( (float) $biz->avg_rating, 1 ) . ' (' . (int) $biz->review_count . ' ' . _n( 'review', 'reviews', (int) $biz->review_count, 'zeko-ai' ) . ')';
		}
		if ( empty( $sub_parts ) ) {
			$sub_parts[] = $name;
		}

		$badges = array();
		if ( $biz->is_verified ) {
			$badges[] = __( 'Verified', 'zeko-ai' );
		}
		if ( $biz->is_featured ) {
			$badges[] = __( 'Featured', 'zeko-ai' );
		}
		if ( $biz->is_sponsored ) {
			$badges[] = __( 'Sponsored', 'zeko-ai' );
		}
		if ( $biz->is_claimed ) {
			$badges[] = __( 'Claimed', 'zeko-ai' );
		}

		$hours = $this->business_hours( $biz );
		$focus = $this->business_focus( $query );

		$facts   = array();
		$address = trim(
			implode(
				', ',
				array_filter( array( (string) $biz->address, (string) $biz->city, (string) $biz->state, (string) $biz->zip, (string) $biz->country ) )
			)
		);
		if ( '' !== $address ) {
			$facts[] = array(
				'label' => __( 'Address', 'zeko-ai' ),
				'value' => $address,
			);
		}
		if ( '' !== (string) $biz->phone ) {
			$facts[] = array(
				'label' => __( 'Phone', 'zeko-ai' ),
				'value' => (string) $biz->phone,
			);
		}
		if ( '' !== (string) $biz->website ) {
			$facts[] = array(
				'label' => __( 'Website', 'zeko-ai' ),
				'value' => (string) $biz->website,
			);
		}
		if ( '' !== (string) $biz->email ) {
			$facts[] = array(
				'label' => __( 'Email', 'zeko-ai' ),
				'value' => (string) $biz->email,
			);
		}
		if ( '' !== (string) $biz->whatsapp ) {
			$facts[] = array(
				'label' => __( 'WhatsApp', 'zeko-ai' ),
				'value' => (string) $biz->whatsapp,
			);
		}
		if ( ! empty( $hours['summary'] ) && 'hours' !== $focus ) {
			$facts[] = array(
				'label' => __( 'Opening hours', 'zeko-ai' ),
				'value' => $hours['summary'] . ' · ' . $hours['state'],
			);
		}
		if ( (float) $biz->avg_rating > 0 ) {
			$facts[] = array(
				'label' => __( 'Rating', 'zeko-ai' ),
				'value' => '★ ' . number_format_i18n( (float) $biz->avg_rating, 1 ) . ' from ' . (int) $biz->review_count . ' ' . _n( 'review', 'reviews', (int) $biz->review_count, 'zeko-ai' ),
			);
		}
		if ( (int) $biz->follower_count > 0 ) {
			$facts[] = array(
				'label' => __( 'Followers', 'zeko-ai' ),
				'value' => (string) (int) $biz->follower_count,
			);
		}
		if ( (int) $biz->view_count > 0 ) {
			$facts[] = array(
				'label' => __( 'Profile views', 'zeko-ai' ),
				'value' => (string) (int) $biz->view_count,
			);
		}
		if ( '' !== (string) $biz->plan && 'free' !== (string) $biz->plan ) {
			$facts[] = array(
				'label' => __( 'Plan', 'zeko-ai' ),
				'value' => ucfirst( (string) $biz->plan ),
			);
		}

		// Read the linked WordPress post for the owner-written description.
		// (the directory stores a human intro there in addition to columns).
		$description = '';
		if ( ! empty( $biz->extra_data['description'] ) ) {
			$description = (string) $biz->extra_data['description'];
		} elseif ( (int) $biz->post_id > 0 ) {
			$post = get_post( (int) $biz->post_id );
			if ( $post && '' !== (string) $post->post_content ) {
				$description = wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 48 );
			}
		}

		$sections = array();

		$service_list = $this->business_services( $biz );
		if ( $service_list ) {
			$sections[] = array(
				'heading' => __( 'Services', 'zeko-ai' ),
				'lines'   => $service_list,
			);
		}

		$product_count = $this->business_product_count( $biz );

		$actions = array(
			$this->entity_action( 'business_view', __( 'View page', 'zeko-ai' ), $url ),
		);
		if ( $service_list ) {
			$actions[] = $this->entity_action( 'business_services', __( 'See services', 'zeko-ai' ), $url . '#zbp-tab-services' );
		}
		if ( $product_count > 0 ) {
			$actions[] = $this->entity_action( 'business_products', __( 'See products', 'zeko-ai' ), $url . '#zbp-tab-products' );
		}
		$actions[] = $this->entity_action( 'business_review', __( 'Submit a review', 'zeko-ai' ), $url . '#zbp-tab-reviews' );
		$actions[] = $this->entity_action( 'business_question', __( 'Ask a question', 'zeko-ai' ), $url . '#zbp-tab-qa' );
		$actions[] = $this->entity_action( 'business_jobs', __( 'See active jobs', 'zeko-ai' ), $url . '#zbp-tab-jobs' );
		if ( ! empty( $hours['summary'] ) ) {
			$actions[] = $this->entity_action( 'business_hours', __( 'See opening hours', 'zeko-ai' ), $url . '#zbp-tab-overview' );
		}

		$geo = '';
		if ( (float) $biz->lat > 0 && (float) $biz->lng > 0 ) {
			$geo = (string) $biz->lat . ',' . (string) $biz->lng;
		} elseif ( '' !== $address ) {
			$geo = $address;
		}
		if ( '' !== $geo ) {
			$actions[] = $this->entity_action( 'business_map', __( 'View on Google Maps', 'zeko-ai' ), 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $name . ' ' . $address ) );
		}
		if ( '' !== (string) $biz->website ) {
			$actions[] = $this->entity_action( 'business_website', __( 'Visit website', 'zeko-ai' ), (string) $biz->website );
		}
		if ( '' !== (string) $biz->phone ) {
			$phone     = preg_replace( '/[^\d+]/', '', (string) $biz->phone );
			$actions[] = $this->entity_action( 'business_call', __( 'Call business', 'zeko-ai' ), 'tel:' . $phone );
		}

		return $this->entity_card(
			'business',
			__( 'Business', 'zeko-ai' ),
			$name,
			$url,
			max( 0.0, min( 1.0, $this->entity_match_score( $query, $name ) ) ),
			array(
				'subtitle'  => implode( ' • ', $sub_parts ),
				'badges'    => $badges,
				'facts'     => $facts,
				'sections'  => $sections,
				'actions'   => $actions,
				'web_terms' => array( $name, '' !== (string) $biz->city ? $name . ' ' . (string) $biz->city : $name ),
				'focus'     => $focus,
			)
		);
	}

	/**
	 * Opening hours summary, "today" line and live open/closed state for a
	 * business. Uses the hours table when present, else the JSON mirror.
	 *
	 * @param object $biz Biz.
	 */
	private function business_hours( object $biz ): array {
		$empty = array(
			'summary' => '',
			'today'   => '',
			'state'   => __( 'Hours not listed', 'zeko-ai' ),
		);

		$rows = array();
		if ( class_exists( '\ZBE\Core\Services' ) && is_callable( array( '\ZBE\Core\Services', 'hours' ) ) ) {
			$rows = \ZBE\Core\Services::hours()->get_for_business( (int) $biz->id );
		}
		if ( empty( $rows ) && ! empty( $biz->business_hours ) ) {
			$day_names = array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );
			foreach ( $biz->business_hours as $k => $def ) {
				$def = (array) $def;
				if ( is_string( $k ) ) {
					$dow = array_search( mb_strtolower( $k ), $day_names, true );
					$dow = false === $dow ? -1 : (int) $dow;
				} else {
					$dow = (int) ( $def['day'] ?? $def['day_of_week'] ?? -1 );
				}
				if ( $dow < 0 || $dow > 6 ) {
					continue;
				}
				$rows[] = array(
					'day_of_week' => $dow,
					'open_time'   => (string) ( $def['open'] ?? $def['open_time'] ?? '' ),
					'close_time'  => (string) ( $def['close'] ?? $def['close_time'] ?? '' ),
					'is_closed'   => ! empty( $def['closed'] ),
				);
			}
		}
		if ( empty( $rows ) ) {
			return $empty;
		}
		if ( 'closed' === (string) $biz->hours_mode ) {
			return array(
				'summary' => __( 'Closed', 'zeko-ai' ),
				'today'   => '',
				'state'   => __( 'Currently closed', 'zeko-ai' ),
			);
		}

		$days = array( __( 'Sun', 'zeko-ai' ), __( 'Mon', 'zeko-ai' ), __( 'Tue', 'zeko-ai' ), __( 'Wed', 'zeko-ai' ), __( 'Thu', 'zeko-ai' ), __( 'Fri', 'zeko-ai' ), __( 'Sat', 'zeko-ai' ) );
		$tz   = '' !== (string) $biz->timezone && function_exists( 'wp_timezone' ) ? (string) $biz->timezone : (string) wp_timezone()->getName();
		$zone = null;
		try {
			$zone = new DateTimeZone( $tz );
		} catch ( Exception $e ) {
			$zone = null;
		}
		$zone_fmt = $zone instanceof DateTimeZone ? $zone : new DateTimeZone( 'UTC' );
		$dow      = (int) wp_date( 'w', time(), $zone_fmt );
		$hm       = (string) wp_date( 'H:i', time(), $zone_fmt );

		$by_dow = array();
		foreach ( $rows as $row ) {
			$row = (array) $row;
			$by_dow[ (int) ( $row['day_of_week'] ?? 0 ) ][] = $row;
		}

		$summary = array();
		foreach ( $by_dow as $d => $list ) {
			$parts = array();
			foreach ( $list as $row ) {
				if ( ! empty( $row['is_closed'] ) ) {
					$parts[] = __( 'Closed', 'zeko-ai' );
				} else {
					$parts[] = mb_substr( (string) ( $row['open_time'] ?? '' ), 0, 5 ) . '–' . mb_substr( (string) ( $row['close_time'] ?? '' ), 0, 5 );
				}
			}
			$summary[] = $days[ $d ] . ' ' . implode( ', ', $parts );
		}
		$summary_str = implode( ' · ', $summary );

		$today_state = '';
		if ( ! empty( $by_dow[ $dow ] ) ) {
			foreach ( $by_dow[ $dow ] as $row ) {
				if ( ! empty( $row['is_closed'] ) ) {
					$today_state = __( 'Closed today', 'zeko-ai' );
					break;
				}
				$open         = mb_substr( (string) $row['open_time'], 0, 5 );
				$close        = mb_substr( (string) $row['close_time'], 0, 5 );
				$is_open_now  = $this->hm_in_range( $hm, $open, $close );
				$today_state  = $is_open_now ? __( 'Open now', 'zeko-ai' ) : __( 'Closed now', 'zeko-ai' );
				$today_state .= ' (' . $days[ $dow ] . ' ' . $open . '–' . $close . ')';
				break;
			}
		}
		if ( '' === $today_state ) {
			$today_state = __( 'Hours not listed for today', 'zeko-ai' );
		}

		return array(
			'summary' => $summary_str,
			'today'   => $today_state,
			'state'   => $today_state,
		);
	}

	/**
	 * True when $now hh:mm falls in [open, close), handling overnight spans.
	 *
	 * @param string $now Now.
	 * @param string $open Open.
	 * @param string $close Close.
	 */
	private function hm_in_range( string $now, string $open, string $close ): bool {
		$n = 60 * (int) substr( $now, 0, 2 ) + (int) substr( $now, 3, 2 );
		$o = 60 * (int) substr( $open, 0, 2 ) + (int) substr( $open, 3, 2 );
		$c = 60 * (int) substr( $close, 0, 2 ) + (int) substr( $close, 3, 2 );
		if ( $c <= $o ) {
			return $n >= $o || $n < $c; // Overnight shift.
		}
		return $n >= $o && $n < $c;
	}

	/**
	 * Top active services of a business, as display lines.
	 *
	 * @param object $biz Biz.
	 */
	private function business_services( object $biz ): array {
		if ( ! class_exists( '\ZBE\Core\Services' ) || ! is_callable( array( '\ZBE\Core\Services', 'services_repo' ) ) ) {
			return array();
		}
		$services = \ZBE\Core\Services::services_repo()->get_by_business( (int) $biz->id );
		if ( empty( $services ) ) {
			return array();
		}
		$lines = array();
		foreach ( array_slice( $services, 0, 5 ) as $svc ) {
			$bits = array( (string) $svc->name );
			if ( (float) $svc->price > 0 ) {
				$bits[] = number_format_i18n( (float) $svc->price ) . ( '' !== (string) $svc->price_type ? ' (' . (string) $svc->price_type . ')' : '' );
			}
			if ( '' !== (string) $svc->duration ) {
				$bits[] = (string) $svc->duration;
			}
			$lines[] = implode( ' — ', $bits );
		}
		return $lines;
	}

	/**
	 * Count of the business's active products.
	 *
	 * @param object $biz Biz.
	 */
	private function business_product_count( object $biz ): int {
		if ( ! class_exists( '\ZBE\Repository\ProductRepository' ) ) {
			return 0;
		}
		try {
			$repo = new \ZBE\Repository\ProductRepository();
			return (int) $repo->count_by_business( (int) $biz->id );
		} catch ( Exception $e ) {
			return 0;
		}
	}

	/**
	 * Which detail the query is after, so the card leads with the right
	 * fact (hours questions get the hours summary up front).
	 *
	 * @param string $query Query.
	 */
	private function business_focus( string $query ): string {
		$low = mb_strtolower( (string) $query );
		foreach ( array( 'closing', 'open now', 'opening hours', 'business hours', 'what time', 'when do they', 'hours', 'open until', 'close' ) as $needle ) {
			if ( false !== mb_strpos( $low, $needle ) ) {
				return 'hours';
			}
		}
		return '';
	}

	// ── Unified search ─────────────────────────────────────────────────.

	/**
	 * Search sources.
	 *
	 * @param array $sources Sources.
	 */
	public function search_sources( array $sources ): array {
		$repo = $this->repo();
		if ( ! $repo ) {
			return $sources;
		}

		$sources[] = array(
			'type'   => 'business',
			'label'  => __( 'Businesses', 'zeko-ai' ),
			'icon'   => 'dashicons-store',
			'search' => function ( string $term, int $limit ) use ( $repo ): array {
				$out       = array();
				$businesses = $repo->find(
					array(
						'search'   => $term,
						'status'   => 'active',
						'per_page' => max( 1, min( 20, $limit ) ),
						'orderby'  => 'directory',
					)
				);
				foreach ( $businesses as $biz ) {
					$out[] = array(
						'id'      => (int) $biz->id,
						'title'   => (string) $biz->name,
						'excerpt' => $this->describe( $biz ),
						'url'     => $this->business_url( $biz ),
					);
				}
				return $out;
			},
		);

		return $sources;
	}

	// ── Recommendations ────────────────────────────────────────────────.

	/**
	 * Recommendation sources.
	 *
	 * @param array $sources Sources.
	 */
	public function recommendation_sources( array $sources ): array {
		$repo = $this->repo();
		if ( ! $repo ) {
			return $sources;
		}

		$sources[] = array(
			'type'  => 'business',
			'label' => __( 'Businesses', 'zeko-ai' ),
			'items' => function ( int $limit ) use ( $repo ): array {
				$businesses = $repo->find(
					array(
						'status'   => 'active',
						'per_page' => max( 1, min( 100, $limit * 5 ) ),
						'orderby'  => 'directory',
					)
				);
				$out = array();
				foreach ( $businesses as $biz ) {
					$out[] = array(
						'id'      => (int) $biz->id,
						'title'   => (string) $biz->name,
						'excerpt' => $this->describe( $biz ),
						'url'     => $this->business_url( $biz ),
					);
				}
				return $out;
			},
		);

		return $sources;
	}
}
