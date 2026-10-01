<?php
namespace ApSemplice\Admin;

use ApSemplice\Audit;
use ApSemplice\Gateways;
use ApSemplice\MemberType;
use ApSemplice\Money;
use ApSemplice\PaymentConfig;
use ApSemplice\PeopleCsv;
use ApSemplice\Plugin;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Gestori dei moduli (admin-post.php). Ogni gestore riceve $_POST già "unslashed" e restituisce
 * [url di destinazione, messaggio]. Gli errori di regola (\InvalidArgumentException) tornano alla
 * pagina di partenza con il messaggio, senza perdere nulla.
 */
final class Actions {

	public static function register(): void {
		$map = array(
			'aps_save_person'        => 'save_person',
			'aps_delete_person'      => 'delete_person',
			'aps_set_membership'     => 'set_membership',
			'aps_save_activity'      => 'save_activity',
			'aps_enroll'             => 'enroll',
			'aps_cancel_enrollment'  => 'cancel_enrollment',
			'aps_add_session'        => 'add_session',
			'aps_update_session'     => 'update_session',
			'aps_generate_sessions'  => 'generate_sessions',
			'aps_cancel_session'     => 'cancel_session',
			'aps_book'               => 'book',
			'aps_transfer_booking'   => 'transfer_booking',
			'aps_test_gateway'       => 'test_gateway',
			'aps_check_payments'     => 'check_payments',
			'aps_payment_reviewed'   => 'payment_reviewed',
			'aps_cancel_booking'     => 'cancel_booking',
			'aps_save_income'        => 'save_income',
			'aps_save_expense'       => 'save_expense',
			'aps_save_transfer'      => 'save_transfer',
			'aps_void_tx'            => 'void_tx',
			'aps_add_account'        => 'add_account',
			'aps_cash_count'         => 'cash_count',
			'aps_save_settings'      => 'save_settings',
			'aps_create_pages'       => 'create_pages',
			'aps_import_preview'     => 'import_preview',
			'aps_import_apply'       => 'import_apply',
		);
		foreach ( $map as $action => $method ) {
			add_action(
				'admin_post_' . $action,
				function () use ( $action, $method ) {
					if ( ! current_user_can( Plugin::CAP ) ) {
						wp_die( 'Non autorizzato.', 403 );
					}
					check_admin_referer( $action );
					$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
					$back = ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : Ui::url( 'aps' );
					try {
						$res = self::$method( $post );
						Ui::redirect( $res[0], $res[1] );
					} catch ( \InvalidArgumentException $e ) {
						Ui::redirect( $back, '', $e->getMessage() );
					} catch ( \Throwable $e ) {
						Ui::redirect( $back, '', 'Errore imprevisto: ' . $e->getMessage() );
					}
				}
			);
		}
	}

	private static function opt( array $a, string $k ): ?string {
		return isset( $a[ $k ] ) && '' !== trim( (string) $a[ $k ] ) ? trim( (string) $a[ $k ] ) : null;
	}

	// ---------- Soci e ospiti ----------

	private static function save_person( array $p ): array {
		$data = array(
			'type'           => (string) ( $p['type'] ?? '' ),
			'card_number'    => $p['card_number'] ?? '',
			'first_name'     => $p['first_name'] ?? '',
			'last_name'      => $p['last_name'] ?? '',
			'email'          => $p['email'] ?? '',
			'phone'          => $p['phone'] ?? '',
			'tax_code'       => $p['tax_code'] ?? '',
			'host_person_id' => $p['host_person_id'] ?? '',
			'joined_on'      => $p['joined_on'] ?? '',
			'notes'          => $p['notes'] ?? '',
		);
		$id = (int) ( $p['id'] ?? 0 );
		if ( $id ) {
			Plugin::people()->update( $id, $data );
			return array( Ui::url( 'aps-person', array( 'id' => $id ) ), 'Scheda salvata.' );
		}
		$id = Plugin::people()->create( $data );
		return array( Ui::url( 'aps-person', array( 'id' => $id ) ), 'Persona creata.' );
	}

	private static function delete_person( array $p ): array {
		Plugin::people()->delete( (int) $p['id'] );
		return array( Ui::url( 'aps-people' ), 'Persona eliminata (l\'utente WordPress, se c\'è, resta).' );
	}

