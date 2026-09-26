<?php
/**
 * Zeko Shop integration for Zeko AI.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Integration_Shop. */
class Zeko_AI_Integration_Shop extends Zeko_AI_Integration {

	/**
	 * Hooks.
	 */
	protected function hooks(): void {
	}

	/**
	 * Db.
	 */
	private function db() {
		return zeko_shop()->get_db();
	}

	/**
	 * Product url.
	 */
	private function product_url(): string {
		return zeko_shop_page_url( 'shop' );
	}

	/**
	 * User orders.
	 *
	 * @param int $user_id User id.
	 */
	private function user_orders( int $user_id ): array {
		return $this->db()->get_user_orders( $user_id, 50 );
	}

	/**
	 * Context.
	 *
	 * @param array $blocks Blocks.
	 */
	public function context( array $blocks ): array {
		if ( ! is_user_logged_in() ) {
			return $blocks;
		}
		$user_id = get_current_user_id();

		$orders = $this->user_orders( $user_id );
		$items  = array();
		foreach ( $orders as $order ) {
			$items[] = array(
				'title'   => sprintf( 'Order #%d', (int) ( $order['order_id'] ?? $order['id'] ?? 0 ) ),
				'url'     => zeko_shop_page_url( 'my-orders' ),
				'snippet' => 'Status: ' . (string) ( $order['status'] ?? '' ),
			);
		}
		if ( $items ) {
			$blocks[] = $this->context_block( 'shop', 'Your shop orders', $items );
		}

		return $blocks;
	}

	/**
	 * Presets.
	 *
	 * @param array $presets Presets.
	 */
	public function presets( array $presets ): array {
		$presets[] = array(
			'id'          => 'product_description',
			'module'      => 'shop',
			'label'       => __( 'Product description', 'zeko-ai' ),
			'description' => __( 'Draft a sales-focused product description.', 'zeko-ai' ),
			'prompt'      => "Write a persuasive product description for the Zeko Shop. Lead with the benefit, then the features, then who it is for.\n\nProduct name: {product_name}\nNotes: {notes}",
			'fields'      => array(
				array(
					'key'   => 'product_name',
					'label' => __( 'Product name', 'zeko-ai' ),
					'type'  => 'text',
				),
				array(
					'key'         => 'notes',
					'label'       => __( 'Notes', 'zeko-ai' ),
					'type'        => 'textarea',
					'placeholder' => __( 'What does it do? Who is it for?', 'zeko-ai' ),
				),
			),
			'target'      => 'textarea[name="description"]',
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
			return $this->resolve_product( $query );
		};
		return $resolvers;
	}

