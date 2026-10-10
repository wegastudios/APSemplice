<?php
use AssociazioneSemplice\PeopleCsv;
use PHPUnit\Framework\TestCase;

final class PeopleGuestsImportTest extends TestCase {

	private function plan( array $rows, array $existing = array() ): array {
		$h = array( 'Tessera', 'Tipo', 'Nome', 'Cognome', 'Email', 'Ospite di', 'Cellulare' );
		foreach ( $rows as $i => $r ) { // gli ospiti hanno un cellulare, salvo che il test lo tolga di proposito (settima colonna presente)
			if ( ! array_key_exists( 6, $r ) ) {
				$rows[ $i ][6] = 'ospite' === $r[1] ? '333 1234567' : '';
			}
		}
		$t = PeopleCsv::parse_table( array_merge( array( $h ), $rows ) );
		return PeopleCsv::plan( $t['rows'], $existing );
	}

	private function members(): array {
		return array(
			array( 'id' => 7, 'card' => '1', 'first' => 'Mario', 'last' => 'Rossi', 'email' => 'mario@example.com', 'tax' => null, 'type' => 'ordinary', 'host_id' => 0 ),
			array( 'id' => 9, 'card' => null, 'first' => 'Gino', 'last' => 'Bianchi', 'email' => null, 'tax' => null, 'type' => 'guest', 'host_id' => 7 ),
		);
	}

	public function test_guest_linked_by_card_email_or_name(): void {
		$p = $this->plan(
			array(
				array( '', 'ospite', 'Anna', 'Verdi', '', '1' ),
				array( '', 'ospite', 'Luca', 'Neri', 'luca@example.com', 'mario@example.com' ),
				array( '', 'ospite', 'Paola', 'Gialli', '', 'Rossi Mario' ),
			),
			$this->members()
		);
		foreach ( $p as $x ) {
			$this->assertSame( 'create', $x['action'], (string) $x['message'] );
			$this->assertSame( 'guest', $x['type'] );
			$this->assertSame( 7, $x['host_id'] );
		}
	}

	public function test_guest_host_can_be_in_the_same_file(): void {
		$p = $this->plan(
			array(
				array( '5', 'ordinario', 'Elena', 'Blu', 'elena@example.com', '' ),
				array( '', 'ospite', 'Piero', 'Rosa', '', '5' ),
				array( '', 'ospite', 'Sara', 'Viola', '', 'elena@example.com' ),
			)
		);
		$this->assertSame( array( 'create', 'create', 'create' ), array_column( $p, 'action' ) );
		$this->assertNull( $p[1]['host_id'], 'ospitante nuovo: si risolve all\'applicazione' );
		$this->assertSame( '5', $p[1]['host_text'] );
	}

	public function test_guest_errors(): void {
		$p = $this->plan(
			array(
				array( '', 'ospite', 'Senza', 'Ospitante', '', '' ),
				array( '', 'ospite', 'Ospitante', 'Sconosciuto', '', '99' ),
				array( '', 'ospite', 'Email', 'Brutta', 'brutta', '1' ),
				array( '', 'ospite', 'Anna', 'Verdi', '', '1' ),
				array( '', 'ospite', 'Anna', 'Verdi', '', '1' ),
			),
			$this->members()
		);
		$this->assertSame( array( 'error', 'error', 'error', 'create', 'error' ), array_column( $p, 'action' ) );
		$this->assertStringContainsString( 'ospitante', $p[0]['message'] );
		$this->assertStringContainsString( 'non trovato', $p[1]['message'] );
		$this->assertStringContainsString( 'già', $p[4]['message'] );
	}

	public function test_guest_needs_a_valid_mobile_number(): void {
		$p = $this->plan(
			array(
				array( '', 'ospite', 'Senza', 'Cellulare', '', '1', '' ),
				array( '', 'ospite', 'Cellulare', 'Brutto', '', '1', '12345' ),
				array( '', 'ospite', 'Cellulare', 'Giusto', '', '1', '+39 333 123 4567' ),
			),
			$this->members()
		);
		$this->assertSame( array( 'error', 'error', 'create' ), array_column( $p, 'action' ) );
		$this->assertStringContainsString( 'ellulare', $p[0]['message'] );
	}

	public function test_existing_guest_is_updated_not_duplicated(): void {
		$p = $this->plan( array( array( '', 'ospite', 'Gino', 'Bianchi', '', '1' ) ), $this->members() );
		$this->assertSame( 'update', $p[0]['action'] );
		$this->assertSame( 9, $p[0]['matched_id'] );
	}

	public function test_a_guest_is_never_matched_as_a_member(): void {
		// Un socio con lo stesso nome di un ospite esistente non deve "diventare" quell'ospite.
		$p = $this->plan( array( array( '', 'ordinario', 'Gino', 'Bianchi', 'gino.b@example.com', '' ) ), $this->members() );
		$this->assertSame( 'create', $p[0]['action'] );
	}

	public function test_header_not_on_first_row(): void {
		$t = PeopleCsv::parse_table( array( array( 'Elenco soci 2023' ), array( 'Nome', 'Cognome', 'Email' ), array( 'Mario', 'Rossi', 'm@example.com' ) ), array( 1, 3, 4 ) );
		$this->assertCount( 1, $t['rows'] );
		$this->assertSame( 4, $t['rows'][0]['line'] );
		$this->assertTrue( PeopleCsv::accepts_header( array( 'nome', 'cognome' ) ) );
		$this->assertFalse( PeopleCsv::accepts_header( array( 'nome' ) ) );
	}
}
