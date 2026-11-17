<?php
/**
 * Abilities, permissions, worker role, adapter, media checks, contracts.
 *
 * @package AOQuill
 */

class Test_AOQ_Abilities extends AOQ_TestCase {

	public function test_all_abilities_registered_with_schemas() {
		foreach ( array_keys( AOQ_Abilities::definitions() ) as $name ) {
			$ability = wp_get_ability( "aoq/$name" );
			$this->assertNotNull( $ability, $name );
			$this->assertNotEmpty( $ability->get_input_schema(), $name );
			$this->assertNotEmpty( $ability->get_output_schema(), $name );
		}
	}

	public function test_write_abilities_not_public_for_mcp() {
		foreach ( AOQ_Abilities::definitions() as $name => $def ) {
			$meta = wp_get_ability( "aoq/$name" )->get_meta();
			$this->assertSame( $def[1], ! empty( $meta['mcp']['public'] ), $name );
		}
	}

	public function test_permissions() {
		wp_set_current_user( 0 );
		$this->assertFalse( AOQ_Abilities::can_run() );
		wp_set_current_user( $this->author );
		$this->assertFalse( AOQ_Abilities::can_run() );
		wp_set_current_user( AOQ_Install::worker_user_id() );
		$this->assertTrue( AOQ_Abilities::can_run() );
	}

	public function test_worker_role_has_only_the_worker_capability() {
		switch_to_blog( get_main_site_id() );
		$role = get_role( AOQ_Install::ROLE );
		restore_current_blog();
		$this->assertSame( array( AOQ_Install::CAP => true ), $role->capabilities );
		$user = get_userdata( AOQ_Install::worker_user_id() );
		$this->assertFalse( is_super_admin( $user->ID ) );
		$this->assertFalse( user_can( $user, 'edit_posts' ) );
		$this->assertFalse( user_can( $user, 'upload_files' ) );
		$this->assertFalse( user_can( $user, 'read' ) );
	}

	public function test_execute_claim_through_the_ability() {
		wp_set_current_user( AOQ_Install::worker_user_id() );
		AOQ_Runs::request_run_now();
		$out = wp_get_ability( 'aoq/claim-run' )->execute( $this->claim_input() );
		$this->assertIsArray( $out );
		$this->assertNotNull( $out['run'] );
	}

	public function test_ability_rejects_invalid_input() {
		wp_set_current_user( AOQ_Install::worker_user_id() );
		$out = wp_get_ability( 'aoq/report-stage' )->execute( array( 'run_id' => 'nope' ) );
		$this->assertWPError( $out );
	}

	public function test_inventory_covers_both_languages() {
		$page  = AOQ_Inventory::page( 1, 200 );
		$langs = array_unique( wp_list_pluck( $page['posts'], 'language' ) );
		sort( $langs );
		$this->assertSame( array( 'bs', 'en' ), $langs );
		$this->assertCount( 2, $page['languages'] );
		$this->assertTrue( AOQ_Schema::validate( $page, 'abilities/get-inventory.output' ) );
	}

	public function test_inventory_slices() {
		$this->assertSame( array( 1 => array( 150, 50 ), 3 => array( 0, 50 ) ), AOQ_Inventory::slices( array( 1 => 200, 3 => 300 ), 150, 100 ) );
		$this->assertSame( array( 3 => array( 0, 10 ) ), AOQ_Inventory::slices( array( 1 => 0, 3 => 10 ), 0, 100 ) );
	}

	public function test_language_conflict_is_a_problem() {
		$other = self::factory()->blog->create( array( 'path' => '/de/' ) );
		update_blog_option( $other, 'WPLANG', '' );
		AOQ_Plugin::translation_adapter()->reset();
		$this->assertNotEmpty( AOQ_Plugin::translation_adapter()->problems() );
		$s                      = AOQ_Settings::get();
		$s['excluded_blog_ids'] = array( $other );
		AOQ_Settings::save( $s );
		$this->assertSame( array(), AOQ_Plugin::translation_adapter()->problems() );
	}

	public function test_media_check_rejects_non_images() {
		$tmp = wp_tempnam( 'aoq-test' );
		file_put_contents( $tmp, '<?php echo 1;' ); // phpcs:ignore
		$this->expectException( RuntimeException::class );
		try {
			AOQ_Media::check_file( $tmp );
		} finally {
			unlink( $tmp ); // phpcs:ignore
		}
	}

	public function test_media_check_accepts_jpeg() {
		$this->assertSame( 'image/jpeg', AOQ_Media::check_file( DIR_TESTDATA . '/images/canola.jpg' ) );
	}

	/**
	 * Shared fixtures from docs/contracts validate the same way as in Python.
	 */
	public function test_contract_fixtures() {
		$root = dirname( __DIR__, 3 ) . '/docs/contracts/fixtures';
		if ( ! is_dir( $root ) ) {
			$this->markTestSkipped( 'docs/contracts not available' );
		}
		$count = 0;
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$name   = substr( dirname( $file->getPathname() ), strlen( $root ) + 1 );
			$name   = str_replace( '\\', '/', $name );
			$value  = json_decode( (string) file_get_contents( $file->getPathname() ), true );
			$result = AOQ_Schema::validate( $value, $name );
			if ( 0 === strpos( $file->getFilename(), 'valid-' ) ) {
				$this->assertTrue( $result, $file->getPathname() . ( is_wp_error( $result ) ? ': ' . $result->get_error_message() : '' ) );
			} else {
				$this->assertWPError( $result, $file->getPathname() );
			}
			++$count;
		}
		$this->assertGreaterThan( 5, $count );
	}
}
