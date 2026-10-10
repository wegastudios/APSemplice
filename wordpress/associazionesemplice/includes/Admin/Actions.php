<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Attachments;
use AssociazioneSemplice\Audit;
use AssociazioneSemplice\Fiscal;
use AssociazioneSemplice\Gateways;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\PaymentConfig;
use AssociazioneSemplice\PeopleCsv;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Gestori dei moduli (admin-post.php). Ogni gestore riceve $_POST già "unslashed" e restituisce
 * [url di destinazione, messaggio]. Gli errori di regola (\InvalidArgumentException) tornano alla
 * pagina di partenza con il messaggio, senza perdere nulla.
 */
final class Actions {

	/** Azioni riservate agli amministratori: impostazioni, pagamenti online, tessera e QR, anni solari, privacy, testi, tesoriere e segreteria. */
	const ADMIN_ONLY = array(
		'asem_save_settings', 'asem_save_payment_settings', 'asem_test_gateway', 'asem_save_card', 'asem_save_wallet_apple', 'asem_save_wallet_google', 'asem_wallet_clear', 'asem_regen_qr',
		'asem_save_look', 'asem_save_donate', 'asem_create_privacy_page', 'asem_save_ical', 'asem_regen_ical', 'asem_create_pages', 'asem_save_wpai', 'asem_wpai_process', 'asem_wpai_retry', 'asem_wpai_clear', 'asem_privacy_anonymize', 'asem_save_comms',
		'asem_save_terms', 'asem_save_texts', 'asem_import_texts', 'asem_reset_texts', 'asem_add_text', 'asem_create_year', 'asem_close_year', 'asem_reopen_year',
		'asem_delete_activity', 'asem_delete_booking', 'asem_purge_enrollments', 'asem_set_treasurer', 'asem_set_secretary', 'asem_set_board_role', 'asem_backup_restore', 'asem_save_levels', 'asem_save_language', 'asem_import_language', 'asem_delete_language',
	);

	/** Azioni che appartengono a una funzione avanzata: senza la funzione non si registrano. */
	const FEATURE_ACTIONS = array(
		'payments'   => array( 'asem_save_payment_settings', 'asem_test_gateway', 'asem_check_payments', 'asem_payment_reviewed' ),
		'funds'      => array( 'asem_save_group_cash', 'asem_save_transfer', 'asem_add_account', 'asem_update_account', 'asem_close_account', 'asem_reopen_account', 'asem_fund_create', 'asem_fund_deposit', 'asem_fund_release', 'asem_fund_settle' ),
		'fiscal'     => array( 'asem_create_year', 'asem_close_year', 'asem_reopen_year' ),
		'receipts'   => array( 'asem_receipt_email' ),
		'door_sales' => array( 'asem_walk_in' ),
		'broadcasts' => array( 'asem_broadcast_send', 'asem_broadcast_test', 'asem_broadcast_retry' ),
		'wallet'     => array( 'asem_save_wallet_apple', 'asem_save_wallet_google', 'asem_wallet_clear' ),
		'texts'      => array( 'asem_save_terms', 'asem_save_texts', 'asem_import_texts', 'asem_reset_texts', 'asem_add_text', 'asem_save_language', 'asem_import_language', 'asem_delete_language' ),
	);

	/** Capability richiesta da un'azione: amministrazione completa o solo operatività (segreteria). */
	public static function required_cap( string $action ): string {
		return in_array( $action, self::ADMIN_ONLY, true ) ? Plugin::CAP : Plugin::CAP_OPS;
	}

