<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCS_Events {

	private $pixel;
	private $capi;

	public function __construct( MCS_Pixel $pixel, MCS_CAPI $capi ) {
		$this->pixel = $pixel;
		$this->capi  = $capi;
	}

	public function register_hooks() {
		add_action( 'wp_head', [ $this, 'handle_pageview' ] );
		add_action( 'wp_footer', [ $this, 'handle_viewcontent' ] );

		// AJAX endpoint dla CAPI — działa nawet gdy strona pochodzi z cache.
		add_action( 'wp_ajax_nopriv_mcs_capi_event', [ $this, 'handle_ajax_capi' ] );
		add_action( 'wp_ajax_mcs_capi_event',        [ $this, 'handle_ajax_capi' ] );

		// wpcf7_before_send_mail odpala się niezależnie od powodzenia wysyłki maila.
		add_action( 'wpcf7_before_send_mail', [ $this, 'handle_lead_cf7' ] );

		// JS listener dla browser-side Lead po wysyłce AJAX przez CF7.
		add_action( 'wp_footer', [ $this, 'inject_cf7_lead_listener' ] );

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
		if ( ! ( is_single() || is_page() ) ) {
			return;
		}
		if ( ! get_option( 'mcs_pixel_id', '' ) ) {
			return;
		}

		$content_name = get_the_title();
		$ajax_url     = admin_url( 'admin-ajax.php' );
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
			fbq('track', 'ViewContent', params, {eventID: id});
			fetch('<?php echo esc_url( $ajax_url ); ?>', {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: new URLSearchParams({
					action: 'mcs_capi_event',
					event_name: 'ViewContent',
					event_id: id,
					'custom_data[content_name]': params.content_name,
					'custom_data[content_type]': params.content_type
				}),
				keepalive: true
			});
		})();
		</script>
		<?php
	}

	/**
	 * Odbiera wywołania CAPI z JS fetch — admin-ajax.php nie jest cachowany,
	 * więc PHP wykonuje się zawsze, nawet gdy strona główna pochodzi z cache.
	 */
	public function handle_ajax_capi() {
		$allowed    = [ 'PageView', 'ViewContent' ];
		$event_name = isset( $_POST['event_name'] ) ? sanitize_text_field( $_POST['event_name'] ) : '';
		$event_id   = isset( $_POST['event_id'] )   ? sanitize_text_field( $_POST['event_id'] )   : '';

		if ( ! in_array( $event_name, $allowed, true ) ) {
			wp_die( '', '', [ 'response' => 400 ] );
		}

		$event_data = [];
		if ( ! empty( $_POST['custom_data'] ) && is_array( $_POST['custom_data'] ) ) {
			$event_data['custom_data'] = array_map( 'sanitize_text_field', $_POST['custom_data'] );
		}

		$this->capi->send_event( $event_name, $event_data, $event_id );
		wp_die();
	}

	/**
	 * CAPI Lead — odpala server-side podczas przetwarzania formularza CF7.
	 * event_id pochodzi z ukrytego pola wstrzykniętego przez JS przed submitem,
	 * co umożliwia deduplikację z pikselem przeglądarkowym.
	 */
	public function handle_lead_cf7( $contact_form ) {
		$submission  = WPCF7_Submission::get_instance();
		$posted_data = $submission ? $submission->get_posted_data() : [];
		$event_id    = ! empty( $posted_data['mcs_lead_event_id'] )
			? sanitize_text_field( $posted_data['mcs_lead_event_id'] )
			: uniqid( 'mcs_lead_', true );

		$this->capi->send_event( 'Lead', [], $event_id );
	}

	/**
	 * Wstrzykuje JS który:
	 * 1. Przed submitem generuje event_id i wstawia go jako ukryte pole formularza.
	 * 2. Po potwierdzeniu wysyłki (wpcf7mailsent) odpala fbq Lead z tym samym event_id.
	 */
	public function inject_cf7_lead_listener() {
		if ( ! get_option( 'mcs_pixel_id', '' ) ) {
			return;
		}
		?>
		<script>
		(function() {
			function mcsGenEventId() {
				if (typeof crypto !== 'undefined' && crypto.randomUUID) {
					return 'mcs_lead_' + crypto.randomUUID();
				}
				return 'mcs_lead_' + Math.random().toString(36).slice(2, 11) + '_' + Date.now();
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

			document.addEventListener('DOMContentLoaded', function() {
				document.querySelectorAll('.wpcf7-form').forEach(mcsInjectEventId);
			});

			document.addEventListener('wpcf7mailsent', function(e) {
				if (typeof fbq === 'undefined') return;
				var form    = e.target.querySelector('form') || e.target;
				var idInput = e.target.querySelector('input[name="mcs_lead_event_id"]');
				var eventId = idInput ? idInput.value : '';

				if (eventId) {
					fbq('track', 'Lead', {}, {eventID: eventId});
					// Regeneruj ID na wypadek kolejnego wysłania bez przeładowania
					if (idInput) idInput.value = mcsGenEventId();
				} else {
					fbq('track', 'Lead');
				}
			}, false);
		})();
		</script>
		<?php
	}

	public function handle_lead_shortcode( $atts ) {
		$event_id = uniqid( 'mcs_lead_sc_', true );
		$this->capi->send_event( 'Lead', [], $event_id );

		ob_start();
		$this->pixel->fire_event( 'Lead', [], $event_id );
		return ob_get_clean();
	}
}
