<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Quali funzioni esistono in questa installazione.
 *
 * Il plugin gratuito contiene il nucleo; le funzioni avanzate stanno nel plugin APSemplice Pro, che aggiunge la propria cartella con
 * {@see Edition::add_dir()}. Una funzione c'è se il suo file di riferimento si trova in una delle cartelle: niente interruttori, niente
 * chiavi, niente codice «spento». Il resto del plugin chiede `Edition::has( 'payments' )` prima di mostrare o usare una funzione avanzata.
 */
final class Edition {

	/** Funzione => file (dentro includes/) che la contiene. */
	const FEATURES = array(
		'license'    => 'License.php',             // verifica della licenza (solo Pro)
		'vat'        => 'Admin/VatFields.php',     // IVA: campi nei moduli e impostazioni fiscali
		'levels'     => 'Admin/LevelsEditor.php',  // più livelli di socio, ognuno con la sua quota
		'payments'   => 'PaymentService.php',      // pagamenti online
		'funds'      => 'Admin/AccountsPage.php',  // conti multipli, giroconti, fondi e cassa per più persone
		'reports'    => 'Admin/ReportsPage.php',   // report di gestione (saldi, attività)
		'fiscal'     => 'Admin/YearsPage.php',     // anni solari, contabilità e rendiconto per il commercialista
		'fivepm'     => 'FivePerMille.php',        // 5 per mille
		'insurance'  => 'Insurance.php',           // assicurazioni e presenze
		'broadcasts' => 'Broadcasts.php',          // comunicazioni di massa
		'pwa'        => 'Pwa.php',                 // app installabile e notifiche
		'wallet'     => 'Wallet.php',              // tessera in Apple e Google Wallet
		'receipts'   => 'Receipts.php',            // ricevute PDF
		'door_sales' => 'DoorSales.php',           // incasso sul posto
	);

	/** Pagine di amministrazione di ogni funzione avanzata: se la funzione non c'è, spariscono dal menu e dalle schede. */
	const PAGES = array(
		'payments'   => array( 'apse-payments' ),
		'funds'      => array( 'apse-accounts', 'apse-transfer', 'apse-group' ),
		'reports'    => array( 'apse-reports' ),
		'fiscal'     => array( 'apse-accounting', 'apse-years', 'apse-statement', 'apse-acct' ),
		'fivepm'     => array( 'apse-fivepm' ),
		'insurance'  => array( 'apse-volunteers', 'apse-attendance' ),
		'broadcasts' => array( 'apse-messages' ),
		'pwa'        => array( 'apse-app' ),
	);

	/** @return string[] pagine di amministrazione delle funzioni che questa installazione non ha */
	public static function missing_pages(): array {
		$out = array();
		foreach ( self::PAGES as $feature => $pages ) {
			if ( ! self::has( $feature ) ) {
				$out = array_merge( $out, $pages );
			}
		}
		return $out;
	}

	/** @var string[] cartelle (con la barra finale) in cui cercare i file: la prima è quella di questo plugin */
	private static $dirs = array();

	/** @return string[] */
	public static function dirs(): array {
		if ( ! self::$dirs ) {
			self::$dirs = array( APSE_DIR . 'includes/' );
		}
		return self::$dirs;
	}

	/** Il plugin Pro dichiara qui la propria cartella `includes/`. */
	public static function add_dir( string $dir ): void {
		$dir = rtrim( $dir, '/\\' ) . '/';
		if ( ! in_array( $dir, self::dirs(), true ) ) {
			self::$dirs[] = $dir;
		}
	}

	/** Percorso del file di una classe (null se non esiste in nessuna cartella). */
	public static function locate( string $relative ): ?string {
		foreach ( self::dirs() as $d ) {
			if ( is_readable( $d . $relative ) ) {
				return $d . $relative;
			}
		}
		return null;
	}

