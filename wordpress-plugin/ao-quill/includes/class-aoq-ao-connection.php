<?php
/**
 * AO connection kept in WordPress (docs/decisions.md D16): engine URL, search and
 * fetch settings, and the worker's mTLS client certificate. The worker reads it at
 * the start of every poll through aoq/get-ao-connection, so a worker server only
 * needs the WordPress URL and an Application Password.
 *
 * The private key is encrypted at rest with a key derived from this site's secret
 * keys (wp-config.php) and is never shown in the admin screens.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

class AOQ_AO_Connection {

	const OPTION      = 'aoq_ao_connection';
	const MTLS_OPTION = 'aoq_ao_mtls';

	/**
	 * Connection settings with defaults.
	 *
	 * @return array{ao_url:string, ao_ipv4_only:bool, searxng_url:string, user_agent:string}
	 */
	public static function get() {
		$s = get_site_option( self::OPTION, array() );
		return array(
			'ao_url'       => (string) ( $s['ao_url'] ?? '' ),
			'ao_ipv4_only' => ! empty( $s['ao_ipv4_only'] ),
			'searxng_url'  => (string) ( $s['searxng_url'] ?? '' ),
			'user_agent'   => (string) ( $s['user_agent'] ?? '' ),
		);
	}

	/**
	 * Validate and store connection settings. Returns error messages.
	 *
	 * @param array $input Raw input.
	 * @return string[]
	 */
	public static function save_settings( array $input ) {
		$errors = array();
		$out    = self::get();
		if ( isset( $input['ao_url'] ) ) {
			$url = esc_url_raw( trim( wp_unslash( (string) $input['ao_url'] ) ), array( 'https' ) );
			if ( '' !== trim( (string) $input['ao_url'] ) && '' === $url ) {
				$errors[] = __( 'The AO URL must start with https://.', 'ao-quill' );
			} else {
				$out['ao_url'] = untrailingslashit( $url );
			}
		}
		if ( isset( $input['searxng_url'] ) ) {
			$url = esc_url_raw( trim( wp_unslash( (string) $input['searxng_url'] ) ), array( 'http', 'https' ) );
			if ( '' !== trim( (string) $input['searxng_url'] ) && '' === $url ) {
				$errors[] = __( 'The SearXNG URL must be an http or https URL.', 'ao-quill' );
			} else {
				$out['searxng_url'] = untrailingslashit( $url );
			}
		}
		if ( isset( $input['user_agent'] ) ) {
			$out['user_agent'] = substr( sanitize_text_field( wp_unslash( (string) $input['user_agent'] ) ), 0, 300 );
		}
		if ( array_key_exists( 'ao_ipv4_only_submitted', $input ) ) {
			$out['ao_ipv4_only'] = ! empty( $input['ao_ipv4_only'] );
		}
		update_site_option( self::OPTION, $out );
		return $errors;
	}

	/**
	 * Encryption key for the private key, derived from the site's secret keys.
	 *
	 * @return string
	 */
	private static function box_key() {
		return sodium_crypto_generichash( wp_salt( 'secure_auth' ) . '|aoq-mtls-key', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Validate and store the client certificate, private key and AO CA.
	 *
	 * @param string $cert_pem Client certificate.
	 * @param string $key_pem  Private key.
	 * @param string $ca_pem   AO CA certificate.
	 * @return array|WP_Error Certificate info on success.
	 */
	public static function save_certificate( $cert_pem, $key_pem, $ca_pem ) {
		$cert_pem = trim( (string) $cert_pem ) . "\n";
		$key_pem  = trim( (string) $key_pem ) . "\n";
		$ca_pem   = trim( (string) $ca_pem ) . "\n";
		$cert     = openssl_x509_read( $cert_pem );
		$ca       = openssl_x509_read( $ca_pem );
		$key      = openssl_pkey_get_private( $key_pem );
		if ( ! $cert ) {
			return new WP_Error( 'aoq_bad_cert', __( 'The client certificate is not a valid PEM certificate.', 'ao-quill' ) );
		}
		if ( ! $ca ) {
			return new WP_Error( 'aoq_bad_ca', __( 'The AO CA certificate is not a valid PEM certificate.', 'ao-quill' ) );
		}
		if ( ! $key ) {
			return new WP_Error( 'aoq_bad_key', __( 'The private key is not a valid unencrypted PEM key.', 'ao-quill' ) );
		}
		if ( ! openssl_x509_check_private_key( $cert, $key ) ) {
			return new WP_Error( 'aoq_key_mismatch', __( 'The private key does not belong to the client certificate.', 'ao-quill' ) );
		}
		$ca_public = openssl_pkey_get_public( $ca );
		if ( ! $ca_public || 1 !== openssl_x509_verify( $cert, $ca_public ) ) {
			return new WP_Error( 'aoq_wrong_ca', __( 'The client certificate was not issued by this AO CA.', 'ao-quill' ) );
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		update_site_option(
			self::MTLS_OPTION,
			array(
				'cert_pem' => $cert_pem,
				'ca_pem'   => $ca_pem,
				'key_box'  => base64_encode( sodium_crypto_secretbox( $key_pem, $nonce, self::box_key() ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'nonce'    => base64_encode( $nonce ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'saved_at' => gmdate( 'c' ),
			)
		);
		return self::certificate_info();
	}

	/**
	 * Remove the stored certificate and key.
	 */
	public static function clear_certificate() {
		delete_site_option( self::MTLS_OPTION );
	}

	/**
	 * Public facts about the stored certificate. Never includes the key.
	 *
	 * @return array{present:bool, subject:string, issuer:string, expires_at:string, expired:bool, key_readable:bool}
	 */
	public static function certificate_info() {
		$m = get_site_option( self::MTLS_OPTION, array() );
		if ( empty( $m['cert_pem'] ) ) {
			return array(
				'present'      => false,
				'subject'      => '',
				'issuer'       => '',
				'expires_at'   => '',
				'expired'      => false,
				'key_readable' => false,
			);
		}
		$info    = openssl_x509_parse( $m['cert_pem'] );
		$expires = isset( $info['validTo_time_t'] ) ? (int) $info['validTo_time_t'] : 0;
		return array(
			'present'      => true,
			'subject'      => (string) ( $info['subject']['CN'] ?? '' ),
			'issuer'       => (string) ( $info['issuer']['CN'] ?? '' ),
			'expires_at'   => $expires ? gmdate( 'Y-m-d', $expires ) : '',
			'expired'      => $expires > 0 && $expires < time(),
			'key_readable' => null !== self::private_key(),
		);
	}

	/**
	 * Decrypted private key, or null when missing or undecryptable (secret keys changed).
	 *
	 * @return string|null
	 */
	private static function private_key() {
		$m = get_site_option( self::MTLS_OPTION, array() );
		if ( empty( $m['key_box'] ) || empty( $m['nonce'] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$plain = sodium_crypto_secretbox_open( (string) base64_decode( $m['key_box'] ), (string) base64_decode( $m['nonce'] ), self::box_key() );
		return false === $plain ? null : $plain;
	}

	/**
	 * Everything the worker needs, shaped by contracts/abilities/get-ao-connection.output.json.
	 *
	 * @return array
	 */
	public static function for_worker() {
		$s    = self::get();
		$m    = get_site_option( self::MTLS_OPTION, array() );
		$key  = self::private_key();
		$mtls = null;
		if ( ! empty( $m['cert_pem'] ) && null !== $key ) {
			$mtls = array(
				'cert_pem' => $m['cert_pem'],
				'key_pem'  => $key,
				'ca_pem'   => $m['ca_pem'],
			);
		}
		return array(
			'ao_url'       => '' !== $s['ao_url'] ? $s['ao_url'] : null,
			'ao_ipv4_only' => $s['ao_ipv4_only'],
			'searxng_url'  => '' !== $s['searxng_url'] ? $s['searxng_url'] : null,
			'user_agent'   => '' !== $s['user_agent'] ? $s['user_agent'] : null,
			'mtls'         => $mtls,
		);
	}

	/**
	 * Problems that block a run.
	 *
	 * @return string[]
	 */
	public static function problems() {
		$problems = array();
		if ( '' === self::get()['ao_url'] ) {
			$problems[] = __( 'Set the AO URL in the AO connection settings.', 'ao-quill' );
		}
		$c = self::certificate_info();
		if ( $c['present'] && ! $c['key_readable'] ) {
			$problems[] = __( 'The stored AO private key cannot be decrypted (the site secret keys changed). Upload the certificate and key again.', 'ao-quill' );
		}
		if ( $c['expired'] ) {
			$problems[] = __( 'The AO client certificate has expired. Enroll the worker again.', 'ao-quill' );
		}
		return $problems;
	}
}
