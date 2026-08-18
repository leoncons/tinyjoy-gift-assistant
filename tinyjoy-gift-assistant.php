<?php
/**
 * Plugin Name: TinyJoy Gift Assistant
 * Plugin URI:  https://tinyjoygifts.com
 * Description: AI-powered gift finder — recommends TinyJoy products by recipient, occasion, vibe, and budget. Includes floating widget + shortcode.
 * Version:     1.0.0
 * Author:      TinyJoy
 * Text Domain: tinyjoy-gift-assistant
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * GitHub Plugin URI: leoncons/tinyjoy-gift-assistant
 * GitHub Branch:     main
 */

defined( 'ABSPATH' ) || exit;

define( 'TGA_VERSION',    '1.0.0' );
define( 'TGA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TGA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

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
}

function tga_settings_page(): void {
	?>
	<div class="wrap">
		<h1>🎁 TinyJoy Gift Assistant</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'tga_settings' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="tga_anthropic_api_key">Anthropic API Key</label></th>
					<td>
						<input type="password" id="tga_anthropic_api_key" name="tga_anthropic_api_key"
							value="<?php echo esc_attr( get_option( 'tga_anthropic_api_key', '' ) ); ?>"
							class="regular-text" autocomplete="new-password" />
						<p class="description">Get your key at <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>. Used to generate personalized gift messages. Leave blank to skip AI messages.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tga_show_widget">Floating Widget</label></th>
					<td>
						<label>
							<input type="checkbox" id="tga_show_widget" name="tga_show_widget" value="1"
								<?php checked( 1, get_option( 'tga_show_widget', 1 ) ); ?> />
							Show the floating "Find a Gift" button on all pages
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tga_widget_label">Widget Button Label</label></th>
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
			<?php submit_button(); ?>
		</form>

		<hr />
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
					'birthday'      => 'Birthday',
					'anniversary'   => 'Anniversary',
					'christmas'     => 'Christmas / Holiday',
					'thank-you'     => 'Thank You',
					'graduation'    => 'Graduation',
					'housewarming'  => 'Housewarming',
					'valentines-day'=> "Valentine's Day",
					'mothers-day'   => "Mother's Day",
					'fathers-day'   => "Father's Day",
					'baby-shower'   => 'Baby Shower',
					'just-because'  => 'Just Because',
					'wedding'       => 'Wedding',
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

// ─── ANTHROPIC AI MESSAGE ─────────────────────────────────────────────────────

function tga_get_ai_message( string $recipient, string $occasion, string $vibe, string $budget, array $products ): string {
	$api_key = get_option( 'tga_anthropic_api_key', '' );
	if ( ! $api_key ) return '';

	$product_names = implode( ', ', array_column( $products, 'name' ) );

	$labels = [
		'recipient' => $recipient && $recipient !== 'anyone' ? $recipient : 'someone special',
		'occasion'  => $occasion  && $occasion  !== 'any'   ? str_replace( '-', ' ', $occasion )   : 'any occasion',
		'vibe'      => $vibe      && $vibe       !== 'any'   ? $vibe                                 : 'any style',
		'budget'    => match( $budget ) {
			'under-25'  => 'under $25',
			'under-50'  => 'under $50',
			'under-100' => 'under $100',
			'100-plus'  => '$100+',
			default     => 'any budget',
		},
	];

	$prompt = "You are TinyJoy's warm and friendly Gift Assistant. TinyJoy sells small, customizable gifts — tagline: 'Small gifts, Big Smiles'.\n\n"
		. "A customer wants a gift for {$labels['recipient']} for {$labels['occasion']}. "
		. "They want something {$labels['vibe']} with a budget of {$labels['budget']}.\n\n"
		. "We matched these TinyJoy products: {$product_names}.\n\n"
		. "Write exactly 2 warm sentences: (1) Why these gifts are perfect for this person and occasion. "
		. "(2) A gentle nudge to personalize it (add a name, photo, or message). "
		. "Sound like a thoughtful friend, not a salesperson. No lists, no headers.";

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
			'messages'   => [
				[ 'role' => 'user', 'content' => $prompt ],
			],
		] ),
	] );

	if ( is_wp_error( $response ) ) return '';
	$code = wp_remote_retrieve_response_code( $response );
	if ( $code !== 200 ) return '';

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return wp_kses( $body['content'][0]['text'] ?? '', [] );
}
