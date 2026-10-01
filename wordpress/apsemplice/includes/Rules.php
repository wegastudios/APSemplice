<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/** Regole di validazione pure (nessun accesso al database): testabili senza WordPress. */
final class Rules {

	/**
	 * @param array      $d    type, first_name, last_name, email, card_number, host_person_id
	 * @param array|null $host persona ospitante (con almeno 'type'), solo per gli ospiti
	 * @return string[] messaggi di errore (vuoto = valido)
	 */
	public static function validate_person( array $d, ?array $host = null ): array {
		$errors = array();
		$type   = (string) ( $d['type'] ?? '' );
		if ( ! MemberType::is_valid( $type ) ) {
			return array( 'Tipo di persona non valido.' );
		}
		if ( '' === trim( (string) ( $d['first_name'] ?? '' ) ) || '' === trim( (string) ( $d['last_name'] ?? '' ) ) ) {
			$errors[] = 'Nome e cognome sono obbligatori.';
		}

		$email = trim( (string) ( $d['email'] ?? '' ) );
		if ( '' === $email ) {
			if ( MemberType::requires_email( $type ) ) {
				$errors[] = 'L\'email è obbligatoria per i soci: ogni socio corrisponde a un utente WordPress.';
			}
		} elseif ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$errors[] = 'L\'email non è valida.';
		}

		$card = trim( (string) ( $d['card_number'] ?? '' ) );
		if ( strlen( $card ) > 40 ) {
			$errors[] = 'Il numero di tessera è troppo lungo (massimo 40 caratteri).';
		}
		if ( MemberType::GUEST === $type && '' !== $card ) {
			$errors[] = 'Gli ospiti non hanno il numero di tessera.';
		}
		if ( MemberType::GUEST === $type && ! Phone::is_valid( (string) ( $d['phone'] ?? '' ) ) ) {
			$errors[] = 'Il numero di cellulare è obbligatorio per gli ospiti (serve a riconoscerli ed evitare doppioni, e a contattarli su WhatsApp).';
		}

		if ( MemberType::requires_host( $type ) ) {
			if ( empty( $d['host_person_id'] ) ) {
				$errors[] = 'Un ospite deve essere collegato al socio che lo ospita.';
			} elseif ( null === $host || ! MemberType::is_member( (string) ( $host['type'] ?? '' ) ) ) {
				$errors[] = 'Il socio ospitante non è valido (deve essere un socio, non un altro ospite).';
			}
		} elseif ( ! empty( $d['host_person_id'] ) ) {
			$errors[] = 'Solo gli ospiti hanno un socio ospitante.';
		}
		return $errors;
	}

	/**
	 * @param array      $d          name, kind, fee_cents, guest_fee_cents (null = come i soci)
	 * @param array|null $instructor persona istruttore (con 'type'), se indicata
	 * @return string[]
	 */
	public static function validate_activity( array $d, ?array $instructor = null ): array {
		$errors = array();
		if ( '' === trim( (string) ( $d['name'] ?? '' ) ) ) {
			$errors[] = 'Il nome dell\'attività è obbligatorio.';
		}
		if ( isset( $d['kind'] ) && ! ActivityKind::is_valid( (string) $d['kind'] ) ) {
			$errors[] = 'Tipo di attività non valido.';
		}
		if ( (int) ( $d['fee_cents'] ?? 0 ) < 0 ) {
			$errors[] = 'Il contributo non può essere negativo.';
		}
		if ( isset( $d['guest_fee_cents'] ) && (int) $d['guest_fee_cents'] < 0 ) {
			$errors[] = 'Il contributo per gli ospiti non può essere negativo.';
		}
		if ( ! empty( $d['instructor_person_id'] ) ) {
			if ( null === $instructor || ! MemberType::can_teach( (string) ( $instructor['type'] ?? '' ) ) ) {
				$errors[] = 'Le attività possono essere tenute solo da soci e volontari.';
			}
		}
		return $errors;
	}

	/**
	 * Una data (sessione) di un evento.
	 *
	 * @param array $s session_date (Y-m-d), start_time (HH:MM, facoltativa), capacity (>= 1, facoltativa)
	 * @return string[]
	 */
	public static function validate_session( array $s ): array {
		$errors = array();
		$date   = (string) ( $s['session_date'] ?? '' );
		$dt     = \DateTime::createFromFormat( 'Y-m-d', $date );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
			$errors[] = 'La data dell\'evento non è valida.';
		}
		$time = (string) ( $s['start_time'] ?? '' );
		if ( '' !== $time && ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$errors[] = 'L\'orario non è valido (usa il formato HH:MM).';
		}
		if ( isset( $s['capacity'] ) && '' !== $s['capacity'] && (int) $s['capacity'] < 1 ) {
			$errors[] = 'I posti disponibili devono essere almeno 1 (lascia vuoto per nessun limite).';
		}
		return $errors;
	}
}
