<?php
namespace ApSemplice\Admin;

use ApSemplice\AssocPolicies;
use ApSemplice\Attendance;
use ApSemplice\Db;
use ApSemplice\Docs;
use ApSemplice\Insurance;
use ApSemplice\MemberBook;
use ApSemplice\Minutes;
use ApSemplice\Money;
use ApSemplice\Plugin;
use ApSemplice\Statement;

defined( 'ABSPATH' ) || exit;

/** Registri: libro soci, verbali, volontari e assicurazione, presenze; e il rendiconto per cassa. */
final class RegistersPage {

	private static function date( ?string $ymd ): string {
		return $ymd ? esc_html( ( new \DateTimeImmutable( $ymd ) )->format( 'd/m/Y' ) ) : '—';
	}

	private static function downloads( string $what, array $args ): string {
		return '<a class="button" target="_blank" href="' . esc_url( Docs::url( $what, $args ) ) . '">PDF</a> <a class="button" href="' . esc_url( Docs::url( $what, $args, 'csv' ) ) . '">CSV</a>';
	}

	// ---------- Libro soci ----------

	public static function render_book(): void {
		$filter = Ui::get_str( 'filter' );
		$filter = in_array( $filter, array( MemberBook::IN_FORCE, MemberBook::LEFT ), true ) ? $filter : '';
		Ui::header( 'Libro soci' );
		$rows = MemberBook::rows( $filter );
		echo '<p class="description">L\'elenco progressivo dei soci con data di ingresso e, se c\'è stata, di cessazione (recesso, esclusione, decesso). Gli ospiti non sono soci e non compaiono. '
			. 'La cessazione si registra dalla scheda del socio.</p>';
		echo '<form method="get" class="apse-filters"><input type="hidden" name="page" value="apse-book"><select name="filter">'
			. Ui::options( array( MemberBook::IN_FORCE => 'In carica', MemberBook::LEFT => 'Cessati' ), $filter, 'Tutti' ) . '</select> <button class="button">Filtra</button> '
			. self::downloads( 'book', array( 'filter' => $filter ) ) . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">' . count( $rows ) . ' soci.</p>';
		echo '<table class="widefat striped"><thead><tr><th>N.</th><th>Cognome e nome</th><th>Codice fiscale</th><th>Livello</th><th>Ingresso</th><th>Cessazione</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">Nessun socio.</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td>' . (int) $r['n'] . '</td><td><a href="' . esc_url( Ui::url( 'apse-person', array( 'id' => $r['id'] ) ) ) . '">' . esc_html( $r['name'] ) . '</a></td><td>' . esc_html( $r['tax_code'] ) . '</td><td>' . esc_html( $r['level'] ) . '</td><td>' . self::date( $r['joined_on'] ) // phpcs:ignore WordPress.Security.EscapeOutput
				. '</td><td>' . ( $r['left_on'] ? self::date( $r['left_on'] ) . ( '' !== $r['left_reason'] ? ' · ' . esc_html( $r['left_reason'] ) : '' ) : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	/** Riquadro nella scheda del socio: registra la cessazione. */
	public static function panel_left( array $p ): void {
		if ( ! \ApSemplice\MemberType::is_member( $p['type'] ) ) {
			return;
		}
		echo '<div class="apse-card"><h2>Libro soci</h2>';
		Ui::form_open( 'apse_member_left', Ui::url( 'apse-person', array( 'id' => (int) $p['id'] ) ) );
		echo Ui::hidden( 'id', $p['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Ingresso: <strong>' . self::date( (string) $p['joined_on'] ) . '</strong></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Cessazione <input type="date" name="left_on" value="' . esc_attr( (string) $p['left_on'] ) . '"> motivo <input type="text" name="left_reason" value="' . esc_attr( (string) $p['left_reason'] ) . '" placeholder="recesso, esclusione, decesso…" maxlength="190"> <button class="button">Salva</button></p>';
		Ui::form_close();
		echo '<p class="description">Lascia vuota la data per tenere il socio in carica. Il socio cessato resta nel libro soci con le sue date.</p></div>';
	}

	// ---------- Verbali ----------

	public static function render_minutes(): void {
		$view = Ui::get_int( 'view' );
		if ( $view || '1' === Ui::get_str( 'new' ) ) {
			self::minute_form( $view );
			return;
		}
		$kind = Ui::get_str( 'kind' );
		$year = Ui::get_int( 'year' );
		Ui::header( 'Verbali', '<a class="page-title-action" href="' . esc_url( Ui::url( 'apse-minutes', array( 'new' => 1 ) ) ) . '">Nuovo verbale</a>' );
		echo '<p class="description">Il libro dei verbali delle assemblee dei soci e delle riunioni del consiglio direttivo, numerati per tipo e anno. Da ogni verbale si stampa il PDF da firmare.</p>';
		$years = array();
		for ( $y = (int) substr( Db::today(), 0, 4 ); $y >= (int) substr( Db::today(), 0, 4 ) - 10; $y-- ) {
			$years[ $y ] = (string) $y;
		}
		echo '<form method="get" class="apse-filters"><input type="hidden" name="page" value="apse-minutes"><select name="kind">' . Ui::options( Minutes::kinds(), $kind, 'Tutti i tipi' )
			. '</select> <select name="year">' . Ui::options( $years, $year ?: null, 'Tutti gli anni' ) . '</select> <button class="button">Filtra</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$rows  = Minutes::all( $kind ?: null, $year ?: null );
		$kinds = Minutes::kinds();
		echo '<table class="widefat striped"><thead><tr><th>N.</th><th>Data</th><th>Tipo</th><th>Titolo</th><th>Approvato</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">Nessun verbale.</td></tr>';
		}
		foreach ( $rows as $m ) {
			echo '<tr><td>' . esc_html( $m['number'] ) . '</td><td>' . self::date( $m['meeting_date'] ) . '</td><td>' . esc_html( $kinds[ $m['kind'] ] ) // phpcs:ignore WordPress.Security.EscapeOutput
				. '</td><td><a href="' . esc_url( Ui::url( 'apse-minutes', array( 'view' => (int) $m['id'] ) ) ) . '">' . esc_html( $m['title'] ) . '</a></td><td>' . ( $m['approved_on'] ? self::date( $m['approved_on'] ) : '—' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	private static function minute_form( int $id ): void {
		$m = $id ? Minutes::get( $id ) : null;
		Ui::header( $m ? 'Verbale ' . $m['number'] : 'Nuovo verbale', '<a class="page-title-action" href="' . esc_url( Ui::url( 'apse-minutes' ) ) . '">← Tutti i verbali</a>' );
		if ( $id && ! $m ) {
			echo '<p>Verbale non trovato.</p>';
			Ui::footer();
			return;
		}
		$v = function ( string $k, string $default = '' ) use ( $m ) {
			return $m ? (string) ( $m[ $k ] ?? '' ) : $default;
		};
		if ( $m ) {
			echo '<p><a class="button" target="_blank" href="' . esc_url( Docs::url( 'minute', array( 'id' => $id ) ) ) . '">Stampa il PDF</a></p>';
		}
		Ui::form_open( 'apse_minute_save', Ui::url( 'apse-minutes', $m ? array( 'view' => $id ) : array( 'new' => 1 ) ) );
		echo Ui::hidden( 'id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table apse-form"><tbody>';
		echo '<tr><th>Tipo *</th><td><select name="kind">' . Ui::options( Minutes::kinds(), $v( 'kind', Minutes::ASSEMBLY ) ) . '</select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Data *</th><td><input type="date" name="meeting_date" value="' . esc_attr( $v( 'meeting_date', Db::today() ) ) . '" required></td></tr>';
		echo '<tr><th>Luogo</th><td><input type="text" name="place" value="' . esc_attr( $v( 'place' ) ) . '" class="regular-text" maxlength="190"></td></tr>';
		echo '<tr><th>Titolo *</th><td><input type="text" name="title" value="' . esc_attr( $v( 'title' ) ) . '" class="large-text" maxlength="190" required></td></tr>';
		echo '<tr><th>Presenti</th><td><textarea name="attendees" rows="4" class="large-text">' . esc_textarea( $v( 'attendees' ) ) . '</textarea><p class="description">Nomi dei presenti, deleghe, numero dei soci presenti.</p></td></tr>';
		echo '<tr><th>Ordine del giorno</th><td><textarea name="agenda" rows="4" class="large-text">' . esc_textarea( $v( 'agenda' ) ) . '</textarea></td></tr>';
		echo '<tr><th>Svolgimento e deliberazioni *</th><td><textarea name="body" rows="16" class="large-text" required>' . esc_textarea( $v( 'body' ) ) . '</textarea></td></tr>';
		echo '<tr><th>Approvato il</th><td><input type="date" name="approved_on" value="' . esc_attr( $v( 'approved_on' ) ) . '"><p class="description">La data in cui il verbale è stato letto e approvato (di solito alla riunione successiva).</p></td></tr>';
		echo '</tbody></table>';
		submit_button( $m ? 'Salva' : 'Crea il verbale' );
		Ui::form_close();
		if ( $m ) {
			Ui::form_open( 'apse_minute_delete', Ui::url( 'apse-minutes' ), false, 'apse-confirm' );
			echo Ui::hidden( 'id', $id ) . '<button class="button button-link-delete" data-confirm="Eliminare questo verbale?">Elimina il verbale</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
		}
		Ui::footer();
	}

	// ---------- Volontari e assicurazione ----------

	public static function render_volunteers(): void {
		Ui::header( 'Assicurazioni' );
		$assoc = \ApSemplice\Settings::insurance_association();
		$vols  = \ApSemplice\Settings::insurance_volunteers();
		if ( ! $assoc && ! $vols ) {
			echo '<p>Le assicurazioni sono spente. Attivale da <a href="' . esc_url( Ui::url( 'apse-settings' ) ) . '">Impostazioni</a> (voce «Assicurazioni»): polizze dell\'associazione e registro delle assicurazioni dei volontari.</p>';
			Ui::footer();
			return;
		}
		if ( $assoc ) {
			self::policies_section();
		}
		if ( $vols ) {
			self::volunteers_section();
		}
		Ui::footer();
	}

	/** Polizze dell'associazione: responsabilità civile, infortuni dei soci, altre coperture. */
	private static function policies_section(): void {
		$statuses = AssocPolicies::statuses();
		$labels   = Insurance::status_labels();
		$kinds    = AssocPolicies::kinds();
		$cls      = array( Insurance::VALID => '', Insurance::EXPIRING => 'apse-warn', Insurance::EXPIRED => 'apse-neg', Insurance::NONE => 'apse-neg' );
		echo '<h2>Polizze dell\'associazione</h2><p class="description">La polizza generale dell\'associazione, a copertura dei soci e della responsabilità civile verso terzi per le attività svolte. '
			. 'Registra compagnia, numero, periodo di copertura, premio e massimale: viene segnalata in Bacheca se la responsabilità civile è scoperta o in scadenza entro ' . (int) Insurance::SOON . ' giorni.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Copertura</th><th>Polizza</th><th>Fino al</th><th>Stato</th></tr></thead><tbody>';
		foreach ( $statuses as $k => $st ) {
			$pol = $st['policy'];
			echo '<tr><td>' . esc_html( $kinds[ $k ] ) . '</td><td>' . ( $pol ? esc_html( $pol['company'] . ( '' !== (string) $pol['policy_no'] ? ' · n. ' . $pol['policy_no'] : '' ) . ( '' !== (string) $pol['coverage'] ? ' · ' . $pol['coverage'] : '' ) ) : '—' )
				. '</td><td>' . ( $pol ? self::date( $pol['valid_to'] ) : '—' ) . '</td><td><span class="' . esc_attr( $cls[ $st['status'] ] ) . '">' . esc_html( $labels[ $st['status'] ] ) . '</span></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		Ui::form_open( 'apse_policy_add', Ui::url( 'apse-volunteers' ) );
		echo '<h3>Registra una polizza dell\'associazione</h3><p><select name="kind">' . Ui::options( $kinds, AssocPolicies::RC ) . '</select> <input type="text" name="company" placeholder="Compagnia" required> <input type="text" name="policy_no" placeholder="N. polizza" size="14"> '
			. 'dal <input type="date" name="valid_from" value="' . esc_attr( Db::today() ) . '" required> al <input type="date" name="valid_to" required></p>'
			. '<p><input type="text" name="premium" placeholder="Premio € (facoltativo)" size="18" inputmode="decimal"> <input type="text" name="coverage" placeholder="Massimale / descrizione (facoltativo)" class="regular-text" maxlength="255"> <button class="button button-primary">Registra la polizza</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_close();
		$all = AssocPolicies::all();
		if ( $all ) {
			echo '<details><summary>Tutte le polizze registrate (' . count( $all ) . ')</summary><table class="widefat striped"><thead><tr><th>Tipo</th><th>Compagnia</th><th>N.</th><th>Periodo</th><th>Premio</th><th>Massimale</th><th></th></tr></thead><tbody>';
			foreach ( $all as $x ) {
				echo '<tr><td>' . esc_html( $kinds[ $x['kind'] ] ?? $x['kind'] ) . '</td><td>' . esc_html( $x['company'] ) . '</td><td>' . esc_html( (string) $x['policy_no'] ) . '</td><td>' . self::date( $x['valid_from'] ) . ' – ' . self::date( $x['valid_to'] ) // phpcs:ignore WordPress.Security.EscapeOutput
					. '</td><td>' . ( null === $x['premium_cents'] ? '—' : esc_html( Money::format( (int) $x['premium_cents'] ) ) ) . '</td><td>' . esc_html( (string) $x['coverage'] ) . '</td><td>';
				Ui::form_open( 'apse_policy_delete', Ui::url( 'apse-volunteers' ), false, 'apse-inline' );
				echo Ui::hidden( 'id', $x['id'] ) . '<button class="button-link-delete" data-confirm="Eliminare questa polizza?">elimina</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::form_close();
				echo '</td></tr>';
			}
			echo '</tbody></table></details>';
		}
	}

	private static function volunteers_section(): void {
		echo '<h2>Volontari</h2>';
		$rows   = Insurance::register();
		$labels = Insurance::status_labels();
		$cls    = array( Insurance::VALID => '', Insurance::EXPIRING => 'apse-warn', Insurance::EXPIRED => 'apse-neg', Insurance::NONE => 'apse-neg' );
		echo '<p class="description">I soci volontari con la loro assicurazione: chi svolge attività in modo continuativo deve essere coperto. '
			. 'Registra qui la polizza (compagnia, numero e periodo di copertura): il registro segnala chi è senza copertura o in scadenza entro ' . (int) Insurance::SOON . ' giorni.</p>';
		echo '<p>' . self::downloads( 'volunteers', array() ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="widefat striped"><thead><tr><th>Volontario</th><th>Polizza</th><th>Copertura fino al</th><th>Stato</th><th>Registra una polizza</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">Nessun volontario.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$p   = $r['person'];
			$pol = $r['policy'];
			echo '<tr><td><a href="' . esc_url( Ui::url( 'apse-person', array( 'id' => (int) $p['id'] ) ) ) . '">' . esc_html( trim( $p['last_name'] . ' ' . $p['first_name'] ) ) . '</a></td>'
				. '<td>' . ( $pol ? esc_html( $pol['company'] . ( '' !== (string) $pol['policy_no'] ? ' · n. ' . $pol['policy_no'] : '' ) ) : '—' ) . '</td>'
				. '<td>' . ( $pol ? self::date( $pol['valid_to'] ) : '—' ) . '</td><td><span class="' . esc_attr( $cls[ $r['status'] ] ) . '">' . esc_html( $labels[ $r['status'] ] ) . '</span></td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_open( 'apse_insurance_add', Ui::url( 'apse-volunteers' ), false, 'apse-inline' );
			echo Ui::hidden( 'person_id', $p['id'] ) . '<input type="text" name="company" placeholder="Compagnia" size="14" required> <input type="text" name="policy_no" placeholder="N. polizza" size="10"> dal <input type="date" name="valid_from" value="' . esc_attr( Db::today() ) . '" required> al <input type="date" name="valid_to" required> <button class="button">Aggiungi</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
			$all = Insurance::for_person( (int) $p['id'] );
			if ( count( $all ) > 0 ) {
				echo '<details><summary class="description">Polizze registrate (' . count( $all ) . ')</summary><ul>';
				foreach ( $all as $x ) {
					echo '<li>' . esc_html( $x['company'] . ( '' !== (string) $x['policy_no'] ? ' n. ' . $x['policy_no'] : '' ) ) . ' · ' . self::date( $x['valid_from'] ) . ' – ' . self::date( $x['valid_to'] ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
					Ui::form_open( 'apse_insurance_delete', Ui::url( 'apse-volunteers' ), false, 'apse-inline' );
					echo Ui::hidden( 'id', $x['id'] ) . '<button class="button-link-delete" data-confirm="Eliminare questa polizza?">elimina</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
					Ui::form_close();
					echo '</li>';
				}
				echo '</ul></details>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	// ---------- Presenze ----------

	public static function render_attendance(): void {
		$aid   = Ui::get_int( 'activity' );
		$today = Db::today();
		$ym    = preg_match( '/^\d{4}-\d{2}$/', Ui::get_str( 'ym' ) ) ? Ui::get_str( 'ym' ) : substr( $today, 0, 7 );
		$date  = Ui::get_str( 'date' );
		Ui::header( 'Registro presenze' );
		$acts = array();
		foreach ( Plugin::activities()->all_for_select() as $a ) {
			$acts[ $a['id'] ] = $a['name'] . ' (' . $a['social_year'] . ')';
		}
		echo '<p class="description">Per ogni lezione di un corso (o data di un evento) segna chi era presente. Il registro si compila a lezione fatta e si stampa per periodo.</p>';
		echo '<form method="get" class="apse-filters"><input type="hidden" name="page" value="apse-attendance"><select name="activity">' . Ui::options( $acts, $aid ?: null, '— scegli il corso o l\'evento —' ) . '</select> '
			. '<input type="month" name="ym" value="' . esc_attr( $ym ) . '"> <button class="button">Mostra le lezioni</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$a = $aid ? Plugin::activities()->get( $aid ) : null;
		if ( ! $a ) {
			Ui::footer();
			return;
		}
		$first = $ym . '-01';
		$last  = ( new \DateTimeImmutable( $first ) )->modify( 'last day of this month' )->format( 'Y-m-d' );
		$dates = Attendance::lesson_dates( $a, $first, $last );
		echo '<h2>' . esc_html( $a['name'] ) . ' — ' . esc_html( Ui::month( $ym ) ) . '</h2>';
		if ( ! $dates ) {
			echo '<p class="description">In questo mese non ci sono lezioni.</p>';
		} else {
			echo '<p>';
			foreach ( $dates as $d ) {
				$done = (bool) Attendance::marks( $aid, $d );
				echo '<a class="button' . ( $d === $date ? ' button-primary' : '' ) . '" href="' . esc_url( Ui::url( 'apse-attendance', array( 'activity' => $aid, 'ym' => $ym, 'date' => $d ) ) ) . '">' . esc_html( ( new \DateTimeImmutable( $d ) )->format( 'd/m' ) ) . ( $done ? ' ✓' : '' ) . '</a> ';
			}
			echo '</p>';
		}
		if ( $date && in_array( $date, $dates, true ) ) {
			$roster = Attendance::roster( $a, $date );
			$marks  = Attendance::marks( $aid, $date );
			echo '<div class="apse-card"><h3>Lezione del ' . self::date( $date ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $date > $today ) {
				echo '<p class="description">La lezione non si è ancora tenuta: le presenze si registrano a lezione fatta.</p>';
			} elseif ( ! $roster ) {
				echo '<p class="description">Nessun iscritto o prenotato per questa data.</p>';
			} else {
				Ui::form_open( 'apse_attendance_save', Ui::url( 'apse-attendance', array( 'activity' => $aid, 'ym' => $ym, 'date' => $date ) ) );
				echo Ui::hidden( 'activity_id', $aid ) . Ui::hidden( 'date', $date ) . '<ul>'; // phpcs:ignore WordPress.Security.EscapeOutput
				foreach ( $roster as $r ) {
					$on = $marks ? ! empty( $marks[ (int) $r['id'] ] ) : true; // prima registrazione: tutti presenti, si tolgono gli assenti
					echo '<li><label><input type="checkbox" name="present[]" value="' . (int) $r['id'] . '"' . checked( $on, true, false ) . '> ' . esc_html( trim( $r['last_name'] . ' ' . $r['first_name'] ) ) . '</label></li>';
				}
				echo '</ul><p><button class="button button-primary">Registra le presenze</button></p>';
				Ui::form_close();
			}
			echo '</div>';
		}
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', Ui::get_str( 'from' ) ) ? Ui::get_str( 'from' ) : substr( $today, 0, 4 ) . '-01-01';
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', Ui::get_str( 'to' ) ) ? Ui::get_str( 'to' ) : $today;
		echo '<h2>Riepilogo del periodo</h2>';
		echo '<form method="get" class="apse-filters"><input type="hidden" name="page" value="apse-attendance"><input type="hidden" name="activity" value="' . (int) $aid . '"><input type="hidden" name="ym" value="' . esc_attr( $ym ) . '">'
			. 'dal <input type="date" name="from" value="' . esc_attr( $from ) . '"> al <input type="date" name="to" value="' . esc_attr( $to ) . '"> <button class="button">Aggiorna</button> '
			. self::downloads( 'attendance', array( 'activity' => $aid, 'from' => $from, 'to' => $to ) ) . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$s = Attendance::summary( $aid, $from, $to );
		echo '<p class="description">' . count( $s['dates'] ) . ' lezioni registrate.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Persona</th><th>Presenze</th><th>Lezioni</th><th>%</th></tr></thead><tbody>';
		if ( ! $s['people'] ) {
			echo '<tr><td colspan="4">Nessuna presenza registrata nel periodo.</td></tr>';
		}
		foreach ( $s['people'] as $p ) {
			echo '<tr><td>' . esc_html( trim( $p['last_name'] . ' ' . $p['first_name'] ) ) . '</td><td>' . (int) $p['presenze'] . '</td><td>' . (int) $p['totale'] . '</td><td>' . ( $p['totale'] ? (int) round( 100 * $p['presenze'] / $p['totale'] ) . '%' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	// ---------- Rendiconto ----------

	public static function render_statement(): void {
		if ( Admin::reports_off( 'Rendiconto per cassa' ) ) {
			return;
		}
		$years = Statement::years();
		$year  = Ui::get_int( 'year', $years[0] );
		$year  = in_array( $year, $years, true ) ? $year : $years[0];
		Ui::header( 'Rendiconto per cassa' );
		echo '<p class="description">Il rendiconto dell\'anno solare costruito dalla prima nota: entrate e uscite per area, confronto con l\'anno precedente, avanzo o disavanzo, cassa e conti. '
			. 'Le aree seguono la «voce di rendiconto» di ogni categoria. Stampa il PDF, aggiungi la relazione e fai firmare tesoriere e presidente; il commercialista può verificarlo prima dell\'approvazione.</p>';
		echo '<form method="get" class="apse-filters"><input type="hidden" name="page" value="apse-statement"><select name="year">' . Ui::options( array_combine( $years, $years ), $year ) . '</select> <button class="button">Mostra</button> '
			. '<a class="button button-primary" target="_blank" href="' . esc_url( Docs::url( 'statement', array( 'year' => $year ) ) ) . '">PDF del rendiconto</a></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$s    = Statement::data( $year );
		$show = function ( string $label, array $groups, array $total ) use ( $year ) {
			echo '<h2>' . esc_html( $label ) . '</h2><table class="widefat striped"><thead><tr><th></th><th style="text-align:right">' . (int) ( $year - 1 ) . '</th><th style="text-align:right">' . (int) $year . '</th></tr></thead><tbody>';
			foreach ( $groups as $g ) {
				echo '<tr><td><strong>' . esc_html( $g['group'] ) . '</strong></td><td style="text-align:right"><strong>' . Ui::money( $g['prev'] ) . '</strong></td><td style="text-align:right"><strong>' . Ui::money( $g['cur'] ) . '</strong></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
				foreach ( $g['lines'] as $l ) {
					echo '<tr><td style="padding-left:24px">' . esc_html( $l['name'] ) . '</td><td style="text-align:right">' . Ui::money( $l['prev'] ) . '</td><td style="text-align:right">' . Ui::money( $l['cur'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
			}
			echo '<tr><td><strong>Totale</strong></td><td style="text-align:right"><strong>' . Ui::money( $total['prev'] ) . '</strong></td><td style="text-align:right"><strong>' . Ui::money( $total['cur'] ) . '</strong></td></tr></tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		};
		$show( 'Entrate', $s['income'], $s['total_income'] );
		$show( 'Uscite', $s['expenses'], $s['total_expense'] );
		echo '<p><strong>' . ( $s['result']['cur'] >= 0 ? 'Avanzo' : 'Disavanzo' ) . ' di gestione ' . (int) $year . ':</strong> ' . Ui::money( $s['result']['cur'] ) . ' <span class="description">(' . (int) ( $year - 1 ) . ': ' . esc_html( Money::format( $s['result']['prev'] ) ) . ')</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<h2>Cassa e conti</h2><table class="widefat striped"><thead><tr><th></th><th style="text-align:right">Iniziale</th><th style="text-align:right">Finale</th></tr></thead><tbody>';
		foreach ( $s['accounts'] as $a ) {
			echo '<tr><td>' . esc_html( $a['name'] ) . '</td><td style="text-align:right">' . Ui::money( $a['opening'] ) . '</td><td style="text-align:right">' . Ui::money( $a['closing'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<tr><td><strong>Totale</strong></td><td style="text-align:right"><strong>' . Ui::money( $s['opening_total'] ) . '</strong></td><td style="text-align:right"><strong>' . Ui::money( $s['closing_total'] ) . '</strong></td></tr></tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<h2>Relazione sull\'andamento della gestione</h2>';
		Ui::form_open( 'apse_statement_notes', Ui::url( 'apse-statement', array( 'year' => $year ) ) );
		echo Ui::hidden( 'year', $year ) . '<p><textarea name="notes" rows="8" class="large-text" maxlength="' . (int) Statement::MAX_NOTES . '">' . esc_textarea( $s['notes'] ) . '</textarea></p><p class="description">Compare in fondo al PDF, prima delle firme.</p><p><button class="button button-primary">Salva la relazione</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::form_close();
		Ui::footer();
	}
}
