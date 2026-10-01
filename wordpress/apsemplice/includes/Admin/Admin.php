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
	}

	public static function menu(): void {
		$cap = Plugin::CAP;
		add_menu_page( 'APSemplice', 'APSemplice', $cap, 'aps', array( DashboardPage::class, 'render' ), 'dashicons-groups', 30 );
		$subs = array(
			array( 'aps', 'Riepilogo', array( DashboardPage::class, 'render' ) ),
			array( 'aps-people', 'Soci e ospiti', array( PeoplePage::class, 'render_list' ) ),
			array( 'aps-activities', 'Attività', array( ActivitiesPage::class, 'render_list' ) ),
			array( 'aps-income', 'Nuovo incasso', array( IncomePage::class, 'render' ) ),
			array( 'aps-expense', 'Nuova spesa', array( ExpensePage::class, 'render' ) ),
			array( 'aps-transfer', 'Giroconto', array( TransferPage::class, 'render' ) ),
			array( 'aps-ledger', 'Prima nota', array( LedgerPage::class, 'render' ) ),
			array( 'aps-accounts', 'Conti e cassa', array( AccountsPage::class, 'render' ) ),
			array( 'aps-reports', 'Report', array( ReportsPage::class, 'render' ) ),
			array( 'aps-settings', 'Impostazioni', array( SettingsPage::class, 'render' ) ),
		);
		foreach ( $subs as $s ) {
			add_submenu_page( 'aps', $s[1], $s[1], $cap, $s[0], $s[2] );
		}
		// Pagine di dettaglio: raggiungibili dai link, non compaiono nel menu
		add_submenu_page( null, 'Scheda persona', 'Scheda persona', $cap, 'aps-person', array( PeoplePage::class, 'render_edit' ) );
		add_submenu_page( null, 'Scheda attività', 'Scheda attività', $cap, 'aps-activity', array( ActivitiesPage::class, 'render_detail' ) );
		add_submenu_page( null, 'Importa soci', 'Importa soci', $cap, 'aps-import', array( ImportPage::class, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, 'aps' ) ) {
			return;
		}
		wp_enqueue_style( 'aps-admin', APS_URL . 'assets/admin.css', array(), APS_VERSION );
		wp_enqueue_script( 'aps-admin', APS_URL . 'assets/admin.js', array(), APS_VERSION, true );
	}
}
