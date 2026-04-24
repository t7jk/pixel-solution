<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCS_Events {

	private $pixel;
	private $capi;

	/** @var string Event ID dla bieżącego żądania (PageView). */
	private $pageview_event_id = '';

	public function __construct( MCS_Pixel $pixel, MCS_CAPI $capi ) {
		$this->pixel = $pixel;
		$this->capi  = $capi;
	}

	public function register_hooks() {
		$this->pageview_event_id = uniqid( 'mcs_pv_', true );

		add_action( 'wp_head', [ $this, 'handle_pageview_pixel' ] );
		add_action( 'template_redirect', [ $this, 'handle_pageview_capi' ] );

		add_action( 'wp_footer', [ $this, 'handle_viewcontent' ] );

		// wpcf7_before_send_mail odpala się niezależnie od powodzenia wysyłki maila.
		add_action( 'wpcf7_before_send_mail', [ $this, 'handle_lead_cf7' ] );

		// JS listener dla browser-side Lead po wysyłce AJAX przez CF7.
		add_action( 'wp_footer', [ $this, 'inject_cf7_lead_listener' ] );

		add_shortcode( 'mcs_lead_event', [ $this, 'handle_lead_shortcode' ] );
	}

	public function handle_pageview_pixel() {
		$this->pixel->inject_base_code( $this->pageview_event_id );
	}

	public function handle_pageview_capi() {
		if ( is_admin() ) {
			return;
		}
		$this->capi->send_event( 'PageView', [], $this->pageview_event_id );
	}

	public function handle_viewcontent() {
		if ( ! ( is_single() || is_page() ) ) {
			return;
		}

		$event_id = uniqid( 'mcs_vc_', true );
		$params   = [
			'content_name' => get_the_title(),
			'content_type' => 'product',
		];

		$this->pixel->fire_event( 'ViewContent', $params, $event_id );
		$this->capi->send_event( 'ViewContent', [ 'custom_data' => $params ], $event_id );
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
