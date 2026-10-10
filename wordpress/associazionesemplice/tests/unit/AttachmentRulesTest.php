<?php
use AssociazioneSemplice\AttachmentRules;
use PHPUnit\Framework\TestCase;

final class AttachmentRulesTest extends TestCase {

	public function test_accepts_pdf_and_photos_by_real_type(): void {
		$this->assertNull( AttachmentRules::check( 'scontrino.pdf', 1000, 'application/pdf' ) );
		$this->assertNull( AttachmentRules::check( 'IMG_1.JPG', 1000, 'image/jpeg' ) );
		$this->assertNull( AttachmentRules::check( 'foto.heic', 1000, 'image/heic' ) );
		$this->assertNull( AttachmentRules::check( 'foto.png', 1000, 'image/png' ) );
	}

	public function test_rejects_wrong_or_disguised_files(): void {
		$this->assertNotNull( AttachmentRules::check( 'virus.php', 1000, 'application/pdf' ) ); // estensione che non corrisponde al contenuto
		$this->assertNotNull( AttachmentRules::check( 'foto.jpg', 1000, 'text/x-php' ) );       // contenuto che non è una foto
		$this->assertNotNull( AttachmentRules::check( 'pagina.html', 1000, 'text/html' ) );
		$this->assertNotNull( AttachmentRules::check( 'foto.jpg.php', 1000, 'image/jpeg' ) );
		$this->assertNotNull( AttachmentRules::check( 'senza-estensione', 1000, 'image/jpeg' ) );
	}

	public function test_size_limits(): void {
		$this->assertNotNull( AttachmentRules::check( 'a.pdf', 0, 'application/pdf' ) );
		$this->assertNull( AttachmentRules::check( 'a.pdf', AttachmentRules::MAX_BYTES, 'application/pdf' ) );
		$this->assertStringContainsString( '10 MB', (string) AttachmentRules::check( 'a.pdf', AttachmentRules::MAX_BYTES + 1, 'application/pdf' ) );
	}

	public function test_display_name_is_safe(): void {
		$this->assertSame( 'scontrino.pdf', AttachmentRules::display_name( '../../etc/scontrino.pdf' ) );
		$this->assertSame( 'scontrino.pdf', AttachmentRules::display_name( 'C:\Users\x\scontrino.pdf' ) );
		$this->assertSame( 'documento', AttachmentRules::display_name( "\x00<>" ) );
		$long = AttachmentRules::display_name( str_repeat( 'a', 300 ) . '.pdf' );
		$this->assertLessThanOrEqual( 120, strlen( $long ) );
		$this->assertSame( 'pdf', AttachmentRules::extension( $long ) );
	}

	public function test_stored_name_only_random_hex(): void {
		$hex = bin2hex( random_bytes( 16 ) );
		$this->assertSame( $hex, AttachmentRules::stored_name( $hex ) );
		$this->assertSame( '', AttachmentRules::stored_name( '../etc/passwd' ) );
		$this->assertSame( '', AttachmentRules::stored_name( 'abc' ) );
	}

	public function test_format_size(): void {
		$this->assertSame( '1 KB', AttachmentRules::format_size( 10 ) );
		$this->assertSame( '300 KB', AttachmentRules::format_size( 307200 ) );
		$this->assertSame( '2,5 MB', AttachmentRules::format_size( 2621440 ) );
	}

	public function test_normalize_files_single_and_multiple(): void {
		$this->assertSame( array( 'files' => array(), 'errors' => array() ), AttachmentRules::normalize_files( null ) );
		$single = AttachmentRules::normalize_files( array( 'name' => 'a.pdf', 'tmp_name' => '/tmp/x', 'size' => 5, 'error' => UPLOAD_ERR_OK ) );
		$this->assertCount( 1, $single['files'] );
		$multi = AttachmentRules::normalize_files(
			array(
				'name'     => array( 'a.pdf', '', 'c.jpg', 'd.jpg' ),
				'tmp_name' => array( '/tmp/a', '', '/tmp/c', '' ),
				'size'     => array( 1, 0, 3, 0 ),
				'error'    => array( UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK, UPLOAD_ERR_INI_SIZE ),
			)
		);
		$this->assertSame( array( 'a.pdf', 'c.jpg' ), array_column( $multi['files'], 'name' ) );
		$this->assertCount( 1, $multi['errors'] );
		$this->assertStringContainsString( 'troppo grande', $multi['errors'][0] );
	}
}
