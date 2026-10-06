<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy (GDPR):
 *  - consenso/informativa: quando e come è stato registrato (cartaceo, sul sito, importato);
 *  - accesso: ogni persona (o l'amministratore) scarica i propri dati in JSON;
 *  - cancellazione: l'anonimizzazione toglie i dati personali ma lascia intatti i movimenti contabili (che l'associazione deve conservare);
 *  - ex soci da anonimizzare: persone inattive da più di N anni (impostabile), da confermare una per una.
 */
final class Privacy {

	const SOURCES = array( 'paper' => 'Modulo cartaceo', 'web' => 'Sul sito', 'verbal' => 'Verbale / altro', 'import' => 'Importato' );

	public static function register(): void {
		add_action( 'admin_post_apse_privacy_export', array( __CLASS__, 'handle_export' ) );
		add_action(
			'admin_post_nopriv_apse_privacy_export',
			function () {
				wp_safe_redirect( wp_login_url( home_url( '/' ) ) );
				exit;
			}
		);
	}

	// ---------- Consenso ----------

	public static function set_consent( int $person_id, string $source ): void {
		$p = Plugin::people()->get( $person_id );
		if ( ! $p ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		if ( ! isset( self::SOURCES[ $source ] ) ) {
			throw new \InvalidArgumentException( 'Modalità non valida.' );
		}
		Db::db()->update( Db::t( 'people' ), array( 'privacy_consent_at' => Db::now(), 'privacy_consent_source' => $source ), array( 'id' => $person_id ) );
		Audit::log( 'privacy.consent', 'person', $person_id, array( 'source' => $source ) );
	}

	public static function clear_consent( int $person_id ): void {
		Db::db()->update( Db::t( 'people' ), array( 'privacy_consent_at' => null, 'privacy_consent_source' => null ), array( 'id' => $person_id ) );
		Audit::log( 'privacy.consent_cleared', 'person', $person_id );
	}

	public static function has_consent( array $p ): bool {
		return ! empty( $p['privacy_consent_at'] );
	}

	// ---------- Accesso ai dati ----------

	/** Tutti i dati che il sito ha su una persona. */
	public static function export( int $person_id ): array {
		$p = Plugin::people()->get( $person_id );
		if ( ! $p ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		$db  = Db::db();
		$acts = Plugin::activities();
		$q   = function ( string $sql, array $args ) use ( $db ) {
			return $db->get_results( $db->prepare( $sql, $args ), ARRAY_A ) ?: array();
		};
		$bookings = array();
		foreach ( $acts->bookings_for_person( $person_id ) as $b ) {
			$bookings[] = array( 'evento' => $b['activity_name'], 'data' => $b['session_date'], 'stato' => $b['status'], 'ingresso' => $b['checked_in_at'] ?? null, 'contributo_cents' => (int) $b['fee_due_cents'] );
		}
		$courses = $q( 'SELECT a.name AS corso, e.start_month AS dal_mese, e.end_month AS al_mese FROM ' . Db::t( 'enrollments' ) . ' e JOIN ' . Db::t( 'activities' ) . ' a ON a.id = e.activity_id WHERE e.person_id = %d', array( $person_id ) );
		$memberships = $q( 'SELECT social_year AS anno, valid_from AS dal, valid_to AS al, source AS origine FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = %d AND deleted_at IS NULL ORDER BY valid_from', array( $person_id ) );
		$payments = $q(
			'SELECT t.tx_date AS data, t.amount_cents AS importo_cents, t.method AS modalita, c.name AS voce, t.description AS descrizione, a.name AS attivita FROM ' . Db::t( 'transactions' ) . ' t '
			. 'JOIN ' . Db::t( 'categories' ) . ' c ON c.id = t.category_id LEFT JOIN ' . Db::t( 'activities' ) . ' a ON a.id = t.activity_id '
			. "WHERE t.voided_at IS NULL AND t.type IN ('income','expense') AND (t.person_id = %d OR t.payer_person_id = %d) ORDER BY t.tx_date",
			array( $person_id, $person_id )
		);
		$guests = array();
		foreach ( Plugin::people()->guests_of( $person_id ) as $g ) {
			$guests[] = array( 'nome' => trim( $g['first_name'] . ' ' . $g['last_name'] ), 'cellulare' => $g['phone'] );
		}
		return array(
			'generato_il' => Db::now(),
			'associazione' => (string) Settings::get( 'association_name' ),
			'anagrafica'  => array(
				'tipo' => MemberType::label( $p['type'] ), 'nome' => $p['first_name'], 'cognome' => $p['last_name'], 'email' => $p['email'], 'cellulare' => $p['phone'],
				'codice_fiscale' => $p['tax_code'], 'tessera' => $p['card_number'], 'iscritto_dal' => $p['joined_on'], 'carica' => BoardRole::label( $p['board_role'] ?? null ), 'note' => $p['notes'],
				'consenso_privacy' => $p['privacy_consent_at'], 'consenso_modalita' => $p['privacy_consent_source'] ? ( self::SOURCES[ $p['privacy_consent_source'] ] ?? $p['privacy_consent_source'] ) : null,
			),
			'iscrizioni_tessera' => $memberships,
			'corsi'        => $courses,
			'prenotazioni' => $bookings,
			'pagamenti'    => $payments,
			'ospiti_inseriti' => $guests,
		);
	}

	public static function can_export( int $person_id ): bool {
		return $person_id > 0 && ( current_user_can( Plugin::CAP ) || current_user_can( 'apse_view_person', $person_id ) );
	}

	public static function export_url( int $person_id ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'apse_privacy_export', 'person' => $person_id ), admin_url( 'admin-post.php' ) ), 'apse_privacy_export_' . $person_id );
	}

	public static function handle_export(): void {
		$pid = isset( $_GET['person'] ) ? (int) $_GET['person'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'apse_privacy_export_' . $pid );
		if ( ! self::can_export( $pid ) ) {
			wp_die( 'Non autorizzato.', 403 );
		}
		try {
			$data = self::export( $pid );
		} catch ( \InvalidArgumentException $e ) {
			wp_die( esc_html( $e->getMessage() ), 404 );
		}
		Audit::log( 'privacy.exported', 'person', $pid );
		$json = (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="miei-dati-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON
		exit;
	}

	// ---------- Anonimizzazione ----------

	/** Motivo per cui una persona non si può anonimizzare ora, oppure '' se si può. */
	public static function blocker( int $person_id ): string {
		$db = Db::db();
		$p  = Plugin::people()->get( $person_id );
		if ( ! $p ) {
			return 'Persona non trovata.';
		}
		if ( ! empty( $p['anonymized_at'] ) ) {
			return 'Già anonimizzata.';
		}
		if ( ! empty( $p['wp_user_id'] ) && user_can( (int) $p['wp_user_id'], Plugin::CAP ) ) {
			return 'È un amministratore del sito: togli prima il ruolo.';
		}
		if ( ! empty( $p['board_role'] ) ) {
			return 'Ha una carica nel consiglio direttivo: toglila prima.';
		}
		if ( Plugin::people()->guests_of( $person_id ) ) {
			return 'Ha degli ospiti collegati: anonimizza o sposta prima loro.';
		}
		if ( (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'enrollments' ) . ' WHERE person_id = %d AND end_month IS NULL', $person_id ) ) > 0 ) {
			return 'È iscritta a un corso: termina prima l\'iscrizione.';
		}
		$future = (int) $db->get_var(
			$db->prepare(
				'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . " s ON s.id = b.session_id WHERE b.person_id = %d AND b.status = 'booked' AND s.session_date >= %s AND s.cancelled_at IS NULL",
				$person_id,
				current_time( 'Y-m-d' )
			)
		);
		if ( $future > 0 ) {
			return 'Ha prenotazioni future: annullale prima.';
		}
		return '';
	}

	/**
	 * Toglie i dati personali: nome, contatti, codice fiscale, tessera, note; scollega (e, se è solo un socio, elimina) l'utente del sito.
	 * I movimenti restano (obbligo di conservazione): il nome nelle descrizioni viene sostituito.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function anonymize( int $person_id ): void {
		$why = self::blocker( $person_id );
		if ( '' !== $why ) {
			throw new \InvalidArgumentException( $why );
		}
		$p    = Plugin::people()->get( $person_id );
		$db   = Db::db();
		$name = Plugin::people()->full_name( $p );
		$uid  = (int) ( $p['wp_user_id'] ?? 0 );
		$db->update(
			Db::t( 'people' ),
			array(
				'first_name' => 'Persona', 'last_name' => 'anonimizzata #' . $person_id, 'email' => null, 'phone' => null, 'tax_code' => null, 'card_number' => null, 'notes' => null,
				'wp_user_id' => null, 'privacy_consent_at' => null, 'privacy_consent_source' => null, 'anonymized_at' => Db::now(), 'updated_at' => Db::now(),
			),
			array( 'id' => $person_id )
		);
		if ( '' !== trim( $name ) ) { // il nome scritto a mano nelle descrizioni dei movimenti
			$db->query(
				$db->prepare(
					'UPDATE ' . Db::t( 'transactions' ) . ' SET description = REPLACE(description, %s, %s) WHERE person_id = %d OR payer_person_id = %d',
					$name,
					'[anonimizzato]',
					$person_id,
					$person_id
				)
			);
		}
		if ( $uid > 0 ) {
			$user = get_userdata( $uid );
			if ( $user && Gatekeeper::is_member_only( (array) $user->roles ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $uid );
			}
		}
		Audit::log( 'person.anonymized', 'person', $person_id, array( 'type' => $p['type'] ) ); // senza il nome
	}

	/**
	 * Persone inattive da più di N anni (ultimo movimento, iscrizione, prenotazione o registrazione) che si possono anonimizzare.
	 *
	 * @return array[] id, name, type, last_seen
	 */
	public static function retention_candidates( ?int $years = null, int $limit = 100 ): array {
		$years = $years ?? max( 1, (int) Settings::get( 'privacy_retention_years' ) );
		$limit_date = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . $years . ' years' ) );
		$db  = Db::db();
		$p   = Db::t( 'people' );
		$sql = "SELECT p.id, p.first_name, p.last_name, p.type, GREATEST(COALESCE(p.joined_on, DATE(p.created_at)), DATE(p.created_at), "
			. 'COALESCE((SELECT MAX(m.valid_to) FROM ' . Db::t( 'memberships' ) . " m WHERE m.person_id = p.id AND m.deleted_at IS NULL), '1970-01-01'), "
			. 'COALESCE((SELECT MAX(s.session_date) FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . " s ON s.id = b.session_id WHERE b.person_id = p.id), '1970-01-01'), "
			. 'COALESCE((SELECT MAX(t.tx_date) FROM ' . Db::t( 'transactions' ) . " t WHERE t.person_id = p.id OR t.payer_person_id = p.id), '1970-01-01'), "
			. 'COALESCE((SELECT MAX(CONCAT(e.start_month, \'-28\')) FROM ' . Db::t( 'enrollments' ) . " e WHERE e.person_id = p.id), '1970-01-01')) AS last_seen "
			. "FROM $p p WHERE p.deleted_at IS NULL AND p.anonymized_at IS NULL "
			. 'HAVING last_seen < %s ORDER BY last_seen LIMIT %d';
		$out = array();
		foreach ( $db->get_results( $db->prepare( $sql, $limit_date, $limit * 3 ), ARRAY_A ) ?: array() as $r ) {
			if ( '' !== self::blocker( (int) $r['id'] ) ) {
				continue;
			}
			$out[] = array( 'id' => (int) $r['id'], 'name' => trim( $r['first_name'] . ' ' . $r['last_name'] ), 'type' => $r['type'], 'last_seen' => $r['last_seen'] );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}
}
