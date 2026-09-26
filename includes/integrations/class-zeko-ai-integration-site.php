<?php
/**
 * Zeko AI site-content integration.
 *
 * Brings the WP core content the theme renders (posts, pages and any custom
 * post types that opt into search) into the assistant's entity layer, so a
 * member who names a page or article gets a rich card instead of a shallow
 * corpus line. Module entities (businesses, jobs, courses…) live in their own
 * tables and are handled by their dedicated integrations, so they never match
 * here.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Integration_Site. */
class Zeko_AI_Integration_Site extends Zeko_AI_Integration {

	// ── Entity resolution ──────────────────────────────────────────────.

	/**
	 * Entity resolvers.
	 *
	 * @param array $resolvers Resolvers.
	 */
	public function entity_resolvers( array $resolvers ): array {
		$resolvers[] = function ( string $query ): ?array {
			return $this->resolve_site_content( $query );
		};
		return $resolvers;
	}

	/**
	 * Post types this resolver absorbs. Defaults to core content plus any
	 * public, searchable custom types the theme/plugins add — filterable so a
	 * site can exclude its own bespoke types.
	 *
	 * @return string[]
	 */
	private function content_types(): array {
		$types = get_post_types(
			array(
				'public'              => true,
				'exclude_from_search' => false,
			),
			'names'
		);
		$types = array_values(
			array_filter(
				(array) $types,
				static function ( string $type ): bool {
					return 'attachment' !== $type;
				}
			)
		);

		if ( empty( $types ) ) {
			$types = array( 'post', 'page' );
		}

		return (array) apply_filters( 'zeko_ai_site_entity_post_types', $types );
	}

	/**
	 * Resolve a query naming a specific page or article into a detail card.
	 *
	 * @param string $query Query.
	 */
	private function resolve_site_content( string $query ): ?array {
		$posts = get_posts(
			array(
				'post_type'      => $this->content_types(),
				'post_status'    => 'publish',
				'post__not_in'   => array( (int) get_option( 'page_on_front' ) ),
				'posts_per_page' => 500,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'fields'         => 'all',
			)
		);

		$best    = null;
		$bestrow = null;
		$bestfit = 0.0;
		foreach ( $posts as $post ) {
			// Strict matching on purpose: site pages are numerous and their.
			// titles overlap everyday language, so a page only resolves when.
			// the member names it in full. Full-title containment scores 1.0;.
			// a full multi-token slug match ("the shipping-whisky article" vs.
			// /shipping-whisky-ocean/) scores slightly lower. Single-token.
			// titles/slugs (Jobs, Rewards, Wallet…) are never matched — those.
			// are theme nav pages already covered by module links and corpus.
			$fit    = 0.0;
			$norm_q = $this->entity_norm( $query );
			$norm_t = $this->entity_norm( (string) $post->post_title );
			$norm_s = $this->entity_norm( (string) $post->post_name );

			if ( count( $this->entity_tokens( $norm_t ) ) >= 2 && false !== mb_strpos( $norm_q, $norm_t ) ) {
				$fit = 1.0;
			} elseif ( count( $this->entity_tokens( $norm_s ) ) >= 2 && false !== mb_strpos( $norm_q, $norm_s ) ) {
				$fit = 0.8;
			}

			if ( $fit > $bestfit ) {
				$bestfit = $fit;
				$bestrow = $post;
			}
			if ( $fit >= 1.0 ) {
				break;
			}
		}

		if ( null === $bestrow || $bestfit < 0.6 ) {
			return null;
		}

		$title   = (string) $bestrow->post_title;
		$url     = get_permalink( $bestrow->ID );
		$is_post = 'post' === $bestrow->post_type;

		$facts   = array();
		$facts[] = array(
			'label' => __( 'Type', 'zeko-ai' ),
			'value' => $is_post ? __( 'Article', 'zeko-ai' ) : ucfirst( $bestrow->post_type ),
		);

		$author = get_the_author_meta( 'display_name', (int) $bestrow->post_author );
		if ( '' !== $author ) {
			$facts[] = array(
				'label' => __( 'Author', 'zeko-ai' ),
				'value' => $author,
			);
		}

		$date = get_the_date( '', $bestrow );
		if ( '' !== $date ) {
			$facts[] = array(
				'label' => __( 'Published', 'zeko-ai' ),
				'value' => $date,
			);
		}

		if ( $is_post ) {
			$categories = get_the_category( $bestrow->ID );
			if ( ! empty( $categories ) && '' !== (string) $categories[0]->name ) {
				$facts[] = array(
					'label' => __( 'Category', 'zeko-ai' ),
					'value' => (string) $categories[0]->name,
				);
			}
		}

		$badges = array();
		if ( $is_post && is_sticky( $bestrow->ID ) ) {
			$badges[] = __( 'Featured', 'zeko-ai' );
		}

		$sections = array();
		$excerpt  = trim( (string) get_the_excerpt( $bestrow ) );
		if ( '' === $excerpt && '' !== trim( (string) $bestrow->post_content ) ) {
			$excerpt = wp_strip_all_tags( (string) $bestrow->post_content );
		}
		if ( '' !== $excerpt ) {
			$sections[] = array(
				'heading' => __( 'About this page', 'zeko-ai' ),
				'lines'   => array( wp_trim_words( $excerpt, 45 ) ),
			);
		}

		$actions = array(
			$this->entity_action(
				$is_post ? 'site_read_post' : 'site_read_page',
				$is_post ? __( 'Read article', 'zeko-ai' ) : __( 'Open page', 'zeko-ai' ),
				$url
			),
		);
		if ( $is_post ) {
			$actions[] = $this->entity_action( 'site_blog', __( 'More articles', 'zeko-ai' ), get_post_type_archive_link( 'post' ) ?: home_url( '/' ) );
		} else {
			$actions[] = $this->entity_action( 'site_home', __( 'Back to the site', 'zeko-ai' ), home_url( '/' ) );
		}

		return $this->entity_card(
			'site',
			$is_post ? __( 'Article', 'zeko-ai' ) : __( 'Page', 'zeko-ai' ),
			$title,
			$url,
			$bestfit,
			array(
				'badges'    => $badges,
				'facts'     => $facts,
				'sections'  => $sections,
				'actions'   => $actions,
				'web_terms' => array( $title ),
			)
		);
	}
}
