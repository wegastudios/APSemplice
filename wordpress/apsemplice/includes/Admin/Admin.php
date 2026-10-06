<?php
namespace ApSemplice\Admin;

use ApSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'parent_file', array( __CLASS__, 'menu_parent' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		Actions::register();
		Exports::register();
		IncomePage::register_ajax();
		LicenseNotice::register();
		RegistersActions::register();
		\ApSemplice\Docs::register();
	}

	/** Voce di menu => [titolo, schede]. Le schede sono pagine nascoste dal menu, raggiungibili dalla barra in cima. */
	const GROUPS = array(
		'apse-people'     => array( 'Rubrica', array( 'apse-people' => 'Soci e ospiti', 'apse-messages' => 'Comunicazioni', 'apse-import' => 'Importa da Excel/CSV', 'apse-wpai' => 'WP All Import' ) ),
		'apse-activities' => array( 'Corsi ed eventi', array( 'apse-activities' => 'Elenco', 'apse-calendar' => 'Calendario' ) ),
		'apse-ledger'     => array( 'Contabilità', array( 'apse-ledger' => 'Prima nota', 'apse-income' => 'Nuovo incasso', 'apse-group' => 'Cassa per più persone', 'apse-expense' => 'Nuova spesa', 'apse-transfer' => 'Giroconto', 'apse-accounts' => 'Conti e fondi', 'apse-years' => 'Anni solari', 'apse-reports' => 'Report', 'apse-statement' => 'Rendiconto' ) ),
		'apse-book'       => array( 'Registri', array( 'apse-book' => 'Libro soci', 'apse-minutes' => 'Verbali', 'apse-volunteers' => 'Volontari e assicurazione', 'apse-attendance' => 'Presenze' ) ),
		'apse-settings'   => array( 'Impostazioni', array( 'apse-settings' => 'Generale', 'apse-payments' => 'Pagamenti online', 'apse-card' => 'Tessera, QR e Wallet', 'apse-backup' => 'Copia di sicurezza', 'apse-comms' => 'Promemoria, privacy, regolamento e ricevute', 'apse-texts' => 'Testi personalizzati', 'apse-audit' => 'Registro azioni' ) ),
	);

	/** Pagine riservate agli amministratori (la segreteria non le vede). */
	const ADMIN_ONLY = array( 'apse-settings', 'apse-payments', 'apse-card', 'apse-comms', 'apse-texts', 'apse-backup', 'apse-audit', 'apse-wpai', 'apse-years' );

	/** Pagine di dettaglio => voce di menu a cui appartengono. */
	const PARENTS = array( 'apse-person' => 'apse-people', 'apse-activity' => 'apse-activities' );

	/** Voce di menu a cui appartiene una pagina ('apse' = Bacheca). */
	public static function menu_item_of( string $page ): string {
		if ( isset( self::PARENTS[ $page ] ) ) {
			return self::PARENTS[ $page ];
		}
		foreach ( self::GROUPS as $main => $g ) {
			if ( $main === $page || isset( $g[1][ $page ] ) ) {
				return $main;
			}
		}
		return 'apse';
	}

	public static function menu(): void {
		$cap = Plugin::CAP_OPS;
		add_menu_page( 'APSemplice', 'APSemplice', $cap, 'apse', array( DashboardPage::class, 'render' ), 'dashicons-groups', 30 );
		$visible = array(
			array( 'apse', 'Bacheca', array( DashboardPage::class, 'render' ) ),
			array( 'apse-people', 'Rubrica', array( PeoplePage::class, 'render_list' ) ),
			array( 'apse-activities', 'Corsi ed eventi', array( ActivitiesPage::class, 'render_list' ) ),
			array( 'apse-ledger', 'Contabilità', array( LedgerPage::class, 'render' ) ),
			array( 'apse-book', 'Registri', array( RegistersPage::class, 'render_book' ) ),
			array( 'apse-settings', 'Impostazioni', array( SettingsPage::class, 'render' ) ),
		);
		foreach ( $visible as $s ) {
			add_submenu_page( 'apse', $s[1], $s[1], in_array( $s[0], self::ADMIN_ONLY, true ) ? Plugin::CAP : $cap, $s[0], $s[2] );
		}
		// Schede e pagine di dettaglio: raggiungibili dai link e dalla barra in cima, non compaiono nel menu
		$hidden = array(
			array( 'apse-calendar', 'Calendario', array( CalendarPage::class, 'render' ) ),
			array( 'apse-years', 'Anni solari', array( YearsPage::class, 'render' ) ),
			array( 'apse-group', 'Cassa per più persone', array( GroupCashPage::class, 'render' ) ),
			array( 'apse-income', 'Nuovo incasso', array( IncomePage::class, 'render' ) ),
			array( 'apse-expense', 'Nuova spesa', array( ExpensePage::class, 'render' ) ),
			array( 'apse-transfer', 'Giroconto', array( TransferPage::class, 'render' ) ),
			array( 'apse-accounts', 'Conti e fondi', array( AccountsPage::class, 'render' ) ),
			array( 'apse-reports', 'Report', array( ReportsPage::class, 'render' ) ),
			array( 'apse-statement', 'Rendiconto', array( RegistersPage::class, 'render_statement' ) ),
			array( 'apse-minutes', 'Verbali', array( RegistersPage::class, 'render_minutes' ) ),
			array( 'apse-volunteers', 'Volontari e assicurazione', array( RegistersPage::class, 'render_volunteers' ) ),
			array( 'apse-attendance', 'Presenze', array( RegistersPage::class, 'render_attendance' ) ),
			array( 'apse-payments', 'Pagamenti online', array( PaymentsPage::class, 'render' ) ),
			array( 'apse-card', 'Tessera, QR e Wallet', array( CardPage::class, 'render' ) ),
			array( 'apse-messages', 'Comunicazioni', array( MessagesPage::class, 'render' ) ),
			array( 'apse-backup', 'Copia di sicurezza', array( BackupPage::class, 'render' ) ),
			array( 'apse-comms', 'Promemoria, privacy, regolamento e ricevute', array( CommsPage::class, 'render' ) ),
			array( 'apse-texts', 'Testi personalizzati', array( TextsPage::class, 'render' ) ),
			array( 'apse-audit', 'Registro azioni', array( AuditPage::class, 'render' ) ),
			array( 'apse-person', 'Scheda persona', array( PeoplePage::class, 'render_edit' ) ),
			array( 'apse-activity', 'Scheda attività', array( ActivitiesPage::class, 'render_detail' ) ),
			array( 'apse-import', 'Importa soci', array( ImportPage::class, 'render' ) ),
			array( 'apse-wpai', 'Import con WP All Import', array( WpAiPage::class, 'render' ) ),
		);
		foreach ( $hidden as $s ) {
			add_submenu_page( null, $s[1], $s[1], in_array( $s[0], self::ADMIN_ONLY, true ) ? Plugin::CAP : $cap, $s[0], $s[2] );
		}
	}

	/** Tiene evidenziata la voce giusta del menu quando si è in una scheda nascosta. */
	public static function menu_parent( $parent_file ) {
		global $plugin_page, $submenu_file;
		if ( is_string( $plugin_page ) && 0 === strpos( $plugin_page, 'apse' ) ) {
			$submenu_file = self::menu_item_of( $plugin_page ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			return 'apse';
		}
		return $parent_file;
	}

	/** Barra delle schede in cima alle pagine di un gruppo (Contabilità, Impostazioni, Rubrica). */
	public static function tabs( string $page ): string {
		$main = self::menu_item_of( $page );
		$tabs = self::GROUPS[ $main ][1] ?? array();
		if ( ! $tabs || isset( self::PARENTS[ $page ] ) ) {
			return '';
		}
		$html = '<nav class="nav-tab-wrapper apse-tabs">';
		foreach ( $tabs as $slug => $label ) {
			if ( in_array( $slug, self::ADMIN_ONLY, true ) && ! current_user_can( Plugin::CAP ) ) {
				continue;
			}
			$html .= '<a class="nav-tab' . ( $slug === $page ? ' nav-tab-active' : '' ) . '" href="' . esc_url( Ui::url( $slug ) ) . '">' . esc_html( $label ) . '</a>';
		}
		return $html . '</nav>';
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, 'apse' ) ) {
			return;
		}
		wp_enqueue_style( 'apse-admin', APSE_URL . 'assets/admin.css', array(), Plugin::asset_version( 'admin.css' ) );
		wp_enqueue_script( 'apse-admin', APSE_URL . 'assets/admin.js', array(), Plugin::asset_version( 'admin.js' ), true );
	}
}
