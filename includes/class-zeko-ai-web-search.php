<?php
/**
 * Optional web search for the Community Agent.
 *
 * Lets the agent pull answers from external search engines when its own
 * knowledge base, ecosystem corpus and personal data come up empty. Works
 * immediately with no API key via the free DuckDuckGo endpoint; admins can
 * switch to Google Custom Search, Bing, Brave or Serper and paste a key for
 * better coverage. Every provider is fail-soft: disabled, unconfigured or
 * failed lookups degrade silently to an empty result set so the agent always
 * falls back to its normal answer path.
 *
 * Outbound requests only happen when web search is enabled, so a site that
 * never turns it on sends no queries to third parties.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Web_Search. */
class Zeko_AI_Web_Search {

	/**
	 * Settings.
	 *
	 * @var mixed Settings.
	 */
	private $settings;

	/**
	 * Construct.
	 *
	 * @param array $settings Settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registered providers: slug => admin label. Filterable so sites can
	 * trim the list or plug in a custom provider endpoint.
	 *
	 * @return array<string,string>
	 */
	public static function providers(): array {
		return apply_filters(
			'zeko_ai_web_search_providers',
			array(
				'duckduckgo' => __( 'DuckDuckGo (no key)', 'zeko-ai' ),
				'google'     => __( 'Google Custom Search', 'zeko-ai' ),
				'bing'       => __( 'Bing Web Search', 'zeko-ai' ),
				'brave'      => __( 'Brave Search', 'zeko-ai' ),
				'serper'     => __( 'Serper (Google)', 'zeko-ai' ),
			)
		);
	}

	/**
	 * Whether web search is switched on at all. The 'zeko_ai_web_search_http'
	 * filter lets tests (and privacy-conscious sites) disable all outbound
	 * HTTP while keeping the feature enabled in settings.
	 */
	public function enabled(): bool {
		if ( ! apply_filters( 'zeko_ai_web_search_http', true ) ) {
			return false;
		}
		return ! empty( $this->settings['web_search_enabled'] );
	}

	/**
	 * Whether the active provider has everything it needs. DuckDuckGo is
	 * keyless; the keyed providers require their credentials.
	 */
	public function configured(): bool {
		switch ( $this->provider() ) {
			case 'duckduckgo':
				return true;
			case 'google':
				return '' !== trim( (string) ( $this->settings['google_search_api_key'] ?? '' ) )
					&& '' !== trim( (string) ( $this->settings['google_search_engine_id'] ?? '' ) );
			case 'bing':
				return '' !== trim( (string) ( $this->settings['bing_search_key'] ?? '' ) );
			case 'brave':
				return '' !== trim( (string) ( $this->settings['brave_search_key'] ?? '' ) );
			case 'serper':
				return '' !== trim( (string) ( $this->settings['serper_search_key'] ?? '' ) );
		}
		return false;
	}

	/**
	 * The active provider slug, always one of the registered set.
	 */
	public function provider(): string {
		$provider = sanitize_key( (string) ( $this->settings['web_search_provider'] ?? 'duckduckgo' ) );
		return array_key_exists( $provider, self::providers() ) ? $provider : 'duckduckgo';
	}

	/**
	 * Search the active provider for a query. Returns normalized results:
	 *
	 * @return array<int,array{title:string,excerpt:string,url:string}>
	 * @param string $query Query.
	 * @param int    $limit Limit.
	 */
	public function search( string $query, int $limit = 5 ): array {
		if ( ! $this->enabled() || ! $this->configured() ) {
			return array();
		}

		$query = trim( wp_strip_all_tags( (string) $query ) );
		if ( '' === $query ) {
			return array();
		}
		$limit = max( 1, min( 10, (int) $limit ) );

		$ttl = max( 0, (int) apply_filters( 'zeko_ai_web_search_cache_ttl', 3600 ) );
		$key = 'zeko_ai_web_' . md5( $this->provider() . '|' . mb_strtolower( $query ) . '|' . $limit );
		if ( $ttl > 0 ) {
			$cached = get_transient( $key );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : array();
			}
		}

		$results = $this->run_provider( $query, $limit );

		if ( $ttl > 0 ) {
			set_transient( $key, $results, $ttl );
		}

