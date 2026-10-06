<?php
use ApSemplice\MemberType;
use ApSemplice\PeopleCsv;
use ApSemplice\Rules;
use PHPUnit\Framework\TestCase;

final class MemberTypeTest extends TestCase {
	public function test_only_volunteers_can_teach(): void {
		$this->assertTrue( MemberType::can_teach( MemberType::VOLUNTEER ) );
		$this->assertFalse( MemberType::can_teach( MemberType::FOUNDER ) );
		$this->assertFalse( MemberType::can_teach( MemberType::ORDINARY ) );
		$this->assertFalse( MemberType::can_teach( MemberType::GUEST ) );
	}

	public function test_only_founders_are_auto_renewed(): void {
		$this->assertTrue( MemberType::is_auto_renewed( MemberType::FOUNDER ) );
		$this->assertFalse( MemberType::is_auto_renewed( MemberType::ORDINARY ) );
	}

	public function test_guest_is_not_a_member(): void {
		$this->assertFalse( MemberType::is_member( MemberType::GUEST ) );
		$this->assertFalse( MemberType::requires_email( MemberType::GUEST ) );
		$this->assertTrue( MemberType::requires_email( MemberType::ORDINARY ) );
		$this->assertTrue( MemberType::requires_host( MemberType::GUEST ) );
	}

	public function test_from_text(): void {
		$this->assertSame( MemberType::FOUNDER, MemberType::from_text( 'Socio fondatore' ) );
		$this->assertSame( MemberType::ORDINARY, MemberType::from_text( 'ordinario' ) );
		$this->assertSame( MemberType::VOLUNTEER, MemberType::from_text( 'Socio e volontario' ) );
		$this->assertNull( MemberType::from_text( 'boh' ) );
	}
}

final class RulesTest extends TestCase {
	private function person( array $over = array() ): array {
		return array_merge( array( 'type' => 'ordinary', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'm@example.com', 'card_number' => '', 'host_person_id' => null ), $over );
	}

	public function test_valid_member(): void {
		$this->assertSame( array(), Rules::validate_person( $this->person() ) );
	}

	public function test_member_email_is_optional_but_must_be_valid(): void {
		foreach ( array( 'founder', 'ordinary', 'volunteer' ) as $t ) {
			$this->assertSame( array(), Rules::validate_person( $this->person( array( 'type' => $t, 'email' => '' ) ) ), $t );
		}
		$this->assertNotEmpty( Rules::validate_person( $this->person( array( 'email' => 'non-una-email' ) ) ) );
	}

	public function test_member_without_email_needs_phone_or_card_number(): void {
		foreach ( array( 'founder', 'ordinary', 'volunteer' ) as $t ) {
			$this->assertSame( array(), Rules::validate_person( $this->person( array( 'type' => $t, 'email' => '', 'phone' => '333 1234567' ) ) ), "$t con il cellulare" );
			$this->assertSame( array(), Rules::validate_person( $this->person( array( 'type' => $t, 'email' => '', 'card_number' => '57' ) ) ), "$t con la tessera" );
		}
		$this->assertNotEmpty( Rules::validate_person( $this->person( array( 'email' => '', 'phone' => '123' ) ) ), 'un cellulare non valido non basta' );
	}

	public function test_import_members_without_email(): void {
		$plan = function ( string $csv, array $existing = array() ) {
			return PeopleCsv::plan( PeopleCsv::parse( $csv )['rows'], $existing );
		};
		$this->assertSame( 'create', $plan( "Nome;Cognome;Cellulare\nLuca;Neri;333 1234567" )[0]['action'] );
		$this->assertSame( 'create', $plan( "Tessera;Nome;Cognome\n30;Luca;Neri" )[0]['action'] );
		$this->assertSame( 'error', $plan( "Nome;Cognome;Cellulare\nLuca;Neri;12" )[0]['action'], 'senza email né tessera il cellulare deve essere valido' );
		$existing = array( array( 'id' => 5, 'card' => null, 'first' => 'Luca', 'last' => 'Nerone', 'email' => null, 'tax' => null, 'phone' => '+39 333 1234567' ) );
		$p        = $plan( "Nome;Cognome;Cellulare\nLuca;Neri;333-1234567", $existing );
		$this->assertSame( 'update', $p[0]['action'], 'senza email il cellulare riconosce il socio già presente' );
		$this->assertSame( 5, $p[0]['matched_id'] );
		$two = $plan( "Nome;Cognome;Cellulare\nLuca;Neri;333 1111111\nPaola;Gialli;333 2222222" );
		$this->assertSame( array( 'create', 'create' ), array_column( $two, 'action' ), 'più soci senza email nello stesso file non si scontrano' );
	}

