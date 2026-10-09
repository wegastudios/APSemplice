<?php
namespace ApSemplice\Rest;

use ApSemplice\Access;
use ApSemplice\ActivityKind;
use ApSemplice\Edition;
use ApSemplice\MemberType;
use ApSemplice\Plugin;
use ApSemplice\Settings;
use ApSemplice\StripeWebhook;

defined( 'ABSPATH' ) || exit;

/**
 * REST API `apsemplice/v1`: unico ingresso per l'area riservata di oggi e per la PWA di domani.
 * Ogni rotta controlla i permessi tramite {@see Access}; i dati escono già "sagomati" per chi li chiede
 * (ad esempio i volontari vedono i nomi degli iscritti ma non i loro contatti).
 */
final class Api {

	const NS = 'apsemplice/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$logged_in = function () {
			return self::guard();
		};
		self::event_routes( $logged_in );
		if ( Edition::has( 'payments' ) ) {
			register_rest_route( self::NS, '/webhooks/stripe', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'stripe_webhook' ), 'permission_callback' => '__return_true' ) );
		}
		register_rest_route( self::NS, '/me', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'me' ), 'permission_callback' => $logged_in ) );
		register_rest_route( self::NS, '/me/activities', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'my_activities' ), 'permission_callback' => $logged_in ) );
		register_rest_route(
			self::NS,
			'/people/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'person' ),
				'permission_callback' => function ( \WP_REST_Request $r ) {
					return self::guard( 'apse_view_person', (int) $r['id'] );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/activities/(?P<id>\d+)/participants',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'participants' ),
				'permission_callback' => function ( \WP_REST_Request $r ) {
					return self::guard( 'apse_view_participants', (int) $r['id'] );
				},
			)
		);
	}

	/**
	 * Controllo comune: serve un utente collegato; se la licenza non è in regola soci e volontari ricevono un
	 * messaggio chiaro (gli amministratori no). Con $ability controlla anche il permesso specifico.
	 *
	 * @return true|false|\WP_Error
	 */
	private static function guard( ?string $ability = null, int $object_id = 0 ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( ! Access::is_admin_user( get_current_user_id() ) && ! Edition::allows( 'member_area' ) ) {
			return new \WP_Error( 'apse_license_required', 'Servizio sospeso: la licenza dell\'associazione non risulta attiva.', array( 'status' => 403 ) );
		}
		return null === $ability ? true : current_user_can( $ability, $object_id );
	}

	/** Eventi e prenotazioni. */
	private static function event_routes( callable $logged_in ): void {
		register_rest_route( self::NS, '/me/bookings', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'my_bookings' ), 'permission_callback' => $logged_in ) );
		register_rest_route(
			self::NS,
			'/activities/(?P<id>\d+)/sessions',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'sessions' ),
				'permission_callback' => function ( \WP_REST_Request $r ) {
					return self::guard( 'apse_view_activity', (int) $r['id'] );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/sessions/(?P<id>\d+)/bookings',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'session_bookings' ),
				'permission_callback' => function ( \WP_REST_Request $r ) {
					$s = Plugin::activities()->session( (int) $r['id'] );
					return $s ? self::guard( 'apse_view_participants', (int) $s['activity_id'] ) : self::guard( Plugin::CAP_OPS );
				},
			)
		);
	}

	public static function my_bookings() {
		$person = Access::person_for_user( get_current_user_id() );
		if ( ! $person ) {
			return new \WP_Error( 'apse_no_person', 'Questo utente non è collegato a un socio.', array( 'status' => 404 ) );
		}
		$rows = array();
		foreach ( Plugin::activities()->bookings_for_person( (int) $person['id'] ) as $b ) {
			$rows[] = array(
				'session_id' => (int) $b['session_id'], 'activity_id' => (int) $b['activity_id'], 'activity' => $b['activity_name'],
				'date' => $b['session_date'], 'time' => $b['start_time'], 'location' => $b['location'],
				'active' => $b['active'], 'fee_due' => (int) $b['fee_due_cents'], 'paid' => $b['paid'], 'state' => $b['state'],
			);
		}
		return rest_ensure_response( array( 'bookings' => $rows ) );
	}

	public static function sessions( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		$a  = Plugin::activities()->get( $id );
		if ( ! $a ) {
			return new \WP_Error( 'apse_not_found', 'Attività non trovata.', array( 'status' => 404 ) );
		}
		$out = array();
		foreach ( Plugin::activities()->sessions( $id ) as $s ) {
			$out[] = array(
				'id' => (int) $s['id'], 'date' => $s['session_date'], 'time' => $s['start_time'], 'location' => $s['location'],
				'capacity' => null === $s['capacity'] ? null : (int) $s['capacity'], 'booked' => (int) $s['booked_count'], 'cancelled' => ! empty( $s['cancelled_at'] ),
			);
		}
		return rest_ensure_response( array( 'activity' => array( 'id' => $id, 'name' => $a['name'], 'kind' => $a['kind'] ), 'sessions' => $out ) );
	}

	/** Prenotati a una data. Come per gli iscritti: i volontari vedono i nomi, solo gli amministratori contatti e pagamenti. */
	public static function session_bookings( \WP_REST_Request $r ) {
		$s = Plugin::activities()->session( (int) $r['id'] );
		if ( ! $s ) {
			return new \WP_Error( 'apse_not_found', 'Data non trovata.', array( 'status' => 404 ) );
		}
		$admin = Access::is_admin_user( get_current_user_id() );
		$rows  = array();
		foreach ( Plugin::activities()->bookings_for_session( (int) $s['id'] ) as $b ) {
			$row = array( 'person_id' => (int) $b['person_id'], 'first_name' => $b['first_name'], 'last_name' => $b['last_name'], 'type_label' => MemberType::label( $b['type'] ), 'active' => $b['active'] );
			if ( $admin ) {
				$row['email']   = $b['email'];
				$row['fee_due'] = (int) $b['fee_due_cents'];
				$row['state']   = $b['state'];
			}
			$rows[] = $row;
		}
		return rest_ensure_response( array( 'session' => array( 'id' => (int) $s['id'], 'date' => $s['session_date'], 'activity_id' => (int) $s['activity_id'] ), 'bookings' => $rows ) );
	}

	/** Webhook di Stripe: pubblico ma accettato solo con firma valida (segreto del webhook impostato nel pannello). */
	public static function stripe_webhook( \WP_REST_Request $r ) {
		$payload = (string) $r->get_body();
		$secret  = Settings::secret( 'stripe_webhook_secret' );
		if ( ! StripeWebhook::verify( $payload, (string) $r->get_header( 'stripe-signature' ), $secret, time() ) ) {
			return new \WP_Error( 'apse_bad_signature', 'Firma non valida.', array( 'status' => 400 ) );
		}
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) ) {
			return new \WP_Error( 'apse_bad_payload', 'Contenuto non valido.', array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'received' => true, 'result' => Plugin::payments()->handle_stripe_event( $event ) ) );
	}

	// ---------- Forme di output ----------

	/** Scheda di una persona con la validità della tessera. */
	private static function shape_person( array $p, bool $with_contacts = true ): array {
		$people = Plugin::people();
		$until  = MemberType::GUEST === $p['type'] ? null : $people->active_until( (int) $p['id'] );
		$out    = array(
			'id'           => (int) $p['id'],
			'type'         => $p['type'],
			'type_label'   => \ApSemplice\Levels::label( $p ),
			'card_number'  => $p['card_number'],
			'first_name'   => $p['first_name'],
			'last_name'    => $p['last_name'],
			'active_until' => $until,
			'active'       => null !== $until && $until >= current_time( 'Y-m-d' ),
		);
		if ( $with_contacts ) {
			$out['email'] = $p['email'];
			$out['phone'] = $p['phone'];
		}
		return $out;
	}

	private static function shape_status( array $s ): array {
		$e = $s['enrollment'];
		$m = $s['summary'];
		return array(
			'activity_id'   => (int) $e['activity_id'],
			'activity'      => $s['activity']['name'],
			'social_year'   => $s['activity']['social_year'],
			'start_month'   => $e['start_month'],
			'end_month'     => $e['end_month'],
			'active'        => null === $e['end_month'],
			'total_due'     => $m['total_due'],
			'total_paid'    => $m['total_paid'],
			'balance'       => $m['balance'],
			'regular'       => $m['regular'],
			'unpaid_months' => array_column( $m['unpaid_months'], 'month' ),
		);
	}

	// ---------- Rotte ----------

	public static function me() {
		$user   = wp_get_current_user();
		$person = Access::person_for_user( (int) $user->ID );
		return rest_ensure_response(
			array(
				'user'        => array( 'id' => (int) $user->ID, 'name' => $user->display_name ),
				'is_admin'    => Access::is_admin_user( (int) $user->ID ),
				'person'      => $person ? self::shape_person( $person ) : null,
				'is_volunteer' => $person && MemberType::can_teach( $person['type'] ),
				'social_year' => Settings::social_year()->label(),
				'association' => (string) Settings::get( 'association_name' ),
			)
		);
	}

	public static function my_activities() {
		$person = Access::person_for_user( get_current_user_id() );
		if ( ! $person ) {
			return new \WP_Error( 'apse_no_person', 'Questo utente non è collegato a un socio.', array( 'status' => 404 ) );
		}
		$rows = array_map( array( __CLASS__, 'shape_status' ), Plugin::activities()->status_for_person( (int) $person['id'] ) );
		return rest_ensure_response( array( 'activities' => $rows ) );
	}

	public static function person( \WP_REST_Request $r ) {
		$p = Plugin::people()->get( (int) $r['id'] );
		if ( ! $p ) {
			return new \WP_Error( 'apse_not_found', 'Persona non trovata.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( self::shape_person( $p ) );
	}

	/** Iscritti di un'attività. Contatti e pagamenti solo agli amministratori: i volontari vedono i nomi. */
	public static function participants( \WP_REST_Request $r ) {
		$id       = (int) $r['id'];
		$activity = Plugin::activities()->get( $id );
		if ( ! $activity ) {
			return new \WP_Error( 'apse_not_found', 'Attività non trovata.', array( 'status' => 404 ) );
		}
		$admin = Access::is_admin_user( get_current_user_id() );
		$rows  = array();
		if ( ActivityKind::uses_sessions( $activity['kind'] ) ) {
			// Eventi: chi ha almeno una prenotazione attiva
			foreach ( Plugin::activities()->booked_people( $id ) as $b ) {
				$row = array( 'person_id' => (int) $b['person_id'], 'first_name' => $b['first_name'], 'last_name' => $b['last_name'], 'type_label' => MemberType::label( $b['type'] ), 'active' => true );
				if ( $admin ) {
					$row['email'] = $b['email'];
				}
				$rows[] = $row;
			}
			return rest_ensure_response( array( 'activity' => array( 'id' => $id, 'name' => $activity['name'], 'social_year' => $activity['social_year'], 'kind' => $activity['kind'] ), 'participants' => $rows ) );
		}
		foreach ( Plugin::activities()->status_for_activity( $id ) as $s ) {
			$e   = $s['enrollment'];
			$row = array(
				'person_id'  => (int) $e['person_id'],
				'first_name' => $e['first_name'],
				'last_name'  => $e['last_name'],
				'type_label' => MemberType::label( $e['type'] ),
				'active'     => null === $e['end_month'],
			);
			if ( $admin ) {
				$row['email']   = $e['email'];
				$row['regular'] = $s['summary']['regular'];
				$row['balance'] = $s['summary']['balance'];
			}
			$rows[] = $row;
		}
		return rest_ensure_response( array( 'activity' => array( 'id' => $id, 'name' => $activity['name'], 'social_year' => $activity['social_year'] ), 'participants' => $rows ) );
	}
}
