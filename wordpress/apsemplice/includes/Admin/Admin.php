<?php
namespace ApSemplice\Admin;

use ApSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		Actions::register();
		Exports::register();
		IncomePage::register_ajax();
		LicenseNotice::register();
	}

	public static function menu(): void {
		$cap = Plugin::CAP;
		add_menu_page( 'APSemplice', 'APSemplice', $cap, 'apse', array( DashboardPage::class, 'render' ), 'dashicons-groups', 30 );
		$subs = array(
			array( 'apse', 'Riepilogo', array( DashboardPage::class, 'render' ) ),
			array( 'apse-people', 'Soci e ospiti', array( PeoplePage::class, 'render_list' ) ),
			array( 'apse-activities', 'Attività', array( ActivitiesPage::class, 'render_list' ) ),
			array( 'apse-income', 'Nuovo incasso', array( IncomePage::class, 'render' ) ),
			array( 'apse-expense', 'Nuova spesa', array( ExpensePage::class, 'render' ) ),
			array( 'apse-transfer', 'Giroconto', array( TransferPage::class, 'render' ) ),
			array( 'apse-ledger', 'Prima nota', array( LedgerPage::class, 'render' ) ),
			array( 'apse-payments', 'Pagamenti online', array( PaymentsPage::class, 'render' ) ),
			array( 'apse-accounts', 'Conti e cassa', array( AccountsPage::class, 'render' ) ),
			array( 'apse-reports', 'Report', array( ReportsPage::class, 'render' ) ),
			array( 'apse-audit', 'Registro azioni', array( AuditPage::class, 'render' ) ),
			array( 'apse-settings', 'Impostazioni', array( SettingsPage::class, 'render' ) ),
		);
		foreach ( $subs as $s ) {
			add_submenu_page( 'apse', $s[1], $s[1], $cap, $s[0], $s[2] );
		}
		// Pagine di dettaglio: raggiungibili dai link, non compaiono nel menu
		add_submenu_page( null, 'Scheda persona', 'Scheda persona', $cap, 'apse-person', array( PeoplePage::class, 'render_edit' ) );
		add_submenu_page( null, 'Scheda attività', 'Scheda attività', $cap, 'apse-activity', array( ActivitiesPage::class, 'render_detail' ) );
		add_submenu_page( null, 'Importa soci', 'Importa soci', $cap, 'apse-import', array( ImportPage::class, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, 'apse' ) ) {
			return;
		}
		wp_enqueue_style( 'apse-admin', APSE_URL . 'assets/admin.css', array(), APSE_VERSION );
		wp_enqueue_script( 'apse-admin', APSE_URL . 'assets/admin.js', array(), APSE_VERSION, true );
	}
}