	/**
	 * Resolve a query naming a specific shop product into a detail card.
	 *
	 * @param string $query Query.
	 */
	private function resolve_product( string $query ): ?array {
		if ( ! function_exists( 'zeko_shop' ) ) {
			return null;
		}

		$products = $this->db()->get_products(
			array(
				'status' => 'active',
				'limit'  => 500,
			)
		);

		$best    = null;
		$bestrow = null;
		$bestfit = 0.0;
		foreach ( $products as $product ) {
			$fit = $this->entity_match_score( $query, (string) ( $product['title'] ?? '' ) );
			if ( $fit > $bestfit ) {
				$bestfit = $fit;
				$bestrow = $product;
			}
			if ( $fit >= 1.0 ) {
				break;
			}
		}

		if ( null === $bestrow || $bestfit < 0.6 ) {
			return null;
		}

		$title = (string) ( $bestrow['title'] ?? '' );
		$url   = $this->product_url();

		$facts = array();
		if ( isset( $bestrow['price'] ) && '' !== (string) $bestrow['price'] ) {
			$facts[] = array(
				'label' => __( 'Price', 'zeko-ai' ),
				'value' => (string) $bestrow['price'],
			);
		}
		if ( isset( $bestrow['stock'] ) && (int) $bestrow['stock'] > 0 ) {
			$facts[] = array(
				'label' => __( 'In stock', 'zeko-ai' ),
				'value' => (string) (int) $bestrow['stock'],
			);
		}
		if ( isset( $bestrow['status'] ) && '' !== (string) $bestrow['status'] ) {
			$facts[] = array(
				'label' => __( 'Status', 'zeko-ai' ),
				'value' => (string) $bestrow['status'],
			);
		}

		$sections    = array();
		$description = trim( (string) ( $bestrow['description'] ?? '' ) );
		if ( '' !== $description ) {
			$sections[] = array(
				'heading' => __( 'About this product', 'zeko-ai' ),
				'lines'   => array( wp_trim_words( wp_strip_all_tags( $description ), 40 ) ),
			);
		}

		$actions = array(
			$this->entity_action( 'shop_view', __( 'View in shop', 'zeko-ai' ), $url ),
			$this->entity_action( 'shop_buy', __( 'Buy', 'zeko-ai' ), $url ),
			$this->entity_action( 'shop_orders', __( 'My orders', 'zeko-ai' ), zeko_shop_page_url( 'my-orders' ) ),
		);

		return $this->entity_card(
			'product',
			__( 'Product', 'zeko-ai' ),
			$title,
			$url,
			$bestfit,
			array(
				'facts'     => $facts,
				'sections'  => $sections,
				'actions'   => $actions,
				'web_terms' => array( $title ),
			)
		);
	}

	/**
	 * Search sources.
	 *
	 * @param array $sources Sources.
	 */
	public function search_sources( array $sources ): array {
		$sources[] = array(
			'type'   => 'product',
			'label'  => __( 'Shop', 'zeko-ai' ),
			'icon'   => 'dashicons-cart',
			'search' => function ( string $term, int $limit ): array {
				$products = $this->db()->get_products(
					array(
						'search' => $term,
						'status' => 'active',
						'limit'  => $limit,
					)
				);
				$out      = array();
				foreach ( $products as $product ) {
					$out[] = array(
						'id'      => (int) $product['product_id'],
						'title'   => (string) $product['title'],
						'excerpt' => wp_trim_words( (string) ( $product['description'] ?? '' ), 20 ),
						'url'     => $this->product_url(),
					);
				}
				return $out;
			},
		);
		return $sources;
	}

	/**
	 * Recommendation sources.
	 *
	 * @param array $sources Sources.
	 */
	public function recommendation_sources( array $sources ): array {
		$sources[] = array(
			'type'  => 'product',
			'label' => __( 'Shop', 'zeko-ai' ),
			'items' => function ( int $limit ): array {
				$products = $this->db()->get_products(
					array(
						'status' => 'active',
						'limit'  => min( 200, $limit * 5 ),
					)
				);
				$out      = array();
				foreach ( $products as $product ) {
					$out[] = array(
						'id'      => (int) $product['product_id'],
						'title'   => (string) $product['title'],
						'excerpt' => wp_trim_words( (string) ( $product['description'] ?? '' ), 30 ),
						'url'     => $this->product_url(),
					);
				}
				return $out;
			},
		);
		return $sources;
	}

	/**
	 * Moderation sources.
	 *
	 * @param array $sources Sources.
	 */
	public function moderation_sources( array $sources ): array {
		$sources[] = array(
			'source' => 'shop',
			'type'   => 'product',
			'label'  => __( 'Shop products', 'zeko-ai' ),
			'fetch'  => function ( int $limit ): array {
				$products = $this->db()->get_products(
					array(
						'status' => 'active',
						'limit'  => $limit,
					)
				);
				$out      = array();
				foreach ( $products as $product ) {
					$out[] = array(
						'content_id' => (int) $product['product_id'],
						'user_id'    => (int) ( $product['seller_id'] ?? 0 ),
						'title'      => (string) $product['title'],
						'content'    => (string) ( $product['title'] ?? '' ) . "\n\n" . (string) ( $product['description'] ?? '' ),
					);
				}
				return $out;
			},
		);
		return $sources;
	}
}
