<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCS_Log {

	const OPTION      = 'mcs_pixel_log';
	const MAX_AGE     = 86400; // 24h
	const MAX_ENTRIES = 300;

	public static function write( array $entry ): void {
		$raw     = get_option( self::OPTION, '[]' );
		$entries = json_decode( $raw, true );
		if ( ! is_array( $entries ) ) {
			$entries = [];
		}

		$cutoff  = time() - self::MAX_AGE;
		$entries = array_values( array_filter( $entries, fn( $e ) => ( $e['ts'] ?? 0 ) > $cutoff ) );

		if ( count( $entries ) >= self::MAX_ENTRIES ) {
			array_shift( $entries );
		}

		$entries[] = $entry;
		update_option( self::OPTION, wp_json_encode( $entries ), false );
	}

	public static function read(): array {
		$raw     = get_option( self::OPTION, '[]' );
		$entries = json_decode( $raw, true );
		if ( ! is_array( $entries ) ) {
			return [];
		}
		$cutoff = time() - self::MAX_AGE;
		return array_values( array_filter( $entries, fn( $e ) => ( $e['ts'] ?? 0 ) > $cutoff ) );
	}

	public static function clear(): void {
		update_option( self::OPTION, '[]', false );
	}
}
