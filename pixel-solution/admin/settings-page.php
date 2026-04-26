<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_init', 'pixel_solution_register_settings' );
function pixel_solution_register_settings() {
	register_setting( 'mcs_pixel_options', 'mcs_pixel_id', [
		'sanitize_callback' => 'sanitize_text_field',
	] );
	register_setting( 'mcs_pixel_options', 'mcs_capi_token', [
		'sanitize_callback' => 'sanitize_text_field',
	] );
	register_setting( 'mcs_pixel_options', 'mcs_test_event_code', [
		'sanitize_callback' => 'sanitize_text_field',
	] );
	register_setting( 'mcs_pixel_options', 'mcs_pixel_hook_map', [
		'sanitize_callback' => 'mcs_sanitize_hook_map',
	] );
	register_setting( 'mcs_pixel_options', 'mcs_form_events', [
		'sanitize_callback' => 'mcs_sanitize_form_events',
	] );
	register_setting( 'mcs_pixel_options', 'mcs_woo_events', [
		'sanitize_callback' => 'mcs_sanitize_woo_events',
	] );
}

function mcs_sanitize_hook_map( $value ) {
	$decoded = json_decode( $value, true );
	if ( ! is_array( $decoded ) ) {
		return '';
	}
	$clean = [];
	foreach ( $decoded as $entry ) {
		if ( empty( $entry['hook'] ) || empty( $entry['event'] ) ) {
			continue;
		}
		$clean[] = [
			'label'   => sanitize_text_field( $entry['label'] ?? '' ),
			'hook'    => sanitize_text_field( $entry['hook'] ),
			'event'   => sanitize_text_field( $entry['event'] ),
			'enabled' => ! empty( $entry['enabled'] ),
		];
	}
	return wp_json_encode( $clean );
}

function mcs_sanitize_form_events( $value ) {
	$decoded = json_decode( $value, true );
	if ( ! is_array( $decoded ) ) {
		return '{}';
	}
	$allowed = [
		'Lead', 'CompleteRegistration', 'Contact', 'Subscribe',
		'SubmitApplication', 'Purchase', 'AddToCart', 'InitiateCheckout',
	];
	$clean = [];
	foreach ( $decoded as $plugin => $events ) {
		if ( ! is_array( $events ) ) continue;
		$key           = sanitize_key( $plugin );
		$clean[ $key ] = array_values( array_filter(
			array_map( 'sanitize_text_field', $events ),
			fn( $e ) => in_array( $e, $allowed, true )
		) );
	}
	return wp_json_encode( $clean );
}

function mcs_sanitize_woo_events( $value ) {
	$decoded  = json_decode( $value, true );
	if ( ! is_array( $decoded ) ) {
		return '{}';
	}
	$allowed = [ 'add_to_cart', 'checkout', 'purchase', 'view_category' ];
	$clean   = [];
	foreach ( $decoded as $key => $enabled ) {
		if ( in_array( $key, $allowed, true ) ) {
			$clean[ sanitize_key( $key ) ] = (bool) $enabled;
		}
	}
	return wp_json_encode( $clean );
}

function mcs_detect_form_plugins() {
	return [
		'cf7' => [
			'label'    => 'Contact Form 7',
			'detected' => class_exists( 'WPCF7' ),
		],
		'wpforms' => [
			'label'    => 'WPForms',
			'detected' => function_exists( 'wpforms' ),
		],
		'gravity' => [
			'label'    => 'Gravity Forms',
			'detected' => class_exists( 'GFForms' ),
		],
		'ninja' => [
			'label'    => 'Ninja Forms',
			'detected' => class_exists( 'Ninja_Forms' ),
		],
	];
}

