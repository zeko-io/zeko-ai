<?php
/**
 * Zeko AI ecosystem integration.
 *
 * Wires the AI module into the shared Zeko experience:
 *   - primary-nav "AI" menu (versioned reconciliation, self-healing URLs)
 *   - admin bar "AI" node
 *   - unified dashboard tab
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Ecosystem. */
class Zeko_AI_Ecosystem {

	/**
	 * MENU VERSION.
	 *
	 * @var mixed
	 */
	private const MENU_VERSION = '0.1.0';

	private const PAGE_SLUGS = array( 'ai-assistant', 'ai-search', 'ai-recommendations' );

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

		add_action( 'init', array( $this, 'register_nav_menus' ) );
		add_filter( 'zeko_nav_items', array( $this, 'register_nav_items' ) );
		add_action( 'wp_before_admin_bar_render', array( $this, 'add_admin_bar_ai_node' ) );

		// Dashboard tab.
		add_filter( 'zeko_dashboard_tabs', array( $this, 'add_dashboard_tab' ), 10, 1 );
		add_action( 'zeko_dashboard_tab_content_ai', array( $this, 'render_dashboard_tab' ) );

		// Keep stored "AI" menu URLs in sync with the current scheme.
		add_filter( 'wp_nav_menu_objects', array( $this, 'fix_ai_menu_urls' ) );
	}

	/**
	 * Rewrite stored "AI" menu item URLs at render time so they always match
	 * the current site scheme (self-heals http URLs stored before SSL).
	 *
	 * @return array
	 * @param array $items Menu item objects.
	 */
	public function fix_ai_menu_urls( $items ) {
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || empty( $item->url ) ) {
				continue;
			}
			$path = trim( (string) wp_parse_url( (string) $item->url, PHP_URL_PATH ), '/' );
			if ( in_array( $path, self::PAGE_SLUGS, true ) ) {
				$item->url = zeko_ai_page_url( $path );
			}
		}
		return $items;
	}

	/**
	 * Nav menus.
	 */
	public function register_nav_menus(): void {
		register_nav_menus(
			array(
				'zeko-ai' => __( 'Zeko AI', 'zeko-ai' ),
			)
		);
	}

	/**
	 * Register AI nav items via the core zeko_nav_items registry.
	 *
	 * @param array $locations Locations.
	 */
	public function register_nav_items( array $locations ): array {
		$children = array();
		foreach ( self::PAGE_SLUGS as $slug ) {
			$children[] = array(
				'title' => ucwords( str_replace( '-', ' ', substr( $slug, 3 ) ) ),
				'url'   => zeko_ai_page_url( $slug ),
			);
		}

		$locations['primary'][] = array(
			'title'    => __( 'AI', 'zeko-ai' ),
			'url'      => zeko_ai_page_url( 'ai-assistant' ),
			'order'    => 9,
			'children' => $children,
		);
		return $locations;
	}

	/**
	 * Append an "AI" item with child pages to the primary nav.
	 *
	 * @deprecated Use register_nav_items() via the zeko_nav_items filter.
	 */
	public function maybe_create_nav_menu_items(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$done = get_option( 'zeko_ai_menu_version', '' );
		if ( self::MENU_VERSION === $done ) {
			return;
		}

		$locations = get_theme_mod( 'nav_menu_locations' );
		$primary   = is_array( $locations ) ? ( $locations['primary'] ?? 0 ) : 0;
		if ( ! $primary ) {
			update_option( 'zeko_ai_menu_version', self::MENU_VERSION );
			return;
		}

		$menu = wp_get_nav_menu_object( $primary );
		if ( ! $menu ) {
			update_option( 'zeko_ai_menu_version', self::MENU_VERSION );
			return;
		}

		// Reconcile the AI child items, then the parent.
		$this->reconcile_ai_children( $menu->term_id );
		$this->reconcile_ai_parent( $menu->term_id );

		update_option( 'zeko_ai_menu_version', self::MENU_VERSION );
	}

	/**
	 * Reconcile ai parent.
	 *
	 * @param int $menu_id Menu id.
	 */
	private function reconcile_ai_parent( int $menu_id ): void {
		$items     = wp_get_nav_menu_items( $menu_id );
		$parent    = null;
		$parent_id = 0;

		foreach ( (array) $items as $item ) {
			if ( 'AI' === (string) $item->title && 0 === (int) $item->menu_item_parent ) {
				$parent = $item;
				break;
			}
		}

		if ( ! $parent ) {
			$parent_id = wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'    => __( 'AI', 'zeko-ai' ),
					'menu-item-url'      => zeko_ai_page_url( 'ai-assistant' ),
					'menu-item-status'   => 'publish',
					'menu-item-position' => 100,
				)
			);
		} else {
			$parent_id = (int) $parent->ID;
			if ( zeko_ai_page_url( 'ai-assistant' ) !== (string) $parent->url ) {
				wp_update_nav_menu_item(
					$menu_id,
					$parent_id,
					array(
						'menu-item-title'  => __( 'AI', 'zeko-ai' ),
						'menu-item-url'    => zeko_ai_page_url( 'ai-assistant' ),
						'menu-item-status' => 'publish',
					)
				);
			}
		}

		if ( $parent_id ) {
			update_post_meta( $parent_id, '_menu_item_class', 'menu-item-zeko-ai' );
		}
	}

	/**
	 * Reconcile ai children.
	 *
	 * @param int $menu_id Menu id.
	 */
	private function reconcile_ai_children( int $menu_id ): void {
		$items = wp_get_nav_menu_items( $menu_id );

		foreach ( self::PAGE_SLUGS as $slug ) {
			$page_url  = zeko_ai_page_url( $slug );
			$found     = null;
			$parent_id = 0;

			foreach ( (array) $items as $item ) {
				if ( (string) $item->url === $page_url && 'AI' !== (string) $item->title ) {
					$found = $item;
					break;
				}
			}

			if ( $found ) {
				$parent_id     = (int) $found->ID;
				$desired       = (int) ( ( $found->menu_item_parent ) ? (int) $found->menu_item_parent : 0 );
				$parent_marker = $this->find_ai_parent( $items );
				if ( (int) $found->menu_item_parent !== $parent_marker ) {
					wp_update_nav_menu_item(
						$menu_id,
						$parent_id,
						array(
							'menu-item-title'     => (string) $found->title,
							'menu-item-url'       => $page_url,
							'menu-item-status'    => 'publish',
							'menu-item-parent-id' => $parent_marker,
							'menu-item-positions' => '',
						)
					);
				}
				continue;
			}

			$parent_marker = $this->find_ai_parent( $items );
			wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'     => ucwords( str_replace( '-', ' ', substr( $slug, 3 ) ) ),
					'menu-item-url'       => $page_url,
					'menu-item-status'    => 'publish',
					'menu-item-parent-id' => $parent_marker,
				)
			);
		}
	}

	/**
	 * Find ai parent.
	 *
	 * @param array $items Items.
	 */
	private function find_ai_parent( array $items ): int {
		foreach ( $items as $item ) {
			if ( 'AI' === (string) $item->title && 0 === (int) $item->menu_item_parent ) {
				return (int) $item->ID;
			}
		}
		return 0;
	}

	/**
	 * Add an "AI" node to the admin bar.
	 */
	public function add_admin_bar_ai_node(): void {
		global $wp_admin_bar;
		if ( ! is_object( $wp_admin_bar ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'zeko-ai',
				'title' => __( 'AI', 'zeko-ai' ),
				'href'  => zeko_ai_page_url( 'ai-assistant' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'zeko-ai-assistant',
				'parent' => 'zeko-ai',
				'title'  => __( 'Assistant', 'zeko-ai' ),
				'href'   => zeko_ai_page_url( 'ai-assistant' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'zeko-ai-search',
				'parent' => 'zeko-ai',
				'title'  => __( 'Search', 'zeko-ai' ),
				'href'   => zeko_ai_page_url( 'ai-search' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'zeko-ai-recommendations',
				'parent' => 'zeko-ai',
				'title'  => __( 'Recommendations', 'zeko-ai' ),
				'href'   => zeko_ai_page_url( 'ai-recommendations' ),
			)
		);
	}

	/**
	 * Add an "AI" tab to the Zeko dashboard.
	 *
	 * @return array
	 * @param array $tabs Existing tabs.
	 */
	public function add_dashboard_tab( array $tabs ): array {
		$tabs['ai'] = __( 'AI', 'zeko-ai' );
		return $tabs;
	}

	/**
	 * Render the "AI" dashboard tab content.
	 */
	public function render_dashboard_tab(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$conversations   = $this->db->count_conversations( $user_id );
		$recommendations = $this->db->count_recommendations( $user_id );

		echo '<div class="zeko-ai-tab" style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">';
		foreach ( array(
			array( (string) number_format_i18n( $conversations ), __( 'My AI conversations', 'zeko-ai' ) ),
			array( (string) number_format_i18n( $recommendations ), __( 'Saved recommendations', 'zeko-ai' ) ),
		) as $stat ) {
			echo '<div style="background:var(--color-surface,#fff);border:1px solid var(--color-border,#e2e8f0);border-radius:12px;padding:12px 18px;text-align:center;flex:1;min-width:120px;">';
			echo '<div style="font-size:22px;font-weight:700;color:#4f46e5;">' . esc_html( $stat[0] ) . '</div>';
			echo '<div style="font-size:12px;color:var(--color-text-secondary,#64748b);">' . esc_html( $stat[1] ) . '</div>';
			echo '</div>';
		}
		echo '</div>';

		echo '<p style="margin:0;"><a class="btn" href="' . esc_url( zeko_ai_page_url( 'ai-assistant' ) ) . '">' . esc_html__( 'Open the AI assistant', 'zeko-ai' ) . '</a> '
			. '<a class="btn btn-secondary" href="' . esc_url( zeko_ai_page_url( 'ai-search' ) ) . '">' . esc_html__( 'Search the ecosystem', 'zeko-ai' ) . '</a> '
			. '<a class="btn btn-secondary" href="' . esc_url( zeko_ai_page_url( 'ai-recommendations' ) ) . '">' . esc_html__( 'My recommendations', 'zeko-ai' ) . '</a></p>';
	}

	/**
	 * AI shortcode pages as menu-ready objects.
	 *
	 * @return object[]
	 */
	public function get_pages(): array {
		$pages = array();
		foreach ( self::PAGE_SLUGS as $slug ) {
			$page = class_exists( 'Zeko_Core_Helpers' )
				? Zeko_Core_Helpers::get_instance()->get_page_by_slug( $slug )
				: get_page_by_path( $slug );
			if ( ! $page ) {
				continue;
			}
			$pages[] = (object) array(
				'ID'   => (int) $page->ID,
				'url'  => zeko_ai_page_url( $slug ),
				'slug' => $slug,
			);
		}
		return $pages;
	}
}
