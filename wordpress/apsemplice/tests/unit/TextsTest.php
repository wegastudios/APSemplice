<?php
use ApSemplice\Texts;
use PHPUnit\Framework\TestCase;

final class TextsTest extends TestCase {

	public function test_which_strings_are_texts(): void {
		foreach ( array( 'Le mie ricevute', 'Salva', 'Posti esauriti', 'RICEVUTA DI PAGAMENTO', 'ti ricordiamo la prenotazione di domani', "Ciao, ecco il link per il tuo primo accesso:" ) as $s ) {
			$this->assertTrue( Texts::is_text( $s ), $s );
		}
		foreach ( array( 'apse_save_person', 'apsf-btn apsf-small', 'SELECT * FROM x', 'FOUNDER', 'text/csv', 'ab', 'Europe/Rome', 'wp-login.php?action=rp', '%s: %d', '$wpdb->prefix', 'a b', 'https://example.org/x', 'Content-Type: application/pdf' ) as $s ) {
			$this->assertFalse( Texts::is_text( $s ), $s );
		}
	}

	public function test_fragments_are_extracted_from_php_source(): void {
		$src = <<<'PHP'
<?php
echo '<section class="x"><h3>Le mie ricevute</h3><p class="apsf-muted">Nessun pagamento registrato finora.</p></section>';
$a = 'apse_front_book';
throw new Exception( 'Posti esauriti per questa data.' );
$b = "Ciao,\n\necco il link per l'accesso";
$c = 'Non c\'è posto';
PHP;
		$f = Texts::fragments_of_source( $src );
		$this->assertContains( 'Le mie ricevute', $f );
		$this->assertContains( 'Nessun pagamento registrato finora.', $f );
		$this->assertContains( 'Posti esauriti per questa data.', $f );
		$this->assertContains( "Non c'è posto", $f );
		$this->assertNotContains( 'apse_front_book', $f );
		$this->assertNotContains( 'x', $f );
	}

	public function test_html_replaces_only_text_not_tags_scripts_or_urls(): void {
		$map  = array( 'Salva' => 'Conferma & chiudi', 'Cerca per nome' => 'Trova un socio' );
		$html = '<a href="/Salva">Salva</a><input placeholder="Cerca per nome" class="Salva"><script>var a="Salva";</script><style>.Salva{}</style> Salva!';
		$out  = Texts::html( $html, $map );
		$this->assertStringContainsString( '<a href="/Salva">Conferma &amp; chiudi</a>', $out );
		$this->assertStringContainsString( 'placeholder="Trova un socio"', $out );
		$this->assertStringContainsString( 'class="Salva"', $out );
		$this->assertStringContainsString( '<script>var a="Salva";</script>', $out );
		$this->assertStringContainsString( '<style>.Salva{}</style> Conferma &amp; chiudi!', $out );
	}

	public function test_html_matches_escaped_apostrophes_and_protects_the_new_text(): void {
		$map = array( "Non c'è posto" => 'Tutto esaurito <b>!</b>' );
		$this->assertSame( '<p>Tutto esaurito &lt;b&gt;!&lt;/b&gt;</p>', Texts::html( '<p>Non c&#039;è posto</p>', $map ) );
		$this->assertSame( '<p>Tutto esaurito &lt;b&gt;!&lt;/b&gt;</p>', Texts::html( "<p>Non c'è posto</p>", $map ) );
	}

	public function test_longest_text_wins_and_plain_is_not_escaped(): void {
		$map = array( 'Da versare' => 'Da pagare', 'Da versare 5,00 €' => 'Resta 5 euro' );
		$this->assertSame( 'Resta 5 euro e Da pagare', Texts::plain( 'Da versare 5,00 € e Da versare', $map ) );
		$this->assertSame( 'a & b', Texts::plain( 'a & b', array() ) );
		$this->assertSame( '', Texts::plain( '', $map ) );
	}
}