	public static function register(): void {
		$map = array(
			'asem_save_person'        => 'save_person',
			'asem_delete_person'      => 'delete_person',
			'asem_set_membership'     => 'set_membership',
			'asem_set_treasurer'      => 'set_treasurer',
			'asem_set_staff'          => 'set_staff',
			'asem_delete_activity'    => 'delete_activity',
			'asem_delete_booking'     => 'delete_booking',
			'asem_purge_enrollments'  => 'purge_enrollments',
			'asem_set_secretary'      => 'set_secretary',
			'asem_save_terms'         => 'save_terms',
			'asem_save_texts'         => 'save_texts',
			'asem_import_texts'       => 'import_texts',
			'asem_reset_texts'        => 'reset_texts',
			'asem_add_text'           => 'add_text',
			'asem_privacy_consent'    => 'privacy_consent',
			'asem_rules_record'       => 'rules_record',
			'asem_privacy_anonymize'  => 'privacy_anonymize',
			'asem_save_comms'         => 'save_comms',
			'asem_create_privacy_page' => 'create_privacy_page',
			'asem_reminders_run'      => 'reminders_run',
			'asem_receipt_email'      => 'receipt_email',
			'asem_set_board_role'     => 'set_board_role',
			'asem_save_levels'        => 'save_levels',
			'asem_save_language'      => 'save_language',
			'asem_import_language'    => 'import_language',
			'asem_delete_language'    => 'delete_language',
			'asem_regen_qr'           => 'regen_qr',
			'asem_save_card'          => 'save_card',
			'asem_save_wallet_apple'  => 'save_wallet_apple',
			'asem_save_wallet_google' => 'save_wallet_google',
			'asem_wallet_clear'       => 'wallet_clear',
			'asem_save_activity'      => 'save_activity',
			'asem_enroll'             => 'enroll',
			'asem_cancel_enrollment'  => 'cancel_enrollment',
			'asem_add_session'        => 'add_session',
			'asem_update_session'     => 'update_session',
			'asem_generate_sessions'  => 'generate_sessions',
			'asem_add_dates'          => 'add_dates',
			'asem_cancel_session'     => 'cancel_session',
			'asem_book'               => 'book',
			'asem_event_staff_add'    => 'event_staff_add',
			'asem_event_staff_remove' => 'event_staff_remove',
			'asem_event_staff_cash'   => 'event_staff_cash',
			'asem_checkin'            => 'checkin',
			'asem_promote_guest'      => 'promote_guest',
			'asem_access_done'        => 'access_done',
			'asem_access_approve'     => 'access_approve',
			'asem_send_notice'        => 'send_notice',
			'asem_walk_in'            => 'walk_in',
			'asem_transfer_booking'   => 'transfer_booking',
			'asem_test_gateway'       => 'test_gateway',
			'asem_check_payments'     => 'check_payments',
			'asem_payment_reviewed'   => 'payment_reviewed',
			'asem_cancel_booking'     => 'cancel_booking',
			'asem_waitlist_remove'    => 'waitlist_remove',
			'asem_backup_restore'     => 'backup_restore',
			'asem_broadcast_send'     => 'broadcast_send',
			'asem_broadcast_test'     => 'broadcast_test',
			'asem_broadcast_retry'    => 'broadcast_retry',
			'asem_save_income'        => 'save_income',
			'asem_save_group_cash'    => 'save_group_cash',
			'asem_save_expense'       => 'save_expense',
			'asem_add_attachment'     => 'add_attachment',
			'asem_remove_attachment'  => 'remove_attachment',
			'asem_save_transfer'      => 'save_transfer',
			'asem_void_tx'            => 'void_tx',
			'asem_add_account'        => 'add_account',
			'asem_suspend_member'     => 'suspend_member',
			'asem_suspend_expired'    => 'suspend_expired',
			'asem_reactivate_member'  => 'reactivate_member',
			'asem_create_year'        => 'create_year',
			'asem_close_year'         => 'close_year',
			'asem_reopen_year'        => 'reopen_year',
			'asem_update_account'     => 'update_account',
			'asem_close_account'      => 'close_account',
			'asem_reopen_account'     => 'reopen_account',
			'asem_fund_create'        => 'fund_create',
			'asem_fund_deposit'       => 'fund_deposit',
			'asem_fund_release'       => 'fund_release',
			'asem_fund_settle'        => 'fund_settle',
			'asem_cash_count'         => 'cash_count',
			'asem_save_settings'      => 'save_settings',
			'asem_quick_cash'         => 'quick_cash',
			'asem_save_look'          => 'save_look',
			'asem_save_donate'        => 'save_donate',
			'asem_save_ical'          => 'save_ical',
			'asem_regen_ical'         => 'regen_ical',
			'asem_quick_enroll'       => 'quick_enroll',
			'asem_save_payment_settings' => 'save_payment_settings',
			'asem_create_pages'       => 'create_pages',
			'asem_import_preview'     => 'import_preview',
			'asem_import_apply'       => 'import_apply',
			'asem_import_undo'        => 'import_undo',
			'asem_save_wpai'          => 'save_wpai',
			'asem_wpai_process'       => 'wpai_process',
			'asem_wpai_retry'         => 'wpai_retry',
			'asem_wpai_clear'         => 'wpai_clear',
		);
		foreach ( self::FEATURE_ACTIONS as $feature => $actions ) { // le azioni delle funzioni avanzate esistono solo se la funzione c'è
			if ( ! \AssociazioneSemplice\Edition::has( $feature ) ) {
				$map = array_diff_key( $map, array_flip( $actions ) );
				foreach ( $actions as $blocked ) {
					\AssociazioneSemplice\Edition::block_action( $blocked );
				}
			}
		}
		add_action( 'admin_post_asem_attachment', array( Attachments::class, 'handle_download' ) );
		foreach ( $map as $action => $method ) {
			add_action(
				'admin_post_' . $action,
				function () use ( $action, $method ) {
					if ( ! current_user_can( self::required_cap( $action ) ) ) {
						wp_die( 'Non autorizzato.', 403 );
					}
					check_admin_referer( $action );
					$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
					$back = ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : Ui::url( 'asem' );
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
			'address'        => $p['address'] ?? '',
			'zip'            => $p['zip'] ?? '',
			'city'           => $p['city'] ?? '',
			'province'       => $p['province'] ?? '',
			'host_person_id' => $p['host_person_id'] ?? '',
			'joined_on'      => $p['joined_on'] ?? '',
			'notes'          => $p['notes'] ?? '',
		);
		$id = (int) ( $p['id'] ?? 0 );
		if ( $id ) {
			Plugin::people()->update( $id, $data );
			if ( array_key_exists( 'level_id', $p ) ) {
				Plugin::people()->set_level_and_family( $id, (int) $p['level_id'], (int) ( $p['family_head_id'] ?? 0 ) );
			}
			return array( Ui::url( 'asem-person', array( 'id' => $id ) ), 'Scheda salvata.' );
		}
		$id = Plugin::people()->create( $data );
		if ( array_key_exists( 'level_id', $p ) ) {
			Plugin::people()->set_level_and_family( $id, (int) $p['level_id'], (int) ( $p['family_head_id'] ?? 0 ) );
		}
		return array( Ui::url( 'asem-person', array( 'id' => $id ) ), 'Persona creata.' );
	}

	private static function delete_person( array $p ): array {
		Plugin::people()->delete( (int) $p['id'] );
		return array( Ui::url( 'asem-people' ), 'Persona eliminata. L\'eventuale utente WordPress collegato non viene rimosso.' );
	}

	private static function set_membership( array $p ): array {
		Plugin::people()->set_membership( (int) $p['id'], (string) $p['social_year'], ! empty( $p['enabled'] ) );
		return array( Ui::url( 'asem-person', array( 'id' => (int) $p['id'] ) ), 'Iscrizione aggiornata.' );
	}

	private static function set_board_role( array $p ): array {
		$id   = (int) ( $p['id'] ?? 0 );
		$role = (string) ( $p['board_role'] ?? '' );
		Plugin::people()->set_board_role( $id, '' === $role ? null : $role );
		return array( Ui::url( 'asem-person', array( 'id' => $id ) ), '' === $role ? 'Carica tolta.' : 'Carica assegnata: ' . \AssociazioneSemplice\BoardRole::label( $role ) . '.' );
	}

	private static function save_language( array $p ): array {
		$code = (string) ( $p['language'] ?? '' );
		if ( ! isset( \AssociazioneSemplice\Languages::available()[ $code ] ) ) {
			throw new \InvalidArgumentException( 'Lingua non disponibile.' );
		}
		Settings::update( array( 'language' => $code ) );
		\AssociazioneSemplice\Texts::flush();
		return array( Ui::url( 'asem-texts' ), 'Lingua impostata: ' . \AssociazioneSemplice\Languages::available()[ $code ] . '.' );
	}

	private static function import_language( array $p ): array {
		if ( empty( $_FILES['lang_file']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['lang_file']['error'] || ! is_uploaded_file( $_FILES['lang_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security
			throw new \InvalidArgumentException( 'Scegli il file della traduzione.' );
		}
		if ( (int) $_FILES['lang_file']['size'] > 5 * 1048576 ) { // phpcs:ignore WordPress.Security
			throw new \InvalidArgumentException( 'Il file è troppo grande (massimo 5 MB).' );
		}
		$name   = (string) $_FILES['lang_file']['name']; // phpcs:ignore WordPress.Security
		$pairs  = array();
		if ( 'json' === strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$j = json_decode( (string) file_get_contents( (string) $_FILES['lang_file']['tmp_name'] ), true ); // phpcs:ignore WordPress.Security
			$pairs = is_array( $j ) && isset( $j['strings'] ) && is_array( $j['strings'] ) ? $j['strings'] : ( is_array( $j ) ? $j : array() );
		} else {
			$err = null;
			foreach ( \AssociazioneSemplice\SheetReader::read( (string) $_FILES['lang_file']['tmp_name'], $name ) as $s ) { // phpcs:ignore WordPress.Security
				try {
					$pairs = \AssociazioneSemplice\Languages::pairs_from_rows( $s['rows'] );
					break;
				} catch ( \InvalidArgumentException $e ) {
					$err = $e;
				}
			}
			if ( ! $pairs && $err ) {
				throw $err;
			}
		}
		$code = trim( (string) ( $p['code'] ?? '' ) );
		$n    = \AssociazioneSemplice\Languages::save_pack( $code, (string) ( $p['name'] ?? '' ), $pairs );
		return array( Ui::url( 'asem-texts' ), 'Traduzione caricata: ' . $n . ' frasi. Ora puoi sceglierla tra le lingue.' );
	}

	private static function delete_language( array $p ): array {
		$code = (string) ( $p['code'] ?? '' );
		\AssociazioneSemplice\Languages::delete_pack( $code );
		if ( Settings::get( 'language' ) === $code ) {
			Settings::update( array( 'language' => 'it' ) );
		}
		return array( Ui::url( 'asem-texts' ), 'Traduzione eliminata.' );
	}

	private static function save_levels( array $p ): array {
		if ( ! \AssociazioneSemplice\Edition::has( 'levels' ) ) {
			throw new \InvalidArgumentException( 'Questa edizione ha un\'unica quota associativa.' );
		}
		$rows = array();
		foreach ( (array) ( $p['level'] ?? array() ) as $r ) {
			if ( is_array( $r ) ) {
				$rows[] = $r;
			}
		}
		\AssociazioneSemplice\Levels::save( $rows );
		return array( Ui::url( 'asem-settings' ), 'Livelli di socio salvati.' );
	}

	private static function privacy_consent( array $p ): array {
		$id   = (int) ( $p['id'] ?? 0 );
		$mode = (string) ( $p['mode'] ?? '' );
		if ( 'clear' === $mode ) {
			\AssociazioneSemplice\Privacy::clear_consent( $id );
			return array( Ui::url( 'asem-person', array( 'id' => $id ) ), 'Consenso rimosso.' );
		}
		\AssociazioneSemplice\Privacy::set_consent( $id, $mode );
		return array( Ui::url( 'asem-person', array( 'id' => $id ) ), 'Consenso registrato.' );
	}

	private static function rules_record( array $p ): array {
		$id   = (int) ( $p['id'] ?? 0 );
		$mode = (string) ( $p['mode'] ?? '' );
		if ( 'clear' === $mode ) {
			\AssociazioneSemplice\Regulation::clear( $id );
			return array( Ui::url( 'asem-person', array( 'id' => $id ) ), 'Accettazione rimossa.' );
		}
		\AssociazioneSemplice\Regulation::accept( $id, $mode );
		return array( Ui::url( 'asem-person', array( 'id' => $id ) ), 'Accettazione registrata.' );
	}

	private static function privacy_anonymize( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		\AssociazioneSemplice\Privacy::anonymize( $id );
		return array( Ui::url( 'asem-comms' ), 'Persona anonimizzata: i dati personali sono stati rimossi, i movimenti contabili restano registrati.' );
	}

	private static function save_comms( array $p ): array {
		$txt = function ( string $k ) use ( $p ) {
			return trim( (string) ( $p[ $k ] ?? '' ) );
		};
		Settings::update(
			array(
				'reminders_enabled'         => ! empty( $p['reminders_enabled'] ) ? 1 : 0,
				'reminders_membership'      => ! empty( $p['reminders_membership'] ) ? 1 : 0,
				'reminders_membership_days' => (int) ( $p['reminders_membership_days'] ?? 30 ),
				'reminders_dues'            => ! empty( $p['reminders_dues'] ) ? 1 : 0,
				'reminders_events'          => ! empty( $p['reminders_events'] ) ? 1 : 0,
				'privacy_url'               => $txt( 'privacy_url' ),
				'privacy_email'             => $txt( 'privacy_email' ),
				'privacy_retention_years'   => (int) ( $p['privacy_retention_years'] ?? 5 ),
				'rules_enabled'             => ! empty( $p['rules_enabled'] ) ? 1 : 0,
				'rules_title'               => $txt( 'rules_title' ),
				'rules_text'                => (string) ( $p['rules_text'] ?? '' ),
				'rules_url'                 => $txt( 'rules_url' ),
				'rules_version'             => $txt( 'rules_version' ),
				'rules_block_booking'       => ! empty( $p['rules_block_booking'] ) ? 1 : 0,
				'receipt_footer'            => $txt( 'receipt_footer' ),
			)
		);
		return array( Ui::url( 'asem-comms' ), 'Impostazioni salvate.' );
	}

	private static function reminders_run( array $p ): array {
		if ( ! \AssociazioneSemplice\Reminders::enabled() ) {
			throw new \InvalidArgumentException( 'I promemoria sono disattivati: attivali e salva le impostazioni prima di inviarli.' );
		}
		$r = \AssociazioneSemplice\Reminders::run();
		return array( Ui::url( 'asem-comms' ), 'Promemoria inviati: ' . $r['membership'] . ' per la tessera, ' . $r['dues'] . ' per le mensilità, ' . $r['events'] . ' per gli eventi di domani.' );
	}

	private static function receipt_email( array $p ): array {
		\AssociazioneSemplice\Receipts::email( (string) ( $p['key'] ?? '' ) );
		return array( $p['_back'] ?? Ui::url( 'asem-ledger' ), 'Ricevuta inviata per email.' );
	}

	private static function save_terms( array $p ): array {
		if ( ! empty( $p['preset'] ) ) { // versione base: femminile (associazione, socie) o maschile (comitato, soci)
			$pre = \AssociazioneSemplice\Terms::PRESETS[ (string) $p['preset'] ] ?? null;
			if ( ! $pre ) {
				throw new \InvalidArgumentException( 'Versione non valida.' );
			}
			Settings::update( array( 'entity_type' => $pre[0], 'member_term' => $pre[1] ) );
			return array( Ui::url( 'asem-texts' ), 'Versione ' . $p['preset'] . ' impostata: tutti i testi sono adattati.' );
		}
		$ec = (string) ( $p['entity_types_custom'] ?? '' );
		$mc = (string) ( $p['member_terms_custom'] ?? '' );
		$ents = \AssociazioneSemplice\Terms::entity_types( $ec );
		$mems = \AssociazioneSemplice\Terms::member_terms( $mc );
		$ent  = mb_strtolower( trim( (string) ( $p['entity_type'] ?? '' ) ), 'UTF-8' );
		$mem  = mb_strtolower( trim( (string) ( $p['member_term'] ?? '' ) ), 'UTF-8' );
		if ( ! isset( $ents[ $ent ] ) ) {
			throw new \InvalidArgumentException( 'Scegli un tipo di ente dall\'elenco (o aggiungilo a mano: una riga «nome;m» oppure «nome;f»).' );
		}
		if ( ! isset( $mems[ $mem ] ) ) {
			throw new \InvalidArgumentException( 'Scegli un termine dall\'elenco (o aggiungilo a mano: una riga «singolare;plurale;m» oppure «…;f»).' );
		}
		Settings::update( array( 'entity_type' => $ent, 'entity_types_custom' => $ec, 'member_term' => $mem, 'member_terms_custom' => $mc ) );
		return array( Ui::url( 'asem-texts' ), 'Tipo di ente e termini salvati: i testi sono adattati.' );
	}

	private static function save_texts( array $p ): array {
		$ov = \AssociazioneSemplice\TextsEngine::overrides();
		$by = array();
		foreach ( \AssociazioneSemplice\TextsEngine::rows() as $r ) {
			$by[ md5( $r['text'] ) ] = $r['text'];
		}
		foreach ( (array) ( $p['t'] ?? array() ) as $hash => $custom ) {
			if ( ! isset( $by[ (string) $hash ] ) ) {
				continue; // solo testi che esistono davvero
			}
			$custom = trim( str_replace( "\r\n", "\n", (string) $custom ) );
			if ( '' === $custom ) {
				unset( $ov[ $by[ $hash ] ] );
			} else {
				$ov[ $by[ $hash ] ] = $custom;
			}
		}
		\AssociazioneSemplice\TextsEngine::save_overrides( $ov );
		return array( $p['_back'] ?? Ui::url( 'asem-texts' ), 'Testi salvati.' );
	}

	private static function import_texts( array $p ): array {
		if ( empty( $_FILES['texts_file']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['texts_file']['error'] || ! is_uploaded_file( $_FILES['texts_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security
			throw new \InvalidArgumentException( 'Scegli il file da importare.' );
		}
		if ( (int) $_FILES['texts_file']['size'] > 5 * 1048576 ) { // phpcs:ignore WordPress.Security
			throw new \InvalidArgumentException( 'Il file è troppo grande (massimo 5 MB).' );
		}
		$sheets = \AssociazioneSemplice\SheetReader::read( (string) $_FILES['texts_file']['tmp_name'], (string) $_FILES['texts_file']['name'] ); // phpcs:ignore WordPress.Security
		$res    = null;
		$err    = null;
		foreach ( $sheets as $s ) {
			try {
				$res = \AssociazioneSemplice\TextsEngine::import_rows( $s['rows'] );
				break;
			} catch ( \InvalidArgumentException $e ) {
				$err = $e;
			}
		}
		if ( ! $res ) {
			throw $err ?: new \InvalidArgumentException( 'File non valido.' );
		}
		return array( Ui::url( 'asem-texts' ), 'Importazione completata: ' . $res['set'] . ' testi impostati, ' . $res['removed'] . ' ripristinati, ' . $res['manual'] . ' aggiunte a mano' . ( $res['ignored'] ? ', ' . $res['ignored'] . ' righe ignorate' : '' ) . '.' );
	}

	private static function reset_texts( array $p ): array {
		\AssociazioneSemplice\TextsEngine::save_overrides( array() );
		return array( Ui::url( 'asem-texts' ), 'Testi originali ripristinati.' );
	}

	private static function add_text( array $p ): array {
		$o = trim( (string) ( $p['original'] ?? '' ) );
		$c = trim( (string) ( $p['custom'] ?? '' ) );
		if ( strlen( $o ) < \AssociazioneSemplice\TextsEngine::MIN_LEN || '' === $c ) {
			throw new \InvalidArgumentException( 'Scrivi il testo di oggi (almeno 3 caratteri) e quello nuovo.' );
		}
		$ov       = \AssociazioneSemplice\TextsEngine::overrides();
		$ov[ $o ] = $c;
		\AssociazioneSemplice\TextsEngine::save_overrides( $ov );
		return array( $p['_back'] ?? Ui::url( 'asem-texts' ), 'Sostituzione aggiunta.' );
	}

	private static function set_secretary( array $p ): array {
		$person = Plugin::people()->get( (int) ( $p['id'] ?? 0 ) );
		$user   = $person && ! empty( $person['wp_user_id'] ) ? get_userdata( (int) $person['wp_user_id'] ) : null;
		if ( ! $person || ! $user || ! MemberType::is_member( $person['type'] ) ) {
			throw new \InvalidArgumentException( 'Solo un socio con accesso al sito può far parte della segreteria.' );
		}
		if ( $user->has_cap( Plugin::CAP ) ) {
			throw new \InvalidArgumentException( 'È già amministratore del sito.' );
		}
		$on = ! empty( $p['enabled'] );
		if ( $on ) {
			$user->add_role( Plugin::ROLE_SECRETARY );
		} else {
			$user->remove_role( Plugin::ROLE_SECRETARY );
		}
		Audit::log( $on ? 'secretary.granted' : 'secretary.revoked', 'person', (int) $person['id'] );
		return array( Ui::url( 'asem-person', array( 'id' => (int) $person['id'] ) ), $on ? 'Ora fa parte della segreteria.' : 'Non fa più parte della segreteria.' );
	}

	private static function set_treasurer( array $p ): array {
		$person = Plugin::people()->get( (int) ( $p['id'] ?? 0 ) );
		if ( ! $person || empty( $person['wp_user_id'] ) || ! MemberType::is_member( $person['type'] ) ) {
			throw new \InvalidArgumentException( 'Solo un socio o volontario con accesso al sito può essere tesoriere.' );
		}
		$on = ! empty( $p['enabled'] );
		\AssociazioneSemplice\Access::set_treasurer( (int) $person['wp_user_id'], $on );
		Audit::log( $on ? 'treasurer.granted' : 'treasurer.revoked', 'person', (int) $person['id'] );
		return array( Ui::url( 'asem-person', array( 'id' => (int) $person['id'] ) ), $on ? 'Ora è tesoriere: può incassare, registrare spese, iscrivere soci e vendere gli eventi dall\'area riservata.' : 'Non è più tesoriere.' );
	}

	/** Staff dell'ente: può verificare gli accessi a tutti gli eventi (l'incasso resta una scelta per singolo evento). */
	private static function set_staff( array $p ): array {
		$person = Plugin::people()->get( (int) ( $p['id'] ?? 0 ) );
		if ( ! $person || empty( $person['wp_user_id'] ) || ! MemberType::is_member( $person['type'] ) ) {
			throw new \InvalidArgumentException( 'Solo un socio o volontario con accesso al sito può far parte dello staff.' );
		}
		$on = ! empty( $p['enabled'] );
		\AssociazioneSemplice\Access::set_entity_staff( (int) $person['wp_user_id'], $on );
		Audit::log( $on ? 'staff.granted' : 'staff.revoked', 'person', (int) $person['id'] );
		return array( Ui::url( 'asem-person', array( 'id' => (int) $person['id'] ) ), $on ? 'Ora fa parte dello staff: verifica gli accessi a tutti gli eventi.' : 'Non fa più parte dello staff dell\'ente.' );
	}

	/** Eliminazione completa di un evento, con la sorte delle somme incassate. Conferma scritta obbligatoria. */
	private static function delete_activity( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		$a  = Plugin::activities()->get( $id );
		if ( ! $a ) {
			throw new \InvalidArgumentException( 'Evento non trovato.' );
		}
		$free = \AssociazioneSemplice\ActivityReset::activity_is_free( $id ); // nessun movimento in prima nota: basta una sola conferma (il pulsante della pagina)
		if ( ! $free ) {
			if ( empty( $p['confirm'] ) ) {
				throw new \InvalidArgumentException( 'Spunta la conferma: l\'eliminazione è definitiva.' );
			}
			if ( mb_strtolower( trim( (string) ( $p['typed'] ?? '' ) ), 'UTF-8' ) !== mb_strtolower( trim( (string) $a['name'] ), 'UTF-8' ) ) {
				throw new \InvalidArgumentException( 'Il nome scritto non corrisponde a quello dell\'evento: l\'evento non è stato eliminato.' );
			}
		} elseif ( empty( $p['mode'] ) ) {
			$p['mode'] = \AssociazioneSemplice\ActivityReset::REFUND;
		}
		$s    = \AssociazioneSemplice\ActivityReset::delete( $id, (string) ( $p['mode'] ?? '' ), array( 'notify' => ! empty( $p['notify'] ), 'void_costs' => ! empty( $p['void_costs'] ) ) );
		$ns   = (int) $s['sessions'];
		$nb   = (int) $s['bookings'];
		$nr   = (int) $s['refunds'];
		$msg  = 'Evento «' . $s['name'] . '» eliminato: ' . ( 1 === $ns ? '1 data' : $ns . ' date' ) . ' e ' . ( 1 === $nb ? '1 prenotazione attiva cancellata' : $nb . ' prenotazioni attive cancellate' ) . '.';
		$msg .= $nr ? ' ' . ( 1 === $nr ? 'Registrata 1 restituzione' : 'Registrate ' . $nr . ' restituzioni' ) . ' in prima nota (' . Money::format( (int) $s['income_cents'] ) . '): il denaro va restituito a chi ha pagato.' : '';
		$msg .= $s['voided'] ? ' Annullati ' . (int) $s['voided'] . ' incassi (' . Money::format( (int) $s['income_cents'] ) . '): non risultano più in prima nota.' : '';
		$msg .= $s['expenses_voided'] ? ' Annullate ' . (int) $s['expenses_voided'] . ' spese.' : '';
		$msg .= $s['notified'] ? ' Avvisate ' . (int) $s['notified'] . ' persone.' : '';
		return array( Ui::url( 'asem-activities' ), $msg );
	}

	/** Toglie dall'elenco di un corso gli iscritti con l'iscrizione già finita e senza incassi (la prima nota non cambia). */
	private static function purge_enrollments( array $p ): array {
		$aid = (int) ( $p['activity_id'] ?? 0 );
		if ( ! Plugin::activities()->get( $aid ) ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		if ( empty( $p['confirm'] ) ) {
			throw new \InvalidArgumentException( 'Spunta la conferma per togliere gli iscritti dall\'elenco.' );
		}
		$r   = \AssociazioneSemplice\ActivityReset::purge_ended_enrollments( $aid );
		$msg = $r['removed'] ? (int) $r['removed'] . ( 1 === $r['removed'] ? ' iscritto tolto' : ' iscritti tolti' ) . ' dall\'elenco: la prima nota non è cambiata.' : 'Nessun iscritto da togliere.';
		if ( $r['kept'] ) {
			$msg .= ' Restano perché hanno incassi registrati (cancellali uno per uno, scegliendo cosa fare delle somme): ' . implode( ', ', $r['kept'] ) . '.';
		}
		return array( Ui::url( 'asem-activity', array( 'id' => $aid ) ), $msg );
	}

	/** Cancellazione di una singola iscrizione, con la sorte delle somme incassate. Conferma scritta obbligatoria. */
	private static function delete_booking( array $p ): array {
		$aid = (int) ( $p['activity'] ?? 0 );
		$pid = (int) ( $p['person'] ?? 0 );
		$sid = (int) ( $p['session'] ?? 0 );
		$pre = \AssociazioneSemplice\ActivityReset::registration_preview( $aid, $pid, $sid );
		if ( ! $pre ) {
			throw new \InvalidArgumentException( 'Iscrizione non trovata.' );
		}
		$free = 0 === (int) $pre['income']['count']; // nessun incasso collegato: la prima nota non cambia, basta una sola conferma
		$who  = Plugin::people()->full_name( $pre['person'] );
		if ( ! $free ) {
			if ( empty( $p['confirm'] ) ) {
				throw new \InvalidArgumentException( 'Spunta la conferma: la cancellazione è definitiva.' );
			}
			if ( mb_strtolower( trim( (string) ( $p['typed'] ?? '' ) ), 'UTF-8' ) !== mb_strtolower( $who, 'UTF-8' ) ) {
				throw new \InvalidArgumentException( 'Il nome scritto non corrisponde: l\'iscrizione non è stata cancellata.' );
			}
		} elseif ( empty( $p['mode'] ) ) {
			$p['mode'] = \AssociazioneSemplice\ActivityReset::REFUND;
		}
		$s    = \AssociazioneSemplice\ActivityReset::delete_registration( $aid, $pid, $sid, (string) ( $p['mode'] ?? '' ), array( 'notify' => ! empty( $p['notify'] ) ) );
		$msg  = 'Iscrizione di ' . $s['name'] . ' cancellata.';
		$msg .= $s['refunds'] ? ' Registrata la restituzione di ' . Money::format( (int) $s['income_cents'] ) . ' in prima nota: il denaro va restituito a chi ha pagato.' : '';
		$msg .= $s['voided'] ? ' Annullati ' . (int) $s['voided'] . ' incassi (' . Money::format( (int) $s['income_cents'] ) . '): non risultano più in prima nota.' : '';
		return array( Ui::url( 'asem-activity', array( 'id' => $aid ) ), $msg );
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
			'end_time'     => (string) ( $p['end_time'] ?? '' ),
			'location'     => (string) ( $p['location'] ?? '' ),
			'capacity'     => (string) ( $p['capacity'] ?? '' ),
		);
	}

	/** Quota per il rimborso: euro (fisso) o percentuale, in centesimi / centesimi di punto percentuale. */
	private static function fund_value( array $p ): int {
		$raw = trim( (string) ( $p['fund_value'] ?? '' ) );
		if ( '' === $raw || '' === (string) ( $p['fund_mode'] ?? '' ) ) {
			return 0;
		}
		if ( 'percent' === $p['fund_mode'] ) {
			return (int) round( (float) str_replace( ',', '.', $raw ) * 100 );
		}
		return Money::parse( $raw ) ?? 0;
	}

	/** Giorni della settimana (1..7) di una riga ricorrente: "ogni <giorno della data>", tutti i giorni oppure dal lunedì al venerdì. */
	private static function repeat_days( array $w ): array {
		$repeat = (string) ( $w['repeat'] ?? 'weekly' );
		if ( 'daily' === $repeat ) {
			return array( 1, 2, 3, 4, 5, 6, 7 );
		}
		if ( 'weekdays' === $repeat ) {
			return array( 1, 2, 3, 4, 5 );
		}
		$ts = strtotime( (string) ( $w['date'] ?? '' ) . ' 12:00:00 UTC' );
		return $ts ? array( (int) gmdate( 'N', $ts ) ) : array();
	}

	/** Righe del modulo "quando": ogni riga ha data e orario e, se "ricorrente", si ripete ogni settimana fino a una data di fine. */
	private static function when_rows( array $p ): array {
		$rows = array();
		foreach ( (array) ( $p['when'] ?? array() ) as $w ) {
			$w = (array) $w;
			if ( isset( $w['type'] ) ) { // formato esplicito (type = single | weekly)
				if ( 'single' === $w['type'] && '' === trim( (string) ( $w['date'] ?? '' ) ) ) {
					continue;
				}
				$rows[] = array(
					'type' => (string) $w['type'], 'date' => (string) ( $w['date'] ?? '' ), 'from' => (string) ( $w['from'] ?? '' ), 'to' => (string) ( $w['to'] ?? '' ),
					'days' => (array) ( $w['days'] ?? array() ), 'start' => (string) ( $w['start'] ?? '' ), 'end' => (string) ( $w['end'] ?? '' ),
				);
				continue;
			}
			if ( '' === trim( (string) ( $w['date'] ?? '' ) ) ) {
				continue; // riga lasciata vuota
			}
			if ( ! empty( $w['recurring'] ) ) {
				$rows[] = array( 'type' => 'weekly', 'days' => self::repeat_days( $w ), 'from' => (string) ( $w['from'] ?? '' ), 'to' => (string) ( $w['to'] ?? '' ), 'start' => (string) $w['date'], 'end' => (string) ( $w['end'] ?? '' ) );
			} else {
				$rows[] = array( 'type' => 'single', 'date' => (string) $w['date'], 'from' => (string) ( $w['from'] ?? '' ), 'to' => (string) ( $w['to'] ?? '' ) );
			}
		}
		return $rows;
	}

	/** Lezioni di un corso dal modulo: le righe ricorrenti diventano lezioni settimanali, le altre date uniche. */
	private static function lesson_slots( array $p ): array {
		$out = array();
		foreach ( self::when_rows( $p ) as $r ) {
			if ( 'single' === $r['type'] ) {
				$out[] = array( 'type' => 'single', 'date' => $r['date'], 'start' => $r['from'], 'end' => $r['to'] );
				continue;
			}
			foreach ( \AssociazioneSemplice\Schedule::days( $r['days'] ) as $day ) {
				$out[] = array( 'type' => 'weekly', 'day' => $day, 'start' => $r['from'], 'end' => $r['to'], 'from' => $r['start'], 'until' => $r['end'] );
			}
		}
		return $out;
	}

	private static function add_dates( array $p ): array {
		$aid = (int) $p['activity_id'];
		$cap = isset( $p['capacity'] ) && '' !== trim( (string) $p['capacity'] ) ? (int) $p['capacity'] : null;
		$n   = Plugin::activities()->add_dates( $aid, self::when_rows( $p ), self::opt( $p, 'location' ), $cap );
		return array( Ui::url( 'asem-activity', array( 'id' => $aid ) ), 0 === $n ? 'Nessuna data nuova: erano già tutte presenti.' : $n . ( 1 === $n ? ' data aggiunta.' : ' date aggiunte.' ) );
	}

	private static function save_activity( array $p ): array {
		list( $vat_rate, $vat_mode ) = Ui::vat_input( $p ); // gli importi si memorizzano sempre IVA compresa (quanto paga chi partecipa)
		$fee   = self::fee_field( $p, 'fee' );
		$guest = self::fee_field( $p, 'guest_fee' );
		$data  = array(
			'name'                 => $p['name'] ?? '',
			'social_year'          => $p['social_year'] ?? '',
			'kind'                 => $p['kind'] ?? 'course',
			'instructor_person_id' => $p['instructor_person_id'] ?? '',
			'fee_cents'            => null === $fee ? 0 : Fiscal::gross_from_input( $fee, $vat_rate, $vat_mode ),
			'guest_fee_cents'      => null === $guest ? null : Fiscal::gross_from_input( $guest, $vat_rate, $vat_mode ),
			'cancellable'          => ! empty( $p['cancellable'] ) ? 1 : 0,
			'cancel_policy'        => $p['cancel_policy'] ?? '',
			'booking_qr'           => ! empty( $p['booking_qr'] ) ? 1 : 0,
			'lesson_slots'         => isset( $p['when'] ) ? self::lesson_slots( $p ) : null,
			'lesson_weekday'       => (int) ( $p['lesson_weekday'] ?? 0 ),
			'billing'              => (string) ( $p['billing'] ?? 'monthly' ),
			'location'             => (string) ( $p['location'] ?? '' ),
			'starts_on'            => (string) ( $p['starts_on'] ?? '' ),
			'ends_on'              => (string) ( $p['ends_on'] ?? '' ),
			'fund_mode'            => (string) ( $p['fund_mode'] ?? '' ),
			'fund_value'           => self::fund_value( $p ),
			'notes'                => $p['notes'] ?? '',
		);
		if ( array_key_exists( 'vat_rate', $p ) ) { // senza il campo (ente senza IVA) l'aliquota già memorizzata non si tocca
			$data['vat_rate'] = $vat_rate;
		}
		if ( array_key_exists( 'hold_hours', $p ) ) { // solo gli eventi hanno il campo
			$data['hold_hours'] = max( 0, min( 720, (int) $p['hold_hours'] ) );
		}
		$id = (int) ( $p['id'] ?? 0 );
		if ( $id ) {
			Plugin::activities()->update( $id, $data );
			return array( Ui::url( 'asem-activity', array( 'id' => $id ) ), 'Attività salvata.' );
		}
		$when = self::when_rows( $p );
		if ( 'event' === $data['kind'] ) {
			$data['session'] = self::session_fields( $p );
			if ( $when ) { // programma a regole: l'evento una tantum ha una sola data
				$dates = \AssociazioneSemplice\Schedule::expand( $when );
				if ( count( $dates ) > 1 ) {
					throw new \InvalidArgumentException( 'Un evento una tantum ha una sola data: per più date scegli "Evento ricorrente".' );
				}
				if ( $dates ) {
					$data['session'] = array( 'session_date' => $dates[0]['date'], 'start_time' => (string) $dates[0]['start'], 'end_time' => (string) $dates[0]['end'], 'location' => (string) ( $p['location'] ?? '' ), 'capacity' => (string) ( $p['capacity'] ?? '' ) );
				}
			}
		}
		$id = Plugin::activities()->create( $data );
		if ( 'recurring' === $data['kind'] && $when ) {
			$cap = isset( $p['capacity'] ) && '' !== trim( (string) $p['capacity'] ) ? (int) $p['capacity'] : null;
			$n   = Plugin::activities()->add_dates( $id, $when, self::opt( $p, 'location' ), $cap );
			return array( Ui::url( 'asem-activity', array( 'id' => $id ) ), 'Attività creata con ' . $n . ( 1 === $n ? ' data.' : ' date.' ) );
		}
		return array( Ui::url( 'asem-activity', array( 'id' => $id ) ), 'Attività creata.' );
	}

	private static function add_session( array $p ): array {
		$aid = (int) $p['activity_id'];
		Plugin::activities()->add_session( $aid, self::session_fields( $p ) );
		return array( Ui::url( 'asem-activity', array( 'id' => $aid ) ), 'Data aggiunta.' );
	}

	private static function update_session( array $p ): array {
		Plugin::activities()->update_session( (int) $p['session_id'], self::session_fields( $p ) );
		return array( Ui::url( 'asem-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Data aggiornata.' );
	}

	private static function generate_sessions( array $p ): array {
		$aid = (int) $p['activity_id'];
		$cap = isset( $p['capacity'] ) && '' !== trim( (string) $p['capacity'] ) ? (int) $p['capacity'] : null;
		$n   = Plugin::activities()->generate_weekly( $aid, (string) ( $p['from'] ?? '' ), (string) ( $p['to'] ?? '' ), self::opt( $p, 'start_time' ), self::opt( $p, 'location' ), $cap );
		return array( Ui::url( 'asem-activity', array( 'id' => $aid ) ), $n . ( 1 === $n ? ' data creata.' : ' date create.' ) );
	}

	private static function cancel_session( array $p ): array {
		Plugin::activities()->cancel_session( (int) $p['session_id'] );
		return array( Ui::url( 'asem-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Data annullata.' );
	}

	private static function book( array $p ): array {
		$pid = (int) ( $p['person_id'] ?? 0 );
		self::assert_not_suspended( $pid );
		Plugin::activities()->book( (int) $p['session_id'], $pid );
		return self::after_signup( $pid, Ui::url( 'asem-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Prenotazione registrata.', (int) $p['activity_id'], (int) $p['session_id'] );
	}

	/**
	 * Chi si presenta senza aver prenotato: prenota (creando l'ospite se serve), incassa il contributo e registra l'ingresso.
	 * Tutto insieme: se qualcosa non va (posti esauriti, conto non valido...) non resta scritto nulla.
	 */
	private static function walk_in( array $p ): array {
		$msg = \AssociazioneSemplice\DoorSales::sell( $p );
		return array( Ui::url( 'asem-activity', array( 'id' => (int) ( $p['activity_id'] ?? 0 ) ) ), $msg );
	}

	private static function send_notice( array $p ): array {
		$aid = (int) ( $p['activity_id'] ?? 0 );
		$r   = \AssociazioneSemplice\Notices::send( $aid, ! empty( $p['session_id'] ) ? (int) $p['session_id'] : null, (string) ( $p['subject'] ?? '' ), (string) ( $p['body'] ?? '' ) );
		return array( Ui::url( 'asem-activity', array( 'id' => $aid ) ), 'Avviso inviato a ' . $r['recipients'] . ' persone (' . $r['emailed'] . ' email inviate).' );
	}

	private static function access_done( array $p ): array {
		\AssociazioneSemplice\AccessRequests::remove( preg_replace( '/[^a-f0-9]/', '', (string) ( $p['id'] ?? '' ) ) );
		return array( Ui::url( 'asem' ), 'Richiesta chiusa.' );
	}

	private static function access_approve( array $p ): array {
		\AssociazioneSemplice\Frontend\FirstAccess::approve_change( preg_replace( '/[^a-f0-9]/', '', (string) ( $p['id'] ?? '' ) ) );
		return array( Ui::url( 'asem' ), 'Email aggiornata: al socio è arrivato il link per scegliere la password.' );
	}

	private static function promote_guest( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		Plugin::people()->promote_guest( $id, array( 'email' => $p['email'] ?? '', 'type' => $p['type'] ?? '', 'card_number' => $p['card_number'] ?? '', 'membership' => ! empty( $p['membership'] ) ) );
		return array( Ui::url( 'asem-person', array( 'id' => $id ) ), 'Ora è socio: la sua storia (eventi, corsi e pagamenti) è rimasta nella scheda.' );
	}

	private static function event_staff_add( array $p ): array {
		Plugin::activities()->add_staff( (int) $p['activity_id'], (int) ( $p['person_id'] ?? 0 ), ! empty( $p['can_cash'] ) );
		return array( Ui::url( 'asem-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Gestore dell\'evento aggiunto: ora vede i prenotati e registra gli ingressi dall\'area riservata.' );
	}

	private static function event_staff_cash( array $p ): array {
		Plugin::activities()->set_staff_cash( (int) $p['activity_id'], (int) ( $p['person_id'] ?? 0 ), ! empty( $p['can_cash'] ) );
		return array( Ui::url( 'asem-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Incasso sul posto aggiornato.' );
	}

	private static function event_staff_remove( array $p ): array {
		Plugin::activities()->remove_staff( (int) $p['activity_id'], (int) ( $p['person_id'] ?? 0 ) );
		return array( Ui::url( 'asem-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Gestore tolto.' );
	}

	/** Ingresso registrato o annullato da amministrazione (anche in un giorno diverso da quello dell'evento). */
	private static function checkin( array $p ): array {
		$r = Plugin::activities()->check_in( (int) $p['session_id'], (int) $p['person_id'], ! empty( $p['undo'] ), true );
		return array( Ui::url( 'asem-activity', array( 'id' => (int) $p['activity_id'] ) ), 'recorded' === $r['status'] ? 'Ingresso registrato.' : ( 'undone' === $r['status'] ? 'Registrazione annullata.' : 'Ingresso già registrato.' ) );
	}

	private static function backup_restore( array $p ): array {
		if ( empty( $p['confirm'] ) ) {
			throw new \InvalidArgumentException( 'Spunta la casella di conferma per ripristinare.' );
		}
		if ( empty( $_FILES['backup_file']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['backup_file']['error'] || ! is_uploaded_file( $_FILES['backup_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security
			throw new \InvalidArgumentException( 'Scegli il file della copia da ripristinare.' );
		}
		$r = \AssociazioneSemplice\Backup::restore( (string) $_FILES['backup_file']['tmp_name'] ); // phpcs:ignore WordPress.Security
		return array( Ui::url( 'asem-backup' ), 'Ripristino completato: ' . $r['rows'] . ' record in ' . $r['tables'] . ' tabelle' . ( $r['files'] ? ', ' . $r['files'] . ' allegati' : '' ) . '. Copia di sicurezza dello stato precedente: ' . $r['safety'] . '.' );
	}

	private static function broadcast_send( array $p ): array {
		list( $g, $ref ) = MessagesPage::parse_audience( (string) ( $p['audience'] ?? '' ) );
		$id = \AssociazioneSemplice\Broadcasts::create( (string) ( $p['subject'] ?? '' ), (string) ( $p['body'] ?? '' ), $g, $ref );
		$b  = \AssociazioneSemplice\Broadcasts::get( $id );
		return array( Ui::url( 'asem-messages', array( 'view' => $id ) ), 'Comunicazione avviata: ' . (int) $b['sent'] . ' email inviate su ' . (int) $b['total'] . ( (int) $b['total'] > (int) $b['sent'] ? ', le altre continuano da sole in background.' : '.' ) );
	}

	private static function broadcast_test( array $p ): array {
		if ( ! \AssociazioneSemplice\Broadcasts::send_test( (string) ( $p['subject'] ?? '' ), (string) ( $p['body'] ?? '' ) ) ) {
			throw new \InvalidArgumentException( 'Invio della prova non riuscito: controlla la posta in uscita del sito.' );
		}
		return array( $p['_back'] ?? Ui::url( 'asem-messages' ), 'Prova inviata al tuo indirizzo email.' );
	}

	private static function broadcast_retry( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		$n  = \AssociazioneSemplice\Broadcasts::retry_failed( $id );
		return array( Ui::url( 'asem-messages', array( 'view' => $id ) ), $n . ( 1 === $n ? ' email rimessa in coda.' : ' email rimesse in coda.' ) );
	}

	private static function waitlist_remove( array $p ): array {
		\AssociazioneSemplice\Waitlist::leave( (int) ( $p['session_id'] ?? 0 ), (int) ( $p['person_id'] ?? 0 ) );
		return array( Ui::url( 'asem-activity', array( 'id' => (int) ( $p['activity_id'] ?? 0 ) ) ), 'Tolto dalla lista d\'attesa.' );
	}

	private static function cancel_booking( array $p ): array {
		Plugin::activities()->cancel_booking( (int) $p['session_id'], (int) $p['person_id'] );
		return array( Ui::url( 'asem-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Prenotazione annullata (eventuali pagamenti vanno rimborsati a mano).' );
	}

	private static function enroll( array $p ): array {
		$pid = (int) ( $p['person_id'] ?? 0 );
		self::assert_not_suspended( $pid );
		Plugin::activities()->enroll( (int) $p['activity_id'], $pid, (string) $p['start_month'] );
		return self::after_signup( $pid, Ui::url( 'asem-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Iscrizione registrata.', (int) $p['activity_id'] );
	}

	private static function cancel_enrollment( array $p ): array {
		Plugin::activities()->cancel( (int) $p['activity_id'], (int) $p['person_id'], (string) $p['last_month'] );
		return array( Ui::url( 'asem-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Cancellazione registrata: i mesi successivi non sono più dovuti.' );
	}

	// ---------- Movimenti ----------

	private static function save_income( array $p ): array {
		$lines = array();
		list( $vat_rate, $vat_mode ) = Ui::vat_input( $p );
		$vat_auto = Ui::vat_is_auto( $p );
		foreach ( (array) ( $p['lines'] ?? array() ) as $l ) {
			$lines[] = ( array_key_exists( 'vat_rate', $p ) && ! $vat_auto ? array( 'vat_rate' => $vat_rate ) : array() ) + array( 'vat_mode' => $vat_mode ) + array(
				'category_id'      => (int) ( $l['category_id'] ?? 0 ),
				'amount_cents'     => Money::parse( $l['amount'] ?? '' ) ?? 0,
				'activity_id'      => (int) ( $l['activity_id'] ?? 0 ),
				'session_id'       => (int) ( $l['session_id'] ?? 0 ),
				'competence_month' => self::opt( $l, 'competence_month' ),
				'social_year'      => self::opt( $l, 'social_year' ),
				'description'      => (string) ( $l['description'] ?? '' ),
				'discount_cents'   => Money::parse( $l['discount'] ?? '' ) ?? 0,
				'discount_note'    => (string) ( $l['discount_note'] ?? '' ),
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
		return array( Ui::url( 'asem-ledger' ), $n > 1 ? "Incasso registrato ($n voci)." : 'Incasso registrato.' );
	}

	private static function add_attachment( array $p ): array {
		$tx  = (int) ( $p['transaction_id'] ?? 0 );
		$ids = Attachments::add( $tx, Attachments::prepare( Attachments::from_request(), $tx ) );
		if ( ! $ids ) {
			throw new \InvalidArgumentException( 'Scegli almeno un file da allegare.' );
		}
		return array( $p['_back'] ?? Ui::url( 'asem-ledger' ), count( $ids ) . ( 1 === count( $ids ) ? ' allegato aggiunto.' : ' allegati aggiunti.' ) );
	}

	private static function remove_attachment( array $p ): array {
		Attachments::remove( (int) ( $p['id'] ?? 0 ) );
		return array( $p['_back'] ?? Ui::url( 'asem-ledger' ), 'Allegato tolto dall\'elenco (resta nel registro azioni).' );
	}

	/**
	 * Cassa per più persone: chi paga salda eventi, corsi e quote per sé e per altri. Tutto in un'unica operazione (se qualcosa non va non resta scritto nulla):
	 * crea i nuovi ospiti, prenota o iscrive chi non lo è ancora e registra un solo incasso con le voci intestate ai beneficiari.
	 * Eccezione voluta: un evento si può pagare anche per un socio sospeso o con la tessera non in regola; per i corsi la tessera deve essere in regola.
	 */
	private static function save_group_cash( array $p ): array {
		$summary = \AssociazioneSemplice\GroupCash::record( $p );
		return array( Ui::url( 'asem-ledger' ), 'Incasso registrato: ' . $summary['lines'] . ( 1 === $summary['lines'] ? ' voce' : ' voci' ) . ' per ' . $summary['people'] . ( 1 === $summary['people'] ? ' persona' : ' persone' ) . ', totale ' . Money::format( $summary['cents'] ) . '.' );
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
			) + ( array_key_exists( 'vat_rate', $p ) ? array( 'vat_rate' => Ui::vat_input( $p )[0], 'vat_mode' => Ui::vat_input( $p )[1] ) : array() )
		);
		$ids = Attachments::add( $tx, $docs );
		return array( Ui::url( 'asem-ledger' ), 'Spesa registrata' . ( $ids ? ' con ' . count( $ids ) . ( 1 === count( $ids ) ? ' allegato.' : ' allegati.' ) : '.' ) );
	}

	private static function save_transfer( array $p ): array {
		Plugin::ledger()->record_transfer(
			(string) ( $p['date'] ?? '' ), (int) ( $p['from_id'] ?? 0 ), (int) ( $p['to_id'] ?? 0 ),
			Money::parse( $p['amount'] ?? '' ) ?? 0, (string) ( $p['method'] ?? '' ), trim( (string) ( $p['description'] ?? '' ) )
		);
		return array( Ui::url( 'asem-ledger' ), 'Giroconto registrato.' );
	}

	private static function void_tx( array $p ): array {
		Plugin::ledger()->void( (int) $p['id'], (string) ( $p['reason'] ?? '' ) );
		return array( $p['_back'] ?? Ui::url( 'asem-ledger' ), 'Movimento annullato (resta tracciato).' );
	}

	// ---------- Conti ----------

	private static function suspend_expired( array $p ): array {
		$n = Plugin::people()->suspend_expired( \AssociazioneSemplice\Limits::get( 'suspend_after_months' ) );
		return array( Ui::url( 'asem-people' ), 0 === $n ? 'Nessun socio da sospendere.' : $n . ( 1 === $n ? ' socio sospeso' : ' soci sospesi' ) . ': sono inattivi finché non li riattivi a mano.' );
	}

	/** Un socio sospeso è inattivo: va riattivato a mano prima di prenotarlo, iscriverlo o incassargli la quota. */
	/**
	 * Dopo un'iscrizione o una prenotazione: se c'è qualcosa da incassare (tessera non valida, mensilità dovuta, contributo non versato)
	 * si apre direttamente l'incasso con quella persona e le voci già compilate; altrimenti si resta dove si era.
	 */
	private static function after_signup( int $pid, string $back, string $msg, int $activity_id = 0, int $session_id = 0 ): array {
		$due = '' !== self::membership_warning( $pid );
		if ( ! $due && $pid ) {
			if ( $session_id ) { // evento: il contributo di questa prenotazione non è ancora stato versato
				foreach ( Plugin::activities()->unpaid_bookings_for_person( $pid ) as $b ) {
					if ( (int) $b['session_id'] === $session_id ) {
						$due = true;
					}
				}
			} elseif ( $activity_id ) { // corso: c'è una mensilità dovuta di questo corso
				foreach ( Plugin::activities()->status_for_person( $pid ) as $st ) {
					if ( (int) $st['enrollment']['activity_id'] === $activity_id && $st['summary']['unpaid_months'] ) {
						$due = true;
					}
				}
			}
		}
		if ( $due ) {
			return array( Ui::url( 'asem-income', array( 'person_id' => $pid, 'due' => 1 ) ), $msg . ' Ecco l\'incasso già compilato con quanto è dovuto.' );
		}
		return array( $back, $msg );
	}

	/** Avviso quando si iscrive o prenota un socio con la tessera non valida: deve rinnovare (la quota compare tra i pagamenti da incassare). */
	private static function membership_warning( int $person_id ): string {
		$person = $person_id ? Plugin::people()->get( $person_id ) : null;
		if ( $person && MemberType::is_member( $person['type'] ) && ! MemberType::is_auto_renewed( $person['type'] ) && ! Plugin::people()->is_active_member( $person_id ) ) {
			return ' Attenzione: la tessera non è valida, il socio deve rinnovare (la quota è tra i pagamenti da incassare in Bacheca).';
		}
		return '';
	}

	private static function assert_not_suspended( int $person_id ): void {
		if ( $person_id && Plugin::people()->is_suspended( $person_id ) ) {
			throw new \InvalidArgumentException( 'Il socio è sospeso (inattivo): riattivalo a mano dalla sua scheda prima di continuare.' );
		}
	}

	private static function suspend_member( array $p ): array {
		Plugin::people()->suspend( (int) ( $p['id'] ?? 0 ) );
		return array( $p['_back'] ?? Ui::url( 'asem' ), 'Socio sospeso: è inattivo finché non rinnova la tessera (oppure lo riattivi).' );
	}

	private static function reactivate_member( array $p ): array {
		Plugin::people()->reactivate( (int) ( $p['id'] ?? 0 ) );
		return array( $p['_back'] ?? Ui::url( 'asem' ), 'Socio riattivato.' );
	}

	private static function create_year( array $p ): array {
		\AssociazioneSemplice\FiscalYears::create( (int) ( $p['year'] ?? 0 ) );
		return array( Ui::url( 'asem-years' ), 'Anno solare creato.' );
	}

	private static function close_year( array $p ): array {
		\AssociazioneSemplice\FiscalYears::close( (int) ( $p['year'] ?? 0 ) );
		return array( Ui::url( 'asem-years' ), 'Anno solare chiuso: non accetta più incassi né spese.' );
	}

	private static function reopen_year( array $p ): array {
		\AssociazioneSemplice\FiscalYears::reopen( (int) ( $p['year'] ?? 0 ) );
		return array( Ui::url( 'asem-years' ), 'Anno solare riaperto.' );
	}

	private static function add_account( array $p ): array {
		Plugin::ledger()->add_account( (string) ( $p['name'] ?? '' ), (string) ( $p['type'] ?? '' ), Money::parse( $p['opening'] ?? '' ) ?? 0 );
		return array( Ui::url( 'asem-accounts' ), 'Conto aggiunto.' );
	}

	private static function update_account( array $p ): array {
		Plugin::ledger()->update_account( (int) ( $p['id'] ?? 0 ), (string) ( $p['name'] ?? '' ), (string) ( $p['type'] ?? '' ), Money::parse( $p['opening'] ?? '' ) ?? 0 );
		return array( Ui::url( 'asem-accounts' ), 'Conto aggiornato: i saldi sono stati ricalcolati.' );
	}

	private static function fund_create( array $p ): array {
		Plugin::funds()->create( (string) ( $p['name'] ?? '' ), Money::parse( $p['amount'] ?? '' ) ?? 0, ! empty( $p['person_id'] ) ? (int) $p['person_id'] : null );
		return array( Ui::url( 'asem-accounts' ), 'Fondo creato.' );
	}

	private static function fund_deposit( array $p ): array {
		Plugin::funds()->deposit( (int) ( $p['id'] ?? 0 ), Money::parse( $p['amount'] ?? '' ) ?? 0, (string) ( $p['date'] ?? current_time( 'Y-m-d' ) ) );
		return array( Ui::url( 'asem-accounts' ), 'Somma accantonata nel fondo.' );
	}

	private static function fund_release( array $p ): array {
		$cents = Money::parse( $p['amount'] ?? '' );
		if ( null === $cents ) {
			throw new \InvalidArgumentException( 'Indica l\'importo da liberare.' );
		}
		Plugin::funds()->release( (int) ( $p['id'] ?? 0 ), $cents, (string) ( $p['date'] ?? current_time( 'Y-m-d' ) ) );
		return array( Ui::url( 'asem-accounts' ), 'Quota liberata: è tornata nella disponibilità reale.' );
	}

	private static function fund_settle( array $p ): array {
		$tx = Plugin::funds()->settle( (int) ( $p['id'] ?? 0 ), (int) ( $p['account_id'] ?? 0 ), (string) ( $p['method'] ?? '' ), (string) ( $p['date'] ?? current_time( 'Y-m-d' ) ) );
		return array( Ui::url( 'asem-accounts' ), $tx ? 'Rimborso registrato in prima nota e fondo estinto.' : 'Fondo a zero: estinto.' );
	}

	private static function close_account( array $p ): array {
		Plugin::ledger()->close_account( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-accounts' ), 'Conto chiuso.' );
	}

	private static function reopen_account( array $p ): array {
		Plugin::ledger()->reopen_account( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-accounts' ), 'Conto riaperto.' );
	}

	private static function cash_count( array $p ): array {
		$counted = Money::parse( $p['counted'] ?? '' );
		if ( null === $counted ) {
			throw new \InvalidArgumentException( 'Inserisci il saldo reale.' );
		}
		$diff = Plugin::ledger()->record_cash_count( (int) $p['account_id'], (string) $p['date'], $counted, ! empty( $p['adjust'] ), self::opt( $p, 'notes' ) );
		$msg  = 0 === $diff ? 'Verifica registrata: il saldo coincide.' : 'Verifica registrata: differenza ' . Money::format( $diff ) . ( ! empty( $p['adjust'] ) ? ' (rettificata).' : '.' );
		return array( Ui::url( 'asem-accounts' ), $msg );
	}

	// ---------- Impostazioni ----------

	/** Iscrizione rapida dalla Bacheca: corso (dal mese in corso) o data di un evento. */
	private static function quick_enroll( array $p ): array {
		$pid    = (int) ( $p['person_id'] ?? 0 );
		$person = Plugin::people()->get( $pid );
		if ( ! $person ) {
			throw new \InvalidArgumentException( 'Scegli chi si iscrive.' );
		}
		self::assert_not_suspended( $pid );
		$target = (string) ( $p['target'] ?? '' );
		$name   = trim( $person['first_name'] . ' ' . $person['last_name'] );
		if ( 0 === strpos( $target, 'a:' ) ) {
			$a = Plugin::activities()->get( (int) substr( $target, 2 ) );
			if ( ! $a ) {
				throw new \InvalidArgumentException( 'Corso non trovato.' );
			}
			Plugin::activities()->enroll( (int) $a['id'], $pid, Settings::social_year()->clamp( substr( current_time( 'Y-m-d' ), 0, 7 ) ) );
			return self::after_signup( $pid, Ui::url( 'asem' ), $name . ' è iscritto/a a ' . $a['name'] . '.', (int) $a['id'] );
		}
		if ( 0 === strpos( $target, 's:' ) ) {
			Plugin::activities()->book( (int) substr( $target, 2 ), $pid );
			return self::after_signup( $pid, Ui::url( 'asem' ), $name . ' è prenotato/a.', 0, (int) substr( $target, 2 ) );
		}
		throw new \InvalidArgumentException( 'Scegli a cosa iscriverlo.' );
	}

	/** Cassa rapida della Bacheca: un incasso o una spesa semplici. */
	private static function quick_cash( array $p ): array {
		$ledger = Plugin::ledger();
		$cents  = Money::parse( $p['amount'] ?? '' );
		if ( null === $cents || $cents <= 0 ) {
			throw new \InvalidArgumentException( 'Indica un importo maggiore di zero.' );
		}
		$cat = null;
		foreach ( $ledger->categories() as $c ) {
			if ( (int) $c['id'] === (int) ( $p['category_id'] ?? 0 ) ) {
				$cat = $c;
			}
		}
		if ( ! $cat || ! in_array( $cat['kind'], array( 'donation', 'other_income', 'general_cost' ), true ) ) {
			throw new \InvalidArgumentException( 'Scegli cosa registrare.' );
		}
		$date   = current_time( 'Y-m-d' );
		$common = array( 'date' => $date, 'account_id' => (int) ( $p['account_id'] ?? 0 ), 'method' => (string) ( $p['method'] ?? '' ) );
		$desc   = trim( (string) ( $p['description'] ?? '' ) );
		if ( 'general_cost' === $cat['kind'] ) {
			$ledger->record_expense( $common + array( 'category_id' => (int) $cat['id'], 'amount_cents' => $cents, 'description' => $desc ) );
			return array( Ui::url( 'asem' ), 'Spesa registrata: ' . Money::format( $cents ) . '.' );
		}
		$ledger->record_receipt( $common + array( 'lines' => array( array( 'category_id' => (int) $cat['id'], 'amount_cents' => $cents, 'description' => $desc ) ) ) );
		return array( Ui::url( 'asem' ), 'Incasso registrato: ' . Money::format( $cents ) . '.' );
	}

	private static function save_settings( array $p ): array {
		$txt = function ( string $k ) use ( $p ) {
			return sanitize_text_field( $p[ $k ] ?? '' );
		};
		$ente = array(); // denominazione, codice fiscale e anno sociale hanno la loro pagina («Dati e fiscalità»): qui si salvano solo se il modulo li contiene
		foreach ( array( 'association_name', 'tax_code' ) as $k ) {
			if ( array_key_exists( $k, $p ) ) {
				$ente[ $k ] = $txt( $k );
			}
		}
		if ( array_key_exists( 'social_year_start_month', $p ) ) {
			$ente['social_year_start_month'] = (int) $p['social_year_start_month'];
		}
		if ( ! empty( $p['guests_present'] ) ) { // la casella non spuntata non arriva nel modulo
			$ente['guests_enabled'] = ! empty( $p['guests_enabled'] ) ? 1 : 0;
		}
		if ( ! empty( $p['uninstall_present'] ) ) {
			$ente['delete_on_uninstall'] = ! empty( $p['delete_on_uninstall'] ) ? 1 : 0;
		}
		Settings::update(
			$ente + array(
				'membership_fee_cents'    => Money::parse( $p['membership_fee'] ?? '' ) ?? 0,
				'family_discount_pct'     => (int) ( $p['family_discount_pct'] ?? 0 ),
				'founder_years'           => (int) ( $p['founder_years'] ?? 99 ),
				'guest_max_events'        => (int) ( $p['guest_max_events'] ?? 2 ),
				'board_councillors'       => (int) ( $p['board_councillors'] ?? 7 ),
				'member_area_page_id'     => (int) ( $p['member_area_page_id'] ?? 0 ),
				'license_key'             => $txt( 'license_key' ),
				'cancel_policy_default'   => $txt( 'cancel_policy_default' ),
				'payment_hint'            => sanitize_textarea_field( $p['payment_hint'] ?? '' ),
				'gate_message'            => $txt( 'gate_message' ),
			)
		);
		if ( ! empty( $p['features_present'] ) ) { // il modulo ha l'elenco delle funzioni: le caselle non spuntate sono spente
			$feat = array();
			foreach ( array_keys( SettingsPage::FEATURES ) as $k ) {
				$feat[ $k ] = ! empty( $p[ $k ] ) ? 1 : 0;
			}
			Settings::update( $feat );
		}
		return array( Ui::url( 'asem-settings' ), 'Impostazioni salvate.' );
	}

	private static function save_payment_settings( array $p ): array {
		$txt = function ( string $k ) use ( $p ) {
			return sanitize_text_field( $p[ $k ] ?? '' );
		};
		Settings::update(
			array(
				'payment_provider'        => $txt( 'payment_provider' ),
				'stripe_mode'             => $txt( 'stripe_mode' ),
				'stripe_publishable_key'  => $txt( 'stripe_publishable_key' ),
				'stripe_secret_key'       => $txt( 'stripe_secret_key' ),     // vuoto = lascia quella salvata
				'stripe_webhook_secret'   => $txt( 'stripe_webhook_secret' ),
				'paypal_mode'             => $txt( 'paypal_mode' ),
				'paypal_client_id'        => $txt( 'paypal_client_id' ),
				'paypal_client_secret'    => $txt( 'paypal_client_secret' ),
				'pay_label_stripe'        => $txt( 'pay_label_stripe' ),
				'pay_note_stripe'         => $txt( 'pay_note_stripe' ),
				'pay_label_paypal'        => $txt( 'pay_label_paypal' ),
				'pay_note_paypal'         => $txt( 'pay_note_paypal' ),
				'pay_label_woocommerce'   => $txt( 'pay_label_woocommerce' ),
				'pay_note_woocommerce'    => $txt( 'pay_note_woocommerce' ),
			)
		);
		foreach ( \AssociazioneSemplice\Settings::SECRET_KEYS as $k ) {
			if ( ! empty( $p[ 'clear_' . $k ] ) ) {
				Settings::clear_secret( $k );
			}
		}
		$check = PaymentConfig::validate( Settings::payment_config() );
		$msg   = 'Impostazioni dei pagamenti salvate.';
		if ( $check['errors'] ) {
			$msg .= ' Attenzione: ' . implode( ' ', $check['errors'] );
		}
		return array( Ui::url( 'asem-payments' ), $msg );
	}

	/** Prova la connessione a Stripe o PayPal con le chiavi salvate (solo quando l'amministratore preme il pulsante). */
	private static function test_gateway( array $p ): array {
		$provider = in_array( $p['provider'] ?? '', array( PaymentConfig::STRIPE, PaymentConfig::PAYPAL ), true ) ? $p['provider'] : '';
		$res      = Gateways::test( $provider, Settings::payment_config(), array( Gateways::class, 'wp_http' ) );
		Audit::log( 'gateway.tested', 'settings', null, array( 'provider' => $provider, 'ok' => $res['ok'] ) );
		if ( ! $res['ok'] ) {
			throw new \InvalidArgumentException( $res['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return array( Ui::url( 'asem-payments' ), $res['message'] );
	}

	private static function check_payments( array $p ): array {
		$r = Plugin::payments()->check_pending( true );
		return array( Ui::url( 'asem-payments' ), 'Controllati ' . $r['checked'] . ', registrati ' . $r['registered'] . ', scaduti ' . $r['expired'] . '.' );
	}

	private static function payment_reviewed( array $p ): array {
		Plugin::payments()->mark_reviewed( (int) $p['id'] );
		return array( Ui::url( 'asem-payments' ), 'Pagamento segnato come controllato.' );
	}

	private static function transfer_booking( array $p ): array {
		Plugin::activities()->transfer_booking( (int) $p['session_id'], (int) $p['person_id'], (int) ( $p['to_person_id'] ?? 0 ), false );
		return array( Ui::url( 'asem-activity', array( 'id' => (int) $p['activity_id'] ) ), 'Nominativo cambiato: il pagamento già fatto passa alla nuova persona.' );
	}

	/** Crea la pagina del sito con l'informativa privacy (si compila da sola con i dati dell'ente) e la collega alle impostazioni. */
	private static function create_privacy_page( array $p ): array {
		$made = \AssociazioneSemplice\Pages::create( array( 'privacy' ) );
		$id   = (int) ( \AssociazioneSemplice\Pages::existing()['privacy'] ?? 0 );
		if ( $id > 0 && '' === (string) Settings::get( 'privacy_url' ) ) {
			Settings::update( array( 'privacy_url' => (string) get_permalink( $id ) ) );
		}
		return array( Ui::url( 'asem-comms' ), $made ? 'Pagina «Informativa privacy» creata e collegata alle impostazioni.' : 'La pagina «Informativa privacy» esiste già.' );
	}

	/** Crea le pagine standard (area soci, area volontari, attività) se non esistono già. */
	private static function create_pages( array $p ): array {
		$made = \AssociazioneSemplice\Pages::create( array( 'area', 'volontari', 'attivita' ) );
		$have = \AssociazioneSemplice\Pages::existing();
		$menu = ! empty( $have['area'] ) && \AssociazioneSemplice\Pages::add_to_menu( (int) $have['area'] );
		return array( Ui::url( 'asem-settings' ), ( $made ? 'Pagine create: ' . implode( ', ', $made ) . '.' : 'Le pagine standard esistono già.' ) . ( $menu ? ' La voce «Area riservata» è nel menu del sito.' : '' ) );
	}

	// ---------- Import (Excel / CSV) ----------

	private static function import_preview( array $p ): array {
		$f = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- valore verificato e ripulito da chi lo usa
		if ( empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) { // phpcs:ignore WordPress.Security
			throw new \InvalidArgumentException( 'Scegli un file Excel o CSV da caricare.' );
		}
		if ( (int) $f['size'] > \AssociazioneSemplice\Limits::get( 'import_max_mb' ) * 1048576 ) {
			throw new \InvalidArgumentException( 'Il file supera i ' . \AssociazioneSemplice\Limits::get( 'import_max_mb' ) . ' MB: dividilo in più file.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$prev  = \AssociazioneSemplice\ImportService::preview_file(
			$f['tmp_name'],
			(string) $f['name'],
			array( 'default_type' => (string) ( $p['default_type'] ?? '' ), 'default_account_id' => (int) ( $p['default_account_id'] ?? 0 ) )
		);
		$token = sanitize_key( wp_generate_password( 16, false ) ); // minuscole: stessa forma con cui viene poi riletto
		set_transient( 'asem_import_' . get_current_user_id() . '_' . $token, $prev, HOUR_IN_SECONDS );
		return array( Ui::url( 'asem-import', array( 'token' => $token ) ), 'File letto: controlla l\'anteprima prima di importare.' );
	}

	private static function save_ical( array $p ): array {
		Settings::update( array( 'ical_enabled' => ! empty( $p['ical_enabled'] ) ? 1 : 0 ) );
		return array( Ui::url( 'asem-calendar' ), ! empty( $p['ical_enabled'] ) ? 'Calendario pubblicato: copia l\'indirizzo qui sotto.' : 'Calendario non più pubblicato.' );
	}

	private static function regen_ical( array $p ): array {
		\AssociazioneSemplice\Calendar::regenerate_token();
		return array( Ui::url( 'asem-calendar' ), 'Nuovo indirizzo del calendario: aggiorna il collegamento in Google Calendar.' );
	}

	private static function save_look( array $p ): array {
		$logo = (int) ( $p['logo_id'] ?? 0 );
		if ( $logo > 0 && ! wp_attachment_is_image( $logo ) ) {
			throw new \InvalidArgumentException( 'Il logo deve essere un\'immagine della libreria media.' );
		}
		$color = function ( string $custom, string $field ) use ( $p ): string {
			if ( empty( $p[ $custom ] ) ) {
				return ''; // senza spunta si usa il colore del sito
			}
			$c = \AssociazioneSemplice\Color::normalize( (string) ( $p[ $field ] ?? '' ) );
			if ( '' === $c ) {
				throw new \InvalidArgumentException( 'Scegli un colore valido oppure togli la spunta «Personalizza».' );
			}
			return $c;
		};
		$upd = array(
			'logo_id'         => max( 0, $logo ),
			'accent_color'    => $color( 'primary_custom', 'accent_color' ),
			'secondary_color' => $color( 'secondary_custom', 'secondary_color' ),
		);
		if ( ! empty( $p['card_present'] ) ) { // sezione «Tessera»: standard oppure su un'immagine propria
			$bg = (int) ( $p['card_bg_id'] ?? 0 );
			if ( $bg > 0 && ! wp_attachment_is_image( $bg ) ) {
				throw new \InvalidArgumentException( 'L\'immagine della tessera deve essere un\'immagine della libreria media.' );
			}
			$mode = \AssociazioneSemplice\CardLayout::MODE_IMAGE === (string) ( $p['card_mode'] ?? '' ) ? \AssociazioneSemplice\CardLayout::MODE_IMAGE : \AssociazioneSemplice\CardLayout::MODE_STANDARD;
			$upd['card_w_cm']   = \AssociazioneSemplice\CardLayout::clean_cm( $p['card_w_cm'] ?? '', 8.56 );
			$upd['card_h_cm']   = \AssociazioneSemplice\CardLayout::clean_cm( $p['card_h_cm'] ?? '', 5.4 );
			$upd['card_mode']   = $mode;
			$upd['card_bg_id']  = max( 0, $bg );
			$upd['card_layout'] = \AssociazioneSemplice\CardLayout::clean( $p['layout'] ?? array() );
		}
		Settings::update( $upd );
		return array( Ui::url( 'asem-look' ), 'Aspetto salvato.' );
	}

	private static function save_donate( array $p ): array {
		$account = \AssociazioneSemplice\Donations::clean_account( (string) ( $p['donate_paypal'] ?? '' ) );
		$on      = ! empty( $p['donate_enabled'] );
		if ( $on && '' === $account ) {
			throw new \InvalidArgumentException( 'Per accendere le donazioni scrivi l\'email del conto PayPal oppure il suo ID commerciante.' );
		}
		Settings::update(
			array(
				'donate_enabled' => $on ? 1 : 0,
				'donate_paypal'  => $account,
				'donate_amounts' => (string) ( $p['donate_amounts'] ?? '' ),
				'donate_purpose' => (string) ( $p['donate_purpose'] ?? '' ),
			)
		);
		return array( Ui::url( 'asem-donate' ), $on ? 'Donazioni attive: inserisci [associazionesemplice_donazioni] dove vuoi il modulo.' : 'Impostazioni salvate: le donazioni sono spente.' );
	}

	private static function save_card( array $p ): array {
		Settings::update( array( 'card_qr_enabled' => ! empty( $p['card_qr_enabled'] ) ? 1 : 0, 'ticket_qr_enabled' => ! empty( $p['ticket_qr_enabled'] ) ? 1 : 0, 'wallet_enabled' => ! empty( $p['wallet_enabled'] ) ? 1 : 0 ) );
		return array( Ui::url( 'asem-card' ), 'Impostazioni salvate.' );
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
		$msg = \AssociazioneSemplice\Wallet::save_apple( array( 'password' => $p['apple_password'] ?? '', 'pass_type' => $p['pass_type'] ?? '', 'team' => $p['team'] ?? '' ), self::uploaded_bytes( 'apple_p12' ), self::uploaded_bytes( 'apple_wwdr' ) );
		return array( Ui::url( 'asem-card' ), $msg );
	}

	private static function save_wallet_google( array $p ): array {
		return array( Ui::url( 'asem-card' ), \AssociazioneSemplice\Wallet::save_google( array( 'issuer' => $p['issuer'] ?? '' ), self::uploaded_bytes( 'google_json' ) ) );
	}

	private static function wallet_clear( array $p ): array {
		\AssociazioneSemplice\Wallet::clear( (string) ( $p['which'] ?? '' ) );
		return array( Ui::url( 'asem-card' ), 'Credenziali rimosse.' );
	}

	private static function regen_qr( array $p ): array {
		Settings::regenerate_card_salt();
		return array( Ui::url( 'asem-card' ), 'QR rigenerati: i vecchi non funzionano più.' );
	}

	private static function import_undo( array $p ): array {
		$r    = \AssociazioneSemplice\ImportService::undo( (int) ( $p['batch_id'] ?? 0 ) );
		$msg  = 'Import annullato: ' . $r['voided'] . ' movimenti annullati';
		$msg .= $r['accounts_removed'] ? ', ' . $r['accounts_removed'] . ' conti tolti' : '';
		$msg .= $r['people_removed'] ? ', ' . $r['people_removed'] . ' soci/ospiti rimossi' : '';
		$msg .= $r['people_restored'] ? ', ' . $r['people_restored'] . ' schede ripristinate' : '';
		$msg .= $r['people_kept'] ? '. Restano perché già usati: ' . implode( '; ', array_slice( $r['people_kept'], 0, 6 ) ) : '';
		return array( Ui::url( 'asem-import' ), $msg . '.' );
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
		return array( Ui::url( 'asem-wpai' ), 'Impostazioni salvate.' );
	}

	private static function wpai_process( array $p ): array {
		$r = \AssociazioneSemplice\WpAllImport::process();
		return array( Ui::url( 'asem-wpai' ), 'Elaborazione conclusa: ' . $r['people'] . ' soci/ospiti, ' . $r['ledger'] . ' movimenti, ' . $r['duplicates'] . ' già presenti, ' . $r['errors'] . ' con errori.' );
	}

	private static function wpai_retry( array $p ): array {
		return array( Ui::url( 'asem-wpai' ), \AssociazioneSemplice\WpAllImport::retry() . ' elementi rimessi in coda: premi "Elabora adesso".' );
	}

	private static function wpai_clear( array $p ): array {
		return array( Ui::url( 'asem-wpai' ), \AssociazioneSemplice\WpAllImport::clear_errors() . ' elementi con errori eliminati.' );
	}

	private static function import_apply( array $p ): array {
		$key  = 'asem_import_' . get_current_user_id() . '_' . sanitize_key( $p['token'] ?? '' );
		$prev = get_transient( $key );
		if ( ! is_array( $prev ) ) {
			throw new \InvalidArgumentException( 'L\'anteprima è scaduta: ricarica il file.' );
		}
		$res = \AssociazioneSemplice\ImportService::apply( $prev, array( 'mark_members' => ! empty( $p['mark_members'] ), 'keep_balances' => ! empty( $p['keep_balances'] ) ) );
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
		return array( $res['people'] && ! $res['ledger'] ? Ui::url( 'asem-people' ) : Ui::url( 'asem-ledger' ), $msg );
	}
}