	public function test_guest_needs_a_member_host_and_no_card(): void {
		$guest = $this->person( array( 'type' => 'guest', 'email' => '', 'phone' => '333 1234567', 'host_person_id' => 5 ) );
		$this->assertNotEmpty( Rules::validate_person( $guest, null ) );
		$this->assertNotEmpty( Rules::validate_person( $guest, array( 'type' => 'guest' ) ) );
		$this->assertSame( array(), Rules::validate_person( $guest, array( 'type' => 'ordinary' ) ) );
		$this->assertNotEmpty( Rules::validate_person( array_merge( $guest, array( 'card_number' => '12' ) ), array( 'type' => 'ordinary' ) ) );
		$this->assertNotEmpty( Rules::validate_person( $this->person( array( 'host_person_id' => 5 ) ) ) );
	}

	public function test_guest_needs_a_mobile_number(): void {
		$guest = $this->person( array( 'type' => 'guest', 'email' => '', 'host_person_id' => 5 ) );
		foreach ( array( '', 'abc', '12345' ) as $bad ) {
			$this->assertNotEmpty( Rules::validate_person( array_merge( $guest, array( 'phone' => $bad ) ), array( 'type' => 'ordinary' ) ), "cellulare: $bad" );
		}
		$this->assertSame( array(), Rules::validate_person( array_merge( $guest, array( 'phone' => '+39 333 123 4567' ) ), array( 'type' => 'ordinary' ) ) );
		$this->assertSame( array(), Rules::validate_person( $this->person( array( 'type' => 'ordinary', 'phone' => '' ) ) ), 'i soci non hanno l obbligo' );
	}

	public function test_names_required(): void {
		$this->assertNotEmpty( Rules::validate_person( $this->person( array( 'first_name' => ' ' ) ) ) );
	}

	public function test_fields_longer_than_the_columns_are_refused(): void {
		$this->assertNotEmpty( Rules::validate_person( $this->person( array( 'first_name' => str_repeat( 'a', 121 ) ) ) ) );
		$this->assertNotEmpty( Rules::validate_person( $this->person( array( 'last_name' => str_repeat( 'b', 121 ) ) ) ) );
		$this->assertNotEmpty( Rules::validate_person( $this->person( array( 'tax_code' => str_repeat( 'C', 33 ) ) ) ) );
		$this->assertNotEmpty( Rules::validate_person( $this->person( array( 'phone' => str_repeat( '3', 61 ) ) ) ) );
		$this->assertSame( array(), Rules::validate_person( $this->person( array( 'first_name' => str_repeat( 'à', 100 ) ) ) ), 'il limite conta i caratteri, non i byte' );
	}

	public function test_joined_on_must_be_a_real_date(): void {
		$this->assertSame( array(), Rules::validate_person( $this->person( array( 'joined_on' => '2024-02-29' ) ) ) );
		$this->assertSame( array(), Rules::validate_person( $this->person( array( 'joined_on' => '' ) ) ), 'vuota: si usa oggi' );
		foreach ( array( '2023-02-29', '2024-13-01', 'ieri', '15/01/2024' ) as $bad ) {
			$this->assertNotEmpty( Rules::validate_person( $this->person( array( 'joined_on' => $bad ) ) ), $bad );
		}
	}

	public function test_activity_instructor_must_be_volunteer(): void {
		$act = array( 'name' => 'Yoga', 'fee_cents' => 2000, 'instructor_person_id' => 3 );
		$this->assertSame( array(), Rules::validate_activity( $act, array( 'type' => 'volunteer' ) ) );
		$this->assertNotEmpty( Rules::validate_activity( $act, array( 'type' => 'ordinary' ) ) );
		$this->assertNotEmpty( Rules::validate_activity( $act, array( 'type' => 'founder' ) ) );
		$this->assertNotEmpty( Rules::validate_activity( $act, array( 'type' => 'guest' ) ) );
		$this->assertSame( array(), Rules::validate_activity( array( 'name' => 'Yoga', 'fee_cents' => 0 ), null ) );
	}
}

final class PeopleCsvTest extends TestCase {
	private function rows( string $csv ): array {
		$p = PeopleCsv::parse( $csv );
		$this->assertArrayHasKey( 'rows', $p, $p['error'] ?? '' );
		return $p['rows'];
	}

	public function test_reads_any_column_order(): void {
		$r = $this->rows( "Cognome;Nome;N. Tessera;Email;Tipo\nRossi;Mario;12;m@x.it;volontario\nBianchi;Anna;;a@x.it;\n" );
		$this->assertCount( 2, $r );
		$this->assertSame( 'Mario', $r[0]['first'] );
		$this->assertSame( '12', $r[0]['card'] );
		$this->assertSame( 'volontario', $r[0]['type_text'] );
		$this->assertNull( $r[1]['card'] );
		$this->assertNull( $r[1]['type_text'] );
	}

