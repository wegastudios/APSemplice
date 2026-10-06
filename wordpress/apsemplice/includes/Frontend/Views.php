<?php
namespace ApSemplice\Frontend;

use ApSemplice\Access;
use ApSemplice\ActivityKind;
use ApSemplice\Labels;
use ApSemplice\License;
use ApSemplice\MemberType;
use ApSemplice\Money;
use ApSemplice\Plugin;
use ApSemplice\Pricing;
use ApSemplice\Settings;
use ApSemplice\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Viste del front-end (area riservata e pagine pubbliche). Ogni vista restituisce HTML già escapato,
 * con classi `apsf-*` stilate da assets/frontend.css che eredita font e colori del tema.
 * Le scelte di layout (colonne, spazi) sono dei builder (Gutenberg, Elementor): qui solo il contenuto.
 */
final class Views {

	const DAYS   = array( 'dom', 'lun', 'mar', 'mer', 'gio', 'ven', 'sab' );
	const MONTHS = array( 1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre' );

	// ---------- Utilità ----------

	public static function date_long( string $ymd ): string {
		$t = strtotime( $ymd . ' 12:00:00 UTC' );
		return self::DAYS[ (int) gmdate( 'w', $t ) ] . ' ' . (int) gmdate( 'j', $t ) . ' ' . self::MONTHS[ (int) gmdate( 'n', $t ) ] . ' ' . gmdate( 'Y', $t );
	}

	private static function d( ?string $ymd ): string {
		return $ymd ? esc_html( ( new \DateTimeImmutable( $ymd ) )->format( 'd/m/Y' ) ) : '—';
	}

	private static function wrap( string $inner, string $class = '' ): string {
		Assets::enqueue();
		return '<div class="apsf ' . esc_attr( $class ) . '">' . self::flash() . $inner . '</div>';
	}

	/** Esito dell'ultima azione (una sola volta per pagina, anche con più viste). */
	private static function flash(): string {
		static $printed = false;
		if ( $printed ) {
			return '';
		}
		$msg = \ApSemplice\Flash::read( 'apsf' ); // solo messaggi scritti dal sito (firmati)
		$ok  = $msg['ok'];
		$err = $msg['err'];
		if ( '' === $ok && '' === $err ) {
			return '';
		}
		$printed = true;
		return '' !== $err ? '<div class="apsf-notice apsf-err" role="alert">' . esc_html( $err ) . '</div>' : '<div class="apsf-notice apsf-ok" role="status">' . esc_html( $ok ) . '</div>';
	}

	private static function notice( string $text, string $kind = '' ): string {
		return '<div class="apsf-notice ' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</div>';
	}

	public static function fee_text( array $a ): string {
		$fee   = (int) $a['fee_cents'];
		$guest = null === $a['guest_fee_cents'] || '' === $a['guest_fee_cents'] ? null : (int) $a['guest_fee_cents'];
		$unit  = ActivityKind::fee_unit( $a['kind'], (string) ( $a['billing'] ?? 'monthly' ) );
		$txt   = 0 === $fee ? 'Gratuito per i soci' : 'Soci ' . Money::format( $fee ) . rtrim( ' ' . $unit );
		if ( null !== $guest ) {
			$txt .= ' · ' . ( 0 === $guest ? 'ospiti: gratuito' : 'ospiti ' . Money::format( $guest ) . rtrim( ' ' . $unit ) );
		} elseif ( $fee > 0 ) {
			$txt .= ' · ospiti: uguale';
		}
		return $txt;
	}

	private static function form( string $action, string $fields_html, string $button, bool $confirm = false, string $class = '', bool $multipart = false ): string {
		$html  = '<form class="apsf-form ' . esc_attr( $class ) . '" method="post"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . ' action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		$html .= '<input type="hidden" name="_back" value="' . esc_url( Restrict::current_url() ) . '">';
		$html .= wp_nonce_field( $action, '_wpnonce', false, false );
		$html .= $fields_html;
		$html .= '<button type="submit" class="apsf-btn wp-element-button"' . ( $confirm ? ' data-confirm="Confermi?"' : '' ) . '>' . esc_html( $button ) . '</button></form>';
		return $html;
	}

	private static function hidden( string $name, $value ): string {
		return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
	}

	// ---------- Accesso ----------

	public static function login_prompt(): string {
		Assets::enqueue();
		$form = wp_login_form( array( 'echo' => false, 'redirect' => Restrict::current_url(), 'label_username' => 'Email o nome utente', 'label_log_in' => 'Accedi' ) );
		return self::wrap(
			'<div class="apsf-card"><h3>Area riservata ai soci</h3><p>Accedi per vedere la tua tessera, le tue attività e prenotarti agli eventi.</p>'
			. $form . '<p class="apsf-small"><strong><a href="' . esc_url( FirstAccess::url() ) . '">Primo accesso</a></strong> (non hai ancora una password) · <a href="' . esc_url( wp_lostpassword_url( Restrict::current_url() ) ) . '">Password dimenticata?</a></p></div>'
		);
	}

	/** Esegue $fn( persona ) solo se l'utente è collegato e ha una scheda socio; altrimenti mostra il messaggio giusto. */
	private static function with_person( callable $fn, string $class = '' ): string {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt();
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // dati personali: mai nella cache di pagina (plugin di cache, CDN)
		}
		$uid      = get_current_user_id();
		$is_admin = Access::is_admin_user( $uid );
		if ( ! $is_admin && ! License::allows( 'member_area' ) ) {
			return self::wrap( self::notice( 'Servizio temporaneamente sospeso. Contatta l\'associazione.', 'apsf-err' ) );
		}
		$person = Access::person_for_user( $uid );
		if ( ! $person ) {
			return self::wrap( self::notice( $is_admin
				? 'Sei amministratore del sito e non hai una scheda socio collegata: qui i soci vedono i propri dati.'
				: 'Il tuo utente non è collegato a una scheda socio. Contatta l\'associazione.' ) );
		}
		return self::wrap( (string) $fn( $person ), $class );
	}

	// ---------- Sezioni dell'area soci ----------

	public static function section_card( array $p ): string {
		$people = Plugin::people();
		$until  = MemberType::GUEST === $p['type'] ? null : $people->active_until( (int) $p['id'] );
		$active = $until && $until >= current_time( 'Y-m-d' );
		$assoc  = (string) Settings::get( 'association_name' );
		$valid  = MemberType::is_auto_renewed( $p['type'] ) ? 'Sempre rinnovata' : ( $until ? self::d( $until ) : '—' );
		return '<section class="apsf-section"><div class="apsf-memcard">'
			. ( '' !== $assoc ? '<div class="apsf-memcard-assoc">' . esc_html( $assoc ) . '</div>' : '' )
			. '<div class="apsf-memcard-name">' . esc_html( trim( $p['first_name'] . ' ' . $p['last_name'] ) ) . '</div>'
			. '<div class="apsf-memcard-type">' . esc_html( MemberType::label( $p['type'] ) ) . '</div>'
			. '<dl class="apsf-memcard-data"><div><dt>Tessera n.</dt><dd>' . esc_html( (string) ( $p['card_number'] ?: '—' ) ) . '</dd></div>'
			. '<div><dt>Valida fino al</dt><dd>' . $valid . '</dd></div></dl>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<span class="apsf-badge ' . ( $active ? 'apsf-badge-ok' : 'apsf-badge-bad' ) . '">' . ( $active ? 'Tessera valida' : 'Tessera non valida' ) . '</span>'
			. self::card_qr( $p ) . \ApSemplice\Wallet::buttons( $p )
			. '</div></section>';
	}

	/** QR della tessera (si verifica al momento, anche se la tessera nel frattempo scade o si rinnova). */
	private static function card_qr( array $p ): string {
		if ( ! Settings::card_qr_enabled() || ! MemberType::is_member( $p['type'] ) ) {
			return '';
		}
		$url = Settings::card_url( (int) $p['id'] );
		try {
			$svg = \ApSemplice\QrCode::svg( $url, 4, 'QR della tessera di ' . trim( $p['first_name'] . ' ' . $p['last_name'] ) );
		} catch ( \InvalidArgumentException $e ) {
			return '';
		}
		return '<div class="apsf-memcard-qr">' . $svg . '<div class="apsf-small">Mostra questo codice: chi lo scansiona vede subito se la tessera è valida. '
			. '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Apri la verifica</a></div></div>';
	}

