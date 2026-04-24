<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCS_Pixel {

	/** Wstrzykuje bazowy snippet Meta Pixel do <head>. */
	public function inject_base_code( $event_id = '' ) {
		$pixel_id = get_option( 'mcs_pixel_id', '' );
		if ( ! $pixel_id ) {
			return;
		}

		$event_id_js = $event_id ? esc_js( $event_id ) : '';
		?>
		<!-- Meta Pixel — MCS -->
		<script>
		!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
		n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
		n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
		t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
		document,'script','https://connect.facebook.net/en_US/fbevents.js');
		fbq('init', '<?php echo esc_js( $pixel_id ); ?>');
		<?php if ( $event_id_js ) : ?>
		fbq('track', 'PageView', {}, {eventID: '<?php echo $event_id_js; ?>'});
		<?php else : ?>
		fbq('track', 'PageView');
		<?php endif; ?>
		</script>
		<noscript><img height="1" width="1" style="display:none"
		src="https://www.facebook.com/tr?id=<?php echo esc_attr( $pixel_id ); ?>&ev=PageView&noscript=1"/></noscript>
		<!-- / Meta Pixel -->
		<?php
	}

	/**
	 * Emituje fbq('track', ...) inline w stopce dla zdarzeń innych niż PageView.
	 *
	 * @param string $event_name  Nazwa zdarzenia Meta.
	 * @param array  $params      Parametry zdarzenia.
	 * @param string $event_id    UUID do deduplikacji z CAPI.
	 */
	public function fire_event( $event_name, $params = [], $event_id = '' ) {
		$pixel_id = get_option( 'mcs_pixel_id', '' );
		if ( ! $pixel_id ) {
			return;
		}

		$params_json   = ! empty( $params ) ? wp_json_encode( $params ) : '{}';
		$event_id_json = $event_id ? wp_json_encode( [ 'eventID' => $event_id ] ) : '{}';
		?>
		<script>
		if (typeof fbq !== 'undefined') {
			fbq('track', '<?php echo esc_js( $event_name ); ?>',
				<?php echo $params_json; ?>,
				<?php echo $event_id_json; ?>
			);
		}
		</script>
		<?php
	}
}
