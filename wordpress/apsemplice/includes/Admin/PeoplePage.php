<?php
namespace ApSemplice\Admin;

use ApSemplice\MemberType;
use ApSemplice\Money;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

final class PeoplePage {

	public static function render_list(): void {
		$q      = Ui::get_str( 'q' );
		$type   = Ui::get_str( 'type' );
		$status = Ui::get_str( 'status' );
		$rows   = Plugin::people()->search( array( 'q' => $q, 'type' => $type, 'status' => $status ) );
		$at_limit = '1' === Ui::get_str( 'at_limit' );
		$gov      = Plugin::people()->guest_overview();
		if ( $at_limit ) {
			$rows = array_values( array_filter( $rows, function ( $x ) use ( $gov ) {
				return MemberType::GUEST === $x['type'] && ! empty( $gov[ (int) $x['id'] ]['flag'] );
			} ) );
		}
		$today  = current_time( 'Y-m-d' );

		Ui::header(
			'Soci e ospiti',
			'<a class="page-title-action" href="' . esc_url( Ui::url( 'apse-person', array( 'type' => 'ordinary' ) ) ) . '">Nuovo socio</a> '
			. '<a class="page-title-action" href="' . esc_url( Ui::url( 'apse-person', array( 'type' => 'guest' ) ) ) . '">Nuovo ospite</a> '
			. '<a class="page-title-action" href="' . esc_url( Ui::url( 'apse-import' ) ) . '">Importa da Excel/CSV</a> '
			. Exports::link( 'people', array(), 'Esporta CSV' )
		);

		echo '<form method="get" class="apse-filters"><input type="hidden" name="page" value="apse-people">';
		echo '<input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Cerca per nome, tessera, email o codice fiscale"> ';
		echo '<select name="type">' . Ui::options( MemberType::labels(), $type, 'Tutti i tipi' ) . '</select> ';
		echo '<select name="status">' . Ui::options( array( 'active' => 'Tessera valida', 'expired' => 'Tessera scaduta / senza tessera', 'noaccess' => 'Senza accesso all\'area riservata' ), $status, 'Qualsiasi stato' ) . '</select> ';
		echo '<label><input type="checkbox" name="at_limit" value="1"' . checked( $at_limit, true, false ) . '> Solo ospiti da invitare a iscriversi</label> ';
		echo '<button class="button">Filtra</button></form>';

		echo '<p class="description">' . count( $rows ) . ' persone.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Tessera</th><th>Nome</th><th>Tipo</th><th>Email</th><th>Stato</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">Nessuna persona trovata.</td></tr>';
		}
		foreach ( $rows as $p ) {
			if ( MemberType::GUEST === $p['type'] ) {
				$state = 'Ospite di ' . esc_html( (string) $p['host_name'] ) . '<br>' . self::guest_badge( $gov[ (int) $p['id'] ] ?? null );
			} elseif ( ! empty( $p['active_until'] ) && $p['active_until'] >= $today ) {
				$state = '<span class="apse-ok">Valida fino al ' . Ui::date( $p['active_until'] ) . '</span>';
			} elseif ( ! empty( $p['active_until'] ) ) {
				$state = '<span class="apse-neg">Scaduta il ' . Ui::date( $p['active_until'] ) . '</span>';
			} else {
				$state = '<span class="apse-neg">Non iscritto</span>';
			}
			if ( MemberType::is_member( $p['type'] ) && empty( $p['wp_user_id'] ) ) {
				$state .= '<br>' . self::invite_link( $p );
			}
			echo '<tr><td>' . esc_html( (string) $p['card_number'] ?: '—' ) . '</td>';
			echo '<td><a href="' . esc_url( Ui::url( 'apse-person', array( 'id' => $p['id'] ) ) ) . '"><strong>' . esc_html( $p['last_name'] . ' ' . $p['first_name'] ) . '</strong></a></td>';
			echo '<td>' . esc_html( MemberType::label( $p['type'] ) ) . '</td><td>' . esc_html( (string) $p['email'] ) . '</td><td>' . $state . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td><a class="button button-small" href="' . esc_url( Ui::url( 'apse-person', array( 'id' => $p['id'] ) ) ) . '">Apri</a></td></tr>';
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	public static function render_edit(): void {
		$id     = Ui::get_int( 'id' );
		$people = Plugin::people();
		$p      = $id ? $people->get( $id ) : null;
		if ( $id && ! $p ) {
			wp_die( 'Persona non trovata.' );
		}
		$type = $p ? $p['type'] : ( MemberType::is_valid( Ui::get_str( 'type' ) ) ? Ui::get_str( 'type' ) : MemberType::ORDINARY );
		$val  = function ( string $k ) use ( $p ) {
			return $p ? (string) ( $p[ $k ] ?? '' ) : '';
		};
		$host_id = $p ? (int) $p['host_person_id'] : Ui::get_int( 'host' );
		$back    = Ui::url( 'apse-person', $id ? array( 'id' => $id ) : array( 'type' => $type ) );
		$hosts   = array_values( array_filter( $people->search(), function ( $x ) use ( $id ) {
			return MemberType::is_member( $x['type'] ) && (int) $x['id'] !== $id;
		} ) );

		Ui::header( $p ? $people->full_name( $p ) : ( MemberType::GUEST === $type ? 'Nuovo ospite' : 'Nuovo socio' ), '<a class="page-title-action" href="' . esc_url( Ui::url( 'apse-people' ) ) . '">← Elenco</a>' );

		echo '<div class="apse-cols"><div class="apse-col">';
		Ui::form_open( 'apse_save_person', $back );
		echo Ui::hidden( 'id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table apse-form"><tbody>';
		echo '<tr><th>Tipo</th><td><select name="type" id="apse-type">' . Ui::options( MemberType::labels(), $type ) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description" id="apse-type-hint"></p></td></tr>';
		echo '<tr class="apse-row-host"><th>Socio ospitante *</th><td>' . Ui::person_select( 'host_person_id', $hosts, $host_id, '— scegli il socio —', 'apse-host' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr class="apse-row-card"><th>N. tessera</th><td><input type="text" name="card_number" id="apse-card" value="' . esc_attr( $val( 'card_number' ) ) . '" class="regular-text"> '
			. '<button type="button" class="button" id="apse-next-card" data-next="' . esc_attr( $people->next_free_card() ) . '">Prossimo libero</button>'
			. '<p class="description">Assegnata a mano, univoca, modificabile.</p></td></tr>';
		echo '<tr><th>Nome *</th><td><input type="text" name="first_name" value="' . esc_attr( $val( 'first_name' ) ) . '" class="regular-text" required></td></tr>';
		echo '<tr><th>Cognome *</th><td><input type="text" name="last_name" value="' . esc_attr( $val( 'last_name' ) ) . '" class="regular-text" required></td></tr>';
		echo '<tr><th>Email</th><td><input type="email" name="email" id="apse-email" value="' . esc_attr( $val( 'email' ) ) . '" class="regular-text">'
			. '<p class="description apse-email-note">Facoltativa: con l\'email il socio ha subito il suo accesso all\'area riservata. Senza, serve almeno il cellulare o il numero di tessera, e il socio si attiva dopo con un link (da mandare su WhatsApp).</p></td></tr>';
		echo '<tr><th>' . ( MemberType::GUEST === $type ? 'Cellulare' : 'Telefono' ) . '</th><td><input type="text" name="phone" value="' . esc_attr( $val( 'phone' ) ) . '" class="regular-text"' . ( MemberType::GUEST === $type ? ' required placeholder="333 1234567"' : '' ) . '>' . ( MemberType::GUEST === $type ? '<p class="description">Obbligatorio per gli ospiti: è il dato che serve a riconoscerli (anche se si registrano da soci diversi) e a contattarli su WhatsApp.</p>' : '' ) . '</td></tr>';
		echo '<tr><th>Codice fiscale</th><td><input type="text" name="tax_code" value="' . esc_attr( $val( 'tax_code' ) ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Data di ingresso</th><td><input type="date" name="joined_on" value="' . esc_attr( $val( 'joined_on' ) ?: current_time( 'Y-m-d' ) ) . '"></td></tr>';
		echo '<tr><th>Note</th><td><textarea name="notes" rows="3" class="large-text">' . esc_textarea( $val( 'notes' ) ) . '</textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( $p ? 'Salva' : 'Crea' );
		Ui::form_close();

		if ( $p ) {
			echo '<hr>';
			Ui::form_open( 'apse_delete_person', $back, false, 'apse-confirm' );
			echo Ui::hidden( 'id', $id ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<button class="button button-link-delete" data-confirm="Eliminare questa persona? L\'utente WordPress collegato non viene cancellato.">Elimina persona</button>';
			Ui::form_close();
		}
		echo '</div>';

		if ( $p ) {
			echo '<div class="apse-col">';
			self::panel_access( $p );
			self::panel_guest_status( $p );
			self::panel_membership( $p );
			self::panel_card_qr( $p );
			self::panel_treasurer( $p );
			self::panel_guests( $p );
			self::panel_activities( $p );
			self::panel_bookings( $p );
			self::panel_payments( $p );
			echo '</div>';
		}
		echo '</div>';
		Ui::footer();
	}

	private static function panel_membership( array $p ): void {
		$people = Plugin::people();
		echo '<div class="apse-card"><h2>Tessera e iscrizione</h2>';
		if ( MemberType::GUEST === $p['type'] ) {
			echo '<p>Gli ospiti non sono soci: partecipano alle attività tramite un socio.</p>';
			if ( $p['host_person_id'] ) {
				echo '<p>Ospite di <a href="' . esc_url( Ui::url( 'apse-person', array( 'id' => $p['host_person_id'] ) ) ) . '">' . esc_html( (string) ( $people->get( (int) $p['host_person_id'] )['last_name'] ?? '' ) ) . '</a></p>';
			}
			echo '</div>';
			return;
		}
		$until  = $people->active_until( (int) $p['id'] );
		$active = $until && $until >= current_time( 'Y-m-d' );
		echo '<p>' . ( $active ? '<strong class="apse-ok">Tessera valida fino al ' . Ui::date( $until ) . '</strong>' : '<strong class="apse-neg">Tessera non valida' . ( $until ? ' (scaduta il ' . Ui::date( $until ) . ')' : '' ) . '</strong>' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( MemberType::is_auto_renewed( $p['type'] ) ) {
			echo '<p class="description">Socio fondatore: la tessera è sempre rinnovata (scadenza a ' . (int) Settings::get( 'founder_years' ) . ' anni dall\'ingresso).</p></div>';
			return;
		}
		$cur = Settings::social_year();
		$opts = array( $cur->previous()->label() => $cur->previous()->label(), $cur->label() => $cur->label() . ' (corrente)', $cur->next()->label() => $cur->next()->label() );
		Ui::form_open( 'apse_set_membership', Ui::url( 'apse-person', array( 'id' => $p['id'] ) ) );
		echo Ui::hidden( 'id', $p['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Anno sociale: <select name="social_year">' . Ui::options( $opts, $cur->label() ) . '</select> '; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<button class="button" name="enabled" value="1">Segna iscritto</button> <button class="button" name="enabled" value="0">Togli iscrizione</button></p>';
		Ui::form_close();
		echo '<p class="description">L\'incasso di una "Quota associativa" iscrive in automatico. Qui puoi iscrivere a mano chi ha già pagato.</p>';
		$rows = $people->memberships( (int) $p['id'] );
		if ( $rows ) {
			echo '<table class="widefat striped"><thead><tr><th>Anno sociale</th><th>Dal</th><th>Al</th><th>Origine</th></tr></thead><tbody>';
			foreach ( $rows as $m ) {
				echo '<tr><td>' . esc_html( $m['social_year'] ) . '</td><td>' . Ui::date( $m['valid_from'] ) . '</td><td>' . Ui::date( $m['valid_to'] ) . '</td><td>' . esc_html( $m['source'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/** Per un socio senza accesso: segnalazione e pulsante che apre WhatsApp con il link di attivazione già pronto. */
	private static function invite_link( array $p ): string {
		$wa = \ApSemplice\Phone::whatsapp( (string) $p['phone'] );
		if ( '' === $wa ) {
			return '<span class="apse-warn">Senza accesso</span> <span class="description">(senza cellulare: apri la scheda per il link)</span>';
		}
		return '<span class="apse-warn">Senza accesso</span> <a class="button button-small" target="_blank" rel="noopener" href="' . esc_url( 'https://wa.me/' . $wa . '?text=' . rawurlencode( \ApSemplice\Frontend\Activation::invite_text( $p ) ) ) . '">💬 Invia link</a>';
	}

	/** Accesso all'area riservata di un socio: attivo, oppure link di attivazione da mandare (WhatsApp o copia). */
	private static function panel_access( array $p ): void {
		if ( ! MemberType::is_member( $p['type'] ) ) {
			return;
		}
		echo '<div class="apse-card"><h2>Accesso all\'area riservata</h2>';
		if ( ! empty( $p['wp_user_id'] ) ) {
			echo '<p><strong class="apse-ok">Accesso attivo</strong> · email ' . esc_html( (string) $p['email'] ) . '</p></div>';
			return;
		}
		$url = \ApSemplice\Frontend\Activation::url( (int) $p['id'] );
		echo '<p><strong class="apse-warn">Senza accesso.</strong> Il socio non ha ancora un\'email collegata: con questo link sceglie email e password e si attiva da solo (vale ' . (int) \ApSemplice\ActivationToken::VALID_DAYS . ' giorni, se scade se ne genera un altro aprendo questa scheda).</p>'
			. '<p><input type="text" readonly class="large-text" value="' . esc_attr( $url ) . '" onclick="this.select()"></p>';
		$wa = \ApSemplice\Phone::whatsapp( (string) $p['phone'] );
		if ( '' !== $wa ) {
			echo '<p><a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url( 'https://wa.me/' . $wa . '?text=' . rawurlencode( \ApSemplice\Frontend\Activation::invite_text( $p ) ) ) . '">💬 Invia il link su WhatsApp</a></p>';
		} else {
			echo '<p class="description">Senza cellulare: copia il link e mandalo come preferisci. Oppure aggiungi l\'email qui sopra: l\'accesso si crea subito.</p>';
		}
		echo '</div>';
	}

	/** Etichetta per un ospite: quante volte è venuto, eventuali registrazioni "gemelle" (stesso nome, email o telefono) e se è da invitare a iscriversi. */
	public static function guest_badge( ?array $ov ): string {
		if ( ! $ov ) {
			return '';
		}
		$txt = $ov['count'] . ( 1 === $ov['count'] ? ' partecipazione' : ' partecipazioni' );
		if ( $ov['twins'] ) {
			$txt .= ' · registrato anche come ' . count( $ov['twins'] ) . ( 1 === count( $ov['twins'] ) ? ' altro ospite' : ' altri ospiti' ) . ': in tutto ' . $ov['total'];
		}
		if ( $ov['flag'] ) {
			return '<strong class="apse-neg">' . esc_html( $txt . ' — da invitare a iscriversi' ) . '</strong>';
		}
		return $ov['twins'] ? '<strong class="apse-warn">' . esc_html( $txt ) . '</strong>' : '<span class="description">' . esc_html( $txt ) . '</span>';
	}

	/** Scheda di un ospite: a cosa è già venuto (eventi e corsi), se risulta registrato più volte e l'iscrizione come socio. */
	private static function panel_guest_status( array $p ): void {
		if ( MemberType::GUEST !== $p['type'] ) {
			return;
		}
		$st = Plugin::activities()->guest_status( (int) $p['id'] );
		$ov = Plugin::people()->guest_overview()[ (int) $p['id'] ] ?? null;
		echo '<div class="apse-card"><h2>Partecipazioni come ospite</h2><p>' . self::guest_badge( $ov ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $ov && $ov['flag'] ) {
			echo '<p class="apse-neg">Ha raggiunto la soglia di partecipazioni per i non soci' . ( $ov['twins'] ? ' (sommando le altre registrazioni)' : '' ) . ': conviene invitarlo a iscriversi come socio, anche per l\'assicurazione. Nessun blocco automatico: decidi tu.</p>';
		}
		if ( $ov && $ov['twins'] ) {
			echo '<p><strong>Potrebbe essere la stessa persona registrata più volte:</strong></p><ul>';
			foreach ( $ov['twins'] as $t ) {
				echo '<li><a href="' . esc_url( Ui::url( 'apse-person', array( 'id' => $t['id'] ) ) ) . '">' . esc_html( $t['name'] ) . '</a> <span class="description">ospite di ' . esc_html( $t['host'] ) . ' · ' . (int) $t['count'] . ' partecipazioni · ' . esc_html( implode( ', ', array_unique( $t['why'] ) ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		if ( $st['items'] ) {
			echo '<table class="widefat striped"><thead><tr><th>Quando</th><th>A cosa</th><th>Stato</th></tr></thead><tbody>';
			foreach ( $st['items'] as $i ) {
				$when  = 'event' === $i['type'] ? Ui::date( $i['when'] ) . ( $i['time'] ? ' ' . esc_html( $i['time'] ) : '' ) : 'dal ' . esc_html( $i['when'] );
				$state = 'event' === $i['type'] ? ( $i['checked_in'] ? '<span class="apse-ok">✔ è venuto</span>' : ( $i['ended'] ? 'prenotato, ingresso non registrato' : 'prenotato' ) ) : ( $i['ended'] ? 'corso concluso' : 'iscritto al corso' );
				echo '<tr><td>' . $when . '</td><td><a href="' . esc_url( Ui::url( 'apse-activity', array( 'id' => $i['activity_id'] ) ) ) . '">' . esc_html( $i['activity_name'] ) . '</a> <span class="description">' . ( 'event' === $i['type'] ? 'evento' : 'corso' ) . '</span></td><td>' . $state . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">Non ha ancora partecipato a nulla.</p>';
		}
		$wa = \ApSemplice\Phone::whatsapp( (string) $p['phone'] );
		if ( '' !== $wa ) {
			$assoc = (string) \ApSemplice\Settings::get( 'association_name' );
			$msg   = 'Ciao ' . $p['first_name'] . ', grazie per essere venuto/a' . ( '' !== $assoc ? ' da ' . $assoc : '' ) . '! Se ti fa piacere continuare a partecipare puoi iscriverti come socio: ti spiego come?';
			echo '<p><a class="button" href="' . esc_url( 'https://wa.me/' . $wa . '?text=' . rawurlencode( $msg ) ) . '" target="_blank" rel="noopener">💬 Scrivigli su WhatsApp</a> <span class="description">cellulare ' . esc_html( (string) $p['phone'] ) . '</span></p>';
		}
		Ui::form_open( 'apse_promote_guest', Ui::url( 'apse-person', array( 'id' => (int) $p['id'] ) ), false, 'apse-confirm' );
		echo '<details style="margin-top:10px"' . ( $ov && $ov['flag'] ? ' open' : '' ) . '><summary><strong>Iscrivi come socio</strong></summary>' . Ui::hidden( 'id', $p['id'] ) // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p>Email (facoltativa) <input type="email" name="email" value="' . esc_attr( (string) $p['email'] ) . '"> tipo <select name="type">' . Ui::options( array( MemberType::ORDINARY => MemberType::label( MemberType::ORDINARY ), MemberType::VOLUNTEER => MemberType::label( MemberType::VOLUNTEER ) ), MemberType::ORDINARY ) . '</select> '
			. 'n. tessera (facoltativo) <input type="text" name="card_number" class="small-text"></p>'
			. '<p><label><input type="checkbox" name="membership" value="1" checked> Segna l\'iscrizione all\'anno sociale ' . esc_html( \ApSemplice\Settings::social_year()->label() ) . '</label> <button class="button button-primary">Iscrivi come socio</button></p>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p class="description">La scheda resta la stessa: tutte le partecipazioni e i pagamenti già registrati restano collegati. Si crea l\'utente per l\'area riservata.</p></details>';
		Ui::form_close();
		echo '</div>';
	}

	/** QR di verifica della tessera: si può stampare o girare al socio (è lo stesso che vede nella sua area riservata). */
	private static function panel_card_qr( array $p ): void {
		if ( ! \ApSemplice\Settings::card_qr_enabled() || ! MemberType::is_member( $p['type'] ) ) {
			return;
		}
		$url = \ApSemplice\Settings::card_url( (int) $p['id'] );
		echo '<div class="apse-card"><h2>QR della tessera</h2><div style="max-width:170px">' . \ApSemplice\QrCode::svg( $url, 4, 'QR della tessera' ) . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<p class="description"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Apri la pagina di verifica</a> · stesso codice che il socio vede nella sua area riservata.</p></div>';
	}

	/** Permesso di registrare spese dall'area riservata (per chi non usa l'amministrazione del sito). */
	private static function panel_treasurer( array $p ): void {
		if ( MemberType::GUEST === $p['type'] || empty( $p['wp_user_id'] ) ) {
			return;
		}
		$on = \ApSemplice\Access::is_treasurer( (int) $p['wp_user_id'] );
		echo '<div class="apse-card"><h2>Tesoriere</h2>';
		echo '<p>' . ( $on ? '<strong class="apse-ok">Può registrare spese dall\'area riservata</strong>' : 'Non può registrare spese.' ) . '</p>';
		Ui::form_open( 'apse_set_treasurer', Ui::url( 'apse-person', array( 'id' => (int) $p['id'] ) ) );
		echo Ui::hidden( 'id', $p['id'] ) . ( $on ? '' : Ui::hidden( 'enabled', 1 ) ) // phpcs:ignore WordPress.Security.EscapeOutput
			. '<button class="button">' . ( $on ? 'Togli il permesso' : 'Permetti di registrare spese' ) . '</button>';
		Ui::form_close();
		echo '<p class="description">Con il permesso, nell\'area riservata compare la pagina "Spese" (shortcode <code>[apsemplice_spese]</code>): scatta lo scontrino e registra la spesa. Vede solo le spese che ha registrato lui e non i saldi dei conti.</p></div>';
	}

	private static function panel_guests( array $p ): void {
		if ( ! MemberType::is_member( $p['type'] ) ) {
			return;
		}
		$guests = Plugin::people()->guests_of( (int) $p['id'] );
		echo '<div class="apse-card"><h2>Ospiti di questo socio</h2>';
		if ( $guests ) {
			echo '<ul>';
			foreach ( $guests as $g ) {
				echo '<li><a href="' . esc_url( Ui::url( 'apse-person', array( 'id' => $g['id'] ) ) ) . '">' . esc_html( $g['first_name'] . ' ' . $g['last_name'] ) . '</a></li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="description">Nessun ospite.</p>';
		}
		echo '<a class="button" href="' . esc_url( Ui::url( 'apse-person', array( 'type' => 'guest', 'host' => $p['id'] ) ) ) . '">Aggiungi ospite</a></div>';
	}

	private static function panel_activities( array $p ): void {
		$statuses = Plugin::activities()->status_for_person( (int) $p['id'] );
		echo '<div class="apse-card"><h2>Attività e pagamenti</h2>';
		if ( ! $statuses ) {
			echo '<p class="description">Non è iscritto a nessuna attività. Si iscrive dalla scheda dell\'attività.</p></div>';
			return;
		}
		foreach ( $statuses as $s ) {
			$e = $s['enrollment'];
			echo '<details class="apse-detail"><summary><strong><a href="' . esc_url( Ui::url( 'apse-activity', array( 'id' => $e['activity_id'] ) ) ) . '">' . esc_html( $s['activity']['name'] ) . '</a></strong> (' . esc_html( $s['activity']['social_year'] ) . ') — ' . Ui::pay_status( $s['summary'] ) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p class="description">' . ( null === $e['end_month'] ? 'Iscritto dal ' . esc_html( Ui::month( $e['start_month'] ) ) : 'Cancellato (ultimo mese dovuto: ' . esc_html( Ui::month( $e['end_month'] ) ) . ')' ) . '</p>';
			echo Ui::months_table( $s['summary'] ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</details>';
		}
		echo '</div>';
	}

	private static function panel_bookings( array $p ): void {
		$rows = Plugin::activities()->bookings_for_person( (int) $p['id'] );
		if ( ! $rows ) {
			return;
		}
		echo '<div class="apse-card"><h2>Eventi e prenotazioni</h2><table class="widefat striped"><thead><tr><th>Data</th><th>Evento</th><th>Contributo</th><th>Stato</th></tr></thead><tbody>';
		foreach ( $rows as $b ) {
			echo '<tr><td>' . Ui::date( $b['session_date'] ) . ( $b['start_time'] ? ' ' . esc_html( $b['start_time'] ) : '' ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<td><a href="' . esc_url( Ui::url( 'apse-activity', array( 'id' => $b['activity_id'] ) ) ) . '">' . esc_html( $b['activity_name'] ) . '</a></td>'
				. '<td>' . esc_html( Money::format( (int) $b['fee_due_cents'] ) ) . '</td><td>' . ( $b['active'] ? Ui::booking_state( $b ) : '<span class="apse-warn">annullata</span>' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div>';
	}

	private static function panel_payments( array $p ): void {
		$rows = array_slice( Plugin::ledger()->rows_of_person( (int) $p['id'] ), 0, 15 );
		if ( ! $rows ) {
			return;
		}
		echo '<div class="apse-card"><h2>Ultimi movimenti</h2><table class="widefat striped"><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . Ui::date( $r['tx_date'] ) . '</td><td>' . esc_html( $r['category_name'] . ( $r['activity_name'] ? ' · ' . $r['activity_name'] : '' ) ) . '</td><td>' . esc_html( Money::format( (int) $r['amount_cents'] ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div>';
	}
}
