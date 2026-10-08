<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

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
		self::schedule_jobs();   // lavori periodici: la classe si carica solo quando il lavoro parte
		self::lazy_admin_post(); // download e azioni di admin-post: la classe si carica solo per quell'azione
		// Parti che servono solo se la funzione è presente e in uso: altrimenti nemmeno si caricano.
		if ( defined( 'PMXI_VERSION' ) || class_exists( 'PMXI_Plugin', false ) ) {
			WpAllImport::register(); // compatibilità con WP All Import (area di appoggio)
		}
		if ( Edition::has( 'pwa' ) && Settings::get( 'pwa_enabled' ) ) {
			Pwa::register(); // app installabile e notifiche
		}
		if ( Edition::has( 'payments' ) && class_exists( 'WooCommerce', false ) ) {
			add_action( 'plugins_loaded', array( WooBridge::class, 'register' ), 20 ); // compatibilità con WooCommerce
		}
		if ( is_admin() ) {
			Admin\Admin::init();
		}
	}

	/**
	 * Lavori periodici: hook => [frequenza, secondi al primo giro, classe, metodo, funzione che li richiede].
	 * Si registra il nome dell'hook e il richiamo con il nome della classe: la classe viene caricata solo quando WP-Cron esegue il lavoro.
	 */
	private static function schedule_jobs(): void {
		$jobs = array(
			'apse_release_holds'          => array( 'hourly', 900, 'Holds', 'release_expired', '' ),
			'apse_send_reminders'         => array( 'daily', 600, 'Reminders', 'run', '' ),
			'apse_broadcast_batch'        => array( '', 0, 'Broadcasts', 'process_all', 'broadcasts' ),
			'apse_purge_backups'          => array( 'hourly', 1200, 'Backup', 'purge_old', '' ),
			'apse_check_pending_payments' => array( 'hourly', 300, 'PaymentService', 'check_pending_job', 'payments' ),
		);
		foreach ( $jobs as $hook => $j ) {
			if ( '' !== $j[4] && ! Edition::has( $j[4] ) ) {
				continue;
			}
			add_action( $hook, array( __NAMESPACE__ . '\\' . $j[2], $j[3] ) );
			if ( '' !== $j[0] && ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + $j[1], $j[0], $hook );
			}
		}
	}

	/** Azione di admin-post => classe che la gestisce. */
	const ADMIN_POST = array(
		'apse_backup'          => 'Backup',
		'apse_backup_saved'    => 'Backup',
		'apse_receipt'         => 'Receipts',
		'apse_statement'       => 'Receipts',
		'apse_privacy_export'  => 'Privacy',
		'apse_export_texts'    => 'Texts',
		'apse_wallet_apple'    => 'Wallet',
		'apse_doc'             => 'Docs',
	);

	private static function lazy_admin_post(): void {
		add_action(
			'admin_init',
			function () {
				$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
				if ( '' !== $action ) {
					self::load_for_action( $action );
				}
			},
			1
		);
	}

	/** Carica e registra la classe che gestisce un'azione di admin-post. @return bool true se l'azione è una di quelle gestite così */
	public static function load_for_action( string $action ): bool {
		if ( ! isset( self::ADMIN_POST[ $action ] ) ) {
			return false;
		}
		$class = __NAMESPACE__ . '\\' . self::ADMIN_POST[ $action ];
		if ( ! class_exists( $class ) ) { // la funzione non è in questa edizione
			return false;
		}
		$class::register();
		return true;
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
