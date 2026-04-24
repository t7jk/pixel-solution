<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCS_CAPI {

	const GRAPH_URL = 'https://graph.facebook.com/v19.0/%s/events';

	/**
	 * Wysyła zdarzenie do Meta Conversions API.
	 *
	 * @param string $event_name Nazwa zdarzenia.
	 * @param array  $event_data Dodatkowe dane zdarzenia (nadpisują/uzupełniają domyślne).
	 * @param string $event_id   UUID do deduplikacji z pikselem przeglądarkowym.
	 */
	public function send_event( $event_name, $event_data = [], $event_id = '' ) {
		$pixel_id = get_option( 'mcs_pixel_id', '' );
		$token    = get_option( 'mcs_capi_token', '' );

		if ( ! $pixel_id || ! $token ) {
			return;
		}

		$source_url = ( is_ssl() ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

		$user_data = [
			'client_ip_address' => hash( 'sha256', sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
			'client_user_agent' => hash( 'sha256', sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
		];

		if ( ! empty( $_COOKIE['_fbc'] ) ) {
			$user_data['fbc'] = sanitize_text_field( $_COOKIE['_fbc'] );
		}
		if ( ! empty( $_COOKIE['_fbp'] ) ) {
			$user_data['fbp'] = sanitize_text_field( $_COOKIE['_fbp'] );
		}

		$payload_event = array_merge(
			[
				'event_name'       => $event_name,
				'event_time'       => time(),
				'event_source_url' => $source_url,
				'action_source'    => 'website',
				'user_data'        => $user_data,
			],
			$event_data
		);

		if ( $event_id ) {
			$payload_event['event_id'] = $event_id;
		}

		$body = [ 'data' => [ $payload_event ] ];

		$test_code = get_option( 'mcs_test_event_code', '' );
		if ( $test_code ) {
			$body['test_event_code'] = $test_code;
		}

		$url      = sprintf( self::GRAPH_URL, $pixel_id ) . '?access_token=' . rawurlencode( $token );
		$response = wp_remote_post(
			$url,
			[
				'headers'     => [ 'Content-Type' => 'application/json' ],
				'body'        => wp_json_encode( $body ),
				'timeout'     => 10,
				'data_format' => 'body',
			]
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[MCS CAPI] WP Error: ' . $response->get_error_message() );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			error_log( '[MCS CAPI] HTTP ' . $code . ' — ' . wp_remote_retrieve_body( $response ) );
		}
	}
}
