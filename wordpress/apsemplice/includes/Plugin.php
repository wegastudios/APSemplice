<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

final class Plugin {

	/** Capability di amministrazione completa (impostazioni, pagamenti online, privacy, testi): solo gli amministratori. */
	const CAP = 'apse_manage';

	/** Capability per lavorare con soci, attività e contabilità: amministratori e ruolo Segreteria. */
	const CAP_OPS = 'apse_operate';

	/** Ruolo WordPress della segreteria: opera sul plugin senza essere amministratore del sito. */
	const ROLE_SECRETARY = 'apse_secretary';

	/** Ruolo WordPress dato ai nuovi utenti creati per i soci: nessun accesso all'area di amministrazione. */
	const ROLE_MEMBER = 'apse_member';

	private static $services = array();

	public static function init(): void {
		Install::maybe_upgrade();
		FiscalYears::maybe_open_current(); // il primo giorno dell'anno si apre il nuovo anno solare
		Access::register();      // capability meta: apse_notify_activity, apse_view_person...
		Gatekeeper::register(); // i soci restano fuori da wp-admin
		Rest\Api::register();    // apsemplice/v1
		Frontend\Front::init();  // shortcode, contenuti riservati, blocchi, widget
		Reminders::register();   // promemoria giornalieri (spenti finché non li accendi nelle impostazioni)
		Receipts::register();    // ricevute e attestazioni in PDF
		Texts::register();       // esportazione dei testi personalizzati
		Privacy::register();     // download dei propri dati
		WpAllImport::register(); // compatibilità con WP All Import (area di appoggio)
		add_action( 'apse_check_pending_payments', function () {
			self::payments()->check_pending();
		} );
		if ( ! wp_next_scheduled( 'apse_check_pending_payments' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'apse_check_pending_payments' );
		}
		if ( is_admin() ) {
			Admin\Admin::init();
		}
	}

	/** Versione di un file in assets/: cambia a ogni aggiornamento, così il browser non tiene in cache script e stili vecchi. */
	public static function asset_version( string $file ): string {
		$path = APSE_DIR . 'assets/' . $file;
		return APSE_VERSION . '.' . ( is_readable( $path ) ? (string) filemtime( $path ) : '0' );
	}

	public static function people(): PeopleService {
		return self::$services['people'] ?? ( self::$services['people'] = new PeopleService() );
	}

	public static function activities(): ActivityService {
		return self::$services['activities'] ?? ( self::$services['activities'] = new ActivityService() );
	}

	public static function ledger(): LedgerService {
		return self::$services['ledger'] ?? ( self::$services['ledger'] = new LedgerService() );
	}

	public static function funds(): FundService {
		return self::$services['funds'] ?? ( self::$services['funds'] = new FundService() );
	}

	public static function payments(): PaymentService {
		return self::$services['payments'] ?? ( self::$services['payments'] = new PaymentService() );
	}

	public static function reports(): ReportService {
		return self::$services['reports'] ?? ( self::$services['reports'] = new ReportService() );
	}
}
