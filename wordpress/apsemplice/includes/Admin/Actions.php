<?php
namespace ApSemplice\Admin;

use ApSemplice\Attachments;
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
			'apse_save_person'        => 'save_person',
			'apse_delete_person'      => 'delete_person',
			'apse_set_membership'     => 'set_membership',
			'apse_set_treasurer'      => 'set_treasurer',
			'apse_regen_qr'           => 'regen_qr',
			'apse_save_card'          => 'save_card',
			'apse_save_wallet_apple'  => 'save_wallet_apple',
			'apse_save_wallet_google' => 'save_wallet_google',
			'apse_wallet_clear'       => 'wallet_clear',
			'apse_save_activity'      => 'save_activity',
			'apse_enroll'             => 'enroll',
			'apse_cancel_enrollment'  => 'cancel_enrollment',
			'apse_add_session'        => 'add_session',
			'apse_update_session'     => 'update_session',
			'apse_generate_sessions'  => 'generate_sessions',
			'apse_cancel_session'     => 'cancel_session',
			'apse_book'               => 'book',
			'apse_event_staff_add'    => 'event_staff_add',
			'apse_event_staff_remove' => 'event_staff_remove',
			'apse_checkin'            => 'checkin',
			'apse_walk_in'            => 'walk_in',
			'apse_transfer_booking'   => 'transfer_booking',
			'apse_test_gateway'       => 'test_gateway',
			'apse_check_payments'     => 'check_payments',
			'apse_payment_reviewed'   => 'payment_reviewed',
			'apse_cancel_booking'     => 'cancel_booking',
			'apse_save_income'        => 'save_income',
			'apse_save_expense'       => 'save_expense',
			'apse_add_attachment'     => 'add_attachment',
			'apse_remove_attachment'  => 'remove_attachment',
			'apse_save_transfer'      => 'save_transfer',
			'apse_void_tx'            => 'void_tx',
			'apse_add_account'        => 'add_account',
			'apse_cash_count'         => 'cash_count',
			'apse_save_settings'      => 'save_settings',
			'apse_create_pages'       => 'create_pages',
			'apse_import_preview'     => 'import_preview',
			'apse_import_apply'       => 'import_apply',
			'apse_import_undo'        => 'import_undo',
			'apse_save_wpai'          => 'save_wpai',
			'apse_wpai_process'       => 'wpai_process',
			'apse_wpai_retry'         => 'wpai_retry',
			'apse_wpai_clear'         => 'wpai_clear',
		);
		add_action( 'admin_post_apse_attachment', array( Attachments::class, 'handle_download' ) );
		foreach ( $map as $action => $method ) {
			add_action(
				'admin_post_' . $action,
				function () use ( $action, $method ) {
					if ( ! current_user_can( Plugin::CAP ) ) {
						wp_die( 'Non autorizzato.', 403 );
					}
					check_admin_referer( $action );
					$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
					$back = ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : Ui::url( 'apse' );
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
			return array( Ui::url( 'apse-person', array( 'id' => $id ) ), 'Scheda salvata.' );
		}
		$id = Plugin::people()->create( $data );
		return array( Ui::url( 'apse-person', array( 'id' => $id ) ), 'Persona creata.' );
	}

	private static function delete_person( array $p ): array {
		Plugin::people()->delete( (int) $p['id'] );
		return array( Ui::url( 'apse-people' ), 'Persona eliminata (l\'utente WordPress, se c\'è, resta).' );
	}

	private static function set_membership( array $p ): array {
		Plugin::people()->set_membership( (int) $p['id'], (string) $p['social_year'], ! empty( $p['enabled'] ) );
		return array( Ui::url( 'apse-person', array( 'id' => (int) $p['id'] ) ), 'Iscrizione aggiornata.' );
	}

	private static function set_treasurer( array $p ): array {
		$person = Plugin::people()->get( (int) ( $p['id'] ?? 0 ) );
		if ( ! $person || empty( $person['wp_user_id'] ) || ! MemberType::is_member( $person['type'] ) ) {
			throw new \InvalidArgumentException( 'Solo un socio o volontario con accesso al sito può essere tesoriere.' );
		}
		$on = ! empty( $p['enabled'] );
		\ApSemplice\Access::set_treasurer( (int) $person['wp_user_id'], $on );
		Audit::log( $on ? 'treasurer.granted' : 'treasurer.revoked', 'person', (int) $person['id'] );
		return array( Ui::url( 'apse-person', array( 'id' => (int) $person['id'] ) ), $on ? 'Ora può registrare spese dall\'area riservata.' : 'Non può più registrare spese.' );
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
			'booking_qr'           => ! empty( $p['booking_qr'] ) ? 1 : 0,
			'notes'                => $p['notes'] ?? '',
		);
		$id = (int) ( $p['id'] ?? 0 );
		if ( $id ) {
			Plugin::activities()->update( $id, $data );
			return array( Ui::url( 'apse-activity', array( 'id' => $id ) ), 'Attività salvata.' );
		}
		if ( 'event' === $data['kind'] ) {
			$data['session'] = self::session_fields( $p );
		}
		$id = Plugin::activities()->create( $data );
		return array( Ui::url( 'apse-activity', array( 'id' => $id ) ), 'Attività creata.' );
	}

	private static function add_session( array $p ): array {
		$aid = (int) $p['activity_id'];
		Plugin::activities()->add_session( $aid, self::session_fields( $p ) );
		return array( Ui::url( 'apse-activity', array( 'id' => $aid ) ), 'Data aggiunta.' );
	}

	private static function update_session( array $p ): array {
		Plugin::activities()->update_session( (int) $p['session_id'], self::session_fields( $p ) );
		return array( Ui::url( 'apse-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Data aggiornata.' );
	}

	private static function generate_sessions( array $p ): array {
		$aid = (int) $p['activity_id'];
		$cap = isset( $p['capacity'] ) && '' !== trim( (string) $p['capacity'] ) ? (int) $p['capacity'] : null;
		$n   = Plugin::activities()->generate_weekly( $aid, (string) ( $p['from'] ?? '' ), (string) ( $p['to'] ?? '' ), self::opt( $p, 'start_time' ), self::opt( $p, 'location' ), $cap );
		return array( Ui::url( 'apse-activity', array( 'id' => $aid ) ), $n . ( 1 === $n ? ' data creata.' : ' date create.' ) );
	}

	private static function cancel_session( array $p ): array {
		Plugin::activities()->cancel_session( (int) $p['session_id'] );
		return array( Ui::url( 'apse-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Data annullata.' );
	}

	private static function book( array $p ): array {
		Plugin::activities()->book( (int) $p['session_id'], (int) ( $p['person_id'] ?? 0 ) );
		return array( Ui::url( 'apse-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Prenotazione registrata.' );
	}

	/**
	 * Chi si presenta senza aver prenotato: prenota (creando l'ospite se serve), incassa il contributo e registra l'ingresso.
	 * Tutto insieme: se qualcosa non va (posti esauriti, conto non valido...) non resta scritto nulla.
	 */
	private static function walk_in( array $p ): array {
		$sid     = (int) ( $p['session_id'] ?? 0 );
		$aid     = (int) ( $p['activity_id'] ?? 0 );
		$svc     = Plugin::activities();
		$session = $svc->session( $sid );
		if ( ! $session || (int) $session['activity_id'] !== $aid ) {
			throw new \InvalidArgumentException( 'Data non trovata.' );
		}
		$msg = '';
		Plugin::ledger()->in_batch(
			function () use ( $p, $sid, $aid, $svc, &$msg ) {
				$people = Plugin::people();
				$ledger = Plugin::ledger();
				$pid    = (int) ( $p['person_id'] ?? 0 );
				$first  = trim( (string) ( $p['new_first_name'] ?? '' ) );
				$last   = trim( (string) ( $p['new_last_name'] ?? '' ) );
				if ( ! $pid && ( '' !== $first || '' !== $last ) ) {
					$host = (int) ( $p['host_person_id'] ?? 0 );
					if ( ! $host ) {
						throw new \InvalidArgumentException( 'Indica il socio che ospita il nuovo ospite.' );
					}
					$pid = $people->create( array( 'type' => MemberType::GUEST, 'host_person_id' => $host, 'first_name' => $first, 'last_name' => $last ) );
				}
				if ( ! $pid ) {
					throw new \InvalidArgumentException( 'Scegli una persona oppure inserisci un nuovo ospite.' );
				}
				if ( ! $svc->has_active_booking( $sid, $pid ) ) {
					$svc->book( $sid, $pid );
				}
				$row = null;
				foreach ( $svc->bookings_for_session( $sid ) as $b ) {
					if ( (int) $b['person_id'] === $pid ) {
						$row = $b;
					}
				}
				$person = $people->get( $pid );
				$parts  = array( 'prenotato' );
				if ( ! empty( $p['pay'] ) ) {
					$due = $row ? (int) $row['remaining'] : 0;
					if ( $due > 0 ) {
						$ledger->record_receipt(
							array(
								'date' => current_time( 'Y-m-d' ), 'account_id' => (int) ( $p['account_id'] ?? 0 ), 'method' => (string) ( $p['method'] ?? '' ), 'person_id' => $pid,
								'lines' => array( array( 'category_id' => $ledger->category_id_of_kind( 'activity_fee' ), 'amount_cents' => $due, 'activity_id' => $aid, 'session_id' => $sid ) ),
							)
						);
						$parts[] = 'incassati ' . Money::format( $due );
					} else {
						$parts[] = 'nulla da incassare';
					}
				}
				if ( ! empty( $p['checkin'] ) ) {
					$svc->check_in( $sid, $pid, false, true );
					$parts[] = 'ingresso registrato';
				}
				$msg = 'Sul posto: ' . trim( $person['first_name'] . ' ' . $person['last_name'] ) . ' — ' . implode( ', ', $parts ) . '.';
			}
		);
		return array( Ui::url( 'apse-activity', array( 'id' => $aid ) ), $msg );
	}

	private static function event_staff_add( array $p ): array {
		Plugin::activities()->add_staff( (int) $p['activity_id'], (int) ( $p['person_id'] ?? 0 ) );
		return array( Ui::url( 'apse-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Gestore dell\'evento aggiunto: ora vede i prenotati e registra gli ingressi dall\'area riservata.' );
	}

	private static function event_staff_remove( array $p ): array {
		Plugin::activities()->remove_staff( (int) $p['activity_id'], (int) ( $p['person_id'] ?? 0 ) );
		return array( Ui::url( 'apse-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Gestore tolto.' );
	}

	/** Ingresso registrato o annullato da amministrazione (anche in un giorno diverso da quello dell'evento). */
	private static function checkin( array $p ): array {
		$r = Plugin::activities()->check_in( (int) $p['session_id'], (int) $p['person_id'], ! empty( $p['undo'] ), true );
		return array( Ui::url( 'apse-activity', array( 'id' => (int) $p['activity_id'] ) ), 'recorded' === $r['status'] ? 'Ingresso registrato.' : ( 'undone' === $r['status'] ? 'Registrazione annullata.' : 'Ingresso già registrato.' ) );
	}

	private static function cancel_booking( array $p ): array {
		Plugin::activities()->cancel_booking( (int) $p['session_id'], (int) $p['person_id'] );
		return array( Ui::url( 'apse-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Prenotazione annullata (eventuali pagamenti vanno rimborsati a mano).' );
	}

	private static function enroll( array $p ): array {
		Plugin::activities()->enroll( (int) $p['activity_id'], (int) ( $p['person_id'] ?? 0 ), (string) $p['start_month'] );
		return array( Ui::url( 'apse-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Iscrizione registrata.' );
	}

	private static function cancel_enrollment( array $p ): array {
		Plugin::activities()->cancel( (int) $p['activity_id'], (int) $p['person_id'], (string) $p['last_month'] );
		return array( Ui::url( 'apse-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Cancellazione registrata: i mesi successivi non sono più dovuti.' );
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
		return array( Ui::url( 'apse-ledger' ), $n > 1 ? "Incasso registrato ($n voci)." : 'Incasso registrato.' );
	}

	private static function add_attachment( array $p ): array {
		$tx  = (int) ( $p['transaction_id'] ?? 0 );
		$ids = Attachments::add( $tx, Attachments::prepare( Attachments::from_request(), $tx ) );
		if ( ! $ids ) {
			throw new \InvalidArgumentException( 'Scegli almeno un file da allegare.' );
		}
		return array( $p['_back'] ?? Ui::url( 'apse-ledger' ), count( $ids ) . ( 1 === count( $ids ) ? ' allegato aggiunto.' : ' allegati aggiunti.' ) );
	}

	private static function remove_attachment( array $p ): array {
		Attachments::remove( (int) ( $p['id'] ?? 0 ) );
		return array( $p['_back'] ?? Ui::url( 'apse-ledger' ), 'Allegato tolto dall\'elenco (resta nel registro azioni).' );
	}

	private static function save_expense( array $p ): array {
		$docs = Attachments::prepare( Attachments::from_request() ); // controllati PRIMA di registrare: se un file non va, non si registra nulla
		$tx   = Plugin::ledger()->record_expense(
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
		$ids = Attachments::add( $tx, $docs );
		return array( Ui::url( 'apse-ledger' ), 'Spesa registrata' . ( $ids ? ' con ' . count( $ids ) . ( 1 === count( $ids ) ? ' allegato.' : ' allegati.' ) : '.' ) );
	}

	private static function save_transfer( array $p ): array {
		Plugin::ledger()->record_transfer(
			(string) ( $p['date'] ?? '' ), (int) ( $p['from_id'] ?? 0 ), (int) ( $p['to_id'] ?? 0 ),
			Money::parse( $p['amount'] ?? '' ) ?? 0, (string) ( $p['method'] ?? 'other' ), trim( (string) ( $p['description'] ?? '' ) )
		);
		return array( Ui::url( 'apse-ledger' ), 'Giroconto registrato.' );
	}

	private static function void_tx( array $p ): array {
		Plugin::ledger()->void( (int) $p['id'], (string) ( $p['reason'] ?? '' ) );
		return array( $p['_back'] ?? Ui::url( 'apse-ledger' ), 'Movimento annullato (resta tracciato).' );
	}

	// ---------- Conti ----------

	private static function add_account( array $p ): array {
		Plugin::ledger()->add_account( (string) ( $p['name'] ?? '' ), (string) ( $p['type'] ?? '' ), Money::parse( $p['opening'] ?? '' ) ?? 0 );
		return array( Ui::url( 'apse-accounts' ), 'Conto aggiunto.' );
	}

	private static function cash_count( array $p ): array {
		$counted = Money::parse( $p['counted'] ?? '' );
		if ( null === $counted ) {
			throw new \InvalidArgumentException( 'Inserisci il saldo reale.' );
		}
		$diff = Plugin::ledger()->record_cash_count( (int) $p['account_id'], (string) $p['date'], $counted, ! empty( $p['adjust'] ), self::opt( $p, 'notes' ) );
		$msg  = 0 === $diff ? 'Verifica registrata: il saldo coincide.' : 'Verifica registrata: differenza ' . Money::format( $diff ) . ( ! empty( $p['adjust'] ) ? ' (rettificata).' : '.' );
		return array( Ui::url( 'apse-accounts' ), $msg );
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
		return array( Ui::url( 'apse-settings' ), $msg );
	}

	/** Prova la connessione a Stripe o PayPal con le chiavi salvate (solo quando l'amministratore preme il pulsante). */
	private static function test_gateway( array $p ): array {
		$provider = in_array( $p['provider'] ?? '', array( PaymentConfig::STRIPE, PaymentConfig::PAYPAL ), true ) ? $p['provider'] : '';
		$res      = Gateways::test( $provider, Settings::payment_config(), array( Gateways::class, 'wp_http' ) );
		Audit::log( 'gateway.tested', 'settings', null, array( 'provider' => $provider, 'ok' => $res['ok'] ) );
		if ( ! $res['ok'] ) {
			throw new \InvalidArgumentException( $res['message'] );
		}
		return array( Ui::url( 'apse-settings' ), $res['message'] );
	}

	private static function check_payments( array $p ): array {
		$r = Plugin::payments()->check_pending( true );
		return array( Ui::url( 'apse-payments' ), 'Controllati ' . $r['checked'] . ', registrati ' . $r['registered'] . ', scaduti ' . $r['expired'] . '.' );
	}

	private static function payment_reviewed( array $p ): array {
		Plugin::payments()->mark_reviewed( (int) $p['id'] );
		return array( Ui::url( 'apse-payments' ), 'Pagamento segnato come controllato.' );
	}

	private static function transfer_booking( array $p ): array {
		Plugin::activities()->transfer_booking( (int) $p['session_id'], (int) $p['person_id'], (int) ( $p['to_person_id'] ?? 0 ), false );
		return array( Ui::url( 'apse-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Nominativo cambiato: il pagamento già fatto passa alla nuova persona.' );
	}

	/** Crea le pagine standard (area soci, area volontari, attività) se non esistono già. */
	private static function create_pages( array $p ): array {
		$defs  = array(
			'area'      => array( 'Area soci', '[apsemplice_area_soci]' ),
			'volontari' => array( 'Area volontari', '[apsemplice_area_volontari]' ),
			'attivita'  => array( 'Attività ed eventi', '[apsemplice_attivita]' ),
		);
		$saved = (array) get_option( 'apse_pages', array() );
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
		update_option( 'apse_pages', $saved );
		if ( ! empty( $saved['area'] ) && 0 === (int) Settings::get( 'member_area_page_id' ) ) {
			Settings::update( array( 'member_area_page_id' => (int) $saved['area'] ) );
		}
		return array( Ui::url( 'apse-settings' ), $made ? 'Pagine create: ' . implode( ', ', $made ) . '.' : 'Le pagine standard esistono già.' );
	}

	// ---------- Import (Excel / CSV) ----------

	private static function import_preview( array $p ): array {
		$f = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification
		if ( empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) { // phpcs:ignore WordPress.Security
			throw new \InvalidArgumentException( 'Scegli un file Excel o CSV da caricare.' );
		}
		if ( (int) $f['size'] > 20 * 1048576 ) {
			throw new \InvalidArgumentException( 'Il file supera i 20 MB: dividilo in più file.' );
		}
		$prev  = \ApSemplice\ImportService::preview_file(
			$f['tmp_name'],
			(string) $f['name'],
			array( 'default_type' => (string) ( $p['default_type'] ?? '' ), 'default_account_id' => (int) ( $p['default_account_id'] ?? 0 ) )
		);
		$token = wp_generate_password( 16, false );
		set_transient( 'apse_import_' . get_current_user_id() . '_' . $token, $prev, HOUR_IN_SECONDS );
		return array( Ui::url( 'apse-import', array( 'token' => $token ) ), 'File letto: controlla l\'anteprima prima di importare.' );
	}

	private static function save_card( array $p ): array {
		Settings::update( array( 'card_qr_enabled' => ! empty( $p['card_qr_enabled'] ) ? 1 : 0 ) );
		return array( Ui::url( 'apse-card' ), ! empty( $p['card_qr_enabled'] ) ? 'QR della tessera attivato.' : 'QR della tessera disattivato.' );
	}

	/** Contenuto di un file caricato (null se non ne è stato scelto uno). */
	private static function uploaded_bytes( string $key ): ?string {
		if ( empty( $_FILES[ $key ]['tmp_name'] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES[ $key ]['error'] ) { // phpcs:ignore WordPress.Security
			return null;
		}
		$f = $_FILES[ $key ]; // phpcs:ignore WordPress.Security
		if ( UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) || (int) $f['size'] > 1048576 ) {
			throw new \InvalidArgumentException( 'Caricamento del file non riuscito (massimo 1 MB).' );
		}
		return (string) file_get_contents( $f['tmp_name'] );
	}

	private static function save_wallet_apple( array $p ): array {
		$msg = \ApSemplice\Wallet::save_apple( array( 'password' => $p['apple_password'] ?? '', 'pass_type' => $p['pass_type'] ?? '', 'team' => $p['team'] ?? '' ), self::uploaded_bytes( 'apple_p12' ), self::uploaded_bytes( 'apple_wwdr' ) );
		return array( Ui::url( 'apse-card' ), $msg );
	}

	private static function save_wallet_google( array $p ): array {
		return array( Ui::url( 'apse-card' ), \ApSemplice\Wallet::save_google( array( 'issuer' => $p['issuer'] ?? '' ), self::uploaded_bytes( 'google_json' ) ) );
	}

	private static function wallet_clear( array $p ): array {
		\ApSemplice\Wallet::clear( (string) ( $p['which'] ?? '' ) );
		return array( Ui::url( 'apse-card' ), 'Credenziali rimosse.' );
	}

	private static function regen_qr( array $p ): array {
		Settings::regenerate_card_salt();
		return array( Ui::url( 'apse-card' ), 'QR rigenerati: i vecchi non funzionano più.' );
	}

	private static function import_undo( array $p ): array {
		$r    = \ApSemplice\ImportService::undo( (int) ( $p['batch_id'] ?? 0 ) );
		$msg  = 'Import annullato: ' . $r['voided'] . ' movimenti annullati';
		$msg .= $r['accounts_removed'] ? ', ' . $r['accounts_removed'] . ' conti tolti' : '';
		$msg .= $r['people_removed'] ? ', ' . $r['people_removed'] . ' soci/ospiti rimossi' : '';
		$msg .= $r['people_restored'] ? ', ' . $r['people_restored'] . ' schede ripristinate' : '';
		$msg .= $r['people_kept'] ? '. Restano perché già usati: ' . implode( '; ', array_slice( $r['people_kept'], 0, 6 ) ) : '';
		return array( Ui::url( 'apse-import' ), $msg . '.' );
	}

	private static function save_wpai( array $p ): array {
		Settings::update(
			array(
				'wpai_default_type'       => (string) ( $p['default_type'] ?? '' ),
				'wpai_default_account_id' => (int) ( $p['default_account_id'] ?? 0 ),
				'wpai_keep_balances'      => ! empty( $p['keep_balances'] ) ? 1 : 0,
				'wpai_mark_members'       => ! empty( $p['mark_members'] ) ? 1 : 0,
			)
		);
		return array( Ui::url( 'apse-wpai' ), 'Impostazioni salvate.' );
	}

	private static function wpai_process( array $p ): array {
		$r = \ApSemplice\WpAllImport::process();
		return array( Ui::url( 'apse-wpai' ), 'Elaborazione conclusa: ' . $r['people'] . ' soci/ospiti, ' . $r['ledger'] . ' movimenti, ' . $r['duplicates'] . ' già presenti, ' . $r['errors'] . ' con errori.' );
	}

	private static function wpai_retry( array $p ): array {
		return array( Ui::url( 'apse-wpai' ), \ApSemplice\WpAllImport::retry() . ' elementi rimessi in coda: premi "Elabora adesso".' );
	}

	private static function wpai_clear( array $p ): array {
		return array( Ui::url( 'apse-wpai' ), \ApSemplice\WpAllImport::clear_errors() . ' elementi con errori eliminati.' );
	}

	private static function import_apply( array $p ): array {
		$key  = 'apse_import_' . get_current_user_id() . '_' . sanitize_key( $p['token'] ?? '' );
		$prev = get_transient( $key );
		if ( ! is_array( $prev ) ) {
			throw new \InvalidArgumentException( 'L\'anteprima è scaduta: ricarica il file.' );
		}
		$res = \ApSemplice\ImportService::apply( $prev, array( 'mark_members' => ! empty( $p['mark_members'] ), 'keep_balances' => ! empty( $p['keep_balances'] ) ) );
		delete_transient( $key );
		$parts = array();
		if ( $res['people'] ) {
			$f       = $res['people']['failed'];
			$parts[] = 'Soci e ospiti: ' . $res['people']['created'] . ' creati, ' . $res['people']['updated'] . ' aggiornati' . ( $f ? ', ' . count( $f ) . ' non riusciti (' . implode( '; ', array_slice( $f, 0, 5 ) ) . ')' : '' );
		}
		if ( $res['ledger'] ) {
			$l       = $res['ledger'];
			$parts[] = 'Prima nota: ' . $l['created'] . ' movimenti' . ( $l['transfers'] ? ', ' . $l['transfers'] . ' giroconti' : '' ) . ( $l['duplicates'] ? ', ' . $l['duplicates'] . ' già presenti saltati' : '' )
				. ( $l['memberships'] ? ', ' . $l['memberships'] . ' iscrizioni registrate' : '' ) . ( $l['shifted'] ? ', saldi attuali invariati' : '' );
		}
		$msg = 'Import completato. ' . implode( '. ', $parts ) . '.' . ( $res['batch_id'] ? ' (Import n. ' . (int) $res['batch_id'] . ': si può annullare in blocco da "Importa".)' : '' );
		return array( $res['people'] && ! $res['ledger'] ? Ui::url( 'apse-people' ) : Ui::url( 'apse-ledger' ), $msg );
	}
}