	/** Il file della funzione c'è, a prescindere dalla licenza? */
	public static function installed( string $feature ): bool {
		return isset( self::FEATURES[ $feature ] ) && null !== self::locate( self::FEATURES[ $feature ] );
	}

	/** Con APSemplice Pro presente ma la licenza non in regola il plugin torna alle funzioni di base. */
	public static function degraded(): bool {
		return self::installed( 'license' ) && function_exists( 'get_option' ) && License::degraded();
	}

	/**
	 * La funzione avanzata si può usare? C'è nella cartella e, se c'è il sistema di licenza, la licenza è in regola. Con la licenza scaduta
	 * tutte le funzioni avanzate spariscono e resta il comportamento dell'edizione gratuita; la licenza stessa si può sempre correggere.
	 */
	public static function has( string $feature ): bool {
		return self::installed( $feature ) && ( 'license' === $feature || ( ! self::degraded() && ! self::plan_blocks( $feature ) ) );
	}

	/** Funzioni fiscali: solo con la licenza «Pro Fiscale» (IVA, 5 per mille, anni solari e rendiconto; più avanti la fatturazione elettronica). */
	const FISCAL = array( 'vat', 'fivepm', 'fiscal' );

	/** La licenza è di livello contabile e la funzione è fiscale? Senza licenza (verifica non attiva) tutto è disponibile. */
	public static function plan_blocks( string $feature ): bool {
		return in_array( $feature, self::FISCAL, true ) && self::installed( 'license' ) && function_exists( 'get_option' ) && License::PLAN_FISCAL !== License::plan();
	}

	/**
	 * Le funzioni di base non dipendono più dalla licenza: tutto ciò che c'è si può usare. Resta per i controlli già scritti.
	 */
	public static function allows( string $feature ): bool {
		return true;
	}

	/** Perché una funzione avanzata non è disponibile (da mostrare a chi prova a usarla). */
	public static function missing_message( string $feature = '' ): string {
		if ( '' !== $feature && self::installed( $feature ) && ! self::degraded() && self::plan_blocks( $feature ) ) {
			return 'Questa funzione fa parte della licenza APSemplice Pro Fiscale: la tua licenza è di livello contabile.';
		}
		if ( self::degraded() ) {
			return 'La licenza di APSemplice Pro non è in regola: questa funzione è sospesa. Regolarizza la licenza e torna disponibile; intanto resta tutto il resto, con i tuoi dati.';
		}
		return 'Questa funzione fa parte di APSemplice Pro e non è inclusa in questa edizione.';
	}

	/** Indirizzo del sito dove si presenta e si acquista APSemplice Pro (modificabile con il filtro `apse_pro_url`). */
	public static function pro_url(): string {
		return (string) apply_filters( 'apse_pro_url', 'https://www.wegastudios.com' );
	}

	/** Come {@see Edition::missing_message()}, con il collegamento per regolarizzare quando la licenza è scaduta (HTML già pronto). */
	public static function missing_html(): string {
		$html = '<p>' . esc_html( self::missing_message() ) . '</p>';
		if ( ! self::degraded() && function_exists( 'admin_url' ) ) {
			$html .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=apse-pro' ) ) . '">Scopri cosa comprende APSemplice Pro →</a></p>';
		}
		if ( self::degraded() ) {
			$url   = License::payment_url();
			$html .= $url
				? '<p><a class="button button-primary" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Regolarizza la licenza</a></p>'
				: '<p>Contatta il fornitore del servizio per regolarizzare la licenza, poi inseriscila in Impostazioni → Soci e quote.</p>';
		}
		return $html;
	}

	/** Azione di una funzione avanzata che non c'è (o è sospesa): chi ci arriva legge perché, invece di una pagina vuota. */
	public static function block_action( string $action ): void {
		add_action(
			'admin_post_' . $action,
			function () {
				wp_die( self::missing_html(), 'Funzione non disponibile', array( 'response' => 403, 'back_link' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		);
	}
}
