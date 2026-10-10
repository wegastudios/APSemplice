<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'parent_file', array( __CLASS__, 'menu_parent' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		Actions::register();
		Exports::register();
		IncomePage::register_ajax();
		Dismiss::register();
		if ( \AssociazioneSemplice\Edition::has( 'license' ) ) {
			LicenseNotice::register();
		}
		RegistersActions::register();
		TechActions::register();
		\AssociazioneSemplice\Wizard::register();
	}

	/** Voce di menu => [titolo, schede]. Le schede sono pagine nascoste dal menu, raggiungibili dalla barra in cima. */
	const GROUPS = array(
		'asem-people'     => array( 'Rubrica soci', array( 'asem-people' => 'Soci e ospiti', 'asem-messages' => 'Comunicazioni' ) ),
		'asem-activities' => array( 'Corsi ed eventi', array( 'asem-activities' => 'Elenco' ) ),
		'asem-tools'      => array( 'Strumenti', array( 'asem-tools' => 'Panoramica', 'asem-import' => 'Importa da Excel/CSV', 'asem-wpai' => 'WP All Import', 'asem-exports' => 'Esporta', 'asem-calendar' => 'Calendari', 'asem-backup' => 'Copia di sicurezza', 'asem-tech' => 'Integrazioni' ) ),
		'asem-money'      => array( 'Cassa', array( 'asem-money' => 'Cassa', 'asem-ledger' => 'Prima nota', 'asem-income' => 'Nuovo incasso', 'asem-group' => 'Cassa per più persone', 'asem-expense' => 'Nuova spesa', 'asem-transfer' => 'Giroconto', 'asem-accounts' => 'Conti e fondi' ) ),
		'asem-accounting' => array( 'Contabilità', array( 'asem-accounting' => 'Anno solare', 'asem-years' => 'Anni solari', 'asem-reports' => 'Report', 'asem-statement' => 'Rendiconto', 'asem-fivepm' => 'Adempimenti (5x1000)' ) ),
		'asem-book'       => array( 'Registri', array( 'asem-book' => 'Libro soci', 'asem-minutes' => 'Verbali', 'asem-volunteers' => 'Assicurazioni', 'asem-attendance' => 'Presenze' ) ),
		'asem-settings'   => array( 'Impostazioni', array( 'asem-entity' => 'Dati e fiscalità', 'asem-comms' => 'Privacy, regolamento e ricevute', 'asem-texts' => 'Testi e lingua', 'asem-settings' => 'Soci e quote', 'asem-card' => 'Tessera, QR e Wallet', 'asem-roles' => 'Ruoli e accessi', 'asem-payments' => 'Pagamenti online', 'asem-acct' => 'Opzioni contabili', 'asem-app' => 'App e notifiche', 'asem-limits' => 'Limiti e soglie', 'asem-audit' => 'Registro azioni', 'asem-guide' => 'Guida iniziale', 'asem-reset' => 'Azzeramento dati' ) ),
	);

	/** Le Impostazioni sono in tre sezioni: ente e funzioni, tecniche, contabilità. Titolo => pagine (la prima è quella a cui porta la scheda). */
	const SETTINGS_SECTIONS = array(
		'Ente e fiscalità' => array( 'asem-entity' => 'Dati e fiscalità', 'asem-comms' => 'Privacy, regolamento e ricevute', 'asem-texts' => 'Testi e lingua' ),
		'Soci e identità'  => array( 'asem-settings' => 'Soci e quote', 'asem-look' => 'Aspetto', 'asem-card' => 'Tessera, QR e Wallet', 'asem-roles' => 'Ruoli e accessi' ),
		'Cassa'            => array( 'asem-payments' => 'Pagamenti online', 'asem-bank' => 'Bonifico', 'asem-donate' => 'Donazioni' ),
		'Contabilità'      => array( 'asem-acct' => 'Opzioni contabili' ),
		'Comunicazioni'    => array( 'asem-app' => 'App e notifiche' ),
		'Sistema'          => array( 'asem-limits' => 'Limiti e soglie', 'asem-audit' => 'Registro azioni', 'asem-guide' => 'Guida iniziale', 'asem-reset' => 'Azzeramento dati' ),
	);

	/** Pagine riservate agli amministratori (la segreteria non le vede). */
	const ADMIN_ONLY = array( 'asem-look', 'asem-pro', 'asem-bank', 'asem-donate', 'asem-settings', 'asem-payments', 'asem-card', 'asem-comms', 'asem-texts', 'asem-backup', 'asem-audit', 'asem-wpai', 'asem-years', 'asem-tech', 'asem-roles', 'asem-acct', 'asem-app', 'asem-limits', 'asem-wizard', 'asem-entity', 'asem-activity-delete', 'asem-booking-delete', 'asem-reset' );

	/** Pagine di dettaglio => voce di menu a cui appartengono. */
	const PARENTS = array( 'asem-person' => 'asem-people', 'asem-activity' => 'asem-activities', 'asem-activity-delete' => 'asem-activities', 'asem-booking-delete' => 'asem-activities' );

	/** Voce di menu a cui appartiene una pagina ('asem' = Bacheca). */
	public static function menu_item_of( string $page ): string {
		if ( isset( self::PARENTS[ $page ] ) ) {
			return self::PARENTS[ $page ];
		}
		foreach ( self::GROUPS as $main => $g ) {
			if ( $main === $page || isset( $g[1][ $page ] ) ) {
				return $main;
			}
		}
		return 'asem';
	}

	public static function menu(): void {
		$cap = Plugin::CAP_OPS;
		add_menu_page( 'AssociazioneSemplice', 'AssociazioneSemplice', $cap, 'asem', self::guard( 'asem', array( DashboardPage::class, 'render' ) ), 'dashicons-groups', 30 );
		$visible = array(
			array( 'asem', 'Bacheca', array( DashboardPage::class, 'render' ) ),
			array( 'asem-people', 'Rubrica soci', array( PeoplePage::class, 'render_list' ) ),
			array( 'asem-activities', 'Corsi ed eventi', array( ActivitiesPage::class, 'render_list' ) ),
			array( 'asem-money', 'Cassa', array( MoneyPage::class, 'render' ) ),
			array( 'asem-accounting', 'Contabilità', array( AccountingPage::class, 'render' ) ),
			array( 'asem-book', 'Registri', array( RegistersPage::class, 'render_book' ) ),
			array( 'asem-tools', 'Strumenti', array( ToolsPage::class, 'render' ) ),
			array( 'asem-settings', 'Impostazioni', array( SettingsPage::class, 'render' ) ),
		);
		if ( ProPage::needed() ) { // c'è qualcosa che questa installazione non ha: una pagina informativa, senza funzioni finte
			$visible[] = array( 'asem-pro', 'Scopri il Pro', array( ProPage::class, 'render' ) );
		}
		foreach ( $visible as $s ) {
			if ( self::group_off( $s[0] ) ) {
				$s[1] = null; // la pagina resta raggiungibile (con l'avviso), ma non compare nel menu
			}
			add_submenu_page( null === $s[1] ? null : 'asem', (string) $s[1], (string) $s[1], in_array( $s[0], self::ADMIN_ONLY, true ) ? Plugin::CAP : $cap, $s[0], self::guard( $s[0], $s[2] ) );
		}
		// Schede e pagine di dettaglio: raggiungibili dai link e dalla barra in cima, non compaiono nel menu
		$hidden = array(
			array( 'asem-activity-delete', 'Elimina l\'evento', array( DeleteActivityPage::class, 'render' ) ),
			array( 'asem-booking-delete', 'Cancella l\'iscrizione', array( DeleteBookingPage::class, 'render' ) ),
			array( 'asem-reset', 'Azzeramento dati', array( ResetPage::class, 'render' ) ),
			array( 'asem-ledger', 'Prima nota', array( LedgerPage::class, 'render' ) ),
			array( 'asem-calendar', 'Calendario', array( CalendarPage::class, 'render' ) ),
			array( 'asem-years', 'Anni solari', array( YearsPage::class, 'render' ) ),
			array( 'asem-group', 'Cassa per più persone', array( GroupCashPage::class, 'render' ) ),
			array( 'asem-income', 'Nuovo incasso', array( IncomePage::class, 'render' ) ),
			array( 'asem-expense', 'Nuova spesa', array( ExpensePage::class, 'render' ) ),
			array( 'asem-transfer', 'Giroconto', array( TransferPage::class, 'render' ) ),
			array( 'asem-accounts', 'Conti e fondi', array( AccountsPage::class, 'render' ) ),
			array( 'asem-reports', 'Report', array( ReportsPage::class, 'render' ) ),
			array( 'asem-statement', 'Rendiconto', array( RegistersPage::class, 'render_statement' ) ),
			array( 'asem-minutes', 'Verbali', array( RegistersPage::class, 'render_minutes' ) ),
			array( 'asem-volunteers', 'Assicurazioni', array( RegistersPage::class, 'render_volunteers' ) ),
			array( 'asem-guide', 'Guida iniziale', array( GuidePage::class, 'render' ) ),
			array( 'asem-tech', 'Integrazioni', array( TechPage::class, 'render_integrations' ) ),
			array( 'asem-app', 'App e notifiche', array( AppPage::class, 'render' ) ),
			array( 'asem-roles', 'Ruoli e accessi', array( TechPage::class, 'render_roles' ) ),
			array( 'asem-limits', 'Limiti e soglie', array( LimitsPage::class, 'render' ) ),
			array( 'asem-wizard', 'Configurazione guidata', array( WizardPage::class, 'render' ) ),
			array( 'asem-entity', 'Dati dell\'ente e fiscalità', array( EntityPage::class, 'render' ) ),
			array( 'asem-exports', 'Esportazioni', array( ToolsPage::class, 'render_exports' ) ),
			array( 'asem-acct', 'Opzioni contabili', array( TechPage::class, 'render_accounting' ) ),
			array( 'asem-fivepm', '5x1000', array( FivePmPage::class, 'render' ) ),
			array( 'asem-attendance', 'Presenze', array( RegistersPage::class, 'render_attendance' ) ),
			array( 'asem-payments', 'Pagamenti online', array( PaymentsPage::class, 'render' ) ),
			array( 'asem-card', 'Tessera, QR e Wallet', array( CardPage::class, 'render' ) ),
			array( 'asem-look', 'Aspetto', array( AppearancePage::class, 'render' ) ),
			array( 'asem-bank', 'Bonifico bancario', array( BankPage::class, 'render' ) ),
			array( 'asem-donate', 'Donazioni con PayPal', array( DonatePage::class, 'render' ) ),
			array( 'asem-messages', 'Comunicazioni', array( MessagesPage::class, 'render' ) ),
			array( 'asem-backup', 'Copia di sicurezza', array( BackupPage::class, 'render' ) ),
			array( 'asem-comms', 'Promemoria, privacy, regolamento e ricevute', array( CommsPage::class, 'render' ) ),
			array( 'asem-texts', 'Testi personalizzati', array( TextsPage::class, 'render' ) ),
			array( 'asem-audit', 'Registro azioni', array( AuditPage::class, 'render' ) ),
			array( 'asem-person', 'Scheda persona', array( PeoplePage::class, 'render_edit' ) ),
			array( 'asem-activity', 'Scheda attività', array( ActivitiesPage::class, 'render_detail' ) ),
			array( 'asem-import', 'Importa soci', array( ImportPage::class, 'render' ) ),
			array( 'asem-wpai', 'Import con WP All Import', array( WpAiPage::class, 'render' ) ),
		);
		foreach ( $hidden as $s ) {
			add_submenu_page( null, $s[1], $s[1], in_array( $s[0], self::ADMIN_ONLY, true ) ? Plugin::CAP : $cap, $s[0], self::guard( $s[0], $s[2] ) );
		}
	}

	/** Tiene evidenziata la voce giusta del menu quando si è in una scheda nascosta. */
	public static function menu_parent( $parent_file ) {
		global $plugin_page, $submenu_file;
		if ( is_string( $plugin_page ) && 0 === strpos( $plugin_page, 'asem' ) ) {
			$submenu_file = self::menu_item_of( $plugin_page ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			return 'asem';
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
		if ( 'asem-settings' === $main ) { // Impostazioni: prima la sezione, poi le schede della sezione
			$current = null;
			foreach ( self::SETTINGS_SECTIONS as $title => $pages ) {
				if ( isset( $pages[ $page ] ) ) {
					$current = $title;
				}
			}
			$html = '<nav class="nav-tab-wrapper asem-tabs">';
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
					$html .= '<ul class="subsubsub asem-subtabs">' . implode( ' | ', $links ) . '</ul><br class="clear">';
				}
			}
			return $html;
		}
		$html = '<nav class="nav-tab-wrapper asem-tabs">';
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
		return array_merge( \AssociazioneSemplice\Modules::off_pages(), \AssociazioneSemplice\Edition::missing_pages() );
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
	public static function guard( string $slug, $render ): callable { // $render può essere una classe che in questa edizione non c'è (la pagina mostra l'avviso)
		static $made = array(); // lo stesso oggetto per la stessa pagina: se è registrata due volte (menu principale e prima voce) WordPress la disegna una volta sola
		if ( isset( $made[ $slug ] ) ) {
			return $made[ $slug ];
		}
		return $made[ $slug ] = function () use ( $slug, $render ) {
			if ( in_array( $slug, \AssociazioneSemplice\Edition::missing_pages(), true ) ) {
				Ui::header( \AssociazioneSemplice\Edition::degraded() ? 'Funzione sospesa' : 'Funzione non inclusa' );
				echo \AssociazioneSemplice\Edition::missing_html(); // phpcs:ignore WordPress.Security.EscapeOutput
				Ui::footer();
				return;
			}
			if ( in_array( $slug, self::disabled_pages(), true ) ) {
				Ui::header( 'Parte non in uso' );
				echo '<p>Questa parte del gestionale è spenta. I dati non sono stati toccati: si riaccende dalla <a href="' . esc_url( Ui::url( 'asem-wizard' ) ) . '">configurazione guidata</a>.</p>';
				Ui::footer();
				return;
			}
			$render();
		};
	}

	/** Se le schede dei report sono spente scrive l'avviso e dice di fermarsi. */
	public static function reports_off( string $title ): bool {
		if ( \AssociazioneSemplice\Settings::get( 'reports_enabled' ) ) {
			return false;
		}
		Ui::header( $title );
		echo '<p>Report e rendiconto sono spenti. Si riaccendono da <a href="' . esc_url( Ui::url( 'asem-acct' ) ) . '">Impostazioni → Contabilità</a>.</p>';
		Ui::footer();
		return true;
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, 'asem' ) ) {
			return;
		}
		wp_enqueue_style( 'asem-admin', ASEM_URL . 'assets/admin.css', array(), Plugin::asset_version( 'admin.css' ) );
		wp_enqueue_script( 'asem-admin', ASEM_URL . 'assets/admin.js', array(), Plugin::asset_version( 'admin.js' ), true );
		$places = \AssociazioneSemplice\Places::script_url(); // suggerimenti di Google per i campi «Luogo», solo se l'ente li ha accesi
		if ( '' !== $places ) {
			wp_enqueue_script( 'asem-places', $places, array( 'asem-admin' ), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- script esterno di Google, la versione la gestisce Google
		}
	}
}
