<?php
/**
 * Bounded activity log.
 *
 * @package Netdevguru_Bridge_For_AutomateFlow
 */

defined( 'ABSPATH' ) || exit;

/**
 * A short, capped record of what the plugin has been doing.
 *
 * Kept in an option rather than a custom table on purpose. The volume is inherently small —
 * this logs outcomes, not requests — and a table would mean dbDelta, an upgrade routine and
 * an uninstall drop for something that never needs to be queried by anything but the one
 * admin screen that renders it.
 *
 * The cap is what makes that safe: an option row that grows without bound is loaded on every
 * request that touches it and, if it ever landed in the autoload set, on every request full
 * stop. Writes here are explicitly non-autoloaded and the buffer is trimmed on every append.
 */
class Netdevguru_Bridge_Logger {

	const OPTION   = 'netdevguru_bridge_log';
	const MAX_ROWS = 100;

	/**
	 * Append an entry, trimming the oldest.
	 *
	 * @param string               $level   One of: info, warning, error.
	 * @param string               $message Human-readable summary.
	 * @param array<string, mixed> $context Extra detail; scalars only, never credentials.
	 */
	public static function log( $level, $message, array $context = array() ) {
		$entries = self::entries();

		array_unshift(
			$entries,
			array(
				'level'   => in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : 'info',
				'message' => sanitize_text_field( $message ),
				'context' => self::scalarize( $context ),
				'time'    => time(),
			)
		);

		update_option( self::OPTION, array_slice( $entries, 0, self::MAX_ROWS ), false );
	}

	/**
	 * Convenience wrappers.
	 *
	 * @param string               $message Summary.
	 * @param array<string, mixed> $context Detail.
	 */
	public static function info( $message, array $context = array() ) {
		self::log( 'info', $message, $context );
	}

	/**
	 * Warning-level entry.
	 *
	 * @param string               $message Summary.
	 * @param array<string, mixed> $context Detail.
	 */
	public static function warning( $message, array $context = array() ) {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Error-level entry.
	 *
	 * @param string               $message Summary.
	 * @param array<string, mixed> $context Detail.
	 */
	public static function error( $message, array $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * All stored entries, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries() {
		$entries = get_option( self::OPTION, array() );

		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Empty the log.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Flatten context to scalars.
	 *
	 * Nested structures are the usual way a full request body — and with it an API key or a
	 * recipient's personal data — ends up in a log that the admin screen then renders. Only
	 * scalars survive, and anything longer than a short string is truncated.
	 *
	 * @param array<string, mixed> $context Raw context.
	 * @return array<string, string>
	 */
	private static function scalarize( array $context ) {
		$out = array();

		foreach ( $context as $key => $value ) {
			if ( ! is_scalar( $value ) && null !== $value ) {
				continue;
			}

			$out[ sanitize_key( $key ) ] = sanitize_text_field( (string) substr( (string) $value, 0, 200 ) );
		}

		return $out;
	}
}
