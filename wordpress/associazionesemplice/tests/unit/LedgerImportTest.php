<?php
use AssociazioneSemplice\LedgerImport as L;
use PHPUnit\Framework\TestCase;

final class LedgerImportTest extends TestCase {

	public function test_amounts(): void {
		$this->assertSame( 123456, L::parse_amount( '1.234,56' ) );
		$this->assertSame( 123456, L::parse_amount( '1,234.56' ) );
		$this->assertSame( 1250, L::parse_amount( '12,5' ) );
		$this->assertSame( 123450, L::parse_amount( '1234.5' ) );
		$this->assertSame( -1250, L::parse_amount( '(12,50)' ) );
		$this->assertSame( -1250, L::parse_amount( '12,50-' ) );
		$this->assertSame( -1250, L::parse_amount( '-12,50' ) );
		$this->assertSame( 1000, L::parse_amount( '€ 10' ) );
		$this->assertSame( 1000, L::parse_amount( "10,00\xC2\xA0€" ) );
		$this->assertSame( 0, L::parse_amount( '0' ) );
		$this->assertNull( L::parse_amount( 'abc' ) );
		$this->assertNull( L::parse_amount( '' ) );
	}

	public function test_dates_and_months(): void {
		$this->assertSame( '2024-01-15', L::parse_date( '2024-01-15' ) );
		$this->assertSame( '2024-01-15', L::parse_date( '15/01/2024' ) );
		$this->assertSame( '2024-01-05', L::parse_date( '5-1-2024' ) );
		$this->assertSame( '2024-01-15', L::parse_date( '15.01.24' ) );
		$this->assertSame( '2024-01-15', L::parse_date( '2024-01-15 10:30:00' ) );
		$this->assertNull( L::parse_date( '31/02/2024' ) );
		$this->assertNull( L::parse_date( '2024-13-01' ) );
		$this->assertNull( L::parse_date( 'ieri' ) );
		$this->assertSame( '2024-03', L::parse_month( '2024-03' ) );
		$this->assertSame( '2024-03', L::parse_month( '03/2024' ) );
		$this->assertSame( '2024-03', L::parse_month( 'Marzo 2024' ) );
		$this->assertSame( '2024-03', L::parse_month( '2024-03-15' ) );
		$this->assertNull( L::parse_month( 'boh' ) );
	}

	public function test_type_words(): void {
		$this->assertSame( 'income', L::type_from_text( 'entrata' ) );
		$this->assertSame( 'income', L::type_from_text( 'incasso' ) );
		$this->assertSame( 'expense', L::type_from_text( 'uscita' ) );
		$this->assertSame( 'expense', L::type_from_text( 'spesa' ) );
		$this->assertSame( 'transfer_in', L::type_from_text( 'girocontoinentrata' ) );
		$this->assertSame( 'transfer_out', L::type_from_text( 'girocontoinuscita' ) );
		$this->assertNull( L::type_from_text( 'boh' ) );
	}

	public function test_methods_and_account_guess(): void {
		$this->assertSame( 'cash', L::method_from_text( 'contanti' ) );
		$this->assertSame( 'bank_transfer', L::method_from_text( 'bonifico' ) );
		$this->assertSame( 'pos', L::method_from_text( 'poscarta' ) );
		$this->assertSame( 'stripe', L::method_from_text( 'cartaonlinestripe' ) );
		$this->assertSame( 'check', L::method_from_text( 'assegno' ) );
		$this->assertNull( L::method_from_text( 'piccioneviaggiatore' ) );
		$this->assertSame( 'cash', L::guess_account_type( 'cassacontanti' ) );
		$this->assertSame( 'bank', L::guess_account_type( 'bancaetica' ) );
		$this->assertSame( 'pos', L::guess_account_type( 'contopos' ) );
	}

	public function test_reads_with_title_rows_and_real_line_numbers(): void {
		$t = array(
			array( 'Rendiconto 2023' ),
			array( 'Data', 'Entrata', 'Uscita', 'Descrizione' ),
			array( '15/01/2024', '10,00', '', 'Quota' ),
			array( '16/01/2024', '', '45,50', 'Affitto' ),
			array( 'boh', '1', '', 'data sbagliata' ),
			array( '17/01/2024', '1,0', '2,0', 'entrambi' ),
		);
		$r = L::parse_table( $t, array( 1, 2, 5, 6, 7, 8 ) );
		$this->assertCount( 4, $r['rows'] );
		$this->assertSame( array( 5, 6, 7, 8 ), array_column( $r['rows'], 'line' ) );
		$this->assertSame( 'income', $r['rows'][0]['type'] );
		$this->assertSame( 1000, $r['rows'][0]['cents'] );
		$this->assertSame( 'expense', $r['rows'][1]['type'] );
		$this->assertSame( 4550, $r['rows'][1]['cents'] );
		$this->assertNotEmpty( $r['rows'][2]['errors'], 'data non valida' );
		$this->assertNotEmpty( $r['rows'][3]['errors'], 'entrata e uscita insieme' );
	}

