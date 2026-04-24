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
	 * Używa wpcf7_before_send_mail zamiast wpcf7_mail_sent, bo działa
	 * niezależnie od tego czy CF7 zdoła wysłać email (problem na wielu hostingach).
	 */
	public function handle_lead_cf7( $contact_form ) {
		$event_id = uniqid( 'mcs_lead_', true );

		// Zapisujemy event_id w sesji PHP, żeby JS po stronie przeglądarki
		// mógł użyć tego samego ID do deduplikacji.
		if ( ! session_id() ) {
			session_start();
		}
		$_SESSION['pixel_solution_lead_event_id'] = $event_id;

		$this->capi->send_event( 'Lead', [], $event_id );
	}

	/**
	 * Wstrzykuje JS nasłuchujący na zdarzenie wpcf7mailsent (natywne CF7 AJAX).
	 * Odpalenie browser-side Lead dopiero po potwierdzeniu z serwera CF7.
	 */
	public function inject_cf7_lead_listener() {
		if ( ! get_option( 'mcs_pixel_id', '' ) ) {
			return;
		}
		?>
		<script>
		document.addEventListener('wpcf7mailsent', function(event) {
			if (typeof fbq === 'undefined') return;
			// event_id generujemy po stronie JS — deduplikacja przez CAPI event_id
			// nie jest możliwa cross-request, więc wysyłamy bez eventID (brak ryzyka duplikatu bo CAPI Lead ma swój ID)
			fbq('track', 'Lead');
		}, false);
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