	private static function set_membership( array $p ): array {
		Plugin::people()->set_membership( (int) $p['id'], (string) $p['social_year'], ! empty( $p['enabled'] ) );
		return array( Ui::url( 'aps-person', array( 'id' => (int) $p['id'] ) ), 'Iscrizione aggiornata.' );
	}

	// ---------- Attività ----------

	/** Contributo da un campo di testo: vuoto = non indicato (null), "0" = gratuito. */
	private static function fee_field( array $p, string $key ): ?int {
		$raw = isset( $p[ $key ] ) ? trim( (string) $p[ $key ] ) : '';
		return '' === $raw ? null : ( Money::parse( $raw ) ?? 0 );
	}

	private static function session_fields( array $p ): array {
		return array(
			'session_date' => (string) ( $p['session_date'] ?? '' ),
			'start_time'   => (string) ( $p['start_time'] ?? '' ),
			'location'     => (string) ( $p['location'] ?? '' ),
			'capacity'     => (string) ( $p['capacity'] ?? '' ),
		);
	}

	private static function save_activity( array $p ): array {
		$data = array(
			'name'                 => $p['name'] ?? '',
			'social_year'          => $p['social_year'] ?? '',
			'kind'                 => $p['kind'] ?? 'course',
			'instructor_person_id' => $p['instructor_person_id'] ?? '',
			'fee_cents'            => self::fee_field( $p, 'fee' ) ?? 0,
			'guest_fee_cents'      => self::fee_field( $p, 'guest_fee' ),
			'cancellable'          => ! empty( $p['cancellable'] ) ? 1 : 0,
			'cancel_policy'        => $p['cancel_policy'] ?? '',
			'notes'                => $p['notes'] ?? '',
		);
		$id = (int) ( $p['id'] ?? 0 );
		if ( $id ) {
			Plugin::activities()->update( $id, $data );
			return array( Ui::url( 'aps-activity', array( 'id' => $id ) ), 'Attività salvata.' );
		}
		if ( 'event' === $data['kind'] ) {
			$data['session'] = self::session_fields( $p );
		}
		$id = Plugin::activities()->create( $data );
		return array( Ui::url( 'aps-activity', array( 'id' => $id ) ), 'Attività creata.' );
	}

	private static function add_session( array $p ): array {
		$aid = (int) $p['activity_id'];
		Plugin::activities()->add_session( $aid, self::session_fields( $p ) );
		return array( Ui::url( 'aps-activity', array( 'id' => $aid ) ), 'Data aggiunta.' );
	}

