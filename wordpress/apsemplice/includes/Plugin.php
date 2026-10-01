<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

final class Plugin {

	/** Capability per usare il plugin. Per ora solo gli amministratori (vedi Install::grant_caps). */
	const CAP = 'apse_manage';

	/** Ruolo WordPress dato ai nuovi utenti creati per i soci: nessun accesso all'area di amministrazione. */
	const ROLE_MEMBER = 'apse_member';

	private static $services = array();

	public static function init(): void {
		Install::maybe_upgrade();
		Access::register();      // capability meta: apse_notify_activity, apse_view_person...
		Gatekeeper::register(); // i soci restano fuori da wp-admin
		Rest\Api::register();    // apsemplice/v1
		Frontend\Front::init();  // shortcode, contenuti riservati, blocchi, widget
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

	public static function people(): PeopleService {
		return self::$services['people'] ?? ( self::$services['people'] = new PeopleService() );
	}

	public static function activities(): ActivityService {
		return self::$services['activities'] ?? ( self::$services['activities'] = new ActivityService() );
	}

	public static function ledger(): LedgerService {
		return self::$services['ledger'] ?? ( self::$services['ledger'] = new LedgerService() );
	}

	public static function payments(): PaymentService {
		return self::$services['payments'] ?? ( self::$services['payments'] = new PaymentService() );
	}

	public static function reports(): ReportService {
		return self::$services['reports'] ?? ( self::$services['reports'] = new ReportService() );
	}
}
