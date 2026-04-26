<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCS_Events {

	private $pixel;
	private $capi;

	private $woo_purchase_event_id = '';
	private $woo_purchase_data     = [];

	// Hook PHP każdego obsługiwanego pluginu formularzy.
	private const FORM_PLUGIN_HOOKS = [
		'cf7'     => 'wpcf7_before_send_mail',
		'wpforms' => 'wpforms_process_complete',
		'gravity' => 'gform_after_submission',
		'ninja'   => 'ninja_forms_after_submission',
	];

	// Stałe zdarzenie Meta per trigger WooCommerce.
	private const WOO_TRIGGER_MAP = [
		'add_to_cart'   => [ 'hook' => 'woocommerce_add_to_cart',          'event' => 'AddToCart'        ],
		'checkout'      => [ 'hook' => 'woocommerce_before_checkout_form',  'event' => 'InitiateCheckout' ],
		'purchase'      => [ 'hook' => 'woocommerce_thankyou',              'event' => 'Purchase'         ],
		'view_category' => [ 'hook' => 'woocommerce_before_shop_loop',      'event' => 'ViewCategory'     ],
	];

	public function __construct( MCS_Pixel $pixel, MCS_CAPI $capi ) {
		$this->pixel = $pixel;
		$this->capi  = $capi;
	}

	public function register_hooks() {
		add_action( 'wp_head', [ $this, 'handle_pageview' ] );
		add_action( 'wp_footer', [ $this, 'handle_viewcontent' ] );
		add_action( 'wp_footer', [ $this, 'handle_search' ] );
		add_action( 'wp_footer', [ $this, 'handle_woo_purchase_pixel' ] );

		// AJAX endpoint dla CAPI — działa nawet gdy strona pochodzi z cache.
		add_action( 'wp_ajax_nopriv_mcs_capi_event', [ $this, 'handle_ajax_capi' ] );
		add_action( 'wp_ajax_mcs_capi_event',        [ $this, 'handle_ajax_capi' ] );

		// Browser-side events po wysyłce formularzy CF7 / WPForms.
		add_action( 'wp_footer', [ $this, 'inject_form_event_listener' ] );

		add_action( 'wp_ajax_mcs_discover_hooks',  [ $this, 'handle_discover_hooks' ] );
		add_action( 'wp_ajax_mcs_fetch_event_log', [ $this, 'handle_fetch_log' ] );
		add_action( 'wp_ajax_mcs_clear_event_log', [ $this, 'handle_clear_log' ] );

		$this->register_form_plugin_hooks();
		$this->register_woo_hooks();
		$this->register_dynamic_hooks();

		add_shortcode( 'mcs_lead_event', [ $this, 'handle_lead_shortcode' ] );
	}

	/**
	 * Inicjuje Pixel w <head> i od razu odpala PageView:
	 * - event_id generowany w JS (unikalny per pageload, działa na cached pages),
	 * - CAPI wywołane przez fetch do admin-ajax.php (PHP uruchamia się poza cache).
	 */
	public function handle_pageview() {
		$this->pixel->inject_base_code();

		if ( ! get_option( 'mcs_pixel_id', '' ) ) {
			return;
		}
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<script>
		(function() {
			var id = typeof crypto !== 'undefined' && crypto.randomUUID
				? 'mcs_pv_' + crypto.randomUUID()
				: 'mcs_pv_' + Math.random().toString(36).slice(2, 11) + '_' + Date.now();
			fbq('track', 'PageView', {}, {eventID: id});
			fetch('<?php echo esc_url( $ajax_url ); ?>', {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: new URLSearchParams({action: 'mcs_capi_event', event_name: 'PageView', event_id: id}),
				keepalive: true
			});
		})();
		</script>
		<?php
	}

	public function handle_viewcontent() {
		if ( is_front_page() || ! ( is_single() || is_page() ) ) {
			return;
		}
		if ( ! get_option( 'mcs_pixel_id', '' ) ) {
			return;
		}

		$content_name = get_the_title();
		$ajax_url     = admin_url( 'admin-ajax.php' );

		$woo_data = null;
		if ( function_exists( 'is_product' ) && is_product() ) {
			global $product;
			if ( ! ( $product instanceof WC_Product ) ) {
				$product = wc_get_product( get_the_ID() );
			}
			if ( $product ) {
				$woo_data = [
					'content_ids' => [ (string) $product->get_id() ],
					'value'       => (float) $product->get_price(),
					'currency'    => get_woocommerce_currency(),
				];
			}
		}
		?>
		<script>
		(function() {
			if (typeof fbq === 'undefined') return;
			var id = typeof crypto !== 'undefined' && crypto.randomUUID
				? 'mcs_vc_' + crypto.randomUUID()
				: 'mcs_vc_' + Math.random().toString(36).slice(2, 11) + '_' + Date.now();
			var params = {
				content_name: <?php echo wp_json_encode( $content_name ); ?>,
				content_type: 'product'
			};
			<?php if ( $woo_data ) : ?>
			params.content_ids = <?php echo wp_json_encode( $woo_data['content_ids'] ); ?>;
			params.value       = <?php echo (float) $woo_data['value']; ?>;
			params.currency    = <?php echo wp_json_encode( $woo_data['currency'] ); ?>;
			<?php endif; ?>
			fbq('track', 'ViewContent', params, {eventID: id});
			var body = new URLSearchParams({
				action:                       'mcs_capi_event',
				event_name:                   'ViewContent',
				event_id:                     id,
				'custom_data[content_name]':  params.content_name,
				'custom_data[content_type]':  params.content_type
			});
			<?php if ( $woo_data ) : ?>
			body.set('custom_data[value]',    String(params.value));
			body.set('custom_data[currency]', params.currency);
			params.content_ids.forEach(function(cid) {
				body.append('custom_data[content_ids][]', cid);
			});
			<?php endif; ?>
			fetch('<?php echo esc_url( $ajax_url ); ?>', {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: body,
				keepalive: true
			});
		})();
		</script>
		<?php
	}

	public function handle_search() {
		if ( ! is_search() || ! get_option( 'mcs_pixel_id', '' ) ) {
			return;
		}
		$query    = get_search_query();
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<script>
		(function() {
			if (typeof fbq === 'undefined') return;
			var id = typeof crypto !== 'undefined' && crypto.randomUUID
				? 'mcs_s_' + crypto.randomUUID()
				: 'mcs_s_' + Math.random().toString(36).slice(2, 11) + '_' + Date.now();
			var params = { search_string: <?php echo wp_json_encode( $query ); ?> };
			fbq('track', 'Search', params, {eventID: id});
			fetch('<?php echo esc_url( $ajax_url ); ?>', {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: new URLSearchParams({
					action:                           'mcs_capi_event',
					event_name:                       'Search',
					event_id:                         id,
					'custom_data[search_string]':     params.search_string
				}),
				keepalive: true
			});
		})();
		</script>
		<?php
	}

	public function handle_woo_purchase_pixel() {
		if ( ! $this->woo_purchase_event_id || ! get_option( 'mcs_pixel_id', '' ) ) {
			return;
		}
		?>
		<script>
		(function() {
			if (typeof fbq === 'undefined') return;
			fbq('track', 'Purchase',
				<?php echo wp_json_encode( $this->woo_purchase_data ); ?>,
				{ eventID: <?php echo wp_json_encode( $this->woo_purchase_event_id ); ?> }
			);
		})();
		</script>
		<?php
	}

	/**
	 * Odbiera wywołania CAPI z JS fetch — admin-ajax.php nie jest cachowany,
	 * więc PHP wykonuje się zawsze, nawet gdy strona główna pochodzi z cache.
	 */
	public function handle_ajax_capi() {
		$allowed    = [ 'PageView', 'ViewContent', 'Search' ];
		$event_name = isset( $_POST['event_name'] ) ? sanitize_text_field( $_POST['event_name'] ) : '';
		$event_id   = isset( $_POST['event_id'] )   ? sanitize_text_field( $_POST['event_id'] )   : '';

		if ( ! in_array( $event_name, $allowed, true ) ) {
			wp_die( '', '', [ 'response' => 400 ] );
		}

		$event_data = [];
		if ( ! empty( $_POST['custom_data'] ) && is_array( $_POST['custom_data'] ) ) {
			foreach ( $_POST['custom_data'] as $k => $v ) {
				$key = sanitize_key( $k );
				$event_data['custom_data'][ $key ] = is_array( $v )
					? array_map( 'sanitize_text_field', $v )
					: sanitize_text_field( $v );
			}
		}

		$this->capi->send_event( $event_name, $event_data, $event_id );
		wp_die();
	}

	public static function get_default_hook_map() {
		return [];
	}

	/**
	 * Rejestruje server-side hooki CAPI dla pluginów formularzy na podstawie
	 * konfiguracji zapisanej w opcji mcs_form_events.
	 */
	private function register_form_plugin_hooks() {
		$raw = get_option( 'mcs_form_events', '' );
		if ( ! $raw ) {
			return;
		}
		$config = json_decode( $raw, true );
		if ( ! is_array( $config ) ) {
			return;
		}

		foreach ( $config as $plugin_key => $events ) {
			$hook = self::FORM_PLUGIN_HOOKS[ $plugin_key ] ?? '';
			if ( ! $hook || empty( $events ) ) {
				continue;
			}
			foreach ( $events as $event_name ) {
				$capi = $this->capi;
				add_action( $hook, function() use ( $event_name, $capi ) {
					$event_id = ! empty( $_POST['mcs_lead_event_id'] )
						? sanitize_text_field( $_POST['mcs_lead_event_id'] )
						: uniqid( 'mcs_ev_', true );

					$extra_ud   = self::extract_user_data_from_post( $_POST );
					$event_data = $extra_ud ? [ 'user_data' => $extra_ud ] : [];
					$capi->send_event( $event_name, $event_data, $event_id );
				}, 10, 10 );
			}
		}
	}

	private function register_woo_hooks() {
		$raw = get_option( 'mcs_woo_events', '' );
		if ( ! $raw ) {
			return;
		}
		$config = json_decode( $raw, true );
		if ( ! is_array( $config ) ) {
			return;
		}

		foreach ( $config as $trigger_key => $enabled ) {
			if ( ! $enabled || empty( self::WOO_TRIGGER_MAP[ $trigger_key ] ) ) {
				continue;
			}
			$hook       = self::WOO_TRIGGER_MAP[ $trigger_key ]['hook'];
			$event_name = self::WOO_TRIGGER_MAP[ $trigger_key ]['event'];
			$capi       = $this->capi;

			if ( 'purchase' === $trigger_key ) {
				add_action( $hook, function( $order_id ) use ( $event_name, $capi ) {
					$order = wc_get_order( $order_id );
					if ( ! $order ) return;

					$content_ids = [];
					foreach ( $order->get_items() as $item ) {
						$content_ids[] = (string) $item->get_product_id();
					}

					$event_id   = uniqid( 'mcs_woo_', true );
					$event_data = [
						'custom_data' => [
							'value'        => (float) $order->get_total(),
							'currency'     => $order->get_currency(),
							'content_ids'  => $content_ids,
							'content_type' => 'product',
						],
					];

					$this->woo_purchase_event_id = $event_id;
					$this->woo_purchase_data     = $event_data['custom_data'];

					$capi->send_event( $event_name, $event_data, $event_id );
				}, 10, 1 );
			} else {
				add_action( $hook, function() use ( $event_name, $capi ) {
					$capi->send_event( $event_name, [], uniqid( 'mcs_woo_', true ) );
				} );
			}
		}
	}

	private function register_dynamic_hooks() {
		$raw = get_option( 'mcs_pixel_hook_map', '' );
		$map = [];
		if ( $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$map = $decoded;
			}
		}
		if ( empty( $map ) ) {
			$map = self::get_default_hook_map();
		}

		$capi = $this->capi;
		foreach ( $map as $entry ) {
			if ( empty( $entry['enabled'] ) || empty( $entry['hook'] ) || empty( $entry['event'] ) ) {
				continue;
			}
			$event_name = sanitize_text_field( $entry['event'] );
			add_action( $entry['hook'], function() use ( $event_name, $capi ) {
				$event_id = ! empty( $_POST['mcs_lead_event_id'] )
					? sanitize_text_field( $_POST['mcs_lead_event_id'] )
					: uniqid( 'mcs_ev_', true );

				$extra_ud   = self::extract_user_data_from_post( $_POST );
				$event_data = $extra_ud ? [ 'user_data' => $extra_ud ] : [];
				$capi->send_event( $event_name, $event_data, $event_id );
			}, 10, 10 );
		}
	}

	/**
	 * Wstrzykuje JS który:
	 * 1. Przed submitem CF7 generuje event_id i wstawia go jako ukryte pole.
	 * 2. Po potwierdzeniu wysyłki odpala zdarzenia Pixel skonfigurowane
	 *    dla danego pluginu formularza (Lead, CompleteRegistration itd.).
	 */
	public function inject_form_event_listener() {
		if ( ! get_option( 'mcs_pixel_id', '' ) ) {
			return;
		}

		$raw    = get_option( 'mcs_form_events', '' );
		$config = [];
		if ( $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$config = $decoded;
			}
		}

		$cf7_events     = wp_json_encode( $config['cf7']     ?? [] );
		$wpforms_events = wp_json_encode( $config['wpforms'] ?? [] );
		?>
		<script>
		(function() {
			var mcsCf7Events     = <?php echo $cf7_events; ?>;
			var mcsWpformsEvents = <?php echo $wpforms_events; ?>;

			function mcsGenEventId() {
				if (typeof crypto !== 'undefined' && crypto.randomUUID) {
					return 'mcs_form_' + crypto.randomUUID();
				}
				return 'mcs_form_' + Math.random().toString(36).slice(2, 11) + '_' + Date.now();
			}

			function mcsInjectEventId(form) {
				var existing = form.querySelector('input[name="mcs_lead_event_id"]');
				if (existing) {
					existing.value = mcsGenEventId();
					return;
				}
				var input = document.createElement('input');
				input.type  = 'hidden';
				input.name  = 'mcs_lead_event_id';
				input.value = mcsGenEventId();
				form.appendChild(input);
			}

			async function mcsHash(str) {
				var buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(str));
				return Array.from(new Uint8Array(buf)).map(function(b) {
					return b.toString(16).padStart(2, '0');
				}).join('');
			}

			// Telephone field detection — uses word boundary to avoid 'intel_*' false positives.
			var TEL_RE = /(^|[\s_\-])tel(efon)?([\s_\-]|$)/;

			function mcsCheckField(ud, name, value, type, id, placeholder) {
				var v = (value == null ? '' : ('' + value)).trim();
				if (!v) return;
				var n = (name || '').toLowerCase();
				var i = (id || '').toLowerCase();
				var p = (placeholder || '').toLowerCase();
				var t = (type || '').toLowerCase();

				if (!ud.em && (t === 'email' || n.indexOf('email') !== -1 || i.indexOf('email') !== -1) && v.indexOf('@') !== -1) {
					ud.em = v.toLowerCase();
				}
				if (!ud.ph && (t === 'tel' || n.indexOf('phone') !== -1 || i.indexOf('phone') !== -1 || TEL_RE.test(n) || TEL_RE.test(i))) {
					var num = v.replace(/\D/g, '');
					if (num.length >= 9) ud.ph = num.indexOf('48') === 0 ? num : '48' + num;
				}
				if (!ud.fn && (n.indexOf('first') !== -1 || n.indexOf('fname') !== -1 || n.indexOf('imie') !== -1 || i.indexOf('-first') !== -1 || p === 'first')) {
					ud.fn = v.toLowerCase();
				}
				if (!ud.ln && (n.indexOf('last') !== -1 || n.indexOf('lname') !== -1 || n.indexOf('surname') !== -1 || n.indexOf('nazwisko') !== -1 || i.indexOf('-last') !== -1 || p === 'last')) {
					ud.ln = v.toLowerCase();
				}
				if (!ud.fn && !ud.ln && (n === 'your-name' || n === 'name' || n === 'full-name' || n === 'fullname' || n === 'imie-i-nazwisko')) {
					ud._generic = v;
				}
			}

			function mcsExtractUserData(form, inputArray) {
				var ud = { em: '', ph: '', fn: '', ln: '', _generic: '' };

				if (form && form.querySelectorAll) {
					Array.prototype.forEach.call(form.querySelectorAll('input, textarea'), function(inp) {
						mcsCheckField(ud, inp.name, inp.value, inp.type, inp.id, inp.placeholder);
					});
				}
				if (inputArray && inputArray.length) {
					inputArray.forEach(function(inp) {
						mcsCheckField(ud, inp.name, inp.value);
					});
				}

				if (!ud.fn && !ud.ln && ud._generic) {
					var parts = ud._generic.split(/\s+/);
					ud.fn = parts[0].toLowerCase();
					if (parts.length > 1) ud.ln = parts.slice(1).join(' ').toLowerCase();
				}
				delete ud._generic;
				return ud;
			}

			async function mcsBuildOpts(eventId, ud) {
				var opts = eventId ? { eventID: eventId } : {};
				if (ud.em) opts.em = await mcsHash(ud.em);
				if (ud.ph) opts.ph = await mcsHash(ud.ph);
				if (ud.fn) opts.fn = await mcsHash(ud.fn);
				if (ud.ln) opts.ln = await mcsHash(ud.ln);
				return opts;
			}

			document.addEventListener('DOMContentLoaded', function() {
				document.querySelectorAll('.wpcf7-form, .wpforms-form').forEach(mcsInjectEventId);
			});

			// WPForms — browser-side Pixel z deduplikacją i danymi użytkownika
			document.addEventListener('wpformsAjaxSubmitSuccess', async function(e) {
				if (typeof fbq === 'undefined' || !mcsWpformsEvents.length) return;
				var form = e.target;
				if (!form || !form.querySelector) return;

				var idInput = form.querySelector('input[name="mcs_lead_event_id"]');
				var eventId = idInput ? idInput.value : '';

				var ud   = mcsExtractUserData(form, null);
				var opts = await mcsBuildOpts(eventId, ud);

				if (idInput) idInput.value = mcsGenEventId();

				mcsWpformsEvents.forEach(function(eventName) {
					fbq('track', eventName, {}, opts);
				});
			}, false);

			// CF7 — browser-side Pixel z deduplikacją i danymi użytkownika
			document.addEventListener('wpcf7mailsent', async function(e) {
				if (typeof fbq === 'undefined' || !mcsCf7Events.length) return;

				var idInput = e.target.querySelector('input[name="mcs_lead_event_id"]');
				var eventId = idInput ? idInput.value : '';

				var inputs = (e.detail && e.detail.inputs) ? e.detail.inputs : [];
				var ud     = mcsExtractUserData(e.target, inputs);
				var opts   = await mcsBuildOpts(eventId, ud);

				if (idInput) idInput.value = mcsGenEventId();

				mcsCf7Events.forEach(function(eventName) {
					fbq('track', eventName, {}, opts);
				});
			}, false);
		})();
		</script>
		<?php
	}

	public function handle_discover_hooks() {
		check_ajax_referer( 'mcs_discover_hooks' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$active_plugins = get_option( 'active_plugins', [] );
		$hooks          = [];
		$file_limit     = 300;
		$files_scanned  = 0;

		foreach ( $active_plugins as $plugin_file ) {
			$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
			if ( ! is_dir( $plugin_dir ) ) {
				continue;
			}
			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY
				);
				foreach ( $iterator as $file ) {
					if ( $files_scanned >= $file_limit ) break 2;
					if ( $file->getExtension() !== 'php' ) continue;
					$content = file_get_contents( $file->getPathname() );
					if ( preg_match_all( '/do_action\s*\(\s*[\'"]([a-zA-Z0-9_\-\/]+)[\'"]/', $content, $matches ) ) {
						$hooks = array_merge( $hooks, $matches[1] );
					}
					$files_scanned++;
				}
			} catch ( Exception $e ) {
				continue;
			}
		}

		$hooks = array_values( array_unique( $hooks ) );
		sort( $hooks );
		wp_send_json_success( [ 'hooks' => $hooks, 'files_scanned' => $files_scanned ] );
	}

	public function handle_fetch_log() {
		check_ajax_referer( 'mcs_log_actions' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		wp_send_json_success( array_reverse( MCS_Log::read() ) );
	}

	public function handle_clear_log() {
		check_ajax_referer( 'mcs_log_actions' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		MCS_Log::clear();
		wp_send_json_success();
	}

	private static function extract_user_data_from_post( array $post ): array {
		$ud           = [];
		$generic_name = null;

		foreach ( $post as $key => $value ) {
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}
			$k = strtolower( $key );
			$v = trim( $value );

			if ( ! isset( $ud['em'] ) && str_contains( $k, 'email' ) && str_contains( $v, '@' ) ) {
				$ud['em'] = hash( 'sha256', strtolower( sanitize_email( $v ) ) );
			}

			if ( ! isset( $ud['ph'] ) && ( str_contains( $k, 'phone' ) || preg_match( '/(^|[_-])tel(efon)?([_-]|$)/', $k ) ) ) {
				$ph = preg_replace( '/\D/', '', $v );
				if ( strlen( $ph ) >= 9 ) {
					if ( ! str_starts_with( $ph, '48' ) ) {
						$ph = '48' . $ph;
					}
					$ud['ph'] = hash( 'sha256', $ph );
				}
			}

			if ( ! isset( $ud['fn'] ) && ( str_contains( $k, 'first' ) || str_contains( $k, 'fname' ) || str_contains( $k, 'imie' ) ) ) {
				$ud['fn'] = hash( 'sha256', strtolower( sanitize_text_field( $v ) ) );
			}

			if ( ! isset( $ud['ln'] ) && ( str_contains( $k, 'last' ) || str_contains( $k, 'lname' ) || str_contains( $k, 'surname' ) || str_contains( $k, 'nazwisko' ) ) ) {
				$ud['ln'] = hash( 'sha256', strtolower( sanitize_text_field( $v ) ) );
			}

			if ( null === $generic_name && in_array( $k, [ 'name', 'your-name', 'full-name', 'fullname', 'imie-i-nazwisko' ], true ) ) {
				$generic_name = sanitize_text_field( $v );
			}
		}

		if ( ! isset( $ud['fn'] ) && ! isset( $ud['ln'] ) && null !== $generic_name ) {
			$parts = preg_split( '/\s+/', $generic_name, 2 );
			if ( ! empty( $parts[0] ) ) {
				$ud['fn'] = hash( 'sha256', strtolower( $parts[0] ) );
			}
			if ( ! empty( $parts[1] ) ) {
				$ud['ln'] = hash( 'sha256', strtolower( $parts[1] ) );
			}
		}

		return $ud;
	}

	public function handle_lead_shortcode( $atts ) {
		$event_id = uniqid( 'mcs_lead_sc_', true );
		$this->capi->send_event( 'Lead', [], $event_id );

		ob_start();
		$this->pixel->fire_event( 'Lead', [], $event_id );
		return ob_get_clean();
	}
}