		return $results;
	}

	/**
	 * Dispatch to the active provider, then normalize + trim the items.
	 *
	 * @return array<int,array{title:string,excerpt:string,url:string}>
	 * @param string $query Query.
	 * @param int    $limit Limit.
	 */
	private function run_provider( string $query, int $limit ): array {
		$method = 'request_' . $this->provider();
		if ( ! is_callable( array( $this, $method ) ) ) {
			return array();
		}

		$items   = call_user_func( array( $this, $method ), $query, $limit );
		$results = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$title   = trim( wp_strip_all_tags( (string) ( $item['title'] ?? '' ) ) );
			$excerpt = trim( wp_strip_all_tags( (string) ( $item['excerpt'] ?? '' ) ) );
			$url     = esc_url_raw( trim( (string) ( $item['url'] ?? '' ) ) );
			if ( '' === $title && '' === $url ) {
				continue;
			}
			$results[] = array(
				'title'   => mb_substr( $title, 0, 240 ),
				'excerpt' => mb_substr( $excerpt, 0, 400 ),
				'url'     => $url,
			);
			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * /** @return array<int,array{title:string,excerpt:string,url:string}> */

	/**
	 * /** @return array<int,array{title:string,excerpt:string,url:string}>
	 *
	 * @param string $query Query.
	 * @param int    $limit Limit.
	 */
	private function request_duckduckgo( string $query, int $limit ): array {
		$url = add_query_arg( array( 'q' => $query ), 'https://html.duckduckgo.com/html/' );

		$html = $this->remote( $url );
		if ( null === $html ) {
			return array();
		}
		if ( false === strpos( $html, 'result__a' ) ) {
			return array();
		}

		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$ok = $doc->loadHTML( $html );
		libxml_clear_errors();
		if ( false === $ok ) {
			return array();
		}

		$titles = array();
		foreach ( $doc->getElementsByTagName( 'a' ) as $anchor ) {
			if ( false !== strpos( (string) $anchor->getAttribute( 'class' ), 'result__a' ) ) {
				$titles[] = array(
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- textContent is a native DOMNode property and cannot be renamed.
					'title' => trim( $anchor->textContent ),
					'href'  => trim( (string) $anchor->getAttribute( 'href' ) ),
				);
			}
		}

		$snippets = array();
		foreach ( $doc->getElementsByTagName( 'a' ) as $anchor ) {
			if ( false !== strpos( (string) $anchor->getAttribute( 'class' ), 'result__snippet' ) ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- textContent is a native DOMNode property and cannot be renamed.
				$snippets[] = trim( $anchor->textContent );
			}
			if ( count( $snippets ) >= count( $titles ) ) {
				break;
			}
		}

		$items = array();
		foreach ( array_slice( $titles, 0, $limit ) as $i => $row ) {
			$items[] = array(
				'title'   => $row['title'],
				'excerpt' => $snippets[ $i ] ?? '',
				'url'     => $this->ddg_final_url( $row['href'] ),
			);
		}

		return $items;
	}

	/**
	 * DuckDuckGo HTML results point at redirect URLs like
	 * //duckduckgo.com/l/?uddg=<encoded>&rut=...; pull the real target out of
	 * the uddg query parameter when present.
	 *
	 * @param string $href Href.
	 */
	private function ddg_final_url( string $href ): string {
		$target = $href;
		if ( false !== stripos( $href, 'uddg=' ) ) {
			$parts = wp_parse_url( $href, PHP_URL_QUERY );
			parse_str( (string) $parts, $query );
			if ( ! empty( $query['uddg'] ) ) {
				$target = urldecode( (string) $query['uddg'] );
			}
		}
		return esc_url_raw( $target );
	}

	/**
	 * /** @return array<int,array{title:string,excerpt:string,url:string}> */

	/**
	 * /** @return array<int,array{title:string,excerpt:string,url:string}>
	 *
	 * @param string $query Query.
	 * @param int    $limit Limit.
	 */
	private function request_google( string $query, int $limit ): array {
		$key = trim( (string) ( $this->settings['google_search_api_key'] ?? '' ) );
		$cx  = trim( (string) ( $this->settings['google_search_engine_id'] ?? '' ) );
		if ( '' === $key || '' === $cx ) {
			return array();
		}

		$url = add_query_arg(
			array(
				'key' => $key,
				'cx'  => $cx,
				'q'   => $query,
				'num' => $limit,
			),
			'https://www.googleapis.com/customsearch/v1'
		);

		$body = $this->get_json( $url );
		if ( ! is_array( $body ) ) {
			return array();
		}

		$items = array();
		foreach ( (array) ( $body['items'] ?? array() ) as $item ) {
			$items[] = array(
				'title'   => (string) ( $item['title'] ?? '' ),
				'excerpt' => (string) ( $item['snippet'] ?? '' ),
				'url'     => (string) ( $item['link'] ?? '' ),
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * /** @return array<int,array{title:string,excerpt:string,url:string}> */

	/**
	 * /** @return array<int,array{title:string,excerpt:string,url:string}>
	 *
	 * @param string $query Query.
	 * @param int    $limit Limit.
	 */
	private function request_bing( string $query, int $limit ): array {
		$key = trim( (string) ( $this->settings['bing_search_key'] ?? '' ) );
		if ( '' === $key ) {
			return array();
		}

		$url = add_query_arg(
			array(
				'q'     => $query,
				'count' => $limit,
			),
			'https://api.bing.microsoft.com/v7.0/search'
		);

		$body = $this->get_json( $url, array( 'Ocp-Apim-Subscription-Key' => $key ) );
		if ( ! is_array( $body ) ) {
			return array();
		}

		$items = array();
		foreach ( (array) ( $body['webPages']['value'] ?? array() ) as $item ) {
			$items[] = array(
				'title'   => (string) ( $item['name'] ?? '' ),
				'excerpt' => (string) ( $item['snippet'] ?? '' ),
				'url'     => (string) ( $item['url'] ?? '' ),
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * /** @return array<int,array{title:string,excerpt:string,url:string}> */

	/**
	 * /** @return array<int,array{title:string,excerpt:string,url:string}>
	 *
	 * @param string $query Query.
	 * @param int    $limit Limit.
	 */
	private function request_brave( string $query, int $limit ): array {
		$key = trim( (string) ( $this->settings['brave_search_key'] ?? '' ) );
		if ( '' === $key ) {
			return array();
		}

		$url = add_query_arg(
			array(
				'q'     => $query,
				'count' => $limit,
			),
			'https://api.search.brave.com/res/v1/web/search'
		);

		$body = $this->get_json( $url, array( 'X-Subscription-Token' => $key ) );
		if ( ! is_array( $body ) ) {
			return array();
		}

		$items = array();
		foreach ( (array) ( $body['web']['results'] ?? array() ) as $item ) {
			$items[] = array(
				'title'   => (string) ( $item['title'] ?? '' ),
				'excerpt' => (string) ( $item['description'] ?? '' ),
				'url'     => (string) ( $item['url'] ?? '' ),
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * /** @return array<int,array{title:string,excerpt:string,url:string}> */

	/**
	 * /** @return array<int,array{title:string,excerpt:string,url:string}>
	 *
	 * @param string $query Query.
	 * @param int    $limit Limit.
	 */
	private function request_serper( string $query, int $limit ): array {
		$key = trim( (string) ( $this->settings['serper_search_key'] ?? '' ) );
		if ( '' === $key ) {
			return array();
		}

		$url      = 'https://google.serper.dev/search';
		$response = apply_filters( 'zeko_ai_web_search_post_request', 'wp_remote_post' );
		if ( ! is_callable( $response ) ) {
			return array();
		}

		try {
			$result = call_user_func(
				$response,
				$url,
				array(
					'timeout' => 12,
					'headers' => array(
						'X-API-KEY'    => $key,
						'Content-Type' => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'q'   => $query,
							'num' => $limit,
						)
					),
				)
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( is_wp_error( $result ) || 200 !== (int) wp_remote_retrieve_response_code( $result ) ) {
			return array();
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $result ), true );
		if ( ! is_array( $body ) ) {
			return array();
		}

		$items = array();
		foreach ( (array) ( $body['organic'] ?? array() ) as $item ) {
			$items[] = array(
				'title'   => (string) ( $item['title'] ?? '' ),
				'excerpt' => (string) ( $item['snippet'] ?? '' ),
				'url'     => (string) ( $item['link'] ?? '' ),
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * GET a URL and return the body text, or null on any failure. The HTTP
	 * function itself is filterable so tests can inject fake responses
	 * (mirrors the Wikipedia lookup seam). A browser-style user agent is
	 * sent so missable HTML endpoints (DuckDuckGo) work as expected.
	 *
	 * @param string $url Url.
	 * @param array  $headers Headers.
	 */
	private function remote( string $url, array $headers = array() ): ?string {
		$request = apply_filters( 'zeko_ai_web_search_request', 'wp_remote_get' );
		if ( ! is_callable( $request ) ) {
			return null;
		}

		$headers['User-Agent'] = 'ZekoCommunityAgent/1.0 (+https://testzeko.test)';

		try {
			$response = call_user_func(
				$request,
				$url,
				array(
					'timeout' => 12,
					'headers' => $headers,
				)
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * GET a URL and decode the JSON body. Null on any failure/failure to
	 * parse.
	 *
	 * @return array|null
	 * @param string $url Url.
	 * @param array  $headers Headers.
	 */
	private function get_json( string $url, array $headers = array() ) {
		$html = $this->remote( $url, $headers );
		if ( null === $html ) {
			return null;
		}

		$body = json_decode( $html, true );
		return is_array( $body ) ? $body : null;
	}
}
