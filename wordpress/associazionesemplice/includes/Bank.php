<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Pagamento con bonifico: l'IBAN (o gli IBAN) dell'associazione si mostrano ai soci nell'area riservata, si possono mandare per email al socio
 * e (se si vuole) si aggiungono ai promemoria di pagamento. Funziona da solo o insieme ai pagamenti online. Spento di default.
 *
 * Sicurezza: le coordinate si modificano solo dagli amministratori (Impostazioni → Pagamenti online); ogni modifica finisce nel registro azioni
 * con l'IBAN mascherato e avvisa per email gli amministratori, così un accesso rubato non può cambiare il conto in silenzio.
 * Le email con le coordinate vanno sempre e solo all'indirizzo del socio.
 */
final class Bank {

	const MAX_ACCOUNTS = 5;

	const DEFAULT_TITLE = 'Pagamento con bonifico';
	const DEFAULT_NOTE  = 'Indica nella causale il tuo nome e cognome e il motivo del pagamento. Non appena riceviamo il bonifico, registriamo il pagamento.';

	// ---------- Conti ----------

	/**
	 * Ripulisce l'elenco dei conti: solo IBAN validi, campi accorciati, al massimo MAX_ACCOUNTS.
	 *
	 * @param array[] $rows label, holder, iban, bic, bank, note
	 * @param bool    $strict se true un IBAN non valido è un errore (con il numero della riga) invece di essere scartato
	 * @return array[]
	 * @throws \InvalidArgumentException
	 */
	public static function sanitize_accounts( array $rows, bool $strict = false ): array {
		$out = array();
		$n   = 0;
		foreach ( $rows as $r ) {
			$n++;
			if ( ! is_array( $r ) ) {
				continue;
			}
			$raw = trim( (string) ( $r['iban'] ?? '' ) );
			if ( '' === $raw ) {
				continue;
			}
			if ( ! Iban::is_valid( $raw ) ) {
				if ( $strict ) {
					throw new \InvalidArgumentException( 'Conto ' . $n . ': l\'IBAN non è valido (controlla le cifre).' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				continue;
			}
			if ( count( $out ) >= self::MAX_ACCOUNTS ) {
				if ( $strict ) {
					throw new \InvalidArgumentException( 'Si possono indicare al massimo ' . self::MAX_ACCOUNTS . ' conti.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				break;
			}
			$clean = function ( string $k, int $max ) use ( $r ) {
				return mb_substr( trim( wp_strip_all_tags( (string) ( $r[ $k ] ?? '' ) ) ), 0, $max );
			};
			$out[] = array(
				'label'  => $clean( 'label', 60 ),
				'holder' => $clean( 'holder', 120 ),
				'iban'   => Iban::normalize( $raw ),
				'bic'    => mb_substr( (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) ( $r['bic'] ?? '' ) ) ), 0, 11 ),
				'bank'   => $clean( 'bank', 80 ),
				'note'   => $clean( 'note', 200 ),
			);
		}
		return $out;
	}

	/** @return array[] conti validi salvati nelle impostazioni */
	public static function accounts(): array {
		$v = Settings::get( 'bank_accounts' );
		return self::sanitize_accounts( is_array( $v ) ? $v : array() );
	}

	/** Attivo se acceso nelle impostazioni e con almeno un conto valido. */
	public static function enabled(): bool {
		return ! empty( Settings::get( 'bank_enabled' ) ) && (bool) self::accounts();
	}

	public static function title(): string {
		$t = trim( (string) Settings::get( 'bank_title' ) );
		return '' !== $t ? $t : self::DEFAULT_TITLE;
	}

	public static function note(): string {
		$t = trim( (string) Settings::get( 'bank_note' ) );
		return '' !== $t ? $t : self::DEFAULT_NOTE;
	}

	// ---------- Causale ----------

	/** Causale suggerita (al massimo 140 caratteri, come nei bonifici SEPA): «Rossi Mario – Quota associativa 2026, …». */
	public static function reason( array $person, array $dues = array() ): string {
		$who   = trim( (string) ( $person['last_name'] ?? '' ) . ' ' . (string) ( $person['first_name'] ?? '' ) );
		$names = array();
		foreach ( $dues as $i ) {
			$names[] = (string) ( $i['label'] ?? '' );
		}
		$names = array_values( array_filter( $names ) );
		$txt   = $who . ( $names ? ' - ' . implode( ', ', $names ) : '' );
		return mb_substr( $txt, 0, 140 );
	}

	// ---------- Testi ----------

	/** Le coordinate in testo semplice (per le email). */
	public static function text_block( array $person = array(), array $dues = array() ): string {
		$lines = array( self::title() . ':' );
		foreach ( self::accounts() as $a ) {
			$lines[] = '';
			if ( '' !== $a['label'] ) {
				$lines[] = $a['label'];
			}
			if ( '' !== $a['holder'] ) {
				$lines[] = 'Intestato a: ' . $a['holder'];
			}
			$lines[] = 'IBAN: ' . Iban::format( $a['iban'] );
			if ( '' !== $a['bic'] ) {
				$lines[] = 'BIC/SWIFT: ' . $a['bic'];
			}
			if ( '' !== $a['bank'] ) {
				$lines[] = 'Banca: ' . $a['bank'];
			}
			if ( '' !== $a['note'] ) {
				$lines[] = $a['note'];
			}
		}
		if ( $person ) {
			$lines[] = '';
			$lines[] = 'Causale: ' . self::reason( $person, $dues );
		}
		$lines[] = '';
		$lines[] = self::note();
		return implode( "\n", $lines );
	}

	/** Riquadro con i conti (e la causale, se c'è un socio): intestazione, IBAN con il pulsante «Copia». Già escapato. */
	public static function html_accounts( array $person = array(), array $dues = array() ): string {
		$html = '<div class="asemf-bank"><h4>' . esc_html( self::title() ) . '</h4>';
		foreach ( self::accounts() as $a ) {
			$html .= '<div class="asemf-bank-acc">' . ( '' !== $a['label'] ? '<strong>' . esc_html( $a['label'] ) . '</strong>' : '' ) . '<dl class="asemf-bank-data">';
			if ( '' !== $a['holder'] ) {
				$html .= '<div><dt>Intestato a</dt><dd>' . esc_html( $a['holder'] ) . '</dd></div>';
			}
			$html .= '<div><dt>IBAN</dt><dd><code class="asemf-iban">' . esc_html( Iban::format( $a['iban'] ) ) . '</code> <button type="button" class="asemf-copy" data-asemf-copy="' . esc_attr( $a['iban'] ) . '">Copia</button></dd></div>';
			if ( '' !== $a['bic'] ) {
				$html .= '<div><dt>BIC/SWIFT</dt><dd>' . esc_html( $a['bic'] ) . '</dd></div>';
			}
			if ( '' !== $a['bank'] ) {
				$html .= '<div><dt>Banca</dt><dd>' . esc_html( $a['bank'] ) . '</dd></div>';
			}
			$html .= '</dl>' . ( '' !== $a['note'] ? '<p class="asemf-small">' . esc_html( $a['note'] ) . '</p>' : '' ) . '</div>';
		}
		if ( $person ) {
			$reason = self::reason( $person, $dues );
			$html  .= '<p>Causale suggerita: <code>' . esc_html( $reason ) . '</code> <button type="button" class="asemf-copy" data-asemf-copy="' . esc_attr( $reason ) . '">Copia</button></p>';
		}
		return $html . '<p class="asemf-small asemf-muted">' . esc_html( self::note() ) . '</p></div>';
	}

	// ---------- Invio per email ----------

	/**
	 * Manda le coordinate (e la causale) all'email del socio, e solo a quella. Al massimo qualche invio all'ora per utente.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function send_to_member( array $person, array $dues, int $user_id ): void {
		if ( ! self::enabled() ) {
			throw new \InvalidArgumentException( 'Il pagamento con bonifico non è attivo.' );
		}
		$email = (string) ( $person['email'] ?? '' );
		if ( '' === $email || ! is_email( $email ) ) {
			throw new \InvalidArgumentException( 'Non risulta un\'email per il tuo profilo: chiedi alla segreteria di aggiungerla.' );
		}
		$key  = 'asem_bank_mail_' . $user_id;
		$sent = (int) get_transient( $key );
		if ( $sent >= Limits::get( 'bank_email_per_hour' ) ) {
			throw new \InvalidArgumentException( 'Hai già richiesto le coordinate più volte: riprova tra un po\'. Le trovi anche in questa pagina.' );
		}
		set_transient( $key, $sent + 1, HOUR_IN_SECONDS );
		$assoc = (string) Settings::get( 'association_name' );
		$total = PaymentItems::total( $dues );
		$body  = 'Ciao ' . $person['first_name'] . ",\n\necco le coordinate per pagare con bonifico" . ( '' !== $assoc ? ' a ' . $assoc : '' ) . ".\n\n";
		if ( $dues ) {
			$body .= "Da pagare:\n";
			foreach ( $dues as $i ) {
				$body .= '- ' . PaymentItems::line_name( $i ) . ': ' . Money::format( (int) $i['amount_cents'] ) . "\n";
			}
			$body .= 'Totale: ' . Money::format( $total ) . "\n\n";
		}
		$body .= self::text_block( $person, $dues ) . "\n\nSe non hai chiesto tu questo messaggio, ignoralo: le coordinate sono quelle dell'associazione.";
		if ( ! Texts::mail( $email, 'Coordinate per il bonifico' . ( '' !== $assoc ? ' — ' . $assoc : '' ), $body ) ) {
			throw new \InvalidArgumentException( 'Non è stato possibile inviare l\'email: riprova più tardi.' );
		}
		Audit::log( 'bank.emailed', 'person', (int) $person['id'] );
	}

	// ---------- Modifiche alle coordinate ----------

	/** Firma delle coordinate (per accorgersi di un cambio): solo gli IBAN, in ordine. */
	public static function signature( array $accounts ): string {
		return implode( ',', array_map( function ( $a ) {
			return Iban::normalize( (string) ( $a['iban'] ?? '' ) );
		}, $accounts ) );
	}

	/**
	 * Registra il cambio dei conti (IBAN mascherati) e avvisa per email gli amministratori: un accesso rubato non può cambiare il conto in silenzio.
	 *
	 * @param array[] $old conti prima
	 * @param array[] $new conti dopo
	 */
	public static function notify_change( array $old, array $new ): void {
		if ( self::signature( $old ) === self::signature( $new ) ) {
			return;
		}
		$mask = function ( array $list ) {
			return array_map( function ( $a ) {
				return Iban::mask( (string) ( $a['iban'] ?? '' ) );
			}, $list );
		};
		Audit::log( 'bank.changed', 'settings', 0, array( 'prima' => $mask( $old ), 'dopo' => $mask( $new ) ) );
		$user  = wp_get_current_user();
		$who   = $user && $user->exists() ? $user->display_name . ' (' . $user->user_email . ')' : 'un amministratore';
		$to    = array( (string) get_option( 'admin_email' ) );
		foreach ( get_users( array( 'capability' => Plugin::CAP, 'number' => 10, 'fields' => array( 'user_email' ) ) ) as $u ) {
			$to[] = (string) $u->user_email;
		}
		$to   = array_values( array_unique( array_filter( array_map( 'strtolower', $to ), 'is_email' ) ) );
		$text = "Le coordinate bancarie mostrate ai soci sono state modificate da " . $who . ".\n\nPrima: " . ( implode( ', ', $mask( $old ) ) ?: 'nessuna' ) . "\nDopo: " . ( implode( ', ', $mask( $new ) ) ?: 'nessuna' )
			. "\n\nSe non sei stato tu, cambia subito le password degli amministratori e controlla le impostazioni dei pagamenti.";
		foreach ( $to as $addr ) {
			Texts::mail( $addr, 'Modificate le coordinate bancarie dell\'associazione', $text );
		}
	}
}
