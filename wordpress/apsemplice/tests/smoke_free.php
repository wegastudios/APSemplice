<?php
/**
 * Prova dell'edizione gratuita dentro un WordPress reale: il plugin SENZA i file elencati in tests/pro-files.txt deve funzionare.
 *   wp eval-file wp-content/plugins/apsemplice/tests/smoke_free.php
 * Si lancia dopo aver cancellato quei file dalla cartella del plugin (lo fa il workflow).
 */

use ApSemplice\Db;
use ApSemplice\Edition;
use ApSemplice\Fiscal;
use ApSemplice\Plugin;
use ApSemplice\Settings;

$GLOBALS['apse_warnings'] = array();
set_error_handler(
	function ( $no, $str, $file, $line ) {
		if ( ! ( error_reporting() & $no ) ) {
			return false;
		}
		if ( false !== strpos( str_replace( '\\', '/', $file ), '/apsemplice/' ) ) {
			$GLOBALS['apse_warnings'][] = "$str ($file:$line)";
		}
		return false;
	}
);

function free_ok( $cond, string $msg ): void {
	if ( ! $cond ) {
		WP_CLI::error( 'FAIL: ' . $msg );
	}
	WP_CLI::log( 'ok - ' . $msg );
}

function free_render( callable $page, array $get = array() ): string {
	$old  = $_GET;
	$_GET = $get;
	ob_start();
	$page();
	$html = ob_get_clean();
	$_GET = $old;
	return $html;
}

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
\ApSemplice\Admin\Admin::init();

// ---------- Cosa c'è e cosa no ----------
$lines = array_filter( array_map( 'trim', file( __DIR__ . '/pro-files.txt' ) ?: array() ), function ( $l ) {
	return '' !== $l && '#' !== $l[0];
} );
free_ok( count( $lines ) >= 1, 'elenco dei file avanzati letto' );
foreach ( $lines as $rel ) {
	free_ok( ! file_exists( APSE_DIR . 'includes/' . $rel ), "assente nell'edizione gratuita: $rel" );
}
free_ok( ! Edition::has( 'license' ) && ! Edition::has( 'vat' ) && ! Edition::has( 'levels' ), 'licenza, IVA e livelli non sono presenti' );
free_ok( Edition::allows( 'export' ) && Edition::allows( 'member_area' ), 'senza licenza le funzioni presenti sono consentite' );

// ---------- IVA: anche con la partita IVA accesa non compare nulla di fiscale ----------
Settings::update( array( 'has_vat' => 1, 'vat_number' => '12345678903', 'fiscal_regime' => 'ordinary' ) );
free_ok( ! Fiscal::vat_applies(), 'l\'IVA non si applica nemmeno se le impostazioni la dicono accesa' );
Settings::update( array( 'has_vat' => 0, 'vat_number' => '' ) );

// ---------- Pagine di amministrazione ----------
$pages = array(
	'Bacheca'               => array( array( \ApSemplice\Admin\DashboardPage::class, 'render' ), array() ),
	'Soci e quote'          => array( array( \ApSemplice\Admin\SettingsPage::class, 'render' ), array() ),
	'Dati dell\'ente'       => array( array( \ApSemplice\Admin\EntityPage::class, 'render' ), array() ),
	'Configurazione guidata' => array( array( \ApSemplice\Admin\WizardPage::class, 'render' ), array() ),
	'Rubrica'               => array( array( \ApSemplice\Admin\PeoplePage::class, 'render_list' ), array() ),
	'Corsi ed eventi'       => array( array( \ApSemplice\Admin\ActivitiesPage::class, 'render_list' ), array() ),
	'Prima nota'            => array( array( \ApSemplice\Admin\LedgerPage::class, 'render' ), array() ),
	'Nuovo incasso'         => array( array( \ApSemplice\Admin\IncomePage::class, 'render' ), array() ),
	'Nuova spesa'           => array( array( \ApSemplice\Admin\ExpensePage::class, 'render' ), array() ),
);
$html = array();
foreach ( $pages as $name => $p ) {
	$html[ $name ] = free_render( $p[0], $p[1] );
	free_ok( '' !== $html[ $name ], "pagina: $name" );
}
free_ok( false === strpos( $html['Soci e quote'], 'Livelli di socio' ) && false === strpos( $html['Soci e quote'], 'Chiave di licenza' ), 'impostazioni: né livelli né licenza' );
free_ok( false === strpos( $html['Dati dell\'ente'], 'Partita IVA' ), 'dati dell\'ente: niente partita IVA' );
free_ok( false === strpos( $html['Configurazione guidata'], 'name="has_vat"' ) && false === strpos( $html['Configurazione guidata'], 'name="extra_levels"' ), 'configurazione guidata: niente IVA né tipi di socio extra' );
free_ok( false === strpos( $html['Nuova spesa'], 'name="vat_rate"' ) && false === strpos( $html['Nuovo incasso'], 'name="vat_rate"' ), 'incasso e spesa: nessun campo IVA' );