	private static function update_session( array $p ): array {
		Plugin::activities()->update_session( (int) $p['session_id'], self::session_fields( $p ) );
		return array( Ui::url( 'aps-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Data aggiornata.' );
	}

	private static function generate_sessions( array $p ): array {
		$aid = (int) $p['activity_id'];
		$cap = isset( $p['capacity'] ) && '' !== trim( (string) $p['capacity'] ) ? (int) $p['capacity'] : null;
		$n   = Plugin::activities()->generate_weekly( $aid, (string) ( $p['from'] ?? '' ), (string) ( $p['to'] ?? '' ), self::opt( $p, 'start_time' ), self::opt( $p, 'location' ), $cap );
		return array( Ui::url( 'aps-activity', array( 'id' => $aid ) ), $n . ( 1 === $n ? ' data creata.' : ' date create.' ) );
	}

	private static function cancel_session( array $p ): array {
		Plugin::activities()->cancel_session( (int) $p['session_id'] );
		return array( Ui::url( 'aps-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Data annullata.' );
	}

	private static function book( array $p ): array {
		Plugin::activities()->book( (int) $p['session_id'], (int) ( $p['person_id'] ?? 0 ) );
		return array( Ui::url( 'aps-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Prenotazione registrata.' );
	}

	private static function cancel_booking( array $p ): array {
		Plugin::activities()->cancel_booking( (int) $p['session_id'], (int) $p['person_id'] );
		return array( Ui::url( 'aps-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Prenotazione annullata (eventuali pagamenti vanno rimborsati a mano).' );
	}

	private static function enroll( array $p ): array {
		Plugin::activities()->enroll( (int) $p['activity_id'], (int) ( $p['person_id'] ?? 0 ), (string) $p['start_month'] );
		return array( Ui::url( 'aps-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Iscrizione registrata.' );
	}

	private static function cancel_enrollment( array $p ): array {
		Plugin::activities()->cancel( (int) $p['activity_id'], (int) $p['person_id'], (string) $p['last_month'] );
		return array( Ui::url( 'aps-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Cancellazione registrata: i mesi successivi non sono più dovuti.' );
	}

	// ---------- Movimenti ----------

	private static function save_income( array $p ): array {
		$lines = array();
		foreach ( (array) ( $p['lines'] ?? array() ) as $l ) {
			$lines[] = array(
				'category_id'      => (int) ( $l['category_id'] ?? 0 ),
				'amount_cents'     => Money::parse( $l['amount'] ?? '' ) ?? 0,
				'activity_id'      => (int) ( $l['activity_id'] ?? 0 ),
				'session_id'       => (int) ( $l['session_id'] ?? 0 ),
				'competence_month' => self::opt( $l, 'competence_month' ),
				'social_year'      => self::opt( $l, 'social_year' ),
				'description'      => (string) ( $l['description'] ?? '' ),
			);
		}
		$n = Plugin::ledger()->record_receipt(
			array(
				'date'         => (string) ( $p['date'] ?? '' ),
				'account_id'   => (int) ( $p['account_id'] ?? 0 ),
				'method'       => (string) ( $p['method'] ?? '' ),
				'person_id'    => (int) ( $p['person_id'] ?? 0 ),
				'document_ref' => $p['document_ref'] ?? '',
				'lines'        => $lines,
			)
		);
		return array( Ui::url( 'aps-ledger' ), $n > 1 ? "Incasso registrato ($n voci)." : 'Incasso registrato.' );
	}

	private static function save_expense( array $p ): array {
		Plugin::ledger()->record_expense(
			array(
				'date'         => (string) ( $p['date'] ?? '' ),
				'account_id'   => (int) ( $p['account_id'] ?? 0 ),
				'method'       => (string) ( $p['method'] ?? '' ),
				'category_id'  => (int) ( $p['category_id'] ?? 0 ),
				'amount_cents' => Money::parse( $p['amount'] ?? '' ) ?? 0,
				'activity_id'  => (int) ( $p['activity_id'] ?? 0 ),
				'person_id'    => (int) ( $p['person_id'] ?? 0 ),
				'description'  => $p['description'] ?? '',
				'document_ref' => $p['document_ref'] ?? '',
			)
		);
		return array( Ui::url( 'aps-ledger' ), 'Spesa registrata.' );
	}

	private static function save_transfer( array $p ): array {
		Plugin::ledger()->record_transfer(
			(string) ( $p['date'] ?? '' ), (int) ( $p['from_id'] ?? 0 ), (int) ( $p['to_id'] ?? 0 ),
			Money::parse( $p['amount'] ?? '' ) ?? 0, (string) ( $p['method'] ?? 'other' ), trim( (string) ( $p['description'] ?? '' ) )
		);
		return array( Ui::url( 'aps-ledger' ), 'Giroconto registrato.' );
	}

	private static function void_tx( array $p ): array {
		Plugin::ledger()->void( (int) $p['id'], (string) ( $p['reason'] ?? '' ) );
		return array( $p['_back'] ?? Ui::url( 'aps-ledger' ), 'Movimento annullato (resta tracciato).' );
	}

	// ---------- Conti ----------

	private static function add_account( array $p ): array {
		Plugin::ledger()->add_account( (string) ( $p['name'] ?? '' ), (string) ( $p['type'] ?? '' ), Money::parse( $p['opening'] ?? '' ) ?? 0 );
		return array( Ui::url( 'aps-accounts' ), 'Conto aggiunto.' );
	}

	private static function cash_count( array $p ): array {
		$counted = Money::parse( $p['counted'] ?? '' );
		if ( null === $counted ) {
			throw new \InvalidArgumentException( 'Inserisci il saldo reale.' );
		}
		$diff = Plugin::ledger()->record_cash_count( (int) $p['account_id'], (string) $p['date'], $counted, ! empty( $p['adjust'] ), self::opt( $p, 'notes' ) );
		$msg  = 0 === $diff ? 'Verifica registrata: il saldo coincide.' : 'Verifica registrata: differenza ' . Money::format( $diff ) . ( ! empty( $p['adjust'] ) ? ' (rettificata).' : '.' );
		return array( Ui::url( 'aps-accounts' ), $msg );
	}

	// ---------- Impostazioni ----------

	private static function save_settings( array $p ): array {
		$txt = function ( string $k ) use ( $p ) {
			return sanitize_text_field( $p[ $k ] ?? '' );
		};
		Settings::update(
			array(
				'association_name'        => $txt( 'association_name' ),
				'tax_code'                => $txt( 'tax_code' ),
				'social_year_start_month' => (int) ( $p['social_year_start_month'] ?? 9 ),
				'membership_fee_cents'    => Money::parse( $p['membership_fee'] ?? '' ) ?? 0,
				'founder_years'           => (int) ( $p['founder_years'] ?? 99 ),
				'member_area_page_id'     => (int) ( $p['member_area_page_id'] ?? 0 ),
				'license_key'             => $txt( 'license_key' ),
				'cancel_policy_default'   => $txt( 'cancel_policy_default' ),
				'accent_color'            => ! empty( $p['accent_custom'] ) ? $txt( 'accent_color' ) : '',
				'payment_hint'            => sanitize_textarea_field( $p['payment_hint'] ?? '' ),
				'gate_message'            => $txt( 'gate_message' ),
				'payment_provider'        => $txt( 'payment_provider' ),
				'stripe_mode'             => $txt( 'stripe_mode' ),
				'stripe_publishable_key'  => $txt( 'stripe_publishable_key' ),
				'stripe_secret_key'       => $txt( 'stripe_secret_key' ),     // vuoto = lascia quella salvata
				'stripe_webhook_secret'   => $txt( 'stripe_webhook_secret' ),
				'paypal_mode'             => $txt( 'paypal_mode' ),
				'paypal_client_id'        => $txt( 'paypal_client_id' ),
				'paypal_client_secret'    => $txt( 'paypal_client_secret' ),
			)
		);
		foreach ( \ApSemplice\Settings::SECRET_KEYS as $k ) {
			if ( ! empty( $p[ 'clear_' . $k ] ) ) {
				Settings::clear_secret( $k );
			}
		}
		$check = PaymentConfig::validate( Settings::payment_config() );
		$msg   = 'Impostazioni salvate.';
		if ( $check['errors'] ) {
			$msg .= ' Attenzione ai pagamenti online: ' . implode( ' ', $check['errors'] );
		}
		return array( Ui::url( 'aps-settings' ), $msg );
	}

	/** Prova la connessione a Stripe o PayPal con le chiavi salvate (solo quando l'amministratore preme il pulsante). */
	private static function test_gateway( array $p ): array {
		$provider = in_array( $p['provider'] ?? '', array( PaymentConfig::STRIPE, PaymentConfig::PAYPAL ), true ) ? $p['provider'] : '';
		$res      = Gateways::test( $provider, Settings::payment_config(), array( Gateways::class, 'wp_http' ) );
		Audit::log( 'gateway.tested', 'settings', null, array( 'provider' => $provider, 'ok' => $res['ok'] ) );
		if ( ! $res['ok'] ) {
			throw new \InvalidArgumentException( $res['message'] );
		}
		return array( Ui::url( 'aps-settings' ), $res['message'] );
	}

	private static function check_payments( array $p ): array {
		$r = Plugin::payments()->check_pending( true );
		return array( Ui::url( 'aps-payments' ), 'Controllati ' . $r['checked'] . ', registrati ' . $r['registered'] . ', scaduti ' . $r['expired'] . '.' );
	}

	private static function payment_reviewed( array $p ): array {
		Plugin::payments()->mark_reviewed( (int) $p['id'] );
		return array( Ui::url( 'aps-payments' ), 'Pagamento segnato come controllato.' );
	}

	private static function transfer_booking( array $p ): array {
		Plugin::activities()->transfer_booking( (int) $p['session_id'], (int) $p['person_id'], (int) ( $p['to_person_id'] ?? 0 ), false );
		return array( Ui::url( 'aps-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Nominativo cambiato: il pagamento già fatto passa alla nuova persona.' );
	}

	/** Crea le pagine standard (area soci, area volontari, attività) se non esistono già. */
	private static function create_pages( array $p ): array {
		$defs  = array(
			'area'      => array( 'Area soci', '[apsemplice_area_soci]' ),
			'volontari' => array( 'Area volontari', '[apsemplice_area_volontari]' ),
			'attivita'  => array( 'Attività ed eventi', '[apsemplice_attivita]' ),
		);
		$saved = (array) get_option( 'aps_pages', array() );
		$made  = array();
		foreach ( $defs as $key => $d ) {
			if ( ! empty( $saved[ $key ] ) && get_post_status( (int) $saved[ $key ] ) ) {
				continue;
			}
			$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $d[0], 'post_content' => $d[1] ) );
			if ( $id && ! is_wp_error( $id ) ) {
				$saved[ $key ] = (int) $id;
				$made[]        = $d[0];
				if ( 'volontari' === $key ) {
					update_post_meta( $id, '_aps_access', 'volunteers' ); // la pagina dei volontari è visibile solo a loro
				}
			}
		}
		update_option( 'aps_pages', $saved );
		if ( ! empty( $saved['area'] ) && 0 === (int) Settings::get( 'member_area_page_id' ) ) {
			Settings::update( array( 'member_area_page_id' => (int) $saved['area'] ) );
		}
		return array( Ui::url( 'aps-settings' ), $made ? 'Pagine create: ' . implode( ', ', $made ) . '.' : 'Le pagine standard esistono già.' );
	}

	// ---------- Import soci ----------

	private static function import_preview( array $p ): array {
		if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security
			throw new \InvalidArgumentException( 'Scegli un file CSV da caricare.' );
		}
		$bytes = file_get_contents( $_FILES['file']['tmp_name'] ); // phpcs:ignore WordPress.Security
		$parse = PeopleCsv::parse( PeopleCsv::decode( (string) $bytes ) );
		if ( isset( $parse['error'] ) ) {
			throw new \InvalidArgumentException( $parse['error'] );
		}
		$default  = MemberType::is_member( (string) ( $p['default_type'] ?? '' ) ) ? $p['default_type'] : MemberType::ORDINARY;
		$existing = array();
		foreach ( Plugin::people()->search() as $e ) {
			$existing[] = array( 'id' => (int) $e['id'], 'card' => $e['card_number'], 'first' => $e['first_name'], 'last' => $e['last_name'], 'email' => $e['email'], 'tax' => $e['tax_code'] );
		}
		$plan  = PeopleCsv::plan( $parse['rows'], $existing, $default );
		$token = wp_generate_password( 16, false );
		set_transient( 'aps_import_' . get_current_user_id() . '_' . $token, $plan, HOUR_IN_SECONDS );
		return array( Ui::url( 'aps-import', array( 'token' => $token ) ), 'File letto: controlla l\'anteprima prima di importare.' );
	}

	private static function import_apply( array $p ): array {
		$key  = 'aps_import_' . get_current_user_id() . '_' . sanitize_key( $p['token'] ?? '' );
		$plan = get_transient( $key );
		if ( ! is_array( $plan ) ) {
			throw new \InvalidArgumentException( 'L\'anteprima è scaduta: ricarica il file.' );
		}
		$mark   = ! empty( $p['mark_members'] );
		$year   = Settings::social_year()->label();
		$people = Plugin::people();
		$created = 0;
		$updated = 0;
		$failed  = array();
		foreach ( $plan as $row ) {
			if ( 'error' === $row['action'] ) {
				continue;
			}
			$r    = $row['row'];
			$data = array( 'first_name' => $r['first'], 'last_name' => $r['last'], 'email' => $r['email'] );
			foreach ( array( 'card' => 'card_number', 'phone' => 'phone', 'tax' => 'tax_code' ) as $from => $to ) {
				if ( null !== $r[ $from ] ) {
					$data[ $to ] = $r[ $from ];
				}
			}
			try {
				if ( 'create' === $row['action'] ) {
					$data['type'] = $row['type'];
					$id           = $people->create( $data );
					$created++;
				} else {
					$id = (int) $row['matched_id'];
					if ( ! empty( $row['type_given'] ) ) {
						$data['type'] = $row['type'];
					}
					$people->update( $id, $data );
					$updated++;
				}
				$person = $people->get( $id );
				if ( $mark && $person && in_array( $person['type'], array( MemberType::ORDINARY, MemberType::VOLUNTEER ), true ) ) {
					$people->set_membership( $id, $year, true, 'import' );
				}
			} catch ( \InvalidArgumentException $e ) {
				$failed[] = 'riga ' . $r['line'] . ': ' . $e->getMessage();
			}
		}
		Audit::log( 'import.applied', 'people', null, array( 'created' => $created, 'updated' => $updated, 'failed' => count( $failed ) ) );
		delete_transient( $key );
		$msg = "Import completato: $created creati, $updated aggiornati" . ( $failed ? ', ' . count( $failed ) . ' non riusciti (' . implode( '; ', array_slice( $failed, 0, 5 ) ) . ')' : '' ) . '.';
		return array( Ui::url( 'aps-people' ), $msg );
	}
}