function pixel_solution_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$pixel_id   = get_option( 'mcs_pixel_id', '' );
	$capi_token = get_option( 'mcs_capi_token', '' );
	$test_code  = get_option( 'mcs_test_event_code', '' );

	$hook_map_raw = get_option( 'mcs_pixel_hook_map', '' );
	$hook_map     = [];
	if ( $hook_map_raw ) {
		$decoded = json_decode( $hook_map_raw, true );
		if ( is_array( $decoded ) ) $hook_map = $decoded;
	}
	if ( empty( $hook_map ) ) {
		$hook_map = MCS_Events::get_default_hook_map();
	}

	$form_events_raw = get_option( 'mcs_form_events', '' );
	$form_events     = [];
	if ( $form_events_raw ) {
		$decoded = json_decode( $form_events_raw, true );
		if ( is_array( $decoded ) ) $form_events = $decoded;
	}

	$form_plugins          = mcs_detect_form_plugins();
	$available_form_events = [ 'Lead', 'CompleteRegistration', 'Contact', 'Subscribe', 'SubmitApplication' ];

	$status_pixel = $pixel_id   ? '&#9679; Set'     : '&#9675; Missing';
	$color_pixel  = $pixel_id   ? 'green'            : '#c00';
	$status_token = $capi_token ? '&#9679; Set'     : '&#9675; Missing';
	$color_token  = $capi_token ? 'green'            : '#c00';

	$woo_events_raw = get_option( 'mcs_woo_events', '' );
	$woo_events     = [];
	if ( $woo_events_raw ) {
		$decoded = json_decode( $woo_events_raw, true );
		if ( is_array( $decoded ) ) $woo_events = $decoded;
	}
	$woo_detected  = class_exists( 'WooCommerce' );
	$woo_triggers  = [
		'add_to_cart'   => [ 'label' => 'Add to Cart',       'event' => 'AddToCart',        'hook' => 'woocommerce_add_to_cart' ],
		'checkout'      => [ 'label' => 'Initiate Checkout',  'event' => 'InitiateCheckout', 'hook' => 'woocommerce_before_checkout_form' ],
		'purchase'      => [ 'label' => 'Purchase',           'event' => 'Purchase',         'hook' => 'woocommerce_thankyou' ],
		'view_category' => [ 'label' => 'View Category',      'event' => 'ViewCategory',     'hook' => 'woocommerce_before_shop_loop' ],
	];

	$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'first-step';
	$tabs = [
		'first-step'   => 'First Step',
		'form-events'  => 'Form Events',
		'woo-events'   => 'WooCommerce Events',
		'hook-map'     => 'Advanced Hook Map',
		'test-code'    => 'Test Event Code',
		'event-log'    => 'Event Log',
	];
	?>
	<div class="wrap">
		<h1>Pixel Solution <span style="font-size:13px;font-weight:normal;color:#888;">by Tomasz Kalinowski</span></h1>

		<nav class="nav-tab-wrapper" style="margin-bottom:0;">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="#mcs-tab-<?php echo esc_attr( $slug ); ?>"
				   class="nav-tab mcs-tab-link<?php echo $active_tab === $slug ? ' nav-tab-active' : ''; ?>"
				   data-tab="<?php echo esc_attr( $slug ); ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<form method="post" action="options.php" id="mcs-main-form"
		      style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px 24px 8px;">

			<?php settings_fields( 'mcs_pixel_options' ); ?>
			<input type="hidden" name="mcs_woo_events" id="mcs-woo-events-json"
				value="<?php echo esc_attr( wp_json_encode( $woo_events ) ); ?>" />
			<input type="hidden" name="mcs_pixel_hook_map"  id="mcs-hook-map-json"
				value="<?php echo esc_attr( wp_json_encode( $hook_map ) ); ?>" />
			<input type="hidden" name="mcs_form_events" id="mcs-form-events-json"
				value="<?php echo esc_attr( wp_json_encode( $form_events ) ); ?>" />

			<?php /* ═══ TAB 1: First Step ═══ */ ?>
			<div id="mcs-tab-first-step" class="mcs-tab-panel">
				<h2 style="margin-top:16px;">First Step</h2>
				<p style="color:#666;max-width:680px;">Paste your Meta Pixel ID and Conversions API token. Both are required for full server-side + browser-side tracking.</p>

				<table style="max-width:520px;margin-bottom:8px;">
					<tr>
						<td style="width:140px;font-weight:600;padding:4px 0;">Pixel ID</td>
						<td><span style="color:<?php echo $color_pixel; ?>;"><?php echo $status_pixel; ?></span></td>
					</tr>
					<tr>
						<td style="font-weight:600;padding:4px 0;">Access Token</td>
						<td><span style="color:<?php echo $color_token; ?>;"><?php echo $status_token; ?></span></td>
					</tr>
				</table>

				<table class="form-table" style="max-width:740px;">
					<tr>
						<th scope="row"><label for="mcs_pixel_id">Pixel ID</label></th>
						<td>
							<input type="text" id="mcs_pixel_id" name="mcs_pixel_id"
								value="<?php echo esc_attr( $pixel_id ); ?>"
								class="regular-text" placeholder="e.g. 1234567890123456" />
							<p class="description">
								15–16 digit number. Find it in <strong>Meta Business Suite → Events Manager</strong> — it appears next to your pixel name at the top.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mcs_capi_token">Access Token CAPI</label></th>
						<td>
							<input type="password" id="mcs_capi_token" name="mcs_capi_token"
								value="<?php echo esc_attr( $capi_token ); ?>"
								class="regular-text" />
							<p class="description">
								Required for server-side Conversions API.<br>
								<strong>Events Manager → your pixel → Settings → Conversions API → Generate access token.</strong>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save Settings' ); ?>
			</div>

			<?php /* ═══ TAB 2: Form Plugin Events ═══ */ ?>
			<div id="mcs-tab-form-events" class="mcs-tab-panel" style="display:none;">
				<h2 style="margin-top:16px;">Form Events</h2>
				<p style="color:#666;max-width:680px;">
					Select which Meta event fires when a form is submitted. One event per plugin — both browser-side (Pixel) and server-side (CAPI) are sent automatically.
				</p>

				<table class="widefat" style="max-width:820px;margin-bottom:12px;">
					<thead>
						<tr>
							<th style="width:20%;">Plugin</th>
							<th style="width:12%;">Status</th>
							<th>Event fired on submission</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $form_plugins as $plugin_key => $plugin ) :
							$selected_arr = $form_events[ $plugin_key ] ?? ( $plugin['detected'] ? [ 'CompleteRegistration' ] : [] );
							$selected     = $selected_arr[0] ?? '';
						?>
						<tr<?php echo ! $plugin['detected'] ? ' style="opacity:0.4;"' : ''; ?>>
							<td><strong><?php echo esc_html( $plugin['label'] ); ?></strong></td>
							<td>
								<?php if ( $plugin['detected'] ) : ?>
									<span style="color:green;">&#9679; Detected</span>
								<?php else : ?>
									<span style="color:#aaa;">&#9675; Not installed</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $plugin['detected'] ) : ?>
									<label style="display:inline-flex;align-items:center;gap:5px;margin-right:16px;white-space:nowrap;">
										<input type="radio"
											class="mcs-form-event-rb"
											name="mcs_form_event_<?php echo esc_attr( $plugin_key ); ?>"
											data-plugin="<?php echo esc_attr( $plugin_key ); ?>"
											data-event=""
											<?php checked( $selected === '' ); ?> />
										<em style="color:#aaa;">None</em>
									</label>
									<?php foreach ( $available_form_events as $event_name ) : ?>
									<label style="display:inline-flex;align-items:center;gap:5px;margin-right:16px;white-space:nowrap;">
										<input type="radio"
											class="mcs-form-event-rb"
											name="mcs_form_event_<?php echo esc_attr( $plugin_key ); ?>"
											data-plugin="<?php echo esc_attr( $plugin_key ); ?>"
											data-event="<?php echo esc_attr( $event_name ); ?>"
											<?php checked( $selected === $event_name ); ?> />
										<?php echo esc_html( $event_name ); ?>
									</label>
									<?php endforeach; ?>
								<?php else : ?>
									<span style="color:#aaa;font-size:12px;">Install the plugin to configure events.</span>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
					<button type="button" id="mcs-form-events-restore" class="button">&#8635; Restore Default</button>
					<span style="color:#888;font-size:12px;">Default: CompleteRegistration for each detected plugin</span>
				</div>
				<?php submit_button( 'Save Settings' ); ?>
			</div>

			<?php /* ═══ TAB 3: WooCommerce Events ═══ */ ?>
			<div id="mcs-tab-woo-events" class="mcs-tab-panel" style="display:none;">
				<h2 style="margin-top:16px;">WooCommerce Events</h2>
				<?php if ( ! $woo_detected ) : ?>
					<p style="color:#aaa;">WooCommerce is not installed or not active.</p>
				<?php else : ?>
				<p style="color:#666;max-width:680px;">
					Enable which WooCommerce actions should send a server-side CAPI event to Meta. Each trigger fires its standard Facebook event.
				</p>
				<table class="widefat" style="max-width:820px;margin-bottom:20px;">
					<thead>
						<tr>
							<th style="width:10%;text-align:center;">Enabled</th>
							<th style="width:24%;">Trigger</th>
							<th style="width:22%;">Meta Event</th>
							<th>WordPress Hook</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $woo_triggers as $key => $trigger ) :
							$checked = ! empty( $woo_events[ $key ] );
						?>
						<tr>
							<td style="text-align:center;">
								<input type="checkbox"
									class="mcs-woo-cb"
									data-key="<?php echo esc_attr( $key ); ?>"
									<?php checked( $checked ); ?> />
							</td>
							<td><strong><?php echo esc_html( $trigger['label'] ); ?></strong></td>
							<td><code><?php echo esc_html( $trigger['event'] ); ?></code></td>
							<td style="color:#888;font-size:12px;font-family:monospace;"><?php echo esc_html( $trigger['hook'] ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
				<?php if ( $woo_detected ) : ?>
				<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
					<button type="button" id="mcs-woo-restore" class="button">&#8635; Restore Default</button>
					<span style="color:#888;font-size:12px;">Default: all triggers disabled</span>
				</div>
				<?php endif; ?>
				<?php submit_button( 'Save Settings' ); ?>
			</div>

			<?php /* ═══ TAB 4: Advanced Hook Map ═══ */ ?>
			<div id="mcs-tab-hook-map" class="mcs-tab-panel" style="display:none;">
				<h2 style="margin-top:16px;">Advanced Hook &rarr; Event Map</h2>
				<p style="color:#666;max-width:680px;">
					For non-form triggers (e.g. WooCommerce). Maps a WordPress action hook to a Facebook event sent server-side via CAPI.
				</p>

				<datalist id="mcs-events-list">
					<option value="Lead">
					<option value="Purchase">
					<option value="CompleteRegistration">
					<option value="Contact">
					<option value="Subscribe">
					<option value="ViewContent">
					<option value="Search">
					<option value="AddToCart">
					<option value="AddPaymentInfo">
					<option value="InitiateCheckout">
					<option value="SubmitApplication">
					<option value="StartTrial">
					<option value="Schedule">
					<option value="FindLocation">
				</datalist>

				<table class="widefat" id="mcs-hook-table" style="max-width:820px;margin-bottom:10px;">
					<thead>
						<tr>
							<th style="width:28%;">Label</th>
							<th style="width:34%;">WordPress Hook</th>
							<th style="width:20%;">FB Event</th>
							<th style="width:8%;text-align:center;">Enabled</th>
							<th style="width:10%;"></th>
						</tr>
					</thead>
					<tbody>
						<tr style="background:#f9f9f9;color:#999;">
							<td><em>PageView</em></td>
							<td><code>wp_head</code></td>
							<td>PageView</td>
							<td style="text-align:center;" title="Always active">&#128274;</td>
							<td><span style="color:#bbb;font-size:11px;">built-in</span></td>
						</tr>
						<tr style="background:#f9f9f9;color:#999;">
							<td><em>ViewContent</em></td>
							<td><code>wp_footer</code> (subpages)</td>
							<td>ViewContent</td>
							<td style="text-align:center;" title="Always active">&#128274;</td>
							<td><span style="color:#bbb;font-size:11px;">built-in</span></td>
						</tr>
					</tbody>
					<tbody id="mcs-hook-tbody"></tbody>
				</table>

				<button type="button" id="mcs-add-hook" class="button" style="margin-bottom:20px;">+ Add Row</button>
				<button type="button" id="mcs-discover-btn" class="button" style="margin-bottom:20px;margin-left:8px;">&#128269; Discover Hooks</button>
				<button type="button" id="mcs-hookmap-restore" class="button" style="margin-bottom:20px;margin-left:8px;">&#8635; Restore Default</button>

				<div id="mcs-discover-panel" style="display:none;max-width:820px;border:1px solid #c3c4c7;background:#f9f9f9;padding:16px;margin-bottom:20px;">
					<strong>Available hooks from active plugins</strong>
					<span id="mcs-discover-status" style="color:#666;font-size:12px;margin-left:8px;"></span>
					<br><br>
					<input type="text" id="mcs-discover-search" placeholder="Filter hooks..." style="width:100%;margin-bottom:10px;" />
					<div id="mcs-discover-list" style="max-height:250px;overflow-y:auto;font-family:monospace;font-size:12px;"></div>
				</div>

				<?php wp_nonce_field( 'mcs_discover_hooks', 'mcs_discover_nonce', false ); ?>
				<?php submit_button( 'Save Settings' ); ?>
			</div>

			<?php /* ═══ TAB 6: Event Log ═══ */ ?>
			<div id="mcs-tab-event-log" class="mcs-tab-panel" style="display:none;">
				<h2 style="margin-top:16px;">
					Event Log
					<span id="mcs-log-count" style="font-size:13px;font-weight:normal;color:#888;margin-left:8px;"></span>
				</h2>
				<p style="color:#666;max-width:680px;">Server-side (CAPI) events sent in the last 24 hours. Auto-refreshes every 30 s while this tab is open.</p>

				<div style="margin-bottom:14px;display:flex;align-items:center;gap:10px;">
					<button type="button" id="mcs-log-refresh" class="button">&#8635; Refresh</button>
					<button type="button" id="mcs-log-clear" class="button" style="color:#c00;">&#128465; Clear Log</button>
					<span id="mcs-log-status" style="font-size:12px;color:#999;"></span>
				</div>

				<table class="widefat" style="max-width:1080px;">
					<thead>
						<tr>
							<th style="width:13%;white-space:nowrap;">Time</th>
							<th style="width:18%;">Event</th>
							<th style="width:6%;text-align:center;">Status</th>
							<th style="width:5%;text-align:center;" title="Email hashed">EM</th>
							<th style="width:5%;text-align:center;" title="Phone hashed">PH</th>
							<th style="width:5%;text-align:center;" title="First name hashed">FN</th>
							<th style="width:5%;text-align:center;" title="Last name hashed">LN</th>
							<th style="width:5%;text-align:center;" title="External ID hashed">XID</th>
							<th>URL</th>
						</tr>
					</thead>
					<tbody id="mcs-log-tbody">
						<tr><td colspan="9" style="text-align:center;color:#999;padding:24px;">Loading…</td></tr>
					</tbody>
				</table>
			</div>

			<?php /* ═══ TAB 5: Test Event Code ═══ */ ?>
			<div id="mcs-tab-test-code" class="mcs-tab-panel" style="display:none;">
				<h2 style="margin-top:16px;">Test Event Code</h2>
				<p style="color:#666;max-width:680px;">
					Use this only while verifying your setup. <strong>Leave empty in production</strong> — events tagged with a test code are excluded from ad optimisation.
				</p>

				<table class="form-table" style="max-width:740px;">
					<tr>
						<th scope="row"><label for="mcs_test_event_code">Test Event Code</label></th>
						<td>
							<input type="text" id="mcs_test_event_code" name="mcs_test_event_code"
								value="<?php echo esc_attr( $test_code ); ?>"
								class="regular-text" placeholder="TEST12345 (optional)" />
							<p class="description">
								<strong>How to test:</strong>
								<ol style="margin:.5em 0 .5em 1.2em;padding:0;">
									<li>Go to <strong>Meta Business Suite → Events Manager</strong>, select your pixel → <strong>Test Events</strong> tab.</li>
									<li>Copy the code (e.g. <code>TEST12345</code>) and paste it above. Save settings.</li>
									<li>Visit your website — events appear in real time in the Test Events panel.</li>
									<li>A correct setup shows each event <strong>twice</strong>: browser (Pixel) + server (CAPI), deduplicated to one.</li>
									<li>When done, <strong>clear this field and save</strong>.</li>
								</ol>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save Settings' ); ?>
			</div>

		</form>

		<p style="color:#aaa;font-size:11px;margin-top:12px;">
			Pixel Solution <?php echo PIXEL_SOLUTION_VERSION; ?> &nbsp;|&nbsp; @tomas3man &nbsp;|&nbsp; github.com/t7jk/pixel-solution
		</p>
	</div>

	<script>
	(function() {
		// ── Tabs ──
		var STORAGE_KEY = 'mcs_active_tab';

		function activateTab(slug) {
			document.querySelectorAll('.mcs-tab-panel').forEach(function(p) {
				p.style.display = 'none';
			});
			document.querySelectorAll('.mcs-tab-link').forEach(function(a) {
				a.classList.remove('nav-tab-active');
			});
			var panel = document.getElementById('mcs-tab-' + slug);
			var link  = document.querySelector('.mcs-tab-link[data-tab="' + slug + '"]');
			if (panel) panel.style.display = '';
			if (link)  link.classList.add('nav-tab-active');
			try { sessionStorage.setItem(STORAGE_KEY, slug); } catch(e) {}
		}

		document.addEventListener('DOMContentLoaded', function() {
			// Restore last active tab from session (survives Save redirect)
			var saved = '';
			try { saved = sessionStorage.getItem(STORAGE_KEY) || ''; } catch(e) {}
			var initial = saved || 'first-step';
			activateTab(initial);

			document.querySelectorAll('.mcs-tab-link').forEach(function(a) {
				a.addEventListener('click', function(e) {
					e.preventDefault();
					activateTab(this.dataset.tab);
				});
			});

			// ── Hook Map ──
			function esc(s) {
				return String(s)
					.replace(/&/g, '&amp;').replace(/"/g, '&quot;')
					.replace(/</g, '&lt;').replace(/>/g, '&gt;');
			}

			function makeRow(entry) {
				var tr = document.createElement('tr');
				tr.innerHTML =
					'<td><input type="text" class="mcs-label" value="' + esc(entry.label || '') + '" style="width:100%;" /></td>' +
					'<td><input type="text" class="mcs-hook"  value="' + esc(entry.hook  || '') + '" style="width:100%;" placeholder="e.g. woocommerce_thankyou" /></td>' +
					'<td><input type="text" class="mcs-event" list="mcs-events-list" value="' + esc(entry.event || 'Lead') + '" style="width:100%;" /></td>' +
					'<td style="text-align:center;"><input type="checkbox" class="mcs-enabled"' + (entry.enabled ? ' checked' : '') + ' /></td>' +
					'<td><button type="button" class="button mcs-del">&times;</button></td>';
				tr.querySelector('.mcs-del').addEventListener('click', function() { tr.remove(); });
				return tr;
			}

			var tbody = document.getElementById('mcs-hook-tbody');
			(function() {
				var raw = document.getElementById('mcs-hook-map-json').value;
				try { JSON.parse(raw); } catch(e) { raw = '[]'; }
				JSON.parse(raw).forEach(function(e) { tbody.appendChild(makeRow(e)); });
			})();

			document.getElementById('mcs-add-hook').addEventListener('click', function() {
				tbody.appendChild(makeRow({ label: '', hook: '', event: 'Lead', enabled: true }));
			});

			// ── Serialize on submit ──
			document.getElementById('mcs-main-form').addEventListener('submit', function() {
				// Hook map
				var map = [];
				document.querySelectorAll('#mcs-hook-tbody tr').forEach(function(tr) {
					var hook  = tr.querySelector('.mcs-hook').value.trim();
					var event = tr.querySelector('.mcs-event').value.trim();
					if (!hook || !event) return;
					map.push({
						label:   tr.querySelector('.mcs-label').value.trim(),
						hook:    hook,
						event:   event,
						enabled: tr.querySelector('.mcs-enabled').checked
					});
				});
				document.getElementById('mcs-hook-map-json').value = JSON.stringify(map);

				// Form events — one radio per plugin
				var config = {};
				document.querySelectorAll('.mcs-form-event-rb:checked').forEach(function(rb) {
					var plugin = rb.dataset.plugin;
					var event  = rb.dataset.event;
					if (event) config[plugin] = [event];
				});
				document.getElementById('mcs-form-events-json').value = JSON.stringify(config);

				// WooCommerce events
				var wooConfig = {};
				document.querySelectorAll('.mcs-woo-cb').forEach(function(cb) {
					wooConfig[cb.dataset.key] = cb.checked;
				});
				document.getElementById('mcs-woo-events-json').value = JSON.stringify(wooConfig);
			});

			// ── Discover Hooks ──
			var discoverBtn    = document.getElementById('mcs-discover-btn');
			var discoverPanel  = document.getElementById('mcs-discover-panel');
			var discoverList   = document.getElementById('mcs-discover-list');
			var discoverSearch = document.getElementById('mcs-discover-search');
			var discoverStatus = document.getElementById('mcs-discover-status');
			var allHooks       = [];

			discoverBtn.addEventListener('click', function() {
				if (discoverPanel.style.display !== 'none') {
					discoverPanel.style.display = 'none';
					return;
				}
				discoverPanel.style.display = 'block';
				if (allHooks.length > 0) return;
				discoverStatus.textContent = 'Scanning…';
				discoverList.textContent   = '';
				var nonce = document.getElementById('mcs_discover_nonce').value;
				fetch(ajaxurl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: new URLSearchParams({ action: 'mcs_discover_hooks', _ajax_nonce: nonce })
				})
				.then(function(r) { return r.json(); })
				.then(function(data) {
					if (!data.success) { discoverStatus.textContent = 'Error.'; return; }
					allHooks = data.data.hooks;
					discoverStatus.textContent = allHooks.length + ' hooks in ' + data.data.files_scanned + ' files.';
					renderHooks(allHooks);
				})
				.catch(function() { discoverStatus.textContent = 'Request failed.'; });
			});

			discoverSearch.addEventListener('input', function() {
				var q = this.value.toLowerCase();
				renderHooks(q ? allHooks.filter(function(h) { return h.toLowerCase().indexOf(q) !== -1; }) : allHooks);
			});

			function renderHooks(list) {
				discoverList.innerHTML = '';
				list.forEach(function(hook) {
					var row = document.createElement('div');
					row.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:3px 0;border-bottom:1px solid #eee;';
					row.innerHTML = '<span>' + esc(hook) + '</span><button type="button" class="button button-small" style="flex-shrink:0;margin-left:8px;">+ Add</button>';
					row.querySelector('button').addEventListener('click', function() {
						tbody.appendChild(makeRow({ label: '', hook: hook, event: 'Lead', enabled: true }));
						tbody.lastElementChild.querySelector('.mcs-label').focus();
					});
					discoverList.appendChild(row);
				});
				if (list.length === 0) discoverList.textContent = 'No hooks match.';
			}

			// ── Event Log ──
		var logNonce  = <?php echo wp_json_encode( wp_create_nonce( 'mcs_log_actions' ) ); ?>;
		var logTimer  = null;
		var logActive = false;

		var EVENT_COLORS = {
			PageView: '#888', ViewContent: '#6c757d',
			Lead: '#0073aa', CompleteRegistration: '#00a32a',
			Purchase: '#d63638', AddToCart: '#dba617',
			InitiateCheckout: '#8a2be2', ViewCategory: '#555',
		};

		function loadLog() {
			fetch(ajaxurl, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: new URLSearchParams({action: 'mcs_fetch_event_log', _ajax_nonce: logNonce})
			})
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (!data.success) return;
				renderLog(data.data);
				document.getElementById('mcs-log-status').textContent =
					'Updated ' + new Date().toLocaleTimeString('pl-PL');
			})
			.catch(function() {
				document.getElementById('mcs-log-status').textContent = 'Request failed.';
			});
		}

		function renderLog(entries) {
			var tbody = document.getElementById('mcs-log-tbody');
			var count = document.getElementById('mcs-log-count');
			count.textContent = '(' + entries.length + ' events)';

			if (!entries.length) {
				tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#999;padding:24px;">No events in the last 24 hours.</td></tr>';
				return;
			}

			function flag(on) {
				return '<td style="text-align:center;color:' + (on ? '#00a32a' : '#ccc') + ';">' + (on ? '&#10003;' : '&#8212;') + '</td>';
			}

			tbody.innerHTML = '';
			entries.forEach(function(e) {
				var d    = new Date(e.ts * 1000);
				var time = d.toLocaleDateString('pl-PL', {day:'2-digit', month:'2-digit'}) + ' '
				         + d.toLocaleTimeString('pl-PL', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
				var color  = EVENT_COLORS[e.event] || '#555';
				var url    = e.url || '';
				try { url = new URL(url).pathname; } catch(x) {}
				var ok     = e.status === 200;
				var tr     = document.createElement('tr');
				tr.innerHTML =
					'<td style="font-family:monospace;font-size:12px;white-space:nowrap;">' + time + '</td>' +
					'<td><span style="display:inline-block;padding:2px 9px;border-radius:3px;background:' + color + ';color:#fff;font-size:12px;font-weight:600;">' + esc(e.event) + '</span></td>' +
					'<td style="text-align:center;"><span style="font-size:11px;padding:1px 5px;border-radius:2px;background:' + (ok ? '#d7f5d7' : '#ffe0e0') + ';color:' + (ok ? '#1a7a1a' : '#c00') + ';">' + (e.status || '?') + '</span></td>' +
					flag(e.has_em) + flag(e.has_ph) + flag(e.has_fn) + flag(e.has_ln) + flag(e.has_xid) +
					'<td style="font-family:monospace;font-size:11px;color:#555;word-break:break-all;">' + esc(url) + '</td>';
				tbody.appendChild(tr);
			});
		}

		function startLogTimer() {
			if (logTimer) return;
			loadLog();
			logTimer = setInterval(loadLog, 30000);
		}

		function stopLogTimer() {
			if (logTimer) { clearInterval(logTimer); logTimer = null; }
		}

		// Start/stop timer based on active tab
		var _origActivate = activateTab;
		activateTab = function(slug) {
			_origActivate(slug);
			if (slug === 'event-log') {
				startLogTimer();
			} else {
				stopLogTimer();
			}
		};

		// If page loaded directly on Event Log tab, kick off the timer now
		// (initial activation happened before the override above was set up).
		if (initial === 'event-log') {
			startLogTimer();
		}

		document.getElementById('mcs-log-refresh').addEventListener('click', loadLog);

		// ── Restore Default buttons ──
		document.getElementById('mcs-form-events-restore').addEventListener('click', function() {
			// Set each plugin group: Lead if plugin row is visible (not faded), else None
			var groups = {};
			document.querySelectorAll('.mcs-form-event-rb').forEach(function(rb) {
				groups[rb.dataset.plugin] = groups[rb.dataset.plugin] || [];
				groups[rb.dataset.plugin].push(rb);
			});
			Object.keys(groups).forEach(function(plugin) {
				var rbs = groups[plugin];
				var row = rbs[0].closest('tr');
				var detected = row && row.style.opacity !== '0.4';
				rbs.forEach(function(rb) {
					rb.checked = detected ? rb.dataset.event === 'CompleteRegistration' : rb.dataset.event === '';
				});
			});
		});

		var wooRestoreBtn = document.getElementById('mcs-woo-restore');
		if (wooRestoreBtn) {
			wooRestoreBtn.addEventListener('click', function() {
				document.querySelectorAll('.mcs-woo-cb').forEach(function(cb) { cb.checked = false; });
			});
		}

		document.getElementById('mcs-hookmap-restore').addEventListener('click', function() {
			if (!confirm('Clear all custom hook entries?')) return;
			document.getElementById('mcs-hook-tbody').innerHTML = '';
		});

		document.getElementById('mcs-log-clear').addEventListener('click', function() {
			if (!confirm('Clear all event log entries?')) return;
			fetch(ajaxurl, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: new URLSearchParams({action: 'mcs_clear_event_log', _ajax_nonce: logNonce})
			})
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.success) renderLog([]);
			});
		});
	});
	})();
	</script>
	<?php
}