	private static function pay_text( array $summary ): string {
		if ( $summary['balance'] < 0 ) {
			return '<span class="apsf-bad">Da versare ' . esc_html( Money::format( -$summary['balance'] ) ) . '</span>';
		}
		return '<span class="apsf-good">In regola</span>' . ( $summary['balance'] > 0 ? ' <span class="apsf-small">(credito ' . esc_html( Money::format( $summary['balance'] ) ) . ')</span>' : '' );
	}

	private static function booking_pay( array $b ): string {
		switch ( $b['state'] ) {
			case Pricing::FREE:
				return '<span class="apsf-good">gratuito</span>';
			case Pricing::PAID:
				return '<span class="apsf-good">pagato</span>';
			case Pricing::PARTIAL:
				return '<span class="apsf-warn">parziale · resta ' . esc_html( Money::format( (int) $b['remaining'] ) ) . '</span>';
		}
		return '<span class="apsf-bad">da pagare ' . esc_html( Money::format( (int) $b['remaining'] ) ) . '</span>';
	}

	public static function section_activities( array $p ): string {
		$html  = '<section class="apsf-section"><h3>Le mie attività</h3>';
		$today = current_time( 'Y-m-d' );
		$stat  = Plugin::activities()->status_for_person( (int) $p['id'] );
		if ( $stat ) {
			$html .= '<div class="apsf-table-wrap"><table class="apsf-table"><thead><tr><th>Corso</th><th>Anno</th><th>Iscrizione</th><th>Pagamenti</th></tr></thead><tbody>';
			foreach ( $stat as $s ) {
				$e     = $s['enrollment'];
				$unpd  = $s['summary']['unpaid_months'] ? '<div class="apsf-small">Mesi da pagare: ' . esc_html( implode( ', ', array_map( function ( $m ) {
					return self::MONTHS[ (int) substr( $m['month'], 5, 2 ) ] . ' ' . substr( $m['month'], 0, 4 );
				}, $s['summary']['unpaid_months'] ) ) ) . '</div>' : '';
				$html .= '<tr><td>' . esc_html( $s['activity']['name'] ) . '</td><td>' . esc_html( $s['activity']['social_year'] ) . '</td><td>'
					. ( null === $e['end_month'] ? 'Attiva' : 'Conclusa' ) . '</td><td>' . self::pay_text( $s['summary'] ) . $unpd . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			$html .= '</tbody></table></div>';
		}

		$upcoming = array();
		$past     = array();
		foreach ( Plugin::activities()->bookings_for_person( (int) $p['id'] ) as $b ) {
			if ( $b['active'] && $b['session_date'] >= $today ) {
				$upcoming[] = $b;
			} else {
				$past[] = $b;
			}
		}
		if ( $upcoming ) {
			usort( $upcoming, function ( $a, $b ) {
				return strcmp( $a['session_date'] . $a['start_time'], $b['session_date'] . $b['start_time'] );
			} );
			$html .= '<h4>Prossime prenotazioni</h4><ul class="apsf-list">';
			foreach ( $upcoming as $b ) {
				$html .= '<li><div><strong>' . esc_html( $b['activity_name'] ) . '</strong><div class="apsf-small">' . esc_html( self::date_long( $b['session_date'] ) )
					. ( $b['start_time'] ? ' · ore ' . esc_html( $b['start_time'] ) : '' ) . ( $b['location'] ? ' · ' . esc_html( $b['location'] ) : '' ) . '</div>'
					. '<div class="apsf-small">Contributo ' . esc_html( Money::format( (int) $b['fee_due_cents'] ) ) . ' · ' . self::booking_pay( $b ) . '</div></div>' // phpcs:ignore WordPress.Security.EscapeOutput
					. self::ticket_qr( $b ) . self::booking_controls( $b, $p ) . '</li>';
			}
			$html .= '</ul>';
		}
		if ( ! $stat && ! $upcoming && ! $past ) {
			$html .= '<p class="apsf-muted">Non sei ancora iscritto a nessuna attività.</p>';
		}
		$unpaid = array_filter( array_merge( $upcoming, $past ), function ( $b ) {
			return $b['active'] && $b['remaining'] > 0;
		} );
		if ( $unpaid || array_filter( $stat, function ( $s ) {
			return $s['summary']['balance'] < 0;
		} ) ) {
			if ( ! Plugin::payments()->enabled() ) {
				$html .= '<p class="apsf-small apsf-muted">' . esc_html( Settings::payment_hint() ) . '</p>';
			}
		}
		if ( $past ) {
			$html .= '<details class="apsf-details"><summary>Storico prenotazioni</summary><ul class="apsf-list">';
			foreach ( array_slice( $past, 0, 10 ) as $b ) {
				$html .= '<li><div><strong>' . esc_html( $b['activity_name'] ) . '</strong><div class="apsf-small">' . esc_html( self::date_long( $b['session_date'] ) ) . ( $b['active'] ? '' : ' · annullata' ) . '</div></div></li>';
			}
			$html .= '</ul></details>';
		}
		return $html . '</section>';
	}

	/** Biglietto QR di una prenotazione: solo per gli eventi per cui il gestore l'ha attivato. */
	private static function ticket_qr( array $b ): string {
		if ( ! Settings::tickets_enabled() || empty( $b['booking_qr'] ) || empty( $b['active'] ) ) {
			return '';
		}
		$url = Settings::ticket_url( (int) $b['session_id'], (int) $b['person_id'] );
		try {
			$svg = \ApSemplice\QrCode::svg( $url, 4, 'Biglietto QR: ' . $b['activity_name'] );
		} catch ( \InvalidArgumentException $e ) {
			return '';
		}
		return '<details class="apsf-details apsf-ticket"><summary>Biglietto QR</summary><div class="apsf-memcard-qr">' . $svg
			. '<div class="apsf-small">Mostralo all\'ingresso: si vede se la prenotazione è valida e se il contributo è versato. <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Apri la verifica</a></div></div></details>';
	}

	/** Annulla (se la regola lo consente) e Cambia nominativo di una prenotazione dell'area soci. */
	private static function booking_controls( array $b, array $actor ): string {
		$svc  = Plugin::activities();
		$sid  = (int) $b['session_id'];
		$pid  = (int) $b['person_id'];
		$ev   = $svc->cancellation_for( $sid, $pid );
		$html = '<div class="apsf-manage">';
		if ( $ev['allowed'] ) {
			$html .= '<div class="apsf-small apsf-muted">' . esc_html( $ev['message'] ) . '</div>'
				. self::form( 'apse_front_cancel_booking', self::hidden( 'session_id', $sid ) . self::hidden( 'person_id', $pid ), 'Annulla prenotazione', true, 'apsf-inline' );
		} else {
			$html .= '<div class="apsf-small apsf-muted">' . esc_html( $ev['message'] ) . '</div>';
		}
		if ( $ev['can_transfer'] ) {
			$pool    = array_merge( array( $actor ), Plugin::people()->guests_of( (int) $actor['id'] ) );
			$options = '';
			foreach ( $pool as $cand ) {
				if ( (int) $cand['id'] === $pid || $svc->has_active_booking( $sid, (int) $cand['id'] ) ) {
					continue;
				}
				$options .= '<option value="' . (int) $cand['id'] . '">' . esc_html( $cand['first_name'] . ' ' . $cand['last_name'] ) . '</option>';
			}
			$fields = '<div class="apsf-fields">'
				. ( '' !== $options ? '<label>Intesta a <select name="to_person_id"><option value="">— scegli —</option>' . $options . '</select></label>' : '' )
				. '<label>' . ( '' !== $options ? 'oppure nuovo ospite: nome' : 'Nuovo ospite: nome' ) . ' <input type="text" name="new_first_name"></label><label>Cognome <input type="text" name="new_last_name"></label><label>Cellulare <input type="tel" name="new_phone" placeholder="333 1234567"></label></div>';
			$html  .= '<details class="apsf-details"><summary>Cambia nominativo</summary>'
				. '<p class="apsf-small apsf-muted">Se il nuovo partecipante ha un contributo diverso (ad esempio un ospite) la differenza va integrata.</p>'
				. self::form( 'apse_front_transfer_booking', self::hidden( 'session_id', $sid ) . self::hidden( 'person_id', $pid ) . $fields, 'Cambia nominativo' ) . '</details>';
		}
		return $html . '</div>';
	}

	/** Stato di un pagamento online, per l'elenco dei pagamenti recenti. */
	private static function payment_status( string $status ): string {
		$m = array(
			'paid' => 'pagato', 'pending' => 'in attesa di conferma', 'created' => 'avviato', 'processing' => 'in registrazione',
			'cancelled' => 'annullato', 'failed' => 'non riuscito', 'expired' => 'scaduto',
		);
		return $m[ $status ] ?? $status;
	}

	/** Cosa c'è da pagare (quota associativa, mensilità, eventi) e, se i pagamenti online sono attivi, il pulsante per pagare. */
	public static function section_pay( array $p ): string {
		$pay  = Plugin::payments();
		$dues = $pay->dues_for( $p );
		$html = '<section class="apsf-section apsf-pay"><h3>Pagamenti</h3>';
		if ( ! $dues ) {
			$html .= '<p class="apsf-muted">Non hai nulla da pagare al momento.</p>';
		} elseif ( ! $pay->enabled() ) {
			$html .= '<ul class="apsf-list">';
			foreach ( $dues as $i ) {
				$html .= '<li><div><strong>' . esc_html( $i['label'] ) . '</strong><div class="apsf-small">' . esc_html( $i['person_name'] ) . '</div></div><strong>' . esc_html( Money::format( (int) $i['amount_cents'] ) ) . '</strong></li>';
			}
			$html .= '</ul><p class="apsf-small apsf-muted">' . esc_html( Settings::payment_hint() ) . '</p>';
		} else {
			$total  = 0;
			$fields = '<ul class="apsf-list apsf-paylist">';
			foreach ( $dues as $i ) {
				$total  += (int) $i['amount_cents'];
				$fields .= '<li><label class="apsf-payrow"><input type="checkbox" name="items[]" value="' . esc_attr( $i['key'] ) . '" data-cents="' . (int) $i['amount_cents'] . '" checked> '
					. '<span><strong>' . esc_html( $i['label'] ) . '</strong><span class="apsf-small"> · ' . esc_html( $i['person_name'] ) . '</span></span></label>'
					. '<strong>' . esc_html( Money::format( (int) $i['amount_cents'] ) ) . '</strong></li>';
			}
			$fields .= '</ul><p class="apsf-paytotal">Totale: <strong class="apsf-pay-total">' . esc_html( Money::format( $total ) ) . '</strong></p>';
			$html   .= self::form( 'apse_front_pay', $fields, 'paypal' === $pay->provider() ? 'Paga con PayPal' : 'Paga con carta' )
				. '<p class="apsf-small apsf-muted">Paghi su una pagina sicura di ' . ( 'paypal' === $pay->provider() ? 'PayPal' : 'Stripe' ) . ': i dati della carta non passano da questo sito.</p>';
		}
		$recent = $pay->list( array( 'payer_person_id' => (int) $p['id'] ), 5 );
		if ( $recent ) {
			$html .= '<details class="apsf-details"><summary>Ultimi pagamenti online</summary><ul class="apsf-list">';
			foreach ( $recent as $r ) {
				$html .= '<li><div>' . esc_html( ( new \DateTimeImmutable( $r['created_at'] ) )->format( 'd/m/Y' ) ) . ' · ' . esc_html( self::payment_status( $r['status'] ) ) . '</div><strong>' . esc_html( Money::format( (int) $r['amount_cents'] ) ) . '</strong></li>';
			}
			$html .= '</ul></details>';
		}
		return $html . '</section>';
	}

	public static function pay(): string {
		return self::with_person( array( __CLASS__, 'section_pay' ) );
	}

	/** Spese del tesoriere: modulo con scatto dello scontrino + le ultime spese registrate da lui (nessun saldo, nessun altro movimento). */
	/** Incassi del tesoriere: una persona, un conto e fino a tre voci (quota associativa, eventi, altre entrate). Il resto lo calcola la contabilità. */
	private static function section_collect(): string {
		if ( ! current_user_can( 'apse_collect', 0 ) ) {
			return '';
		}
		$ledger = Plugin::ledger();
		$people = '<option value="">— scegli —</option>';
		foreach ( Plugin::people()->search() as $p ) {
			$people .= '<option value="' . (int) $p['id'] . '">' . esc_html( trim( $p['last_name'] . ' ' . $p['first_name'] ) ) . '</option>';
		}
		$accounts = '';
		foreach ( $ledger->accounts() as $a ) {
			$accounts .= '<option value="' . (int) $a['id'] . '">' . esc_html( $a['name'] ) . '</option>';
		}
		$what = '<option value="">— niente —</option><option value="m">Quota associativa</option><optgroup label="Eventi">';
		foreach ( Plugin::activities()->upcoming_sessions( 40 ) as $s ) {
			$what .= '<option value="s:' . (int) $s['id'] . '">' . esc_html( $s['activity_name'] . ' · ' . self::d( $s['session_date'] ) ) . '</option>';
		}
		$what .= '</optgroup><optgroup label="Altre entrate">';
		foreach ( $ledger->categories() as $c ) {
			if ( Labels::category_kinds()[ $c['kind'] ][1] && ! in_array( $c['kind'], array( 'membership', 'activity_fee', 'adjustment' ), true ) ) {
				$what .= '<option value="c:' . (int) $c['id'] . '">' . esc_html( $c['name'] ) . '</option>';
			}
		}
		$what .= '</optgroup>';
		$rows  = '';
		for ( $i = 0; $i < 3; $i++ ) {
			$rows .= '<div class="apsf-fields"><label>Voce <select name="lines[' . $i . '][what]">' . $what . '</select></label>'
				. '<label>Importo (€) <input type="text" name="lines[' . $i . '][amount]" inputmode="decimal" placeholder="0,00"></label></div>';
		}
		$fields = '<div class="apsf-fields"><label>Chi paga <select name="person_id" required>' . $people . '</select></label>'
			. '<label>Sul conto <select name="account_id">' . $accounts . '</select></label></div>' . $rows;
		return '<section class="apsf-section apsf-collect"><h3>Incassa</h3><p class="apsf-small apsf-muted">Quota associativa, eventi e altre entrate. Per le quote l\'anno si calcola da solo.</p>'
			. self::form( 'apse_front_collect', $fields, 'Registra l\'incasso' ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function section_expenses( array $p ): string {
		if ( ! current_user_can( 'apse_add_expense', 0 ) ) {
			return '';
		}
		$ledger = Plugin::ledger();
		$cats   = array();
		foreach ( $ledger->categories() as $c ) {
			if ( Labels::category_kinds()[ $c['kind'] ][2] && 'adjustment' !== $c['kind'] ) {
				$cats[ (int) $c['id'] ] = $c['name'];
			}
		}
		$accounts = array();
		foreach ( $ledger->accounts() as $a ) {
			$accounts[ (int) $a['id'] ] = $a['name'];
		}
		$methods = array_diff_key( Labels::methods(), array( 'stripe' => 1, 'paypal' => 1 ) );
		$acts    = array();
		foreach ( Plugin::activities()->for_year( Settings::social_year()->label() ) as $a ) {
			$acts[ (int) $a['id'] ] = $a['name'];
		}
		$default = $ledger->default_account_for( 'cash' );
		$opts    = function ( array $items, $sel = null, string $empty = '' ) {
			$h = '' !== $empty ? '<option value="">' . esc_html( $empty ) . '</option>' : '';
			foreach ( $items as $k => $label ) {
				$h .= '<option value="' . esc_attr( (string) $k ) . '"' . ( (string) $k === (string) $sel ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
			}
			return $h;
		};
		$fields = '<div class="apsf-fields">'
			. '<label>Data <input type="date" name="date" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '" max="' . esc_attr( current_time( 'Y-m-d' ) ) . '" required></label>'
			. '<label>Importo (€) <input type="text" name="amount" inputmode="decimal" placeholder="0,00" required></label>'
			. '<label>Voce <select name="category_id" required>' . $opts( $cats, null, '— scegli —' ) . '</select></label>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<label>Dal conto <select name="account_id">' . $opts( $accounts, $default ? $default['id'] : null ) . '</select></label>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<label>Attività <select name="activity_id">' . $opts( $acts, null, 'Nessuna (costo generale)' ) . '</select></label>' // phpcs:ignore WordPress.Security.EscapeOutput
			. '<label>Descrizione <input type="text" name="description" maxlength="255"></label>'
			. '<label>N. fattura / scontrino <input type="text" name="document_ref" maxlength="80"></label>'
			. '</div>' . self::doc_inputs();
		$html = '<section class="apsf-section apsf-expenses"><h3>Registra una spesa</h3>'
			. '<p class="apsf-small apsf-muted">Fotografa lo scontrino o allega la fattura: la spesa entra in prima nota con il documento.</p>'
			. self::form( 'apse_front_expense', $fields, 'Registra la spesa', false, '', true );
		$mine = $ledger->expenses_by_user( get_current_user_id(), 10 );
		if ( $mine ) {
			$att   = \ApSemplice\Attachments::map_for( array_column( $mine, 'id' ) );
			$html .= '<h4>Le tue ultime spese</h4><ul class="apsf-list">';
			foreach ( $mine as $t ) {
				$docs  = '';
				foreach ( $att[ (int) $t['id'] ] ?? array() as $a ) {
					$docs .= '<a href="' . esc_url( \ApSemplice\Attachments::url( (int) $a['id'] ) ) . '" target="_blank" rel="noopener">' . esc_html( $a['original_name'] ) . '</a> ';
				}
				$more  = '<details class="apsf-details"><summary>Aggiungi documenti</summary>'
					. self::form( 'apse_front_expense_docs', self::hidden( 'transaction_id', $t['id'] ) . self::doc_inputs(), 'Allega', false, '', true ) . '</details>';
				$html .= '<li><div><strong>' . esc_html( $t['category_name'] ) . '</strong> · ' . esc_html( self::d( $t['tx_date'] ) )
					. ( '' !== $t['description'] ? '<div class="apsf-small">' . esc_html( $t['description'] ) . '</div>' : '' )
					. '<div class="apsf-small">' . ( $docs ? '📎 ' . $docs : '<span class="apsf-muted">nessun documento</span>' ) . '</div>' . $more . '</div>'
					. '<strong>' . esc_html( Money::format( (int) $t['amount_cents'] ) ) . '</strong></li>';
			}
			$html .= '</ul>';
		}
		return self::section_collect() . $html . '</section>';
	}

	/** Scelta dei documenti: file dal telefono o dal computer, oppure scatto con la fotocamera. */
	private static function doc_inputs(): string {
		return '<div class="apsf-docs"><label class="apsf-file">Documenti (PDF o foto) <input type="file" name="docs[]" class="apse-doc-input" accept="image/*,application/pdf" multiple></label>'
			. '<label class="apsf-btn apsf-shot">📷 Scatta una foto<input type="file" name="shots[]" class="apse-doc-input" accept="image/*" capture="environment" hidden></label></div>';
	}

	public static function expenses(): string {
		return self::with_person(
			function ( $p ) {
				return current_user_can( 'apse_add_expense', 0 ) ? self::section_expenses( $p ) : self::notice( 'Questa pagina è riservata al tesoriere.' );
			}
		);
	}

	public static function section_guests( array $p ): string {
		if ( ! MemberType::is_member( $p['type'] ) ) {
			return '';
		}
		$guests = Plugin::people()->guests_of( (int) $p['id'] );
		$html   = '<section class="apsf-section"><h3>I miei ospiti</h3>';
		$html  .= '<p class="apsf-muted">Gli ospiti possono partecipare alle attività senza essere soci: puoi prenotarli agli eventi.</p>';
		if ( $guests ) {
			$html .= '<ul class="apsf-list">';
			$today = current_time( 'Y-m-d' );
			foreach ( $guests as $g ) {
				$tickets = '';
				foreach ( Plugin::activities()->bookings_for_person( (int) $g['id'] ) as $b ) { // biglietti QR degli eventi che li prevedono
					if ( $b['active'] && $b['session_date'] >= $today && Settings::tickets_enabled() && ! empty( $b['booking_qr'] ) ) {
						$tickets .= '<div class="apsf-small">' . esc_html( $b['activity_name'] ) . ' · ' . esc_html( self::date_long( $b['session_date'] ) ) . '</div>' . self::ticket_qr( $b );
					}
				}
				$st      = Plugin::activities()->guest_status( (int) $g['id'] );
				$seen    = array();
				foreach ( array_slice( $st['items'], 0, 3 ) as $it ) {
					$seen[] = $it['activity_name'];
				}
				$status = '<div class="apsf-small apsf-muted">Partecipazioni: ' . (int) $st['count']
					. ( $seen ? ' (' . esc_html( implode( ', ', $seen ) ) . ')' : '' ) . '</div>';
				$html .= '<li><div><strong>' . esc_html( $g['first_name'] . ' ' . $g['last_name'] ) . '</strong>' . $status . $tickets . '</div></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			$html .= '</ul>';
		}
		$fields = '<div class="apsf-fields"><label>Nome <input type="text" name="first_name" required></label><label>Cognome <input type="text" name="last_name" required></label>'
			. '<label>Email (facoltativa) <input type="email" name="email"></label><label>Cellulare (obbligatorio) <input type="tel" name="phone" required placeholder="333 1234567" inputmode="tel"></label></div>';
		return $html . '<details class="apsf-details"><summary>Aggiungi un ospite</summary>' . self::form( 'apse_front_add_guest', $fields, 'Aggiungi ospite' ) . '</details></section>';
	}

	public static function section_profile( array $p ): string {
		$fields = '<div class="apsf-fields"><label>Telefono <input type="text" name="phone" value="' . esc_attr( (string) $p['phone'] ) . '"></label>'
			. '<label>Codice fiscale <input type="text" name="tax_code" value="' . esc_attr( (string) $p['tax_code'] ) . '"></label></div>';
		return '<section class="apsf-section"><h3>Il mio profilo</h3><dl class="apsf-dl"><div><dt>Nome</dt><dd>' . esc_html( $p['first_name'] . ' ' . $p['last_name'] ) . '</dd></div>'
			. '<div><dt>Email</dt><dd>' . esc_html( (string) $p['email'] ) . '</dd></div></dl>'
			. '<p class="apsf-small apsf-muted">Per cambiare nome o email scrivi all\'associazione.</p>'
			. self::form( 'apse_front_profile', $fields, 'Salva' ) . '</section>';
	}

	// ---------- Gestione degli eventi: prenotati e ingressi ----------

	/** Eventi che l'utente può gestire (referente, gestori indicati, amministratori). @return array[] attività */
	private static function managed_events(): array {
		$svc = Plugin::activities();
		$out = array();
		if ( Access::is_admin_user( get_current_user_id() ) ) {
			foreach ( $svc->for_year( Settings::social_year()->label() ) as $a ) {
				if ( ActivityKind::uses_sessions( $a['kind'] ) ) {
					$out[] = $a;
				}
			}
			return $out;
		}
		$person = Access::current_person();
		foreach ( $person ? $svc->managed_activity_ids( (int) $person['id'] ) : array() as $aid ) {
			$a = $svc->get( $aid );
			if ( $a && current_user_can( 'apse_manage_event', $aid ) ) {
				$out[] = $a;
			}
		}
		return $out;
	}

	private static function session_url( int $session_id = 0 ): string {
		$base = remove_query_arg( array( 'apsf_ok', 'apsf_err', 'apsf_sig', 'apse_session' ), Restrict::current_url() );
		return $session_id ? add_query_arg( 'apse_session', $session_id, $base ) : $base;
	}

	/** Elenco delle date degli eventi gestiti e, aprendone una, lista dei prenotati con registrazione degli ingressi. */
	public static function section_checkin( array $p ): string {
		$events = self::managed_events();
		if ( ! $events ) {
			return '';
		}
		$svc = Plugin::activities();
		$sid = isset( $_GET['apse_session'] ) ? (int) $_GET['apse_session'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $sid ) {
			$s = $svc->session( $sid );
			foreach ( $events as $a ) {
				if ( $s && (int) $s['activity_id'] === (int) $a['id'] ) {
					return self::checkin_detail( $s, $a );
				}
			}
		}
		$today = current_time( 'Y-m-d' );
		$from  = gmdate( 'Y-m-d', strtotime( $today . ' -3 days' ) );
		$html  = '<section class="apsf-section apsf-checkin"><h3>Ingressi agli eventi</h3><p class="apsf-small apsf-muted">Gli eventi che gestisci: apri una data per vedere i prenotati e registrare gli ingressi.</p>';
		$any   = false;
		foreach ( $events as $a ) {
			$rows = '';
			foreach ( $svc->sessions( (int) $a['id'] ) as $s ) {
				if ( ! empty( $s['cancelled_at'] ) || $s['session_date'] < $from ) {
					continue;
				}
				$active  = 0;
				$present = 0;
				foreach ( $svc->bookings_for_session( (int) $s['id'] ) as $b ) {
					if ( $b['active'] ) {
						$active++;
						$present += ! empty( $b['checked_in_at'] ) ? 1 : 0;
					}
				}
				$rows .= '<li><div><strong>' . esc_html( self::date_long( $s['session_date'] ) ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . '</strong>'
					. ( $s['session_date'] === $today ? ' <span class="apsf-badge apsf-badge-ok">oggi</span>' : '' )
					. '<div class="apsf-small">' . (int) $active . ' prenotati · ' . (int) $present . ' presenti</div></div>'
					. '<a class="apsf-btn" href="' . esc_url( self::session_url( (int) $s['id'] ) ) . '">Apri</a></li>';
			}
			if ( '' !== $rows ) {
				$any   = true;
				$html .= '<h4>' . esc_html( $a['name'] ) . '</h4><ul class="apsf-list">' . $rows . '</ul>';
			}
		}
		if ( ! $any ) {
			$html .= '<p class="apsf-muted">Nessuna data in programma per i tuoi eventi.</p>';
		}
		return $html . '</section>';
	}

	/** Contatore dei posti e, per chi può incassare, il modulo dell'ingresso sul posto (amico dell'ultimo minuto). */
	private static function seats_html( array $s ): string {
		$seat = Plugin::activities()->seats( (int) $s['id'] );
		if ( null === $seat['capacity'] ) {
			return '';
		}
		$cls = 0 === $seat['free'] ? 'apsf-bad' : 'apsf-good';
		return '<p class="' . $cls . '"><strong>' . ( 0 === $seat['free'] ? 'Posti esauriti' : 'Posti liberi: ' . (int) $seat['free'] ) . '</strong> <span class="apsf-small apsf-muted">(' . (int) $seat['taken'] . ' prenotati su ' . (int) $seat['capacity'] . ')</span></p>';
	}

	private static function door_form( array $s, array $a ): string {
		if ( ! current_user_can( 'apse_door_cash', (int) $a['id'] ) || ! empty( $s['cancelled_at'] ) ) {
			return '';
		}
		$seat = Plugin::activities()->seats( (int) $s['id'] );
		if ( 0 === $seat['free'] ) {
			return '<p class="apsf-small apsf-muted">Posti esauriti: nessun ingresso sul posto possibile.</p>';
		}
		$all = '<option value="">— scegli il socio —</option>';
		foreach ( Plugin::people()->search() as $p ) {
			if ( MemberType::is_member( $p['type'] ) ) {
				$all .= '<option value="' . (int) $p['id'] . '">' . esc_html( trim( $p['last_name'] . ' ' . $p['first_name'] ) ) . '</option>';
			}
		}
		$accs = '';
		foreach ( Plugin::ledger()->accounts() as $acc ) {
			if ( in_array( $acc['type'], array( 'cash', 'pos' ), true ) ) {
				$accs .= '<option value="' . (int) $acc['id'] . '">' . esc_html( $acc['name'] ) . '</option>';
			}
		}
		$ids = self::hidden( 'session_id', $s['id'] );
		return '<details class="apsf-door"><summary><strong>＋ Ingresso sul posto</strong> <span class="apsf-small apsf-muted">socio non prenotato (biglietto socio: ' . esc_html( Money::format( Plugin::activities()->fee_for( $a, MemberType::ORDINARY ) ) ) . ')</span></summary>'
			. self::form(
				'apse_front_door',
				$ids . '<label>Socio <select name="person_id" required>' . $all . '</select></label>'
				. '<label><input type="checkbox" name="pay" value="1" checked> Incassa il biglietto</label>'
				. ( '' !== $accs ? '<label>Pagamento <select name="account_id">' . $accs . '</select></label>' : '' ),
				'Prenota, incassa e registra l\'ingresso',
				true
			) . '</details>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function checkin_detail( array $s, array $a ): string {
		$svc      = Plugin::activities();
		$today    = current_time( 'Y-m-d' );
		$list     = '';
		$booked   = 0;
		$present  = 0;
		$unpaid   = 0;
		$cancel   = 0;
		$all_bookings = $svc->bookings_for_session( (int) $s['id'] );
		$gov          = Plugin::people()->guest_overview();
		foreach ( $all_bookings as $b ) {
			if ( ! $b['active'] ) {
				$cancel++;
				continue;
			}
			$booked++;
			$in    = ! empty( $b['checked_in_at'] );
			$present += $in ? 1 : 0;
			$host  = '';
			if ( MemberType::GUEST === $b['type'] && ! empty( $b['host_person_id'] ) ) {
				$h    = Plugin::people()->get( (int) $b['host_person_id'] );
				$host = $h ? ' di ' . $h['first_name'] . ' ' . $h['last_name'] : '';
			}
			if ( (int) $b['fee_due_cents'] <= 0 ) {
				$pay = '<span class="apsf-small apsf-muted">Gratuito</span>';
			} elseif ( (int) $b['remaining'] > 0 ) {
				$unpaid++;
				$pay = '<span class="apsf-bad apsf-small">Da versare ' . esc_html( Money::format( (int) $b['remaining'] ) ) . '</span>';
			} else {
				$pay = '<span class="apsf-good apsf-small">Versato</span>';
			}
			$ids = self::hidden( 'session_id', $s['id'] ) . self::hidden( 'person_id', $b['person_id'] );
			$act = $in
				? '<span class="apsf-small apsf-good">✔ ore ' . esc_html( mysql2date( 'H:i', $b['checked_in_at'] ) ) . '</span>' . self::form( 'apse_front_checkin', $ids . self::hidden( 'undo', 1 ), 'Annulla', true, 'apsf-inline' )
				: self::form( 'apse_front_checkin', $ids, 'Registra ingresso', false, 'apsf-inline' );
			$name = trim( $b['first_name'] . ' ' . $b['last_name'] );
			$list .= '<li class="apsf-booked" data-name="' . esc_attr( Text::normalize( $name ) ) . '" data-state="' . ( $in ? 'in' : 'out' ) . '"><div><strong>' . esc_html( $name ) . '</strong>'
				. '<div class="apsf-small apsf-muted">' . esc_html( MemberType::GUEST === $b['type'] ? 'Ospite' . $host : MemberType::label( $b['type'] ) ) . '</div>'
				. ( MemberType::GUEST === $b['type'] ? self::guest_note( $gov[ (int) $b['person_id'] ] ?? null ) : '' ) . $pay . '</div><div class="apsf-checkin-act">' . $act . '</div></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$can_scan = Settings::tickets_enabled() && ! empty( $a['booking_qr'] ) && $s['session_date'] === $today;
		$scan     = '';
		if ( $can_scan ) {
			$scan = '<div class="apsf-scan"><button type="button" class="apsf-btn" data-apsf-scan>📷 Scansiona il QR del biglietto</button> '
				. '<video class="apsf-scan-video" playsinline muted hidden></video><p class="apsf-small apsf-muted apsf-scan-msg">Oppure scansiona con la fotocamera del telefono: il QR apre la pagina dove registrare l\'ingresso.</p>'
				. self::form( 'apse_front_checkin_scan', '<input type="hidden" name="ticket" value="">', 'Registra', false, 'apsf-scan-form' ) . '</div>';
		}
		$html = '<section class="apsf-section apsf-checkin"><p><a href="' . esc_url( self::session_url() ) . '">← Tutti gli eventi</a></p>'
			. '<h3>' . esc_html( $a['name'] ) . '</h3><p><strong>' . esc_html( self::date_long( $s['session_date'] ) ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . '</strong>'
			. ( $s['location'] ? ' · ' . esc_html( $s['location'] ) : '' ) . '</p>'
			. '<dl class="apsf-dl apsf-counts"><div><dt>Prenotati</dt><dd>' . (int) $booked . ( null === $s['capacity'] ? '' : ' / ' . (int) $s['capacity'] ) . '</dd></div><div><dt>Presenti</dt><dd>' . (int) $present . '</dd></div>'
			. '<div><dt>Da registrare</dt><dd>' . (int) ( $booked - $present ) . '</dd></div><div><dt>Contributo da versare</dt><dd>' . (int) $unpaid . '</dd></div></dl>'
			. ( $s['session_date'] !== $today ? '<p class="apsf-small apsf-muted">Gli ingressi si registrano nel giorno dell\'evento.</p>' : '' ) . self::seats_html( $s ) . $scan . self::door_form( $s, $a ) . self::notice_form( $a, (int) $s['id'] );
		if ( ! $booked ) {
			return $html . '<p class="apsf-muted">Nessuna prenotazione.</p></section>';
		}
		return $html . '<div class="apsf-checkin-tools"><input type="search" class="apsf-search" placeholder="Cerca per nome" aria-label="Cerca per nome">'
			. '<span class="apsf-filters"><button type="button" class="apsf-chip is-on" data-filter="all">Tutti</button><button type="button" class="apsf-chip" data-filter="out">Da registrare</button><button type="button" class="apsf-chip" data-filter="in">Presenti</button></span></div>'
			. '<ul class="apsf-list apsf-booked-list">' . $list . '</ul>' // phpcs:ignore WordPress.Security.EscapeOutput
			. ( $cancel ? '<p class="apsf-small apsf-muted">' . (int) $cancel . ' prenotazioni annullate non sono in elenco.</p>' : '' ) . '</section>';
	}

	/**
	 * Nota su un ospite per chi accoglie all'ingresso: quante volte è venuto, se risulta registrato anche con altri nomi
	 * (stesso cellulare, email o nome) e se è da invitare a iscriversi. Nessun blocco: decide chi gestisce.
	 */
	public static function guest_note( ?array $ov ): string {
		if ( ! $ov ) {
			return '';
		}
		$txt = $ov['count'] . 'ª partecipazione come ospite';
		if ( $ov['twins'] ) {
			$names = array_map( function ( $t ) {
				return $t['name'] . ' (ospite di ' . $t['host'] . ', ' . $t['count'] . ')';
			}, $ov['twins'] );
			$txt  .= ' · risulta registrato anche come ' . implode( '; ', $names ) . ': in tutto ' . $ov['total'];
		}
		if ( $ov['flag'] ) {
			return '<div class="apsf-small apsf-bad">' . esc_html( $txt . ' — da invitare a iscriversi' ) . '</div>';
		}
		return '<div class="apsf-small ' . ( $ov['twins'] ? 'apsf-bad' : 'apsf-muted' ) . '">' . esc_html( $txt ) . '</div>';
	}

	// ---------- Avvisi agli iscritti ----------

	/** Modulo "Invia un avviso agli iscritti" (vuoto se l'utente non può inviarne per quell'attività). */
	private static function notice_form( array $a, ?int $session_id = null ): string {
		$aid = (int) $a['id'];
		if ( ! \ApSemplice\Notices::can_send( $aid ) ) {
			return '';
		}
		$count  = count( \ApSemplice\Notices::recipients( $aid, $session_id ) );
		$fields = self::hidden( 'activity_id', $aid ) . ( $session_id ? self::hidden( 'session_id', $session_id ) : '' ) . '<div class="apsf-fields">';
		if ( ! $session_id && ActivityKind::uses_sessions( $a['kind'] ) ) {
			$opts = '<option value="">Tutti i prenotati</option>';
			foreach ( Plugin::activities()->sessions( $aid ) as $s ) {
				if ( empty( $s['cancelled_at'] ) && $s['session_date'] >= current_time( 'Y-m-d' ) ) {
					$opts .= '<option value="' . (int) $s['id'] . '">Solo ' . esc_html( self::date_long( $s['session_date'] ) ) . '</option>';
				}
			}
			$fields .= '<label>A chi <select name="session_id">' . $opts . '</select></label>';
		}
		$fields .= '<label>Titolo <input type="text" name="subject" maxlength="' . \ApSemplice\Notices::MAX_SUBJECT . '" required></label>'
			. '<label>Messaggio <textarea name="body" rows="4" maxlength="' . \ApSemplice\Notices::MAX_BODY . '" required></textarea></label></div>';
		return '<details class="apsf-details"><summary>Invia un avviso agli iscritti</summary>'
			. '<p class="apsf-small apsf-muted">Arriva per email a chi è iscritto' . ( $session_id ? ' a questa data' : '' ) . ' (ora ' . (int) $count . ' persone; gli ospiti senza email lo ricevono tramite il socio che li ospita) e resta nella loro bacheca. Usalo per cambi dell\'ultimo momento.</p>'
			. self::form( 'apse_front_notice', $fields, 'Invia avviso', true ) . '</details>';
	}

	/** Bacheca degli avvisi per i soci (e per chi ha ospiti iscritti): quelli recenti delle attività a cui partecipano. */
	public static function section_notices( array $p ): string {
		$list = \ApSemplice\Notices::board( (int) $p['id'], 8 );
		if ( ! $list ) {
			return '';
		}
		$html = '<section class="apsf-section apsf-notices"><h3>Avvisi</h3><ul class="apsf-list">';
		foreach ( $list as $n ) {
			$html .= '<li><div><strong>' . esc_html( $n['subject'] ) . '</strong><div class="apsf-small apsf-muted">' . esc_html( $n['activity_name'] ) . ' · ' . esc_html( mysql2date( 'd/m/Y H:i', $n['created_at'] ) )
				. ' · ' . esc_html( $n['author_name'] ) . '</div><div>' . nl2br( esc_html( $n['body'] ) ) . '</div></div></li>';
		}
		return $html . '</ul></section>';
	}

	/** Calendario del mese: lezioni dei corsi e date degli eventi, con in evidenza le attività a cui partecipa il socio. */
	public static function section_calendar( array $p ): string {
		$month = isset( $_GET['apsf_m'] ) ? sanitize_text_field( wp_unslash( $_GET['apsf_m'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ) {
			$month = substr( current_time( 'Y-m-d' ), 0, 7 );
		}
		$first = new \DateTimeImmutable( $month . '-01' );
		$mine  = array_map( 'intval', Plugin::activities()->person_activity_ids( (int) $p['id'] ) );
		$by    = array();
		foreach ( \ApSemplice\Calendar::occurrences( $first->format( 'Y-m-d' ), $first->modify( 'last day of this month' )->format( 'Y-m-d' ) ) as $o ) {
			$by[ $o['date'] ][] = $o;
		}
		$prev = $first->modify( '-1 month' )->format( 'Y-m' );
		$next = $first->modify( '+1 month' )->format( 'Y-m' );
		$html = '<section class="apsf-section apsf-calendar"><h3>Calendario</h3><p class="apsf-small">'
			. '<a href="' . esc_url( add_query_arg( 'apsf_m', $prev ) ) . '">&lsaquo; ' . esc_html( self::MONTHS[ (int) $first->modify( '-1 month' )->format( 'n' ) ] ) . '</a> &nbsp; <strong>'
			. esc_html( self::MONTHS[ (int) $first->format( 'n' ) ] . ' ' . $first->format( 'Y' ) ) . '</strong> &nbsp; '
			. '<a href="' . esc_url( add_query_arg( 'apsf_m', $next ) ) . '">' . esc_html( self::MONTHS[ (int) $first->modify( '+1 month' )->format( 'n' ) ] ) . ' &rsaquo;</a></p>';
		if ( ! $by ) {
			$html .= '<p class="apsf-muted">Nessuna lezione o evento in questo mese.</p>';
		} else {
			$html .= '<ul class="apsf-list">';
			foreach ( $by as $date => $items ) {
				$html .= '<li><div><strong>' . esc_html( self::date_long( $date ) ) . '</strong>';
				foreach ( $items as $o ) {
					$html .= '<div class="apsf-small">' . ( $o['start'] ? esc_html( $o['start'] . ( $o['end'] ? '-' . $o['end'] : '' ) ) . ' &middot; ' : '' ) . esc_html( $o['title'] )
						. ( $o['location'] ? ' <span class="apsf-muted">&middot; ' . esc_html( $o['location'] ) . '</span>' : '' )
						. ( in_array( (int) $o['activity_id'], $mine, true ) ? ' <strong>&middot; la tua</strong>' : '' ) . '</div>';
				}
				$html .= '</div></li>';
			}
			$html .= '</ul>';
		}
		if ( \ApSemplice\Calendar::enabled() ) {
			$feed = \ApSemplice\Calendar::feed_url();
			$html .= '<p class="apsf-small"><a href="' . esc_url( \ApSemplice\Calendar::google_add_url( $feed ) ) . '" target="_blank" rel="noopener">Aggiungi a Google Calendar</a> &middot; <a href="' . esc_url( \ApSemplice\Calendar::webcal_url( $feed ) ) . '">Apple / Outlook</a></p>';
		}
		return $html . '</section>';
	}

	public static function calendar(): string {
		return self::with_person( array( __CLASS__, 'section_calendar' ) );
	}

	public static function notices(): string {
		return self::with_person(
			function ( $p ) {
				$html = self::section_notices( $p );
				return '' !== $html ? $html : self::notice( 'Nessun avviso recente.' );
			}
		);
	}

	public static function checkin(): string {
		return self::with_person(
			function ( $p ) {
				$html = self::section_checkin( $p );
				return '' !== $html ? $html : self::notice( 'Non gestisci nessun evento.' );
			}
		);
	}

	public static function section_volunteer( array $p ): string {
		if ( ! MemberType::can_teach( $p['type'] ) ) {
			return '';
		}
		$svc   = Plugin::activities();
		$html  = '<section class="apsf-section"><h3>Le attività che tengo</h3>';
		$found = false;
		foreach ( $svc->taught_activity_ids( (int) $p['id'] ) as $aid ) {
			$a = $svc->get( $aid );
			if ( ! $a || ! current_user_can( 'apse_view_participants', $aid ) || $a['social_year'] !== Settings::social_year()->label() ) {
				continue;
			}
			$found = true;
			$html .= '<div class="apsf-card"><h4>' . esc_html( $a['name'] ) . ' <span class="apsf-badge">' . esc_html( ActivityKind::short_label( $a['kind'] ) ) . '</span></h4>';
			if ( ActivityKind::uses_sessions( $a['kind'] ) ) {
				$sessions = array_filter( $svc->sessions( $aid ), function ( $s ) {
					return empty( $s['cancelled_at'] ) && $s['session_date'] >= current_time( 'Y-m-d' );
				} );
				if ( ! $sessions ) {
					$html .= '<p class="apsf-muted">Nessuna data in programma.</p>';
				}
				foreach ( array_slice( $sessions, 0, 6 ) as $s ) {
					$names = array();
					foreach ( $svc->bookings_for_session( (int) $s['id'] ) as $b ) {
						if ( $b['active'] ) {
							$names[] = $b['first_name'] . ' ' . $b['last_name'] . ( MemberType::GUEST === $b['type'] ? ' (ospite)' : '' );
						}
					}
					$html .= '<p><strong>' . esc_html( self::date_long( $s['session_date'] ) ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . '</strong> — '
						. count( $names ) . ( null === $s['capacity'] ? ' prenotati' : ' / ' . (int) $s['capacity'] . ' posti' ) . '<br><span class="apsf-small">' . esc_html( $names ? implode( ', ', $names ) : 'Nessuna prenotazione' ) . '</span></p>';
				}
			} else {
				$names = array();
				foreach ( $svc->status_for_activity( $aid ) as $s ) {
					if ( null === $s['enrollment']['end_month'] ) {
						$names[] = $s['enrollment']['first_name'] . ' ' . $s['enrollment']['last_name'] . ( MemberType::GUEST === $s['enrollment']['type'] ? ' (ospite)' : '' );
					}
				}
				$html .= '<p>' . count( $names ) . ' iscritti<br><span class="apsf-small">' . esc_html( $names ? implode( ', ', $names ) : 'Nessun iscritto' ) . '</span></p>';
			}
			$html .= self::notice_form( $a );
			$html .= '</div>';
		}
		if ( ! $found ) {
			$html .= '<p class="apsf-muted">Non risulti referente di attività dell\'anno sociale in corso.</p>';
		}
		return $html . '</section>';
	}

	// ---------- Viste complete (usate da shortcode, blocchi, widget) ----------

	public static function area( array $atts = array() ): string {
		$sections = array_filter( array_map( 'trim', explode( ',', (string) ( $atts['sezioni'] ?? 'tessera,attivita,calendario,avvisi,pagamenti,ospiti,profilo,volontario,ingressi,spese' ) ) ) );
		return self::with_person(
			function ( $p ) use ( $sections ) {
				$map  = array(
					'tessera'    => 'section_card', 'attivita' => 'section_activities', 'calendario' => 'section_calendar', 'pagamenti' => 'section_pay', 'ospiti' => 'section_guests',
					'profilo'    => 'section_profile', 'volontario' => 'section_volunteer', 'spese' => 'section_expenses', 'ingressi' => 'section_checkin', 'avvisi' => 'section_notices',
				);
				$html = '<div class="apsf-hello">Ciao <strong>' . esc_html( $p['first_name'] ) . '</strong></div><div class="apsf-area">';
				foreach ( $sections as $s ) {
					if ( isset( $map[ $s ] ) ) {
						$html .= self::{$map[ $s ]}( $p );
					}
				}
				return $html . '</div>';
			},
			'apsf-area-wrap'
		);
	}

	public static function card(): string {
		return self::with_person( array( __CLASS__, 'section_card' ) );
	}

	public static function my_activities(): string {
		return self::with_person( array( __CLASS__, 'section_activities' ) );
	}

	public static function guests(): string {
		return self::with_person( array( __CLASS__, 'section_guests' ) );
	}

	public static function profile(): string {
		return self::with_person( array( __CLASS__, 'section_profile' ) );
	}

	public static function volunteer(): string {
		return self::with_person(
			function ( $p ) {
				return MemberType::can_teach( $p['type'] ) ? self::section_volunteer( $p ) : self::notice( 'Quest\'area è riservata ai soci e volontari.' );
			}
		);
	}

	// ---------- Pagine pubbliche: attività ed eventi ----------

	/** Chi sta guardando e chi può prenotare (sé stesso e i propri ospiti). */
	private static function booking_context(): array {
		$ctx = array( 'logged' => is_user_logged_in(), 'can_book' => false, 'people' => array(), 'suspended' => false );
		if ( ! $ctx['logged'] ) {
			return $ctx;
		}
		if ( ! Access::is_admin_user( get_current_user_id() ) && ! License::allows( 'member_area' ) ) {
			$ctx['suspended'] = true;
			return $ctx;
		}
		$p = Access::current_person();
		if ( $p && MemberType::is_member( $p['type'] ) ) {
			$ctx['actor']    = $p;
			$ctx['can_book'] = Plugin::people()->is_active_member( (int) $p['id'] );
			$ctx['people']   = array_merge( array( $p ), Plugin::people()->guests_of( (int) $p['id'] ) );
		}
		return $ctx;
	}

	private static function session_row( array $s, array $activity, array $ctx, string $prefix = '' ): string {
		$svc    = Plugin::activities();
		$cap    = null === $s['capacity'] ? null : (int) $s['capacity'];
		$booked = (int) ( $s['booked_count'] ?? 0 );
		$full   = null !== $cap && $booked >= $cap;
		$html   = '<li><div>' . $prefix . '<strong>' . esc_html( self::date_long( $s['session_date'] ) ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . '</strong>'
			. ( $s['location'] ? '<div class="apsf-small">' . esc_html( $s['location'] ) . '</div>' : '' )
			. ( null !== $cap ? '<div class="apsf-small">' . ( $full ? 'Posti esauriti' : max( 0, $cap - $booked ) . ' posti liberi' ) . '</div>' : '' ) . '</div><div class="apsf-actions">';
		if ( ! $ctx['logged'] ) {
			$html .= '<a class="apsf-btn wp-element-button" href="' . esc_url( wp_login_url( Restrict::current_url() ) ) . '">Accedi per prenotarti</a>';
		} elseif ( ! empty( $ctx['can_book'] ) ) {
			$free = array();
			foreach ( $ctx['people'] as $person ) {
				if ( $svc->has_active_booking( (int) $s['id'], (int) $person['id'] ) ) {
					$html .= '<span class="apsf-badge apsf-badge-ok">✓ ' . esc_html( $person['first_name'] ) . '</span> '
						. ( $svc->cancellation_for( (int) $s['id'], (int) $person['id'] )['allowed']
							? self::form( 'apse_front_cancel_booking', self::hidden( 'session_id', $s['id'] ) . self::hidden( 'person_id', $person['id'] ), 'Annulla', true, 'apsf-inline' )
							: '<span class="apsf-small apsf-muted">non annullabile (gestiscila nella tua area)</span>' );
				} else {
					$free[] = $person;
				}
			}
			if ( $free && ! $full ) {
				$fields = '';
				if ( count( $free ) > 1 ) {
					$fields .= '<select name="person_id" aria-label="Chi prenoti">';
					foreach ( $free as $person ) {
						$fee     = $svc->fee_for( $activity, $person['type'] );
						$fields .= '<option value="' . (int) $person['id'] . '">' . esc_html( $person['first_name'] . ' — ' . ( 0 === $fee ? 'gratuito' : Money::format( $fee ) ) ) . '</option>';
					}
					$fields .= '</select>';
				} else {
					$fields .= self::hidden( 'person_id', $free[0]['id'] );
				}
				$html .= self::form( 'apse_front_book', self::hidden( 'session_id', $s['id'] ) . $fields, 'Prenotati', false, 'apsf-inline' );
			}
		} elseif ( ! empty( $ctx['actor'] ) ) {
			$html .= '<span class="apsf-small apsf-muted">Rinnova la tessera per prenotarti</span>';
		}
		return $html . '</div></li>';
	}

	/**
	 * Elenco delle attività dell'anno sociale (o una sola con id=…): corsi con quota, eventi con le prossime date
	 * e il pulsante per prenotarsi (soci con tessera valida, anche per i propri ospiti).
	 */
	public static function activities( array $atts = array() ): string {
		$svc   = Plugin::activities();
		$label = ! empty( $atts['anno'] ) ? (string) $atts['anno'] : Settings::social_year()->label();
		$kind  = self::kind_from_text( (string) ( $atts['tipo'] ?? '' ) );
		$max   = max( 1, (int) ( $atts['date'] ?? 5 ) );
		if ( ! empty( $atts['id'] ) ) {
			$one  = $svc->get( (int) $atts['id'] );
			$list = $one ? array( array_merge( $one, array( 'instructor_name' => self::person_name( (int) $one['instructor_person_id'] ) ) ) ) : array();
		} else {
			$list = array_values(
				array_filter(
					$svc->for_year( $label ),
					function ( $a ) use ( $kind ) {
						return null === $kind || $a['kind'] === $kind;
					}
				)
			);
		}
		if ( ! $list ) {
			return self::wrap( '<p class="apsf-muted">Nessuna attività da mostrare.</p>' );
		}
		$ctx  = self::booking_context();
		$mine = ! empty( $ctx['actor'] ) ? $svc->person_activity_ids( (int) $ctx['actor']['id'] ) : array();
		$html = '<div class="apsf-grid">';
		foreach ( $list as $a ) {
			$html .= '<article class="apsf-card"><span class="apsf-badge">' . esc_html( ActivityKind::short_label( $a['kind'] ) ) . '</span>'
				. '<h3>' . esc_html( $a['name'] ) . '</h3><p class="apsf-small apsf-muted">' . esc_html( self::fee_text( $a ) )
				. ( ! empty( $a['instructor_name'] ) ? '<br>Con ' . esc_html( $a['instructor_name'] ) : '' ) . '</p>';
			if ( ! empty( $a['notes'] ) ) {
				$html .= '<p>' . esc_html( $a['notes'] ) . '</p>';
			}
			if ( ActivityKind::uses_sessions( $a['kind'] ) ) {
				$sessions = array_values(
					array_filter(
						$svc->sessions( (int) $a['id'] ),
						function ( $s ) {
							return empty( $s['cancelled_at'] ) && $s['session_date'] >= current_time( 'Y-m-d' );
						}
					)
				);
				if ( ! $sessions ) {
					$html .= '<p class="apsf-muted">Nessuna data in programma.</p>';
				} else {
					$html .= '<ul class="apsf-list apsf-sessions">';
					foreach ( array_slice( $sessions, 0, $max ) as $s ) {
						$html .= self::session_row( $s, $a, $ctx );
					}
					$html .= '</ul>';
				}
			} elseif ( in_array( (int) $a['id'], $mine, true ) ) {
				$html .= '<p><span class="apsf-badge apsf-badge-ok">✓ Sei iscritto</span></p>';
			}
			$html .= '</article>';
		}
		return self::wrap( $html . '</div>' );
	}

	/** Le prossime date di tutti gli eventi, in ordine di data. */
	public static function upcoming( array $atts = array() ): string {
		$rows = Plugin::activities()->upcoming_sessions( max( 1, min( 50, (int) ( $atts['limite'] ?? 5 ) ) ) );
		if ( ! $rows ) {
			return self::wrap( '<p class="apsf-muted">Nessun evento in programma.</p>' );
		}
		$ctx   = self::booking_context();
		$book  = ! isset( $atts['prenotazione'] ) || 'no' !== strtolower( (string) $atts['prenotazione'] );
		$html  = '<ul class="apsf-list apsf-upcoming">';
		foreach ( $rows as $s ) {
			if ( $book ) {
				$html .= self::session_row( $s, array_merge( $s, array( 'id' => $s['activity_id'] ) ), $ctx, '<div class="apsf-evt">' . esc_html( $s['activity_name'] ) . '</div>' ); // phpcs:ignore WordPress.Security.EscapeOutput
			} else {
				$html .= '<li><div><div class="apsf-evt">' . esc_html( $s['activity_name'] ) . '</div><strong>' . esc_html( self::date_long( $s['session_date'] ) ) . ( $s['start_time'] ? ' · ore ' . esc_html( $s['start_time'] ) : '' ) . '</strong>'
					. ( $s['location'] ? '<div class="apsf-small">' . esc_html( $s['location'] ) . '</div>' : '' ) . '</div></li>';
			}
		}
		return self::wrap( $html . '</ul>' );
	}

	private static function kind_from_text( string $t ): ?string {
		$map = array( 'corso' => ActivityKind::COURSE, 'corsi' => ActivityKind::COURSE, 'course' => ActivityKind::COURSE, 'evento' => ActivityKind::EVENT, 'eventi' => ActivityKind::EVENT,
			'event' => ActivityKind::EVENT, 'ricorrente' => ActivityKind::RECURRING, 'ricorrenti' => ActivityKind::RECURRING, 'recurring' => ActivityKind::RECURRING );
		return $map[ strtolower( trim( $t ) ) ] ?? null;
	}

	private static function person_name( int $id ): string {
		$p = $id ? Plugin::people()->get( $id ) : null;
		return $p ? trim( $p['first_name'] . ' ' . $p['last_name'] ) : '';
	}
}