	public function test_single_amount_column_signed_or_typed(): void {
		$t = array(
			array( 'Data', 'Importo', 'Tipo' ),
			array( '2024-01-01', '-20,00', '' ),
			array( '2024-01-02', '30,00', '' ),
			array( '2024-01-03', '15,00', 'Uscita' ),
			array( '2024-01-04', '', '' ),
		);
		$r = L::parse_table( $t );
		$this->assertSame( array( 'expense', 'income', 'expense' ), array_slice( array_column( $r['rows'], 'type' ), 0, 3 ) );
		$this->assertSame( array( 2000, 3000, 1500 ), array_slice( array_column( $r['rows'], 'cents' ), 0, 3 ) );
		$this->assertNotEmpty( $r['rows'][3]['errors'], 'importo mancante' );
	}

	public function test_header_detection(): void {
		$this->assertTrue( L::accepts_header( array( 'data', 'importo' ) ) );
		$this->assertTrue( L::accepts_header( array( 'data', 'tipo', 'conto', 'modalita', 'voce', 'attivita', 'ntessera', 'persona', 'descrizione', 'competenza', 'riferimento', 'entrata', 'uscita' ) ), 'il file esportato dal plugin' );
		$this->assertFalse( L::accepts_header( array( 'nome', 'cognome', 'email' ) ) );
		$this->assertFalse( L::accepts_header( array( 'data', 'descrizione' ) ) );
	}

	private function ctx( array $over = array() ): array {
		return array_merge(
			array(
				'accounts'           => array( array( 'id' => 1, 'name' => 'Cassa contanti', 'type' => 'cash' ), array( 'id' => 2, 'name' => 'Conto corrente', 'type' => 'bank' ) ),
				'categories'         => array(
					array( 'id' => 10, 'name' => 'Quota associativa', 'kind' => 'membership' ), array( 'id' => 11, 'name' => 'Quota attività / corso', 'kind' => 'activity_fee' ),
					array( 'id' => 12, 'name' => 'Altra entrata', 'kind' => 'other_income' ), array( 'id' => 13, 'name' => 'Costo generale', 'kind' => 'general_cost' ),
					array( 'id' => 14, 'name' => 'Rettifica', 'kind' => 'adjustment' ),
				),
				'people'             => array( array( 'id' => 7, 'card' => '5', 'first' => 'Mario', 'last' => 'Rossi' ), array( 'id' => 8, 'card' => '6', 'first' => 'Anna', 'last' => 'Rossi' ) ),
				'activities'         => array( array( 'id' => 3, 'name' => 'Yoga' ) ),
				'existing'           => array(),
				'default_account_id' => 0,
			),
			$over
		);
	}

	private function plan( array $rows, array $ctx = array() ): array {
		$h = array( 'Data', 'Tipo', 'Conto', 'Modalità', 'Voce', 'Importo', 'Descrizione', 'Riferimento', 'N. tessera', 'Persona', 'Attività', 'Competenza' );
		return L::plan( L::parse_table( array_merge( array( $h ), $rows ) )['rows'], $this->ctx( $ctx ) );
	}

	public function test_plan_resolves_account_category_person_activity(): void {
		$p = $this->plan(
			array(
				array( '15/01/2024', 'Entrata', 'cassa CONTANTI', 'Contanti', 'quota associativa', '10,00', 'Quota', '', '5', '', '', '' ),
				array( '16/01/2024', 'Entrata', 'Conto corrente', '', 'Corso di yoga', '30', '', '', '', 'Rossi Anna', 'yoga', 'gennaio 2024' ),
			)
		);
		$a = $p[0];
		$this->assertSame( 'create', $a['action'] );
		$this->assertSame( 1, $a['data']['account_id'] );
		$this->assertSame( 10, $a['data']['category_id'] );
		$this->assertSame( 7, $a['data']['person_id'] );
		$this->assertSame( 'cash', $a['data']['method'] );
		$b = $p[1];
		$this->assertSame( 2, $b['data']['account_id'] );
		$this->assertSame( 11, $b['data']['category_id'], 'parola chiave "corso"' );
		$this->assertSame( 8, $b['data']['person_id'], 'nome e cognome in ordine inverso' );
		$this->assertSame( 3, $b['data']['activity_id'] );
		$this->assertSame( '2024-01', $b['data']['month'] );
		$this->assertSame( 'bank_transfer', $b['data']['method'], 'modalità dedotta dal tipo di conto' );
	}

