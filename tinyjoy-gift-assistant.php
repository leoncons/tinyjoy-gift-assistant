<?php
/**
 * Plugin Name: TinyJoy Gift Assistant
 * Plugin URI:  https://tinyjoygifts.com
 * Description: AI-powered gift finder — recommends TinyJoy products by recipient, occasion, vibe, and budget. Includes floating widget + shortcode.
 * Version:     1.1.0
 * Author:      TinyJoy
 * Text Domain: tinyjoy-gift-assistant
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * GitHub Plugin URI: leoncons/tinyjoy-gift-assistant
 * GitHub Branch:     main
 */

defined( 'ABSPATH' ) || exit;

define( 'TGA_VERSION',    '1.1.0' );
define( 'TGA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TGA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ─── SELF-UPDATER ─────────────────────────────────────────────────────────────
// Queries GitHub Releases API so WordPress can install updates natively
// (delete old folder + install new zip) without any external plugin.
// To release an update: bump TGA_VERSION, push to main, create a GitHub Release
// tagged vX.Y.Z — WordPress sites will see the update within 12 hours.

add_filter( 'pre_set_site_transient_update_plugins', 'tga_check_for_update' );
function tga_check_for_update( $transient ) {
	if ( empty( $transient->checked ) ) {
		return $transient;
	}

	$plugin_slug = plugin_basename( __FILE__ );
	$api_url     = 'https://api.github.com/repos/leoncons/tinyjoy-gift-assistant/releases/latest';

	$response = get_transient( 'tga_github_update_check' );
	if ( false === $response ) {
		$response = wp_remote_get( $api_url, [
			'headers' => [ 'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) ],
			'timeout' => 10,
		] );
		set_transient( 'tga_github_update_check', $response, 12 * HOUR_IN_SECONDS );
	}

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return $transient;
	}

	$release = json_decode( wp_remote_retrieve_body( $response ) );
	if ( empty( $release->tag_name ) ) {
		return $transient;
	}

	$remote_version = ltrim( $release->tag_name, 'v' );

	if ( version_compare( $remote_version, TGA_VERSION, '>' ) ) {
		$zip_url = ! empty( $release->assets[0]->browser_download_url )
			? $release->assets[0]->browser_download_url
			: "https://github.com/leoncons/tinyjoy-gift-assistant/archive/refs/tags/{$release->tag_name}.zip";

		$transient->response[ $plugin_slug ] = (object) [
			'slug'         => 'tinyjoy-gift-assistant',
			'plugin'       => $plugin_slug,
			'new_version'  => $remote_version,
			'url'          => 'https://github.com/leoncons/tinyjoy-gift-assistant',
			'package'      => $zip_url,
			'icons'        => [],
			'banners'      => [],
			'requires'     => '6.0',
			'requires_php' => '8.0',
			'tested'       => get_bloginfo( 'version' ),
		];
	}

	return $transient;
}

add_filter( 'plugins_api', 'tga_plugin_api_info', 20, 3 );
function tga_plugin_api_info( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || 'tinyjoy-gift-assistant' !== ( $args->slug ?? '' ) ) {
		return $result;
	}

	$response = wp_remote_get(
		'https://api.github.com/repos/leoncons/tinyjoy-gift-assistant/releases/latest',
		[ 'headers' => [ 'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) ], 'timeout' => 10 ]
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return $result;
	}

	$release = json_decode( wp_remote_retrieve_body( $response ) );
	if ( empty( $release->tag_name ) ) {
		return $result;
	}

	$zip_url = ! empty( $release->assets[0]->browser_download_url )
		? $release->assets[0]->browser_download_url
		: "https://github.com/leoncons/tinyjoy-gift-assistant/archive/refs/tags/{$release->tag_name}.zip";

	return (object) [
		'name'          => 'TinyJoy Gift Assistant',
		'slug'          => 'tinyjoy-gift-assistant',
		'version'       => ltrim( $release->tag_name, 'v' ),
		'author'        => '<a href="https://tinyjoygifts.com">TinyJoy</a>',
		'homepage'      => 'https://tinyjoygifts.com',
		'requires'      => '6.0',
		'requires_php'  => '8.0',
		'download_link' => $zip_url,
		'sections'      => [
			'description' => 'AI-powered gift finder for TinyJoy.',
			'changelog'   => nl2br( esc_html( $release->body ?? '' ) ),
		],
	];
}

// Clear cached check after any plugin upgrade so new version is detected immediately.
add_action( 'upgrader_process_complete', 'tga_clear_update_cache', 10, 2 );
function tga_clear_update_cache( $upgrader, $options ): void {
	if ( 'plugin' === ( $options['type'] ?? '' ) ) {
		delete_transient( 'tga_github_update_check' );
	}
}

// ─── ADMIN ────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'tga_admin_menu' );
function tga_admin_menu(): void {
	add_options_page(
		'Gift Assistant',
		'Gift Assistant',
		'manage_options',
		'tinyjoy-gift-assistant',
		'tga_settings_page'
	);
}

add_action( 'admin_init', 'tga_register_settings' );
function tga_register_settings(): void {
	register_setting( 'tga_settings', 'tga_anthropic_api_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
	register_setting( 'tga_settings', 'tga_show_widget',       [ 'sanitize_callback' => 'absint', 'default' => 1 ] );
	register_setting( 'tga_settings', 'tga_max_results',       [ 'sanitize_callback' => 'absint', 'default' => 3 ] );
	register_setting( 'tga_settings', 'tga_widget_label',      [ 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Find a Gift' ] );
	register_setting( 'tga_settings', 'tga_ai_tier',           [ 'sanitize_callback' => 'sanitize_text_field', 'default' => 'free' ] );
	register_setting( 'tga_settings', 'tga_ai_provider',       [ 'sanitize_callback' => 'sanitize_text_field', 'default' => 'groq' ] );
	register_setting( 'tga_settings', 'tga_groq_api_key',      [ 'sanitize_callback' => 'sanitize_text_field' ] );
	register_setting( 'tga_settings', 'tga_gemini_api_key',    [ 'sanitize_callback' => 'sanitize_text_field' ] );
	register_setting( 'tga_settings', 'tga_openai_api_key',    [ 'sanitize_callback' => 'sanitize_text_field' ] );
}

function tga_settings_page(): void {
	$tier     = get_option( 'tga_ai_tier', 'free' );
	$provider = get_option( 'tga_ai_provider', 'groq' );

	// Normalise: ensure provider is valid for the saved tier.
	if ( $tier === 'free' && ! in_array( $provider, [ 'groq' ], true ) ) {
		$provider = 'groq';
	}
	if ( $tier === 'paid' && ! in_array( $provider, [ 'anthropic', 'gemini', 'openai' ], true ) ) {
		$provider = 'anthropic';
	}

	$ant_key  = get_option( 'tga_anthropic_api_key', '' );
	$groq_key = get_option( 'tga_groq_api_key', '' );
	$gem_key  = get_option( 'tga_gemini_api_key', '' );
	$oai_key  = get_option( 'tga_openai_api_key', '' );
	?>
	<div class="wrap">
	<h1>🎁 TinyJoy Gift Assistant</h1>

	<style>
	.tga-s { margin-top: 28px; }
	.tga-s > h2 { font-size: 15px; font-weight: 600; border-bottom: 1px solid #dcdcde; padding-bottom: 10px; margin-bottom: 20px; }
	/* Tier cards */
	.tga-tier-row { display: flex; gap: 12px; margin-bottom: 24px; }
	.tga-tier-lbl { flex: 1; max-width: 230px; display: flex; align-items: flex-start; gap: 12px; padding: 16px 18px; border: 2px solid #dcdcde; border-radius: 8px; cursor: pointer; transition: border-color .15s, background .15s; }
	.tga-tier-lbl:hover { border-color: #999; }
	.tga-tier-lbl.tga-on { border-color: #2271b1; background: #f0f6fc; }
	.tga-tier-lbl input { margin-top: 3px; cursor: pointer; flex-shrink: 0; }
	.tga-tier-body strong { display: block; font-size: 14px; margin-bottom: 3px; }
	.tga-tier-body span { display: block; color: #555; font-size: 12px; line-height: 1.4; }
	/* Provider cards */
	.tga-prov-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
	.tga-prov-lbl { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border: 2px solid #dcdcde; border-radius: 8px; cursor: pointer; min-width: 148px; transition: border-color .15s, background .15s; }
	.tga-prov-lbl:not(.tga-soon):hover { border-color: #999; }
	.tga-prov-lbl.tga-on { border-color: #2271b1; background: #f0f6fc; }
	.tga-prov-lbl.tga-soon { opacity: .45; cursor: default; pointer-events: none; }
	.tga-prov-logo { font-size: 22px; line-height: 1; flex-shrink: 0; }
	.tga-prov-body strong { display: block; font-size: 13px; }
	.tga-prov-body small { color: #777; font-size: 11px; }
	/* Key panel */
	.tga-key-panel { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 20px 24px; margin-bottom: 6px; }
	.tga-key-panel > label { font-weight: 600; font-size: 13px; display: block; margin-bottom: 8px; }
	/* Instructions */
	.tga-instr { margin-top: 16px; background: #f6f7f7; border-left: 3px solid #2271b1; padding: 14px 18px; border-radius: 0 6px 6px 0; }
	.tga-instr h4 { margin: 0 0 10px; font-size: 13px; color: #1d2327; }
	.tga-instr ol { margin: 0 0 10px; padding-left: 20px; }
	.tga-instr ol li { font-size: 13px; color: #444; margin-bottom: 5px; }
	.tga-instr .tga-meta { font-size: 12px; color: #666; margin: 8px 0 0; }
	/* Badges */
	.tga-badge { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 11px; font-weight: 600; vertical-align: middle; margin-left: 4px; }
	.tga-badge-free { background: #d1fae5; color: #065f46; }
	.tga-badge-paid { background: #fef3c7; color: #92400e; }
	.tga-badge-soon { background: #f3f4f6; color: #6b7280; font-weight: 500; }
	</style>

	<form method="post" action="options.php">
	<?php settings_fields( 'tga_settings' ); ?>

	<!-- ── Display ──────────────────────────────── -->
	<div class="tga-s">
		<h2>Display</h2>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="tga_show_widget">Floating Widget</label></th>
				<td>
					<label>
						<input type="checkbox" id="tga_show_widget" name="tga_show_widget" value="1"
							<?php checked( 1, get_option( 'tga_show_widget', 1 ) ); ?> />
						Show the 🎁 "Find a Gift" button on all pages
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tga_widget_label">Button Label</label></th>
				<td>
					<input type="text" id="tga_widget_label" name="tga_widget_label"
						value="<?php echo esc_attr( get_option( 'tga_widget_label', 'Find a Gift' ) ); ?>"
						class="regular-text" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tga_max_results">Max Products Shown</label></th>
				<td>
					<input type="number" id="tga_max_results" name="tga_max_results" min="1" max="6"
						value="<?php echo esc_attr( get_option( 'tga_max_results', 3 ) ); ?>"
						class="small-text" />
					<p class="description">How many matching products to show per search (1–6).</p>
				</td>
			</tr>
		</table>
	</div>

	<!-- ── AI Messages ──────────────────────────── -->
	<div class="tga-s">
		<h2>AI Messages</h2>
		<p class="description" style="margin-bottom: 20px;">
			A short personalised message appears above search results. Select a plan, choose a provider, and paste your API key.
			Leave the key blank to disable AI messages.
		</p>

		<!-- Tier selector -->
		<div class="tga-tier-row">
			<label class="tga-tier-lbl <?php echo $tier === 'free' ? 'tga-on' : ''; ?>">
				<input type="radio" name="tga_ai_tier" value="free" <?php checked( $tier, 'free' ); ?>>
				<div class="tga-tier-body">
					<strong>🆓 Free</strong>
					<span>Open-source models with generous free tiers — no credit card needed</span>
				</div>
			</label>
			<label class="tga-tier-lbl <?php echo $tier === 'paid' ? 'tga-on' : ''; ?>">
				<input type="radio" name="tga_ai_tier" value="paid" <?php checked( $tier, 'paid' ); ?>>
				<div class="tga-tier-body">
					<strong>✨ Paid</strong>
					<span>Premium commercial APIs — higher quality &amp; reliability</span>
				</div>
			</label>
		</div>

		<!-- ── Free providers ──── -->
		<div id="tga-free" <?php echo $tier !== 'free' ? 'style="display:none"' : ''; ?>>
			<p style="margin: 0 0 12px; font-size: 13px; color: #444;">Choose your AI provider:</p>
			<div class="tga-prov-row">
				<label class="tga-prov-lbl <?php echo ( $tier === 'free' && $provider === 'groq' ) ? 'tga-on' : ''; ?>">
					<input type="radio" name="tga_ai_provider" value="groq" <?php checked( $provider, 'groq' ); ?>>
					<span class="tga-prov-logo">⚡</span>
					<div class="tga-prov-body">
						<strong>Groq</strong>
						<small>Fast LLaMA · Free</small>
					</div>
				</label>
				<div class="tga-prov-lbl tga-soon">
					<span class="tga-prov-logo">☁️</span>
					<div class="tga-prov-body">
						<strong>Cloudflare AI <span class="tga-badge tga-badge-soon">Soon</span></strong>
						<small>Workers AI</small>
					</div>
				</div>
				<div class="tga-prov-lbl tga-soon">
					<span class="tga-prov-logo">🔀</span>
					<div class="tga-prov-body">
						<strong>OpenRouter <span class="tga-badge tga-badge-soon">Soon</span></strong>
						<small>Multi-model</small>
					</div>
				</div>
			</div>

			<!-- Groq key panel -->
			<div id="tga-key-groq" class="tga-key-panel"
				<?php echo ( $tier !== 'free' || $provider !== 'groq' ) ? 'style="display:none"' : ''; ?>>
				<label for="tga_groq_api_key">
					Groq API Key <span class="tga-badge tga-badge-free">Free</span>
				</label>
				<input type="password" id="tga_groq_api_key" name="tga_groq_api_key"
					value="<?php echo esc_attr( $groq_key ); ?>"
					class="regular-text" autocomplete="new-password" placeholder="gsk_…" />
				<div class="tga-instr">
					<h4>🔑 How to get your free Groq API key</h4>
					<ol>
						<li>Go to <a href="https://console.groq.com" target="_blank">console.groq.com</a></li>
						<li>Create a free account — no credit card required</li>
						<li>Click <strong>API Keys</strong> in the left sidebar</li>
						<li>Click <strong>Create API Key</strong> and name it (e.g. "TinyJoy")</li>
						<li>Copy the key (starts with <code>gsk_</code>) and paste it above</li>
					</ol>
					<p class="tga-meta">Free tier: 14,400 requests/day &nbsp;·&nbsp; Model: <code>llama-3.1-8b-instant</code></p>
				</div>
			</div>
		</div>

		<!-- ── Paid providers ──── -->
		<div id="tga-paid" <?php echo $tier !== 'paid' ? 'style="display:none"' : ''; ?>>
			<p style="margin: 0 0 12px; font-size: 13px; color: #444;">Choose your AI provider:</p>
			<div class="tga-prov-row">
				<label class="tga-prov-lbl <?php echo ( $tier === 'paid' && $provider === 'anthropic' ) ? 'tga-on' : ''; ?>">
					<input type="radio" name="tga_ai_provider" value="anthropic" <?php checked( $provider, 'anthropic' ); ?>>
					<span class="tga-prov-logo">🤖</span>
					<div class="tga-prov-body">
						<strong>Anthropic</strong>
						<small>Claude Haiku</small>
					</div>
				</label>
				<label class="tga-prov-lbl <?php echo ( $tier === 'paid' && $provider === 'gemini' ) ? 'tga-on' : ''; ?>">
					<input type="radio" name="tga_ai_provider" value="gemini" <?php checked( $provider, 'gemini' ); ?>>
					<span class="tga-prov-logo">💎</span>
					<div class="tga-prov-body">
						<strong>Gemini</strong>
						<small>1.5 Flash</small>
					</div>
				</label>
				<label class="tga-prov-lbl <?php echo ( $tier === 'paid' && $provider === 'openai' ) ? 'tga-on' : ''; ?>">
					<input type="radio" name="tga_ai_provider" value="openai" <?php checked( $provider, 'openai' ); ?>>
					<span class="tga-prov-logo">✦</span>
					<div class="tga-prov-body">
						<strong>OpenAI</strong>
						<small>GPT-4o mini</small>
					</div>
				</label>
			</div>

			<!-- Anthropic key panel -->
			<div id="tga-key-anthropic" class="tga-key-panel"
				<?php echo ( $tier !== 'paid' || $provider !== 'anthropic' ) ? 'style="display:none"' : ''; ?>>
				<label for="tga_anthropic_api_key">
					Anthropic API Key <span class="tga-badge tga-badge-paid">Paid</span>
				</label>
				<input type="password" id="tga_anthropic_api_key" name="tga_anthropic_api_key"
					value="<?php echo esc_attr( $ant_key ); ?>"
					class="regular-text" autocomplete="new-password" placeholder="sk-ant-…" />
				<div class="tga-instr">
					<h4>🔑 How to get your Anthropic API key</h4>
					<ol>
						<li>Go to <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a></li>
						<li>Sign in or create an account and add billing details</li>
						<li>Click <strong>API Keys</strong> in the left sidebar</li>
						<li>Click <strong>Create Key</strong> and give it a name</li>
						<li>Copy the key (starts with <code>sk-ant-</code>) and paste it above</li>
					</ol>
					<p class="tga-meta">Model: <code>claude-haiku-4-5</code> &nbsp;·&nbsp; ~$0.001 per message &nbsp;·&nbsp; <a href="https://www.anthropic.com/pricing" target="_blank">Pricing ↗</a></p>
				</div>
			</div>

			<!-- Gemini key panel -->
			<div id="tga-key-gemini" class="tga-key-panel"
				<?php echo ( $tier !== 'paid' || $provider !== 'gemini' ) ? 'style="display:none"' : ''; ?>>
				<label for="tga_gemini_api_key">
					Google Gemini API Key <span class="tga-badge tga-badge-paid">Paid</span>
				</label>
				<input type="password" id="tga_gemini_api_key" name="tga_gemini_api_key"
					value="<?php echo esc_attr( $gem_key ); ?>"
					class="regular-text" autocomplete="new-password" placeholder="AIza…" />
				<div class="tga-instr">
					<h4>🔑 How to get your Gemini API key</h4>
					<ol>
						<li>Go to <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a></li>
						<li>Sign in with your Google account</li>
						<li>Click <strong>Create API Key</strong></li>
						<li>Select or create a Google Cloud project when prompted</li>
						<li>Copy the key (starts with <code>AIza</code>) and paste it above</li>
					</ol>
					<p class="tga-meta">Model: <code>gemini-1.5-flash</code> &nbsp;·&nbsp; Free tier available &nbsp;·&nbsp; <a href="https://ai.google.dev/pricing" target="_blank">Pricing ↗</a></p>
				</div>
			</div>

			<!-- OpenAI key panel -->
			<div id="tga-key-openai" class="tga-key-panel"
				<?php echo ( $tier !== 'paid' || $provider !== 'openai' ) ? 'style="display:none"' : ''; ?>>
				<label for="tga_openai_api_key">
					OpenAI API Key <span class="tga-badge tga-badge-paid">Paid</span>
				</label>
				<input type="password" id="tga_openai_api_key" name="tga_openai_api_key"
					value="<?php echo esc_attr( $oai_key ); ?>"
					class="regular-text" autocomplete="new-password" placeholder="sk-…" />
				<div class="tga-instr">
					<h4>🔑 How to get your OpenAI API key</h4>
					<ol>
						<li>Go to <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com/api-keys</a></li>
						<li>Sign in or create an account, then add a payment method</li>
						<li>Click <strong>+ Create new secret key</strong></li>
						<li>Name it (e.g. "TinyJoy") and click <strong>Create</strong></li>
						<li>Copy the key (starts with <code>sk-</code>) and paste it above</li>
					</ol>
					<p class="tga-meta">Model: <code>gpt-4o-mini</code> &nbsp;·&nbsp; ~$0.0003 per message &nbsp;·&nbsp; <a href="https://openai.com/pricing" target="_blank">Pricing ↗</a></p>
				</div>
			</div>
		</div>
	</div>

	<?php submit_button(); ?>
	</form>

	<hr style="margin: 32px 0 24px;" />
	<h2>Shortcode</h2>
	<p>Embed the full Gift Finder wizard on any page or post:</p>
	<code>[tinyjoy_gift_finder]</code>

	<h2 style="margin-top:24px;">Tag Schema</h2>
	<p>For matching to work, tag your WooCommerce products using these slugs:</p>

	<h3>Occasion (custom taxonomy: <code>occasion</code>)</h3>
	<table class="widefat" style="max-width:600px;">
		<thead><tr><th>Slug</th><th>Label</th></tr></thead>
		<tbody>
			<?php
			$occasions = [
				'birthday'       => 'Birthday',
				'anniversary'    => 'Anniversary',
				'christmas'      => 'Christmas / Holiday',
				'thank-you'      => 'Thank You',
				'graduation'     => 'Graduation',
				'housewarming'   => 'Housewarming',
				'valentines-day' => "Valentine's Day",
				'mothers-day'    => "Mother's Day",
				'fathers-day'    => "Father's Day",
				'baby-shower'    => 'Baby Shower',
				'just-because'   => 'Just Because',
				'wedding'        => 'Wedding',
			];
			foreach ( $occasions as $slug => $label ) {
				echo "<tr><td><code>{$slug}</code></td><td>{$label}</td></tr>";
			}
			?>
		</tbody>
	</table>

	<h3 style="margin-top:16px;">Recipient (standard product tag)</h3>
	<p><code>for-mom</code> &nbsp; <code>for-dad</code> &nbsp; <code>for-partner</code> &nbsp; <code>for-friend</code> &nbsp; <code>for-coworker</code> &nbsp; <code>for-teacher</code> &nbsp; <code>for-teen</code> &nbsp; <code>for-kids</code></p>

	<h3>Vibe (standard product tag)</h3>
	<p><code>sentimental</code> &nbsp; <code>funny</code> &nbsp; <code>cute</code> &nbsp; <code>practical</code> &nbsp; <code>creative</code> &nbsp; <code>personalized</code></p>

	<script>
	(function () {
		var tierRadios = document.querySelectorAll('[name="tga_ai_tier"]');
		var provRadios = document.querySelectorAll('[name="tga_ai_provider"]');

		// ── Tier switching ──────────────────────────
		tierRadios.forEach(function (r) {
			r.addEventListener('change', function () {
				var tier = this.value;
				document.querySelectorAll('.tga-tier-lbl').forEach(function (lbl) {
					lbl.classList.toggle('tga-on', lbl.querySelector('input').value === tier);
				});
				document.getElementById('tga-free').style.display = tier === 'free' ? '' : 'none';
				document.getElementById('tga-paid').style.display = tier === 'paid' ? '' : 'none';
				// Auto-select default provider for the chosen tier
				var defaults = { free: 'groq', paid: 'anthropic' };
				var defRadio = document.querySelector('[name="tga_ai_provider"][value="' + defaults[tier] + '"]');
				if (defRadio) {
					defRadio.checked = true;
					defRadio.dispatchEvent(new Event('change'));
				}
			});
		});

		// ── Provider switching ──────────────────────
		provRadios.forEach(function (r) {
			r.addEventListener('change', function () {
				var prov = this.value;
				document.querySelectorAll('.tga-prov-lbl input[type="radio"]').forEach(function (input) {
					input.closest('.tga-prov-lbl').classList.toggle('tga-on', input.value === prov);
				});
				['groq', 'anthropic', 'gemini', 'openai'].forEach(function (p) {
					var el = document.getElementById('tga-key-' + p);
					if (el) el.style.display = p === prov ? '' : 'none';
				});
			});
		});
	})();
	</script>
	</div>
	<?php
}

// ─── ASSETS ───────────────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', 'tga_enqueue_assets' );
function tga_enqueue_assets(): void {
	wp_enqueue_style(
		'tga-styles',
		TGA_PLUGIN_URL . 'assets/gift-assistant.css',
		[],
		TGA_VERSION
	);
	wp_enqueue_script(
		'tga-script',
		TGA_PLUGIN_URL . 'assets/gift-assistant.js',
		[ 'jquery' ],
		TGA_VERSION,
		true
	);
	wp_localize_script( 'tga-script', 'TGA', [
		'ajaxurl'  => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'tga_find_gifts' ),
		'currency' => get_woocommerce_currency_symbol(),
	] );
}

// ─── SHORTCODE ────────────────────────────────────────────────────────────────

add_shortcode( 'tinyjoy_gift_finder', 'tga_render_shortcode' );
function tga_render_shortcode( array $atts ): string {
	return tga_render_wizard( 'inline' );
}

// ─── FLOATING WIDGET ──────────────────────────────────────────────────────────

add_action( 'wp_footer', 'tga_render_floating_widget' );
function tga_render_floating_widget(): void {
	if ( ! get_option( 'tga_show_widget', 1 ) ) return;
	$label = esc_html( get_option( 'tga_widget_label', 'Find a Gift' ) );
	echo '<button id="tga-float-btn" class="tga-float-btn" aria-label="' . $label . '" aria-expanded="false">'
		. '<span class="tga-float-icon">🎁</span>'
		. '<span class="tga-float-label">' . $label . '</span>'
		. '</button>';
	echo tga_render_wizard( 'float' );
}

// ─── WIZARD HTML ──────────────────────────────────────────────────────────────

function tga_render_wizard( string $mode ): string {
	$panel_id    = $mode === 'float' ? 'tga-panel-float' : 'tga-panel-inline';
	$panel_class = 'tga-panel tga-panel--' . $mode;

	ob_start();
	?>
	<div id="<?php echo $panel_id; ?>" class="<?php echo $panel_class; ?>" role="dialog" aria-modal="true" aria-label="Gift Assistant" hidden>

		<?php if ( $mode === 'float' ) : ?>
		<div class="tga-header">
			<span class="tga-header-title">🎁 Gift Assistant</span>
			<button class="tga-close-btn" aria-label="Close">✕</button>
		</div>
		<?php else : ?>
		<div class="tga-header tga-header--inline">
			<span class="tga-header-title">🎁 TinyJoy Gift Finder</span>
			<p class="tga-header-sub">Answer 4 quick questions — we'll find the perfect match.</p>
		</div>
		<?php endif; ?>

		<div class="tga-progress-bar-wrap" aria-hidden="true">
			<div class="tga-progress-bar" style="width:25%"></div>
		</div>

		<div class="tga-wizard-body">

			<!-- Step 1: Recipient -->
			<div class="tga-step" data-step="1">
				<p class="tga-step-label">Step 1 of 4</p>
				<h3 class="tga-step-title">Who's the gift for?</h3>
				<div class="tga-options" data-field="recipient">
					<?php
					$recipients = [
						'anyone'   => 'Anyone 🌟',
						'mom'      => 'Mom 💕',
						'dad'      => 'Dad 🔧',
						'partner'  => 'Partner ❤️',
						'friend'   => 'Friend 🤝',
						'coworker' => 'Coworker 💼',
						'teacher'  => 'Teacher 🍎',
						'teen'     => 'Teen 🎮',
						'kids'     => 'Kids 🧸',
					];
					foreach ( $recipients as $val => $label ) :
					?>
					<button class="tga-option" data-field="recipient" data-value="<?php echo esc_attr( $val ); ?>">
						<?php echo esc_html( $label ); ?>
					</button>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Step 2: Occasion -->
			<div class="tga-step" data-step="2" hidden>
				<p class="tga-step-label">Step 2 of 4</p>
				<h3 class="tga-step-title">What's the occasion?</h3>
				<div class="tga-options" data-field="occasion">
					<?php
					$occasions = [
						'any'            => 'Any occasion',
						'birthday'       => 'Birthday 🎂',
						'anniversary'    => 'Anniversary 💍',
						'christmas'      => 'Christmas 🎄',
						'thank-you'      => 'Thank You 🙏',
						'graduation'     => 'Graduation 🎓',
						'housewarming'   => 'Housewarming 🏠',
						'valentines-day' => "Valentine's 💝",
						'mothers-day'    => "Mother's Day 🌷",
						'fathers-day'    => "Father's Day 👔",
						'baby-shower'    => 'Baby Shower 👶',
						'just-because'   => 'Just Because ✨',
					];
					foreach ( $occasions as $val => $label ) :
					?>
					<button class="tga-option" data-field="occasion" data-value="<?php echo esc_attr( $val ); ?>">
						<?php echo esc_html( $label ); ?>
					</button>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Step 3: Vibe -->
			<div class="tga-step" data-step="3" hidden>
				<p class="tga-step-label">Step 3 of 4</p>
				<h3 class="tga-step-title">What's their vibe?</h3>
				<div class="tga-options" data-field="vibe">
					<?php
					$vibes = [
						'any'          => 'Any vibe ✨',
						'sentimental'  => 'Sentimental 🥹',
						'funny'        => 'Funny / Playful 😄',
						'cute'         => 'Cute & Sweet 🌸',
						'practical'    => 'Practical 🛠️',
						'creative'     => 'Creative 🎨',
						'personalized' => 'Personalized 🖊️',
					];
					foreach ( $vibes as $val => $label ) :
					?>
					<button class="tga-option" data-field="vibe" data-value="<?php echo esc_attr( $val ); ?>">
						<?php echo esc_html( $label ); ?>
					</button>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Step 4: Budget -->
			<div class="tga-step" data-step="4" hidden>
				<p class="tga-step-label">Step 4 of 4</p>
				<h3 class="tga-step-title">What's your budget?</h3>
				<div class="tga-options" data-field="budget">
					<?php
					$budgets = [
						'any'       => 'Any budget',
						'under-25'  => 'Under $25',
						'under-50'  => 'Under $50',
						'under-100' => 'Under $100',
						'100-plus'  => '$100+',
					];
					foreach ( $budgets as $val => $label ) :
					?>
					<button class="tga-option" data-field="budget" data-value="<?php echo esc_attr( $val ); ?>">
						<?php echo esc_html( $label ); ?>
					</button>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Loading -->
			<div class="tga-loading" hidden>
				<div class="tga-spinner"></div>
				<p>Finding the perfect gift…</p>
			</div>

			<!-- Results -->
			<div class="tga-results" hidden>
				<div class="tga-ai-message"></div>
				<div class="tga-products-grid"></div>
				<div class="tga-results-footer">
					<button class="tga-restart-btn">🔄 Try different filters</button>
				</div>
			</div>

			<!-- Empty state -->
			<div class="tga-empty" hidden>
				<p>😕 No products matched those filters yet — check back as we add more gifts!</p>
				<button class="tga-restart-btn">Try again</button>
			</div>

		</div><!-- .tga-wizard-body -->
	</div><!-- .tga-panel -->
	<?php
	return ob_get_clean();
}

// ─── AJAX HANDLER ─────────────────────────────────────────────────────────────

add_action( 'wp_ajax_tga_find_gifts',        'tga_handle_find_gifts' );
add_action( 'wp_ajax_nopriv_tga_find_gifts', 'tga_handle_find_gifts' );

function tga_handle_find_gifts(): void {
	check_ajax_referer( 'tga_find_gifts', 'nonce' );

	$recipient = sanitize_text_field( $_POST['recipient'] ?? '' );
	$occasion  = sanitize_text_field( $_POST['occasion']  ?? '' );
	$vibe      = sanitize_text_field( $_POST['vibe']      ?? '' );
	$budget    = sanitize_text_field( $_POST['budget']    ?? '' );

	// Progressive fallback: full match → drop vibe → drop occasion → drop recipient
	$products = tga_query_products( $recipient, $occasion, $vibe, $budget );

	if ( empty( $products ) && $vibe && $vibe !== 'any' ) {
		$products = tga_query_products( $recipient, $occasion, '', $budget );
	}
	if ( empty( $products ) && $occasion && $occasion !== 'any' ) {
		$products = tga_query_products( $recipient, '', '', $budget );
	}
	if ( empty( $products ) && $recipient && $recipient !== 'anyone' ) {
		$products = tga_query_products( '', '', '', $budget );
	}

	if ( empty( $products ) ) {
		wp_send_json_success( [ 'products' => [], 'message' => '' ] );
	}

	$message = tga_get_ai_message( $recipient, $occasion, $vibe, $budget, $products );

	wp_send_json_success( [
		'products' => $products,
		'message'  => $message,
	] );
}

// ─── PRODUCT QUERY ────────────────────────────────────────────────────────────

function tga_query_products( string $recipient, string $occasion, string $vibe, string $budget ): array {
	$args = [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => absint( get_option( 'tga_max_results', 3 ) ),
		'orderby'        => 'rand',
	];

	// Build taxonomy query
	$tax_query  = [ 'relation' => 'AND' ];
	$tag_terms  = [];

	if ( $recipient && $recipient !== 'anyone' ) {
		$tag_terms[] = 'for-' . $recipient;
	}
	if ( $vibe && $vibe !== 'any' ) {
		$tag_terms[] = $vibe;
	}
	if ( ! empty( $tag_terms ) ) {
		$tax_query[] = [
			'taxonomy' => 'product_tag',
			'field'    => 'slug',
			'terms'    => $tag_terms,
			'operator' => count( $tag_terms ) > 1 ? 'AND' : 'IN',
		];
	}
	if ( $occasion && $occasion !== 'any' ) {
		$tax_query[] = [
			'taxonomy' => 'occasion',
			'field'    => 'slug',
			'terms'    => [ $occasion ],
		];
	}
	if ( count( $tax_query ) > 1 ) {
		$args['tax_query'] = $tax_query;
	}

	// Budget filter via price meta
	$budget_map = [
		'under-25'  => [ 'max' => 25 ],
		'under-50'  => [ 'max' => 50 ],
		'under-100' => [ 'max' => 100 ],
		'100-plus'  => [ 'min' => 100 ],
	];
	if ( isset( $budget_map[ $budget ] ) ) {
		$range = $budget_map[ $budget ];
		$meta  = [ 'relation' => 'AND' ];
		if ( isset( $range['max'] ) ) {
			$meta[] = [ 'key' => '_price', 'value' => $range['max'], 'compare' => '<=', 'type' => 'NUMERIC' ];
		}
		if ( isset( $range['min'] ) ) {
			$meta[] = [ 'key' => '_price', 'value' => $range['min'], 'compare' => '>=', 'type' => 'NUMERIC' ];
		}
		$args['meta_query'] = $meta;
	}

	$query   = new WP_Query( $args );
	$results = [];

	foreach ( $query->posts as $post ) {
		$product = wc_get_product( $post->ID );
		if ( ! $product || ! $product->is_purchasable() ) continue;

		$thumb_id = get_post_thumbnail_id( $post->ID );
		$image    = $thumb_id
			? wp_get_attachment_image_url( $thumb_id, 'woocommerce_thumbnail' )
			: wc_placeholder_img_src( 'woocommerce_thumbnail' );

		$results[] = [
			'id'          => $post->ID,
			'name'        => $product->get_name(),
			'price_html'  => $product->get_price_html(),
			'url'         => get_permalink( $post->ID ),
			'image'       => esc_url( $image ),
			'short_desc'  => wp_strip_all_tags( wp_trim_words(
				$product->get_short_description() ?: $product->get_description(),
				12
			) ),
		];
	}

	return $results;
}

// ─── AI MESSAGE ───────────────────────────────────────────────────────────────

function tga_ai_build_prompt( string $recipient, string $occasion, string $vibe, string $budget, array $products ): string {
	$product_names = implode( ', ', array_column( $products, 'name' ) );
	$labels = [
		'recipient' => $recipient && $recipient !== 'anyone' ? $recipient : 'someone special',
		'occasion'  => $occasion  && $occasion  !== 'any'   ? str_replace( '-', ' ', $occasion ) : 'any occasion',
		'vibe'      => $vibe      && $vibe       !== 'any'   ? $vibe : 'any style',
		'budget'    => match ( $budget ) {
			'under-25'  => 'under $25',
			'under-50'  => 'under $50',
			'under-100' => 'under $100',
			'100-plus'  => '$100+',
			default     => 'any budget',
		},
	];
	return "You are TinyJoy's warm and friendly Gift Assistant. TinyJoy sells small, customizable gifts — tagline: 'Small gifts, Big Smiles'.\n\n"
		. "A customer wants a gift for {$labels['recipient']} for {$labels['occasion']}. "
		. "They want something {$labels['vibe']} with a budget of {$labels['budget']}.\n\n"
		. "We matched these TinyJoy products: {$product_names}.\n\n"
		. "Write exactly 2 warm sentences: (1) Why these gifts are perfect for this person and occasion. "
		. "(2) A gentle nudge to personalize it (add a name, photo, or message). "
		. "Sound like a thoughtful friend, not a salesperson. No lists, no headers.";
}

function tga_ai_groq( string $prompt ): string {
	$api_key = get_option( 'tga_groq_api_key', '' );
	if ( ! $api_key ) return '';
	$response = wp_remote_post( 'https://api.groq.com/openai/v1/chat/completions', [
		'timeout' => 15,
		'headers' => [
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		],
		'body' => wp_json_encode( [
			'model'      => 'llama-3.1-8b-instant',
			'max_tokens' => 180,
			'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
		] ),
	] );
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) return '';
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return wp_kses( $body['choices'][0]['message']['content'] ?? '', [] );
}

function tga_ai_anthropic( string $prompt ): string {
	$api_key = get_option( 'tga_anthropic_api_key', '' );
	if ( ! $api_key ) return '';
	$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
		'timeout' => 15,
		'headers' => [
			'x-api-key'         => $api_key,
			'anthropic-version' => '2023-06-01',
			'content-type'      => 'application/json',
		],
		'body' => wp_json_encode( [
			'model'      => 'claude-haiku-4-5-20251001',
			'max_tokens' => 180,
			'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
		] ),
	] );
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) return '';
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return wp_kses( $body['content'][0]['text'] ?? '', [] );
}

function tga_ai_gemini( string $prompt ): string {
	$api_key = get_option( 'tga_gemini_api_key', '' );
	if ( ! $api_key ) return '';
	$url      = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . rawurlencode( $api_key );
	$response = wp_remote_post( $url, [
		'timeout' => 15,
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => wp_json_encode( [
			'contents'         => [ [ 'parts' => [ [ 'text' => $prompt ] ] ] ],
			'generationConfig' => [ 'maxOutputTokens' => 180 ],
		] ),
	] );
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) return '';
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return wp_kses( $body['candidates'][0]['content']['parts'][0]['text'] ?? '', [] );
}

function tga_ai_openai( string $prompt ): string {
	$api_key = get_option( 'tga_openai_api_key', '' );
	if ( ! $api_key ) return '';
	$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
		'timeout' => 15,
		'headers' => [
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		],
		'body' => wp_json_encode( [
			'model'      => 'gpt-4o-mini',
			'max_tokens' => 180,
			'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
		] ),
	] );
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) return '';
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return wp_kses( $body['choices'][0]['message']['content'] ?? '', [] );
}

function tga_get_ai_message( string $recipient, string $occasion, string $vibe, string $budget, array $products ): string {
	$prompt   = tga_ai_build_prompt( $recipient, $occasion, $vibe, $budget, $products );
	$provider = get_option( 'tga_ai_provider', 'groq' );
	return match ( $provider ) {
		'anthropic' => tga_ai_anthropic( $prompt ),
		'gemini'    => tga_ai_gemini( $prompt ),
		'openai'    => tga_ai_openai( $prompt ),
		default     => tga_ai_groq( $prompt ),
	};
}
