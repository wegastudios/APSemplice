<?php
use PHPUnit\Framework\TestCase;

/** La versione sta in tre posti (intestazione, costante, readme): devono dire la stessa cosa. */
final class VersionTest extends TestCase {

	private function main_file(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/apsemplice.php' );
	}

	public function test_header_and_constant_match(): void {
		preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $this->main_file(), $h );
		preg_match( "/define\( 'APSE_VERSION', '([^']+)' \)/", $this->main_file(), $c );
		$this->assertNotEmpty( $h[1] ?? '' );
		$this->assertSame( $h[1], $c[1] ?? '' );
	}

	public function test_readme_stable_tag_and_changelog_match(): void {
		preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $this->main_file(), $h );
		$readme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' );
		preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $s );
		$this->assertSame( $h[1], $s[1] ?? '' );
		$this->assertStringContainsString( '= ' . $h[1] . ' =', $readme, 'il changelog del readme ha una voce per la versione corrente' );
	}

	public function test_version_follows_the_numbering_rule(): void {
		preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $this->main_file(), $h );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+(\.\d+)?$/', $h[1] );
		if ( preg_match( '/^1\.1\.(\d+)$/', $h[1], $m ) ) {
			$this->assertLessThanOrEqual( 20, (int) $m[1], 'dopo la 1.1.20 si passa alla 1.2' );
		}
	}
}
