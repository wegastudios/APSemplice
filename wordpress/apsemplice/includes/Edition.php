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
		'reports'    => 'Admin/ReportsPage.php',   // report, rendiconto, anni solari e contabilità
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
		'reports'    => array( 'apse-accounting', 'apse-years', 'apse-reports', 'apse-statement', 'apse-acct' ),
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

	/** La funzione avanzata è presente in questa installazione? */
	public static function has( string $feature ): bool {
		return isset( self::FEATURES[ $feature ] ) && null !== self::locate( self::FEATURES[ $feature ] );
	}

	/**
	 * La funzione è consentita? Senza il sistema di licenza (plugin gratuito) tutto ciò che è presente è consentito; con la licenza
	 * decide {@see License::allows()}.
	 */
	public static function allows( string $feature ): bool {
		return ! self::has( 'license' ) || License::allows( $feature );
	}
}