	public function test_plan_fallbacks_new_accounts_and_errors(): void {
		$p = $this->plan(
			array(
				array( '16/01/2024', 'Uscita', 'Banca Etica', '', 'Pulizie', '45,50', 'Sala', 'FT-1', '', '', '', '' ),
				array( '17/01/2024', 'Uscita', '', '', '', '5', '', '', '', '', '', '' ),
				array( '18/01/2024', 'Entrata', 'Cassa contanti', '', '', '5', '', '', '99', 'Sconosciuto', 'Danza', 'forse' ),
			)
		);
		$this->assertSame( 'create', $p[0]['action'] );
		$this->assertSame( 'Banca Etica', $p[0]['data']['new_account'] );
		$this->assertSame( 'bank', $p[0]['data']['new_account_type'] );
		$this->assertSame( 13, $p[0]['data']['category_id'] );
		$this->assertSame( '[Pulizie] Sala', $p[0]['data']['description'] );
		$this->assertNotEmpty( $p[0]['warnings'] );
		$this->assertSame( 'error', $p[1]['action'], 'conto mancante senza conto predefinito' );
		$this->assertSame( 'create', $p[2]['action'] );
		$this->assertSame( 12, $p[2]['data']['category_id'] );
		$this->assertCount( 5, $p[2]['warnings'], 'senza voce, tessera, persona, attività e competenza non riconosciute' );
		$d = $this->plan( array( array( '17/01/2024', 'Uscita', '', '', '', '5', '', '', '', '', '', '' ) ), array( 'default_account_id' => 1 ) );
		$this->assertSame( 1, $d[0]['data']['account_id'] );
		$this->assertSame( 'cash', $d[0]['data']['method'] );
	}

	public function test_plan_duplicates_are_counted_not_blindly_skipped(): void {
		$key = L::key( '2024-01-15', '1', 'income', 1000, 'Quota', '' );
		$row = array( '15/01/2024', 'Entrata', 'Cassa contanti', '', 'Quota associativa', '10,00', 'Quota', '', '', '', '', '' );
		$p   = $this->plan( array( $row, $row, $row ), array( 'existing' => array( $key => 2 ) ) );
		$this->assertSame( array( 'duplicate', 'duplicate', 'create' ), array_column( $p, 'action' ), 'due già presenti: i primi due sono doppioni, il terzo è nuovo' );
	}

	public function test_plan_pairs_transfers(): void {
		$p = $this->plan(
			array(
				array( '20/01/2024', 'Giroconto in uscita', 'Cassa contanti', 'Contanti', 'Giroconto', '100', 'Versamento', '', '', '', '', '' ),
				array( '20/01/2024', 'Giroconto in entrata', 'Conto corrente', 'Contanti', 'Giroconto', '100', 'Versamento', '', '', '', '', '' ),
				array( '21/01/2024', 'Giroconto in uscita', 'Cassa contanti', 'Contanti', 'Giroconto', '50', '', '', '', '', '', '' ),
			)
		);
		$this->assertSame( array( 'transfer', 'paired', 'skip' ), array_column( $p, 'action' ) );
		$this->assertSame( 1, $p[0]['data']['from']['id'] );
		$this->assertSame( 2, $p[0]['data']['to']['id'] );
		$this->assertSame( 10000, $p[0]['data']['cents'] );
		$s = L::summary( $p, array( 1 => 'Cassa contanti', 2 => 'Conto corrente' ) );
		$this->assertSame( array( 'Cassa contanti' => -10000, 'Conto corrente' => 10000 ), $s['per_account'] );
		$this->assertSame( 1, $s['counts']['transfer'] );
		$this->assertSame( 1, $s['counts']['skip'] );
	}

	public function test_member_from_the_same_file_is_linked_later_without_a_warning(): void {
		$ctx = array( 'pending_people' => array( array( 'card' => '900', 'first' => 'Ida', 'last' => 'Storica' ) ) );
		$p   = $this->plan(
			array(
				array( '15/01/2024', 'Entrata', 'Cassa contanti', '', 'Quota associativa', '10,00', '', '', '900', '', '', '' ),
				array( '16/01/2024', 'Entrata', 'Cassa contanti', '', 'Quota associativa', '10,00', '', '', '', 'Storica Ida', '', '' ),
				array( '17/01/2024', 'Entrata', 'Cassa contanti', '', 'Quota associativa', '10,00', '', '', '777', '', '', '' ),
			),
			$ctx
		);
		$this->assertTrue( $p[0]['data']['person_late'] );
		$this->assertSame( array(), $p[0]['warnings'] );
		$this->assertTrue( $p[1]['data']['person_late'] );
		$this->assertFalse( $p[2]['data']['person_late'] );
		$this->assertNotEmpty( $p[2]['warnings'], 'una tessera che non è né nel database né nel file resta un avviso' );
	}

	public function test_summary_totals_and_range(): void {
		$p = $this->plan(
			array(
				array( '15/01/2024', 'Entrata', 'Cassa contanti', '', 'Quota associativa', '10,00', '', '', '', '', '', '' ),
				array( '16/02/2024', 'Uscita', 'Banca Etica', '', 'Pulizie', '4,50', '', '', '', '', '', '' ),
			)
		);
		$s = L::summary( $p, array( 1 => 'Cassa contanti' ) );
		$this->assertSame( 1000, $s['income'] );
		$this->assertSame( 450, $s['expense'] );
		$this->assertSame( '2024-01-15', $s['from'] );
		$this->assertSame( '2024-02-16', $s['to'] );
		$this->assertSame( array( 'Banca Etica' ), $s['new_accounts'] );
		$this->assertSame( -450, $s['per_account']['Banca Etica'] );
	}
}
