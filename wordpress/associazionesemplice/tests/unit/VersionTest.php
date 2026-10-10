<?php
use PHPUnit\Framework\TestCase;

/** La versione sta in tre posti (intestazione, costante, readme): devono dire la stessa cosa. */
final class VersionTest extends TestCase {

	private function main_file(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/associazionesemplice.php' );
	}

	public function test_header_and_constant_match(): void {
		preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $this->main_file(), $h );
		preg_match( "/define\( 'ASEM_VERSION', '([^']+)' \)/", $this->main_file(), $c );
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

	/** Il Pro ha una versione sua (si aggiorna da solo), ma header, costante e readme dicono la stessa cosa e il livello di compatibilità coincide. */
	public function test_pro_plugin_is_consistent_and_compatible(): void {
		$pro = (string) file_get_contents( dirname( __DIR__, 3 ) . '/associazionesemplice-pro/associazionesemplice-pro.php' );
		preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $pro, $v );
		preg_match( "/define\( 'ASEM_PRO_VERSION', '([^']+)' \)/", $pro, $c );
		$this->assertSame( $v[1], $c[1] ?? '' );
		$readme = (string) file_get_contents( dirname( __DIR__, 3 ) . '/associazionesemplice-pro/readme.txt' );
		preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $s );
		$this->assertSame( $v[1], $s[1] ?? '' );
		preg_match( "/define\( 'ASEM_PRO_API', (\d+) \)/", $pro, $pa );
		preg_match( "/define\( 'ASEM_API', (\d+) \)/", $this->main_file(), $fa );
		$this->assertNotEmpty( $pa[1] ?? '' );
		$this->assertSame( $fa[1] ?? '', $pa[1] ?? 'x', 'ASEM_API e ASEM_PRO_API coincidono' );
	}
}