// ---------- Un giro vero: socio, evento, prenotazione, incasso ----------
$people = Plugin::people();
$acts   = Plugin::activities();
$ledger = Plugin::ledger();
$sy     = Settings::social_year( current_time( 'Y-m-d' ) )->label();
$pid    = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Prova', 'last_name' => 'Gratuita', 'email' => 'prova.gratuita@example.com' ) );
$people->set_membership( $pid, Settings::membership_year()->label(), true );
$aid = $acts->create( array( 'name' => 'Cena di prova', 'social_year' => $sy, 'kind' => 'event', 'fee_cents' => 1500, 'session' => array( 'session_date' => current_time( 'Y-m-d' ), 'capacity' => 10 ) ) );
$sid = (int) $acts->sessions( $aid )[0]['id'];
$acts->book( $sid, $pid );
$cash = $ledger->accounts()[0];
$tx   = $ledger->record_receipt( array( 'date' => current_time( 'Y-m-d' ), 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $pid, 'lines' => array( array( 'category_id' => $ledger->category_id_of_kind( 'activity_fee' ), 'amount_cents' => 1500, 'activity_id' => $aid, 'session_id' => $sid ) ) ) );
free_ok( $tx > 0, 'incasso registrato in prima nota' );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT vat_rate, vat_cents FROM ' . Db::t( 'transactions' ) . ' WHERE id = %d', $tx ), ARRAY_A );
free_ok( null === $row['vat_rate'] && 0 === (int) $row['vat_cents'], 'nessuna IVA sull\'incasso' );

// ---------- Tutte le pagine di amministrazione si aprono (anche quelle delle funzioni assenti, con l'avviso) ----------
$src = (string) file_get_contents( APSE_DIR . 'includes/Admin/Admin.php' );
preg_match_all( "/array\( '(apse[a-z-]*)', '(?:[^'\\\\]|\\\\.)*', array\( ([A-Za-z]+)::class, '([a-z_]+)' \) \)/", $src, $m, PREG_SET_ORDER );
free_ok( count( $m ) > 30, 'elenco delle pagine di amministrazione letto (' . count( $m ) . ')' );
$skip = array( 'apse-activity-delete', 'apse-booking-delete', 'apse-reset', 'apse-activity' );
$n_pages = 0;
foreach ( $m as $pg ) {
	if ( in_array( $pg[1], $skip, true ) ) {
		continue;
	}
	$cb   = array( 'ApSemplice\\Admin\\' . $pg[2], $pg[3] );
	$wrap = \ApSemplice\Admin\Admin::guard( $pg[1], $cb );
	$out  = free_render( $wrap, array( 'page' => $pg[1] ) );
	free_ok( '' !== $out, 'pagina ' . $pg[1] . ' si apre' );
	$n_pages++;
}
free_ok( $n_pages > 30, "$n_pages pagine provate" );

// ---------- Pagamenti: solo l'elenco di ciò che c'è da pagare ----------
free_ok( ! Edition::has( 'payments' ) && get_class( Plugin::payments() ) === 'ApSemplice\OfflinePayments' && ! Plugin::payments()->enabled() && array() === Plugin::payments()->providers(), 'pagamenti online assenti' );
$aid2 = $acts->create( array( 'name' => 'Gita da pagare', 'social_year' => $sy, 'kind' => 'event', 'fee_cents' => 1000, 'session' => array( 'session_date' => current_time( 'Y-m-d' ), 'capacity' => 10 ) ) );
$acts->book( (int) $acts->sessions( $aid2 )[0]['id'], $pid );
$actor = $people->get( $pid );
$dues  = Plugin::payments()->dues_for( $actor );
free_ok( is_array( $dues ) && count( $dues ) >= 1, 'le voci da pagare si calcolano lo stesso' );
$area = \ApSemplice\Frontend\Views::section_pay( $actor );
free_ok( false !== strpos( $area, 'Pagamenti' ) && false === strpos( $area, 'apse_front_pay' ), 'area soci: voci da pagare senza pulsanti di pagamento online' );
$threw = null !== ( function () use ( $actor ) {
	try {
		\ApSemplice\Frontend\Actions::do_pay( array() );
	} catch ( \Throwable $e ) {
		return $e->getMessage();
	}
	return null;
} )();
free_ok( $threw, 'il pagamento online è rifiutato' );

// ---------- Il salvataggio dei livelli è rifiutato ----------
$threw = false;
try {
	$m = new ReflectionMethod( \ApSemplice\Admin\Actions::class, 'save_levels' );
	$m->setAccessible( true );
	$m->invoke( null, array( 'level' => array() ) );
} catch ( \Throwable $e ) {
	$threw = true;
}
free_ok( $threw, 'livelli: il salvataggio non è consentito' );

free_ok( ! $GLOBALS['apse_warnings'], 'nessun warning/notice PHP dal plugin' . ( $GLOBALS['apse_warnings'] ? ': ' . implode( ' | ', array_slice( $GLOBALS['apse_warnings'], 0, 5 ) ) : '' ) );

WP_CLI::success( 'Edizione gratuita: tutti i controlli sono passati.' );
