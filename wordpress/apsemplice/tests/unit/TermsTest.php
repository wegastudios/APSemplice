<?php
use ApSemplice\Terms;
use PHPUnit\Framework\TestCase;

final class TermsTest extends TestCase {

	public function test_articles(): void {
		$this->assertSame( 'il comitato', Terms::with_article( 'comitato', 'm', false ) );
		$this->assertSame( 'del comitato', Terms::with_article( 'comitato', 'm', false, 'di' ) );
		$this->assertSame( "dell'ente no profit", Terms::with_article( 'ente no profit', 'm', false, 'di' ) );
		$this->assertSame( "l'ente no profit", Terms::with_article( 'ente no profit', 'm', false ) );
		$this->assertSame( 'lo studente', Terms::with_article( 'studente', 'm', false ) );
		$this->assertSame( 'allo zio', Terms::with_article( 'zio', 'm', false, 'a' ) );
		$this->assertSame( 'la onlus', Terms::with_article( 'onlus', 'f', false ) );
		$this->assertSame( "all'associata", Terms::with_article( 'associata', 'f', false, 'a' ) );
		$this->assertSame( 'ai sostenitori', Terms::with_article( 'sostenitori', 'm', true, 'a' ) );
		$this->assertSame( 'agli iscritti', Terms::with_article( 'iscritti', 'm', true, 'a' ) );
		$this->assertSame( 'delle iscritte', Terms::with_article( 'iscritte', 'f', true, 'di' ) );
		$this->assertSame( 'un comitato', Terms::indefinite( 'comitato', 'm' ) );
		$this->assertSame( 'uno zio', Terms::indefinite( 'zio', 'm' ) );
		$this->assertSame( "un'associata", Terms::indefinite( 'associata', 'f' ) );
		$this->assertSame( 'una onlus', Terms::indefinite( 'onlus', 'f' ) );
	}

	public function test_defaults_change_nothing(): void {
		$this->assertSame( array(), Terms::build_map( 'associazione', 'f', 'socio', 'soci', 'm' ) );
		$this->assertSame( 'Il socio paga', Terms::apply_map( 'Il socio paga', array() ) );
	}

	public function test_entity_type_with_gender_and_articles(): void {
		$m = Terms::build_map( 'comitato', 'm', 'socio', 'soci', 'm' );
		$this->assertSame( 'Il comitato, del comitato, al comitato, un comitato e dal comitato.', Terms::apply_map( "L'associazione, dell'associazione, all'associazione, un'associazione e dall'associazione.", $m ) );
		$this->assertSame( 'Comitato di prova', Terms::apply_map( 'Associazione di prova', $m ) );
		$this->assertSame( "I soci dell'anno sociale, associazioni", Terms::apply_map( "I soci dell'anno sociale, associazioni", $m ) );
		$f = Terms::build_map( 'onlus', 'f', 'socio', 'soci', 'm' );
		$this->assertSame( 'la onlus e della onlus', Terms::apply_map( "l'associazione e dell'associazione", $f ) );
	}

	public function test_member_term_masculine(): void {
		$m = Terms::build_map( 'associazione', 'f', 'iscritto', 'iscritti', 'm' );
		$this->assertSame( "L'iscritto paga la quota", Terms::apply_map( 'Il socio paga la quota', $m ) );
		$this->assertSame( "agli iscritti, degli iscritti e dell'iscritto", Terms::apply_map( 'ai soci, dei soci e del socio', $m ) );
		$this->assertSame( 'nuovo iscritto, Iscritti, un iscritto', Terms::apply_map( 'nuovo socio, Soci, un socio', $m ) );
		$this->assertSame( "anno sociale e dell'associazione", Terms::apply_map( "anno sociale e dell'associazione", $m ) ); // il tipo di ente resta com'è
	}

	public function test_member_term_feminine_agrees_articles(): void {
		$m = Terms::build_map( 'associazione', 'f', 'associata', 'associate', 'f' );
		$this->assertSame( 'le associate, delle associate, tutte le associate, nuove associate', Terms::apply_map( 'i soci, dei soci, tutti i soci, nuovi soci', $m ) );
		$this->assertSame( "dell'associata, un'associata, questa associata", Terms::apply_map( 'del socio, un socio, questo socio', $m ) );
	}

	public function test_web_addresses_and_emails_are_left_alone(): void {
		$m = Terms::build_map( 'associazione', 'f', 'iscritto', 'iscritti', 'm' );
		$this->assertSame( 'Vai a https://example.org/area-soci/ per gli iscritti, scrivi a soci@example.org', Terms::apply_map( 'Vai a https://example.org/area-soci/ per i soci, scrivi a soci@example.org', $m ) );
	}

	public function test_lists_from_settings_lines(): void {
		$this->assertSame( array( array( 'fondazione', 'f' ), array( 'museo', 'm' ) ), Terms::parse_lines( "fondazione;f\n\n  \nsolo\nmuseo ; m", 2 ) );
		$e = Terms::entity_types( "Fondazione;f\nmuseo;m" );
		$this->assertSame( 'f', $e['fondazione'] );
		$this->assertSame( 'f', $e['associazione'] );
		$this->assertSame( 'm', $e['museo'] );
		$t = Terms::member_terms( 'Tesserata;tesserate;f' );
		$this->assertSame( array( 'tesserate', 'f' ), $t['tesserata'] );
		$this->assertSame( array( 'soci', 'm' ), $t['socio'] );
	}

	public function test_feminine_qualifiers_and_presets(): void {
		$m = Terms::build_map( 'associazione', 'f', 'socia', 'socie', 'f' );
		$this->assertSame( 'Socia fondatrice, socia e volontaria, socie ordinarie', Terms::apply_map( 'Socio fondatore, socio e volontario, soci ordinari', $m ) );
		$this->assertSame( array( 'associazione', 'socia' ), Terms::PRESETS['femminile'] );
		$this->assertSame( array( 'comitato', 'socio' ), Terms::PRESETS['maschile'] );
	}
}
