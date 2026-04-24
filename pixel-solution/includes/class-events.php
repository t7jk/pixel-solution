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
		// PageView — generujemy event_id raz na żądanie.
		$this->pageview_event_id = uniqid( 'mcs_pv_', true );

		add_action( 'wp_head', [ $this, 'handle_pageview_pixel' ] );
		add_action( 'template_redirect', [ $this, 'handle_pageview_capi' ] );

		// ViewContent — dla pojedynczych postów/stron.
		add_action( 'wp_footer', [ $this, 'handle_viewcontent' ] );

		// Lead — integracja z CF7.
		add_action( 'wpcf7_mail_sent', [ $this, 'handle_lead_cf7' ] );

		// Shortcode [mcs_lead_event] — manualne odpalenie Lead.
		add_shortcode( 'mcs_lead_event', [ $this, 'handle_lead_shortcode' ] );
	}

	/** Wstrzykuje bazowy snippet piksela z event_id PageView. */
	public function handle_pageview_pixel() {
		$this->pixel->inject_base_code( $this->pageview_event_id );
	}

	/** Wysyła PageView przez CAPI (server-side). */
	public function handle_pageview_capi() {
		if ( is_admin() ) {
			return;
		}
		$this->capi->send_event( 'PageView', [], $this->pageview_event_id );
	}

	/** ViewContent dla is_single() / is_page(). */
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

	/** Lead po wysłaniu formularza CF7. */
	public function handle_lead_cf7( $contact_form ) {
		$event_id = uniqid( 'mcs_lead_', true );
		$this->capi->send_event( 'Lead', [], $event_id );

		// Pixel w stopce — CF7 działa przez AJAX, więc dodajemy skrypt inline.
		add_action(
			'wp_footer',
			function () use ( $event_id ) {
				$this->pixel->fire_event( 'Lead', [], $event_id );
			}
		);
	}

	/** Shortcode [mcs_lead_event] — odpalenie Lead ręcznie na stronie podziękowania. */
	public function handle_lead_shortcode( $atts ) {
		$event_id = uniqid( 'mcs_lead_sc_', true );
		$this->capi->send_event( 'Lead', [], $event_id );

		ob_start();
		$this->pixel->fire_event( 'Lead', [], $event_id );
		return ob_get_clean();
	}
}
