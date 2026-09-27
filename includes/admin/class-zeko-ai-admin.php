<?php
/**
 * Zeko AI admin pages: settings, moderation queue, usage analytics.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Admin. */
class Zeko_AI_Admin {

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

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		add_action( 'admin_post_zeko_ai_moderation_action', array( $this, 'handle_moderation_action' ) );
		add_action( 'admin_post_zeko_ai_analytics_clear', array( $this, 'handle_analytics_clear' ) );
		add_action( 'admin_post_zeko_ai_test_provider', array( $this, 'handle_test_provider' ) );
		add_action( 'admin_post_zeko_ai_agent_teach', array( $this, 'handle_agent_teach' ) );
		add_action( 'admin_post_zeko_ai_agent_delete_knowledge', array( $this, 'handle_agent_delete_knowledge' ) );
		add_action( 'admin_post_zeko_ai_agent_delete_query', array( $this, 'handle_agent_delete_query' ) );
		add_action( 'admin_post_zeko_ai_agent_mark_answered', array( $this, 'handle_agent_mark_answered' ) );
		add_action( 'admin_post_zeko_ai_knowledge_export', array( $this, 'handle_knowledge_export' ) );
		add_action( 'admin_post_zeko_ai_knowledge_import', array( $this, 'handle_knowledge_import' ) );
		add_action( 'admin_post_zeko_ai_health_clear', array( $this, 'handle_health_clear' ) );
		add_action( 'admin_post_zeko_ai_memory_delete', array( $this, 'handle_memory_delete' ) );
		add_action( 'admin_post_zeko_ai_memory_clear_user', array( $this, 'handle_memory_clear_user' ) );
	}

	/**
	 * Menu.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Zeko AI', 'zeko-ai' ),
			__( 'Zeko AI', 'zeko-ai' ),
			'manage_options',
			'zeko-ai',
			array( $this, 'render_overview' ),
			'dashicons-superhero',
			59
		);

		add_submenu_page(
			'zeko-ai',
			__( 'AI Moderation Queue', 'zeko-ai' ),
			__( 'Moderation', 'zeko-ai' ),
			'manage_options',
			'zeko-ai-moderation',
			array( $this, 'render_moderation' )
		);

		add_submenu_page(
			'zeko-ai',
			__( 'AI Usage Analytics', 'zeko-ai' ),
			__( 'Analytics', 'zeko-ai' ),
			'manage_options',
			'zeko-ai-analytics',
			array( $this, 'render_analytics' )
		);

		add_submenu_page(
			'zeko-ai',
			__( 'Community Agent', 'zeko-ai' ),
			__( 'Agent', 'zeko-ai' ),
			'manage_options',
			'zeko-ai-agent',
			array( $this, 'render_agent' )
		);

		add_submenu_page(
			'zeko-ai',
			__( 'Provider Health', 'zeko-ai' ),
			__( 'Health', 'zeko-ai' ),
			'manage_options',
			'zeko-ai-health',
			array( $this, 'render_health' )
		);

		add_submenu_page(
			'zeko-ai',
			__( 'Member Memory', 'zeko-ai' ),
			__( 'Memory', 'zeko-ai' ),
			'manage_options',
			'zeko-ai-memory',
			array( $this, 'render_memory' )
		);
	}

	/**
	 * Settings.
	 */
	public function register_settings(): void {
		register_setting( 'zeko_ai_settings_group', 'zeko_ai_settings', array( 'Zeko_AI_Settings', 'sanitize' ) );
	}

	// ═══════════════════════════════════════════════════════════════.
	// SETTINGS / OVERVIEW.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Render overview.
	 */
	public function render_overview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = zeko_ai_get_settings();
		$provider = zeko_ai()->get_provider();
		$totals   = $this->db->usage_totals();
		$features = array(
			'assistant'       => __( 'Assistant & chatbot', 'zeko-ai' ),
			'content'         => __( 'Content generation', 'zeko-ai' ),
			'search'          => __( 'Unified search', 'zeko-ai' ),
			'recommendations' => __( 'Recommendations', 'zeko-ai' ),
			'moderation'      => __( 'Moderation', 'zeko-ai' ),
			'analytics'       => __( 'Analytics', 'zeko-ai' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Zeko AI', 'zeko-ai' ); ?></h1>

			<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
				<?php
				foreach ( array(
					array( (string) number_format_i18n( $totals['requests'] ), __( 'AI requests', 'zeko-ai' ) ),
					array( (string) number_format_i18n( $totals['tokens_in'] ), __( 'Tokens in', 'zeko-ai' ) ),
					array( (string) number_format_i18n( $totals['tokens_out'] ), __( 'Tokens out', 'zeko-ai' ) ),
					array( '$' . number_format_i18n( $totals['cost'], 4 ), __( 'Estimated cost', 'zeko-ai' ) ),
				) as $stat ) :
					?>
					<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:12px 18px;text-align:center;flex:1;min-width:120px;">
						<div style="font-size:20px;font-weight:700;color:#4f46e5;"><?php echo esc_html( $stat[0] ); ?></div>
						<div style="font-size:12px;color:#646970;"><?php echo esc_html( $stat[1] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<p>
				<strong><?php esc_html_e( 'Active provider:', 'zeko-ai' ); ?></strong>
					<?php echo esc_html( $provider->name() ); ?> · <?php echo esc_html( $provider->model() ); ?>
				(<?php echo esc_html( in_array( $settings['provider'], array( 'mock', 'agent' ), true ) ? __( 'offline, no API keys required', 'zeko-ai' ) : __( 'live API', 'zeko-ai' ) ); ?>)
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:8px;">
					<?php wp_nonce_field( 'zeko_ai_test_provider' ); ?>
					<input type="hidden" name="action" value="zeko_ai_test_provider" />
					<button type="submit" class="button"><?php esc_html_e( 'Test provider', 'zeko-ai' ); ?></button>
				</form>
			</p>

			<form method="post" action="options.php">
					<?php settings_fields( 'zeko_ai_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zeko_ai_provider"><?php esc_html_e( 'Provider', 'zeko-ai' ); ?></label></th>
						<td>
							<select name="zeko_ai_settings[provider]" id="zeko_ai_provider">
								<optgroup label="<?php esc_attr_e( 'ZEKO built-in (offline, no keys)', 'zeko-ai' ); ?>">
									<option value="agent" <?php selected( $settings['provider'], 'agent' ); ?>><?php esc_html_e( 'Zeko Local Engine (community-trained agent)', 'zeko-ai' ); ?></option>
									<option value="mock" <?php selected( $settings['provider'], 'mock' ); ?>><?php esc_html_e( 'Mock (offline, deterministic)', 'zeko-ai' ); ?></option>
								</optgroup>
								<optgroup label="<?php esc_attr_e( 'External providers (API key required)', 'zeko-ai' ); ?>">
									<option value="openai" <?php selected( $settings['provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'zeko-ai' ); ?></option>
									<option value="anthropic" <?php selected( $settings['provider'], 'anthropic' ); ?>><?php esc_html_e( 'Anthropic', 'zeko-ai' ); ?></option>
									<option value="openrouter" <?php selected( $settings['provider'], 'openrouter' ); ?>><?php esc_html_e( 'OpenRouter', 'zeko-ai' ); ?></option>
									<option value="gemini" <?php selected( $settings['provider'], 'gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'zeko-ai' ); ?></option>
									<option value="groq" <?php selected( $settings['provider'], 'groq' ); ?>><?php esc_html_e( 'Groq', 'zeko-ai' ); ?></option>
									<option value="deepseek" <?php selected( $settings['provider'], 'deepseek' ); ?>><?php esc_html_e( 'DeepSeek', 'zeko-ai' ); ?></option>
									<option value="cloud" <?php selected( $settings['provider'], 'cloud' ); ?>><?php esc_html_e( 'Zeko Cloud (hosted AI credits)', 'zeko-ai' ); ?></option>
								</optgroup>
							</select>
							<p class="description"><?php esc_html_e( 'Selecting an external provider without its API key automatically falls back to the offline Zeko Local Engine. If a live provider errors at runtime, requests fail over to the next keyed provider, then the Zeko Local Engine, then Mock — the site never breaks.', 'zeko-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zeko_ai_openai_key"><?php esc_html_e( 'OpenAI API key', 'zeko-ai' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" name="zeko_ai_settings[openai_key]" id="zeko_ai_openai_key" value="<?php echo esc_attr( $settings['openai_key'] ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Only needed when the provider is OpenAI. Stored in options.', 'zeko-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zeko_ai_openai_model"><?php esc_html_e( 'OpenAI model', 'zeko-ai' ); ?></label></th>
						<td><input type="text" class="regular-text" name="zeko_ai_settings[openai_model]" id="zeko_ai_openai_model" value="<?php echo esc_attr( $settings['openai_model'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="zeko_ai_anthropic_key"><?php esc_html_e( 'Anthropic API key', 'zeko-ai' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" name="zeko_ai_settings[anthropic_key]" id="zeko_ai_anthropic_key" value="<?php echo esc_attr( $settings['anthropic_key'] ); ?>" autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zeko_ai_anthropic_model"><?php esc_html_e( 'Anthropic model', 'zeko-ai' ); ?></label></th>
						<td><input type="text" class="regular-text" name="zeko_ai_settings[anthropic_model]" id="zeko_ai_anthropic_model" value="<?php echo esc_attr( $settings['anthropic_model'] ); ?>" /></td>
					</tr>
					<?php
					foreach ( array(
						'openrouter' => __( 'OpenRouter', 'zeko-ai' ),
						'gemini'     => __( 'Google Gemini', 'zeko-ai' ),
						'groq'       => __( 'Groq', 'zeko-ai' ),
						'deepseek'   => __( 'DeepSeek', 'zeko-ai' ),
					) as $slug => $label ) :
						?>
						<tr>
							<th scope="row"><label for="zeko_ai_<?php echo esc_attr( $slug ); ?>_key"><?php echo esc_html( sprintf( /* translators: %s: provider name. */ __( '%s API key', 'zeko-ai' ), $label ) ); ?></label></th>
							<td>
								<input type="password" class="regular-text" name="zeko_ai_settings[<?php echo esc_attr( $slug ); ?>_key]" id="zeko_ai_<?php echo esc_attr( $slug ); ?>_key" value="<?php echo esc_attr( (string) ( $settings[ $slug . '_key' ] ?? '' ) ); ?>" autocomplete="off" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="zeko_ai_<?php echo esc_attr( $slug ); ?>_model"><?php echo esc_html( sprintf( /* translators: %s: provider name. */ __( '%s model', 'zeko-ai' ), $label ) ); ?></label></th>
							<td><input type="text" class="regular-text" name="zeko_ai_settings[<?php echo esc_attr( $slug ); ?>_model]" id="zeko_ai_<?php echo esc_attr( $slug ); ?>_model" value="<?php echo esc_attr( (string) ( $settings[ $slug . '_model' ] ?? '' ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="zeko_ai_<?php echo esc_attr( $slug ); ?>_base_url"><?php echo esc_html( sprintf( /* translators: %s: provider name. */ __( '%s base URL', 'zeko-ai' ), $label ) ); ?></label></th>
							<td>
								<input type="url" class="regular-text" name="zeko_ai_settings[<?php echo esc_attr( $slug ); ?>_base_url]" id="zeko_ai_<?php echo esc_attr( $slug ); ?>_base_url" value="<?php echo esc_attr( (string) ( $settings[ $slug . '_base_url' ] ?? '' ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Leave as-is to use the default endpoint.', 'zeko-ai' ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Moderation', 'zeko-ai' ); ?></th>
						<td>
							<label><input type="checkbox" name="zeko_ai_settings[moderation_enabled]" value="1" <?php checked( (int) $settings['moderation_enabled'] ); ?> /> <?php esc_html_e( 'Auto-review new community content', 'zeko-ai' ); ?></label>
							<p class="description"><?php esc_html_e( 'Threshold (0–1) for flagging. Content scoring at or above it is flagged for review.', 'zeko-ai' ); ?></p>
							<input type="number" step="0.05" min="0" max="1" name="zeko_ai_settings[moderation_threshold]" value="<?php echo esc_attr( (string) $settings['moderation_threshold'] ); ?>" style="max-width:100px;" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enabled features', 'zeko-ai' ); ?></th>
						<td>
							<?php foreach ( $features as $key => $label ) : ?>
								<label style="display:inline-block;margin-right:14px;">
									<input type="checkbox" name="zeko_ai_settings[feature_<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( (int) ( $settings[ 'feature_' . $key ] ?? ( 'analytics' === $key ? 0 : 1 ) ) ); ?> /> <?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Floating widget', 'zeko-ai' ); ?></th>
						<td><label><input type="checkbox" name="zeko_ai_settings[floating_widget]" value="1" <?php checked( (int) $settings['floating_widget'] ); ?> /> <?php esc_html_e( 'Show the floating AI assistant button on the front-end', 'zeko-ai' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Streaming replies', 'zeko-ai' ); ?></th>
						<td>
							<label><input type="checkbox" name="zeko_ai_settings[streaming_enabled]" value="1" <?php checked( (int) ( $settings['streaming_enabled'] ?? 1 ) ); ?> /> <?php esc_html_e( 'Stream assistant replies token-by-token (OpenAI, OpenAI-compatible and Anthropic only; offline engines still fall back to a single chunk)', 'zeko-ai' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Community Agent', 'zeko-ai' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="zeko_ai_settings[agent_learning]" value="1" <?php checked( (int) ( $settings['agent_learning'] ?? 1 ) ); ?> /> <?php esc_html_e( 'Enable self-training (log queries, reinforce used knowledge)', 'zeko-ai' ); ?></label>
							<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="zeko_ai_settings[agent_auto_learn]" value="1" <?php checked( (int) ( $settings['agent_auto_learn'] ?? 0 ) ); ?> /> <?php esc_html_e( 'Auto-learn from confirmed answers (when members thank a corpus result)', 'zeko-ai' ); ?></label>
							<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="zeko_ai_settings[agent_world_knowledge]" value="1" <?php checked( (int) ( $settings['agent_world_knowledge'] ?? 0 ) ); ?> /> <?php esc_html_e( 'Allow free Wikipedia lookups for world knowledge (off by default)', 'zeko-ai' ); ?></label>
							<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="zeko_ai_settings[agent_email_alerts]" value="1" <?php checked( (int) ( $settings['agent_email_alerts'] ?? 0 ) ); ?> /> <?php esc_html_e( 'Email the admin when the agent repairs its data or a provider fails (throttled)', 'zeko-ai' ); ?></label>
							<label><input type="checkbox" name="zeko_ai_settings[agent_radar_digest]" value="1" <?php checked( (int) ( $settings['agent_radar_digest'] ?? 0 ) ); ?> /> <?php esc_html_e( 'Send the weekly radar digest email to active members', 'zeko-ai' ); ?></label>
							<p class="description"><?php esc_html_e( 'Teach the agent Q&A entries and review unanswered queries under the Agent screen. Starter Q&A is seeded automatically on the first admin visit.', 'zeko-ai' ); ?></p>
							<p class="description"><?php esc_html_e( 'Auto-learn, admin email alerts, and the weekly radar digest are off by default for privacy and only activate after you explicitly enable them here.', 'zeko-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Web search', 'zeko-ai' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="zeko_ai_settings[web_search_enabled]" value="1" <?php checked( (int) ( $settings['web_search_enabled'] ?? 0 ) ); ?> /> <?php esc_html_e( 'Add web results from a keyless engine. The chat agent and site search show Zeko results first, then a short "from the web" section (web is also the fallback when nothing on Zeko matches)', 'zeko-ai' ); ?></label>
							<label style="display:block;margin-bottom:6px;"><?php esc_html_e( 'Provider', 'zeko-ai' ); ?>:
								<select name="zeko_ai_settings[web_search_provider]" style="min-width:220px;">
									<?php foreach ( Zeko_AI_Web_Search::providers() as $slug => $label ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( (string) ( $settings['web_search_provider'] ?? 'duckduckgo' ), $slug ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<label style="display:block;margin-bottom:6px;"><?php esc_html_e( 'Google API key', 'zeko-ai' ); ?>:
								<input type="text" name="zeko_ai_settings[google_search_api_key]" value="<?php echo esc_attr( (string) ( $settings['google_search_api_key'] ?? '' ) ); ?>" class="regular-text" autocomplete="off" /></label>
							<label style="display:block;margin-bottom:6px;"><?php esc_html_e( 'Google Search Engine ID (cx)', 'zeko-ai' ); ?>:
								<input type="text" name="zeko_ai_settings[google_search_engine_id]" value="<?php echo esc_attr( (string) ( $settings['google_search_engine_id'] ?? '' ) ); ?>" class="regular-text" autocomplete="off" /></label>
							<label style="display:block;margin-bottom:6px;"><?php esc_html_e( 'Bing API key', 'zeko-ai' ); ?>:
								<input type="text" name="zeko_ai_settings[bing_search_key]" value="<?php echo esc_attr( (string) ( $settings['bing_search_key'] ?? '' ) ); ?>" class="regular-text" autocomplete="off" /></label>
							<label style="display:block;margin-bottom:6px;"><?php esc_html_e( 'Brave API key', 'zeko-ai' ); ?>:
								<input type="text" name="zeko_ai_settings[brave_search_key]" value="<?php echo esc_attr( (string) ( $settings['brave_search_key'] ?? '' ) ); ?>" class="regular-text" autocomplete="off" /></label>
							<label style="display:block;margin-bottom:6px;"><?php esc_html_e( 'Serper API key', 'zeko-ai' ); ?>:
								<input type="text" name="zeko_ai_settings[serper_search_key]" value="<?php echo esc_attr( (string) ( $settings['serper_search_key'] ?? '' ) ); ?>" class="regular-text" autocomplete="off" /></label>
							<label style="display:block;margin-bottom:6px;"><?php esc_html_e( 'Max results', 'zeko-ai' ); ?>:
								<input type="number" name="zeko_ai_settings[web_search_max_results]" value="<?php echo esc_attr( (string) ( $settings['web_search_max_results'] ?? 5 ) ); ?>" min="1" max="10" style="width:70px;" /></label>
							<p class="description"><?php esc_html_e( 'DuckDuckGo needs no key and works immediately. Google, Bing, Brave and Serper each need an API key from their dashboard; without the required key that provider simply returns no results. Search results are cached briefly and never stored permanently.', 'zeko-ai' ); ?></p>
							<p class="description"><?php esc_html_e( 'Web search is off by default: enabling it sends member queries to the chosen external search engine, so activate it only as an explicit opt-in.', 'zeko-ai' ); ?></p>
						</td>
					</tr>
				</table>
					<?php submit_button(); ?>
			</form>
		</div>
			<?php
	}

	/**
	 * Handle test provider.
	 */
	public function handle_test_provider(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-ai' ) );
		}
		check_admin_referer( 'zeko_ai_test_provider' );

		$message = __( 'Provider test:', 'zeko-ai' );
		try {
			$result   = zeko_ai()->get_provider()->complete(
				'Reply with the single word: ok',
				array( 'max_tokens' => 10 )
			);
			$message .= ' ' . sprintf(
				/* translators: 1: provider name, 2: model, 3: reply snippet. */
				__( '%1$s (%2$s) replied: "%3$s"', 'zeko-ai' ),
				zeko_ai()->get_provider()->name(),
				(string) $result['model'],
				mb_substr( (string) $result['content'], 0, 80 )
			);
			$type = 'updated';
		} catch ( Exception $e ) {
			$message .= ' ' . $e->getMessage();
			$type     = 'error';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'zeko_ai_notice'      => rawurlencode( $message ),
					'zeko_ai_notice_type' => $type,
				),
				admin_url( 'admin.php?page=zeko-ai' )
			)
		);
		exit;
	}

	// ═══════════════════════════════════════════════════════════════.
	// COMMUNITY AGENT.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Handle agent teach.
	 */
	public function handle_agent_teach(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-ai' ) );
		}
		check_admin_referer( 'zeko_ai_agent_teach' );

		$question = isset( $_POST['question'] ) ? sanitize_text_field( wp_unslash( $_POST['question'] ) ) : '';
		$answer   = isset( $_POST['answer'] ) ? sanitize_textarea_field( wp_unslash( $_POST['answer'] ) ) : '';
		$module   = isset( $_POST['module'] ) ? sanitize_key( $_POST['module'] ) : '';
		$weight   = isset( $_POST['weight'] ) ? min( 10.0, max( 0.1, (float) $_POST['weight'] ) ) : 1;

		if ( '' === $question || '' === $answer ) {
			$notice = __( 'Both a question and an answer are required.', 'zeko-ai' );
			$type   = 'error';
		} else {
			$id     = $this->db->insert_knowledge(
				array(
					'user_id'  => get_current_user_id(),
					'question' => $question,
					'answer'   => $answer,
					'module'   => $module,
					'source'   => 'admin',
					'weight'   => $weight,
				)
			);
			$notice = $id ? sprintf(
			/* translators: 1: knowledge entry id. */
				__( 'Knowledge saved (entry #%1$d). The agent will reuse this answer.', 'zeko-ai' ),
				$id
			) : __( 'Could not save the knowledge entry.', 'zeko-ai' );
			$type = $id ? 'updated' : 'error';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'zeko_ai_notice'      => rawurlencode( $notice ),
					'zeko_ai_notice_type' => $type,
				),
				admin_url( 'admin.php?page=zeko-ai-agent' )
			)
		);
		exit;
	}

	/**
	 * Handle agent delete knowledge.
	 */
	public function handle_agent_delete_knowledge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-ai' ) );
		}
		check_admin_referer( 'zeko_ai_agent_delete_knowledge' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$this->db->delete_knowledge( $id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'zeko_ai_notice'      => rawurlencode( __( 'Knowledge entry deleted.', 'zeko-ai' ) ),
					'zeko_ai_notice_type' => 'updated',
				),
				admin_url( 'admin.php?page=zeko-ai-agent' )
			)
		);
		exit;
	}

	/**
	 * Handle agent delete query.
	 */
	public function handle_agent_delete_query(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-ai' ) );
		}
		check_admin_referer( 'zeko_ai_agent_delete_query' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$this->db->delete_agent_query( $id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'zeko_ai_notice'      => rawurlencode( __( 'Query removed from the log.', 'zeko-ai' ) ),
					'zeko_ai_notice_type' => 'updated',
				),
				admin_url( 'admin.php?page=zeko-ai-agent' )
			)
		);
		exit;
	}

	/**
	 * Handle agent mark answered.
	 */
	public function handle_agent_mark_answered(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-ai' ) );
		}
		check_admin_referer( 'zeko_ai_agent_mark_answered' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$this->db->update_agent_query(
			$id,
			array(
				'answered' => 1,
				'mode'     => 'manual',
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'zeko_ai_notice'      => rawurlencode( __( 'Query marked as answered.', 'zeko-ai' ) ),
					'zeko_ai_notice_type' => 'updated',
				),
				admin_url( 'admin.php?page=zeko-ai-agent' )
			)
		);
		exit;
	}

	/**
	 * Handle knowledge export.
	 */
	public function handle_knowledge_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-ai' ) );
		}
		check_admin_referer( 'zeko_ai_knowledge_export' );

		$items = $this->db->export_knowledge();

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="zeko-ai-knowledge-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode(
			array(
				'version'   => ZEKO_AI_VERSION,
				'exported'  => gmdate( 'c' ),
				'total'     => count( $items ),
				'knowledge' => $items,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Handle knowledge import.
	 */
	public function handle_knowledge_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-ai' ) );
		}
		check_admin_referer( 'zeko_ai_knowledge_import' );

		$json       = '';
		$cleanup    = false;
		$import_err = '';

		if ( ! empty( $_FILES['import_file']['tmp_name'] ) && is_uploaded_file( $_FILES['import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$cleanup = true;
			if ( class_exists( 'Zeko_Core_Upload' ) ) {
				$valid = Zeko_Core_Upload::validate_file( $_FILES['import_file'], 'json' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( is_wp_error( $valid ) ) {
					$import_err = $valid->get_error_message();
				} else {
					$json = (string) file_get_contents( $_FILES['import_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				}
			} else {
				$json = (string) file_get_contents( $_FILES['import_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		} elseif ( isset( $_POST['import_json'] ) ) {
			$json = sanitize_textarea_field( wp_unslash( $_POST['import_json'] ) );
		}

		$overwrite = ! empty( $_POST['overwrite'] );
		$decoded   = json_decode( $json, true );

		if ( $cleanup && ! empty( $_FILES['import_file']['tmp_name'] ) ) {
			@unlink( $_FILES['import_file']['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( '' !== $import_err ) {
			$notice = $import_err;
			$type   = 'error';
		} elseif ( ! is_array( $decoded ) ) {
			$notice = __( 'Import failed: the JSON could not be parsed.', 'zeko-ai' );
			$type   = 'error';
		} else {
			$items  = isset( $decoded['knowledge'] ) && is_array( $decoded['knowledge'] ) ? $decoded['knowledge'] : $decoded;
			$result = $this->db->import_knowledge( $items, (bool) $overwrite );
			$notice = sprintf(
			/* translators: 1: inserted, 2: updated, 3: skipped. */
				__( 'Import complete — %1$d inserted, %2$d updated, %3$d skipped.', 'zeko-ai' ),
				(int) $result['inserted'],
				(int) $result['updated'],
				(int) $result['skipped']
			);
			$type = 'updated';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'zeko_ai_notice'      => rawurlencode( $notice ),
					'zeko_ai_notice_type' => $type,
				),
				admin_url( 'admin.php?page=zeko-ai-agent' )
			)
		);
		exit;
	}

	/**
	 * Handle health clear.
	 */
	public function handle_health_clear(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-ai' ) );
		}
		check_admin_referer( 'zeko_ai_health_clear' );

		$this->db->delete_provider_logs();
		wp_safe_redirect(
			add_query_arg(
				array(
					'zeko_ai_notice'      => rawurlencode( __( 'Provider log cleared.', 'zeko-ai' ) ),
					'zeko_ai_notice_type' => 'updated',
				),
				admin_url( 'admin.php?page=zeko-ai-health' )
			)
		);
		exit;
	}

	/**
	 * Handle memory delete.
	 */
	public function handle_memory_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-ai' ) );
		}
		check_admin_referer( 'zeko_ai_memory_delete' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$this->db->delete_memory( $id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'zeko_ai_notice'      => rawurlencode( __( 'Memory entry deleted.', 'zeko-ai' ) ),
					'zeko_ai_notice_type' => 'updated',
				),
				admin_url( 'admin.php?page=zeko-ai-memory' )
			)
		);
		exit;
	}

	/**
	 * Handle memory clear user.
	 */
	public function handle_memory_clear_user(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-ai' ) );
		}
		check_admin_referer( 'zeko_ai_memory_clear_user' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		if ( $user_id > 0 ) {
			$this->db->delete_user_memory( $user_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'zeko_ai_notice'      => rawurlencode( __( 'Member memory cleared.', 'zeko-ai' ) ),
					'zeko_ai_notice_type' => 'updated',
				),
				admin_url( 'admin.php?page=zeko-ai-memory' )
			)
		);
		exit;
	}

	/**
	 * Render agent.
	 */
	public function render_agent(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$agent     = zeko_ai()->get_agent();
		$settings  = zeko_ai_get_settings();
		$knowledge = $this->db->get_knowledge_entries( array( 'limit' => 200 ) );
		$gaps      = $this->db->get_agent_queries(
			array(
				'answered' => 0,
				'limit'    => 200,
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Community Agent', 'zeko-ai' ); ?></h1>

			<?php $this->maybe_show_notice(); ?>

			<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
				<?php
				foreach ( array(
					array( esc_html( $agent->model() ), __( 'Agent model', 'zeko-ai' ) ),
					array( (string) $this->db->count_knowledge(), __( 'Knowledge entries', 'zeko-ai' ) ),
					array( (string) $this->db->count_agent_queries( 0 ), __( 'Unanswered queries', 'zeko-ai' ) ),
					array( (int) ( $settings['agent_learning'] ?? 1 ) ? __( 'On', 'zeko-ai' ) : __( 'Off', 'zeko-ai' ), __( 'Self-training', 'zeko-ai' ) ),
					array( (int) ( $settings['agent_auto_learn'] ?? 0 ) ? __( 'On', 'zeko-ai' ) : __( 'Off', 'zeko-ai' ), __( 'Auto-learn', 'zeko-ai' ) ),
					array( (int) ( $settings['agent_world_knowledge'] ?? 0 ) ? __( 'On', 'zeko-ai' ) : __( 'Off', 'zeko-ai' ), __( 'World knowledge', 'zeko-ai' ) ),
					array( (int) ( $settings['web_search_enabled'] ?? 0 ) ? __( 'On', 'zeko-ai' ) : __( 'Off', 'zeko-ai' ), __( 'Web search', 'zeko-ai' ) ),
				) as $stat ) :
					?>
					<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:12px 18px;text-align:center;flex:1;min-width:120px;">
						<div style="font-size:20px;font-weight:700;color:#4f46e5;"><?php echo esc_html( $stat[0] ); ?></div>
						<div style="font-size:12px;color:#646970;"><?php echo esc_html( $stat[1] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<p>
					<?php esc_html_e( 'The Community Agent answers questions from real Zeko data (jobs, courses, Q&A, shop, freelance, mentor, dating, wallet, rewards) with no API key. Teach it FAQ entries below; every unanswered question is logged so you can fill the gaps.', 'zeko-ai' ); ?>
			</p>

			<h2><?php esc_html_e( 'Teach the agent', 'zeko-ai' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:720px;">
					<?php wp_nonce_field( 'zeko_ai_agent_teach' ); ?>
				<input type="hidden" name="action" value="zeko_ai_agent_teach" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zeko_ai_agent_question"><?php esc_html_e( 'Question', 'zeko-ai' ); ?></label></th>
						<td><input type="text" class="regular-text" name="question" id="zeko_ai_agent_question" required maxlength="500" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="zeko_ai_agent_answer"><?php esc_html_e( 'Answer', 'zeko-ai' ); ?></label></th>
						<td><textarea class="large-text" rows="5" name="answer" id="zeko_ai_agent_answer" required></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="zeko_ai_agent_module"><?php esc_html_e( 'Module', 'zeko-ai' ); ?></label></th>
						<td>
							<select name="module" id="zeko_ai_agent_module">
								<option value=""><?php esc_html_e( 'General', 'zeko-ai' ); ?></option>
								<?php
								foreach ( array(
									'jobs'      => __( 'Jobs', 'zeko-ai' ),
									'learn'     => __( 'Learn', 'zeko-ai' ),
									'qa'        => __( 'Q&A', 'zeko-ai' ),
									'shop'      => __( 'Shop', 'zeko-ai' ),
									'freelance' => __( 'Freelance', 'zeko-ai' ),
									'mentor'    => __( 'Mentor', 'zeko-ai' ),
									'love'      => __( 'Dating', 'zeko-ai' ),
									'wallet'    => __( 'Wallet', 'zeko-ai' ),
									'rewards'   => __( 'Rewards', 'zeko-ai' ),
								) as $key => $label ) :
									?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zeko_ai_agent_weight"><?php esc_html_e( 'Priority (0.1–10)', 'zeko-ai' ); ?></label></th>
						<td><input type="number" step="0.1" min="0.1" max="10" name="weight" id="zeko_ai_agent_weight" value="1" style="max-width:100px;" /></td>
					</tr>
				</table>
					<?php submit_button( __( 'Save knowledge', 'zeko-ai' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Learned knowledge', 'zeko-ai' ); ?></h2>
				<?php if ( empty( $knowledge ) ) : ?>
				<p><?php esc_html_e( 'No knowledge yet. Teach your first FAQ above.', 'zeko-ai' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Question', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Answer', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Module', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Priority', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Used', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'zeko-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $knowledge as $entry ) : ?>
							<tr>
								<td style="max-width:240px;"><?php echo esc_html( (string) $entry->question ); ?></td>
								<td style="max-width:320px;"><?php echo esc_html( mb_substr( (string) $entry->answer, 0, 140 ) ); ?></td>
								<td><?php echo esc_html( (string) $entry->module ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $entry->weight, 2 ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $entry->use_count ) ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'zeko_ai_agent_delete_knowledge' ); ?>
										<input type="hidden" name="action" value="zeko_ai_agent_delete_knowledge" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $entry->id ); ?>" />
										<button class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this knowledge entry?', 'zeko-ai' ) ); ?>');"><?php esc_html_e( 'Delete', 'zeko-ai' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Teaching gap suggestions', 'zeko-ai' ); ?></h2>
				<?php
				$suggested = $this->db->suggest_gaps();
				if ( empty( $suggested ) ) :
					?>
				<p><?php esc_html_e( 'No clusters yet. Once a few unanswered queries share an intent, this list highlights the highest-impact topics to teach first.', 'zeko-ai' ); ?></p>
				<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Intent', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Unanswered', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Sample query', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Suggest', 'zeko-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $suggested as $suggestion ) : ?>
							<tr>
								<td><?php echo esc_html( $suggestion['intent'] ? $suggestion['intent'] : __( 'General', 'zeko-ai' ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $suggestion['count'] ) ); ?></td>
								<td style="max-width:360px;"><?php echo esc_html( mb_substr( $suggestion['sample'], 0, 160 ) ); ?></td>
								<td>
									<button class="button button-small" type="button" onclick="document.getElementById('zeko_ai_agent_question').value=<?php echo wp_json_encode( $suggestion['sample'] ); ?>;document.getElementById('zeko_ai_agent_question').focus();"><?php esc_html_e( 'Use as question', 'zeko-ai' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Click "Use as question" to load a cluster sample into the teach form above.', 'zeko-ai' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Unanswered queries (learning gaps)', 'zeko-ai' ); ?></h2>
				<?php if ( empty( $gaps ) ) : ?>
				<p><?php esc_html_e( 'No unanswered queries recorded. Great — nothing to teach right now.', 'zeko-ai' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Query', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Intent', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Date', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'zeko-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $gaps as $query ) : ?>
							<tr>
								<td style="max-width:360px;"><?php echo esc_html( (string) $query->query ); ?></td>
								<td><?php echo esc_html( (string) $query->intent ); ?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $query->created_at ) ); ?></td>
								<td style="white-space:nowrap;">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'zeko_ai_agent_mark_answered' ); ?>
										<input type="hidden" name="action" value="zeko_ai_agent_mark_answered" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $query->id ); ?>" />
										<button class="button button-small"><?php esc_html_e( 'Mark answered', 'zeko-ai' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'zeko_ai_agent_delete_query' ); ?>
										<input type="hidden" name="action" value="zeko_ai_agent_delete_query" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $query->id ); ?>" />
										<button class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'zeko-ai' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Import / export knowledge', 'zeko-ai' ); ?></h2>
			<div style="max-width:720px;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
					<?php wp_nonce_field( 'zeko_ai_knowledge_export' ); ?>
					<input type="hidden" name="action" value="zeko_ai_knowledge_export" />
					<p><?php esc_html_e( 'Download the current knowledge base as a portable JSON file (up to 500 entries).', 'zeko-ai' ); ?></p>
					<button type="submit" class="button"><?php esc_html_e( 'Export knowledge', 'zeko-ai' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'zeko_ai_knowledge_import' ); ?>
					<input type="hidden" name="action" value="zeko_ai_knowledge_import" />
					<p>
						<label for="zeko_ai_import_file" style="font-weight:600;"><?php esc_html_e( 'Import JSON file', 'zeko-ai' ); ?></label><br />
						<input type="file" name="import_file" id="zeko_ai_import_file" accept="application/json,.json" />
					</p>
					<p>
						<label for="zeko_ai_import_json" style="font-weight:600;"><?php esc_html_e( 'Or paste JSON', 'zeko-ai' ); ?></label><br />
						<textarea class="large-text" rows="6" name="import_json" id="zeko_ai_import_json" placeholder='{"knowledge":[{ "question": "...", "answer": "...", "module": "jobs", "weight": 1 }]}'></textarea>
					</p>
					<p>
						<label><input type="checkbox" name="overwrite" value="1" /> <?php esc_html_e( 'Overwrite existing entries with the same question', 'zeko-ai' ); ?></label>
					</p>
					<button type="submit" class="button"><?php esc_html_e( 'Import knowledge', 'zeko-ai' ); ?></button>
				</form>
			</div>
		</div>
			<?php
	}

	// ═══════════════════════════════════════════════════════════════.
	// PROVIDER HEALTH.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Render health.
	 */
	public function render_health(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = zeko_ai_get_settings();
		$chain    = Zeko_AI_Provider_Factory::chain_names( $settings );
		$logs     = $this->db->get_provider_logs( array( 'limit' => 200 ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Provider Health', 'zeko-ai' ); ?></h1>

			<?php $this->maybe_show_notice(); ?>

			<h2><?php esc_html_e( 'Failover chain', 'zeko-ai' ); ?></h2>
			<p><?php esc_html_e( 'Requests try providers in this order. When one throws, the next is used automatically and the failure is logged below.', 'zeko-ai' ); ?></p>
			<ol>
				<?php foreach ( $chain as $name ) : ?>
					<li><code><?php echo esc_html( $name ); ?></code></li>
				<?php endforeach; ?>
			</ol>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
				<?php wp_nonce_field( 'zeko_ai_health_clear' ); ?>
				<input type="hidden" name="action" value="zeko_ai_health_clear" />
				<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Clear the provider log?', 'zeko-ai' ) ); ?>');"><?php esc_html_e( 'Clear provider log', 'zeko-ai' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Recent failures', 'zeko-ai' ); ?></h2>
			<?php if ( empty( $logs ) ) : ?>
				<p><?php esc_html_e( 'No failures recorded. Every request in the current chain is healthy.', 'zeko-ai' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Event', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Provider', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Detail', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Date', 'zeko-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $log->event ); ?></td>
								<td>
									<?php echo esc_html( (string) $log->provider ); ?>
									<?php if ( '' !== (string) $log->model ) : ?>
										<div style="color:#646970;font-size:12px;"><?php echo esc_html( (string) $log->model ); ?></div>
									<?php endif; ?>
								</td>
								<td style="max-width:420px;"><?php echo esc_html( (string) $log->detail ); ?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $log->created_at ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════.
	// MEMBER MEMORY.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Render memory.
	 */
	public function render_memory(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$entries = $this->db->get_all_memory( array( 'limit' => 200 ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Member Memory', 'zeko-ai' ); ?></h1>

			<?php $this->maybe_show_notice(); ?>

			<p>
				<?php esc_html_e( 'Facts the Community Agent remembers per member (names, interests, preferences, goals). This data personalizes search and recommendations and can be deleted at any time.', 'zeko-ai' ); ?>
			</p>

			<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:12px 18px;text-align:center;max-width:220px;margin-bottom:16px;">
				<div style="font-size:20px;font-weight:700;color:#4f46e5;"><?php echo esc_html( number_format_i18n( $this->db->count_memory() ) ); ?></div>
				<div style="font-size:12px;color:#646970;"><?php esc_html_e( 'Memory entries', 'zeko-ai' ); ?></div>
			</div>

			<?php if ( empty( $entries ) ) : ?>
				<p><?php esc_html_e( 'No memory stored yet. Facts are captured when members share them in the assistant chat.', 'zeko-ai' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Member', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Fact', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Value', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Source', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'zeko-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td>
									<?php
									$user = get_userdata( (int) $entry->user_id );
									echo esc_html( $user ? $user->display_name : '#' . (string) $entry->user_id );
									?>
								</td>
								<td><code><?php echo esc_html( (string) $entry->fact_key ); ?></code></td>
								<td style="max-width:360px;"><?php echo esc_html( (string) $entry->fact_value ); ?></td>
								<td><?php echo esc_html( (string) $entry->source ); ?></td>
								<td style="white-space:nowrap;">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'zeko_ai_memory_delete' ); ?>
										<input type="hidden" name="action" value="zeko_ai_memory_delete" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $entry->id ); ?>" />
										<button class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'zeko-ai' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'zeko_ai_memory_clear_user' ); ?>
										<input type="hidden" name="action" value="zeko_ai_memory_clear_user" />
										<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $entry->user_id ); ?>" />
										<button class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Clear all memory for this member?', 'zeko-ai' ) ); ?>');"><?php esc_html_e( 'Clear all', 'zeko-ai' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
			<?php
	}

	// ═══════════════════════════════════════════════════════════════.
	// MODERATION.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Handle moderation action.
	 */
	public function handle_moderation_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-ai' ) );
		}
		check_admin_referer( 'zeko_ai_moderation_action' );

		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$action   = isset( $_POST['zeko_action'] ) ? sanitize_key( $_POST['zeko_action'] ) : '';
		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=zeko-ai-moderation' );

		$moderation = zeko_ai()->get_moderation();

		switch ( $action ) {
			case 'review':
				$result = $moderation->review( $id, get_current_user_id() );
				$notice = $result ? sprintf(
					/* translators: 1: new decision. */
					__( 'Re-reviewed with AI. New decision: %1$s', 'zeko-ai' ),
					$result['decision']
				) : __( 'Entry not found.', 'zeko-ai' );
				break;
			case 'approve':
				$moderation->set_decision( $id, 'approved', get_current_user_id() );
				$notice = __( 'Entry approved.', 'zeko-ai' );
				break;
			case 'flag':
				$moderation->set_decision( $id, 'flagged', get_current_user_id() );
				$notice = __( 'Entry flagged.', 'zeko-ai' );
				break;
			case 'dismiss':
				$moderation->set_decision( $id, 'dismissed', get_current_user_id() );
				$notice = __( 'Entry dismissed.', 'zeko-ai' );
				break;
			case 'delete':
				$this->db->delete_moderation( $id );
				$notice = __( 'Entry deleted.', 'zeko-ai' );
				break;
			default:
				$notice = __( 'Unknown action.', 'zeko-ai' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'zeko_ai_notice'      => rawurlencode( $notice ),
					'zeko_ai_notice_type' => 'updated',
				),
				$redirect
			)
		);
		exit;
	}

	/**
	 * Render moderation.
	 */
	public function render_moderation(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filters; values pass sanitize_key() and only drive display filtering.
		$decision = isset( $_GET['decision'] ) ? sanitize_key( $_GET['decision'] ) : 'flagged';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter; sanitized, display only.
		$source     = isset( $_GET['source'] ) ? sanitize_key( $_GET['source'] ) : '';
		$entries    = $this->db->get_moderation_entries(
			array(
				'decision' => $decision,
				'source'   => $source,
				'limit'    => 200,
			)
		);
		$sources    = zeko_ai()->get_moderation()->get_sources();
		$source_map = array();
		foreach ( $sources as $s ) {
			$source_map[ (string) $s['source'] ] = (string) $s['label'];
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Moderation', 'zeko-ai' ); ?></h1>

			<?php $this->maybe_show_notice(); ?>

			<p>
				<?php esc_html_e( 'Filter:', 'zeko-ai' ); ?>
				<?php
				foreach ( array(
					'flagged'   => __( 'Flagged', 'zeko-ai' ),
					'approved'  => __( 'Approved', 'zeko-ai' ),
					'dismissed' => __( 'Dismissed', 'zeko-ai' ),
					''          => __( 'All', 'zeko-ai' ),
				) as $key => $label ) :
					?>
					<a class="button<?php echo $decision === $key ? ' button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'decision', $key, admin_url( 'admin.php?page=zeko-ai-moderation' ) ) ); ?>"><?php echo esc_html( $label ); ?> (<?php echo esc_html( (string) $this->db->count_moderation( array( 'decision' => $key ) ) ); ?>)</a>
				<?php endforeach; ?>
			</p>

			<p>
					<?php esc_html_e( 'Sources:', 'zeko-ai' ); ?>
				<a class="button<?php echo '' === $source ? ' button-primary' : ''; ?>" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'decision' => $decision,
								'source'   => '',
							),
							admin_url( 'admin.php?page=zeko-ai-moderation' )
						)
					);
					?>
				"><?php esc_html_e( 'All', 'zeko-ai' ); ?></a>
				<?php foreach ( $source_map as $key => $label ) : ?>
					<a class="button<?php echo $source === $key ? ' button-primary' : ''; ?>" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'decision' => $decision,
								'source'   => $key,
							),
							admin_url( 'admin.php?page=zeko-ai-moderation' )
						)
					);
					?>
					"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</p>

				<?php if ( empty( $entries ) ) : ?>
				<p><?php esc_html_e( 'No entries match this filter.', 'zeko-ai' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Author', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Source', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Content', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'AI verdict', 'zeko-ai' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'zeko-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $entry->id ); ?></td>
								<td>
									<?php
									$user = get_userdata( (int) $entry->user_id );
									echo esc_html( $user ? $user->display_name : '#' . (string) $entry->user_id );
									?>
								</td>
								<td><?php echo esc_html( $source_map[ (string) $entry->source ] ?? (string) $entry->source ); ?></td>
								<td style="max-width:360px;"><?php echo esc_html( mb_substr( (string) $entry->content, 0, 200 ) ); ?></td>
								<td>
									<span class="<?php echo 'flagged' === $entry->decision ? 'dot' : ''; ?>" style="<?php echo 'flagged' === $entry->decision ? 'display:inline-block;width:8px;height:8px;border-radius:50%;background:#d63638;margin-right:4px;' : ''; ?>"></span>
									<?php echo esc_html( (string) $entry->decision ); ?>
									<?php if ( $entry->reasons ) : ?>
										<div style="color:#646970;font-size:12px;"><?php echo esc_html( implode( ', ', (array) $entry->reasons ) ); ?></div>
									<?php endif; ?>
								</td>
								<td style="white-space:nowrap;">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'zeko_ai_moderation_action' ); ?>
										<input type="hidden" name="action" value="zeko_ai_moderation_action" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $entry->id ); ?>" />
										<input type="hidden" name="zeko_action" value="review" />
										<button class="button button-small"><?php esc_html_e( 'Re-review', 'zeko-ai' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'zeko_ai_moderation_action' ); ?>
										<input type="hidden" name="action" value="zeko_ai_moderation_action" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $entry->id ); ?>" />
										<input type="hidden" name="zeko_action" value="approve" />
										<button class="button button-small"><?php esc_html_e( 'Approve', 'zeko-ai' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'zeko_ai_moderation_action' ); ?>
										<input type="hidden" name="action" value="zeko_ai_moderation_action" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $entry->id ); ?>" />
										<input type="hidden" name="zeko_action" value="dismiss" />
										<button class="button button-small"><?php esc_html_e( 'Dismiss', 'zeko-ai' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'zeko_ai_moderation_action' ); ?>
										<input type="hidden" name="action" value="zeko_ai_moderation_action" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $entry->id ); ?>" />
										<input type="hidden" name="zeko_action" value="delete" />
										<button class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this entry?', 'zeko-ai' ) ); ?>');"><?php esc_html_e( 'Delete', 'zeko-ai' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
			<?php
	}

	// ═══════════════════════════════════════════════════════════════.
	// ANALYTICS.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Handle analytics clear.
	 */
	public function handle_analytics_clear(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-ai' ) );
		}
		check_admin_referer( 'zeko_ai_analytics_clear' );

		$this->db->delete_usage();
		wp_safe_redirect(
			add_query_arg(
				array(
					'zeko_ai_notice'      => rawurlencode( __( 'Usage log cleared.', 'zeko-ai' ) ),
					'zeko_ai_notice_type' => 'updated',
				),
				admin_url( 'admin.php?page=zeko-ai-analytics' )
			)
		);
		exit;
	}

	/**
	 * Render analytics.
	 */
	public function render_analytics(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$totals     = $this->db->usage_totals();
		$by_feature = $this->db->usage_by_feature();
		$by_day     = $this->db->usage_by_day( 14 );
		$top_users  = $this->db->usage_top_users();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Usage Analytics', 'zeko-ai' ); ?></h1>

			<?php $this->maybe_show_notice(); ?>

			<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
				<?php
				foreach ( array(
					array( (string) number_format_i18n( $totals['requests'] ), __( 'Requests', 'zeko-ai' ) ),
					array( (string) number_format_i18n( $totals['tokens_in'] ), __( 'Tokens in', 'zeko-ai' ) ),
					array( (string) number_format_i18n( $totals['tokens_out'] ), __( 'Tokens out', 'zeko-ai' ) ),
					array( '$' . number_format_i18n( $totals['cost'], 4 ), __( 'Estimated cost', 'zeko-ai' ) ),
				) as $stat ) :
					?>
					<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:12px 18px;text-align:center;flex:1;min-width:120px;">
						<div style="font-size:20px;font-weight:700;color:#4f46e5;"><?php echo esc_html( $stat[0] ); ?></div>
						<div style="font-size:12px;color:#646970;"><?php echo esc_html( $stat[1] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
					<?php wp_nonce_field( 'zeko_ai_analytics_clear' ); ?>
				<input type="hidden" name="action" value="zeko_ai_analytics_clear" />
				<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Clear all usage data?', 'zeko-ai' ) ); ?>');"><?php esc_html_e( 'Clear usage log', 'zeko-ai' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'By feature', 'zeko-ai' ); ?></h2>
			<table class="widefat striped" style="max-width:720px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Feature', 'zeko-ai' ); ?></th>
						<th><?php esc_html_e( 'Requests', 'zeko-ai' ); ?></th>
						<th><?php esc_html_e( 'Tokens in', 'zeko-ai' ); ?></th>
						<th><?php esc_html_e( 'Tokens out', 'zeko-ai' ); ?></th>
						<th><?php esc_html_e( 'Cost', 'zeko-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $by_feature ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No usage recorded yet.', 'zeko-ai' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $by_feature as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $row->feature ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->requests ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->tokens_in ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->tokens_out ) ); ?></td>
								<td>$<?php echo esc_html( number_format_i18n( (float) $row->cost, 4 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Last 14 days', 'zeko-ai' ); ?></h2>
			<table class="widefat striped" style="max-width:720px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Day', 'zeko-ai' ); ?></th>
						<th><?php esc_html_e( 'Requests', 'zeko-ai' ); ?></th>
						<th><?php esc_html_e( 'Tokens', 'zeko-ai' ); ?></th>
						<th><?php esc_html_e( 'Cost', 'zeko-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $by_day ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No usage in this window.', 'zeko-ai' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $by_day as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $row->day ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->requests ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->tokens_in + (int) $row->tokens_out ) ); ?></td>
								<td>$<?php echo esc_html( number_format_i18n( (float) $row->cost, 4 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Top users', 'zeko-ai' ); ?></h2>
			<table class="widefat striped" style="max-width:720px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'zeko-ai' ); ?></th>
						<th><?php esc_html_e( 'Requests', 'zeko-ai' ); ?></th>
						<th><?php esc_html_e( 'Tokens', 'zeko-ai' ); ?></th>
						<th><?php esc_html_e( 'Cost', 'zeko-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $top_users ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No usage recorded yet.', 'zeko-ai' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $top_users as $row ) : ?>
							<tr>
								<td>
									<?php
									$user = get_userdata( (int) $row->user_id );
									echo esc_html( $user ? $user->display_name : '#' . (string) $row->user_id );
									?>
								</td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->requests ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->tokens_in + (int) $row->tokens_out ) ); ?></td>
								<td>$<?php echo esc_html( number_format_i18n( (float) $row->cost, 4 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
			<?php
	}

	/**
	 * Show notice.
	 */
	private function maybe_show_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice param; rendered with esc_html() below.
		$notice = sanitize_text_field( wp_unslash( $_GET['zeko_ai_notice'] ?? '' ) );
		if ( '' === $notice ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice type param; sanitized + escaped below.
		$type = isset( $_GET['zeko_ai_notice_type'] ) ? sanitize_key( $_GET['zeko_ai_notice_type'] ) : 'updated';
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $notice )
		);
	}
}