	public function test_excel_artifacts_and_quotes(): void {
		$r = $this->rows( "tessera,nome,cognome,email\n123.0,\"Anna, Maria\",\"De \"\"Luca\"\"\",a@b.it" );
		$this->assertSame( '123', $r[0]['card'] );
		$this->assertSame( 'Anna, Maria', $r[0]['first'] );
		$this->assertSame( 'De "Luca"', $r[0]['last'] );
	}

	public function test_missing_required_columns(): void {
		$this->assertArrayHasKey( 'error', PeopleCsv::parse( "Tessera;Nome\n1;Mario" ) );
		$this->assertArrayHasKey( 'error', PeopleCsv::parse( '' ) );
	}

	public function test_decode_windows_1252(): void {
		$bytes = mb_convert_encoding( "Nome;Cognome\nNiccolò;D'Amico", 'Windows-1252', 'UTF-8' );
		$this->assertStringContainsString( 'Niccolò', PeopleCsv::decode( $bytes ) );
		$this->assertSame( "a;b", PeopleCsv::decode( "\xEF\xBB\xBFa;b" ) );
	}

	private $existing = array(
		array( 'id' => 1, 'card' => '10', 'first' => 'Mario', 'last' => 'Rossi', 'email' => 'mario@x.it', 'tax' => 'RSSMRA80A01H501U' ),
		array( 'id' => 2, 'card' => null, 'first' => 'Anna', 'last' => 'Verdi', 'email' => 'anna@x.it', 'tax' => null ),
	);

	private function plan( string $csv, string $default = 'ordinary' ): array {
		return PeopleCsv::plan( $this->rows( $csv ), $this->existing, $default );
	}

	public function test_new_member_is_created_with_default_type(): void {
		$p = $this->plan( "Tessera;Nome;Cognome;Email\n11;Luca;Neri;luca@x.it", 'volunteer' );
		$this->assertSame( 'create', $p[0]['action'] );
		$this->assertSame( 'volunteer', $p[0]['type'] );
		$this->assertFalse( $p[0]['type_given'] );
	}

	public function test_email_is_mandatory(): void {
		$this->assertSame( 'error', $this->plan( "Nome;Cognome;Email\nLuca;Neri;" )[0]['action'] );
		$this->assertSame( 'error', $this->plan( "Nome;Cognome;Email\nLuca;Neri;nonvalida" )[0]['action'] );
	}

	public function test_updates_existing_by_card_email_tax_or_name(): void {
		$p = $this->plan( "Tessera;Nome;Cognome;Email\n10;Mario;Rossi;mario@x.it" );
		$this->assertSame( 'update', $p[0]['action'] );
		$this->assertSame( 1, $p[0]['matched_id'] );
		$p = $this->plan( "Nome;Cognome;Email\nAnnetta;Verdi;anna@x.it" ); // per email
		$this->assertSame( 2, $p[0]['matched_id'] );
		$p = $this->plan( "Nome;Cognome;Email\nAnna;Verdi;altra@x.it" ); // per nome e cognome
		$this->assertSame( 2, $p[0]['matched_id'] );
	}

	public function test_card_of_another_person_is_rejected(): void {
		$this->assertSame( 'error', $this->plan( "Tessera;Nome;Cognome;Email\n10;Luca;Neri;luca@x.it" )[0]['action'] );
	}

	public function test_duplicates_inside_the_file_are_rejected(): void {
		$p = $this->plan( "Tessera;Nome;Cognome;Email\n20;Luca;Neri;l@x.it\n20;Paola;Gialli;p@x.it\n21;Carlo;Blu;l@x.it" );
		$this->assertSame( 'create', $p[0]['action'] );
		$this->assertSame( 'error', $p[1]['action'] );
		$this->assertSame( 'error', $p[2]['action'] );
	}

	public function test_guests_and_unknown_types_are_rejected(): void {
		$this->assertSame( 'error', $this->plan( "Nome;Cognome;Email;Tipo\nLuca;Neri;l@x.it;ospite" )[0]['action'] );
		$this->assertSame( 'error', $this->plan( "Nome;Cognome;Email;Tipo\nLuca;Neri;l@x.it;boh" )[0]['action'] );
	}

	public function test_conflicting_identifiers_are_rejected(): void {
		$p = $this->plan( "Tessera;Nome;Cognome;Email\n10;Mario;Rossi;anna@x.it" ); // tessera di Mario, email di Anna
		$this->assertSame( 'error', $p[0]['action'] );
	}
}
