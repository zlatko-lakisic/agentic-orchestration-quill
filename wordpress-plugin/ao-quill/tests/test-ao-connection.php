<?php
/**
 * AO connection store: validation, encryption at rest, worker payload.
 *
 * @package AOQuill
 */

class Test_AOQ_AO_Connection extends AOQ_TestCase {

	/**
	 * A CA and a client certificate signed by it, generated for the test.
	 *
	 * @param string $cn Client common name.
	 * @return array{ca_pem:string, cert_pem:string, key_pem:string}
	 */
	private function material( $cn = 'ao-quill-worker' ) {
		$conf   = array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
			'digest_alg'       => 'sha256',
		);
		$ca_key = openssl_pkey_new( $conf );
		$ca_csr = openssl_csr_new( array( 'commonName' => 'Test AO CA' ), $ca_key, $conf );
		$ca     = openssl_csr_sign( $ca_csr, null, $ca_key, 30, $conf );
		$key    = openssl_pkey_new( $conf );
		$csr    = openssl_csr_new( array( 'commonName' => $cn ), $key, $conf );
		$cert   = openssl_csr_sign( $csr, $ca, $ca_key, 30, $conf );
		openssl_x509_export( $ca, $ca_pem );
		openssl_x509_export( $cert, $cert_pem );
		openssl_pkey_export( $key, $key_pem );
		return compact( 'ca_pem', 'cert_pem', 'key_pem' );
	}

	public function set_up() {
		parent::set_up();
		delete_site_option( AOQ_AO_Connection::OPTION );
		AOQ_AO_Connection::clear_certificate();
	}

	public function test_settings_require_https_for_ao() {
		$errors = AOQ_AO_Connection::save_settings( array( 'ao_url' => 'http://ao.example.internal:8765' ) );
		$this->assertNotEmpty( $errors );
		$this->assertSame( '', AOQ_AO_Connection::get()['ao_url'] );
		$this->assertSame( array(), AOQ_AO_Connection::save_settings( array( 'ao_url' => 'https://ao.example.internal:8765/', 'ao_ipv4_only_submitted' => '1', 'ao_ipv4_only' => '1' ) ) );
		$this->assertSame( 'https://ao.example.internal:8765', AOQ_AO_Connection::get()['ao_url'] );
		$this->assertTrue( AOQ_AO_Connection::get()['ao_ipv4_only'] );
	}

	public function test_certificate_round_trip_and_key_encrypted_at_rest() {
		$m    = $this->material();
		$info = AOQ_AO_Connection::save_certificate( $m['cert_pem'], $m['key_pem'], $m['ca_pem'] );
		$this->assertIsArray( $info );
		$this->assertSame( 'ao-quill-worker', $info['subject'] );
		$this->assertSame( 'Test AO CA', $info['issuer'] );
		$this->assertTrue( $info['key_readable'] );

		$stored = wp_json_encode( get_site_option( AOQ_AO_Connection::MTLS_OPTION ) );
		$this->assertStringNotContainsString( 'PRIVATE KEY', $stored, 'key must not be stored in clear' );

		AOQ_AO_Connection::save_settings( array( 'ao_url' => 'https://ao.example.internal:8765' ) );
		$payload = AOQ_AO_Connection::for_worker();
		$this->assertSame( trim( $m['key_pem'] ), trim( $payload['mtls']['key_pem'] ) );
		$this->assertSame( 'https://ao.example.internal:8765', $payload['ao_url'] );
		$this->assertTrue( AOQ_Schema::validate( $payload, 'abilities/get-ao-connection.output' ) );
	}

	public function test_mismatched_key_and_foreign_ca_are_rejected() {
		$a = $this->material();
		$b = $this->material( 'other' );
		$this->assertWPError( AOQ_AO_Connection::save_certificate( $a['cert_pem'], $b['key_pem'], $a['ca_pem'] ) );
		$this->assertWPError( AOQ_AO_Connection::save_certificate( $a['cert_pem'], $a['key_pem'], $b['ca_pem'] ) );
		$this->assertFalse( AOQ_AO_Connection::certificate_info()['present'] );
	}

	public function test_without_settings_the_payload_is_empty_and_a_run_is_blocked() {
		$payload = AOQ_AO_Connection::for_worker();
		$this->assertNull( $payload['ao_url'] );
		$this->assertNull( $payload['mtls'] );
		$this->assertNotEmpty( preg_grep( '/AO URL/', AOQ_AO_Connection::problems() ) );
	}

	public function test_abilities_are_worker_only_and_not_public() {
		foreach ( array( 'get-ao-connection', 'save-ao-certificate' ) as $name ) {
			$ability = wp_get_ability( "aoq/$name" );
			$this->assertNotNull( $ability, $name );
			$this->assertTrue( empty( $ability->get_meta()['mcp']['public'] ), $name );
		}
		wp_set_current_user( $this->author );
		$this->assertFalse( AOQ_Abilities::can_run() );
	}
}
