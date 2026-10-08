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
		if ( \ApSemplice\Edition::has( 'license' ) ) {
			LicenseNotice::register();
		}
		RegistersActions::register();
		TechActions::register();
		\ApSemplice\Wizard::register();
	}

	/** Voce di menu => [titolo, schede]. Le schede sono pagine nascoste dal menu, raggiungibili dalla barra in cima. */
	const GROUPS = array(
		'apse-people'     => array( 'Rubrica', array( 'apse-people' => 'Soci e ospiti', 'apse-messages' => 'Comunicazioni' ) ),
		'apse-activities' => array( 'Corsi ed eventi', array( 'apse-activities' => 'Elenco' ) ),
		'apse-tools'      => array( 'Strumenti', array( 'apse-tools' => 'Panoramica', 'apse-import' => 'Importa da Excel/CSV', 'apse-wpai' => 'WP All Import', 'apse-exports' => 'Esporta', 'apse-calendar' => 'Calendari', 'apse-backup' => 'Copia di sicurezza', 'apse-tech' => 'Integrazioni' ) ),
		'apse-money'      => array( 'Soldi', array( 'apse-money' => 'Cassa', 'apse-ledger' => 'Prima nota', 'apse-income' => 'Nuovo incasso', 'apse-group' => 'Cassa per più persone', 'apse-expense' => 'Nuova spesa', 'apse-transfer' => 'Giroconto', 'apse-accounts' => 'Conti e fondi' ) ),
		'apse-accounting' => array( 'Contabilità', array( 'apse-accounting' => 'Anno solare', 'apse-years' => 'Anni solari', 'apse-reports' => 'Report', 'apse-statement' => 'Rendiconto', 'apse-fivepm' => 'Adempimenti (5x1000)' ) ),
		'apse-book'       => array( 'Registri', array( 'apse-book' => 'Libro soci', 'apse-minutes' => 'Verbali', 'apse-volunteers' => 'Assicurazioni', 'apse-attendance' => 'Presenze' ) ),
		'apse-settings'   => array( 'Impostazioni', array( 'apse-entity' => 'Dati e fiscalità', 'apse-comms' => 'Privacy, regolamento e ricevute', 'apse-texts' => 'Testi e lingua', 'apse-settings' => 'Soci e quote', 'apse-card' => 'Tessera, QR e Wallet', 'apse-roles' => 'Ruoli e accessi', 'apse-payments' => 'Pagamenti online', 'apse-acct' => 'Opzioni contabili', 'apse-app' => 'App e notifiche', 'apse-limits' => 'Limiti e soglie', 'apse-audit' => 'Registro azioni', 'apse-guide' => 'Guida iniziale', 'apse-reset' => 'Azzeramento dati' ) ),
	);

	/** Le Impostazioni sono in tre sezioni: ente e funzioni, tecniche, contabilità. Titolo => pagine (la prima è quella a cui porta la scheda). */
	const SETTINGS_SECTIONS = array(
		'Ente e fiscalità' => array( 'apse-entity' => 'Dati e fiscalità', 'apse-comms' => 'Privacy, regolamento e ricevute', 'apse-texts' => 'Testi e lingua' ),
		'Soci e identità'  => array( 'apse-settings' => 'Soci e quote', 'apse-card' => 'Tessera, QR e Wallet', 'apse-roles' => 'Ruoli e accessi' ),
		'Soldi'            => array( 'apse-payments' => 'Pagamenti online' ),
		'Contabilità'      => array( 'apse-acct' => 'Opzioni contabili' ),
		'Comunicazioni'    => array( 'apse-app' => 'App e notifiche' ),
		'Sistema'          => array( 'apse-limits' => 'Limiti e soglie', 'apse-audit' => 'Registro azioni', 'apse-guide' => 'Guida iniziale', 'apse-reset' => 'Azzeramento dati' ),
	);

	/** Pagine riservate agli amministratori (la segreteria non le vede). */
	const ADMIN_ONLY = array( 'apse-settings', 'apse-payments', 'apse-card', 'apse-comms', 'apse-texts', 'apse-backup', 'apse-audit', 'apse-wpai', 'apse-years', 'apse-tech', 'apse-roles', 'apse-acct', 'apse-app', 'apse-limits', 'apse-wizard', 'apse-entity', 'apse-activity-delete', 'apse-booking-delete', 'apse-reset' );

	/** Pagine di dettaglio => voce di menu a cui appartengono. */
	const PARENTS = array( 'apse-person' => 'apse-people', 'apse-activity' => 'apse-activities', 'apse-activity-delete' => 'apse-activities', 'apse-booking-delete' => 'apse-activities' );

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
		add_menu_page( 'APSemplice', 'APSemplice', $cap, 'apse', self::guard( 'apse', array( DashboardPage::class, 'render' ) ), 'dashicons-groups', 30 );
		$visible = array(
			array( 'apse', 'Bacheca', array( DashboardPage::class, 'render' ) ),
			array( 'apse-people', 'Rubrica', array( PeoplePage::class, 'render_list' ) ),
			array( 'apse-activities', 'Corsi ed eventi', array( ActivitiesPage::class, 'render_list' ) ),
			array( 'apse-money', 'Soldi', array( MoneyPage::class, 'render' ) ),
			array( 'apse-accounting', 'Contabilità', array( AccountingPage::class, 'render' ) ),
			array( 'apse-book', 'Registri', array( RegistersPage::class, 'render_book' ) ),
			array( 'apse-tools', 'Strumenti', array( ToolsPage::class, 'render' ) ),
			array( 'apse-settings', 'Impostazioni', array( SettingsPage::class, 'render' ) ),
		);
		foreach ( $visible as $s ) {
			if ( self::group_off( $s[0] ) ) {
				$s[1] = null; // la pagina resta raggiungibile (con l'avviso), ma non compare nel menu
			}
			add_submenu_page( null === $s[1] ? null : 'apse', (string) $s[1], (string) $s[1], in_array( $s[0], self::ADMIN_ONLY, true ) ? Plugin::CAP : $cap, $s[0], self::guard( $s[0], $s[2] ) );
		}
		// Schede e pagine di dettaglio: raggiungibili dai link e dalla barra in cima, non compaiono nel menu
		$hidden = array(
			array( 'apse-activity-delete', 'Elimina l\'evento', array( DeleteActivityPage::class, 'render' ) ),
			array( 'apse-booking-delete', 'Cancella l\'iscrizione', array( DeleteBookingPage::class, 'render' ) ),
			array( 'apse-reset', 'Azzeramento dati', array( ResetPage::class, 'render' ) ),
			array( 'apse-ledger', 'Prima nota', array( LedgerPage::class, 'render' ) ),
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
			array( 'apse-volunteers', 'Assicurazioni', array( RegistersPage::class, 'render_volunteers' ) ),
			array( 'apse-guide', 'Guida iniziale', array( GuidePage::class, 'render' ) ),
			array( 'apse-tech', 'Integrazioni', array( TechPage::class, 'render_integrations' ) ),
			array( 'apse-app', 'App e notifiche', array( AppPage::class, 'render' ) ),
			array( 'apse-roles', 'Ruoli e accessi', array( TechPage::class, 'render_roles' ) ),
			array( 'apse-limits', 'Limiti e soglie', array( LimitsPage::class, 'render' ) ),
			array( 'apse-wizard', 'Configurazione guidata', array( WizardPage::class, 'render' ) ),
			array( 'apse-entity', 'Dati dell\'ente e fiscalità', array( EntityPage::class, 'render' ) ),
			array( 'apse-exports', 'Esportazioni', array( ToolsPage::class, 'render_exports' ) ),
			array( 'apse-acct', 'Opzioni contabili', array( TechPage::class, 'render_accounting' ) ),
			array( 'apse-fivepm', '5x1000', array( FivePmPage::class, 'render' ) ),
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
			add_submenu_page( null, $s[1], $s[1], in_array( $s[0], self::ADMIN_ONLY, true ) ? Plugin::CAP : $cap, $s[0], self::guard( $s[0], $s[2] ) );
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
		$off     = self::disabled_pages();
		$visible = function ( string $slug ) use ( $off ) {
			return ! in_array( $slug, $off, true ) && ( ! in_array( $slug, self::ADMIN_ONLY, true ) || current_user_can( Plugin::CAP ) );
		};
		if ( 'apse-settings' === $main ) { // Impostazioni: prima la sezione, poi le schede della sezione
			$current = null;
			foreach ( self::SETTINGS_SECTIONS as $title => $pages ) {
				if ( isset( $pages[ $page ] ) ) {
					$current = $title;
				}
			}
			$html = '<nav class="nav-tab-wrapper apse-tabs">';
			foreach ( self::SETTINGS_SECTIONS as $title => $pages ) {
				$first = null;
				foreach ( array_keys( $pages ) as $slug ) {
					if ( $visible( $slug ) ) {
						$first = $slug;
						break;
					}
				}
				if ( null !== $first ) {
					$html .= '<a class="nav-tab' . ( $title === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( Ui::url( $first ) ) . '">' . esc_html( $title ) . '</a>';
				}
			}
			$html .= '</nav>';
			if ( null !== $current ) {
				$links = array();
				foreach ( self::SETTINGS_SECTIONS[ $current ] as $slug => $label ) {
					if ( $visible( $slug ) ) {
						$links[] = '<li><a' . ( $slug === $page ? ' class="current"' : '' ) . ' href="' . esc_url( Ui::url( $slug ) ) . '">' . esc_html( $label ) . '</a></li>';
					}
				}
				if ( count( $links ) > 1 ) {
					$html .= '<ul class="subsubsub apse-subtabs">' . implode( ' | ', $links ) . '</ul><br class="clear">';
				}
			}
			return $html;
		}
		$html = '<nav class="nav-tab-wrapper apse-tabs">';
		foreach ( $tabs as $slug => $label ) {
			if ( ! $visible( $slug ) ) {
				continue;
			}
			$html .= '<a class="nav-tab' . ( $slug === $page ? ' nav-tab-active' : '' ) . '" href="' . esc_url( Ui::url( $slug ) ) . '">' . esc_html( $label ) . '</a>';
		}
		return $html . '</nav>';
	}

	/** Schede spente dalle impostazioni (report e rendiconto). @return string[] */
	public static function disabled_pages(): array {
		return array_merge( \ApSemplice\Modules::off_pages(), \ApSemplice\Edition::missing_pages() );
	}

	/** Voce di menu da nascondere: tutte le sue pagine sono spente. */
	private static function group_off( string $slug ): bool {
		$off = self::disabled_pages();
		if ( ! in_array( $slug, $off, true ) ) {
			return false;
		}
		foreach ( array_keys( self::GROUPS[ $slug ][1] ?? array() ) as $tab ) {
			if ( ! in_array( $tab, $off, true ) ) {
				return false;
			}
		}
		return true;
	}

	/** Una pagina di una parte spenta non mostra i suoi contenuti: dice come riaccenderla. */
	public static function guard( string $slug, callable $render ): callable {
		static $made = array(); // lo stesso oggetto per la stessa pagina: se è registrata due volte (menu principale e prima voce) WordPress la disegna una volta sola
		if ( isset( $made[ $slug ] ) ) {
			return $made[ $slug ];
		}
		return $made[ $slug ] = function () use ( $slug, $render ) {
			if ( in_array( $slug, \ApSemplice\Edition::missing_pages(), true ) ) {
				Ui::header( 'Funzione non inclusa' );
				echo '<p>Questa funzione fa parte di APSemplice Pro e non è inclusa in questa edizione.</p>';
				Ui::footer();
				return;
			}
			if ( in_array( $slug, self::disabled_pages(), true ) ) {
				Ui::header( 'Parte non in uso' );
				echo '<p>Questa parte del gestionale è spenta. I dati non sono stati toccati: si riaccende dalla <a href="' . esc_url( Ui::url( 'apse-wizard' ) ) . '">configurazione guidata</a>.</p>';
				Ui::footer();
				return;
			}
			$render();
		};
	}

	/** Se le schede dei report sono spente scrive l'avviso e dice di fermarsi. */
	public static function reports_off( string $title ): bool {
		if ( \ApSemplice\Settings::get( 'reports_enabled' ) ) {
			return false;
		}
		Ui::header( $title );
		echo '<p>Report e rendiconto sono spenti. Si riaccendono da <a href="' . esc_url( Ui::url( 'apse-acct' ) ) . '">Impostazioni → Contabilità</a>.</p>';
		Ui::footer();
		return true;
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, 'apse' ) ) {
			return;
		}
		wp_enqueue_style( 'apse-admin', APSE_URL . 'assets/admin.css', array(), Plugin::asset_version( 'admin.css' ) );
		wp_enqueue_script( 'apse-admin', APSE_URL . 'assets/admin.js', array(), Plugin::asset_version( 'admin.js' ), true );
	}
}
