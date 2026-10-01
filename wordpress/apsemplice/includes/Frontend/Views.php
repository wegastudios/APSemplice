<?php
namespace ApSemplice\Frontend;

use ApSemplice\Access;
use ApSemplice\ActivityKind;
use ApSemplice\License;
use ApSemplice\MemberType;
use ApSemplice\Money;
use ApSemplice\Plugin;
use ApSemplice\Pricing;
use ApSemplice\Settings;

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
		$ok  = isset( $_GET['apsf_ok'] ) ? sanitize_text_field( wp_unslash( $_GET['apsf_ok'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$err = isset( $_GET['apsf_err'] ) ? sanitize_text_field( wp_unslash( $_GET['apsf_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
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
		$unit  = ActivityKind::fee_unit( $a['kind'] );
		$txt   = 0 === $fee ? 'Gratuito per i soci' : 'Soci ' . Money::format( $fee ) . ' ' . $unit;
		if ( null !== $guest ) {
			$txt .= ' · ' . ( 0 === $guest ? 'ospiti: gratuito' : 'ospiti ' . Money::format( $guest ) . ' ' . $unit );
		} elseif ( $fee > 0 ) {
			$txt .= ' · ospiti: uguale';
		}
		return $txt;
	}

	private static function form( string $action, string $fields_html, string $button, bool $confirm = false, string $class = '' ): string {
		$html  = '<form class="apsf-form ' . esc_attr( $class ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
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
			. $form . '<p class="apsf-small"><a href="' . esc_url( wp_lostpassword_url( Restrict::current_url() ) ) . '">Password dimenticata?</a></p></div>'
		);
	}

	/** Esegue $fn( persona ) solo se l'utente è collegato e ha una scheda socio; altrimenti mostra il messaggio giusto. */
	private static function with_person( callable $fn, string $class = '' ): string {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt();
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
			. '</div></section>';
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
					. self::booking_controls( $b, $p ) . '</li>';
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
			$html .= '<p class="apsf-small apsf-muted">' . esc_html( (string) apply_filters( 'aps_payment_hint', 'Il pagamento si effettua in sede presso la segreteria.' ) ) . '</p>';
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

	/** Annulla (se la regola lo consente) e Cambia nominativo di una prenotazione dell'area soci. */
	private static function booking_controls( array $b, array $actor ): string {
		$svc  = Plugin::activities();
		$sid  = (int) $b['session_id'];
		$pid  = (int) $b['person_id'];
		$ev   = $svc->cancellation_for( $sid, $pid );
		$html = '<div class="apsf-manage">';
		if ( $ev['allowed'] ) {
			$html .= '<div class="apsf-small apsf-muted">' . esc_html( $ev['message'] ) . '</div>'
				. self::form( 'aps_front_cancel_booking', self::hidden( 'session_id', $sid ) . self::hidden( 'person_id', $pid ), 'Annulla prenotazione', true, 'apsf-inline' );
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
				. '<label>' . ( '' !== $options ? 'oppure nuovo ospite: nome' : 'Nuovo ospite: nome' ) . ' <input type="text" name="new_first_name"></label><label>Cognome <input type="text" name="new_last_name"></label></div>';
			$html  .= '<details class="apsf-details"><summary>Cambia nominativo</summary>'
				. '<p class="apsf-small apsf-muted">Se il nuovo partecipante ha un contributo diverso (ad esempio un ospite) la differenza va integrata.</p>'
				. self::form( 'aps_front_transfer_booking', self::hidden( 'session_id', $sid ) . self::hidden( 'person_id', $pid ) . $fields, 'Cambia nominativo' ) . '</details>';
		}
		return $html . '</div>';
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
			foreach ( $guests as $g ) {
				$html .= '<li><strong>' . esc_html( $g['first_name'] . ' ' . $g['last_name'] ) . '</strong></li>';
			}
			$html .= '</ul>';
		}
		$fields = '<div class="apsf-fields"><label>Nome <input type="text" name="first_name" required></label><label>Cognome <input type="text" name="last_name" required></label>'
			. '<label>Email (facoltativa) <input type="email" name="email"></label><label>Telefono (facoltativo) <input type="text" name="phone"></label></div>';
		return $html . '<details class="apsf-details"><summary>Aggiungi un ospite</summary>' . self::form( 'aps_front_add_guest', $fields, 'Aggiungi ospite' ) . '</details></section>';
	}

	public static function section_profile( array $p ): string {
		$fields = '<div class="apsf-fields"><label>Telefono <input type="text" name="phone" value="' . esc_attr( (string) $p['phone'] ) . '"></label>'
			. '<label>Codice fiscale <input type="text" name="tax_code" value="' . esc_attr( (string) $p['tax_code'] ) . '"></label></div>';
		return '<section class="apsf-section"><h3>Il mio profilo</h3><dl class="apsf-dl"><div><dt>Nome</dt><dd>' . esc_html( $p['first_name'] . ' ' . $p['last_name'] ) . '</dd></div>'
			. '<div><dt>Email</dt><dd>' . esc_html( (string) $p['email'] ) . '</dd></div></dl>'
			. '<p class="apsf-small apsf-muted">Per cambiare nome o email scrivi all\'associazione.</p>'
			. self::form( 'aps_front_profile', $fields, 'Salva' ) . '</section>';
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
			if ( ! $a || ! current_user_can( 'aps_view_participants', $aid ) || $a['social_year'] !== Settings::social_year()->label() ) {
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
			$html .= '</div>';
		}
		if ( ! $found ) {
			$html .= '<p class="apsf-muted">Non risulti istruttore di attività dell\'anno sociale in corso.</p>';
		}
		return $html . '</section>';
	}

	// ---------- Viste complete (usate da shortcode, blocchi, widget) ----------

	public static function area( array $atts = array() ): string {
		$sections = array_filter( array_map( 'trim', explode( ',', (string) ( $atts['sezioni'] ?? 'tessera,attivita,ospiti,profilo,volontario' ) ) ) );
		return self::with_person(
			function ( $p ) use ( $sections ) {
				$map  = array(
					'tessera'    => 'section_card', 'attivita' => 'section_activities', 'ospiti' => 'section_guests',
					'profilo'    => 'section_profile', 'volontario' => 'section_volunteer',
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
							? self::form( 'aps_front_cancel_booking', self::hidden( 'session_id', $s['id'] ) . self::hidden( 'person_id', $person['id'] ), 'Annulla', true, 'apsf-inline' )
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
				$html .= self::form( 'aps_front_book', self::hidden( 'session_id', $s['id'] ) . $fields, 'Prenotati', false, 'apsf-inline' );
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
		$rows = Plugin::activities()->upcoming_sessions( max( 1, (int) ( $atts['limite'] ?? 5 ) ) );
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
