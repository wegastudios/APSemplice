<?php
use ApSemplice\Edition;
use PHPUnit\Framework\TestCase;

final class EditionTest extends TestCase {

	public function test_unknown_feature_is_not_present(): void {
		$this->assertFalse( Edition::has( 'nonexistent' ) );
	}

	public function test_features_map_points_to_existing_files_in_the_full_code(): void {
		foreach ( Edition::FEATURES as $feature => $file ) {
			$this->assertNotNull( Edition::locate( $file ), $feature . ' (' . $file . ')' );
			$this->assertTrue( Edition::has( $feature ) );
		}
	}

	public function test_extra_directory_is_searched_for_classes(): void {
		$dir = sys_get_temp_dir() . '/apse-edition-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir . '/includes', 0777, true );
		file_put_contents( $dir . '/includes/Extra.php', '<?php' );
		$this->assertNull( Edition::locate( 'Extra.php' ) );
		Edition::add_dir( $dir . '/includes' );
		$this->assertSame( $dir . '/includes/Extra.php', Edition::locate( 'Extra.php' ) );
		unlink( $dir . '/includes/Extra.php' );
		rmdir( $dir . '/includes' );
		rmdir( $dir );
	}
}
