<?php
/**
 * Test di fumo dentro un WordPress reale:
 *   wp eval-file wp-content/plugins/apsemplice/tests/smoke.php
 * Crea dati di prova, verifica le regole principali e fa il render di tutte le pagine di amministrazione.
 * Esce con errore al primo controllo fallito.
 */

use ApSemplice\Access;
use ApSemplice\Admin;
use ApSemplice\Attachments;
use ApSemplice\Audit;
use ApSemplice\Gatekeeper;
use ApSemplice\Gateways;
use ApSemplice\ImportService;
use ApSemplice\Notices;
use ApSemplice\Wallet;
use ApSemplice\WpAllImport;
use ApSemplice\PaymentConfig;
use ApSemplice\Secrets;
use ApSemplice\License;
use ApSemplice\Db;
use ApSemplice\Labels;
use ApSemplice\MemberType;
use ApSemplice\PeopleCsv;
use ApSemplice\Plugin;
use ApSemplice\Settings;

$GLOBALS['apse_warnings'] = array();
set_error_handler(
	function ( $no, $str, $file, $line ) {
		if ( ! ( error_reporting() & $no ) ) {
			return false; // avvisi soppressi con @ (es. verifiche di certificati)
		}
		if ( false !== strpos( str_replace( '\\', '/', $file ), '/apsemplice/' ) ) {
			$GLOBALS['apse_warnings'][] = "$str ($file:$line)";
		}
		return false;
	}
);

function apse_ok( $cond, string $msg ): void {
	if ( ! $cond ) {
		WP_CLI::error( 'FAIL: ' . $msg );
	}
	WP_CLI::log( 'ok - ' . $msg );
}

function apse_throws( callable $f ): ?string {
	try {
		$f();
	} catch ( \InvalidArgumentException $e ) {
		return $e->getMessage();
	}
	return null;
}

function apse_render( callable $page, string $must_contain, array $get = array() ): string {
	$old = $_GET;
	$_GET = array_merge( array(), $get );
	ob_start();
	$page();
	$html = ob_get_clean();
	$_GET = $old;
	apse_ok( false !== strpos( $html, $must_contain ), 'pagina: ' . $must_contain );
	return $html;
}

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// ---------- Installazione ----------
foreach ( array( 'people', 'memberships', 'accounts', 'categories', 'activities', 'enrollments', 'transactions', 'cash_counts' ) as $t ) {
	apse_ok( Db::t( $t ) === $wpdb->get_var( "SHOW TABLES LIKE '" . Db::t( $t ) . "'" ), "tabella $t" );
}
apse_ok( null !== get_role( Plugin::ROLE_MEMBER ), 'ruolo Socio APS' );
apse_ok( get_role( 'administrator' )->has_cap( Plugin::CAP ), 'gli amministratori hanno la capability' );
apse_ok( ! get_role( 'subscriber' )->has_cap( Plugin::CAP ), 'gli altri ruoli no' );
apse_ok( count( Plugin::ledger()->accounts() ) >= 2, 'conti iniziali' );
apse_ok( count( Plugin::ledger()->categories() ) >= 8, 'categorie iniziali' );

wp_set_current_user( 1 );
$people   = Plugin::people();
$acts     = Plugin::activities();
$ledger   = Plugin::ledger();
$reports  = Plugin::reports();
$today    = Db::today();
Settings::update( array( 'ticket_qr_enabled' => 1, 'wallet_enabled' => 1 ) ); // spenti di default: i test li accendono
$year     = substr( $today, 0, 4 );
$sy       = Settings::social_year();
$month    = $sy->clamp( substr( $today, 0, 7 ) );
$cash     = $ledger->default_account_for( 'cash' );
$bank     = $ledger->default_account_for( 'bank' );
Settings::update( array( 'guest_max_events' => 0 ) ); // i controlli più vecchi usano gli ospiti senza limite: il limite si prova più avanti
$start_balance = array_sum( array_column( $ledger->balances(), 'balance' ) );

// ---------- Soci e utenti WordPress ----------
$founder = $people->create( array( 'type' => 'founder', 'card_number' => '1', 'first_name' => 'Fulvia', 'last_name' => 'Fondi', 'email' => 'Fulvia@Example.com' ) );
$fp      = $people->get( $founder );
apse_ok( ! empty( $fp['wp_user_id'] ), 'il socio fondatore ha un utente WordPress' );
$wpu = get_userdata( (int) $fp['wp_user_id'] );
apse_ok( 'fulvia@example.com' === $wpu->user_email, 'email dell\'utente = email del socio (minuscola)' );
apse_ok( array( Plugin::ROLE_MEMBER ) === array_values( $wpu->roles ), 'ruolo Socio APS, nessun accesso admin' );
apse_ok( ! user_can( $wpu, Plugin::CAP ), 'il socio non può gestire il plugin' );
apse_ok( $people->is_active_member( $founder ), 'fondatore: tessera sempre valida' );
apse_ok( $people->active_until( $founder ) > gmdate( 'Y-m-d', strtotime( '+90 years' ) ), 'fondatore: scadenza a 99 anni' );

apse_ok( null !== apse_throws( function () use ( $people ) { $people->create( array( 'type' => 'ordinary', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'non-valida' ) ); } ), 'email non valida: rifiutata' );
$msg = (string) apse_throws( function () use ( $people ) { $people->create( array( 'type' => 'ordinary', 'card_number' => '1', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.com' ) ); } );
apse_ok( '' !== $msg && false !== strpos( $msg, 'Fondi' ), 'tessera duplicata rifiutata e il messaggio dice a chi appartiene' );
apse_ok( null !== apse_throws( function () use ( $people ) { $people->create( array( 'type' => 'ordinary', 'card_number' => '9', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'FULVIA@example.com' ) ); } ), 'email duplicata rifiutata' );
apse_ok( '2' === $people->next_free_card(), 'prossima tessera libera' );

$vol = $people->create( array( 'type' => 'volunteer', 'card_number' => '2', 'first_name' => 'Vera', 'last_name' => 'Volta', 'email' => 'vera@example.com' ) );
$ord = $people->create( array( 'type' => 'ordinary', 'card_number' => '3', 'first_name' => 'Omar', 'last_name' => 'Ordini', 'email' => 'omar@example.com' ) );

$existing_user = wp_create_user( 'esistente', 'x-Pass-123456', 'esistente@example.com' );
$linked        = $people->create( array( 'type' => 'ordinary', 'card_number' => '4', 'first_name' => 'Elia', 'last_name' => 'Esistente', 'email' => 'esistente@example.com' ) );
apse_ok( (int) $existing_user === (int) $people->get( $linked )['wp_user_id'], 'utente WordPress esistente collegato' );
apse_ok( array( 'subscriber' ) === array_values( get_userdata( $existing_user )->roles ), 'il ruolo dell\'utente esistente non viene toccato' );
apse_ok( null !== apse_throws( function () use ( $people ) { $people->create( array( 'type' => 'ordinary', 'first_name' => 'Doppio', 'last_name' => 'Utente', 'email' => 'vera@example.com' ) ); } ), 'stesso utente non collegabile a due soci' );

// ---------- Ospiti ----------
apse_ok( null !== apse_throws( function () use ( $people ) { $people->create( array( 'type' => 'guest', 'first_name' => 'Gino', 'last_name' => 'Ospiti' ) ); } ), 'l\'ospite richiede il socio ospitante' );
$guest = $people->create( array( 'type' => 'guest', 'first_name' => 'Gino', 'last_name' => 'Ospiti', 'phone' => '347 1111111', 'host_person_id' => $ord ) );
apse_ok( empty( $people->get( $guest )['wp_user_id'] ), 'l\'ospite non ha utente WordPress' );
apse_ok( null !== apse_throws( function () use ( $people, $guest ) { $people->create( array( 'type' => 'guest', 'first_name' => 'X', 'last_name' => 'Y', 'host_person_id' => $guest ) ); } ), 'un ospite non può ospitare' );
apse_ok( 1 === count( $people->guests_of( $ord ) ), 'ospiti del socio' );
apse_ok( null !== apse_throws( function () use ( $people, $ord ) { $people->delete( $ord ); } ), 'non si elimina un socio con ospiti' );
apse_ok( ! $people->is_active_member( $guest ), 'l\'ospite non è socio' );

// ---------- Aggiornamento e sincronizzazione utente ----------
$people->update( $vol, array( 'email' => 'vera.volta@example.com', 'first_name' => 'Veronica' ) );
$vu = get_userdata( (int) $people->get( $vol )['wp_user_id'] );
apse_ok( 'vera.volta@example.com' === $vu->user_email && 'Veronica' === $vu->first_name, 'modifica sincronizzata sull\'utente WordPress' );
$people->update( $linked, array( 'email' => 'elia.nuova@example.com' ) );
apse_ok( 'esistente@example.com' === get_userdata( $existing_user )->user_email, 'email di un utente non "solo socio" non viene cambiata' );
apse_ok( null !== apse_throws( function () use ( $people, $ord ) { $people->update( $ord, array( 'type' => 'guest', 'host_person_id' => 1 ) ); } ), 'un socio non diventa ospite' );

// ---------- Attività ----------
apse_ok( null !== apse_throws( function () use ( $acts, $sy, $ord ) { $acts->create( array( 'name' => 'Yoga', 'social_year' => $sy->label(), 'instructor_person_id' => $ord ) ); } ), 'istruttore ordinario rifiutato' );
apse_ok( null !== apse_throws( function () use ( $acts, $sy, $founder ) { $acts->create( array( 'name' => 'Yoga', 'social_year' => $sy->label(), 'instructor_person_id' => $founder ) ); } ), 'istruttore fondatore rifiutato' );
$yoga = $acts->create( array( 'name' => 'Yoga', 'social_year' => $sy->label(), 'instructor_person_id' => $vol, 'monthly_fee_cents' => 2000 ) );
apse_ok( $yoga > 0, 'attività tenuta da socio e volontario' );

$acts->enroll( $yoga, $ord, $month );
$acts->enroll( $yoga, $guest, $month );
apse_ok( 2 === $acts->active_participants( $yoga ), 'iscritti attivi (socio e ospite)' );
$st = $acts->status_for_activity( $yoga );
apse_ok( 2 === count( $st ) && -2000 === $st[0]['summary']['balance'], 'prima del pagamento: da versare 20,00' );
apse_ok( $month === $acts->first_unpaid_month( $yoga, $ord ), 'primo mese non pagato' );

// ---------- Incassi ----------
$cat = array();
foreach ( $ledger->categories() as $c ) {
	$cat[ $c['kind'] ] = (int) $c['id'];
}
$sy_label = $sy->label();
$n = $ledger->record_receipt(
	array(
		'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $ord,
		'lines' => array(
			array( 'category_id' => $cat['membership'], 'amount_cents' => 1000, 'social_year' => $sy_label ),
			array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 2000, 'activity_id' => $yoga, 'competence_month' => $month ),
		),
	)
);
apse_ok( 2 === $n, 'incasso con due voci' );
apse_ok( $people->is_active_member( $ord ), 'la quota associativa iscrive il socio' );
$ord_status = $acts->status_for_person( $ord );
apse_ok( 1 === count( $ord_status ) && 0 === $ord_status[0]['summary']['balance'] && $ord_status[0]['summary']['regular'], 'mensilità pagata: in regola' );
apse_ok( null === $acts->first_unpaid_month( $yoga, $ord ), 'nessun mese da pagare' );

apse_ok( null !== apse_throws( function () use ( $ledger, $today, $cash, $cat, $founder ) { $ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $founder, 'lines' => array( array( 'category_id' => $cat['membership'], 'amount_cents' => 1000 ) ) ) ); } ), 'il fondatore non paga la quota' );
apse_ok( null !== apse_throws( function () use ( $ledger, $today, $cash, $cat, $guest ) { $ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $guest, 'lines' => array( array( 'category_id' => $cat['membership'], 'amount_cents' => 1000 ) ) ) ); } ), 'l\'ospite non paga la quota associativa' );
apse_ok( null !== apse_throws( function () use ( $ledger, $today, $cash, $cat ) { $ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'lines' => array( array( 'category_id' => $cat['membership'], 'amount_cents' => 1000 ) ) ) ); } ), 'quota associativa senza socio rifiutata' );
apse_ok( null !== apse_throws( function () use ( $ledger, $today, $cash, $cat ) { $ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'lines' => array( array( 'category_id' => $cat['donation'], 'amount_cents' => 0 ) ) ) ); } ), 'importo zero rifiutato' );

// L'ospite paga solo la mensilità (e il pagamento è in contanti)
$ledger->record_receipt(
	array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $guest,
		'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 2000, 'activity_id' => $yoga, 'competence_month' => $month ) ) )
);
apse_ok( $acts->status_for_person( $guest )[0]['summary']['regular'], 'l\'ospite paga solo l\'attività' );

$cash_balance = $ledger->expected_balance( (int) $cash['id'], $today );
apse_ok( $start_balance + 5000 === array_sum( array_column( $ledger->balances(), 'balance' ) ), 'saldo totale = iniziale + 50,00 incassati' );

// ---------- Spesa, giroconto, annullamento, verifica cassa ----------
$ledger->record_expense( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'category_id' => $cat['member_reimbursement'], 'amount_cents' => 1500, 'activity_id' => $yoga, 'person_id' => $vol, 'description' => 'Rimborso istruttrice' ) );
$ledger->record_transfer( $today, (int) $cash['id'], (int) $bank['id'], 1000, 'bank_transfer', 'Versamento' );
$by_id = array();
foreach ( $ledger->balances() as $b ) {
	$by_id[ (int) $b['id'] ] = $b['balance'];
}
apse_ok( $cash_balance - 1500 - 1000 === $by_id[ (int) $cash['id'] ], 'cassa dopo spesa e giroconto' );
apse_ok( $start_balance + 5000 - 1500 === array_sum( $by_id ), 'il giroconto non cambia il totale' );

$tx_ids = $wpdb->get_col( 'SELECT id FROM ' . Db::t( 'transactions' ) . " WHERE type = 'transfer_out'" );
$ledger->void( (int) $tx_ids[0], 'prova' );
apse_ok( $cash_balance - 1500 === $ledger->expected_balance( (int) $cash['id'], $today ) && $start_balance + 5000 - 1500 === array_sum( array_column( $ledger->balances(), 'balance' ) ), 'annullare un giroconto annulla entrambe le righe' );

$mem_tx = (int) $wpdb->get_var( 'SELECT transaction_id FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = ' . (int) $ord . ' AND deleted_at IS NULL' );
$ledger->void( $mem_tx, 'errore' );
apse_ok( ! $people->is_active_member( $ord ), 'annullando la quota si toglie l\'iscrizione' );
apse_ok( null !== apse_throws( function () use ( $ledger, $mem_tx ) { $ledger->void( $mem_tx, 'di nuovo' ); } ), 'non si annulla due volte' );

$expected = $ledger->expected_balance( (int) $cash['id'], $today );
$diff     = $ledger->record_cash_count( (int) $cash['id'], $today, $expected + 500, true );
apse_ok( 500 === $diff && $expected + 500 === $ledger->expected_balance( (int) $cash['id'], $today ), 'verifica cassa con rettifica riallinea il saldo' );

// ---------- Permessi derivati dai dati (Access) ----------
$u_admin = 1;
$u_vol   = (int) $people->get( $vol )['wp_user_id'];
$u_ord   = (int) $people->get( $ord )['wp_user_id'];
$other   = $acts->create( array( 'name' => 'Teatro', 'social_year' => $sy->label(), 'monthly_fee_cents' => 1000 ) );
apse_ok( user_can( $u_admin, 'apse_notify_activity', $yoga ), 'admin: può avvisare qualsiasi attività' );
apse_ok( user_can( $u_vol, 'apse_notify_activity', $yoga ) && user_can( $u_vol, 'apse_view_participants', $yoga ), 'il volontario istruttore gestisce i suoi iscritti' );
apse_ok( ! user_can( $u_vol, 'apse_notify_activity', $other ), 'il volontario non gestisce attività altrui' );
apse_ok( ! user_can( $u_ord, 'apse_notify_activity', $yoga ) && ! user_can( $u_ord, 'apse_view_participants', $yoga ), 'il socio ordinario non gestisce iscritti' );
apse_ok( user_can( $u_ord, 'apse_view_person', $ord ) && ! user_can( $u_ord, 'apse_view_person', $vol ), 'il socio vede solo sé stesso' );
apse_ok( user_can( $u_ord, 'apse_view_activity', $yoga ) && ! user_can( $u_ord, 'apse_view_activity', $other ), 'il socio vede le attività a cui è iscritto' );
apse_ok( user_can( $u_ord, 'apse_add_guest', $ord ) && ! user_can( $u_vol, 'apse_add_guest', $ord ), 'ogni socio aggiunge ospiti solo per sé' );
apse_ok( ! user_can( 0, 'apse_view_person', $ord ), 'utente anonimo: nessun permesso' );
apse_ok( ! user_can( $u_ord, Plugin::CAP ), 'un socio non ha la capability di amministrazione' );

// ---------- REST API ----------
function apse_rest( int $user, string $route ): WP_REST_Response {
	wp_set_current_user( $user );
	return rest_do_request( new WP_REST_Request( 'GET', $route ) );
}
$r = apse_rest( $u_ord, '/apsemplice/v1/me' );
apse_ok( 200 === $r->get_status() && $ord === $r->get_data()['person']['id'] && false === $r->get_data()['is_admin'], 'REST /me: socio' );
apse_ok( true === apse_rest( $u_admin, '/apsemplice/v1/me' )->get_data()['is_admin'], 'REST /me: amministratore' );
apse_ok( in_array( apse_rest( 0, '/apsemplice/v1/me' )->get_status(), array( 401, 403 ), true ), 'REST /me: senza login rifiutato' );
$r = apse_rest( $u_ord, '/apsemplice/v1/me/activities' );
apse_ok( 200 === $r->get_status() && 1 === count( $r->get_data()['activities'] ) && 'Yoga' === $r->get_data()['activities'][0]['activity'], 'REST /me/activities' );
apse_ok( 404 === apse_rest( 1, '/apsemplice/v1/me/activities' )->get_status(), 'REST /me/activities: utente senza socio -> 404' );

$r = apse_rest( $u_vol, "/apsemplice/v1/activities/$yoga/participants" );
apse_ok( 200 === $r->get_status() && 2 === count( $r->get_data()['participants'] ), 'REST participants: il volontario istruttore vede gli iscritti' );
apse_ok( ! isset( $r->get_data()['participants'][0]['email'] ) && ! isset( $r->get_data()['participants'][0]['balance'] ), 'REST participants: il volontario NON vede contatti né pagamenti' );
apse_ok( isset( apse_rest( $u_admin, "/apsemplice/v1/activities/$yoga/participants" )->get_data()['participants'][0]['email'] ), 'REST participants: l\'amministratore vede i contatti' );
apse_ok( 403 === apse_rest( $u_ord, "/apsemplice/v1/activities/$yoga/participants" )->get_status(), 'REST participants: il socio è rifiutato' );
apse_ok( 403 === apse_rest( $u_vol, "/apsemplice/v1/activities/$other/participants" )->get_status(), 'REST participants: attività di un altro rifiutata' );
apse_ok( 404 === apse_rest( $u_admin, '/apsemplice/v1/activities/999999/participants' )->get_status(), 'REST participants: attività inesistente' );
apse_ok( 200 === apse_rest( $u_ord, "/apsemplice/v1/people/$ord" )->get_status() && 403 === apse_rest( $u_ord, "/apsemplice/v1/people/$vol" )->get_status(), 'REST people: solo la propria scheda' );
wp_set_current_user( 1 );

// ---------- Area riservata: i soci restano fuori da wp-admin ----------
wp_set_current_user( $u_ord );
apse_ok( false === apply_filters( 'show_admin_bar', true ), 'barra di amministrazione nascosta ai soci' );
wp_set_current_user( 1 );
apse_ok( true === apply_filters( 'show_admin_bar', true ), 'barra di amministrazione visibile agli amministratori' );
apse_ok( Gatekeeper::area_url() === apply_filters( 'login_redirect', '/wp-admin/', '', get_userdata( $u_ord ) ), 'dopo il login il socio va all\'area riservata' );
apse_ok( '/wp-admin/' === apply_filters( 'login_redirect', '/wp-admin/', '', get_userdata( 1 ) ), 'l\'amministratore non viene dirottato' );
apse_ok( '/wp-admin/' === apply_filters( 'login_redirect', '/wp-admin/', '', get_userdata( $existing_user ) ), 'chi ha altri ruoli non viene dirottato' );

// ---------- Registro azioni e licenza ----------
$actions = array_column( Audit::recent( 1000 ), 'action' );
foreach ( array( 'person.created', 'person.updated', 'membership.set', 'membership.removed', 'activity.created', 'activity.enrolled', 'tx.created', 'tx.voided', 'cashcount.recorded' ) as $a ) {
	apse_ok( in_array( $a, $actions, true ), "registro azioni: $a" );
}
apse_ok( 1 === (int) Audit::recent( 1 )[0]['user_id'], 'il registro ricorda chi ha agito' );
Settings::update( array( 'license_key' => '  ABC-123  ', 'member_area_page_id' => 0 ) );
apse_ok( 'ABC-123' === License::key() && License::allows( 'online_payments' ), 'licenza: chiave salvata, funzioni consentite (standby)' );
apse_ok( '' !== License::status()['domain'], 'licenza: dominio del sito' );
$inst1 = License::installation();
apse_ok( $inst1['id'] === License::installation()['id'] && ! $inst1['moved'], 'licenza: l\'id dell\'installazione è stabile' );
update_option( License::OPT_INSTALL_URL, 'https://produzione-originale.example.it' ); // simula un database copiato da un altro indirizzo
$inst2 = License::installation();
apse_ok( $inst2['moved'] && $inst2['id'] !== $inst1['id'], 'licenza: una copia su un altro indirizzo diventa una nuova installazione' );
apse_ok( $inst2['id'] === License::installation()['id'], 'licenza: il nuovo id poi resta stabile' );

// ---------- Licenza non in regola: si torna alle funzioni di base ----------
apse_ok( ! \ApSemplice\Edition::degraded() && \ApSemplice\Edition::has( 'payments' ) && \ApSemplice\Edition::has( 'levels' ) && '' === Admin\LicenseNotice::html(), 'licenza in standby: tutto disponibile e nessun avviso' );
License::set_state( 'unpaid', $today );
apse_ok( \ApSemplice\Edition::degraded() && ! \ApSemplice\Edition::has( 'payments' ) && ! \ApSemplice\Edition::has( 'levels' ) && ! \ApSemplice\Edition::has( 'reports' ) && ! \ApSemplice\Edition::has( 'vat' ) && ! \ApSemplice\Edition::has( 'broadcasts' ) && ! \ApSemplice\Edition::has( 'pwa' ), 'licenza scaduta: le funzioni avanzate spariscono' );
apse_ok( \ApSemplice\Edition::has( 'license' ) && \ApSemplice\Edition::installed( 'payments' ), 'licenza scaduta: la licenza si può ancora correggere e i file restano' );
apse_ok( \ApSemplice\Edition::allows( 'export' ) && \ApSemplice\Edition::allows( 'member_area' ) && user_can( $u_vol, 'apse_view_participants', $yoga ) && 200 === apse_rest( $u_ord, '/apsemplice/v1/me' )->get_status(), 'licenza scaduta: soci, volontari ed esportazioni non si bloccano' );
apse_ok( false !== strpos( Admin\Exports::link( 'people', array(), 'Esporta' ), 'href=' ), 'licenza scaduta: i pulsanti di esportazione restano attivi' );
apse_ok( get_class( Plugin::payments() ) === 'ApSemplice\OfflinePayments' && ! Plugin::payments()->enabled(), 'licenza scaduta: niente nuovi pagamenti online (restano le voci da pagare)' );
apse_ok( false !== strpos( (string) apse_throws( function () { \ApSemplice\Broadcasts::create( 'Oggetto', 'Testo', 'members_all' ); } ), 'licenza' ), 'licenza scaduta: le comunicazioni di massa dicono perché sono sospese' );
$lic_notice = Admin\LicenseNotice::html();
apse_ok( false !== strpos( $lic_notice, 'notice-warning' ) && false === strpos( $lic_notice, 'overlay' ), 'licenza scaduta: un avviso in cima alle pagine, non un popup che copre i dati' );
// livelli di licenza: contabile (senza le funzioni fiscali) e fiscale
$pl_fiscal = array( 'vat', 'fivepm', 'fiscal' );
\ApSemplice\License::set_state( 'active', null, null, 'fiscal' );
apse_ok( 'fiscal' === License::plan() && \ApSemplice\Edition::has( 'vat' ) && \ApSemplice\Edition::has( 'fivepm' ) && \ApSemplice\Edition::has( 'receipts' ) && \ApSemplice\Edition::has( 'fiscal' ) && \ApSemplice\Edition::has( 'payments' ), 'licenza fiscale: tutte le funzioni, anche IVA, 5x1000, ricevute e anni solari' );
\ApSemplice\License::set_state( 'active', null, null, 'accounting' );
apse_ok( 'accounting' === License::plan(), 'licenza contabile: il livello viene letto' );
$pl_off = true;
foreach ( $pl_fiscal as $pf ) {
	$pl_off = $pl_off && ! \ApSemplice\Edition::has( $pf );
}
apse_ok( $pl_off && \ApSemplice\Edition::has( 'payments' ) && \ApSemplice\Edition::has( 'funds' ) && \ApSemplice\Edition::has( 'reports' ) && \ApSemplice\Edition::has( 'levels' ) && \ApSemplice\Edition::has( 'broadcasts' ), 'licenza contabile: niente IVA, 5x1000 e anni solari; ricevute e il resto del Pro restano' );
apse_ok( ! \ApSemplice\Fiscal::vat_applies() && false !== strpos( \ApSemplice\Edition::missing_message( 'vat' ), 'Pro Fiscale' ), 'licenza contabile: l\'IVA non si applica e il messaggio spiega il livello' );
apse_ok( in_array( 'apse-fivepm', \ApSemplice\Edition::missing_pages(), true ) && in_array( 'apse-years', \ApSemplice\Edition::missing_pages(), true ) && ! in_array( 'apse-reports', \ApSemplice\Edition::missing_pages(), true ), 'licenza contabile: le pagine fiscali spariscono, i report restano' );
apse_ok( Admin\ProPage::needed(), 'licenza contabile: la pagina che presenta il livello fiscale è disponibile' );
ob_start();
Admin\ProPage::render();
$pl_page = (string) ob_get_clean();
apse_ok( false !== strpos( $pl_page, 'Pro Fiscale' ) && false !== strpos( $pl_page, 'IVA' ) && false !== strpos( $pl_page, 'attiva' ), 'pagina Pro: elenca i due livelli e le funzioni già attive' );
delete_option( License::OPT_STATE );
apse_ok( \ApSemplice\Edition::has( 'vat' ) && \ApSemplice\Edition::has( 'fiscal' ) && ! Admin\ProPage::needed(), 'licenza in standby: tutto disponibile come Pro completo, nessuna promozione' );
// quote diverse: spariscono, resta la quota sola
$lv_fee = Plugin::people()->create( array( 'type' => 'ordinary', 'first_name' => 'Livello', 'last_name' => 'Speciale' ) );
\ApSemplice\Levels::save( array_merge( array_map( function ( $l ) { return array( 'id' => (int) $l['id'], 'name' => $l['name'], 'base_type' => $l['base_type'], 'fee' => null === $l['fee_cents'] ? '' : ApSempliceMoney::plain( (int) $l['fee_cents'] ), 'active' => (int) $l['active'] ); }, \ApSemplice\Levels::all() ), array( array( 'id' => 0, 'name' => 'Livello con quota propria', 'base_type' => 'ordinary', 'fee' => '77,00', 'active' => 1 ) ) ) );
$lv_id = 0;
foreach ( \ApSemplice\Levels::all() as $l ) {
	if ( 'Livello con quota propria' === $l['name'] ) {
		$lv_id = (int) $l['id'];
	}
}
$wpdb->update( Db::t( 'people' ), array( 'level_id' => $lv_id ), array( 'id' => $lv_fee ) );
delete_option( License::OPT_STATE );
apse_ok( 7700 === \ApSemplice\Levels::base_fee( Plugin::people()->get( $lv_fee ) ), 'quote diverse: con la licenza in regola vale la quota del livello' );
License::set_state( 'unpaid', $today );
$lv_quota = (int) Settings::get( 'membership_fee_cents' );
apse_ok( $lv_quota === \ApSemplice\Levels::base_fee( Plugin::people()->get( $lv_fee ) ) && 1 === count( \ApSemplice\Levels::choices( 'ordinary' ) ), 'quote diverse: con la licenza scaduta c\'è una quota sola e non si sceglie il tipo di socio' );
License::set_state( 'active' );
apse_ok( \ApSemplice\Edition::has( 'payments' ) && \ApSemplice\Edition::has( 'levels' ) && '' === Admin\LicenseNotice::html() && count( \ApSemplice\Levels::choices( 'ordinary' ) ) >= 2, 'licenza regolarizzata: tutto torna disponibile' );
$wpdb->update( Db::t( 'people' ), array( 'level_id' => null ), array( 'id' => $lv_fee ) );
delete_option( License::OPT_STATE );
wp_set_current_user( 1 );

// ---------- Cancellazione da attività ----------
$acts->cancel( $yoga, $guest, $month );
apse_ok( 1 === $acts->active_participants( $yoga ), 'cancellato dall\'attività' );
apse_ok( 1 === count( $acts->status_for_person( $guest ) ), 'la cancellazione conserva la storia' );

// ---------- Report ----------
$p = $reports->period( $year . '-01-01', $year . '-12-31' );
apse_ok( $p['closing_total'] === array_sum( array_column( $ledger->balances(), 'balance' ) ), 'report: saldo finale = saldi dei conti' );
apse_ok( $p['total_income'] - $p['total_expense'] === $p['result'], 'report: avanzo' );
$s = $reports->social_year( $sy );
apse_ok( 1 === ( $s['members_by_type']['founder'] ?? 0 ), 'report anno sociale: 1 fondatore' );
apse_ok( ! isset( $s['members_by_type']['volunteer'] ), 'report anno sociale: il volontario senza quota non è iscritto' );
apse_ok( 2 === count( $s['activities'] ), 'report anno sociale: due attività (Yoga e Teatro)' );
$yoga_sum = array_values( array_filter( $s['activities'], function ( $a ) { return 'Yoga' === $a['activity']['name']; } ) )[0];
apse_ok( 4000 === $yoga_sum['income'] && 1500 === $yoga_sum['cost'] && 2500 === $yoga_sum['margin'], 'report anno sociale: incassi 40,00, costi 15,00, resta 25,00' );

// ---------- Tipi di attività: evento una tantum, ricorrente, corso con contributo ospiti ----------
$ev_date = gmdate( 'Y-m-d', strtotime( $today . ' +10 days' ) );
apse_ok( null !== apse_throws( function () use ( $acts, $sy_label ) { $acts->create( array( 'name' => 'Senza data', 'social_year' => $sy_label, 'kind' => 'event' ) ); } ), 'evento una tantum: la data è obbligatoria' );
$event = $acts->create(
	array(
		'name' => 'Serata giochi', 'social_year' => $sy_label, 'kind' => 'event', 'instructor_person_id' => $vol, 'fee_cents' => 500, 'guest_fee_cents' => 800,
		'session' => array( 'session_date' => $ev_date, 'start_time' => '21:00', 'location' => 'Sede', 'capacity' => 2 ),
	)
);
$ev_sessions = $acts->sessions( $event );
$sid         = (int) $ev_sessions[0]['id'];
apse_ok( 1 === count( $ev_sessions ) && '21:00' === $ev_sessions[0]['start_time'], 'evento una tantum: una data creata' );
apse_ok( null !== apse_throws( function () use ( $acts, $event, $ev_date ) { $acts->add_session( $event, array( 'session_date' => $ev_date ) ); } ), 'evento una tantum: una sola data' );
apse_ok( null !== apse_throws( function () use ( $acts, $event, $ord, $month ) { $acts->enroll( $event, $ord, $month ); } ), 'agli eventi ci si prenota, non ci si iscrive per mesi' );

$acts->book( $sid, $founder );
$acts->book( $sid, $guest );
$by_person = function ( int $session ) use ( $acts ) {
	$out = array();
	foreach ( $acts->bookings_for_session( $session ) as $b ) {
		$out[ (int) $b['person_id'] ] = $b;
	}
	return $out;
};
$bk = $by_person( $sid );
apse_ok( 500 === (int) $bk[ $founder ]['fee_due_cents'] && 800 === (int) $bk[ $guest ]['fee_due_cents'], 'evento: contributo soci 5,00 e contributo ospiti 8,00' );
apse_ok( null !== apse_throws( function () use ( $acts, $sid, $founder ) { $acts->book( $sid, $founder ); } ), 'non si prenota due volte' );
$msg = (string) apse_throws( function () use ( $acts, $sid, $ord ) { $acts->book( $sid, $ord ); } );
apse_ok( false !== stripos( $msg, 'esauriti' ), 'capienza: posti esauriti' );

$pay = function ( int $person, int $cents, ?int $session, ?int $activity = null ) use ( $ledger, $today, $cash, $cat, $event ) {
	return $ledger->record_receipt(
		array(
			'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $person,
			'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => $cents, 'activity_id' => $activity ?: $event, 'session_id' => $session ) ),
		)
	);
};
apse_ok( null !== apse_throws( function () use ( $pay, $founder ) { $pay( $founder, 500, null ); } ), 'contributo evento senza la data: rifiutato' );
apse_ok( null !== apse_throws( function () use ( $pay, $vol, $sid ) { $pay( $vol, 500, $sid ); } ), 'contributo di chi non è prenotato: rifiutato' );
apse_ok( null !== apse_throws( function () use ( $pay, $ord, $sid, $yoga ) { $pay( $ord, 500, $sid, $yoga ); } ), 'un corso non accetta la data di un evento' );
$pay( $founder, 500, $sid );
$pay( $guest, 300, $sid );
$bk = $by_person( $sid );
apse_ok( 'paid' === $bk[ $founder ]['state'] && 'partial' === $bk[ $guest ]['state'] && 500 === $bk[ $guest ]['remaining'], 'pagamenti evento: socio pagato, ospite parziale (resta 5,00)' );
$unpaid = $acts->unpaid_bookings_for_person( $guest );
apse_ok( 1 === count( $unpaid ) && 500 === $unpaid[0]['remaining'], 'incasso: l\'evento da pagare viene proposto' );
apse_ok( 0 === count( $acts->unpaid_bookings_for_person( $founder ) ), 'incasso: niente da proporre se già pagato' );

$acts->cancel_booking( $sid, $founder );
$acts->book( $sid, $ord ); // il posto liberato si può riprenotare
apse_ok( 2 === (int) $acts->sessions( $event )[0]['booked_count'], 'annullando una prenotazione si libera il posto' );
apse_ok( 2 === $acts->active_participants( $event ), 'partecipanti dell\'evento' );

// evento ricorrente gratuito per i soci
$rec = $acts->create( array( 'name' => 'Aperitivo del giovedì', 'social_year' => $sy_label, 'kind' => 'recurring', 'fee_cents' => 0, 'guest_fee_cents' => 300 ) );
$rec_to = gmdate( 'Y-m-d', strtotime( $ev_date . ' +21 days' ) );
apse_ok( 4 === $acts->generate_weekly( $rec, $ev_date, $rec_to, '19:30', 'Bar', 20 ), 'ricorrente: quattro date settimanali' );
apse_ok( 0 === $acts->generate_weekly( $rec, $ev_date, $rec_to ), 'ricorrente: nessun duplicato rigenerando' );
apse_ok( null !== apse_throws( function () use ( $acts, $rec ) { $acts->generate_weekly( $rec, '2026-01-10', '2026-01-01' ); } ), 'ricorrente: periodo al contrario rifiutato' );
apse_ok( null !== apse_throws( function () use ( $acts, $yoga, $ev_date ) { $acts->add_session( $yoga, array( 'session_date' => $ev_date ) ); } ), 'i corsi non hanno date' );
$rec_sessions = $acts->sessions( $rec );
$s1           = (int) $rec_sessions[0]['id'];
$s2           = (int) $rec_sessions[1]['id'];
$acts->book( $s1, $ord );
$acts->book( $s1, $guest );
$acts->book( $s2, $ord ); // iscrizione al singolo evento: ogni data si prenota separatamente
$bk = $by_person( $s1 );
apse_ok( 'free' === $bk[ $ord ]['state'] && 300 === (int) $bk[ $guest ]['fee_due_cents'], 'ricorrente: gratuito per i soci, 3,00 per gli ospiti' );
apse_ok( 2 === $acts->active_participants( $rec ), 'ricorrente: persone distinte' );
apse_ok( 1 === count( $acts->bookings_for_session( $s2 ) ), 'ricorrente: ogni data ha le sue prenotazioni' );
$acts->cancel_session( $s2 );
$cancelled = array_filter( $acts->bookings_for_person( $ord ), function ( $b ) use ( $s2 ) { return (int) $b['session_id'] === $s2 && ! $b['active']; } );
apse_ok( 1 === count( $cancelled ), 'data annullata: la prenotazione non conta più' );
apse_ok( null !== apse_throws( function () use ( $acts, $s2, $vol ) { $acts->book( $s2, $vol ); } ), 'non si prenota una data annullata' );

// corso con contributo ospiti diverso
$corso = $acts->create( array( 'name' => 'Disegno', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 2000, 'guest_fee_cents' => 3500 ) );
$acts->enroll( $corso, $ord, $month );
$acts->enroll( $corso, $guest, $month );
$course_summary = function () use ( $acts, $corso ) {
	$out = array();
	foreach ( $acts->status_for_activity( $corso ) as $x ) {
		$out[ (int) $x['enrollment']['person_id'] ] = $x['summary'];
	}
	return $out;
};
$cs = $course_summary();
apse_ok( -2000 === $cs[ $ord ]['balance'] && -3500 === $cs[ $guest ]['balance'], 'corso: mensilità soci 20,00 e ospiti 35,00' );
$acts->update( $corso, array( 'guest_fee_cents' => 0 ) );
$cs = $course_summary();
apse_ok( 0 === $cs[ $guest ]['balance'] && -2000 === $cs[ $ord ]['balance'], 'corso: contributo ospiti 0 = gratuito per gli ospiti' );
$acts->update( $corso, array( 'guest_fee_cents' => null ) );
apse_ok( 2000 === $acts->fee_for( $acts->get( $corso ), 'guest' ), 'corso: contributo ospiti non impostato = come i soci' );
$acts->update( $corso, array( 'kind' => 'event' ) );
apse_ok( 'course' === $acts->get( $corso )['kind'], 'il tipo non cambia dopo la creazione' );

// REST per eventi e prenotazioni
$r = apse_rest( $u_vol, "/apsemplice/v1/activities/$event/sessions" );
apse_ok( 200 === $r->get_status() && 1 === count( $r->get_data()['sessions'] ) && 2 === $r->get_data()['sessions'][0]['capacity'], 'REST sessions: il volontario istruttore vede le date' );
apse_ok( 403 === apse_rest( $u_ord, "/apsemplice/v1/activities/$rec/sessions" )->get_status(), 'REST sessions: chi non è iscritto/istruttore è rifiutato' );
$r = apse_rest( $u_vol, "/apsemplice/v1/sessions/$sid/bookings" );
apse_ok( 200 === $r->get_status() && ! isset( $r->get_data()['bookings'][0]['email'] ) && ! isset( $r->get_data()['bookings'][0]['state'] ), 'REST bookings: il volontario vede solo i nomi' );
apse_ok( isset( apse_rest( 1, "/apsemplice/v1/sessions/$sid/bookings" )->get_data()['bookings'][0]['state'] ), 'REST bookings: l\'amministratore vede anche i pagamenti' );
apse_ok( 403 === apse_rest( $u_ord, "/apsemplice/v1/sessions/$sid/bookings" )->get_status(), 'REST bookings: il socio è rifiutato' );
$r = apse_rest( $u_vol, "/apsemplice/v1/activities/$event/participants" );
apse_ok( 200 === $r->get_status() && 2 === count( $r->get_data()['participants'] ), 'REST participants: anche per gli eventi' );
$r = apse_rest( $u_ord, '/apsemplice/v1/me/bookings' );
apse_ok( 200 === $r->get_status() && count( $r->get_data()['bookings'] ) >= 3, 'REST /me/bookings' );
wp_set_current_user( 1 );


// ---------- Front-end: shortcode, area soci, prenotazioni dal sito, contenuti riservati ----------
$people->set_membership( $ord, $sy_label, true );
$people->set_membership( $vol, $sy_label, true );
$u_f = (int) $people->get( $founder )['wp_user_id'];
foreach ( array_keys( \ApSemplice\Frontend\Shortcodes::VIEWS ) as $slug ) {
	apse_ok( shortcode_exists( 'apsemplice_' . $slug ), "shortcode apsemplice_$slug registrato" );
}
apse_ok( shortcode_exists( 'apsemplice_riservato' ), 'shortcode apsemplice_riservato registrato' );
// aree del sito: ogni vista ha la sua area, blocchi e widget si scelgono in ordine
$ar_opt = \ApSemplice\Areas::options();
apse_ok( array() === \ApSemplice\Areas::unassigned(), 'aree: ogni vista appartiene a un\'area' );
apse_ok( 'tesoriere' === \ApSemplice\Areas::area_of( 'spese' ) && 'segreteria' === \ApSemplice\Areas::area_of( 'segreteria' ) && 'eventi' === \ApSemplice\Areas::area_of( 'ingressi' ) && 'pubblico' === \ApSemplice\Areas::area_of( 'bonifico' ) && 'soci' === \ApSemplice\Areas::area_of( 'tessera' ), 'aree: soci, segreteria, tesoriere, eventi e pubblico' );
$ar_keys = array_keys( $ar_opt );
apse_ok( 0 === strpos( $ar_opt['tessera'], 'Soci · ' ) && 0 === strpos( $ar_opt['tesoriere'], 'Tesoriere · ' ) && array_search( 'segreteria', $ar_keys, true ) > array_search( 'area_soci', $ar_keys, true ) && array_search( 'attivita', $ar_keys, true ) > array_search( 'ingressi', $ar_keys, true ), 'aree: le viste per blocchi e widget sono nominate e ordinate per area' );
$ar_views = array();
foreach ( \ApSemplice\Areas::GROUPS as $ar_g ) {
	$ar_views = array_merge( $ar_views, $ar_g[1] );
}
apse_ok( count( $ar_views ) === count( array_unique( $ar_views ) ) && ! array_diff( array_keys( \ApSemplice\Frontend\Shortcodes::VIEWS ), array_merge( $ar_views, array_keys( \ApSemplice\Areas::ALIASES ) ) ), 'aree: nessuna vista in due aree e nessuna dimenticata' );
apse_ok( shortcode_exists( 'apsemplice_segreteria' ) && shortcode_exists( 'apsemplice_tesoriere' ), 'aree: shortcode della segreteria e del tesoriere registrati' );
$ar_bl = \ApSemplice\Frontend\Blocks::class;
apse_ok( class_exists( $ar_bl ), 'aree: blocchi presenti' );
$as = function ( int $user, string $shortcode ) {
	wp_set_current_user( $user );
	return do_shortcode( $shortcode );
};

// Area soci per ruolo
$html = $as( 0, '[apsemplice_area_soci]' );
apse_ok( false !== strpos( $html, 'loginform' ), 'area soci: l\'anonimo vede il modulo di accesso' );
$html = $as( $u_ord, '[apsemplice_area_soci]' );
apse_ok( false !== strpos( $html, 'Omar' ) && false !== strpos( $html, 'Tessera n.' ) && false !== strpos( $html, 'Le mie attività' ) && false !== strpos( $html, 'I miei ospiti' ) && false !== strpos( $html, 'Il mio profilo' ), 'area soci: il socio vede tessera, attività, ospiti e profilo' );
apse_ok( false === strpos( $html, 'Le attività che tengo' ), 'area soci: il socio non vede la parte dei volontari' );
$html_vol = $as( $u_vol, '[apsemplice_area_soci]' );
apse_ok( false !== strpos( $html_vol, 'Le attività che tengo' ) && false !== strpos( $html_vol, 'Yoga' ), 'area soci: il volontario vede le attività che tiene' );
apse_ok( false === strpos( $html_vol, 'omar@example.com' ), 'area soci: il volontario non vede le email degli iscritti' );
apse_ok( false !== strpos( $as( 1, '[apsemplice_area_soci]' ), 'amministratore' ), 'area soci: l\'amministratore senza scheda riceve un messaggio chiaro' );
apse_ok( false !== strpos( $as( $u_ord, '[apsemplice_tessera]' ), 'apsf-memcard' ), 'shortcode tessera' );
apse_ok( false !== strpos( $as( $u_vol, '[apsemplice_area_volontari]' ), 'Yoga' ), 'shortcode area volontari (volontario)' );
apse_ok( false !== strpos( $as( $u_ord, '[apsemplice_area_volontari]' ), 'riservata ai soci e volontari' ), 'shortcode area volontari (socio semplice)' );

// Elenco attività pubblico e prenotazioni
$html = $as( 0, '[apsemplice_attivita]' );
apse_ok( false !== strpos( $html, 'Yoga' ) && false !== strpos( $html, 'Serata giochi' ) && false !== strpos( $html, 'Accedi per prenotarti' ), 'attività: pubblico, con invito ad accedere per prenotarsi' );
apse_ok( false !== strpos( $html, 'Soci 5,00' ) && false !== strpos( $html, 'ospiti 8,00' ), 'attività: contributo soci e ospiti visibile' );
$html = $as( 0, '[apsemplice_attivita tipo="evento"]' );
apse_ok( false !== strpos( $html, 'Serata giochi' ) && false === strpos( $html, 'Yoga' ), 'attività: filtro per tipo' );
apse_ok( false !== strpos( $as( $u_f, '[apsemplice_attivita tipo="ricorrente"]' ), 'apse_front_book' ), 'attività: il socio con tessera valida vede il pulsante Prenotati' );
apse_ok( false !== strpos( $as( 0, '[apsemplice_prossimi_eventi limite="3"]' ), 'apsf-upcoming' ), 'prossimi eventi' );
apse_ok( false !== strpos( $as( 0, '[apsemplice_accesso]' ), 'loginform' ) && '' === $as( $u_f, '[apsemplice_accesso]' ), 'accesso: solo per chi non è collegato' );

// Azioni dei soci
$front = '\ApSemplice\Frontend\Actions';
wp_set_current_user( $u_f );
$s3  = (int) $rec_sessions[2]['id'];
$msg = $front::do_book( array( 'session_id' => $s3, 'person_id' => $founder ) );
apse_ok( false !== strpos( $msg, 'Prenotazione registrata' ) && $acts->has_active_booking( $s3, $founder ), 'sito: il socio si prenota a un evento' );
$front::do_add_guest( array( 'first_name' => 'Gia', 'last_name' => 'Ospite', 'phone' => '333 2222222' ) );
$g_list = $people->guests_of( $founder );
apse_ok( 1 === count( $g_list ) && MemberType::GUEST === $g_list[0]['type'] && (int) $g_list[0]['host_person_id'] === $founder, 'sito: il socio aggiunge un proprio ospite' );
$g_id = (int) $g_list[0]['id'];
$front::do_book( array( 'session_id' => $s3, 'person_id' => $g_id ) );
$gb = $by_person( $s3 );
apse_ok( 300 === (int) $gb[ $g_id ]['fee_due_cents'] && 0 === (int) $gb[ $founder ]['fee_due_cents'], 'sito: l\'ospite prenotato paga il contributo ospiti' );
apse_ok( null !== apse_throws( function () use ( $front, $s3, $guest ) { $front::do_book( array( 'session_id' => $s3, 'person_id' => $guest ) ); } ), 'sito: non si prenota l\'ospite di un altro socio' );
$past_session = $acts->add_session( $rec, array( 'session_date' => gmdate( 'Y-m-d', strtotime( $today . ' -3 days' ) ) ) );
apse_ok( null !== apse_throws( function () use ( $front, $past_session, $founder ) { $front::do_book( array( 'session_id' => $past_session, 'person_id' => $founder ) ); } ), 'sito: non si prenota un evento già passato' );
wp_set_current_user( $u_ord );
apse_ok( null !== apse_throws( function () use ( $front, $s3, $founder ) { $front::do_book( array( 'session_id' => $s3, 'person_id' => $founder ) ); } ), 'sito: non si prenota un altro socio' );
$people->set_membership( $ord, $sy_label, false );
apse_ok( null !== apse_throws( function () use ( $front, $s3, $ord ) { $front::do_book( array( 'session_id' => $s3, 'person_id' => $ord ) ); } ), 'sito: con la tessera scaduta non ci si prenota' );
$people->set_membership( $ord, $sy_label, true );
wp_set_current_user( $u_f );
$msg = (string) apse_throws( function () use ( $front, $s3, $g_id ) { $front::do_cancel_booking( array( 'session_id' => $s3, 'person_id' => $g_id ) ); } );
apse_ok( false !== strpos( $msg, 'non è cancellabile' ) && $acts->has_active_booking( $s3, $g_id ), 'sito: la prenotazione a pagamento dell\'ospite non si annulla' );
$front::do_profile( array( 'phone' => '3331112222', 'tax_code' => 'vrdgpp75b10f205b', 'address' => 'Via Verdi 9', 'zip' => '00100', 'city' => 'Roma' ) );
apse_ok( '3331112222' === $people->get( $founder )['phone'] && 'VRDGPP75B10F205B' === $people->get( $founder )['tax_code'] && 'Via Verdi 9' === $people->get( $founder )['address'], 'sito: il socio aggiorna il proprio profilo' );

// Contenuti riservati: pagine e articoli
wp_set_current_user( 1 );
$mk   = function ( string $title, string $rule, array $ids = array() ) {
	$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => "SEGRETO $title", 'post_excerpt' => "RIASSUNTO $title" ) );
	if ( 'public' !== $rule ) {
		update_post_meta( $id, '_aps_access', $rule );
		update_post_meta( $id, '_aps_access_activities', $ids );
	}
	return $id;
};
$show = function ( int $post_id, int $user ) {
	wp_set_current_user( $user );
	$GLOBALS['post'] = get_post( $post_id );
	setup_postdata( $GLOBALS['post'] );
	$html = apply_filters( 'the_content', $GLOBALS['post']->post_content );
	wp_reset_postdata();
	return $html;
};
$p_pub  = $mk( 'Pubblico', 'public' );
$p_mem  = $mk( 'Solo soci', 'members' );
$p_vol  = $mk( 'Solo volontari', 'volunteers' );
$p_yoga = $mk( 'Programma Yoga', 'activity', array( $yoga ) );
$p_teat = $mk( 'Programma Teatro', 'activity', array( $other ) );
$sees   = function ( int $post, int $user ) use ( $show ) {
	return false !== strpos( $show( $post, $user ), 'SEGRETO' );
};
apse_ok( $sees( $p_pub, 0 ), 'riservati: il contenuto pubblico lo vedono tutti' );
apse_ok( ! $sees( $p_mem, 0 ) && false !== strpos( $show( $p_mem, 0 ), 'Accedi' ), 'riservati: l\'anonimo vede l\'invito ad accedere, non il contenuto' );
apse_ok( $sees( $p_mem, $u_ord ) && ! $sees( $p_vol, $u_ord ), 'riservati: il socio vede "solo soci" ma non "solo volontari"' );
apse_ok( $sees( $p_vol, $u_vol ), 'riservati: il volontario vede "solo volontari"' );
apse_ok( $sees( $p_yoga, $u_ord ) && ! $sees( $p_teat, $u_ord ), 'riservati: il socio iscritto a Yoga vede il programma di Yoga ma non quello di Teatro' );
apse_ok( $sees( $p_yoga, $u_vol ) && ! $sees( $p_teat, $u_vol ), 'riservati: l\'istruttore vede solo i contenuti delle sue attività' );
apse_ok( $sees( $p_mem, $u_f ) && ! $sees( $p_vol, $u_f ) && ! $sees( $p_yoga, $u_f ), 'riservati: il fondatore vede "solo soci" ma non quello di attività a cui non è iscritto' );
apse_ok( $sees( $p_vol, 1 ) && $sees( $p_teat, 1 ) && $sees( $p_yoga, 1 ), 'riservati: l\'amministratore vede tutto' );
$people->set_membership( $ord, $sy_label, false );
apse_ok( ! $sees( $p_mem, $u_ord ) && $sees( $p_yoga, $u_ord ), 'riservati: tessera scaduta = niente "solo soci", ma resta il programma dell\'attività a cui è iscritto' );
$people->set_membership( $ord, $sy_label, true );

wp_set_current_user( 0 );
$rest = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $p_mem ) )->get_data();
apse_ok( '' === $rest['content']['rendered'] && ! empty( $rest['content']['protected'] ), 'riservati: l\'API REST non rivela il contenuto' );
apse_ok( false === strpos( get_the_excerpt( $p_mem ), 'RIASSUNTO' ), 'riservati: neanche il riassunto' );
wp_set_current_user( 1 );
$rest = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $p_mem ) )->get_data();
apse_ok( false !== strpos( $rest['content']['rendered'], 'SEGRETO' ), 'riservati: l\'amministratore legge il contenuto via REST' );

// Riquadro nell'editor e colonna
wp_set_current_user( 1 );
$p_edit = $mk( 'Da riservare', 'public' );
$_POST  = array( 'apse_access_nonce' => wp_create_nonce( 'apse_access_save' ), 'apse_access' => 'activity', 'apse_access_activities' => array( (string) $yoga ) );
\ApSemplice\Frontend\Restrict::save_meta_box( $p_edit, get_post( $p_edit ) );
apse_ok( 'activity' === get_post_meta( $p_edit, '_aps_access', true ) && array( $yoga ) === array_map( 'intval', (array) get_post_meta( $p_edit, '_aps_access_activities', true ) ), 'riquadro Accesso: salva regola e attività' );
ob_start();
\ApSemplice\Frontend\Restrict::print_column( 'apse_access', $p_edit );
apse_ok( false !== strpos( ob_get_clean(), 'Iscritti: Yoga' ), 'colonna Accesso negli elenchi' );
ob_start();
\ApSemplice\Frontend\Restrict::render_meta_box( get_post( $p_edit ) );
apse_ok( false !== strpos( ob_get_clean(), 'name="apse_access"' ), 'riquadro Accesso: si disegna' );
$_POST = array( 'apse_access_nonce' => wp_create_nonce( 'apse_access_save' ), 'apse_access' => 'public' );
\ApSemplice\Frontend\Restrict::save_meta_box( $p_edit, get_post( $p_edit ) );
apse_ok( '' === get_post_meta( $p_edit, '_aps_access', true ), 'riquadro Accesso: tornando pubblico la regola si toglie' );
$_POST = array( 'apse_access_nonce' => 'sbagliato', 'apse_access' => 'members' );
\ApSemplice\Frontend\Restrict::save_meta_box( $p_edit, get_post( $p_edit ) );
apse_ok( '' === get_post_meta( $p_edit, '_aps_access', true ), 'riquadro Accesso: nonce errato ignorato' );
$_POST = array();

// Parti di pagina: shortcode e blocchi
apse_ok( false === strpos( $as( 0, '[apsemplice_riservato accesso="soci"]INTERNO[/apsemplice_riservato]' ), 'INTERNO' ), 'parte riservata (shortcode): nascosta agli anonimi' );
apse_ok( false !== strpos( $as( $u_f, '[apsemplice_riservato accesso="soci"]INTERNO[/apsemplice_riservato]' ), 'INTERNO' ), 'parte riservata (shortcode): visibile ai soci' );
apse_ok( false !== strpos( $as( $u_ord, "[apsemplice_riservato accesso=\"attivita\" attivita=\"$yoga\"]X-YOGA[/apsemplice_riservato]" ), 'X-YOGA' ) && false === strpos( $as( $u_f, "[apsemplice_riservato accesso=\"attivita\" attivita=\"$yoga\"]X-YOGA[/apsemplice_riservato]" ), 'X-YOGA' ), 'parte riservata (shortcode): solo gli iscritti all\'attività' );
apse_ok( false !== strpos( $as( 0, '[apsemplice_riservato accesso="soci" messaggio="Solo per noi"]x[/apsemplice_riservato]' ), 'Solo per noi' ), 'parte riservata: messaggio personalizzato' );
$registry = WP_Block_Type_Registry::get_instance();
apse_ok( $registry->is_registered( 'apsemplice/vista' ) && $registry->is_registered( 'apsemplice/riservato' ), 'blocchi Gutenberg registrati' );
$block = function ( string $name, array $attrs, string $inner = '' ) {
	return render_block( array( 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => $inner, 'innerContent' => array( $inner ) ) );
};
wp_set_current_user( 0 );
apse_ok( false === strpos( $block( 'apsemplice/riservato', array( 'accesso' => 'members' ), '<p>BLK</p>' ), 'BLK' ), 'blocco Contenuto riservato: nascosto agli anonimi' );
wp_set_current_user( $u_f );
apse_ok( false !== strpos( $block( 'apsemplice/riservato', array( 'accesso' => 'members' ), '<p>BLK</p>' ), 'BLK' ), 'blocco Contenuto riservato: visibile ai soci' );
apse_ok( false !== strpos( $block( 'apsemplice/vista', array( 'vista' => 'attivita' ) ), 'Yoga' ), 'blocco APSemplice (vista attività)' );
wp_set_current_user( 1 );
apse_ok( file_exists( APSE_DIR . 'assets/blocks.js' ) && file_exists( APSE_DIR . 'assets/frontend.css' ), 'asset front-end presenti' );

// Pagine standard
$pages = new ReflectionMethod( Admin\Actions::class, 'create_pages' );
$pages->setAccessible( true );
$res1 = $pages->invoke( null, array() );
$saved_pages = (array) get_option( 'apse_pages', array() );
apse_ok( 3 === count( $saved_pages ) && false !== strpos( get_post( $saved_pages['area'] )->post_content, '[apsemplice_area_soci]' ), 'pagine standard create con gli shortcode' );
apse_ok( 'volunteers' === get_post_meta( $saved_pages['volontari'], '_aps_access', true ), 'la pagina Area volontari è riservata ai volontari' );
apse_ok( (int) $saved_pages['area'] === (int) Settings::get( 'member_area_page_id' ) && Gatekeeper::area_url() === get_permalink( $saved_pages['area'] ), 'l\'Area soci diventa la pagina di arrivo dopo il login' );
apse_ok( false !== strpos( $pages->invoke( null, array() )[1], 'esistono già' ), 'pagine standard: non si duplicano' );
apse_ok( false !== strpos( do_shortcode( get_post( $saved_pages['attivita'] )->post_content ), 'Yoga' ), 'la pagina Attività mostra le attività' );

// Configurazione guidata
$wz_ent = (string) Settings::get( 'entity_type' );
$wz_mem = (string) Settings::get( 'member_term' );
$wz_old = array( 'association_name' => Settings::get( 'association_name' ), 'tax_code' => Settings::get( 'tax_code' ), 'payment_provider' => Settings::get( 'payment_provider' ), 'bank_enabled' => Settings::get( 'bank_enabled' ) );
apse_ok( null !== apse_throws( function () { \ApSemplice\Wizard::apply( array( 'entity_type' => 'astronave', 'member_term' => 'socio' ) ); } ) && null !== apse_throws( function () { \ApSemplice\Wizard::apply( array( 'tax_code' => '123' ) ); } ) && null !== apse_throws( function () { \ApSemplice\Wizard::apply( array( 'payment_choice' => 'bitcoin' ) ); } ), 'configurazione guidata: ente, codice fiscale e pagamento non validi si rifiutano' );
apse_ok( null !== apse_throws( function () { \ApSemplice\Wizard::apply( array( 'payment_choice' => 'woocommerce' ) ); } ) || \ApSemplice\WooBridge::active(), 'configurazione guidata: WooCommerce si sceglie solo se è attivo' );
$wz_done = \ApSemplice\Wizard::apply( array(
	'entity_type' => $wz_ent, 'member_term' => $wz_mem, 'association_name' => 'Circolo Prova', 'tax_code' => '12345678903', 'social_year_start_month' => '9',
	'payment_choice' => 'stripe_paypal', 'bank_enabled' => '1', 'pages_present' => '1', 'pages' => array( 'calendario', 'inesistente' ),
) );
apse_ok( 'Circolo Prova' === Settings::get( 'association_name' ) && '12345678903' === Settings::get( 'tax_code' ) && 'stripe_paypal' === Settings::get( 'payment_provider' ) && 1 === (int) Settings::get( 'bank_enabled' ) && $wz_ent === Settings::get( 'entity_type' ), 'configurazione guidata: ente e pagamenti applicati' );
$wz_pages = \ApSemplice\Pages::existing();
apse_ok( isset( $wz_pages['calendario'] ) && ! isset( $wz_pages['inesistente'] ) && 'members' === get_post_meta( $wz_pages['calendario'], '_aps_access', true ) && false !== strpos( implode( ' ', $wz_done ), 'Calendario' ), 'configurazione guidata: crea solo le pagine note, con l\'accesso giusto' );
\ApSemplice\Wizard::apply( array( 'pages_present' => '1', 'pages' => array( 'calendario' ) ) );
apse_ok( count( \ApSemplice\Pages::existing() ) === count( $wz_pages ), 'configurazione guidata: le pagine esistenti non si duplicano' );
\ApSemplice\Wizard::apply( array( 'pages_present' => '1', 'pages' => array( 'segreteria', 'tesoriere', 'ingressi' ) ) );
$wz_p2 = \ApSemplice\Pages::existing();
apse_ok( isset( $wz_p2['segreteria'], $wz_p2['tesoriere'], $wz_p2['ingressi'] ) && false !== strpos( get_post( $wz_p2['segreteria'] )->post_content, '[apsemplice_segreteria]' ) && false !== strpos( get_post( $wz_p2['tesoriere'] )->post_content, '[apsemplice_tesoriere]' ) && false !== strpos( get_post( $wz_p2['ingressi'] )->post_content, '[apsemplice_ingressi]' ), 'pagine: la procedura crea le pagine delle aree segreteria, tesoriere e ingressi' );
$wz_by = \ApSemplice\Pages::by_area();
apse_ok( array( 'soci', 'segreteria', 'tesoriere', 'eventi', 'pubblico' ) === array_keys( $wz_by ) && isset( $wz_by['eventi']['ingressi'], $wz_by['soci']['area'], $wz_by['pubblico']['bonifico'] ), 'pagine: raggruppate per area (soci, segreteria, tesoriere, eventi, pubblico)' );
$sg_adm  = $as( 1, '[apsemplice_segreteria]' );
$sg_anon = $as( 0, '[apsemplice_segreteria]' );
$sg_mem  = $as( $u_f, '[apsemplice_segreteria]' );
apse_ok( false !== strpos( $sg_adm, 'Segreteria' ) && false !== strpos( $sg_adm, 'page=apse-people' ) && false === strpos( $sg_anon, 'page=apse-people' ) && false !== strpos( $sg_mem, 'riservata alla segreteria' ) && false === strpos( $sg_mem, 'page=apse-people' ), 'area segreteria: i collegamenti compaiono solo a chi ha i permessi della segreteria' );
wp_set_current_user( 1 );
apse_ok( \ApSemplice\Wizard::DONE === \ApSemplice\Wizard::status() && ! \ApSemplice\Wizard::pending(), 'configurazione guidata: risulta completata' );
\ApSemplice\Wizard::mark( \ApSemplice\Wizard::SKIPPED );
ob_start();
Admin\WizardPage::render();
$wz_html = (string) ob_get_clean();
apse_ok( false !== strpos( $wz_html, 'docs.stripe.com/keys' ) && false !== strpos( $wz_html, 'target="_blank"' ) && false !== strpos( $wz_html, 'rel="noopener noreferrer"' ) && false !== strpos( $wz_html, 'Applica la configurazione' ), 'configurazione guidata: pagina con i tutorial in una nuova finestra' );
apse_ok( in_array( 'apse-wizard', Admin\Admin::ADMIN_ONLY, true ) && isset( Admin\TechActions::ACTIONS['apse_wizard_save'] ), 'configurazione guidata: riservata agli amministratori' );
// ... a domande: parti del gestionale, iscrizione su presentazione, altri tipi di socio
apse_ok( ! in_array( 'apse-ledger', Admin\Admin::disabled_pages(), true ) && \ApSemplice\Modules::on( 'accounts' ), 'moduli: prima della configurazione è tutto acceso' );
\ApSemplice\Wizard::apply( array( 'mod_present' => '1', 'mod' => array( 'activities' => '1', 'ledger' => '0', 'accounts' => '1', 'accounting' => '1', 'reports' => '1', 'book' => '0', 'messages' => '1', 'import' => '0' ) ) );
$wz_off = Admin\Admin::disabled_pages();
apse_ok( ! \ApSemplice\Modules::on( 'ledger' ) && ! \ApSemplice\Modules::on( 'accounts' ) && ! \ApSemplice\Modules::on( 'reports' ) && \ApSemplice\Modules::on( 'activities' ) && in_array( 'apse-ledger', $wz_off, true ) && in_array( 'apse-accounts', $wz_off, true ) && in_array( 'apse-statement', $wz_off, true ) && in_array( 'apse-book', $wz_off, true ) && ! in_array( 'apse-activities', $wz_off, true ), 'moduli: senza prima nota spariscono anche conti e bilanci; le parti scelte restano' );
ob_start();
call_user_func( Admin\Admin::guard( 'apse-ledger', function () { echo 'CONTENUTO'; } ) );
$wz_g = (string) ob_get_clean();
apse_ok( false === strpos( $wz_g, 'CONTENUTO' ) && false !== strpos( $wz_g, 'configurazione guidata' ), 'moduli: una pagina spenta non si apre e indica come riaccenderla' );
\ApSemplice\Wizard::apply( array( 'mod_present' => '1', 'mod' => array( 'activities' => '1', 'ledger' => '1', 'accounts' => '0', 'accounting' => '1', 'reports' => '1', 'book' => '1', 'messages' => '1', 'import' => '1' ) ) );
apse_ok( \ApSemplice\Modules::on( 'ledger' ) && ! \ApSemplice\Modules::on( 'accounts' ) && \ApSemplice\Modules::on( 'reports' ) && 1 === (int) Settings::get( 'reports_enabled' ), 'moduli: riaccendendo la prima nota tornano i bilanci scelti, i conti restano spenti' );
Settings::update( array( 'modules' => array(), 'reports_enabled' => 1 ) );
apse_ok( \ApSemplice\Modules::on( 'accounts' ) && ! Admin\Admin::disabled_pages(), 'moduli: ripristino, tutto acceso' );
$wz_req = count( \ApSemplice\AccessRequests::pending() );
\ApSemplice\Wizard::apply( array( 'join_mode' => 'invite' ) );
apse_ok( 'invite' === Settings::get( 'join_mode' ) && 'unknown_queued' === \ApSemplice\Frontend\FirstAccess::submit( 'Sconosciuto Presentato', 'sconosciuto.presentato@example.com', '347 9990011' ) && count( \ApSemplice\AccessRequests::pending() ) === $wz_req, 'iscrizione su presentazione: lo sconosciuto riceve la stessa risposta ma nessuna richiesta viene registrata' );
\ApSemplice\Wizard::apply( array( 'join_mode' => 'request' ) );
\ApSemplice\Frontend\FirstAccess::submit( 'Sconosciuto Richiesta', 'sconosciuto.richiesta@example.com', '347 9990022' );
apse_ok( count( \ApSemplice\AccessRequests::pending() ) === $wz_req + 1, 'iscrizione aperta alle richieste: la richiesta arriva alla segreteria' );
foreach ( \ApSemplice\AccessRequests::pending() as $wz_r ) { // la coda torna com'era: altri controlli la leggono
	if ( 'sconosciuto.richiesta@example.com' === ( $wz_r['email'] ?? '' ) ) {
		\ApSemplice\AccessRequests::remove( (string) $wz_r['id'] );
	}
}
\ApSemplice\Wizard::apply( array( 'extra_levels' => "Ridotto Prova; 15\nSostenitore Prova" ) );
$wz_lv = array_column( \ApSemplice\Levels::all(), 'fee_cents', 'name' );
\ApSemplice\Wizard::apply( array( 'extra_levels' => 'Ridotto Prova; 15' ) );
apse_ok( 1500 === (int) ( $wz_lv['Ridotto Prova'] ?? 0 ) && array_key_exists( 'Sostenitore Prova', $wz_lv ) && null === $wz_lv['Sostenitore Prova'] && count( \ApSemplice\Levels::all() ) === count( $wz_lv ), 'tipi di socio: aggiunti dalla procedura con la loro quota, senza duplicati' );
apse_ok( null !== apse_throws( function () { \ApSemplice\Wizard::apply( array( 'extra_levels' => 'Strano; abc' ) ); } ) && null !== apse_throws( function () { \ApSemplice\Wizard::apply( array( 'privacy_url' => 'javascript:alert(1)' ) ); } ), 'configurazione guidata: quota e indirizzo privacy non validi si rifiutano' );
\ApSemplice\Wizard::apply( array( 'privacy_url' => 'https://example.com/privacy', 'privacy_retention_years' => '7', 'receipt_footer' => 'Esente IVA' ) );
apse_ok( 'https://example.com/privacy' === Settings::get( 'privacy_url' ) && 7 === (int) Settings::get( 'privacy_retention_years' ), 'configurazione guidata: privacy e ricevute' );
Settings::update( array( 'privacy_url' => '', 'privacy_retention_years' => 5, 'receipt_footer' => '' ) );
$wz_html2 = $wz_html;
apse_ok( false !== strpos( $wz_html2, 'Cosa ti serve' ) && false !== strpos( $wz_html2, 'Solo su presentazione' ) && false !== strpos( $wz_html2, 'name="mod[ledger]"' ) && false !== strpos( $wz_html2, 'data-if="mod[ledger]=1"' ), 'configurazione guidata: domande per parte e blocchi condizionati dalle risposte' );
// procedura guidata: partita IVA, sede e adempimenti
apse_ok( null !== apse_throws( function () { \ApSemplice\Wizard::apply( array( 'has_vat' => '1', 'vat_number' => '12345678901' ) ); } ) && null !== apse_throws( function () { \ApSemplice\Wizard::apply( array( 'pec' => 'non-una-pec' ) ); } ), 'procedura: partita IVA o PEC non valide si rifiutano' );
\ApSemplice\Wizard::apply( array( 'has_vat' => '1', 'vat_number' => 'IT 12345678903', 'fiscal_regime' => 'ordinario', 'vat_default_rate' => '10', 'vat_prices_mode' => 'escl', 'vat_membership_rate' => '22', 'legal_address' => 'Via Verdi 3', 'legal_zip' => '10100', 'legal_city' => 'Torino', 'legal_province' => 'TO', 'pec' => 'ente@pec.example.com' ) );
apse_ok( \ApSemplice\Fiscal::vat_applies() && '12345678903' === Settings::get( 'vat_number' ) && 10 === \ApSemplice\Fiscal::default_rate() && 'escl' === \ApSemplice\Fiscal::default_mode() && 22 === \ApSemplice\Fiscal::membership_rate() && 'Torino' === Settings::get( 'legal_city' ), 'procedura: partita IVA, aliquote e sede applicate' );
\ApSemplice\Wizard::apply( array( 'association_name' => 'Solo nome cambiato' ) );
apse_ok( \ApSemplice\Fiscal::vat_applies() && '12345678903' === Settings::get( 'vat_number' ), 'procedura: se il passo non c\'è, la partita IVA già impostata non si tocca' );
\ApSemplice\Wizard::apply( array( 'has_vat' => '0', 'vat_number' => '12345678903', 'vat_membership_rate' => '22' ) );
apse_ok( ! \ApSemplice\Fiscal::vat_applies() && '' === Settings::get( 'vat_number' ) && null === \ApSemplice\Fiscal::membership_rate(), 'procedura: senza partita IVA il numero e l\'aliquota delle quote si azzerano' );
$wz_feat = array_intersect_key( Settings::all(), Admin\SettingsPage::FEATURES ); // l'elenco delle funzioni spegne ciò che non è spuntato: si ripristina dopo il controllo
Settings::update( array( 'fivepm_enabled' => 1 ) );
\ApSemplice\Wizard::apply( array( 'features_present' => '1' ) );
apse_ok( 1 === (int) Settings::get( 'fivepm_enabled' ), 'procedura: l\'elenco delle funzioni non tocca il 5x1000, che ha il suo passo' );
Settings::update( $wz_feat );
Settings::update( array( 'fivepm_enabled' => 1 ) );
\ApSemplice\Wizard::apply( array( 'adempimenti_present' => '1' ) );
apse_ok( 0 === (int) Settings::get( 'fivepm_enabled' ), 'procedura: nel passo adempimenti, senza risposta sì il 5x1000 si spegne' );
\ApSemplice\Wizard::apply( array( 'adempimenti_present' => '1', 'fivepm_enabled' => '1' ) );
apse_ok( 1 === (int) Settings::get( 'fivepm_enabled' ), 'procedura: il 5x1000 si accende dal passo adempimenti' );
ob_start();
Admin\WizardPage::render();
$wz_html3 = (string) ob_get_clean();
apse_ok( false !== strpos( $wz_html3, 'Partita IVA' ) && false !== strpos( $wz_html3, 'data-if="has_vat=1"' ) && false !== strpos( $wz_html3, 'name="vat_number"' ) && false !== strpos( $wz_html3, 'data-if="mod[accounting]=1"' ) && false !== strpos( $wz_html3, 'name="fivepm_enabled"' ) && false !== strpos( $wz_html3, 'name="legal_address"' ), 'procedura: passo partita IVA con i campi che compaiono solo se c\'è, e domanda sul 5x1000 legata alla contabilità' );
apse_ok( false !== strpos( $wz_html3, 'name="go_import"' ) && false !== strpos( $wz_html3, 'Elenco dei soci' ) && false === strpos( $wz_html3, 'name="mod[import]"' ), 'procedura: passo per importare l\'elenco di soci e ospiti, sempre proposto (le importazioni non si chiedono)' );
Settings::update( array( 'payment_provider' => 'none', 'bank_enabled' => 0 ) );
$wz_go = Admin\TechActions::wizard_save( array( 'go_import' => '1' ) );
apse_ok( false !== strpos( $wz_go[0], 'apse-import' ) && false !== strpos( $wz_go[1], 'elenco' ), 'procedura: a fine configurazione porta all\'importazione dell\'elenco' );
$wz_nogo = Admin\TechActions::wizard_save( array() );
apse_ok( false === strpos( $wz_nogo[0], 'apse-import' ), 'procedura: senza la richiesta non porta all\'importazione' );
ob_start();
Admin\ToolsPage::render();
$wz_tools = (string) ob_get_clean();
ob_start();
Admin\EntityPage::render();
$wz_ent_html = (string) ob_get_clean();
apse_ok( false !== strpos( $wz_tools, 'Configurazione guidata' ) && false !== strpos( $wz_tools, 'page=apse-wizard' ) && false === strpos( $wz_ent_html, 'page=apse-wizard' ), 'procedura: si riapre dagli Strumenti, non è sempre in prima linea nelle impostazioni' );
// procedura minima: ospiti e iscrizione nel primo passo, privacy tra le pagine esistenti, niente anonimizzazione, funzioni facoltative a scomparsa
$wz_p_ente = strpos( $wz_html3, 'Il tuo ente' );
$wz_p_iva  = strpos( $wz_html3, '>Partita IVA</h2>' );
apse_ok( false !== $wz_p_ente && false !== $wz_p_iva && $wz_p_ente < $wz_p_iva && ( $wz_ente_html = substr( $wz_html3, $wz_p_ente, $wz_p_iva - $wz_p_ente ) ) && false !== strpos( $wz_ente_html, 'name="guests_enabled"' ) && false !== strpos( $wz_ente_html, 'name="join_mode"' ) && false !== strpos( $wz_ente_html, 'name="member_term"' ) && false !== strpos( $wz_ente_html, '<details' ), 'procedura: nel primo passo nome, tipo, come si chiamano i soci, ospiti e chi può iscriversi; il resto in «Più dettagli»' );
apse_ok( false !== strpos( $wz_html3, 'privacy_page_id' ) && false === strpos( $wz_html3, 'privacy_retention_years' ) && false === strpos( $wz_html3, 'Conservazione dei dati' ) && false !== strpos( $wz_html3, 'altre funzioni facoltative' ), 'procedura: privacy tra le pagine esistenti, niente anonimizzazione, funzioni facoltative a scomparsa' );
$wz_steps = preg_match_all( '/<section class="apse-wiz-step"/', $wz_html3 );
apse_ok( $wz_steps <= 8, 'procedura: al massimo otto passi (' . $wz_steps . ')' );
apse_ok( false !== strpos( $wz_html3, 'Descrivi il tuo ente e scegli in 4 veloci sezioni i servizi che vuoi gestire' ) && 4 === preg_match_all( '/<li data-group="/', $wz_html3 ) && false !== strpos( $wz_html3, '>Ente<' ) && false !== strpos( $wz_html3, '>Gestione<' ) && false !== strpos( $wz_html3, '>Pagamenti<' ) && false !== strpos( $wz_html3, '>Aspetto<' ), 'procedura: indicatore di sezione (Ente, Gestione, Pagamenti, Aspetto) al posto del conteggio delle pagine' );
apse_ok( 2 === preg_match_all( '/<section class="apse-wiz-step"[^>]*data-group="ente"/', $wz_html3 ) && (int) preg_match_all( '/<section class="apse-wiz-step"[^>]*data-group="pagamenti"/', $wz_html3 ) >= 2 && 1 === preg_match_all( '/<section class="apse-wiz-step"[^>]*data-group="gestione"/', $wz_html3 ) && 1 === preg_match_all( '/<section class="apse-wiz-step"[^>]*data-group="aspetto"/', $wz_html3 ) && 1 === preg_match_all( '/<section class="apse-wiz-step"[^>]*data-group="optional"/', $wz_html3 ) && false === strpos( $wz_html3, 'Passo "' ), 'procedura: l\'ente occupa tre schermate di una sola sezione, l\'elenco dei soci è facoltativo e non si conta' );
apse_ok( 5 === (int) Settings::get( 'privacy_retention_years' ), 'anonimizzazione: di default 5 anni di inattività' );
// pagina privacy preselezionata solo alla prima configurazione; nome dell'ente mancante segnalato in Bacheca; partecipanti dei corsi chiusi di default
$pv_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Privacy di WordPress prova' ) );
update_option( 'wp_page_for_privacy_policy', $pv_page );
$pv_old = Settings::get( 'privacy_url' );
Settings::update( array( 'privacy_url' => '' ) );
$pv_status = get_option( \ApSemplice\Wizard::OPTION, '' );
update_option( \ApSemplice\Wizard::OPTION, \ApSemplice\Wizard::SKIPPED ); // sito già in uso
ob_start();
Admin\WizardPage::render();
$pv_used = (string) ob_get_clean();
delete_option( \ApSemplice\Wizard::OPTION ); // prima configurazione
ob_start();
Admin\WizardPage::render();
$pv_first = (string) ob_get_clean();
$pv_sel = function ( string $html, int $id ) {
	return 1 === preg_match( "/<option[^>]*value=\"" . $id . "\"[^>]*selected|<option[^>]*selected[^>]*value=\"" . $id . "\"|<option class=\"level-0\" value=\"" . $id . "\" selected/", $html ) || 1 === preg_match( '/value="' . $id . '"\s+selected/', $html );
};
apse_ok( ! $pv_sel( $pv_used, $pv_page ) && $pv_sel( $pv_first, $pv_page ), 'procedura: la pagina privacy di WordPress si propone solo alla prima configurazione, non su un sito già in uso' );
update_option( \ApSemplice\Wizard::OPTION, '' !== $pv_status ? $pv_status : \ApSemplice\Wizard::DONE );
delete_option( 'wp_page_for_privacy_policy' );
wp_delete_post( $pv_page, true );
Settings::update( array( 'privacy_url' => $pv_old ) );
$nm_old = Settings::get( 'association_name' );
Settings::update( array( 'association_name' => '' ) );
ob_start();
Admin\DashboardPage::render();
$nm_dash = (string) ob_get_clean();
Settings::update( array( 'association_name' => 'Associazione con nome' ) );
ob_start();
Admin\DashboardPage::render();
$nm_dash2 = (string) ob_get_clean();
Settings::update( array( 'association_name' => $nm_old ) );
apse_ok( false !== strpos( $nm_dash, 'Manca il nome dell' ) && false === strpos( $nm_dash2, 'Manca il nome dell' ), 'bacheca: segnala il nome dell\'ente mancante, e non più quando c\'è' );
// trovato sul sito vero: pagina disegnata due volte (stessa pagina registrata con due oggetti diversi) e messaggi con l'apostrofo che sparivano
$gd = function () {};
apse_ok( Admin\Admin::guard( 'apse-prova-guard', $gd ) === Admin\Admin::guard( 'apse-prova-guard', $gd ), 'menu: la stessa pagina ha sempre lo stesso oggetto (WordPress la disegna una volta sola)' );
$fl_url  = \ApSemplice\Flash::url( 'https://example.com/wp-admin/admin.php?page=apse-entity', 'apse', 'Dati dell\'ente salvati.' );
$fl_safe = wp_sanitize_redirect( $fl_url ); // il reindirizzamento di WordPress toglie i caratteri non ammessi
parse_str( (string) wp_parse_url( $fl_safe, PHP_URL_QUERY ), $fl_q );
$fl_get = $_GET;
$_GET   = $fl_q;
$fl_msg = \ApSemplice\Flash::read( 'apse' );
$_GET   = $fl_get;
apse_ok( 'Dati dell’ente salvati.' === $fl_msg['ok'], 'messaggi: con l\'apostrofo arrivano comunque (resta valida la firma dopo il reindirizzamento)' );
apse_ok( false !== strpos( Admin\TechActions::wizard_skip( array() )[1], 'Strumenti' ), 'procedura: «Salta per ora» dice che si riapre dagli Strumenti' );
Settings::update( array( 'association_name' => $nm_old ) );
// ospiti non accettati
\ApSemplice\Wizard::apply( array( 'guests_enabled' => '0' ) );
apse_ok( ! Settings::guests_enabled() && null !== apse_throws( function () use ( $people, $ord ) { $people->create( array( 'type' => 'guest', 'first_name' => 'Non', 'last_name' => 'Accettato', 'phone' => '348 7776655', 'host_person_id' => $ord ) ); } ), 'ospiti non accettati: non se ne registrano di nuovi' );
apse_ok( '' === \ApSemplice\Frontend\Views::section_guests( $people->get( $founder ) ) || $people->guests_of( $founder ), 'ospiti non accettati: la sezione «I miei ospiti» non compare' );
\ApSemplice\Wizard::apply( array( 'guests_enabled' => '1' ) );
$wz_g2 = $people->create( array( 'type' => 'guest', 'first_name' => 'Ora', 'last_name' => 'Accettato', 'phone' => '348 7776644', 'host_person_id' => $ord ) );
apse_ok( Settings::guests_enabled() && $wz_g2 > 0, 'ospiti accettati: si registrano' );
$people->delete( $wz_g2 ); // l'ospite di prova non resta: altri controlli eliminano il socio ospitante
// informativa privacy: scelta tra le pagine esistenti
$wz_pg = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Informativa privacy prova' ) );
$wz_draft = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Bozza' ) );
\ApSemplice\Wizard::apply( array( 'privacy_page_id' => (string) $wz_pg ) );
apse_ok( get_permalink( $wz_pg ) === Settings::get( 'privacy_url' ) && null !== apse_throws( function () use ( $wz_draft ) { \ApSemplice\Wizard::apply( array( 'privacy_page_id' => (string) $wz_draft ) ); } ) && null !== apse_throws( function () { \ApSemplice\Wizard::apply( array( 'privacy_page_id' => '999999' ) ); } ), 'procedura: l\'informativa privacy è una pagina pubblicata già presente (le bozze e le pagine inesistenti si rifiutano)' );
\ApSemplice\Wizard::apply( array( 'privacy_page_id' => '0' ) );
apse_ok( '' === Settings::get( 'privacy_url' ), 'procedura: nessuna pagina scelta, nessuna informativa da accettare' );
Settings::update( array( 'privacy_url' => 'https://esterno.example.com/privacy' ) );
\ApSemplice\Wizard::apply( array( 'privacy_page_id' => '0' ) );
apse_ok( 'https://esterno.example.com/privacy' === Settings::get( 'privacy_url' ), 'procedura: un indirizzo esterno già impostato non si cancella' );
Settings::update( array( 'privacy_url' => '' ) );
wp_delete_post( $wz_pg, true );
wp_delete_post( $wz_draft, true );
$wz_pos_pay = strpos( $wz_html3, 'Pagamenti dei soci' );
apse_ok( false !== $wz_pos_pay && false !== strpos( substr( $wz_html3, max( 0, $wz_pos_pay - 200 ), 260 ), 'mod[ledger]=1' ), 'procedura: i pagamenti si chiedono solo se c\'è la prima nota' );
Settings::update( array( 'fivepm_enabled' => 0, 'has_vat' => 0, 'vat_number' => '', 'vat_membership_rate' => '', 'vat_default_rate' => 22, 'vat_prices_mode' => 'incl', 'legal_address' => '', 'legal_zip' => '', 'legal_city' => '', 'legal_province' => '', 'pec' => '', 'association_name' => $wz_old['association_name'] ) );
// dati dell'ente e partita IVA
$en_base = array( 'association_name' => 'Ente Fiscale', 'entity_type' => $wz_ent, 'member_term' => $wz_mem, 'tax_code' => '12345678903', 'social_year_start_month' => '9', 'legal_address' => 'Via Roma 1', 'legal_zip' => '20100', 'legal_city' => 'Milano', 'legal_province' => 'MI', 'pec' => 'ente@pec.example.com', 'vat_default_rate' => '22', 'vat_prices_mode' => 'incl', 'fiscal_regime' => 'ordinario' );
apse_ok( ! \ApSemplice\Fiscal::vat_applies(), 'iva: senza partita IVA non si applica' );
apse_ok( null !== apse_throws( function () use ( $en_base ) { Admin\TechActions::save_entity( array_merge( $en_base, array( 'has_vat' => '1', 'vat_number' => '12345678901' ) ) ); } ) && null !== apse_throws( function () use ( $en_base ) { Admin\TechActions::save_entity( array_merge( $en_base, array( 'tax_code' => '12345678901' ) ) ); } ) && null !== apse_throws( function () use ( $en_base ) { Admin\TechActions::save_entity( array_merge( $en_base, array( 'pec' => 'non-email' ) ) ); } ), 'dati ente: partita IVA, codice fiscale e PEC non validi si rifiutano' );
Admin\TechActions::save_entity( array_merge( $en_base, array( 'has_vat' => '1', 'vat_number' => 'IT 12345678903', 'vat_default_rate' => '10', 'vat_prices_mode' => 'escl', 'sdi_code' => 'abcdefg' ) ) );
apse_ok( \ApSemplice\Fiscal::vat_applies() && '12345678903' === Settings::get( 'vat_number' ) && 10 === \ApSemplice\Fiscal::default_rate() && 'escl' === \ApSemplice\Fiscal::default_mode() && 'ABCDEFG' === Settings::get( 'sdi_code' ) && 'Milano' === Settings::get( 'legal_city' ), 'dati ente: partita IVA, aliquota e modalità salvate' );
Admin\TechActions::save_entity( array_merge( $en_base, array( 'has_vat' => '1', 'vat_number' => '12345678903', 'fiscal_regime' => 'forfettario' ) ) );
apse_ok( ! \ApSemplice\Fiscal::vat_applies(), 'iva: in regime forfettario non si applica' );
Admin\TechActions::save_entity( $en_base );
apse_ok( ! \ApSemplice\Fiscal::vat_applies() && '' === Settings::get( 'vat_number' ), 'dati ente: tolta la partita IVA, il numero si azzera' );
ob_start();
Admin\EntityPage::render();
$en_html = (string) ob_get_clean();
apse_ok( false !== strpos( $en_html, 'name="vat_number"' ) && false !== strpos( $en_html, 'Partita IVA' ) && false !== strpos( $en_html, 'name="legal_address"' ) && in_array( 'apse-entity', Admin\Admin::ADMIN_ONLY, true ) && isset( Admin\TechActions::ACTIONS['apse_save_entity'] ), 'dati ente: pagina riservata agli amministratori con sede e partita IVA' );
// IVA su incassi, quote, attività e spese
$vt_last = function () {
	return Db::db()->get_row( 'SELECT * FROM ' . Db::t( 'transactions' ) . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
};
$vt_m    = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Iva', 'last_name' => 'Provata' ) );
$vt_act  = $acts->create( array( 'name' => 'Corso con IVA', 'social_year' => $sy->label(), 'monthly_fee_cents' => 12200, 'vat_rate' => 22 ) );
$vt_free = $acts->create( array( 'name' => 'Corso fuori campo', 'social_year' => $sy->label(), 'monthly_fee_cents' => 5000 ) );
apse_ok( 22 === (int) $acts->get( $vt_act )['vat_rate'] && null === $acts->get( $vt_free )['vat_rate'], 'iva: l\'aliquota si memorizza sull\'attività (vuota = fuori campo)' );
$vt_exp = 0;
foreach ( $ledger->categories() as $vt_c ) {
	if ( 'general_cost' === $vt_c['kind'] && ! $vt_exp ) {
		$vt_exp = (int) $vt_c['id'];
	}
}
$vt_base = array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash' );
// senza partita IVA nessuna IVA viene registrata
$ledger->record_receipt( $vt_base + array( 'person_id' => $vt_m, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 12200, 'activity_id' => $vt_act, 'competence_month' => $month ) ) ) );
$vt_t = $vt_last();
apse_ok( null === $vt_t['vat_rate'] && 0 === (int) $vt_t['vat_cents'], 'iva: senza partita IVA l\'incasso non ha IVA' );
Settings::update( array( 'has_vat' => 1, 'vat_number' => '12345678903', 'fiscal_regime' => 'ordinario', 'vat_default_rate' => 22, 'vat_membership_rate' => '' ) );
$vt_m2 = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Iva', 'last_name' => 'Seconda' ) );
$ledger->record_receipt( $vt_base + array( 'person_id' => $vt_m2, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 12200, 'activity_id' => $vt_act, 'competence_month' => $month ) ) ) );
$vt_t = $vt_last();
apse_ok( 22 === (int) $vt_t['vat_rate'] && 2200 === (int) $vt_t['vat_cents'] && 12200 === (int) $vt_t['amount_cents'], 'iva: l\'incasso di un\'attività con aliquota contiene l\'IVA (22% su 122,00 = 22,00)' );
$vt_m3 = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Iva', 'last_name' => 'Terza' ) );
$ledger->record_receipt( $vt_base + array( 'person_id' => $vt_m3, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 5000, 'activity_id' => $vt_free, 'competence_month' => $month ) ) ) );
$vt_t = $vt_last();
apse_ok( null === $vt_t['vat_rate'] && 0 === (int) $vt_t['vat_cents'], 'iva: un\'attività fuori campo non ha IVA' );
$ledger->record_receipt( $vt_base + array( 'lines' => array( array( 'category_id' => $cat['donation'], 'amount_cents' => 10000, 'vat_rate' => 22, 'vat_mode' => 'escl' ) ) ) );
$vt_t = $vt_last();
apse_ok( 12200 === (int) $vt_t['amount_cents'] && 2200 === (int) $vt_t['vat_cents'] && 22 === (int) $vt_t['vat_rate'], 'iva: importo scritto IVA esclusa, si incassa con l\'IVA (100,00 + 22% = 122,00)' );
$ledger->record_receipt( $vt_base + array( 'lines' => array( array( 'category_id' => $cat['donation'], 'amount_cents' => 12200, 'vat_rate' => 10 ) ) ) );
$vt_t = $vt_last();
apse_ok( 12200 === (int) $vt_t['amount_cents'] && 1109 === (int) $vt_t['vat_cents'], 'iva: importo scritto IVA compresa, si scorpora (122,00 al 10% = 11,09 di IVA)' );
Settings::update( array( 'vat_membership_rate' => 10 ) );
$ledger->record_receipt( $vt_base + array( 'person_id' => $vt_m, 'lines' => array( array( 'category_id' => $cat['membership'], 'amount_cents' => 1100, 'social_year' => $sy->label() ) ) ) );
$vt_t = $vt_last();
apse_ok( 10 === (int) $vt_t['vat_rate'] && 100 === (int) $vt_t['vat_cents'], 'iva: la quota associativa segue l\'aliquota delle quote (10% su 11,00 = 1,00)' );
Settings::update( array( 'vat_membership_rate' => '' ) );
$ledger->record_expense( $vt_base + array( 'category_id' => $vt_exp, 'amount_cents' => 10000, 'vat_rate' => 22, 'vat_mode' => 'escl', 'description' => 'Spesa con IVA' ) );
$vt_t = $vt_last();
apse_ok( 12200 === (int) $vt_t['amount_cents'] && 2200 === (int) $vt_t['vat_cents'], 'iva: spesa con importo IVA esclusa, si paga con l\'IVA' );
$ledger->record_expense( $vt_base + array( 'category_id' => $vt_exp, 'amount_cents' => 5000 ) );
$vt_t = $vt_last();
apse_ok( null === $vt_t['vat_rate'] && 5000 === (int) $vt_t['amount_cents'], 'iva: spesa senza aliquota resta fuori campo' );
$vt_csv = Admin\Exports::ledger( '2000-01-01', '2100-12-31' )[1];
apse_ok( false !== strpos( $vt_csv, 'Aliquota IVA;Imponibile;IVA' ) && false !== strpos( $vt_csv, '22%;100,00;22,00' ), 'iva: l\'esportazione della prima nota ha aliquota, imponibile e IVA' );
$vt_rows = \ApSemplice\Receipts::rows( 'tx' . (int) $vt_last()['id'] );
$vt_bd = \ApSemplice\Receipts::vat_breakdown( array( array( 'vat_rate' => 22, 'vat_cents' => 2200, 'amount_cents' => 12200 ), array( 'vat_rate' => 22, 'vat_cents' => 220, 'amount_cents' => 1220 ), array( 'vat_rate' => null, 'vat_cents' => 0, 'amount_cents' => 500 ) ) );
apse_ok( array( 22 => array( 'net' => 10000 + 1000, 'vat' => 2420 ) ) === $vt_bd, 'iva: la ricevuta raggruppa imponibile e IVA per aliquota' );
$vt_row = Admin\Ui::vat_row( null, 'incl', 'Gli importi', true );
apse_ok( false !== strpos( $vt_row, 'name="vat_rate"' ) && false !== strpos( $vt_row, 'IVA esclusa' ) && false !== strpos( $vt_row, 'Automatica' ), 'iva: i moduli hanno aliquota e modo (IVA compresa o esclusa)' );
Settings::update( array( 'has_vat' => 0, 'vat_number' => '', 'vat_membership_rate' => '' ) );
apse_ok( '' === Admin\Ui::vat_row( null, 'incl' ), 'iva: senza partita IVA i moduli non mostrano nulla di fiscale' );
// soldi e contabilità separati, con la prima nota unica
ob_start();
Admin\MoneyPage::render();
$mn_html = (string) ob_get_clean();
ob_start();
Admin\AccountingPage::render();
$ac_html = (string) ob_get_clean();
apse_ok( false !== strpos( $mn_html, 'Disponibilità reale' ) && false !== strpos( $mn_html, 'Nuovo incasso' ) && false !== strpos( $mn_html, 'Ultimi movimenti' ) && false !== strpos( $mn_html, 'Anno sociale' ) && false !== strpos( $mn_html, 'page=apse-ledger' ), 'soldi: cassa, liquidità reale, anno sociale e ultimi movimenti, con la prima nota' );
apse_ok( false !== strpos( $ac_html, 'Anno solare' ) && false !== strpos( $ac_html, 'Adempimenti' ) && false !== strpos( $ac_html, 'page=apse-years' ) && false !== strpos( $ac_html, 'page=apse-ledger' ), 'contabilità: anno solare, anni, adempimenti e la stessa prima nota' );
$ac_tabs = Admin\Admin::tabs( 'apse-accounting' );
apse_ok( false !== strpos( $ac_tabs, 'Anni solari' ) && false !== strpos( $ac_tabs, 'Rendiconto' ) && false !== strpos( $ac_tabs, 'Adempimenti' ) && false === strpos( $ac_tabs, 'Nuovo incasso' ) && false !== strpos( Admin\Admin::tabs( 'apse-money' ), 'Nuovo incasso' ) && false !== strpos( Admin\Admin::tabs( 'apse-money' ), 'Prima nota' ) && false === strpos( Admin\Admin::tabs( 'apse-money' ), 'Anni solari' ), 'menu: Soldi ha incassi, spese, conti e prima nota; Contabilità anni, rendiconto e adempimenti' );
Settings::update( array( 'modules' => array( 'accounting' => 0 ) ) );
$ac_off = Admin\Admin::disabled_pages();
apse_ok( in_array( 'apse-accounting', $ac_off, true ) && in_array( 'apse-years', $ac_off, true ) && in_array( 'apse-fivepm', $ac_off, true ) && in_array( 'apse-reports', $ac_off, true ) && in_array( 'apse-statement', $ac_off, true ) && ! in_array( 'apse-ledger', $ac_off, true ) && ! in_array( 'apse-income', $ac_off, true ) && ! in_array( 'apse-accounts', $ac_off, true ), 'senza contabilità resta la prima nota con incassi, spese e conti: il resto non si vede' );
Settings::update( array( 'modules' => array( 'ledger' => 0 ) ) );
$ac_off2 = Admin\Admin::disabled_pages();
apse_ok( in_array( 'apse-money', $ac_off2, true ) && in_array( 'apse-ledger', $ac_off2, true ) && in_array( 'apse-accounting', $ac_off2, true ) && in_array( 'apse-accounts', $ac_off2, true ), 'senza prima nota non si vede né Soldi né la contabilità' );
Settings::update( array( 'modules' => array() ) );
// eventi: stesso anno sociale, partecipanti con WhatsApp, eliminazione di eventi e iscrizioni con restituzione o annullamento
$ev_sy  = $sy->label();
$ev_far = gmdate( 'Y-m-d', strtotime( $today . ' +3 years' ) );
$ev_n0  = count( $acts->for_year( $ev_sy ) );
apse_ok( null !== apse_throws( function () use ( $acts, $ev_sy, $ev_far ) { $acts->create( array( 'name' => 'Evento fuori anno', 'social_year' => $ev_sy, 'kind' => 'event', 'session' => array( 'session_date' => $ev_far ) ) ); } ) && count( $acts->for_year( $ev_sy ) ) === $ev_n0, 'anno sociale: un evento con la data fuori dall\'anno non si crea (niente attività a metà)' );
$ev_a = $acts->create( array( 'name' => 'Serata di prova', 'social_year' => $ev_sy, 'kind' => 'event', 'fee_cents' => 1000, 'session' => array( 'session_date' => $today, 'capacity' => 20 ) ) );
$ev_s = (int) $acts->sessions( $ev_a )[0]['id'];
apse_ok( $ev_s > 0 && null !== apse_throws( function () use ( $acts, $ev_s, $ev_far ) { $acts->update_session( $ev_s, array( 'session_date' => $ev_far ) ); } ), 'anno sociale: la data di un evento non si sposta fuori dall\'anno sociale' );
$ev_r = $acts->create( array( 'name' => 'Ricorrente di prova', 'social_year' => $ev_sy, 'kind' => 'recurring', 'fee_cents' => 500 ) );
apse_ok( null !== apse_throws( function () use ( $acts, $ev_r, $today, $ev_far ) { $acts->generate_weekly( $ev_r, $today, $ev_far ); } ) && null !== apse_throws( function () use ( $acts, $ev_r, $ev_far ) { $acts->add_session( $ev_r, array( 'session_date' => $ev_far ) ); } ), 'anno sociale: le date di un evento ricorrente restano nell\'anno sociale' );
apse_ok( null !== apse_throws( function () use ( $acts, $ev_sy, $ev_far ) { $acts->create( array( 'name' => 'Corso lungo', 'social_year' => $ev_sy, 'kind' => 'course', 'fee_cents' => 100, 'starts_on' => gmdate( 'Y-m-d' ), 'ends_on' => $ev_far ) ); } ), 'anno sociale: un corso inizia e finisce nello stesso anno sociale' );
// partecipanti attesi con WhatsApp
$ev_mk = function ( string $first, string $last, string $phone = '' ) use ( $people, $ev_a ) {
	$id = $people->create( array( 'type' => 'ordinary', 'first_name' => $first, 'last_name' => $last, 'email' => strtolower( $first . '.' . $last ) . '@example.com', 'phone' => $phone ) );
	$people->set_membership( $id, Settings::membership_year()->label(), true );
	return $id;
};
$ev_p1 = $ev_mk( 'Paola', 'Prenotata', '347 1112233' );
$ev_p2 = $ev_mk( 'Mario', 'Senzatel' );
$acts->book( $ev_s, $ev_p1 );
$acts->book( $ev_s, $ev_p2 );
$_GET['id'] = (string) $ev_a;
ob_start();
Admin\ActivitiesPage::render_detail();
$ev_html = (string) ob_get_clean();
apse_ok( false !== strpos( $ev_html, 'Partecipanti attesi' ) && false !== strpos( $ev_html, '<details' ) && false !== strpos( $ev_html, 'wa.me/' ) && false !== strpos( $ev_html, 'Paola Prenotata' ) && false !== strpos( $ev_html, 'nessun cellulare' ), 'evento: elenco a scomparsa dei partecipanti con il collegamento WhatsApp solo per chi ha lasciato il numero' );
apse_ok( false !== strpos( $ev_html, 'page=apse-activity-delete' ) && false !== strpos( $ev_html, 'apse_delete_booking' ), 'evento: eliminazione dell\'evento e cancellazione delle iscrizioni per gli amministratori' );
// incassi
$ev_bal = function () use ( $ledger, $cash ) {
	foreach ( $ledger->balances() as $b ) {
		if ( $b['name'] === $cash['name'] ) {
			return (int) $b['balance'];
		}
	}
	return 0;
};
$ev_b0 = $ev_bal();
$ev_pay = function ( int $person, int $cents ) use ( $ledger, $vt_base, $cat, $ev_a, $ev_s ) {
	$ledger->record_receipt( $vt_base + array( 'person_id' => $person, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => $cents, 'activity_id' => $ev_a, 'session_id' => $ev_s ) ) ) );
};
$ev_pay( $ev_p1, 1000 );
$ev_pay( $ev_p2, 1500 );
apse_ok( $ev_bal() === $ev_b0 + 2500, 'evento: incassi registrati' );
// cancellazione di una singola iscrizione: restituzione
$pre = \ApSemplice\ActivityReset::registration_preview( $ev_a, $ev_p1, $ev_s );
apse_ok( $pre && 1 === $pre['income']['count'] && 1000 === $pre['income']['cents'] && array() === \ApSemplice\ActivityReset::registration_blockers( $ev_a, $ev_p1, $ev_s, 'refund' ), 'iscrizione: anteprima con gli incassi collegati' );
$ev_del_b = new ReflectionMethod( Admin\Actions::class, 'delete_booking' );
$ev_del_b->setAccessible( true );
apse_ok( null !== apse_throws( function () use ( $ev_del_b, $ev_a, $ev_p1, $ev_s ) { $ev_del_b->invoke( null, array( 'activity' => $ev_a, 'person' => $ev_p1, 'session' => $ev_s, 'mode' => 'refund' ) ); } ) && null !== apse_throws( function () use ( $ev_del_b, $ev_a, $ev_p1, $ev_s ) { $ev_del_b->invoke( null, array( 'activity' => $ev_a, 'person' => $ev_p1, 'session' => $ev_s, 'mode' => 'refund', 'confirm' => '1', 'typed' => 'Altro Nome' ) ); } ), 'iscrizione: senza la doppia conferma (spunta e nome scritto) non si cancella nulla' );
apse_ok( $acts->has_active_booking( $ev_s, $ev_p1 ), 'iscrizione: dopo i rifiuti la prenotazione c\'è ancora' );
$ev_del_b->invoke( null, array( 'activity' => $ev_a, 'person' => $ev_p1, 'session' => $ev_s, 'mode' => 'refund', 'confirm' => '1', 'typed' => 'paola prenotata' ) );
$ev_t = $vt_last();
apse_ok( ! $acts->has_active_booking( $ev_s, $ev_p1 ) && $ev_bal() === $ev_b0 + 1500 && 'expense' === $ev_t['type'] && 1000 === (int) $ev_t['amount_cents'] && false !== strpos( $ev_t['description'], 'Restituzione' ), 'iscrizione: con la restituzione resta l\'incasso e si registra l\'uscita (saldo netto a zero)' );
// cancellazione di una singola iscrizione: annullamento degli incassi
$ev_del_b->invoke( null, array( 'activity' => $ev_a, 'person' => $ev_p2, 'session' => $ev_s, 'mode' => 'void', 'confirm' => '1', 'typed' => 'Mario Senzatel' ) );
apse_ok( ! $acts->has_active_booking( $ev_s, $ev_p2 ) && $ev_bal() === $ev_b0, 'iscrizione: con l\'annullamento l\'incasso sparisce dal saldo' );
apse_ok( null !== $acts->get( $ev_a ), 'iscrizione: l\'evento resta' );
// eliminazione completa dell'evento
$ev_p3 = $ev_mk( 'Tina', 'Tre' );
$ev_p4 = $ev_mk( 'Ugo', 'Quattro' );
$acts->book( $ev_s, $ev_p3 );
$acts->book( $ev_s, $ev_p4 );
$ev_pay( $ev_p3, 800 );
$ev_pay( $ev_p4, 700 );
$acts->add_staff( $ev_a, $ev_p4, false );
$ledger->record_expense( $vt_base + array( 'category_id' => $vt_exp, 'amount_cents' => 300, 'activity_id' => $ev_a, 'description' => 'Noleggio locale' ) );
$ev_pre = \ApSemplice\ActivityReset::preview( $ev_a );
apse_ok( 3 === $ev_pre['income']['count'] && 2500 === $ev_pre['income']['cents'] && 2 === $ev_pre['income_open']['count'] && 1500 === $ev_pre['income_open']['cents'] && 1 === $ev_pre['expense']['count'] && 1 === $ev_pre['refund_exp']['count'] && 2 === $ev_pre['bookings'] && 1 === $ev_pre['staff'], 'evento: anteprima di ciò che si elimina e dei soldi collegati (l\'incasso già restituito con una singola iscrizione non si conta due volte)' );
$ev_del = new ReflectionMethod( Admin\Actions::class, 'delete_activity' );
$ev_del->setAccessible( true );
apse_ok( null !== apse_throws( function () use ( $ev_del, $ev_a ) { $ev_del->invoke( null, array( 'id' => $ev_a, 'mode' => 'refund', 'confirm' => '1', 'typed' => 'Un altro evento' ) ); } ) && null !== apse_throws( function () use ( $ev_del, $ev_a ) { $ev_del->invoke( null, array( 'id' => $ev_a, 'mode' => 'refund', 'typed' => 'Serata di prova' ) ); } ) && null !== apse_throws( function () use ( $ev_del, $ev_a ) { $ev_del->invoke( null, array( 'id' => $ev_a, 'mode' => 'boh', 'confirm' => '1', 'typed' => 'Serata di prova' ) ); } ) && null !== $acts->get( $ev_a ), 'evento: senza conferma, con il nome sbagliato o senza la scelta sui soldi non si elimina' );
$ev_b1 = $ev_bal();
$ev_res = $ev_del->invoke( null, array( 'id' => $ev_a, 'mode' => 'refund', 'confirm' => '1', 'typed' => ' serata di PROVA ', 'notify' => '1' ) );
$ev_left = Db::db()->get_results( 'SELECT * FROM ' . Db::t( 'transactions' ) . " WHERE description LIKE '[Evento eliminato: Serata di prova]%' ORDER BY id", ARRAY_A );
apse_ok( null === $acts->get( $ev_a ) && 0 === count( $acts->sessions( $ev_a ) ) && 0 === (int) Db::db()->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . ' WHERE session_id = ' . (int) $ev_s ) && 0 === (int) Db::db()->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'activity_staff' ) . ' WHERE activity_id = ' . (int) $ev_a ), 'evento eliminato: date, prenotazioni e staff cancellati' );
apse_ok( $ev_bal() === $ev_b1 - 1500 && 8 === count( $ev_left ) && 3 === count( array_filter( $ev_left, function ( $r ) { return 'expense' === $r['type'] && false !== strpos( $r['description'], 'Restituzione' ); } ) ) && ! array_filter( $ev_left, function ( $r ) { return null !== $r['activity_id']; } ), 'evento eliminato con restituzione: incassi e restituzioni restano in prima nota, senza più il collegamento all\'evento (la spesa dell\'affitto resta)' );
apse_ok( false !== strpos( implode( ' ', array_column( Audit::recent( 80 ), 'action' ) ), 'activity.deleted' ) && false !== strpos( $ev_res[1], 'restituzioni' ) && false !== strpos( $ev_res[1], 'il denaro va restituito', 0 ), 'evento eliminato: registrato nel registro azioni e messaggio sulle restituzioni' );
$vt_catrows = Db::db()->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'categories' ) . " WHERE name = 'Restituzione quote'" );
apse_ok( 1 === (int) $vt_catrows, 'evento eliminato: la voce «Restituzione quote» si crea una sola volta' );
// eliminazione con annullamento degli incassi (e delle spese)
$ev_a2 = $acts->create( array( 'name' => 'Seconda serata', 'social_year' => $ev_sy, 'kind' => 'event', 'fee_cents' => 1000, 'session' => array( 'session_date' => $today ) ) );
$ev_s2 = (int) $acts->sessions( $ev_a2 )[0]['id'];
$ev_p5 = $ev_mk( 'Vera', 'Cinque' );
$acts->book( $ev_s2, $ev_p5 );
$ledger->record_receipt( $vt_base + array( 'person_id' => $ev_p5, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 900, 'activity_id' => $ev_a2, 'session_id' => $ev_s2 ) ) ) );
$ledger->record_expense( $vt_base + array( 'category_id' => $vt_exp, 'amount_cents' => 200, 'activity_id' => $ev_a2, 'description' => 'Materiale' ) );
$ev_p7 = $ev_mk( 'Rita', 'Sette' );
$acts->book( $ev_s2, $ev_p7 );
$ledger->record_receipt( $vt_base + array( 'person_id' => $ev_p7, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 400, 'activity_id' => $ev_a2, 'session_id' => $ev_s2 ) ) ) );
\ApSemplice\ActivityReset::delete_registration( $ev_a2, $ev_p7, $ev_s2, 'refund' ); // una iscrizione già cancellata con restituzione
$ev_b2 = $ev_bal();
$ev_pre2 = \ApSemplice\ActivityReset::preview( $ev_a2 );
apse_ok( 2 === $ev_pre2['income']['count'] && 1 === $ev_pre2['income_open']['count'] && 900 === $ev_pre2['income_open']['cents'] && 1 === $ev_pre2['refund_exp']['count'], 'evento: le restituzioni già fatte per singole iscrizioni si riconoscono' );
$ev_res2 = \ApSemplice\ActivityReset::delete( $ev_a2, 'void', array( 'void_costs' => true ) );
apse_ok( null === $acts->get( $ev_a2 ) && 2 === $ev_res2['voided'] && 1 === $ev_res2['expenses_voided'] && $ev_bal() === $ev_b2 - 900 + 200, 'evento eliminato con annullamento: incassi, restituzioni compensative e spesa annullati, il saldo torna come prima' );
$ev_open = (int) Db::db()->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . " WHERE description LIKE '[Evento eliminato: Seconda serata]%' AND voided_at IS NULL" );
apse_ok( 0 === $ev_open, 'evento eliminato con annullamento: nessun movimento attivo resta in prima nota' );
// anno chiuso: non si procede
$ev_a3 = $acts->create( array( 'name' => 'Terza serata', 'social_year' => $ev_sy, 'kind' => 'event', 'fee_cents' => 100, 'session' => array( 'session_date' => $today ) ) );
if ( ! \ApSemplice\FiscalYears::get( 2019 ) ) {
	$ev_made2019 = true;
	\ApSemplice\FiscalYears::create( 2019 );
}
Db::db()->update( Db::t( 'fiscal_years' ), array( 'status' => 'closed' ), array( 'year' => 2019 ) );
Db::db()->insert( Db::t( 'transactions' ), array( 'tx_date' => '2019-05-05', 'type' => 'income', 'amount_cents' => 500, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'category_id' => $cat['activity_fee'], 'activity_id' => $ev_a3, 'description' => 'Storico', 'created_at' => Db::now() ) );
$ev_tx3 = (int) Db::db()->insert_id;
$ev_bl = \ApSemplice\ActivityReset::blockers( $ev_a3, 'void' );
apse_ok( $ev_bl && false !== strpos( $ev_bl[0], '2019' ) && null !== apse_throws( function () use ( $ev_a3 ) { \ApSemplice\ActivityReset::delete( $ev_a3, 'void' ); } ) && null !== $acts->get( $ev_a3 ), 'evento: con movimenti in un anno solare chiuso non si elimina (e non cambia nulla)' );
Db::db()->delete( Db::t( 'transactions' ), array( 'id' => $ev_tx3 ) );
\ApSemplice\ActivityReset::delete( $ev_a3, 'refund' );
apse_ok( null === $acts->get( $ev_a3 ), 'evento senza incassi: si elimina senza toccare la prima nota' );
if ( ! empty( $ev_made2019 ) ) {
	Db::db()->delete( Db::t( 'fiscal_years' ), array( 'year' => 2019 ) );
} else {
	Db::db()->update( Db::t( 'fiscal_years' ), array( 'status' => 'open' ), array( 'year' => 2019 ) );
}
// pagine a due passaggi
$ev_a4 = $acts->create( array( 'name' => 'Quarta serata', 'social_year' => $ev_sy, 'kind' => 'event', 'fee_cents' => 100, 'session' => array( 'session_date' => $today ) ) );
$ev_s4 = (int) $acts->sessions( $ev_a4 )[0]['id'];
$ev_p6 = $ev_mk( 'Gino', 'Sei' );
$acts->book( $ev_s4, $ev_p6 );
$ledger->record_receipt( $vt_base + array( 'person_id' => $ev_p6, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 600, 'activity_id' => $ev_a4, 'session_id' => $ev_s4 ) ) ) );
$_GET = array( 'id' => (string) $ev_a4 );
ob_start();
Admin\DeleteActivityPage::render();
$dp1 = (string) ob_get_clean();
$_GET = array( 'id' => (string) $ev_a4, 'step' => '2', 'mode' => 'void' );
ob_start();
Admin\DeleteActivityPage::render();
$dp2 = (string) ob_get_clean();
$_GET = array( 'id' => (string) $ev_a4, 'step' => '2', 'mode' => 'refund' );
ob_start();
Admin\DeleteActivityPage::render();
$dp3 = (string) ob_get_clean();
apse_ok( false !== strpos( $dp1, 'Restituisci le somme' ) && false !== strpos( $dp1, 'Annulla gli incassi' ) && false === strpos( $dp1, 'Elimina definitivamente' ), 'pagina di eliminazione: primo passaggio, scelta sulle somme e nessun pulsante finale' );
apse_ok( false !== strpos( $dp2, 'ANNULLATI' ) && false !== strpos( $dp2, 'spariscono da saldi, report, rendiconto' ) && false !== strpos( $dp2, 'Elimina definitivamente' ) && false !== strpos( $dp2, 'name="typed"' ) && false !== strpos( $dp2, 'name="confirm"' ), 'pagina di eliminazione: secondo passaggio con il messaggio chiaro sulla prima nota e la doppia conferma' );
apse_ok( false !== strpos( $dp3, 'restano in prima nota' ) && false !== strpos( $dp3, 'Restituzione quote' ) && false !== strpos( $dp3, 'non viene restituito dal sistema' ), 'pagina di eliminazione: con la restituzione dice che il denaro va restituito a mano' );
$_GET = array( 'activity' => (string) $ev_a4, 'person' => (string) $ev_p6, 'session' => (string) $ev_s4, 'step' => '2', 'mode' => 'void' );
ob_start();
Admin\DeleteBookingPage::render();
$dp4 = (string) ob_get_clean();
apse_ok( false !== strpos( $dp4, 'ANNULLATI' ) && false !== strpos( $dp4, 'Cancella l\'iscrizione' ) && false !== strpos( $dp4, 'Gino Sei' ), 'pagina di cancellazione iscrizione: messaggio chiaro e conferma con nome' );
$_GET = array();
apse_ok( 'apse-activities' === Admin\Admin::menu_item_of( 'apse-activity-delete' ) && 'apse-activities' === Admin\Admin::menu_item_of( 'apse-booking-delete' ) && in_array( 'apse_delete_activity', Admin\Actions::ADMIN_ONLY, true ) && in_array( 'apse_delete_booking', Admin\Actions::ADMIN_ONLY, true ) && in_array( 'apse-activity-delete', Admin\Admin::ADMIN_ONLY, true ), 'eliminazioni: riservate agli amministratori' );
$ev_p8 = $ev_mk( 'Lia', 'Otto', '349 5556677' );
$acts->book( $ev_s4, $ev_p8 );
ob_start();
Admin\ActivitiesPage::render_list();
$ev_list = (string) ob_get_clean();
apse_ok( false !== strpos( $ev_list, 'Quarta serata' ) && false !== strpos( $ev_list, '<details' ) && false !== strpos( $ev_list, 'Lia Otto' ) && false !== strpos( $ev_list, 'wa.me/' ) && false === strpos( $ev_list, 'page=apse-booking-delete' ), 'elenco attività: partecipanti attesi a scomparsa in ogni evento, con WhatsApp (la cancellazione resta nella scheda)' );
\ApSemplice\ActivityReset::delete( $ev_a4, 'void' );
\ApSemplice\ActivityReset::delete( $ev_r, 'refund' );
// cancellazioni che non toccano la prima nota: una sola conferma
$fr_a = $acts->create( array( 'name' => 'Evento senza soldi', 'social_year' => $ev_sy, 'kind' => 'event', 'fee_cents' => 0, 'session' => array( 'session_date' => $today ) ) );
$fr_s = (int) $acts->sessions( $fr_a )[0]['id'];
$fr_p = $ev_mk( 'Franco', 'Gratis', '348 9998877' );
$fr_q = $ev_mk( 'Giada', 'Gratis' );
$acts->book( $fr_s, $fr_p );
$acts->book( $fr_s, $fr_q );
$fr_tx = (int) Db::db()->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) );
apse_ok( \ApSemplice\ActivityReset::registration_is_free( $fr_a, $fr_p, $fr_s ) && \ApSemplice\ActivityReset::activity_is_free( $fr_a ), 'senza incassi: iscrizione ed evento risultano «liberi» (nessun effetto sulla prima nota)' );
$_GET = array( 'activity' => (string) $fr_a, 'person' => (string) $fr_p, 'session' => (string) $fr_s );
ob_start();
Admin\DeleteBookingPage::render();
$fr_pg = (string) ob_get_clean();
$_GET = array( 'id' => (string) $fr_a );
ob_start();
Admin\DeleteActivityPage::render();
$fr_pe = (string) ob_get_clean();
$_GET = array( 'id' => (string) $fr_a );
ob_start();
Admin\ActivitiesPage::render_detail();
$fr_det = (string) ob_get_clean();
$_GET = array();
apse_ok( false === strpos( $fr_pg, 'name="typed"' ) && false !== strpos( $fr_pg, 'Cancella l\'iscrizione' ) && false !== strpos( $fr_pg, 'la prima nota non cambia' ) && false === strpos( $fr_pe, 'name="typed"' ) && false !== strpos( $fr_pe, 'Elimina l\'evento' ), 'senza incassi: le pagine di cancellazione hanno un solo pulsante, niente nome da scrivere' );
apse_ok( false !== strpos( $fr_det, 'onsubmit="return confirm(' ) && false !== strpos( $fr_det, 'apse_delete_booking' ), 'senza incassi: dalla lista la cancellazione è un pulsante con una sola conferma' );
$fr_del_b = new ReflectionMethod( Admin\Actions::class, 'delete_booking' );
$fr_del_b->setAccessible( true );
$fr_del_b->invoke( null, array( 'activity' => $fr_a, 'person' => $fr_p, 'session' => $fr_s ) );
apse_ok( ! $acts->has_active_booking( $fr_s, $fr_p ) && $acts->has_active_booking( $fr_s, $fr_q ) && (int) Db::db()->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) ) === $fr_tx, 'senza incassi: l\'iscrizione si cancella senza conferma scritta e la prima nota non cambia' );
$fr_del_a = new ReflectionMethod( Admin\Actions::class, 'delete_activity' );
$fr_del_a->setAccessible( true );
$fr_del_a->invoke( null, array( 'id' => $fr_a ) );
apse_ok( null === $acts->get( $fr_a ) && (int) Db::db()->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) ) === $fr_tx, 'senza movimenti: l\'evento si elimina con una sola conferma e la prima nota non cambia' );
$fr_b = $acts->create( array( 'name' => 'Evento con soldi', 'social_year' => $ev_sy, 'kind' => 'event', 'fee_cents' => 500, 'session' => array( 'session_date' => $today ) ) );
$fr_bs = (int) $acts->sessions( $fr_b )[0]['id'];
$fr_bp = $ev_mk( 'Hugo', 'Pagante' );
$acts->book( $fr_bs, $fr_bp );
$ledger->record_receipt( $vt_base + array( 'person_id' => $fr_bp, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 500, 'activity_id' => $fr_b, 'session_id' => $fr_bs ) ) ) );
apse_ok( ! \ApSemplice\ActivityReset::registration_is_free( $fr_b, $fr_bp, $fr_bs ) && ! \ApSemplice\ActivityReset::activity_is_free( $fr_b ) && null !== apse_throws( function () use ( $fr_del_b, $fr_b, $fr_bp, $fr_bs ) { $fr_del_b->invoke( null, array( 'activity' => $fr_b, 'person' => $fr_bp, 'session' => $fr_bs, 'mode' => 'refund' ) ); } ) && null !== apse_throws( function () use ( $fr_del_a, $fr_b ) { $fr_del_a->invoke( null, array( 'id' => $fr_b, 'mode' => 'refund' ) ); } ), 'con incassi: serve ancora la doppia conferma, per l\'iscrizione e per l\'evento' );
\ApSemplice\ActivityReset::delete( $fr_b, 'void' );
// corso: iscritti non confermati da togliere dall'elenco, pagamento diretto dalla lista
$pg_c  = $acts->create( array( 'name' => 'Corso pulizia elenco', 'social_year' => $ev_sy, 'kind' => 'course', 'monthly_fee_cents' => 8000 ) );
$pg_a  = $ev_mk( 'Anna', 'Ritirata' );
$pg_b  = $ev_mk( 'Bruno', 'Pagante' );
$pg_k  = $ev_mk( 'Carla', 'Attiva', '340 1234567' );
foreach ( array( $pg_a, $pg_b ) as $pg_p ) { // iscrizioni già finite (non confermate)
	Db::db()->insert( Db::t( 'enrollments' ), array( 'activity_id' => $pg_c, 'person_id' => $pg_p, 'start_month' => '2000-01', 'end_month' => '2000-02', 'created_at' => Db::now() ) );
}
$acts->enroll( $pg_c, $pg_k, $sy->clamp( substr( $today, 0, 7 ) ) );
$ledger->record_receipt( $vt_base + array( 'person_id' => $pg_b, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 8000, 'activity_id' => $pg_c, 'competence_month' => '2000-01' ) ) ) );
$pg_ended = \ApSemplice\ActivityReset::ended_enrollments( $pg_c );
apse_ok( 2 === count( $pg_ended ) && in_array( $pg_a, $pg_ended, true ) && in_array( $pg_b, $pg_ended, true ) && ! in_array( $pg_k, $pg_ended, true ), 'corso: si riconoscono gli iscritti con l\'iscrizione già finita' );
$pg_purge = new ReflectionMethod( Admin\Actions::class, 'purge_enrollments' );
$pg_purge->setAccessible( true );
apse_ok( null !== apse_throws( function () use ( $pg_purge, $pg_c ) { $pg_purge->invoke( null, array( 'activity_id' => $pg_c ) ); } ) && 2 === count( \ApSemplice\ActivityReset::ended_enrollments( $pg_c ) ), 'corso: senza la conferma non si toglie nessuno' );
$pg_res = $pg_purge->invoke( null, array( 'activity_id' => $pg_c, 'confirm' => '1' ) );
$pg_left = \ApSemplice\ActivityReset::ended_enrollments( $pg_c );
apse_ok( array( $pg_b ) === $pg_left && false !== strpos( $pg_res[1], 'Bruno Pagante' ) && false !== strpos( $pg_res[1], 'la prima nota non è cambiata' ), 'corso: si tolgono gli iscritti senza incassi, resta chi ha incassi (con il nome nel messaggio) e la prima nota non cambia' );
apse_ok( 1 === (int) Db::db()->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = ' . (int) $pg_c . ' AND person_id = ' . (int) $pg_k ), 'corso: l\'iscritto attivo resta' );
$_GET = array( 'id' => (string) $pg_c );
ob_start();
Admin\ActivitiesPage::render_detail();
$pg_html = (string) ob_get_clean();
apse_ok( false !== strpos( $pg_html, 'apse_purge_enrollments' ) && false !== strpos( $pg_html, 'Da versare' ) && 1 === preg_match( '/page=apse-income[^"]*person_id=' . (int) $pg_k . '[^"]*due=1|page=apse-income[^"]*due=1[^"]*person_id=' . (int) $pg_k . '/', html_entity_decode( $pg_html ) ) && false !== strpos( $pg_html, 'page=apse-booking-delete' ), 'corso: dalla lista si va al pagamento diretto («Paga») e si cancella ogni iscritto' );
$_GET = array();
apse_ok( false !== strpos( $pg_html, 'Partecipanti attesi' ) && false === strpos( $pg_html, '<details open' ), 'corso: l\'elenco dei partecipanti attesi è a scomparsa e chiuso di default' );
$pg_del_b = new ReflectionMethod( Admin\Actions::class, 'delete_booking' );
$pg_del_b->setAccessible( true );
$pg_del_b->invoke( null, array( 'activity' => $pg_c, 'person' => $pg_b, 'session' => 0, 'mode' => 'refund', 'confirm' => '1', 'typed' => 'Bruno Pagante' ) );
apse_ok( array() === \ApSemplice\ActivityReset::ended_enrollments( $pg_c ), 'corso: chi ha incassi si cancella dalla lista scegliendo cosa fare delle somme' );
\ApSemplice\ActivityReset::delete( $pg_c, 'refund' );
// strumenti
foreach ( array( 'apse-import', 'apse-wpai', 'apse-exports', 'apse-calendar', 'apse-backup', 'apse-tech', 'apse-tools' ) as $tl_p ) {
	apse_ok( 'apse-tools' === Admin\Admin::menu_item_of( $tl_p ), 'strumenti: ' . $tl_p . ' sta negli Strumenti' );
}
ob_start();
Admin\ToolsPage::render();
$tl_html = (string) ob_get_clean();
ob_start();
Admin\ToolsPage::render_exports();
$tl_exp = (string) ob_get_clean();
apse_ok( false !== strpos( $tl_html, 'Importa da Excel o CSV' ) && false !== strpos( $tl_html, 'Copia di sicurezza' ) && false !== strpos( $tl_html, 'Calendario di corsi ed eventi' ) && false !== strpos( $tl_html, 'Esportazioni CSV' ), 'strumenti: la panoramica elenca importazioni, esportazioni, calendari, copie e integrazioni' );
apse_ok( false !== strpos( $tl_exp, 'Soci e ospiti' ) && false !== strpos( $tl_exp, 'Prima nota' ) && false !== strpos( $tl_exp, 'apse_export' ), 'strumenti: le esportazioni hanno il loro elenco' );
Settings::update( array( 'modules' => array( 'ledger' => 0, 'activities' => 0, 'import' => 0 ) ) );
ob_start();
Admin\ToolsPage::render_exports();
$tl_exp2 = (string) ob_get_clean();
ob_start();
Admin\ToolsPage::render();
$tl_html2 = (string) ob_get_clean();
apse_ok( false === strpos( $tl_exp2, 'Prima nota' ) && false !== strpos( $tl_exp2, 'Soci e ospiti' ) && false === strpos( $tl_html2, 'Importa da Excel o CSV' ) && false === strpos( $tl_html2, 'Calendario di corsi ed eventi' ), 'strumenti: senza prima nota, corsi e importazioni quelle voci non compaiono' );
Settings::update( array( 'modules' => array() ) );
Settings::update( array( 'vat_default_rate' => 22, 'vat_prices_mode' => 'incl', 'sdi_code' => '', 'legal_address' => '', 'legal_zip' => '', 'legal_city' => '', 'legal_province' => '', 'pec' => '', 'fiscal_regime' => 'ordinario' ) );
Settings::update( $wz_old );

// Elementor (installato nel test): i widget si registrano e i controlli si costruiscono
apse_ok( class_exists( '\Elementor\Plugin' ), 'Elementor è presente nell\'ambiente di test' );
$el_widgets = \Elementor\Plugin::instance()->widgets_manager->get_widget_types();
apse_ok( isset( $el_widgets['apsemplice_view'] ) && isset( $el_widgets['apsemplice_reserved'] ), 'Elementor: widget APSemplice registrati' );
apse_ok( array_key_exists( 'view', $el_widgets['apsemplice_view']->get_controls() ) && array_key_exists( 'rule', $el_widgets['apsemplice_reserved']->get_controls() ), 'Elementor: i controlli dei widget si costruiscono' );
apse_ok( false !== strpos( (string) file_get_contents( APSE_DIR . 'includes/Frontend/Elementor/ViewWidget.php' ), 'Areas::options()' ) && false !== strpos( (string) file_get_contents( APSE_DIR . 'includes/Frontend/Blocks.php' ), 'Areas::options()' ), 'blocco e widget: propongono le viste per area' );
wp_set_current_user( 1 );

// ---------- Cancellazioni, cambio di nominativo, pagamenti online (configurazione) ----------
wp_set_current_user( 1 );
$mkev = function ( string $name, int $fee, ?int $guest_fee, array $extra = array(), int $days = 10 ) use ( $acts, $sy_label, $today ) {
	return $acts->create(
		array_merge(
			array(
				'name' => $name, 'social_year' => $sy_label, 'kind' => 'event', 'fee_cents' => $fee, 'guest_fee_cents' => $guest_fee,
				'session' => array( 'session_date' => gmdate( 'Y-m-d', strtotime( $today . " +$days days" ) ), 'capacity' => 6 ),
			),
			$extra
		)
	);
};
$first_session = function ( int $activity ) use ( $acts ) {
	return (int) $acts->sessions( $activity )[0]['id'];
};
wp_set_current_user( $u_f );
$cancels = function ( int $session, int $person ) use ( $front ) {
	return apse_throws( function () use ( $front, $session, $person ) { $front::do_cancel_booking( array( 'session_id' => $session, 'person_id' => $person ) ); } );
};
wp_set_current_user( 1 );

// Evento gratuito: si annulla sempre
$free_ev = $mkev( 'Aperitivo gratuito', 0, null );
$free_s  = $first_session( $free_ev );
wp_set_current_user( $u_f );
$front::do_book( array( 'session_id' => $free_s, 'person_id' => $founder ) );
apse_ok( true === $acts->cancellation_for( $free_s, $founder )['allowed'] && null === $cancels( $free_s, $founder ), 'evento gratuito: si può sempre annullare' );
apse_ok( ! $acts->has_active_booking( $free_s, $founder ), 'evento gratuito: prenotazione annullata' );

// Evento a pagamento non cancellabile: niente annullo, ma cambio di nominativo
wp_set_current_user( 1 );
$paid_ev = $mkev( 'Cena sociale', 500, 800 );
$paid_s  = $first_session( $paid_ev );
wp_set_current_user( $u_f );
$front::do_book( array( 'session_id' => $paid_s, 'person_id' => $founder ) );
$ev = $acts->cancellation_for( $paid_s, $founder );
apse_ok( ! $ev['allowed'] && 'not_cancellable' === $ev['reason'] && $ev['can_transfer'], 'evento a pagamento: non cancellabile ma il nominativo si può cambiare' );
apse_ok( false !== strpos( (string) $cancels( $paid_s, $founder ), 'non è cancellabile' ), 'evento a pagamento: l\'annullo dal sito è rifiutato' );
wp_set_current_user( 1 );
$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $founder,
	'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 500, 'activity_id' => $paid_ev, 'session_id' => $paid_s ) ) ) );
wp_set_current_user( $u_f );

// il socio cambia il nominativo a favore di un proprio ospite (contributo ospiti 8,00): integra 3,00
$front::do_add_guest( array( 'first_name' => 'Nico', 'last_name' => 'Ospite', 'phone' => '333 3333333' ) );
$nico = 0;
foreach ( $people->guests_of( $founder ) as $g ) {
	if ( 'Nico' === $g['first_name'] ) {
		$nico = (int) $g['id'];
	}
}
$msg = $front::do_transfer_booking( array( 'session_id' => $paid_s, 'person_id' => $founder, 'to_person_id' => $nico ) );
$bk  = $by_person( $paid_s );
apse_ok( false !== strpos( $msg, 'Da integrare' ) && false !== strpos( $msg, '3,00' ), 'cambio nominativo a un ospite: da integrare 3,00' );
apse_ok( 800 === (int) $bk[ $nico ]['fee_due_cents'] && 500 === $bk[ $nico ]['paid'] && 300 === $bk[ $nico ]['remaining'] && 'partial' === $bk[ $nico ]['state'], 'il pagamento già fatto passa all\'ospite' );
apse_ok( ! $bk[ $founder ]['active'] && 'transferred' === $bk[ $founder ]['status'] && 0 === $bk[ $founder ]['paid'], 'la prenotazione del socio risulta trasferita' );
apse_ok( 1 === (int) $acts->sessions( $paid_ev )[0]['booked_count'], 'i posti occupati non cambiano' );
apse_ok( 500 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(amount_cents) FROM ' . Db::t( 'transactions' ) . ' WHERE session_id = %d AND person_id = %d AND voided_at IS NULL', $paid_s, $nico ) ), 'il pagamento è intestato al nuovo partecipante' );
apse_ok( false !== strpos( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT description FROM ' . Db::t( 'transactions' ) . ' WHERE session_id = %d AND person_id = %d LIMIT 1', $paid_s, $nico ) ), 'intestato da' ), 'nota di trasferimento nella descrizione del pagamento' );

// ...e poi a un nuovo ospite indicato per nome (resta lo stesso contributo, niente da integrare)
$msg = $front::do_transfer_booking( array( 'session_id' => $paid_s, 'person_id' => $nico, 'new_first_name' => 'Nuovo', 'new_last_name' => 'Amico', 'new_phone' => '333 9999991' ) );
apse_ok( false !== strpos( $msg, 'Da integrare' ) && false !== strpos( $msg, '3,00' ), 'cambio verso un nuovo ospite indicato per nome: resta da integrare 3,00' );
$amico = 0;
foreach ( $people->guests_of( $founder ) as $g ) {
	if ( 'Amico' === $g['last_name'] ) {
		$amico = (int) $g['id'];
	}
}
apse_ok( $amico > 0 && $acts->has_active_booking( $paid_s, $amico ) && ! $acts->has_active_booking( $paid_s, $nico ), 'il nuovo ospite creato dal cambio nominativo è prenotato' );
apse_ok( null !== apse_throws( function () use ( $front, $paid_s, $amico, $guest ) { $front::do_transfer_booking( array( 'session_id' => $paid_s, 'person_id' => $amico, 'to_person_id' => $guest ) ); } ), 'non si intesta a un ospite di un altro socio' );
apse_ok( null !== apse_throws( function () use ( $front, $paid_s, $amico ) { $front::do_transfer_booking( array( 'session_id' => $paid_s, 'person_id' => $amico ) ); } ), 'cambio nominativo senza destinatario rifiutato' );

// non si cambia nominativo dopo l'inizio dell'evento
wp_set_current_user( 1 );
$past_ev_id = $acts->create( array( 'name' => 'Evento passato', 'social_year' => $sy_label, 'kind' => 'recurring', 'fee_cents' => 500 ) );
$past_s     = $acts->add_session( $past_ev_id, array( 'session_date' => gmdate( 'Y-m-d', strtotime( $today . ' -2 days' ) ) ) );
$acts->book( $past_s, $founder );
wp_set_current_user( $u_f );
apse_ok( false !== stripos( (string) apse_throws( function () use ( $front, $past_s, $founder, $amico ) { $front::do_transfer_booking( array( 'session_id' => $past_s, 'person_id' => $founder, 'to_person_id' => $amico ) ); } ), 'iniziato' ), 'dopo l\'inizio il nominativo non si cambia più' );

// l'amministratore può cambiare nominativo a chiunque (anche a evento iniziato)
wp_set_current_user( 1 );
$acts->transfer_booking( $past_s, $founder, $vol, false );
apse_ok( $acts->has_active_booking( $past_s, $vol ) && ! $acts->has_active_booking( $past_s, $founder ), 'amministratore: cambio nominativo senza vincoli di tempo' );
apse_ok( null !== apse_throws( function () use ( $acts, $past_s, $vol ) { $acts->transfer_booking( $past_s, $vol, $vol, false ); } ), 'non si cambia verso la stessa persona' );

// Evento a pagamento cancellabile: termini 7 giorni / 24 ore / predefinito
$can7   = $mkev( 'Gita (7 giorni)', 500, null, array( 'cancellable' => 1, 'cancel_policy' => '7d' ), 10 );
$can7_s = $first_session( $can7 );
$late7  = $mkev( 'Gita tardiva (7 giorni)', 500, null, array( 'cancellable' => 1, 'cancel_policy' => '7d' ), 3 );
$late_s = $first_session( $late7 );
$can24  = $mkev( 'Concerto (24 ore)', 500, null, array( 'cancellable' => 1, 'cancel_policy' => '24h' ), 3 );
$c24_s  = $first_session( $can24 );
$candef = $mkev( 'Spettacolo (predefinito)', 500, null, array( 'cancellable' => 1 ), 3 );
$cdef_s = $first_session( $candef );
foreach ( array( $can7_s, $late_s, $c24_s, $cdef_s ) as $sx ) {
	$acts->book( $sx, $founder );
}
apse_ok( 1 === (int) $acts->get( $can7 )['cancellable'] && '7d' === $acts->get( $can7 )['cancel_policy'], 'evento cancellabile: salvato con il suo termine' );
apse_ok( $acts->cancellation_for( $can7_s, $founder )['allowed'], 'cancellabile (7 giorni), evento tra 10 giorni: si annulla' );
apse_ok( ! $acts->cancellation_for( $late_s, $founder )['allowed'] && 'deadline_passed' === $acts->cancellation_for( $late_s, $founder )['reason'], 'cancellabile (7 giorni), evento tra 3 giorni: termine scaduto' );
apse_ok( $acts->cancellation_for( $c24_s, $founder )['allowed'], 'cancellabile (24 ore), evento tra 3 giorni: si annulla' );
apse_ok( $acts->cancellation_for( $cdef_s, $founder )['allowed'], 'termine predefinito (48 ore), evento tra 3 giorni: si annulla' );
Settings::update( array( 'cancel_policy_default' => '7d' ) );
apse_ok( ! $acts->cancellation_for( $cdef_s, $founder )['allowed'], 'predefinito portato a una settimana: ora il termine è scaduto' );
Settings::update( array( 'cancel_policy_default' => 'boh' ) );
apse_ok( '48h' === Settings::get( 'cancel_policy_default' ), 'termine predefinito non valido: torna a 48 ore' );
wp_set_current_user( $u_f );
apse_ok( null === $cancels( $can7_s, $founder ) && ! $acts->has_active_booking( $can7_s, $founder ), 'cancellabile: l\'annullo dal sito funziona nei termini' );
apse_ok( false !== strpos( (string) $cancels( $late_s, $founder ), 'scaduto' ), 'cancellabile: dopo il termine l\'annullo è rifiutato con il motivo' );
wp_set_current_user( 1 );
$acts->cancel_booking( $late_s, $founder );
apse_ok( ! $acts->has_active_booking( $late_s, $founder ), 'amministratore: può sempre annullare' );
$html = $as( $u_f, '[apsemplice_area_soci]' );
apse_ok( false !== strpos( $html, 'Cambia nominativo' ) && false !== strpos( $html, 'apse_front_transfer_booking' ), 'area soci: pulsante Cambia nominativo' );
apse_ok( false !== strpos( $html, 'non è cancellabile' ) || false !== strpos( $html, 'Puoi annullare fino al' ), 'area soci: spiega se e fino a quando si può annullare' );

// Pagina attività: creazione con cancellabilità e pagina scheda
apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Cancellabile anche se a pagamento', array( 'id' => $can7 ) );
apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Cambia nominativo', array( 'id' => $paid_ev ) );
apse_render( array( Admin\ActivitiesPage::class, 'render_list' ), 'Cancellabile anche se a pagamento' );
$pc = new ReflectionMethod( Admin\Actions::class, 'save_activity' );
$pc->setAccessible( true );
$res = $pc->invoke( null, array( 'name' => 'Da modulo', 'social_year' => $sy_label, 'kind' => 'event', 'fee' => '4,00', 'guest_fee' => '6,00', 'cancellable' => '1', 'cancel_policy' => '24h', 'session_date' => gmdate( 'Y-m-d', strtotime( $today . ' +20 days' ) ), 'start_time' => '18:30', 'capacity' => '3' ) );
preg_match( '/id=(\d+)/', $res[0], $mm );
$form_ev = $acts->get( (int) $mm[1] );
apse_ok( 400 === (int) $form_ev['fee_cents'] && 600 === (int) $form_ev['guest_fee_cents'] && 1 === (int) $form_ev['cancellable'] && '24h' === $form_ev['cancel_policy'], 'modulo attività: evento cancellabile con termine e contributo ospiti' );
$pc->invoke( null, array( 'id' => $form_ev['id'], 'name' => 'Da modulo', 'social_year' => $sy_label, 'fee' => '4,00', 'guest_fee' => '', 'cancel_policy' => '' ) );
$form_ev = $acts->get( (int) $form_ev['id'] );
apse_ok( 0 === (int) $form_ev['cancellable'] && null === $form_ev['cancel_policy'] && null === $form_ev['guest_fee_cents'], 'modulo attività: togliere la spunta e il contributo ospiti salva davvero' );

// Impostazioni dei pagamenti: Stripe / PayPal in alternativa a WooCommerce, chiavi cifrate
Settings::update( array( 'payment_provider' => 'stripe', 'stripe_mode' => 'test', 'stripe_publishable_key' => 'pk_test_51Abc1234', 'stripe_secret_key' => 'sk_test_51Abc1234', 'stripe_webhook_secret' => 'whsec_abc1234' ) );
$raw = get_option( 'apse_settings' );
apse_ok( Secrets::is_encrypted( $raw['stripe_secret_key'] ) && false === strpos( wp_json_encode( $raw ), 'sk_test_51Abc1234' ) && false === strpos( wp_json_encode( $raw ), 'whsec_abc1234' ), 'chiavi segrete: nel database sono cifrate' );
apse_ok( 'sk_test_51Abc1234' === Settings::secret( 'stripe_secret_key' ) && Settings::has_secret( 'stripe_webhook_secret' ), 'chiavi segrete: si leggono in chiaro solo dal codice' );
Settings::update( array( 'stripe_secret_key' => '' ) );
apse_ok( 'sk_test_51Abc1234' === Settings::secret( 'stripe_secret_key' ), 'chiave segreta lasciata vuota nel modulo: resta quella salvata' );
Settings::update( array( 'stripe_secret_key' => 'sk_test_NUOVA9876' ) );
apse_ok( 'sk_test_NUOVA9876' === Settings::secret( 'stripe_secret_key' ), 'chiave segreta nuova: sostituisce la vecchia' );
Settings::update( array( 'stripe_secret_key' => 'sk_test_51Abc1234' ) );
apse_ok( array() === PaymentConfig::validate( Settings::payment_config() )['errors'], 'configurazione Stripe valida' );
$http_seen = null;
$fake      = function ( $method, $url, $headers, $body ) use ( &$http_seen ) {
	$http_seen = array( $method, $url, $headers );
	return array( 'code' => 200, 'body' => '{"livemode":false}' );
};
$g = Gateways::test( 'stripe', Settings::payment_config(), $fake );
apse_ok( $g['ok'] && 'Bearer sk_test_51Abc1234' === $http_seen[2]['Authorization'], 'prova Stripe: usa la chiave salvata (rete simulata)' );
ob_start();
Admin\PaymentsPage::render();
$settings_html = ob_get_clean();
ob_start();
Admin\SettingsPage::render();
$general_html = ob_get_clean();
apse_ok( false === strpos( $settings_html, 'sk_test_51Abc1234' ) && false !== strpos( $settings_html, '••••1234' ) && false === strpos( $settings_html, 'whsec_abc1234' ), 'impostazioni: la chiave segreta non viene mai stampata, solo la maschera' );
apse_ok( false !== strpos( $settings_html, 'Verifica connessione Stripe' ) && false !== strpos( $settings_html, 'WooCommerce (negozio del sito)' ) && false !== strpos( $general_html, 'Termine predefinito per annullare' ) && false === strpos( $general_html, 'Verifica connessione Stripe' ), 'impostazioni: i pagamenti stanno nella loro scheda, le cancellazioni nel generale' );
Settings::update( array( 'payment_provider' => 'paypal', 'paypal_mode' => 'sandbox', 'paypal_client_id' => str_repeat( 'A', 40 ), 'paypal_client_secret' => str_repeat( 'b', 40 ) ) );
apse_ok( array() === PaymentConfig::validate( Settings::payment_config() )['errors'], 'configurazione PayPal valida' );
Settings::update( array( 'payment_provider' => 'bogus' ) );
apse_ok( 'none' === Settings::get( 'payment_provider' ), 'gateway non valido: si torna a "nessuno"' );
Settings::clear_secret( 'stripe_secret_key' );
apse_ok( ! Settings::has_secret( 'stripe_secret_key' ) && '' === Settings::secret( 'stripe_secret_key' ), 'chiave segreta rimossa' );
apse_ok( in_array( 'settings.secret_cleared', array_column( Audit::recent( 50 ), 'action' ), true ) && false === strpos( wp_json_encode( Audit::recent( 200 ) ), 'sk_test' ), 'registro azioni: nessuna chiave dentro' );
Settings::update( array( 'payment_provider' => 'none' ) );
wp_set_current_user( 1 );

// ---------- Tutto dal pannello: aspetto, messaggi, chiavi (nessun file da modificare) ----------
wp_set_current_user( 1 );
Settings::update( array( 'accent_color' => '#C0392B' ) );
apse_ok( '#c0392b' === Settings::get( 'accent_color' ), 'colore d\'accento: salvato normalizzato' );
$css = \ApSemplice\Frontend\Assets::inline_css();
apse_ok( false !== strpos( $css, '--apsf-accent:#c0392b' ) && false !== strpos( $css, '--apsf-accent-text:#ffffff' ), 'colore d\'accento: diventa lo stile del front-end, con testo leggibile' );
Settings::update( array( 'accent_color' => 'rosso' ) );
apse_ok( '' === Settings::get( 'accent_color' ) && \ApSemplice\Frontend\Assets::accent( '#123456' ) === ( '' !== \ApSemplice\Frontend\Assets::theme_accent() ? \ApSemplice\Frontend\Assets::theme_accent() : '#123456' ), 'colore non valido: si torna al colore del sito (Elementor o tema)' );

Settings::update( array( 'payment_hint' => 'Paga con bonifico a IT00X.' ) );
apse_ok( false !== strpos( $as( $u_ord, '[apsemplice_area_soci]' ), 'Paga con bonifico a IT00X.' ), 'invito al pagamento: testo scelto nelle impostazioni' );
Settings::update( array( 'payment_hint' => '   ' ) );
apse_ok( Settings::DEFAULT_PAYMENT_HINT === Settings::payment_hint(), 'invito al pagamento vuoto: torna quello predefinito' );

Settings::update( array( 'gate_message' => 'Area dedicata ai nostri soci.' ) );
apse_ok( false !== strpos( $show( $p_mem, 0 ), 'Area dedicata ai nostri soci.' ), 'messaggio sui contenuti riservati: testo scelto nelle impostazioni' );
Settings::update( array( 'gate_message' => '' ) );
apse_ok( false !== strpos( $show( $p_mem, 0 ), 'Contenuto riservato ai soci' ), 'messaggio sui contenuti riservati vuoto: automatico' );

License::set_state( 'unpaid', $today, 'https://licenze.example/paga?k=1' );
apse_ok( false !== strpos( Admin\LicenseNotice::html(), 'https://licenze.example/paga?k=1' ), 'popup licenza: usa l\'indirizzo di pagamento comunicato dal servizio' );
delete_option( License::OPT_STATE );
apse_ok( false === strpos( Admin\LicenseNotice::html(), 'licenze.example' ), 'popup licenza: niente popup con la licenza in regola' );

// il modulo delle impostazioni salva tutto
$ss = new ReflectionMethod( Admin\Actions::class, 'save_settings' );
$ss->setAccessible( true );
$form = array(
	'association_name' => 'APS Prova', 'social_year_start_month' => '9', 'membership_fee' => '12,00', 'founder_years' => '99',
	'member_area_page_id' => (string) Settings::get( 'member_area_page_id' ), 'cancel_policy_default' => '24h',
	'payment_hint' => 'Ciao', 'gate_message' => '',
	'accent_custom' => '1', 'accent_color' => '#336699', // non più nel modulo: l'aspetto ha la sua scheda
);
$ps = new ReflectionMethod( Admin\Actions::class, 'save_payment_settings' );
$ps->setAccessible( true );
$pform = array( 'payment_provider' => 'stripe', 'stripe_mode' => 'test', 'stripe_publishable_key' => 'pk_test_51Zzz', 'stripe_secret_key' => 'sk_test_51Zzz', 'stripe_webhook_secret' => 'whsec_zzz' );
$ss->invoke( null, $form );
$ps->invoke( null, $pform );
apse_ok( 'Ciao' === Settings::payment_hint() && '24h' === Settings::get( 'cancel_policy_default' ) && 1200 === (int) Settings::get( 'membership_fee_cents' ) && 'sk_test_51Zzz' === Settings::secret( 'stripe_secret_key' ), 'modulo impostazioni: salva messaggi, termini e chiavi' );
$form2 = $form;
unset( $form2['accent_custom'] );
$pform2 = array_merge( $pform, array( 'stripe_secret_key' => '', 'stripe_webhook_secret' => '' ) );
$ss->invoke( null, $form2 );
$ps->invoke( null, $pform2 );
apse_ok( 'sk_test_51Zzz' === Settings::secret( 'stripe_secret_key' ), 'chiave lasciata vuota: resta quella salvata' );
$ps->invoke( null, array_merge( $pform2, array( 'clear_stripe_secret_key' => '1' ) ) );
apse_ok( ! Settings::has_secret( 'stripe_secret_key' ) && Settings::has_secret( 'stripe_webhook_secret' ), 'modulo: la spunta "rimuovi" toglie solo quella chiave' );

// una chiave cifrata da un altro sito (database copiato) non si legge qui, e il pannello lo dice
$opt                         = get_option( 'apse_settings' );
$opt['stripe_secret_key']    = Secrets::encrypt( 'sk_test_ALTRO', 'sale-di-un-altro-sito' );
update_option( 'apse_settings', $opt );
apse_ok( Settings::has_secret( 'stripe_secret_key' ) && Settings::secret_unreadable( 'stripe_secret_key' ) && '' === Settings::secret( 'stripe_secret_key' ), 'chiave cifrata da un altro sito: segnalata come non leggibile' );
ob_start();
Admin\PaymentsPage::render();
$h = ob_get_clean();
ob_start();
Admin\SettingsPage::render();
$h_general = ob_get_clean();
apse_ok( false !== strpos( $h, 'non è leggibile su questo sito' ) && false === strpos( $h, 'wp-config' ) && false === strpos( $h, 'sk_test_ALTRO' ), 'pannello: avviso sulla chiave illeggibile, nessun file da modificare' );
apse_ok( false !== strpos( $h_general, 'Aspetto e messaggi del sito' ) && false !== strpos( $h_general, 'page=apse-look' ), 'pannello: sezione aspetto con il collegamento alla scheda Aspetto' );
Settings::clear_secret( 'stripe_secret_key' );
Settings::clear_secret( 'stripe_webhook_secret' );
Settings::update( array( 'payment_provider' => 'none', 'accent_color' => '', 'cancel_policy_default' => '48h' ) );
wp_set_current_user( 1 );
$csv = Admin\Exports::ledger( $year . '-01-01', $year . '-12-31' )[1];
apse_ok( false !== strpos( $csv, 'N. tessera' ) && false !== strpos( $csv, 'Rimborso' ), 'export prima nota' );
apse_ok( false !== strpos( Admin\Exports::people()[1], 'fulvia@example.com' ), 'export soci' );
apse_ok( "'=HYPERLINK(\"x\")" === Admin\Exports::neutralize( '=HYPERLINK("x")' ) && "'+1+1" === Admin\Exports::neutralize( '+1+1' ) && "'-2+3" === Admin\Exports::neutralize( '-2+3' ) && "'@SUM(A1)" === Admin\Exports::neutralize( '@SUM(A1)' ) && "'\t=1" === Admin\Exports::neutralize( "\t=1" ), 'export CSV: le formule nelle celle di testo vengono neutralizzate' );
apse_ok( '-12,50' === Admin\Exports::neutralize( '-12,50' ) && '1.234,56' === Admin\Exports::neutralize( '1.234,56' ) && '+39 336 1112233' === Admin\Exports::neutralize( '+39 336 1112233' ) && 'Mario Rossi' === Admin\Exports::neutralize( 'Mario Rossi' ) && '' === Admin\Exports::neutralize( '' ), 'export CSV: importi, telefoni e testi normali restano com\'erano' );

// ---------- Import CSV: piano ----------
$existing = array();
foreach ( $people->search() as $e ) {
	$existing[] = array( 'id' => (int) $e['id'], 'card' => $e['card_number'], 'first' => $e['first_name'], 'last' => $e['last_name'], 'email' => $e['email'], 'tax' => $e['tax_code'] );
}
$parsed = PeopleCsv::parse( "Tessera;Tipo;Nome;Cognome;Email\n3;ordinario;Omar;Ordini;omar@example.com\n50;volontario;Nuova;Persona;nuova@example.com\n3;ordinario;Dup;Licato;dup@example.com\n" );
$plan   = PeopleCsv::plan( $parsed['rows'], $existing );
apse_ok( 'update' === $plan[0]['action'] && 'create' === $plan[1]['action'] && 'error' === $plan[2]['action'], 'import: aggiorna, crea, scarta duplicati' );

// ---------- Eliminazione ----------
$people->delete( $guest );
$people->delete( $ord );
$again = $people->create( array( 'type' => 'ordinary', 'card_number' => '3', 'first_name' => 'Nuovo', 'last_name' => 'Titolare', 'email' => 'omar@example.com' ) );
apse_ok( $again > 0, 'eliminando un socio si libera tessera ed email' );

// ---------- Pagamenti online: pagina ospitata da Stripe / PayPal (gateway simulati) ----------
wp_set_current_user( 1 );
$ps = Plugin::payments();
Settings::update( array( 'payment_provider' => 'none', 'membership_fee_cents' => 1000 ) );
apse_ok( '' === $ps->provider() && ! $ps->enabled(), 'pagamenti online: spenti finché non si sceglie un gateway' );
apse_ok( isset( Labels::methods()['stripe'] ) && isset( Labels::methods()['paypal'] ), 'metodi di pagamento Stripe e PayPal disponibili' );
apse_ok( false !== wp_next_scheduled( 'apse_check_pending_payments' ), 'controllo periodico dei pagamenti in sospeso pianificato' );

$mails = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	10,
	2
);

// gateway simulati
$stripe_sessions = array();
$paypal_orders   = array();
$fake_gateway    = function ( $method, $url, $headers, $body ) use ( &$stripe_sessions, &$paypal_orders ) {
	if ( false !== strpos( $url, 'api.stripe.com/v1/checkout/sessions' ) ) {
		if ( 'POST' === $method ) {
			parse_str( (string) $body, $b );
			$id    = 'cs_test_' . ( count( $stripe_sessions ) + 1 );
			$total = 0;
			foreach ( $b['line_items'] as $li ) {
				$total += (int) $li['price_data']['unit_amount'];
			}
			$stripe_sessions[ $id ] = array( 'paid' => false, 'amount' => $total, 'ref' => $b['client_reference_id'], 'status' => 'open', 'key' => $headers['Idempotency-Key'] ?? '' );
			return array( 'code' => 200, 'body' => wp_json_encode( array( 'id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/' . $id ) ) );
		}
		$id = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$s  = $stripe_sessions[ $id ];
		return array( 'code' => 200, 'body' => wp_json_encode( array( 'id' => $id, 'payment_status' => $s['paid'] ? 'paid' : 'unpaid', 'status' => $s['status'], 'amount_total' => $s['amount'], 'payment_intent' => 'pi_' . $id, 'client_reference_id' => $s['ref'] ) ) );
	}
	if ( false !== strpos( $url, '/v1/oauth2/token' ) ) {
		return array( 'code' => 200, 'body' => '{"access_token":"TOK"}' );
	}
	if ( false !== strpos( $url, '/v2/checkout/orders' ) ) {
		if ( 'POST' === $method && '/orders' === substr( $url, -7 ) ) {
			$p     = json_decode( (string) $body, true );
			$id    = 'ORD' . ( count( $paypal_orders ) + 1 );
			$cents = (int) round( (float) $p['purchase_units'][0]['amount']['value'] * 100 );
			$paypal_orders[ $id ] = array( 'status' => 'CREATED', 'amount' => $cents );
			return array( 'code' => 201, 'body' => wp_json_encode( array( 'id' => $id, 'status' => 'CREATED', 'links' => array( array( 'rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=' . $id ) ) ) ) );
		}
		$id = preg_match( '#/orders/([A-Z0-9]+)#', $url, $m ) ? $m[1] : '';
		if ( '/capture' === substr( $url, -8 ) ) {
			if ( 'APPROVED' !== $paypal_orders[ $id ]['status'] ) {
				return array( 'code' => 422, 'body' => '{"details":[{"issue":"ORDER_NOT_APPROVED"}]}' );
			}
			$paypal_orders[ $id ]['status'] = 'COMPLETED';
		}
		$out = array( 'id' => $id, 'status' => $paypal_orders[ $id ]['status'] );
		if ( 'COMPLETED' === $out['status'] ) {
			$out['purchase_units'] = array( array( 'payments' => array( 'captures' => array( array( 'id' => 'CAP' . $id, 'status' => 'COMPLETED', 'amount' => array( 'currency_code' => 'EUR', 'value' => \ApSemplice\PaymentItems::decimal( $paypal_orders[ $id ]['amount'] ) ) ) ) ) ) );
		}
		return array( 'code' => 200, 'body' => wp_json_encode( $out ) );
	}
	return array( 'code' => 404, 'body' => '{}' );
};
$ps->set_http( $fake_gateway );
$sum_tx = function ( string $where ) use ( $wpdb ) {
	return (int) $wpdb->get_var( 'SELECT COALESCE(SUM(amount_cents),0) FROM ' . Db::t( 'transactions' ) . " WHERE voided_at IS NULL AND $where" );
};
$balance_of = function ( string $name ) use ( $ledger ) {
	foreach ( $ledger->balances() as $b ) {
		if ( $name === $b['name'] ) {
			return (int) $b['balance'];
		}
	}
	return 0;
};
$latest = function () use ( $ps ) {
	return $ps->list( array(), 1 )[0];
};

Settings::update( array( 'payment_provider' => 'stripe', 'stripe_mode' => 'test', 'stripe_publishable_key' => 'pk_test_51Smoke1', 'stripe_secret_key' => 'sk_test_51Smoke1', 'stripe_webhook_secret' => 'whsec_smoke123', 'limits' => array( 'pay_open_per_hour' => 100 ) ) );
apse_ok( 'stripe' === $ps->provider() && $ps->enabled(), 'pagamenti online: Stripe attivo con la configurazione completa' );

// dati dedicati: un socio senza tessera valida con un ospite, un corso e un evento
$pm   = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Pia', 'last_name' => 'Pagante', 'email' => 'pia.pagante@example.com' ) );
$pmp  = $people->get( $pm );
$u_pm = (int) $pmp['wp_user_id'];
$pg   = $people->create( array( 'type' => 'guest', 'first_name' => 'Gigi', 'last_name' => 'Pagante', 'phone' => '333 4444444', 'host_person_id' => $pm ) );
$pcourse = $acts->create( array( 'name' => 'Ceramica', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 2000, 'guest_fee_cents' => 1500 ) );
$acts->enroll( $pcourse, $pm, $month );
$acts->enroll( $pcourse, $pg, $month );
$pev   = $mkev( 'Cena online', 500, 800 );
$pev_s = $first_session( $pev );
$acts->book( $pev_s, $pm );
$acts->book( $pev_s, $pg );
$k_q   = "q:$pm:" . Settings::membership_year()->label();
$k_mon = "m:$pcourse:$pm:$month";
$k_ev  = "b:$pev_s:$pm";
$k_gm  = "m:$pcourse:$pg:$month";
$k_gev = "b:$pev_s:$pg";
$dues  = $ps->dues_for( $pmp );
apse_ok( isset( $dues[ $k_q ] ) && 1000 === $dues[ $k_q ]['amount_cents'], 'da pagare: quota associativa del socio senza tessera valida' );
apse_ok( isset( $dues[ $k_mon ] ) && 2000 === $dues[ $k_mon ]['amount_cents'] && isset( $dues[ $k_ev ] ) && 500 === $dues[ $k_ev ]['amount_cents'], 'da pagare: mensilità del corso e contributo dell\'evento, con gli importi del server' );
apse_ok( isset( $dues[ $k_gm ] ) && 1500 === $dues[ $k_gm ]['amount_cents'] && isset( $dues[ $k_gev ] ) && 800 === $dues[ $k_gev ]['amount_cents'], 'da pagare: anche le voci dell\'ospite, con i suoi importi' );
apse_ok( ! isset( $ps->dues_for( $people->get( $pg ) )[ $k_q ] ) && ! isset( $dues[ "b:$pev_s:$vol" ] ), 'da pagare: l\'ospite non paga la quota e non ci sono voci altrui' );
apse_ok( array() === array_filter( array_keys( $ps->dues_for( $people->get( $founder ) ) ), function ( $k ) { return 0 === strpos( $k, 'q:' ); } ), 'da pagare: il fondatore non paga la quota' );

// creazione: il server ricalcola, ignora chiavi estranee o inventate
wp_set_current_user( $u_pm );
apse_ok( null !== apse_throws( function () use ( $ps, $pmp, $u_pm, $pev_s, $ord ) { $ps->create_checkout( $pmp, $u_pm, array( 'm:999:1:2026-01', "b:$pev_s:$ord", 'q:1:2026/2027' ), home_url( '/area/' ) ); } ), 'checkout: chiavi inventate o altrui rifiutate' );
$url = $ps->create_checkout( $pmp, $u_pm, array( $k_mon, $k_ev, 'm:999:1:2026-01', "b:$pev_s:$ord" ), home_url( '/area/' ) );
$row = $latest();
apse_ok( 0 === strpos( $url, 'https://checkout.stripe.com/c/pay/cs_test_' ) && 'pending' === $row['status'] && 2500 === (int) $row['amount_cents'] && 'stripe' === $row['provider'], 'checkout Stripe: pagamento in attesa, importo 25,00 deciso dal server' );
apse_ok( 2500 === $stripe_sessions[ $row['provider_ref'] ]['amount'] && $stripe_sessions[ $row['provider_ref'] ]['key'] === $row['public_id'], 'Stripe riceve l\'importo del server e una chiave di idempotenza' );
License::set_state( 'unpaid', $today );
apse_ok( ! $ps->enabled() && null !== apse_throws( function () use ( $ps, $pmp, $u_pm, $k_ev ) { $ps->create_checkout( $pmp, $u_pm, array( $k_ev ), home_url( '/area/' ) ); } ), 'licenza non in regola: niente nuovi pagamenti online' );
delete_option( License::OPT_STATE );

// ritorno: annullato, poi in attesa, poi pagato (verifica diretta col gateway)
apse_ok( false !== strpos( $ps->handle_return( $row['public_id'], 'cancel', $u_pm ), 'annullato' ) && 'cancelled' === $ps->get_by_public( $row['public_id'] )['status'], 'ritorno "annulla": nessun addebito, pagamento annullato' );
$ps->create_checkout( $pmp, $u_pm, array( $k_mon, $k_ev ), home_url( '/area/' ) );
$row = $latest();
apse_ok( null !== apse_throws( function () use ( $ps, $row, $u_f ) { $ps->handle_return( $row['public_id'], 'ok', $u_f ); } ), 'ritorno: solo chi ha avviato il pagamento può verificarlo' );
apse_ok( false !== strpos( $ps->handle_return( $row['public_id'], 'ok', $u_pm ), 'attesa' ) && 'pending' === $ps->get_by_public( $row['public_id'] )['status'], 'ritorno: se il gateway non conferma resta in attesa (nessun incasso)' );
$stripe_sessions[ $row['provider_ref'] ]['paid'] = true;
apse_ok( false !== strpos( $ps->handle_return( $row['public_id'], 'ok', $u_pm ), 'ricevuto' ), 'ritorno: pagamento confermato dal gateway' );
$paid_row = $ps->get_by_public( $row['public_id'] );
apse_ok( 'paid' === $paid_row['status'] && 2500 === (int) $paid_row['allocated_cents'] && ! (int) $paid_row['review'], 'pagamento registrato per intero, nessun controllo da fare' );
apse_ok( 2500 === $balance_of( 'Stripe' ), 'prima nota: 25,00 sul conto "Stripe" (creato in automatico)' );
apse_ok( ! isset( $ps->dues_for( $people->get( $pm ) )[ $k_mon ] ) && ! isset( $ps->dues_for( $people->get( $pm ) )[ $k_ev ] ) && 'paid' === $by_person( $pev_s )[ $pm ]['state'], 'la mensilità e il contributo dell\'evento risultano pagati' );
apse_ok( 2500 === $sum_tx( "method = 'stripe' AND document_ref = '" . esc_sql( $row['provider_ref'] ) . "'" ), 'i movimenti riportano metodo Stripe e il riferimento del gateway' );
$ps->handle_return( $row['public_id'], 'ok', $u_pm );
$ps->check_pending( true );
apse_ok( 2500 === $balance_of( 'Stripe' ), 'pagamento già registrato: ritorni e controlli ripetuti non lo duplicano' );
apse_ok( ! empty( $mails ) && false !== strpos( wp_json_encode( $mails[0] ), 'pia.pagante@example.com' ) && false !== strpos( (string) $mails[0]['message'], '25,00' ), 'ricevuta via email al socio che ha pagato' );

// webhook di Stripe: firma, ripetizioni, importo diverso
$mk_event = function ( array $row, int $amount, string $type = 'checkout.session.completed' ) {
	return array( 'id' => 'evt_' . wp_generate_password( 6, false ), 'type' => $type, 'data' => array( 'object' => array( 'id' => $row['provider_ref'], 'client_reference_id' => $row['public_id'], 'payment_status' => 'paid', 'amount_total' => $amount, 'payment_intent' => 'pi_wh' ) ) );
};
$webhook = function ( array $ev, ?string $secret = null, ?int $ts = null ) {
	wp_set_current_user( 0 );
	$payload = wp_json_encode( $ev );
	$req     = new WP_REST_Request( 'POST', '/apsemplice/v1/webhooks/stripe' );
	$req->set_body( $payload );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_header( 'Stripe-Signature', \ApSemplice\StripeWebhook::header( $payload, $secret ?? 'whsec_smoke123', $ts ?? time() ) );
	return rest_do_request( $req );
};
wp_set_current_user( $u_pm );
$ps->create_checkout( $pmp, $u_pm, array( $k_gev ), home_url( '/area/' ) );
$row = $latest();
$stripe_sessions[ $row['provider_ref'] ]['paid'] = true;
apse_ok( 400 === $webhook( $mk_event( $row, 800 ), 'whsec_sbagliato' )->get_status(), 'webhook: firma sbagliata rifiutata' );
apse_ok( 400 === $webhook( $mk_event( $row, 800 ), null, time() - 3600 )->get_status(), 'webhook: firma troppo vecchia rifiutata' );
apse_ok( 'pending' === $ps->get_by_public( $row['public_id'] )['status'], 'webhook rifiutato: nessun incasso registrato' );
$r = $webhook( $mk_event( $row, 800 ) );
apse_ok( 200 === $r->get_status() && 'registrato' === $r->get_data()['result'] && 'paid' === $ps->get_by_public( $row['public_id'] )['status'] && 'paid' === $by_person( $pev_s )[ $pg ]['state'], 'webhook valido: pagamento dell\'ospite registrato' );
$before = $balance_of( 'Stripe' );
$webhook( $mk_event( $row, 800 ) );
apse_ok( $before === $balance_of( 'Stripe' ) && 3300 === $before, 'webhook ripetuto da Stripe: nessun doppio incasso' );
apse_ok( 'ignorato' === $webhook( array( 'id' => 'e', 'type' => 'charge.refunded', 'data' => array( 'object' => array() ) ) )->get_data()['result'], 'webhook di altro tipo: ignorato' );

// importo pagato diverso da quello atteso
wp_set_current_user( $u_pm );
$ps->create_checkout( $pmp, $u_pm, array( $k_gm ), home_url( '/area/' ) );
$row = $latest();
$stripe_sessions[ $row['provider_ref'] ]['paid'] = true;
$webhook( $mk_event( $row, 100 ) );
$p2 = $ps->get_by_public( $row['public_id'] );
apse_ok( 'paid' === $p2['status'] && 1 === (int) $p2['review'] && 0 === (int) $p2['allocated_cents'] && false !== strpos( (string) $p2['error'], 'diverso' ), 'importo pagato diverso da quello atteso: registrato e segnato "da controllare"' );
apse_ok( 100 === $sum_tx( "method = 'stripe' AND description LIKE '%non abbinato%'" ), 'importo diverso: i soldi arrivati entrano comunque in prima nota come "non abbinato"' );

// prenotazione annullata mentre il socio stava pagando
wp_set_current_user( 1 );
$ev2   = $mkev( 'Evento annullato nel frattempo', 500, null );
$ev2_s = $first_session( $ev2 );
$acts->book( $ev2_s, $pm );
wp_set_current_user( $u_pm );
$ps->create_checkout( $pmp, $u_pm, array( "b:$ev2_s:$pm" ), home_url( '/area/' ) );
$row = $latest();
wp_set_current_user( 1 );
$acts->cancel_booking( $ev2_s, $pm );
$stripe_sessions[ $row['provider_ref'] ]['paid'] = true;
$ps->handle_return( $row['public_id'], 'ok', $u_pm );
$p3 = $ps->get_by_public( $row['public_id'] );
apse_ok( 'paid' === $p3['status'] && 1 === (int) $p3['review'] && 0 === (int) $p3['allocated_cents'], 'prenotazione annullata nel frattempo: pagamento registrato e segnato "da controllare"' );
apse_ok( 500 === $sum_tx( "document_ref = '" . esc_sql( $row['provider_ref'] ) . "'" ), 'prenotazione annullata: i 5,00 incassati restano in prima nota' );
$review_list = $ps->list( array( 'review' => 1 ) );
apse_ok( 2 === count( $review_list ), 'elenco "da controllare": i due pagamenti anomali' );
$ps->mark_reviewed( (int) $review_list[0]['id'] );
apse_ok( 1 === count( $ps->list( array( 'review' => 1 ) ) ), 'segnare un pagamento come controllato' );

// quota associativa online
apse_ok( ! $people->is_active_member( $pm ), 'prima del pagamento il socio non ha la tessera valida' );
wp_set_current_user( $u_pm );
$ps->create_checkout( $pmp, $u_pm, array( $k_q ), home_url( '/area/' ) );
$row = $latest();
$stripe_sessions[ $row['provider_ref'] ]['paid'] = true;
$ps->handle_return( $row['public_id'], 'ok', $u_pm );
apse_ok( $people->is_active_member( $pm ) && ! isset( $ps->dues_for( $people->get( $pm ) )[ $k_q ] ), 'pagata la quota online: il socio ha la tessera valida e la voce sparisce' );

// pagina dell'area soci e azione dal sito
$q  = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Quinto', 'last_name' => 'Quotato', 'email' => 'quinto.quotato@example.com' ) );
$uq = (int) $people->get( $q )['wp_user_id'];
$html = $as( $uq, '[apsemplice_pagamenti]' );
apse_ok( false !== strpos( $html, 'apse_front_pay' ) && false !== strpos( $html, 'Paga con carta' ) && false !== strpos( $html, 'data-cents' ), 'area soci: elenco da pagare con il pulsante "Paga con carta"' );
apse_ok( false !== strpos( $as( $uq, '[apsemplice_area_soci]' ), 'apsf-pay' ), 'l\'area soci completa include la sezione pagamenti' );
wp_set_current_user( $uq );
$redirect = \ApSemplice\Frontend\Actions::do_pay( array( 'items' => array( "q:$q:" . Settings::membership_year()->label() ) ) );
apse_ok( 0 === strpos( $redirect, 'https://checkout.stripe.com/' ), 'azione dal sito: porta alla pagina di pagamento ospitata' );
$open = $latest();

// scadenza: un pagamento rimasto in sospeso per giorni
$wpdb->update( Db::t( 'payments' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-5 days' ) ) ), array( 'id' => (int) $open['id'] ) );
$res = $ps->check_pending( true );
apse_ok( $res['checked'] >= 1 && $res['expired'] >= 1 && 'expired' === $ps->get_by_public( $open['public_id'] )['status'], 'controllo periodico: i pagamenti mai confermati scadono dopo qualche giorno' );

// attacchi ai pagamenti: i soldi arrivati non si perdono e gli eventi non attendibili non registrano nulla
$mk_pending = function ( string $first ) use ( $people, $ps, $latest ) {
	$id  = $people->create( array( 'type' => 'ordinary', 'first_name' => $first, 'last_name' => 'Attaccata', 'email' => strtolower( $first ) . '.attaccata@example.com' ) );
	$uid = (int) $people->get( $id )['wp_user_id'];
	wp_set_current_user( $uid );
	$ps->create_checkout( $people->get( $id ), $uid, array_keys( $ps->dues_for( $people->get( $id ) ) ), home_url( '/area/' ) );
	return array( $id, $uid, $latest() );
};
// 1. annullato sul sito ma pagato al gateway: il webhook lo registra comunque
list( $lt, $u_lt, $late ) = $mk_pending( 'Tarda' );
$ps->handle_return( $late['public_id'], 'cancel', $u_lt );
apse_ok( 'cancelled' === $ps->get_by_public( $late['public_id'] )['status'], 'pagamento: l\'annullo sul sito chiude un pagamento non pagato' );
$stripe_sessions[ $late['provider_ref'] ]['paid'] = true;
apse_ok( 'registrato' === $webhook( $mk_event( $late, (int) $late['amount_cents'] ) )->get_data()['result'] && 'paid' === $ps->get_by_public( $late['public_id'] )['status'] && $people->is_active_member( $lt ), 'pagamento annullato sul sito ma pagato al gateway: il webhook lo registra, i soldi non si perdono' );
// 2. indirizzo di annullo aperto dopo aver pagato: si controlla il gateway prima di annullare
list( $lt2, $u_lt2, $late2 ) = $mk_pending( 'Doppia' );
$stripe_sessions[ $late2['provider_ref'] ]['paid'] = true;
apse_ok( false !== strpos( $ps->handle_return( $late2['public_id'], 'cancel', $u_lt2 ), 'ricevuto' ) && 'paid' === $ps->get_by_public( $late2['public_id'] )['status'] && $people->is_active_member( $lt2 ), 'ritorno "annulla" con pagamento già riuscito: registrato, non annullato' );
// 3. evento di prova su un sito in modalità reale (e viceversa): non registra incassi
list( $lt3, $u_lt3, $late3 ) = $mk_pending( 'Prova' );
$stripe_sessions[ $late3['provider_ref'] ]['paid'] = true;
$ev3 = $mk_event( $late3, (int) $late3['amount_cents'] );
$ev3['livemode'] = true; // il sito è in modalità di prova
apse_ok( 'modalità diversa' === $webhook( $ev3 )->get_data()['result'] && 'pending' === $ps->get_by_public( $late3['public_id'] )['status'], 'webhook: un evento reale su un sito in modalità di prova non registra nulla' );
$ev3['livemode'] = false;
apse_ok( 'registrato' === $webhook( $ev3 )->get_data()['result'], 'webhook: con la modalità giusta si registra' );
// 4. valuta diversa dall'euro: i soldi non si contano come euro, il pagamento va controllato
list( $lt4, $u_lt4, $late4 ) = $mk_pending( 'Dollari' );
$stripe_sessions[ $late4['provider_ref'] ]['paid'] = true;
$ev4 = $mk_event( $late4, (int) $late4['amount_cents'] );
$ev4['data']['object']['currency'] = 'usd';
$webhook( $ev4 );
$p4 = $ps->get_by_public( $late4['public_id'] );
apse_ok( 'paid' === $p4['status'] && 1 === (int) $p4['review'] && 0 === (int) $p4['allocated_cents'] && ! $people->is_active_member( $lt4 ), 'pagamento in un\'altra valuta: segnato "da controllare", nessuna quota registrata' );
apse_ok( 0 === \ApSemplice\StripeApi::paid_eur_cents( array( 'currency' => 'USD', 'amount_total' => 1000 ) ) && 1000 === \ApSemplice\StripeApi::paid_eur_cents( array( 'currency' => 'EUR', 'amount_total' => 1000 ) ) && 0 === \ApSemplice\PayPalApi::captured_cents( array( 'purchase_units' => array( array( 'payments' => array( 'captures' => array( array( 'status' => 'COMPLETED', 'amount' => array( 'currency_code' => 'USD', 'value' => '10.00' ) ) ) ) ) ) ) ), 'valuta: solo gli euro si contano (Stripe e PayPal)' );
// 5. registrazione interrotta a metà: si segnala, non si riprova
list( $lt5, $u_lt5, $late5 ) = $mk_pending( 'Interrotta' );
$wpdb->update( Db::t( 'payments' ), array( 'status' => 'processing', 'updated_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-2 hours' ) ) ), array( 'id' => (int) $late5['id'] ) );
$ps->check_pending();
$p5 = $ps->get_by_public( $late5['public_id'] );
apse_ok( 'paid' === $p5['status'] && 1 === (int) $p5['review'] && false !== strpos( (string) $p5['error'], 'interrotta' ), 'pagamento rimasto "in registrazione": segnato da controllare, non registrato due volte' );
wp_set_current_user( 1 );

// PayPal: ordine, approvazione, cattura
Settings::update( array( 'payment_provider' => 'paypal', 'paypal_mode' => 'sandbox', 'paypal_client_id' => str_repeat( 'A', 40 ), 'paypal_client_secret' => str_repeat( 'b', 40 ) ) );
apse_ok( 'paypal' === $ps->provider(), 'pagamenti online: PayPal attivo' );
wp_set_current_user( 1 );
$ev3   = $mkev( 'Gita in barca', 700, null );
$ev3_s = $first_session( $ev3 );
$acts->book( $ev3_s, $pm );
wp_set_current_user( $u_pm );
$url = $ps->create_checkout( $pmp, $u_pm, array( "b:$ev3_s:$pm" ), home_url( '/area/' ) );
$row = $latest();
apse_ok( false !== strpos( $url, 'sandbox.paypal.com/checkoutnow' ) && 'paypal' === $row['provider'] && 700 === (int) $row['amount_cents'] && 'pending' === $row['status'], 'checkout PayPal: ordine creato, il socio va su PayPal' );
apse_ok( false !== strpos( $ps->handle_return( $row['public_id'], 'ok', $u_pm ), 'attesa' ), 'PayPal: ritorno senza approvazione = ancora in attesa' );
$paypal_orders[ $row['provider_ref'] ]['status'] = 'APPROVED';
apse_ok( false !== strpos( $ps->handle_return( $row['public_id'], 'ok', $u_pm ), 'ricevuto' ) && 'paid' === $ps->get_by_public( $row['public_id'] )['status'], 'PayPal: approvato, il sito cattura e registra il pagamento' );
apse_ok( 700 === $balance_of( 'PayPal' ) && 700 === $sum_tx( "method = 'paypal' AND document_ref = '" . esc_sql( $row['provider_ref'] ) . "'" ), 'prima nota: 7,00 sul conto "PayPal"' );

// PayPal approvato ma l'utente non è tornato sul sito: lo recupera il controllo periodico
wp_set_current_user( 1 );
$ev4   = $mkev( 'Torneo', 600, null );
$ev4_s = $first_session( $ev4 );
$acts->book( $ev4_s, $pm );
wp_set_current_user( $u_pm );
$ps->create_checkout( $pmp, $u_pm, array( "b:$ev4_s:$pm" ), home_url( '/area/' ) );
$row = $latest();
$paypal_orders[ $row['provider_ref'] ]['status'] = 'APPROVED';
$res = $ps->check_pending( true );
apse_ok( $res['registered'] >= 1 && 'paid' === $ps->get_by_public( $row['public_id'] )['status'] && 1300 === $balance_of( 'PayPal' ), 'PayPal: l\'utente non è tornato, il controllo periodico cattura e registra' );
apse_ok( false === strpos( $as( $uq, '[apsemplice_pagamenti]' ), 'Paga con carta' ), 'area soci: con PayPal non compare "Paga con carta"' );

// Stripe e PayPal insieme, con diciture personalizzabili
Settings::update( array( 'payment_provider' => 'stripe_paypal' ) );
apse_ok( array( 'stripe', 'paypal' ) === $ps->providers() && $ps->enabled(), 'pagamenti: Stripe e PayPal si possono usare insieme' );
$both_html = $as( $uq, '[apsemplice_pagamenti]' );
apse_ok( false !== strpos( $both_html, 'Paga con carta' ) && false !== strpos( $both_html, 'Paga con PayPal' ) && false !== strpos( $both_html, 'name="provider" value="stripe"' ) && false !== strpos( $both_html, 'name="provider" value="paypal"' ), 'area soci: due pulsanti, uno per metodo' );
Settings::update( array( 'pay_label_paypal' => 'Paga a rate con PayPal', 'pay_note_paypal' => 'Puoi dividere il pagamento in tre rate senza interessi.' ) );
$both_html = $as( $uq, '[apsemplice_pagamenti]' );
apse_ok( false !== strpos( $both_html, 'Paga a rate con PayPal' ) && false !== strpos( $both_html, 'Puoi dividere il pagamento in tre rate' ) && false !== strpos( $both_html, 'Paga con carta' ) && false === strpos( $both_html, '>Paga con PayPal<' ), 'area soci: le diciture di PayPal sono quelle scelte, quelle di Stripe restano' );
$bp   = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Bea', 'last_name' => 'Bipagante', 'email' => 'bea.bipagante@example.com' ) );
$u_bp = (int) $people->get( $bp )['wp_user_id'];
wp_set_current_user( $u_bp );
$bp_keys = array_keys( $ps->dues_for( $people->get( $bp ) ) );
$url_s   = $ps->create_checkout( $people->get( $bp ), $u_bp, $bp_keys, home_url( '/area/' ), 'stripe' );
$row_s   = $latest();
$url_p   = $ps->create_checkout( $people->get( $bp ), $u_bp, $bp_keys, home_url( '/area/' ), 'paypal' );
$row_p   = $latest();
apse_ok( 0 === strpos( $url_s, 'https://checkout.stripe.com/' ) && 'stripe' === $row_s['provider'] && false !== strpos( $url_p, 'paypal.com' ) && 'paypal' === $row_p['provider'], 'checkout: ogni pulsante porta al suo gateway' );
apse_ok( null !== apse_throws( function () use ( $ps, $people, $bp, $u_bp, $bp_keys ) { $ps->create_checkout( $people->get( $bp ), $u_bp, $bp_keys, home_url( '/area/' ), 'woocommerce' ); } ), 'checkout: un metodo non attivo è rifiutato' );
wp_set_current_user( 1 );
Settings::clear_secret( 'paypal_client_secret' );
apse_ok( array( 'stripe' ) === $ps->providers() && $ps->enabled(), 'pagamenti: se PayPal ha la configurazione incompleta resta attivo solo Stripe' );
Settings::update( array( 'paypal_client_secret' => str_repeat( 'b', 40 ) ) );
apse_ok( array( 'stripe', 'paypal' ) === $ps->providers(), 'pagamenti: rimessa la chiave, tornano entrambi' );
$cfg_both = array( 'payment_provider' => 'stripe_paypal', 'stripe_mode' => 'test', 'stripe_publishable_key' => 'pk_test_51Abc123', 'stripe_secret_key' => 'sk_test_51Abc123', 'stripe_webhook_secret' => 'whsec_abc123', 'paypal_mode' => 'sandbox', 'paypal_client_id' => str_repeat( 'A', 40 ), 'paypal_client_secret' => str_repeat( 'b', 40 ) );
apse_ok( array() === \ApSemplice\PaymentConfig::validate( $cfg_both )['errors'] && ! empty( \ApSemplice\PaymentConfig::validate( array_merge( $cfg_both, array( 'paypal_client_id' => '' ) ) )['errors'] ), 'configurazione: con entrambi i gateway si controllano entrambi' );
apse_ok( ! empty( \ApSemplice\PaymentConfig::validate( array_merge( $cfg_both, array( 'stripe_mode' => 'live', 'stripe_publishable_key' => 'pk_live_51Abc123', 'stripe_secret_key' => 'sk_live_51Abc123', 'site_https' => 0 ) ) )['errors'] ), 'configurazione: la modalità reale non si accetta su un sito http' );
Settings::update( array( 'payment_provider' => 'none' ) );
apse_ok( ! $ps->enabled() && array() === $ps->providers() && false === strpos( $as( $uq, '[apsemplice_pagamenti]' ), 'name="provider"' ) && false !== strpos( $as( $uq, '[apsemplice_pagamenti]' ), Settings::payment_hint() ), 'pagamenti online disattivati: i soci vedono cosa devono e l\'invito a pagare in sede' );
Settings::update( array( 'pay_label_paypal' => '', 'pay_note_paypal' => '' ) );

// bonifico: IBAN mostrati ai soci e inviati per email
$iban_ok = 'IT60X0542811101000000123456';
apse_ok( ! \ApSemplice\Bank::enabled() && '' === \ApSemplice\Frontend\Views::bank_public() && false === strpos( $as( $uq, '[apsemplice_pagamenti]' ), 'IT60' ), 'bonifico: spento di default, nessun IBAN esposto' );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $iban_ok ) { Admin\TechActions::save_bank( array( 'bank_enabled' => '1', 'bank' => array( array( 'label' => 'Principale', 'holder' => 'APS Prova', 'iban' => 'IT60X0542811101000000123457' ) ) ) ); } ), 'IBAN' ) && false !== strpos( (string) apse_throws( function () { Admin\TechActions::save_bank( array( 'bank_enabled' => '1', 'bank' => array() ) ); } ), 'almeno un IBAN' ), 'bonifico: un IBAN sbagliato (cifra di controllo) è rifiutato e non si attiva senza conti' );
$mails = array();
Admin\TechActions::save_bank( array( 'bank_enabled' => '1', 'bank_title' => 'Paga con bonifico', 'bank_in_reminders' => '1', 'bank' => array( array( 'label' => 'Conto principale', 'holder' => 'APS Prova', 'iban' => 'it60 x054 2811 1010 0000 0123 456', 'bic' => 'BCITITMM', 'bank' => 'Banca di Prova' ), array( 'label' => '', 'iban' => '' ) ) ) );
apse_ok( \ApSemplice\Bank::enabled() && $iban_ok === \ApSemplice\Bank::accounts()[0]['iban'] && 1 === count( \ApSemplice\Bank::accounts() ), 'bonifico: salvato (IBAN normalizzato, righe vuote scartate)' );
$bank_mail = '';
foreach ( $mails as $m ) {
	if ( false !== strpos( (string) $m['subject'], 'Modificate le coordinate bancarie' ) ) {
		$bank_mail = (string) $m['message'];
	}
}
apse_ok( '' !== $bank_mail && false === strpos( $bank_mail, '0542811101000000123456' ) && false !== strpos( $bank_mail, 'IT** **** 3456' ), 'bonifico: ogni cambio di IBAN avvisa gli amministratori per email (con l\'IBAN mascherato)' );
apse_ok( false === strpos( wp_json_encode( Audit::recent( 400 ) ), '0542811101000000123456' ) && in_array( 'bank.changed', array_column( Audit::recent( 400 ), 'action' ), true ), 'bonifico: il registro azioni segna il cambio senza scrivere l\'IBAN' );
$bank_html = $as( $uq, '[apsemplice_pagamenti]' );
apse_ok( false !== strpos( $bank_html, 'IT60 X054 2811 1010 0000 0123 456' ) && false !== strpos( $bank_html, 'Paga con bonifico' ) && false !== strpos( $bank_html, 'data-apsf-copy="' . $iban_ok . '"' ) && false !== strpos( $bank_html, 'Causale suggerita' ) && false !== strpos( $bank_html, 'Quotato Quinto' ) && false !== strpos( $bank_html, 'apse_front_bank_email' ), 'bonifico: l\'area soci mostra IBAN, pulsante «Copia», causale con il nome e il modulo per riceverlo per email' );
apse_ok( false !== strpos( \ApSemplice\Frontend\Views::bank_public(), 'IT60 X054' ) && false === strpos( \ApSemplice\Frontend\Views::bank_public(), 'Causale suggerita' ) && shortcode_exists( 'apsemplice_bonifico' ), 'bonifico: lo shortcode pubblico mostra gli IBAN senza causale personale' );
apse_ok( false !== strpos( \ApSemplice\Bank::text_block( array( 'first_name' => 'Quinto', 'last_name' => 'Quotato' ), array( array( 'label' => 'Quota associativa 2026' ) ) ), 'Causale: Quotato Quinto - Quota associativa 2026' ), 'bonifico: causale nel testo delle email' );
// invio per email: solo all'indirizzo del socio, con un limite orario
$mails = array();
wp_set_current_user( $uq );
Settings::update( array( 'limits' => array( 'bank_email_per_hour' => 1 ) ) );
delete_transient( 'apse_bank_mail_' . $uq );
$msg_b = \ApSemplice\Frontend\Actions::do_bank_email( array( 'items' => array( 'chiave-estranea' ) ) );
$to_b  = ! empty( $mails ) ? (string) ( (array) $mails[0]['to'] )[0] : '';
apse_ok( false !== strpos( $msg_b, 'controlla la tua email' ) && $to_b === (string) $people->get( $q )['email'] && false !== strpos( (string) $mails[0]['message'], 'IT60 X054 2811' ), 'bonifico: l\'email con le coordinate va solo all\'indirizzo del socio' );
apse_ok( false !== strpos( (string) apse_throws( function () { \ApSemplice\Frontend\Actions::do_bank_email( array() ); } ), 'più volte' ) && 1 === count( $mails ), 'bonifico: oltre il limite orario non partono altre email' );
delete_transient( 'apse_bank_mail_' . $uq );
Settings::update( array( 'limits' => array() ) );
wp_set_current_user( 1 );
Admin\TechActions::save_bank( array( 'bank_enabled' => '0', 'bank' => array() ) );
apse_ok( ! \ApSemplice\Bank::enabled() && '' === \ApSemplice\Frontend\Views::bank_public() && null !== apse_throws( function () use ( $uq ) { wp_set_current_user( $uq ); \ApSemplice\Frontend\Actions::do_bank_email( array() ); } ), 'bonifico: tolti i conti, nulla è esposto e l\'invio per email non funziona' );
wp_set_current_user( 1 );
apse_render( array( Admin\PaymentsPage::class, 'render' ), 'Bonifico bancario' );
apse_ok( Admin\Admin::ADMIN_ONLY && in_array( 'apse-limits', Admin\Admin::ADMIN_ONLY, true ), 'limiti: la pagina è riservata agli amministratori' );

// pagina di amministrazione e impostazioni
wp_set_current_user( 1 );
$_SERVER['REQUEST_METHOD'] = 'GET';
apse_render( array( Admin\PaymentsPage::class, 'render' ), 'Verifica i pagamenti in sospeso' );
$adm = apse_render( array( Admin\PaymentsPage::class, 'render' ), 'da controllare', array( 'review' => '1' ) );
apse_ok( false !== strpos( $adm, 'Pagamento online' ) || false !== strpos( $adm, 'Importo pagato' ), 'amministrazione: i pagamenti da controllare mostrano il motivo' );
apse_render( array( Admin\PaymentsPage::class, 'render' ), 'checkout.session.async_payment_succeeded' );
$audit_all = Audit::recent( 400 );
$audit     = array_column( $audit_all, 'action' );
apse_ok( in_array( 'payment.created', $audit, true ) && in_array( 'payment.paid', $audit, true ) && false === strpos( wp_json_encode( $audit_all ), 'sk_test_51Smoke1' ), 'registro azioni: pagamenti tracciati, senza chiavi' );

// ripristino
Settings::update( array( 'payment_provider' => 'none' ) );
Settings::clear_secret( 'stripe_secret_key' );
Settings::clear_secret( 'stripe_webhook_secret' );
Settings::clear_secret( 'paypal_client_secret' );
$ps->set_http( null );
remove_all_filters( 'pre_wp_mail' );
wp_set_current_user( 1 );

// ---------- Allegati alle spese (cartella privata, non la libreria media) ----------
wp_set_current_user( 1 );
$_SERVER['REQUEST_METHOD'] = 'GET';
Admin\Admin::init(); // in WP-CLI is_admin() è falso: si registrano qui i gestori dell'amministrazione
foreach ( array( 'admin_post_apse_export', 'wp_ajax_apse_person_context', 'admin_post_apse_front_pay', 'admin_post_apse_attachment', 'admin_post_apse_add_attachment', 'admin_post_apse_save_expense' ) as $hook ) {
	apse_ok( has_action( $hook ), "hook registrato con il prefisso apse_: $hook" );
}
apse_ok( Db::t( 'attachments' ) === $wpdb->get_var( "SHOW TABLES LIKE '" . Db::t( 'attachments' ) . "'" ) && 0 === strpos( Db::t( 'attachments' ), $wpdb->prefix . 'apse_' ), 'tabella allegati con prefisso apse_' );

$tmp   = sys_get_temp_dir();
$mkf   = function ( string $name, string $content ) use ( $tmp ) {
	$p = $tmp . '/apse-' . wp_generate_password( 8, false ) . '-' . $name;
	file_put_contents( $p, $content );
	return $p;
};
$pdf1  = $mkf( 'scontrino.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n" );
$png1  = $mkf( 'foto.png', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
$png2  = $mkf( 'altra.png', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ) );
$fake  = $mkf( 'furbo.png', "<?php echo 'ciao';" );
$files = function ( array $paths ) {
	$out = array( 'name' => array(), 'tmp_name' => array(), 'size' => array(), 'error' => array() );
	foreach ( $paths as $name => $p ) {
		$out['name'][]     = $name;
		$out['tmp_name'][] = $p;
		$out['size'][]     = filesize( $p );
		$out['error'][]    = UPLOAD_ERR_OK;
	}
	return $out;
};
$exp_cat = 0;
foreach ( $ledger->categories() as $c ) {
	if ( Labels::category_kinds()[ $c['kind'] ][2] && 'adjustment' !== $c['kind'] ) {
		$exp_cat = (int) $c['id'];
		break;
	}
}
$save_exp     = new ReflectionMethod( Admin\Actions::class, 'save_expense' );
$add_att      = new ReflectionMethod( Admin\Actions::class, 'add_attachment' );
$rm_att       = new ReflectionMethod( Admin\Actions::class, 'remove_attachment' );
$exp_post     = array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'category_id' => $exp_cat, 'amount' => '12,50', 'description' => 'Materiale con scontrino' );
$media_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
$tx_before    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) );

// un file non ammesso blocca tutto: nessuna spesa registrata
$_FILES = array( 'docs' => $files( array( 'scontrino.pdf' => $pdf1, 'furbo.png' => $fake ) ) );
$msg    = (string) apse_throws( function () use ( $save_exp, $exp_post ) { $save_exp->invoke( null, $exp_post ); } );
apse_ok( false !== strpos( $msg, 'non è ammesso' ) && $tx_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) ), 'allegato camuffato (php con estensione png): rifiutato e la spesa non viene registrata' );

// spesa con un PDF e una foto
$_FILES = array( 'docs' => $files( array( 'scontrino.pdf' => $pdf1 ) ), 'shots' => $files( array( 'foto.png' => $png1 ) ) );
$res    = $save_exp->invoke( null, $exp_post );
$tx     = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Db::t( 'transactions' ) . " WHERE type = 'expense'" );
$att    = Attachments::list_for( $tx );
apse_ok( false !== strpos( $res[1], '2 allegati' ) && 2 === count( $att ), 'spesa registrata con due allegati (PDF e foto)' );
$a0 = $att[0];
apse_ok( 'application/pdf' === $a0['mime'] && 'scontrino.pdf' === $a0['original_name'] && preg_match( '/^[0-9a-f]{32}$/', $a0['stored_name'] ) && 64 === strlen( $a0['sha256'] ), 'allegato: tipo letto dal contenuto, nome sul disco casuale e senza estensione' );
$path = Attachments::path_of( $a0 );
apse_ok( is_file( $path ) && filesize( $path ) === (int) $a0['size_bytes'] && 0 === strpos( $path, Attachments::dir() ), 'il file sta nella cartella privata del plugin' );
apse_ok( is_file( Attachments::dir() . '/.htaccess' ) && false !== strpos( (string) file_get_contents( Attachments::dir() . '/.htaccess' ), 'Require all denied' ) && is_file( Attachments::dir() . '/index.php' ), 'cartella privata protetta (.htaccess e index)' );
apse_ok( $media_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ), 'niente nella libreria media: la galleria resta pulita' );
$url = Attachments::url( (int) $a0['id'] );
apse_ok( false !== strpos( $url, 'action=apse_attachment' ) && false !== strpos( $url, '_wpnonce=' ), 'indirizzo di apertura con controllo permessi (nonce)' );
apse_ok( user_can( 1, Plugin::CAP ) && ! user_can( $u_ord, Plugin::CAP ) && ! user_can( $u_vol, Plugin::CAP ), 'solo chi gestisce il plugin può aprire gli allegati' );

// aggiungere altri file a un movimento esistente; doppioni
$_FILES = array( 'docs' => $files( array( 'altra.png' => $png2 ) ) );
$res    = $add_att->invoke( null, array( 'transaction_id' => $tx, '_back' => 'x' ) );
apse_ok( false !== strpos( $res[1], '1 allegato' ) && 3 === count( Attachments::list_for( $tx ) ), 'allegato aggiunto dopo la registrazione' );
$_FILES = array( 'docs' => $files( array( 'copia-dello-scontrino.pdf' => $pdf1 ) ) );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $add_att, $tx ) { $add_att->invoke( null, array( 'transaction_id' => $tx ) ); } ), 'già allegato' ), 'stesso file allegato due volte: rifiutato' );
$_FILES = array();
apse_ok( null !== apse_throws( function () use ( $add_att, $tx ) { $add_att->invoke( null, array( 'transaction_id' => $tx ) ); } ), 'nessun file scelto: messaggio di errore' );

// rimozione: sparisce dall'elenco ma non dal disco né dal registro
$rm_id = (int) $att[1]['id'];
$rm_att->invoke( null, array( 'id' => $rm_id ) );
apse_ok( 2 === count( Attachments::list_for( $tx ) ) && null !== Attachments::get( $rm_id )['removed_at'] && is_file( Attachments::path_of( Attachments::get( $rm_id ) ) ), 'allegato tolto dall\'elenco: il file resta sul disco' );
apse_ok( null !== apse_throws( function () use ( $rm_att, $rm_id ) { $rm_att->invoke( null, array( 'id' => $rm_id ) ); } ), 'allegato già tolto: nessuna seconda rimozione' );

// movimento annullato: non si allega
$tx2  = $ledger->record_expense( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'category_id' => $exp_cat, 'amount_cents' => 100 ) );
$ledger->void( $tx2, 'prova' );
$prep = Attachments::prepare( $files( array( 'altra.png' => $png2 ) ) );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $tx2, $prep ) { Attachments::add( $tx2, $prep ); } ), 'annullato' ), 'su un movimento annullato non si allega nulla' );

// pagine e registro azioni
$html = apse_render( array( Admin\ExpensePage::class, 'render' ), 'Documenti' );
apse_ok( false !== strpos( $html, 'multipart/form-data' ) && false !== strpos( $html, 'capture="environment"' ) && false !== strpos( $html, 'application/pdf' ), 'modulo spesa: carica file e scatta foto (fotocamera del telefono)' );
$html = apse_render( array( Admin\LedgerPage::class, 'render' ), 'Allegati (2)', array( 'year' => substr( $today, 0, 4 ) ) );
apse_ok( false !== strpos( $html, 'action=apse_attachment' ) && false !== strpos( $html, 'scontrino.pdf' ) && false !== strpos( $html, 'apse_add_attachment' ), 'prima nota: allegati del movimento con apertura e aggiunta' );
$aud = Audit::recent( 400 );
apse_ok( in_array( 'attachment.added', array_column( $aud, 'action' ), true ) && in_array( 'attachment.removed', array_column( $aud, 'action' ), true ) && false === strpos( wp_json_encode( $aud ), 'scontrino.pdf' ), 'registro azioni: allegati tracciati, senza i nomi dei file' );
foreach ( array( $pdf1, $png1, $png2, $fake ) as $f ) {
	@unlink( $f );
}

// ---------- Tesoriere: registra spese dall'area riservata ----------
wp_set_current_user( 1 );
$_SERVER['REQUEST_METHOD'] = 'GET';
$tre_p = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Tina', 'last_name' => 'Tesoriera', 'email' => 'tina.tesoriera@example.com' ) );$u_tre = (int) $people->get( $tre_p )['wp_user_id'];
$set_tre = new ReflectionMethod( Admin\Actions::class, 'set_treasurer' );
$tre_pdf = $mkf( 'fattura.pdf', "%PDF-1.4\n% fattura del tesoriere " . wp_generate_password( 12, false ) . "\ntrailer<<>>\n%%EOF\n" );
$tre_png = $mkf( 'scontrino.png', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
$tre_post = array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'category_id' => $exp_cat, 'amount' => '8,40', 'description' => 'Colori per il corso', 'document_ref' => 'SC-77' );

apse_ok( ! user_can( $u_tre, 'apse_add_expense', 0 ) && ! Access::is_treasurer( $u_tre ), 'tesoriere: un socio qualsiasi non può registrare spese' );
$html = $as( $u_tre, '[apsemplice_spese]' );
apse_ok( false !== strpos( $html, 'riservata al tesoriere' ) && false === strpos( $html, 'apse_front_expense' ), 'tesoriere: la pagina Spese è chiusa a chi non ha il permesso' );
apse_ok( false === strpos( $as( $u_tre, '[apsemplice_area_soci]' ), 'Registra una spesa' ), 'tesoriere: nell\'area soci la sezione Spese non compare senza permesso' );
wp_set_current_user( $u_tre );
$_FILES = array();
apse_ok( null !== apse_throws( function () use ( $front, $tre_post ) { $front::do_expense( $tre_post ); } ), 'tesoriere: l\'azione è rifiutata senza permesso' );

// l'amministratore dà il permesso
wp_set_current_user( 1 );
apse_ok( null !== apse_throws( function () use ( $set_tre, $guest ) { $set_tre->invoke( null, array( 'id' => $guest, 'enabled' => 1 ) ); } ), 'tesoriere: un ospite non può esserlo' );
$res = $set_tre->invoke( null, array( 'id' => $tre_p, 'enabled' => 1 ) );
apse_ok( Access::is_treasurer( $u_tre ) && user_can( $u_tre, 'apse_add_expense', 0 ) && ! user_can( $u_tre, Plugin::CAP ), 'tesoriere: con il permesso può registrare spese ma non è amministratore' );
$html = apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Tesoriere', array( 'id' => $tre_p ) );
apse_ok( false !== strpos( $html, 'Togli il permesso' ), 'scheda socio: il permesso di tesoriere è visibile e revocabile' );

// la schermata
$html = $as( $u_tre, '[apsemplice_spese]' );
apse_ok( false !== strpos( $html, 'apse_front_expense' ) && false !== strpos( $html, 'multipart/form-data' ) && false !== strpos( $html, 'capture="environment"' ) && false !== strpos( $html, 'name="amount"' ), 'schermata spese: modulo con importo, voce, conto e fotocamera' );
apse_ok( false === strpos( $html, 'Saldo' ) && false === strpos( $html, 'saldo' ), 'schermata spese: nessun saldo dei conti' );
apse_ok( false !== strpos( $as( $u_tre, '[apsemplice_area_soci]' ), 'Registra una spesa' ), 'area soci: la sezione Spese compare per il tesoriere' );
apse_ok( isset( \ApSemplice\Frontend\Shortcodes::VIEWS['spese'] ) && shortcode_exists( 'apsemplice_spese' ), 'shortcode [apsemplice_spese] registrato (anche per blocco e widget)' );

// registrazione con documenti
wp_set_current_user( $u_tre );
$_FILES = array( 'docs' => $files( array( 'fattura.pdf' => $tre_pdf ) ), 'shots' => $files( array( 'scontrino.png' => $tre_png ) ) );
$msg = $front::do_expense( $tre_post );
$ttx = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Db::t( 'transactions' ) . " WHERE type = 'expense'" );
$row = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'transactions' ) . ' WHERE id = ' . $ttx, ARRAY_A );
apse_ok( false !== strpos( $msg, '2 documenti' ) && 840 === (int) $row['amount_cents'] && (int) $row['created_by'] === $u_tre && 'SC-77' === $row['document_ref'], 'tesoriere: spesa registrata con due documenti, a suo nome' );
apse_ok( 2 === count( Attachments::list_for( $ttx ) ), 'tesoriere: i documenti sono allegati alla spesa' );
$tre_att = Attachments::list_for( $ttx )[0];
$admin_att = Attachments::list_for( $tx )[0];
apse_ok( Attachments::can_open( $tre_att ) && ! Attachments::can_open( $admin_att ), 'tesoriere: apre i documenti delle sue spese, non quelli di altri' );
$html = $as( $u_tre, '[apsemplice_spese]' );
apse_ok( false !== strpos( $html, 'Le tue ultime spese' ) && false !== strpos( $html, 'Colori per il corso' ) && false !== strpos( $html, 'action=apse_attachment' ) && false === strpos( $html, 'Materiale con scontrino' ), 'tesoriere: vede le proprie spese con i documenti e non quelle degli altri' );
$mine = Plugin::ledger()->expenses_by_user( $u_tre );
apse_ok( 1 === count( $mine ) && $ttx === (int) $mine[0]['id'], 'elenco delle spese dell\'utente: solo le sue' );

// controlli
$n_tx = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) );
$_FILES = array();
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front, $tre_post ) { $front::do_expense( array_merge( $tre_post, array( 'date' => gmdate( 'Y-m-d', strtotime( '+3 days' ) ) ) ) ); } ), 'futuro' ), 'tesoriere: data nel futuro rifiutata' );
$fake2  = $mkf( 'furbo2.png', "<?php echo 'ciao';" );
$_FILES = array( 'docs' => $files( array( 'furbo2.png' => $fake2 ) ) );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front, $tre_post ) { $front::do_expense( $tre_post ); } ), 'non è ammesso' ) && $n_tx === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) ), 'tesoriere: file non ammesso, nessuna spesa registrata' );
$_FILES = array();
apse_ok( null !== apse_throws( function () use ( $front, $tre_post ) { $front::do_expense( array_merge( $tre_post, array( 'amount' => '0' ) ) ); } ), 'tesoriere: importo nullo rifiutato' );

// altri documenti: solo sulle proprie spese
$more = $mkf( 'altro.pdf', "%PDF-1.4\n% altro " . wp_generate_password( 12, false ) . "\n%%EOF\n" );
$_FILES = array( 'docs' => $files( array( 'altro.pdf' => $more ) ) );
$msg    = $front::do_expense_docs( array( 'transaction_id' => $ttx ) );
apse_ok( false !== strpos( $msg, '1 documento' ) && 3 === count( Attachments::list_for( $ttx ) ), 'tesoriere: aggiunge documenti a una sua spesa' );
$_FILES = array( 'docs' => $files( array( 'altro.pdf' => $more ) ) );
apse_ok( null !== apse_throws( function () use ( $front, $tx ) { $front::do_expense_docs( array( 'transaction_id' => $tx ) ); } ), 'tesoriere: non aggiunge documenti alle spese di altri' );
$_FILES = array();

// licenza sospesa e revoca
wp_set_current_user( 1 );
$set_tre->invoke( null, array( 'id' => $tre_p ) );
apse_ok( ! Access::is_treasurer( $u_tre ) && ! user_can( $u_tre, 'apse_add_expense', 0 ), 'tesoriere: permesso revocato' );
$aud = array_column( Audit::recent( 400 ), 'action' );
apse_ok( in_array( 'treasurer.granted', $aud, true ) && in_array( 'treasurer.revoked', $aud, true ), 'registro azioni: permesso dato e tolto' );
foreach ( array( $tre_pdf, $tre_png, $fake2, $more ) as $f ) {
	@unlink( $f );
}

// ---------- Import da Excel / CSV: soci, ospiti e prima nota di anni passati ----------
wp_set_current_user( 1 );
$_SERVER['REQUEST_METHOD'] = 'GET';
$mk_xlsx = function ( array $sheets, string $path ) {
	$ns  = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	$zip = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$wb   = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="' . $ns . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
	$rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
	$n    = 0;
	foreach ( $sheets as $name => $rows ) {
		$n++;
		$wb   .= '<sheet name="' . htmlspecialchars( $name, ENT_XML1 ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
		$rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
		$xml   = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . $ns . '"><sheetData>';
		foreach ( $rows as $ri => $cells ) {
			$xml .= '<row r="' . ( $ri + 1 ) . '">';
			foreach ( $cells as $ci => $c ) {
				$ref = chr( 65 + $ci ) . ( $ri + 1 );
				if ( is_array( $c ) ) { // numero (con stile facoltativo: 1 = data)
					$xml .= '<c r="' . $ref . '"' . ( isset( $c[1] ) ? ' s="' . $c[1] . '"' : '' ) . '><v>' . $c[0] . '</v></c>';
				} elseif ( '' !== $c ) {
					$xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars( $c, ENT_XML1 ) . '</t></is></c>';
				}
			}
			$xml .= '</row>';
		}
		$zip->addFromString( 'xl/worksheets/sheet' . $n . '.xml', $xml . '</sheetData></worksheet>' );
	}
	$zip->addFromString( 'xl/workbook.xml', $wb . '</sheets></workbook>' );
	$zip->addFromString( 'xl/_rels/workbook.xml.rels', $rels . '</Relationships>' );
	$zip->addFromString( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="' . $ns . '"><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>' );
	$zip->close();
};
$balances = function () use ( $ledger ) {
	return array_column( $ledger->balances(), 'balance', 'name' );
};
$cash_name = $cash['name'];
$xlsx      = sys_get_temp_dir() . '/apse-storico-' . wp_generate_password( 6, false ) . '.xlsx';
$mk_xlsx(
	array(
		'Soci'        => array(
			array( 'Numero tessera', 'Tipo', 'Nome', 'Cognome', 'Email', 'Telefono' ),
			array( array( 900 ), 'ordinario', 'Ida', 'Storica', 'ida.storica@example.com', '3331112222' ),
			array( array( 901 ), 'volontario', 'Otto', 'Storico', 'otto.storico@example.com', '' ),
		),
		'Ospiti'      => array(
			array( 'Tipo', 'Nome', 'Cognome', 'Ospite di', 'Cellulare' ),
			array( 'ospite', 'Gianni', 'Storico', array( 900 ), '333 7777771' ),
			array( 'ospite', 'Gina', 'Storica', 'otto.storico@example.com', '333 7777772' ),
		),
		'Prima nota'  => array(
			array( 'Rendiconto 2022 (titolo sopra la tabella)' ),
			array( 'Data', 'Tipo', 'Conto', 'Voce', 'Importo', 'Descrizione', 'N. tessera' ),
			array( array( 44630, 1 ), 'Entrata', $cash_name, 'Quota associativa 2021/2022', array( 10 ), 'Quota storica', array( 900 ) ),
			array( '15/04/2022', 'Uscita', 'Conto Storico 2022', 'Pulizie', array( '45.5' ), 'Sala', '' ),
			array( '02/05/2022', 'Giroconto in uscita', $cash_name, 'Giroconto', array( 100 ), 'Versamento', '' ),
			array( '02/05/2022', 'Giroconto in entrata', 'Conto Storico 2022', 'Giroconto', array( 100 ), 'Versamento', '' ),
		),
		'Note'        => array( array( 'Appunti del tesoriere' ), array( 'niente di importabile' ) ),
	),
	$xlsx
);
$bal0   = $balances();
$prev   = ImportService::preview_file( $xlsx, 'storico.xlsx', array( 'default_type' => 'ordinary' ) );
$pp     = $prev['people']['plan'];
$ll     = $prev['ledger'];
apse_ok( array( 'Note' ) === $prev['ignored'], 'import Excel: il foglio senza intestazioni riconoscibili è ignorato' );
apse_ok( 4 === count( $pp ) && array( 'create', 'create', 'create', 'create' ) === array_column( $pp, 'action' ), 'import Excel: soci e ospiti letti da due fogli, tutti nuovi' );
apse_ok( 'ordinary' === $pp[0]['type'] && 'volunteer' === $pp[1]['type'] && 'guest' === $pp[2]['type'] && '900' === $pp[0]['row']['card'], 'import Excel: tipi riconosciuti e tessera numerica letta come 900' );
apse_ok( 2 === $ll['summary']['counts']['create'] && 1 === $ll['summary']['counts']['transfer'] && 0 === $ll['summary']['counts']['error'], 'import Excel: prima nota letta (2 movimenti e un giroconto accoppiato)' );
apse_ok( '2022-03-10' === $ll['plan'][0]['data']['date'] && 1000 === $ll['plan'][0]['data']['cents'] && 'membership' === $ll['plan'][0]['data']['category_kind'], 'import Excel: data seriale di Excel, importo e voce riconosciuti (titolo sopra la tabella saltato)' );
apse_ok( 4550 === $ll['plan'][1]['data']['cents'] && 'Conto Storico 2022' === $ll['plan'][1]['data']['new_account'] && array( 'Conto Storico 2022' ) === $ll['summary']['new_accounts'], 'import Excel: conto inesistente = conto nuovo' );
apse_ok( $ll['plan'][0]['row']['line'] === 3, 'import Excel: gli errori indicano la riga vera del foglio' );
$tok = 'imp' . wp_generate_password( 6, false );
set_transient( 'apse_import_1_' . $tok, $prev, HOUR_IN_SECONDS );
$html = apse_render( array( Admin\ImportPage::class, 'render' ), 'Prima nota', array( 'token' => $tok ) );
apse_ok( false !== strpos( $html, 'Conto Storico 2022' ) && false !== strpos( $html, 'keep_balances' ) && false !== strpos( $html, 'Soci e ospiti' ), 'anteprima: riepilogo per conto, conto nuovo e opzione per non cambiare i saldi' );
delete_transient( 'apse_import_1_' . $tok );
apse_ok( false !== strpos( apse_render( array( Admin\ImportPage::class, 'render' ), 'Modelli CSV' ), 'accept=".xlsx' ), 'pagina di import: accetta file Excel' );

// applicazione
$res = ImportService::apply( $prev, array( 'keep_balances' => true ) );
apse_ok( 4 === $res['people']['created'] && array() === $res['people']['failed'], 'import: soci e ospiti creati' );
$ida   = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE card_number = '900'", ARRAY_A );
$otto  = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE card_number = '901'", ARRAY_A );
$gianni = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE first_name = 'Gianni' AND last_name = 'Storico'", ARRAY_A );
$gina   = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE first_name = 'Gina' AND last_name = 'Storica'", ARRAY_A );
apse_ok( 'ordinary' === $ida['type'] && '3331112222' === $ida['phone'] && (int) $ida['wp_user_id'] > 0 && 'volunteer' === $otto['type'], 'import: i soci hanno scheda e utente WordPress' );
apse_ok( 'guest' === $gianni['type'] && (int) $gianni['host_person_id'] === (int) $ida['id'] && (int) $gina['host_person_id'] === (int) $otto['id'], 'import: gli ospiti sono collegati al socio (per tessera e per email, anche se il socio è nello stesso file)' );
apse_ok( 2 === $res['ledger']['created'] && 1 === $res['ledger']['transfers'] && 1 === $res['ledger']['memberships'], 'import: movimenti, giroconto e iscrizione registrati' );
$bal1 = $balances();
apse_ok( $bal1[ $cash_name ] === $bal0[ $cash_name ] && 1 === $res['ledger']['shifted'], 'import storico: il saldo attuale della cassa non cambia (saldo iniziale aggiustato)' );
apse_ok( 5450 === $bal1['Conto Storico 2022'], 'import storico: il conto nuovo ha il saldo che risulta dai movimenti (−45,50 + 100,00)' );
$row = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'transactions' ) . " WHERE description = 'Quota storica'", ARRAY_A );
apse_ok( $row && '2022-03-10' === $row['tx_date'] && (int) $row['person_id'] === (int) $ida['id'] && $row['social_year'] === Settings::membership_year( '2022-03-10' )->label(), 'import: la quota storica è del socio e nell\'anno della tessera giusto' );
apse_ok( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = ' . (int) $ida['id'] . ' AND deleted_at IS NULL' ) >= 1, 'import: la quota storica registra l\'iscrizione di quell\'anno' );
$sy_2022 = $wpdb->get_var( $wpdb->prepare( 'SELECT valid_from FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = %d ORDER BY id LIMIT 1', (int) $ida['id'] ) );
apse_ok( $sy_2022 && $sy_2022 < '2023-01-01', 'import: l\'iscrizione ha la validità di quell\'anno sociale' );
apse_ok( 'Pulizie' !== (string) $wpdb->get_var( 'SELECT description FROM ' . Db::t( 'transactions' ) . " WHERE description LIKE '%Sala%' LIMIT 1" ) && false !== strpos( (string) $wpdb->get_var( 'SELECT description FROM ' . Db::t( 'transactions' ) . " WHERE description LIKE '%Sala%' LIMIT 1" ), '[Pulizie]' ), 'import: la voce non riconosciuta resta scritta nella descrizione' );
apse_ok( in_array( 'import.ledger', array_column( Audit::recent( 400 ), 'action' ), true ), 'registro azioni: import della prima nota tracciato' );

// secondo caricamento dello stesso file: nessun doppione
$tx_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) );
$prev2    = ImportService::preview_file( $xlsx, 'storico.xlsx', array() );
apse_ok( array( 'update', 'update', 'update', 'update' ) === array_column( $prev2['people']['plan'], 'action' ), 'secondo import: soci e ospiti già presenti si aggiornano, non si duplicano' );
apse_ok( 0 === $prev2['ledger']['summary']['counts']['create'] && 3 === $prev2['ledger']['summary']['counts']['duplicate'], 'secondo import: movimenti e giroconto già in prima nota sono riconosciuti come doppioni' );
$res2 = ImportService::apply( $prev2, array( 'keep_balances' => true ) );
apse_ok( 0 === $res2['people']['created'] && 4 === $res2['people']['updated'] && 0 === $res2['ledger']['created'] && $tx_count === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) ) && $balances()[ $cash_name ] === $bal0[ $cash_name ], 'secondo import: nulla di nuovo in prima nota e saldi invariati' );

// CSV (punto e virgola, intestazioni diverse) e conto predefinito; saldi che cambiano se non si chiede di mantenerli
$csv_p = $mkf( 'soci.csv', "Tessera;Nome;Cognome;Email\r\n902;Csv;Prova;csv.prova@example.com\r\n" );
$pv    = ImportService::preview_file( $csv_p, 'soci.csv', array() );
apse_ok( null === $pv['ledger'] && 'create' === $pv['people']['plan'][0]['action'], 'import CSV: i soci si leggono come prima' );
$csv_l = $mkf( 'cassa.csv', "Data;Importo;Descrizione\r\n01/06/2022;5,00;Offerta\r\n" );
$pv    = ImportService::preview_file( $csv_l, 'cassa.csv', array( 'default_account_id' => (int) $cash['id'] ) );
apse_ok( 'create' === $pv['ledger']['plan'][0]['action'] && 500 === $pv['ledger']['plan'][0]['data']['cents'] && ! empty( $pv['ledger']['plan'][0]['warnings'] ), 'import CSV: prima nota con il conto predefinito (voce mancante = voce generica con avviso)' );
$before = $balances()[ $cash_name ];
ImportService::apply( $pv, array( 'keep_balances' => false ) );
apse_ok( $balances()[ $cash_name ] === $before + 500, 'senza "non cambiare i saldi" il saldo segue i movimenti importati' );

// file non validi
apse_ok( false !== strpos( (string) apse_throws( function () use ( $csv_p ) { ImportService::preview_file( $csv_p, 'vecchio.xls', array() ); } ), '.xlsx' ), 'import: il vecchio .xls viene spiegato, non letto male' );
$junk = $mkf( 'rotto.xlsx', 'questo non è un file zip' );
apse_ok( null !== apse_throws( function () use ( $junk ) { ImportService::preview_file( $junk, 'rotto.xlsx', array() ); } ), 'import: un .xlsx rotto è rifiutato con un messaggio' );
$nothing = $mkf( 'altro.csv', "Colore;Forma\r\nrosso;tondo\r\n" );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $nothing ) { ImportService::preview_file( $nothing, 'altro.csv', array() ); } ), 'Non sono stati trovati' ), 'import: file senza soci né movimenti = messaggio con le colonne richieste' );

// ---------- WP All Import: area di appoggio ----------
apse_ok( defined( 'PMXI_VERSION' ) || class_exists( 'PMXI_Plugin' ), 'WP All Import è attivo insieme al plugin (nessun conflitto)' );
foreach ( array( WpAllImport::TYPE_LEDGER, WpAllImport::TYPE_PEOPLE ) as $pt ) {
	$o = get_post_type_object( $pt );
	apse_ok( $o && $o->show_ui && ! $o->public && array_key_exists( $pt, get_post_types( array( '_builtin' => false, 'show_ui' => true ) ) ), "WP All Import vede il tipo $pt tra quelli in cui importare" );
	apse_ok( Plugin::CAP === $o->cap->create_posts && ! user_can( $u_vol, $o->cap->create_posts ), "il tipo $pt lo gestisce solo chi amministra il plugin" );
}
apse_ok( has_action( 'pmxi_after_post_import' ), 'il plugin ascolta la fine di ogni importazione di WP All Import' );
$stg = function ( string $type, array $meta, int $author = 1 ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'riga di prova', 'post_author' => $author ) );
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	return $id;
};
$bal_w0 = $balances()[ $cash_name ];
$p_ok   = $stg( WpAllImport::TYPE_LEDGER, array( 'apse_date' => '2022-07-01', 'apse_income' => '20,00', 'apse_account' => $cash_name, 'apse_category' => 'Quota associativa', 'apse_card' => '902', 'apse_description' => 'WPAI quota' ) );
$p_bad  = $stg( WpAllImport::TYPE_LEDGER, array( 'apse_date' => 'boh', 'apse_amount' => '5', 'apse_account' => $cash_name ) );
$p_soc  = $stg( WpAllImport::TYPE_PEOPLE, array( 'apse_first_name' => 'Wanda', 'apse_last_name' => 'Pai', 'apse_email' => 'wanda.pai@example.com', 'apse_member_type' => 'volontario', 'apse_card_number' => '903' ) );
$p_osp  = $stg( WpAllImport::TYPE_PEOPLE, array( 'apse_first_name' => 'Osvaldo', 'apse_last_name' => 'Pai', 'apse_member_type' => 'ospite', 'apse_host' => '903', 'apse_phone' => '333 6666661' ) );
$p_ext  = $stg( WpAllImport::TYPE_LEDGER, array( 'apse_date' => '2022-07-02', 'apse_income' => '1,00', 'apse_account' => $cash_name ), (int) $u_vol );
apse_ok( 5 === WpAllImport::pending_count(), 'WP All Import: elementi in attesa contati' );
do_action( 'pmxi_after_post_import', 1 ); // come fa WP All Import a importazione finita
apse_ok( ! get_post( $p_ok ) && ! get_post( $p_soc ) && ! get_post( $p_osp ), 'WP All Import: gli elementi riusciti vengono registrati e tolti' );
apse_ok( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . " WHERE description = 'WPAI quota'" ), 'WP All Import: il movimento è in prima nota' );
$wanda = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE card_number = '903'", ARRAY_A );
$osv   = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE first_name = 'Osvaldo'", ARRAY_A );
apse_ok( $wanda && 'volunteer' === $wanda['type'] && $osv && (int) $osv['host_person_id'] === (int) $wanda['id'], 'WP All Import: socio e ospite collegato creati' );
apse_ok( $balances()[ $cash_name ] === $bal_w0, 'WP All Import: i saldi attuali non cambiano (impostazione predefinita)' );
apse_ok( get_post( $p_bad ) && 'error' === get_post_meta( $p_bad, 'apse_result', true ) && false !== strpos( (string) get_post_meta( $p_bad, 'apse_message', true ), 'Data' ), 'WP All Import: la riga sbagliata resta in coda con il motivo' );
apse_ok( get_post( $p_ext ) && 'error' === get_post_meta( $p_ext, 'apse_result', true ) && false !== strpos( (string) get_post_meta( $p_ext, 'apse_message', true ), 'autore' ), 'WP All Import: un elemento creato da chi non amministra il plugin non viene importato' );
$html = apse_render( array( Admin\WpAiPage::class, 'render' ), 'Elementi in coda' );
apse_ok( false !== strpos( $html, 'apse_amount' ) && false !== strpos( $html, 'apse_host' ) && false !== strpos( $html, 'Riprova quelli con errore' ), 'pagina WP All Import: campi da usare, impostazioni e coda con errori' );
apse_ok( 2 === WpAllImport::retry() && 2 === WpAllImport::pending_count(), 'WP All Import: riprova rimette in coda gli errori' );
WpAllImport::process();
apse_ok( 2 === WpAllImport::clear_errors() && 0 === WpAllImport::pending_count(), 'WP All Import: elimina gli elementi con errore' );
$set = new ReflectionMethod( Admin\Actions::class, 'save_wpai' );
$set->invoke( null, array( 'default_type' => 'volunteer', 'default_account_id' => (int) $cash['id'], 'mark_members' => '1' ) );
apse_ok( 'volunteer' === Settings::get( 'wpai_default_type' ) && 0 === (int) Settings::get( 'wpai_keep_balances' ) && 1 === (int) Settings::get( 'wpai_mark_members' ), 'WP All Import: impostazioni salvate' );
Settings::update( array( 'wpai_default_type' => 'ordinary', 'wpai_default_account_id' => 0, 'wpai_keep_balances' => 1, 'wpai_mark_members' => 0 ) );
// ---------- Annullamento in blocco di un import ----------
$undo_x = sys_get_temp_dir() . '/apse-undo-' . wp_generate_password( 6, false ) . '.xlsx';
$mk_xlsx(
	array(
		'Soci'       => array(
			array( 'Numero tessera', 'Tipo', 'Nome', 'Cognome', 'Email', 'Telefono' ),
			array( array( 950 ), 'ordinario', 'Uma', 'Annullabile', 'uma.annullabile@example.com', '' ),
			array( array( 951 ), 'ordinario', 'Ugo', 'Usato', 'ugo.usato@example.com', '' ),
			array( array( 900 ), 'ordinario', 'Ida', 'Storica', 'ida.storica@example.com', '3339990000' ),
		),
		'Ospiti'     => array(
			array( 'Tipo', 'Nome', 'Cognome', 'Ospite di', 'Cellulare' ),
			array( 'ospite', 'Ugo', 'Ospite', array( 950 ), '333 7777773' ),
		),
		'Prima nota' => array(
			array( 'Data', 'Tipo', 'Conto', 'Voce', 'Importo', 'Descrizione', 'N. tessera' ),
			array( '10/03/2021', 'Entrata', $cash_name, 'Quota associativa', array( 12 ), 'Quota Uma', array( 950 ) ),
			array( '11/03/2021', 'Uscita', 'Conto Annullabile', 'Pulizie', array( 7 ), 'Spesa annullabile', '' ),
			array( '12/03/2021', 'Giroconto in uscita', $cash_name, 'Giroconto', array( 5 ), 'Giro annullabile', '' ),
			array( '12/03/2021', 'Giroconto in entrata', 'Conto Annullabile', 'Giroconto', array( 5 ), 'Giro annullabile', '' ),
		),
	),
	$undo_x
);
$cash_row0  = $ledger->account( (int) $cash['id'] );
$bal_u0     = $balances();
$ida_before = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE card_number = '900'", ARRAY_A );
$pu         = ImportService::preview_file( $undo_x, 'undo.xlsx', array() );
$ru         = ImportService::apply( $pu, array( 'keep_balances' => true, 'mark_members' => true ) );
$bid        = (int) $ru['batch_id'];
$b0         = ImportService::batch( $bid );
apse_ok( $bid > 0 && 'undo.xlsx' === $b0['source'] && 3 === $b0['summary']['people_created'] && 1 === $b0['summary']['people_updated'] && 2 === $b0['summary']['transactions'] && 1 === $b0['summary']['transfers'], 'import: viene registrato con numero, origine e riepilogo' );
$tx_of = function ( int $b ) use ( $wpdb ) {
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . " WHERE import_batch = $b AND voided_at IS NULL" );
};
apse_ok( 4 === $tx_of( $bid ), 'import: i 4 movimenti (2 normali e le 2 righe del giroconto) portano il numero dell\'import' );
$ugo = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE card_number = '951'", ARRAY_A );
$ledger->record_expense( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'category_id' => $exp_cat, 'amount_cents' => 100, 'person_id' => (int) $ugo['id'] ) ); // da ora "Ugo Usato" è usato
apse_ok( '3339990000' === $wpdb->get_var( 'SELECT phone FROM ' . Db::t( 'people' ) . " WHERE card_number = '900'" ) && $balances()[ $cash_name ] === $bal_u0[ $cash_name ] - 100, 'prima dell\'annullamento: scheda aggiornata e saldo attuale invariato dall\'import' );
apse_ok( false !== strpos( apse_render( array( Admin\ImportPage::class, 'render' ), 'Import già fatti' ), 'Annulla questo import' ), 'pagina di import: elenco degli import con il pulsante di annullamento' );

$ru_undo = ImportService::undo( $bid );
apse_ok( 0 === $tx_of( $bid ) && $ru_undo['voided'] >= 3, 'annullamento: tutti i movimenti dell\'import sono annullati (giroconto compreso)' );
apse_ok( $balances()[ $cash_name ] === $bal_u0[ $cash_name ] - 100 && (int) $ledger->account( (int) $cash['id'] )['opening_cents'] === (int) $cash_row0['opening_cents'], 'annullamento: il saldo iniziale della cassa torna com\'era e il saldo attuale non cambia' );
apse_ok( ! array_key_exists( 'Conto Annullabile', $balances() ) && 1 === $ru_undo['accounts_removed'], 'annullamento: il conto creato dall\'import, rimasto vuoto, viene tolto' );
apse_ok( $ida_before['phone'] === $wpdb->get_var( 'SELECT phone FROM ' . Db::t( 'people' ) . " WHERE email = 'ida.storica@example.com'" ) && 1 === $ru_undo['people_restored'], 'annullamento: i dati del socio aggiornato tornano quelli di prima' );
apse_ok( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = ' . (int) $ida_before['id'] . ' AND social_year = \'' . esc_sql( Settings::membership_year()->label() ) . '\' AND deleted_at IS NULL' ), 'annullamento: l\'iscrizione segnata dall\'import viene tolta' );
$uma_del = $wpdb->get_var( 'SELECT deleted_at FROM ' . Db::t( 'people' ) . " WHERE email = 'uma.annullabile@example.com'" );
$osp_del = $wpdb->get_var( 'SELECT deleted_at FROM ' . Db::t( 'people' ) . " WHERE first_name = 'Ugo' AND last_name = 'Ospite'" );
apse_ok( $uma_del && $osp_del && 2 === $ru_undo['people_removed'], 'annullamento: socio e ospite creati e mai usati vengono rimossi (prima l\'ospite, poi il socio)' );
apse_ok( false === get_user_by( 'email', 'uma.annullabile@example.com' ) && $ru_undo['users_removed'] >= 1, 'annullamento: l\'utente WordPress nato con l\'import viene tolto' );
apse_ok( null === $wpdb->get_var( 'SELECT deleted_at FROM ' . Db::t( 'people' ) . " WHERE card_number = '951'" ) && 1 === count( $ru_undo['people_kept'] ) && false !== strpos( $ru_undo['people_kept'][0], 'Ugo Usato' ), 'annullamento: chi è già stato usato (qui ha un movimento) resta e viene segnalato' );
apse_ok( null !== ImportService::batch( $bid )['undone_at'] && null !== apse_throws( function () use ( $bid ) { ImportService::undo( $bid ); } ), 'annullamento: un import si annulla una volta sola' );
apse_ok( in_array( 'import.undone', array_column( Audit::recent( 400 ), 'action' ), true ), 'registro azioni: annullamento in blocco tracciato' );
apse_ok( false !== strpos( apse_render( array( Admin\ImportPage::class, 'render' ), 'Import già fatti' ), 'Annullato il' ), 'pagina di import: l\'import annullato risulta tale' );

// il tutto-o-niente: un errore a metà annulla anche le righe già scritte (le transazioni annidate non si "chiudono" a vicenda)
$n_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) );
try {
	$ledger->in_batch(
		function () use ( $ledger, $cash, $bank, $today ) {
			$ledger->record_transfer( $today, (int) $cash['id'], (int) $bank['id'], 100, 'cash', 'prova' );
			throw new \RuntimeException( 'errore a metà' );
		}
	);
} catch ( \RuntimeException $e ) {
	$n_after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) );
}
apse_ok( isset( $n_after ) && $n_after === $n_before, 'prima nota: se un\'operazione di gruppo fallisce a metà, non resta scritto nulla' );
$uuid = $ledger->record_transfer( $today, (int) $cash['id'], (int) $bank['id'], 100, 'cash', 'prova uuid' );
apse_ok( 36 === strlen( $uuid ), 'giroconto: restituisce il suo identificativo' );
$ledger->void( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Db::t( 'transactions' ) . ' WHERE transfer_id = %s AND type = \'transfer_out\'', $uuid ) ), 'prova' );
@unlink( $undo_x );

foreach ( array( $xlsx, $csv_p, $csv_l, $junk, $nothing ) as $f ) {
	@unlink( $f );
}

// ---------- QR della tessera e pagina di verifica ----------
wp_set_current_user( 1 );
$_SERVER['REQUEST_METHOD'] = 'GET';
apse_ok( 0 === (int) Settings::get( 'card_qr_enabled' ) && ! Settings::card_qr_enabled(), 'QR della tessera: spento di default' );
apse_ok( false === strpos( $as( $u_f, '[apsemplice_tessera]' ), '<svg' ), 'QR della tessera spento: la tessera digitale non lo mostra' );
apse_ok( 'disabled' === \ApSemplice\Frontend\CardVerify::result( \ApSemplice\CardToken::param( $founder, Settings::card_secret() ) )['status'] && false !== strpos( \ApSemplice\Frontend\CardVerify::page( 'boh' ), 'Verifica non attiva' ), 'QR della tessera spento: la pagina di verifica non è attiva' );
$save_card = new ReflectionMethod( Admin\Actions::class, 'save_card' );
$save_card->invoke( null, array( 'card_qr_enabled' => '1', 'ticket_qr_enabled' => '1', 'wallet_enabled' => '1' ) );
apse_ok( Settings::card_qr_enabled(), 'il gestore attiva il QR della tessera dalle impostazioni' );
$card_html = $as( $u_f, '[apsemplice_tessera]' );
apse_ok( false !== strpos( $card_html, '<svg' ) && false !== strpos( $card_html, 'apse_card=' ) && false !== strpos( $card_html, 'Mostra questo codice' ), 'tessera digitale: mostra il QR' . ( false === strpos( $card_html, '<svg' ) ? ' [' . substr( preg_replace( '/\s+/', ' ', $card_html ), 0, 400 ) . ']' : '' ) );
apse_ok( preg_match( '/apse_card=(\d+\.[a-f0-9]{20})/', $card_html, $qm ) === 1 && (int) explode( '.', $qm[1] )[0] === $founder, 'il QR contiene un codice firmato della tessera del socio, senza dati personali' );
$res = \ApSemplice\Frontend\CardVerify::result( $qm[1] );
apse_ok( 'valid' === $res['status'] && (int) $res['person']['id'] === $founder, 'verifica: il socio fondatore ha la tessera valida' );
$tina_param = \ApSemplice\CardToken::param( $tre_p, Settings::card_secret() );
apse_ok( 'expired' === \ApSemplice\Frontend\CardVerify::result( $tina_param )['status'], 'verifica: un socio senza tessera valida risulta non valido' );
$people->set_membership( $tre_p, Settings::social_year()->label(), true, 'manual' );
apse_ok( 'valid' === \ApSemplice\Frontend\CardVerify::result( $tina_param )['status'], 'verifica: lo stesso QR diventa valido appena la tessera si rinnova (verifica in diretta)' );
$people->suspend( $tre_p );
apse_ok( 'expired' === \ApSemplice\Frontend\CardVerify::result( $tina_param )['status'], 'verifica: un socio sospeso o uscito non ha la tessera valida, anche se non è scaduta' );
$people->reactivate( $tre_p );
apse_ok( 'valid' === \ApSemplice\Frontend\CardVerify::result( $tina_param )['status'], 'verifica: riattivato, la tessera torna valida' );
apse_ok( 'invalid' === \ApSemplice\Frontend\CardVerify::result( $founder . '.' . str_repeat( '0', 20 ) )['status'] && 'invalid' === \ApSemplice\Frontend\CardVerify::result( 'boh' )['status'] && 'invalid' === \ApSemplice\Frontend\CardVerify::result( ( $founder + 1 ) . '.' . explode( '.', $qm[1] )[1] )['status'], 'verifica: firma sbagliata, formato errato o firma di un altro socio = non valido' );
apse_ok( 'invalid' === \ApSemplice\Frontend\CardVerify::result( \ApSemplice\CardToken::param( $guest, Settings::card_secret() ) )['status'], 'verifica: gli ospiti non hanno tessera' );
$page = \ApSemplice\Frontend\CardVerify::page( $qm[1] );
apse_ok( false !== strpos( $page, 'Tessera valida' ) && false !== strpos( $page, 'Fulvia' ) && false === strpos( $page, 'example.com' ) && false !== strpos( $page, 'noindex' ), 'pagina di verifica: stato, nome e validità; nessuna email; non indicizzata' );
apse_ok( false !== strpos( \ApSemplice\Frontend\CardVerify::page( 'boh' ), 'QR non valido' ), 'pagina di verifica: codice non valido' );
// ---------- Biglietto QR delle prenotazioni (per singolo evento) ----------
wp_set_current_user( 1 );
$tev   = $mkev( 'Concerto con QR', 500, null, array( 'booking_qr' => 1 ) );
$tev_s = $first_session( $tev );
$acts->book( $tev_s, $founder );
$acts->book( $tev_s, $g_id );
apse_ok( 1 === (int) $acts->get( $tev )['booking_qr'] && 0 === (int) $acts->get( $paid_ev )['booking_qr'], 'evento: il biglietto QR è una scelta per singolo evento (spento di default)' );
$course_qr = $acts->create( array( 'name' => 'Corso senza biglietti', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 0, 'booking_qr' => 1 ) );
apse_ok( 0 === (int) $acts->get( $course_qr )['booking_qr'], 'i corsi non hanno biglietti QR (non hanno prenotazioni)' );
apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Biglietto QR', array( 'id' => $tev ) );
$mine = $as( $u_f, '[apsemplice_mie_attivita]' );
apse_ok( 1 === substr_count( $mine, '<summary>Biglietto QR</summary>' ) && false !== strpos( $mine, 'apse_ticket=' ), 'area soci: il biglietto QR compare solo per la prenotazione dell\'evento che lo prevede' );
apse_ok( preg_match( '/apse_ticket=(\d+\.\d+\.[a-f0-9]{20})/', $mine, $tm ) === 1, 'il QR contiene un codice firmato della prenotazione (data e persona), senza dati personali' );
$guest_html = $as( $u_f, '[apsemplice_ospiti]' );
apse_ok( false !== strpos( $guest_html, 'apse_ticket=' ) && false !== strpos( $guest_html, 'Concerto con QR' ), 'area soci: il socio trova anche i biglietti dei suoi ospiti' );
$t = \ApSemplice\Frontend\TicketVerify::result( $tm[1] );
apse_ok( 'valid' === $t['status'] && (int) $t['person']['id'] === $founder && $t['today'] === false, 'biglietto: prenotazione valida' );
$page = \ApSemplice\Frontend\TicketVerify::page( $tm[1] );
apse_ok( false !== strpos( $page, 'Prenotazione valida' ) && false !== strpos( $page, 'Concerto con QR' ) && false !== strpos( $page, 'Da versare' ) && false !== strpos( $page, 'altra data' ) && false === strpos( $page, 'example.com' ), 'biglietto: evento, data, contributo da versare e avviso se non è oggi; nessuna email' );
$pay( $founder, 500, $tev_s, $tev );
apse_ok( false !== strpos( \ApSemplice\Frontend\TicketVerify::page( $tm[1] ), 'Versato' ), 'biglietto: dopo il pagamento risulta versato (verifica in diretta)' );
apse_ok( 'invalid' === \ApSemplice\Frontend\TicketVerify::result( \ApSemplice\CardToken::ticket_param( $paid_s, $founder, Settings::card_secret() ) )['status'], 'biglietto: per un evento senza QR attivo il codice non vale' );
$other = explode( '.', $tm[1] );
apse_ok( 'invalid' === \ApSemplice\Frontend\TicketVerify::result( $other[0] . '.' . $g_id . '.' . $other[2] )['status'] && 'invalid' === \ApSemplice\Frontend\TicketVerify::result( 'boh' )['status'], 'biglietto: la firma di un\'altra persona o un formato errato non valgono' );
$acts->cancel_booking( $tev_s, $founder );
apse_ok( 'cancelled' === \ApSemplice\Frontend\TicketVerify::result( $tm[1] )['status'] && false !== strpos( \ApSemplice\Frontend\TicketVerify::page( $tm[1] ), 'Prenotazione annullata' ), 'biglietto: prenotazione annullata' );
$gt = \ApSemplice\CardToken::ticket_param( $tev_s, $g_id, Settings::card_secret() );
apse_ok( 'valid' === \ApSemplice\Frontend\TicketVerify::result( $gt )['status'] && false === strpos( $as( $u_f, '[apsemplice_mie_attivita]' ), '<summary>Biglietto QR</summary>' ), 'biglietto: la prenotazione annullata non mostra più il QR; quella dell\'ospite resta valida' );
$wpdb->update( Db::t( 'sessions' ), array( 'session_date' => gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ) ), array( 'id' => $tev_s ) );
apse_ok( 'past' === \ApSemplice\Frontend\TicketVerify::result( $gt )['status'] && false !== strpos( \ApSemplice\Frontend\TicketVerify::page( $gt ), 'Evento già svolto' ) && false === strpos( \ApSemplice\Frontend\TicketVerify::page( $gt ), 'Gia Ospite' ), 'biglietto: evento già svolto, e un vecchio QR non mostra più il nome di chi era' );
$wpdb->update( Db::t( 'sessions' ), array( 'session_date' => $today ), array( 'id' => $tev_s ) );
apse_ok( true === \ApSemplice\Frontend\TicketVerify::result( $gt )['today'] && false === strpos( \ApSemplice\Frontend\TicketVerify::page( $gt ), 'altra data' ), 'biglietto: nel giorno dell\'evento nessun avviso' );
// ---------- Gestori dell'evento, lista prenotati e registrazione degli ingressi ----------
wp_set_current_user( 1 );
$guest_row = $people->get( $g_id );
apse_ok( ! user_can( $u_tre, 'apse_manage_event', $tev ) && ! user_can( $uq, 'apse_manage_event', $tev ) && user_can( 1, 'apse_manage_event', $tev ), 'evento: all\'inizio lo gestiscono solo gli amministratori' );
$acts->add_staff( $tev, $tre_p );
apse_ok( user_can( $u_tre, 'apse_manage_event', $tev ) && ! user_can( $uq, 'apse_manage_event', $tev ) && ! user_can( $u_tre, 'apse_manage_event', $paid_ev ), 'gestore indicato: gestisce quell\'evento e non gli altri' );
apse_ok( array( $tre_p ) === array_map( function ( $m ) { return (int) $m['person_id']; }, $acts->staff( $tev ) ) && $acts->is_staff( $tev, $tre_p ) && in_array( $tev, $acts->managed_activity_ids( $tre_p ), true ), 'gestori dell\'evento: elenco e eventi gestiti' );
apse_ok( null !== apse_throws( function () use ( $acts, $tev, $tre_p ) { $acts->add_staff( $tev, $tre_p ); } ) && null !== apse_throws( function () use ( $acts, $tev, $guest ) { $acts->add_staff( $tev, $guest ); } ) && null !== apse_throws( function () use ( $acts, $corso, $vol ) { $acts->add_staff( $corso, $vol ); } ), 'gestori: non due volte, non gli ospiti, non per i corsi' );
apse_ok( ! user_can( $u_vol, 'apse_manage_event', $tev ), 'un volontario qualsiasi non gestisce un evento che non tiene' );
$acts->update( $tev, array( 'instructor_person_id' => $vol ) );
apse_ok( user_can( $u_vol, 'apse_manage_event', $tev ) && null !== apse_throws( function () use ( $acts, $tev, $vol ) { $acts->add_staff( $tev, $vol ); } ), 'l\'istruttore gestisce l\'evento per definizione' );

// area riservata: elenco degli eventi e lista dei prenotati
$list = $as( $u_tre, '[apsemplice_ingressi]' );
apse_ok( false !== strpos( $list, 'Concerto con QR' ) && false !== strpos( $list, 'oggi' ) && false !== strpos( $list, 'apse_session=' ), 'area riservata: il gestore vede i suoi eventi con le date (oggi evidenziato)' );
apse_ok( false !== strpos( $as( $uq, '[apsemplice_ingressi]' ), 'Non gestisci nessun evento' ) && false === strpos( $as( $uq, '[apsemplice_area_soci]' ), 'Ingressi agli eventi' ), 'area riservata: chi non gestisce eventi non vede la sezione' );
$_GET['apse_session'] = (string) $tev_s;
$det = $as( $u_tre, '[apsemplice_ingressi]' );
apse_ok( false !== strpos( $det, $guest_row['first_name'] ) && false !== strpos( $det, 'Registra ingresso' ) && false !== strpos( $det, 'data-apsf-scan' ) && false !== strpos( $det, 'Prenotati' ) && false !== strpos( $det, 'Da versare' ), 'lista prenotati: nomi, contributo, pulsante di ingresso e scansione del QR' );
apse_ok( false === strpos( $det, '@' ) && false === strpos( $det, 'mailto:' ) && false === strpos( $det, 'tel:' ), 'lista prenotati: nessun recapito (email o telefono)' );
unset( $_GET['apse_session'] );
$_GET['apse_session'] = (string) $paid_s;
apse_ok( false === strpos( $as( $u_tre, '[apsemplice_ingressi]' ), 'Cena sociale' ), 'un gestore non apre le liste di eventi che non gestisce' );
unset( $_GET['apse_session'] );

// registrazione ingressi
wp_set_current_user( $uq );
apse_ok( null !== apse_throws( function () use ( $front, $tev_s, $g_id ) { $front::do_checkin( array( 'session_id' => $tev_s, 'person_id' => $g_id ) ); } ), 'ingresso: chi non gestisce l\'evento non può registrarlo' );
wp_set_current_user( $u_tre );
$m1 = $front::do_checkin( array( 'session_id' => $tev_s, 'person_id' => $g_id ) );
$bk = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'bookings' ) . ' WHERE session_id = %d AND person_id = %d', $tev_s, $g_id ), ARRAY_A );
apse_ok( false !== strpos( $m1, 'Ingresso registrato' ) && false !== strpos( $m1, 'contributo da versare' ) && ! empty( $bk['checked_in_at'] ) && (int) $bk['checked_in_by'] === $u_tre, 'ingresso: registrato con ora e chi l\'ha fatto; avvisa se il contributo non è versato' );
apse_ok( false !== strpos( $front::do_checkin( array( 'session_id' => $tev_s, 'person_id' => $g_id ) ), 'già registrato' ), 'ingresso: la seconda volta dice che era già registrato' );
$tp = \ApSemplice\Frontend\TicketVerify::result( $gt );
apse_ok( 'used' === $tp['status'] && false !== strpos( \ApSemplice\Frontend\TicketVerify::page( $gt ), 'Ingresso già registrato' ), 'biglietto: dopo l\'ingresso il QR risulta già usato (una copia del QR non entra due volte)' );
$ps = \ApSemplice\Frontend\TicketVerify::page( $gt );
wp_set_current_user( 0 );
apse_ok( false === strpos( \ApSemplice\Frontend\TicketVerify::page( $gt ), 'apse_front_checkin' ), 'pagina del biglietto: senza accesso non ci sono comandi' );
wp_set_current_user( $uq );
apse_ok( false === strpos( \ApSemplice\Frontend\TicketVerify::page( $gt ), 'apse_front_checkin' ), 'pagina del biglietto: un socio che non gestisce l\'evento non ha comandi' );
wp_set_current_user( $u_tre );
apse_ok( false !== strpos( \ApSemplice\Frontend\TicketVerify::page( $gt ), 'Annulla la registrazione' ) && false !== strpos( \ApSemplice\Frontend\TicketVerify::page( $gt, array( 'ok' => 'Ingresso registrato: prova' ) ), 'Ingresso registrato: prova' ), 'pagina del biglietto: il gestore può annullare la registrazione e vede l\'esito dell\'azione' );
apse_ok( false !== strpos( $front::do_checkin( array( 'session_id' => $tev_s, 'person_id' => $g_id, 'undo' => '1' ) ), 'annullata' ) && empty( $wpdb->get_var( $wpdb->prepare( 'SELECT checked_in_at FROM ' . Db::t( 'bookings' ) . ' WHERE session_id = %d AND person_id = %d', $tev_s, $g_id ) ) ), 'ingresso: la registrazione si può annullare' );
apse_ok( 'valid' === \ApSemplice\Frontend\TicketVerify::result( $gt )['status'] && false !== strpos( \ApSemplice\Frontend\TicketVerify::page( $gt ), 'Registra ingresso' ), 'biglietto: annullata la registrazione torna valido, con il comando per il gestore' );

// ingresso da QR scansionato
$m2 = $front::do_checkin_scan( array( 'ticket' => Settings::ticket_url( $tev_s, $g_id ) ) );
apse_ok( false !== strpos( $m2, 'Ingresso registrato' ), 'ingresso da QR: l\'indirizzo letto dal QR registra l\'ingresso' );
$front::do_checkin( array( 'session_id' => $tev_s, 'person_id' => $g_id, 'undo' => '1' ) );
apse_ok( false !== strpos( $front::do_checkin_scan( array( 'ticket' => $gt ) ), 'Ingresso registrato' ), 'ingresso da QR: anche il solo codice' );
$front::do_checkin( array( 'session_id' => $tev_s, 'person_id' => $g_id, 'undo' => '1' ) );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front ) { $front::do_checkin_scan( array( 'ticket' => 'https://example.org/?apse_ticket=1.2.' . str_repeat( 'a', 20 ) ) ); } ), 'non valido' ) && null !== apse_throws( function () use ( $front ) { $front::do_checkin_scan( array( 'ticket' => '' ) ); } ), 'ingresso da QR: un QR di altro tipo o falso è rifiutato' );
$foreign = \ApSemplice\CardToken::ticket_param( $paid_s, $founder, Settings::card_secret() );
apse_ok( null !== apse_throws( function () use ( $front, $foreign ) { $front::do_checkin_scan( array( 'ticket' => $foreign ) ); } ), 'ingresso da QR: un biglietto di un evento che non gestisci è rifiutato' );

// giorno dell'evento, prenotazioni annullate
$wpdb->update( Db::t( 'sessions' ), array( 'session_date' => gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) ) ), array( 'id' => $tev_s ) );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front, $tev_s, $g_id ) { $front::do_checkin( array( 'session_id' => $tev_s, 'person_id' => $g_id ) ); } ), 'giorno dell\'evento' ), 'ingresso: si registra nel giorno dell\'evento' );
apse_ok( false === strpos( \ApSemplice\Frontend\TicketVerify::page( $gt ), 'apse_front_checkin' ) && false !== strpos( \ApSemplice\Frontend\TicketVerify::page( $gt ), 'giorno dell\'evento' ), 'pagina del biglietto: un altro giorno il gestore non ha il comando' );
wp_set_current_user( 1 );
apse_ok( false !== strpos( $front::do_checkin( array( 'session_id' => $tev_s, 'person_id' => $g_id ) ), 'Ingresso registrato' ), 'ingresso: l\'amministratore può registrarlo anche in un altro giorno' );
$front::do_checkin( array( 'session_id' => $tev_s, 'person_id' => $g_id, 'undo' => '1' ) );
$wpdb->update( Db::t( 'sessions' ), array( 'session_date' => $today ), array( 'id' => $tev_s ) );
apse_ok( null !== apse_throws( function () use ( $acts, $tev_s, $founder ) { $acts->check_in( $tev_s, $founder ); } ), 'ingresso: una prenotazione annullata non entra' );
apse_ok( 'none' === $acts->check_in( $tev_s, $g_id, true )['status'], 'ingresso: annullare chi non è entrato non fa nulla' );

// amministrazione
$adm = apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Staff dell\'evento', array( 'id' => $tev ) );
apse_ok( false !== strpos( $adm, 'Tina' ) && false !== strpos( $adm, '<th>Ingresso</th>' ) && false !== strpos( $adm, 'apse_checkin' ), 'amministrazione: gestori dell\'evento e colonna degli ingressi' );
$adm_checkin = new ReflectionMethod( Admin\Actions::class, 'checkin' );
$r1          = $adm_checkin->invoke( null, array( 'activity_id' => $tev, 'session_id' => $tev_s, 'person_id' => $g_id ) );
apse_ok( 'Ingresso registrato.' === $r1[1] && ! empty( $wpdb->get_var( $wpdb->prepare( 'SELECT checked_in_at FROM ' . Db::t( 'bookings' ) . ' WHERE session_id = %d AND person_id = %d', $tev_s, $g_id ) ) ), 'amministrazione: registra l\'ingresso' );
$adm_checkin->invoke( null, array( 'activity_id' => $tev, 'session_id' => $tev_s, 'person_id' => $g_id, 'undo' => 1 ) );
$staff_rm = new ReflectionMethod( Admin\Actions::class, 'event_staff_remove' );
$staff_rm->invoke( null, array( 'activity_id' => $tev, 'person_id' => $tre_p ) );
apse_ok( ! $acts->is_staff( $tev, $tre_p ) && ! user_can( $u_tre, 'apse_manage_event', $tev ), 'amministrazione: tolto il gestore, perde subito il permesso' );
$acts->add_staff( $tev, $tre_p );
$aud = array_column( Audit::recent( 500 ), 'action' );
apse_ok( in_array( 'checkin.recorded', $aud, true ) && in_array( 'checkin.undone', $aud, true ) && in_array( 'event_staff.added', $aud, true ) && in_array( 'event_staff.removed', $aud, true ), 'registro azioni: ingressi e gestori tracciati' );

// ---------- Ingresso senza prenotazione (sul posto) ----------
wp_set_current_user( 1 );
$walk   = new ReflectionMethod( Admin\Actions::class, 'walk_in' );
$wi_ev  = $mkev( 'Serata sul posto', 600, 900 );
$wi_s   = $first_session( $wi_ev );
$income = function ( int $session, ?int $person = null ) use ( $wpdb ) {
	return (int) $wpdb->get_var( 'SELECT COALESCE(SUM(amount_cents),0) FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND session_id = $session" . ( $person ? " AND person_id = $person" : '' ) );
};
$base = array( 'activity_id' => $wi_ev, 'session_id' => $wi_s, 'method' => 'cash', 'account_id' => (int) $cash['id'], 'pay' => '1', 'checkin' => '1' );
$r1   = $walk->invoke( null, array_merge( $base, array( 'person_id' => $q ) ) );
$b1   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'bookings' ) . ' WHERE session_id = %d AND person_id = %d', $wi_s, $q ), ARRAY_A );
apse_ok( 'booked' === $b1['status'] && ! empty( $b1['checked_in_at'] ) && 600 === $income( $wi_s, $q ) && false !== strpos( $r1[1], 'incassati' ) && false !== strpos( $r1[1], 'ingresso registrato' ), 'sul posto: un socio senza prenotazione viene prenotato, incassato (6,00) e fatto entrare in un colpo solo' );
$r2 = $walk->invoke( null, array_merge( $base, array( 'new_first_name' => 'Walter', 'new_last_name' => 'Sulposto', 'new_phone' => '333 8888881', 'host_person_id' => $founder ) ) );
$wg = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE first_name = 'Walter' AND last_name = 'Sulposto'", ARRAY_A );
apse_ok( $wg && 'guest' === $wg['type'] && (int) $wg['host_person_id'] === $founder && 900 === $income( $wi_s, (int) $wg['id'] ) && ! empty( $wpdb->get_var( $wpdb->prepare( 'SELECT checked_in_at FROM ' . Db::t( 'bookings' ) . ' WHERE session_id = %d AND person_id = %d', $wi_s, (int) $wg['id'] ) ) ), 'sul posto: un nuovo ospite viene creato, collegato al socio, incassato col contributo ospiti (9,00) e fatto entrare' );
$r3  = $walk->invoke( null, array( 'activity_id' => $wi_ev, 'session_id' => $wi_s, 'person_id' => $tre_p, 'checkin' => '1' ) );
apse_ok( 0 === $income( $wi_s, $tre_p ) && false === strpos( $r3[1], 'incassati' ) && false !== strpos( $r3[1], 'ingresso registrato' ), 'sul posto: senza incasso il contributo resta da versare (lo si vede nell\'elenco)' );
$r4 = $walk->invoke( null, array_merge( $base, array( 'person_id' => $q ) ) );
apse_ok( false !== strpos( $r4[1], 'nulla da incassare' ) && 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " WHERE session_id = $wi_s AND person_id = $q" ) && 600 === $income( $wi_s, $q ), 'sul posto: chi era già prenotato e ha pagato non paga né si prenota due volte' );

// tutto o niente
$n_people = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) );
apse_ok( null !== apse_throws( function () use ( $walk, $base ) { $walk->invoke( null, $base ); } ) && null !== apse_throws( function () use ( $walk, $base ) { $walk->invoke( null, array_merge( $base, array( 'new_first_name' => 'Senza', 'new_last_name' => 'Ospitante' ) ) ); } ), 'sul posto: serve una persona, e un nuovo ospite ha bisogno del socio che lo ospita' );
$fresh = $people->create( array( 'type' => 'guest', 'first_name' => 'Fabio', 'last_name' => 'Fresco', 'phone' => '333 4444445', 'host_person_id' => $founder ) );
apse_ok( null !== apse_throws( function () use ( $walk, $base, $fresh ) { $walk->invoke( null, array_merge( $base, array( 'person_id' => $fresh, 'account_id' => 0 ) ) ); } ) && ! $acts->has_active_booking( $wi_s, $fresh ) && $n_people + 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) ), 'sul posto: se l\'incasso non va (conto non valido) non resta nemmeno la prenotazione' );
$few   = $mkev( 'Pochi posti', 0, null, array( 'session' => array( 'session_date' => gmdate( 'Y-m-d', strtotime( $today . ' +5 days' ) ), 'capacity' => 1 ) ) );
$few_s = $first_session( $few );
$walk->invoke( null, array( 'activity_id' => $few, 'session_id' => $few_s, 'person_id' => $q ) );
$n_people = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $walk, $few, $few_s, $founder ) { $walk->invoke( null, array( 'activity_id' => $few, 'session_id' => $few_s, 'new_first_name' => 'Troppi', 'new_last_name' => 'Ospiti', 'new_phone' => '333 8888882', 'host_person_id' => $founder ) ); } ), 'esauriti' ) && $n_people === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) ), 'sul posto: con i posti esauriti non si prenota e il nuovo ospite non viene creato' );
apse_ok( null !== apse_throws( function () use ( $walk, $wi_ev, $few_s, $q ) { $walk->invoke( null, array( 'activity_id' => $wi_ev, 'session_id' => $few_s, 'person_id' => $q ) ); } ), 'sul posto: la data deve essere di quell\'evento' );
apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Ingresso senza prenotazione', array( 'id' => $wi_ev ) );
apse_ok( 3 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " WHERE session_id = $wi_s AND status = 'booked'" ), 'sul posto: i prenotati sono tre, senza doppioni né residui dei tentativi falliti' );

$old_url = Settings::card_url( $founder );
Settings::regenerate_card_salt();
apse_ok( Settings::card_url( $founder ) !== $old_url && 'invalid' === \ApSemplice\Frontend\CardVerify::result( $qm[1] )['status'], 'QR rigenerati: i vecchi smettono di funzionare' );
apse_ok( 'valid' === \ApSemplice\Frontend\CardVerify::result( explode( 'apse_card=', Settings::card_url( $founder ) )[1] )['status'], 'QR rigenerati: i nuovi funzionano' );
$matrix = \ApSemplice\QrCode::matrix( Settings::card_url( $founder ) );
apse_ok( count( $matrix ) >= 29 && count( $matrix ) <= 57, 'il QR dell\'indirizzo di verifica ha dimensioni ragionevoli' );
apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'QR della tessera', array( 'id' => $founder ) );
apse_render( array( Admin\CardPage::class, 'render' ), 'Rigenera tutti i QR' );

// ---------- Tessera nel wallet: Apple Wallet e Google Wallet ----------
wp_set_current_user( 1 );
$_SERVER['REQUEST_METHOD'] = 'GET';
$mk_cert = function ( string $cn ) {
	$pk  = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
	$csr = openssl_csr_new( array( 'commonName' => $cn, 'organizationalUnitName' => 'TEAM123456' ), $pk, array( 'digest_alg' => 'sha256' ) );
	$x   = openssl_csr_sign( $csr, null, $pk, 365, array( 'digest_alg' => 'sha256' ) );
	openssl_x509_export( $x, $cert );
	openssl_pkey_export( $pk, $key );
	return array( $cert, $key, $x, $pk );
};
$read_pkpass = function ( string $bin ) {
	$tmp = tempnam( sys_get_temp_dir(), 'apsepk' );
	file_put_contents( $tmp, $bin );
	$zip = new ZipArchive();
	$zip->open( $tmp );
	$out = array( 'pass' => json_decode( $zip->getFromName( 'pass.json' ), true ), 'files' => array() );
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$out['files'][] = $zip->getNameIndex( $i );
	}
	$zip->close();
	unlink( $tmp );
	return $out;
};
$founder_p = $people->get( $founder );
apse_ok( null === Wallet::apple_config() && null === Wallet::google_config() && '' === Wallet::buttons( $founder_p ), 'wallet: non configurato di default, nessun pulsante' );

// Apple
list( $a_cert, $a_key, $a_x, $a_pk ) = $mk_cert( 'Pass Type ID: pass.test.apse' );
list( $wwdr_cert )                   = $mk_cert( 'Apple Worldwide Developer Relations' );
openssl_pkcs12_export( $a_x, $p12, $a_pk, 'pw' );
preg_match( '/-----BEGIN CERTIFICATE-----(.+)-----END CERTIFICATE-----/s', $wwdr_cert, $wm );
$wwdr_der = base64_decode( $wm[1] );
apse_ok( null !== apse_throws( function () use ( $p12 ) { Wallet::save_apple( array( 'password' => 'sbagliata' ), $p12, null ); } ) && null !== apse_throws( function () { Wallet::save_apple( array(), null, null ); } ) && null !== apse_throws( function () { Wallet::save_apple( array(), null, 'non un certificato' ); } ), 'Apple Wallet: password sbagliata, nulla da salvare o certificato WWDR non valido sono rifiutati' );
$msg = Wallet::save_apple( array( 'password' => 'pw' ), $p12, $wwdr_der );
$acfg = Wallet::apple_config();
apse_ok( $acfg && 'pass.test.apse' === $acfg['pass_type'] && 'TEAM123456' === $acfg['team'] && false !== strpos( $msg, 'certificato caricato' ), 'Apple Wallet: dal file .p12 si ricavano Pass Type ID e Team ID, e il WWDR (anche in formato .cer binario) viene letto' );
$raw = (string) wp_json_encode( get_option( Settings::OPTION ) );
apse_ok( Settings::has_secret( 'wallet_apple_key_pem' ) && false === strpos( $raw, 'PRIVATE KEY' ) && false === strpos( wp_json_encode( Audit::recent( 400 ) ), 'PRIVATE KEY' ), 'Apple Wallet: la chiave privata è cifrata nel database e non finisce nel registro' );

// tessera Apple: socio fondatore (sempre rinnovata) e socio con scadenza
$pk1 = $read_pkpass( Wallet::apple_pass( $founder ) );
$f1 = $pk1['files'];sort( $f1 );apse_ok( array( 'icon.png', 'icon@2x.png', 'icon@3x.png', 'manifest.json', 'pass.json', 'signature' ) === $f1, 'tessera Apple: contiene pass.json, icone, manifesto e firma' );
apse_ok( 'apse-' . $founder === $pk1['pass']['serialNumber'] && 'pass.test.apse' === $pk1['pass']['passTypeIdentifier'] && 'Sempre rinnovata' === $pk1['pass']['generic']['auxiliaryFields'][0]['value'] && ! isset( $pk1['pass']['expirationDate'] ), 'tessera Apple: dati del socio fondatore, senza scadenza' );
apse_ok( 'PKBarcodeFormatQR' === $pk1['pass']['barcodes'][0]['format'] && false !== strpos( $pk1['pass']['barcodes'][0]['message'], 'apse_card=' ), 'tessera Apple: con il QR della tessera attivo contiene il suo codice di verifica' );
$pk2 = $read_pkpass( Wallet::apple_pass( $tre_p ) );
apse_ok( ! empty( $pk2['pass']['expirationDate'] ) && preg_match( '#^\d{2}/\d{2}/\d{4}$#', $pk2['pass']['generic']['auxiliaryFields'][0]['value'] ), 'tessera Apple: un socio con scadenza ha la data di scadenza' );
Settings::update( array( 'card_qr_enabled' => 0 ) );
apse_ok( ! isset( $read_pkpass( Wallet::apple_pass( $founder ) )['pass']['barcodes'] ), 'tessera Apple: con il QR della tessera spento non c\'è alcun codice' );
Settings::update( array( 'card_qr_enabled' => 1 ) );
apse_ok( null !== apse_throws( function () use ( $guest ) { Wallet::apple_pass( $guest ); } ), 'gli ospiti non hanno tessera nel wallet' );

// pulsanti nell'area soci e permessi di scarico
$html = $as( $u_f, '[apsemplice_tessera]' );
apse_ok( false !== strpos( $html, 'Aggiungi ad Apple Wallet' ) && false !== strpos( $html, 'apse_wallet_apple' ) && false !== strpos( $html, '_wpnonce=' ), 'area soci: pulsante per Apple Wallet con controllo dei permessi' );
apse_ok( user_can( $u_f, 'apse_view_person', $founder ) && ! user_can( $u_tre, 'apse_view_person', $founder ) && ( \ApSemplice\Plugin::load_for_action( 'apse_wallet_apple' ) && has_action( 'admin_post_apse_wallet_apple' ) ), 'la tessera Apple si scarica solo per sé stessi (o da amministratore)' );

// Google
list( , $g_key ) = $mk_cert( 'google' );
$gjson = wp_json_encode( array( 'client_email' => 'wallet@progetto.iam.gserviceaccount.com', 'private_key' => $g_key ) );
apse_ok( null !== apse_throws( function () { Wallet::save_google( array( 'issuer' => 'abc' ), null ); } ) && null !== apse_throws( function () { Wallet::save_google( array(), '{"x":1}' ); } ), 'Google Wallet: ID emittente non numerico o JSON senza chiave sono rifiutati' );
Wallet::save_google( array( 'issuer' => '3388000000012345678' ), $gjson );
$gurl = Wallet::google_url( $founder );
$jwt  = substr( $gurl, strlen( 'https://pay.google.com/gp/v/save/' ) );
list( $jh, $jp, $js ) = explode( '.', $jwt );
$gpub = openssl_pkey_get_details( openssl_pkey_get_private( $g_key ) )['key'];
$gcl  = json_decode( \ApSemplice\GoogleWallet::b64url_decode( $jp ), true );
apse_ok( 0 === strpos( $gurl, 'https://pay.google.com/gp/v/save/' ) && 1 === openssl_verify( $jh . '.' . $jp, \ApSemplice\GoogleWallet::b64url_decode( $js ), $gpub, OPENSSL_ALGO_SHA256 ), 'Google Wallet: indirizzo di salvataggio con JWT firmato (RS256)' );
apse_ok( 'savetowallet' === $gcl['typ'] && 'wallet@progetto.iam.gserviceaccount.com' === $gcl['iss'] && false !== strpos( $gcl['payload']['genericObjects'][0]['barcode']['value'], 'apse_card=' ), 'Google Wallet: emittente, tipo e QR della tessera' );
apse_ok( Settings::has_secret( 'wallet_google_key_pem' ) && false === strpos( wp_json_encode( get_option( Settings::OPTION ) ), 'PRIVATE KEY' ), 'Google Wallet: la chiave è cifrata nel database' );
$html = $as( $u_f, '[apsemplice_tessera]' );
apse_ok( false !== strpos( $html, 'Salva su Google Wallet' ) && false !== strpos( $html, 'pay.google.com/gp/v/save/' ), 'area soci: pulsante per Google Wallet' );

// pagina di amministrazione
$adm = apse_render( array( Admin\CardPage::class, 'render' ), 'Apple Wallet', array( 'test_person' => (string) $founder ) );
apse_ok( false !== strpos( $adm, 'Google Wallet' ) && false !== strpos( $adm, 'Configurato' ) && false !== strpos( $adm, 'pass.test.apse' ) && false !== strpos( $adm, 'Scarica la tessera per Apple Wallet' ) && false === strpos( $adm, 'PRIVATE KEY' ), 'pagina Tessera e Wallet: stato, prova con un socio e nessuna chiave in chiaro' );
apse_ok( in_array( 'wallet.apple_saved', array_column( Audit::recent( 400 ), 'action' ), true ) && in_array( 'wallet.google_saved', array_column( Audit::recent( 400 ), 'action' ), true ), 'registro azioni: configurazione dei wallet tracciata' );

// rimozione
Wallet::clear( 'apple' );
apse_ok( null === Wallet::apple_config() && ! Settings::has_secret( 'wallet_apple_key_pem' ) && false === strpos( $as( $u_f, '[apsemplice_tessera]' ), 'Apple Wallet' ) && false !== strpos( $as( $u_f, '[apsemplice_tessera]' ), 'Google Wallet' ), 'Apple Wallet rimosso: via la chiave e il pulsante' );
Wallet::clear( 'google' );
apse_ok( null === Wallet::google_config() && '' === Wallet::buttons( $founder_p ), 'Google Wallet rimosso: nessun pulsante' );

// ---------- Ospiti: nessun limite automatico, ma chi gestisce li "intercetta" ----------
wp_set_current_user( 1 );
$_SERVER['REQUEST_METHOD'] = 'GET';
apse_ok( 2 === (int) Settings::defaults()['guest_max_events'], 'ospiti: la soglia di segnalazione è 2 di default' );
Settings::update( array( 'guest_max_events' => 2 ) );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $people, $founder ) { $people->create( array( 'type' => 'guest', 'first_name' => 'Senza', 'last_name' => 'Cellulare', 'host_person_id' => $founder ) ); } ), 'cellulare' ) && null !== apse_throws( function () use ( $people, $founder ) { $people->create( array( 'type' => 'guest', 'first_name' => 'Cellulare', 'last_name' => 'Brutto', 'phone' => '123', 'host_person_id' => $founder ) ); } ), 'ospiti: il cellulare è obbligatorio (e deve essere un numero)' );
$gx = $people->create( array( 'type' => 'guest', 'first_name' => 'Olga', 'last_name' => 'Occasionale', 'phone' => '333 5555551', 'host_person_id' => $founder ) );
$e1 = $mkev( 'Open day yoga', 0, null, array(), 10 );
$e2 = $mkev( 'Open day teatro', 0, null, array(), 12 );
$e3 = $mkev( 'Presentazione della stagione', 0, null, array(), 14 );
$e4 = $mkev( 'Gita di primavera', 0, null, array(), 16 );
list( $gs1, $gs2, $gs3, $gs4 ) = array( $first_session( $e1 ), $first_session( $e2 ), $first_session( $e3 ), $first_session( $e4 ) );
$acts->book( $gs1, $gx );
$acts->book( $gs2, $gx );
apse_ok( 2 === $acts->guest_status( $gx )['count'] && $acts->guest_status( $gx )['flagged'], 'ospite: raggiunta la soglia (2) risulta segnalato' );
$acts->book( $gs3, $gx );
apse_ok( 3 === $acts->guest_status( $gx )['count'] && $acts->has_active_booking( $gs3, $gx ), 'nessun blocco: l\'ospite può prenotare anche oltre la soglia (decide chi gestisce)' );
$ov = $people->guest_overview()[ $gx ];
apse_ok( 3 === $ov['count'] && 3 === $ov['total'] && $ov['flag'] && array() === $ov['twins'], 'quadro ospiti: partecipazioni, totale e segnalazione' );
$st = $acts->guest_status( $gx );
apse_ok( array( 'Presentazione della stagione', 'Open day teatro', 'Open day yoga' ) === array_column( $st['items'], 'activity_name' ), 'ospite: l\'elenco dice a cosa è già venuto (la più recente per prima)' );
$acts->cancel_booking( $gs3, $gx );
apse_ok( 2 === $acts->guest_status( $gx )['count'], 'ospite: una prenotazione annullata non conta' );
$acts->book( $gs4, $founder );
$acts->transfer_booking( $gs4, $founder, $gx );
apse_ok( 3 === $acts->guest_status( $gx )['count'], 'ospite: anche il cambio di nominativo verso di lui è consentito e si conta' );
$acts->transfer_booking( $gs4, $gx, $founder );

// corsi
$gy   = $people->create( array( 'type' => 'guest', 'first_name' => 'Pino', 'last_name' => 'Provino', 'phone' => '333 5555552', 'host_person_id' => $founder ) );
$cors = $acts->create( array( 'name' => 'Corso di prova ospiti', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 0 ) );
$acts->enroll( $cors, $gy, $month );
$acts->enroll( $cors, $gy, $month );
apse_ok( 1 === $acts->guest_status( $gy )['count'], 'ospite: l\'iscrizione a un corso conta come partecipazione, riattivarla non conta due volte' );

// il "furbo": lo stesso ospite registrato da soci diversi (stesso cellulare scritto in modo diverso, o stesso nome)
$twin_phone = $people->create( array( 'type' => 'guest', 'first_name' => 'Pinuccio', 'last_name' => 'Provini', 'phone' => '+39 333-555 5552', 'host_person_id' => $tre_p ) );
$acts->book( $gs1, $twin_phone );
$other_host = $people->create( array( 'type' => 'guest', 'first_name' => 'Gianni', 'last_name' => 'Altrui', 'phone' => '333 5555554', 'host_person_id' => $tre_p ) );
$twin_name  = $people->create( array( 'type' => 'guest', 'first_name' => 'PINO', 'last_name' => 'provino', 'phone' => '347 0000000', 'host_person_id' => $tre_p ) );
$gov2 = $people->guest_overview();
apse_ok( 1 === $gov2[ $gy ]['count'] && 2 === $gov2[ $gy ]['total'] && 2 === count( $gov2[ $gy ]['twins'] ), 'furbi: le registrazioni gemelle (stesso cellulare, stesso nome) si sommano' );
apse_ok( $gov2[ $gy ]['flag'] && $gov2[ $twin_phone ]['flag'], 'furbi: ognuno ha una sola partecipazione, ma insieme raggiungono la soglia e vengono segnalati' );
$why = array();
foreach ( $gov2[ $gy ]['twins'] as $t ) {
	$why[ $t['id'] ] = implode( ',', array_unique( $t['why'] ) );
}
apse_ok( false !== strpos( (string) ( $why[ $twin_phone ] ?? '' ), 'stesso cellulare' ) && false !== strpos( (string) ( $why[ $twin_name ] ?? '' ), 'stesso nome' ), 'furbi: il motivo (stesso cellulare, stesso nome) è indicato' );
apse_ok( 2 === count( $people->find_by_phone( '333-5555552' ) ) && 1 === count( $people->find_by_phone( '+39 3335555551' ) ) && array() === $people->find_by_phone( 'boh' ), 'ricerca per cellulare: scritto in qualunque modo' );
$ids_to_invite = array_map( function ( $g ) { return (int) $g['id']; }, $people->guests_to_invite() );
apse_ok( in_array( $gx, $ids_to_invite, true ) && in_array( $gy, $ids_to_invite, true ) && in_array( $twin_phone, $ids_to_invite, true ), 'ospiti da invitare: elenco per chi gestisce' );

// visibilità
$html = apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Partecipazioni come ospite', array( 'id' => $gy ) );
apse_ok( false !== strpos( $html, 'Potrebbe essere la stessa persona' ) && false !== strpos( $html, 'Pinuccio' ) && false !== strpos( $html, 'stesso cellulare' ) && false !== strpos( $html, 'Corso di prova ospiti' ) && false !== strpos( $html, 'wa.me/393335555552' ) && false !== strpos( $html, 'Iscrivi come socio' ), 'scheda ospite: a cosa è venuto, le registrazioni gemelle, WhatsApp e iscrizione come socio' );
$gz   = $people->create( array( 'type' => 'guest', 'first_name' => 'Zeno', 'last_name' => 'Zerovolte', 'phone' => '333 5555553', 'host_person_id' => $founder ) );
$list = apse_render( array( Admin\PeoplePage::class, 'render_list' ), 'Occasionale', array( 'type' => 'guest', 'at_limit' => '1' ) );
apse_ok( false !== strpos( $list, 'da invitare a iscriversi' ) && false !== strpos( $list, 'Provino' ) && false === strpos( $list, 'Zerovolte' ), 'elenco persone: filtro "ospiti da invitare a iscriversi"' );
$ev = apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Open day yoga', array( 'id' => $e1 ) );
apse_ok( false !== strpos( $ev, 'registrato anche come' ) && false !== strpos( $ev, 'da invitare a iscriversi' ), 'pagina evento: accanto a ogni ospite le partecipazioni e se risulta registrato più volte' );
apse_render( array( Admin\DashboardPage::class, 'render' ), 'Ospiti da invitare a iscriversi' );
$front_g = $as( $u_f, '[apsemplice_ospiti]' );
apse_ok( false !== strpos( $front_g, 'Partecipazioni: 2' ) && false !== strpos( $front_g, 'Open day yoga' ) && false === strpos( $front_g, 'iscriversi come socio' ) && false === strpos( $front_g, 'segreteria' ), 'area soci: il socio vede a cosa sono venuti i suoi ospiti, senza segnalazioni' );
$acts->add_staff( $e1, $tre_p );
$wpdb->update( Db::t( 'sessions' ), array( 'session_date' => $today ), array( 'id' => $gs1 ) );
$_GET['apse_session'] = (string) $gs1;
$det = $as( $u_tre, '[apsemplice_ingressi]' );
unset( $_GET['apse_session'] );
apse_ok( false !== strpos( $det, 'partecipazione come ospite' ) && false !== strpos( $det, 'risulta registrato anche come' ) && false !== strpos( $det, 'da invitare a iscriversi' ), 'lista prenotati all\'ingresso: chi gestisce vede quante volte è venuto e se risulta registrato anche con altri nomi' );
apse_ok( false === strpos( $det, '333 5555552' ) && false === strpos( $det, 'wa.me' ), 'lista prenotati all\'ingresso: nessun cellulare mostrato a chi gestisce l\'evento' );
apse_ok( false !== strpos( apse_render( array( Admin\SettingsPage::class, 'render' ), 'Ospiti: soglia di segnalazione' ), 'Non blocca nulla' ), 'impostazioni: la soglia è una segnalazione, non un blocco' );
apse_ok( false !== strpos( apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Cellulare', array( 'type' => 'guest' ) ), 'Obbligatorio per gli ospiti' ), 'modulo ospite: cellulare obbligatorio' );
Settings::update( array( 'guest_max_events' => 0 ) );
apse_ok( empty( $people->guest_overview()[ $gx ]['flag'] ) && array() === $people->guests_to_invite(), 'soglia 0: nessuna segnalazione' );
Settings::update( array( 'guest_max_events' => 2 ) );

// aggiungere un ospite dall'area soci: cellulare obbligatorio, nessun limite
wp_set_current_user( $u_f );
$no_phone = (string) apse_throws( function () use ( $front ) { $front::do_add_guest( array( 'first_name' => 'Sara', 'last_name' => 'Senzacell' ) ); } );
apse_ok( false !== strpos( $no_phone, 'cellulare' ), 'nuovo ospite dall\'area soci: senza cellulare viene rifiutato' );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front ) { $front::do_add_guest( array( 'first_name' => 'Altro', 'last_name' => 'Nome', 'phone' => '333 5555552' ) ); } ), 'Hai già questo ospite' ), 'nuovo ospite: lo stesso cellulare tra i propri ospiti (scritto in modo diverso) è il doppione evidente' );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front ) { $front::do_add_guest( array( 'first_name' => 'pino', 'last_name' => 'PROVINO', 'phone' => '333 0001111' ) ); } ), 'Hai già questo ospite' ), 'nuovo ospite: lo stesso nome tra i propri ospiti è rifiutato' );
$member_phone = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Mara', 'last_name' => 'Telefonata', 'email' => 'mara.telefonata@example.com', 'phone' => '348 1234567' ) );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front ) { $front::do_add_guest( array( 'first_name' => 'Mara', 'last_name' => 'Telefonata', 'phone' => '+39 348 123 4567' ) ); } ), 'Non è possibile aggiungere questo numero' ), 'nuovo ospite: un cellulare già di un socio è rifiutato senza dire che è di un socio' );
$front::do_add_guest( array( 'first_name' => 'Giovanni', 'last_name' => 'Altrui', 'phone' => '+39 333 5555554' ) ); // stesso cellulare di un ospite di un altro socio: consentito, ma lo si vedrà
$twins_now = $people->guest_overview();
$gianni_id = (int) $wpdb->get_var( 'SELECT id FROM ' . Db::t( 'people' ) . " WHERE first_name = 'Giovanni' AND last_name = 'Altrui' AND host_person_id = $founder" );
apse_ok( $gianni_id > 0 && ! empty( $twins_now[ $gianni_id ]['twins'] ), 'nuovo ospite: se il cellulare è di un altro ospite non si blocca, ma risulta gemello e lo si vede' );
wp_set_current_user( 1 );
$walk2 = new ReflectionMethod( Admin\Actions::class, 'walk_in' );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $walk2, $e1, $gs1, $founder ) { $walk2->invoke( null, array( 'activity_id' => $e1, 'session_id' => $gs1, 'new_first_name' => 'Senza', 'new_last_name' => 'Telefono', 'host_person_id' => $founder ) ); } ), 'cellulare' ), 'sul posto: un nuovo ospite richiede il cellulare' );
apse_ok( 1 === count( $people->find_homonyms( 'olga', 'occasionale' ) ) && array() === $people->find_homonyms( 'olga', 'occasionale', $gx ), 'ricerca omonimi: senza maiuscole e escludendo la persona stessa' );

// iscrizione come socio: la storia resta
$before = $acts->guest_status( $gx )['items'];
apse_ok( null !== apse_throws( function () use ( $people, $founder ) { $people->promote_guest( $founder, array( 'email' => 'x@example.com' ) ); } ) && null !== apse_throws( function () use ( $people, $gx ) { $people->promote_guest( $gx, array( 'email' => 'non-una-email' ) ); } ), 'iscrizione come socio: vale solo per gli ospiti e se c è l email deve essere valida' );
$people->promote_guest( $gx, array( 'email' => 'olga.occasionale@example.com', 'type' => 'volunteer', 'card_number' => '777', 'membership' => '1' ) );
$olga = $people->get( $gx );
apse_ok( 'volunteer' === $olga['type'] && empty( $olga['host_person_id'] ) && '777' === $olga['card_number'] && ! empty( $olga['wp_user_id'] ) && $people->is_active_member( $gx ), 'iscrizione come socio: tipo, tessera, utente WordPress e iscrizione all\'anno sociale' );
apse_ok( $before === $acts->participations( $gx ) && has_action( 'admin_post_apse_promote_guest' ), 'iscrizione come socio: eventi e corsi già frequentati restano collegati alla stessa scheda' );
apse_ok( ! isset( $people->guest_overview()[ $gx ] ), 'iscrizione come socio: non è più tra gli ospiti da segnalare' );
apse_ok( in_array( 'person.promoted', array_column( Audit::recent( 500 ), 'action' ), true ), 'registro azioni: iscrizione di un ospite come socio tracciata' );
Settings::update( array( 'guest_max_events' => 0 ) ); // gli altri controlli usano gli ospiti senza segnalazioni

// ---------- Avvisi dei volontari agli iscritti dell'attività ----------
wp_set_current_user( 1 );
$_SERVER['REQUEST_METHOD'] = 'GET';
apse_ok( Db::t( 'notices' ) === $wpdb->get_var( "SHOW TABLES LIKE '" . Db::t( 'notices' ) . "'" ), 'tabella degli avvisi' );
$mails = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	10,
	2
);
$nc = $acts->create( array( 'name' => 'Laboratorio avvisi', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 0, 'instructor_person_id' => $vol ) );
$acts->enroll( $nc, $tre_p, $month );
$acts->enroll( $nc, $q, $month );
$acts->enroll( $nc, $gz, $month );
$emails = array_map( 'strtolower', array_column( Notices::recipients( $nc ), 'email' ) );
sort( $emails );
$expected = array_map( 'strtolower', array( $people->get( $tre_p )['email'], $people->get( $q )['email'], $people->get( $founder )['email'] ) );
sort( $expected );
apse_ok( $emails === $expected, 'avvisi: i destinatari sono gli iscritti; un ospite senza email riceve tramite il socio che lo ospita' );
$acts->enroll( $nc, $founder, $month );
apse_ok( 3 === count( Notices::recipients( $nc ) ), 'avvisi: se il socio è iscritto anche lui, riceve una sola copia' );
$acts->cancel( $nc, $founder, $month );

// invio dal volontario
wp_set_current_user( $u_vol );
$msg = $front::do_notice( array( 'activity_id' => $nc, 'subject' => 'Cambio di orario', 'body' => "Stasera si comincia alle 19.\nPortate l'acqua." ) );
apse_ok( false !== strpos( $msg, 'Avviso inviato a 3 persone' ) && 3 === count( $mails ), 'avvisi: il volontario invia e arriva una email a ciascun iscritto' );
$one = $mails[0];
apse_ok( 1 === count( (array) $one['to'] ) && false !== strpos( (string) $one['subject'], 'Laboratorio avvisi: Cambio di orario' ) && false !== strpos( (string) $one['message'], 'Stasera si comincia alle 19' ) && false !== strpos( (string) $one['message'], 'Avviso di Veronica' ) && 0 === strpos( (string) $one['message'], 'Ciao ' ), 'avvisi: email individuale, con titolo, testo e chi lo manda' );
$all_text = wp_json_encode( $mails );
apse_ok( 3 === count( array_unique( array_map( function ( $m ) { return strtolower( (string) ( (array) $m['to'] )[0] ); }, $mails ) ) ) && false === strpos( (string) $one['message'], (string) $people->get( $q )['email'] ), 'avvisi: ognuno vede solo il proprio indirizzo' );
$row = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'notices' ) . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
apse_ok( 3 === (int) $row['recipients'] && 3 === (int) $row['emailed'] && $nc === (int) $row['activity_id'] && 'Veronica Volta' === $row['author_name'], 'avvisi: registrato con destinatari, email partite e autore' );
apse_ok( false === strpos( wp_json_encode( Audit::recent( 500 ) ), 'Stasera si comincia' ) && in_array( 'notice.sent', array_column( Audit::recent( 500 ), 'action' ), true ), 'registro azioni: invio tracciato senza il testo' );

// permessi
wp_set_current_user( $uq );
apse_ok( ! Notices::can_send( $nc ) && null !== apse_throws( function () use ( $front, $nc ) { $front::do_notice( array( 'activity_id' => $nc, 'subject' => 'x', 'body' => 'y' ) ); } ), 'avvisi: un socio qualunque non può inviarne' );
wp_set_current_user( $u_tre );
apse_ok( ! Notices::can_send( $nc ), 'avvisi: un gestore di un altro evento non può inviarne per un corso' );
wp_set_current_user( 1 );
apse_ok( Notices::can_send( $nc ), 'avvisi: l\'amministratore può sempre' );

// controlli sul contenuto e limite giornaliero
apse_ok( null !== apse_throws( function () use ( $front, $nc ) { $front::do_notice( array( 'activity_id' => $nc, 'subject' => '', 'body' => 'testo' ) ); } ) && null !== apse_throws( function () use ( $front, $nc ) { $front::do_notice( array( 'activity_id' => $nc, 'subject' => 'titolo', 'body' => '  ' ) ); } ) && null !== apse_throws( function () use ( $front, $nc ) { $front::do_notice( array( 'activity_id' => $nc, 'subject' => str_repeat( 'a', 121 ), 'body' => 'b' ) ); } ) && null !== apse_throws( function () use ( $front, $nc ) { $front::do_notice( array( 'activity_id' => $nc, 'subject' => 's', 'body' => str_repeat( 'b', 2001 ) ) ); } ), 'avvisi: titolo e testo obbligatori e di lunghezza limitata' );
$n_before = count( $mails );
for ( $i = 0; $i < 4; $i++ ) {
	$front::do_notice( array( 'activity_id' => $nc, 'subject' => "Prova $i", 'body' => 'testo' ) );
}
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front, $nc ) { $front::do_notice( array( 'activity_id' => $nc, 'subject' => 'Troppi', 'body' => 'testo' ) ); } ), 'ultime 24 ore' ) && count( $mails ) === $n_before + 12, 'avvisi: oltre 5 al giorno per attività si ferma (contro gli abusi)' );

// bacheca e moduli nell'area riservata
$board = $as( $uq, '[apsemplice_area_soci]' );
apse_ok( false !== strpos( $board, 'Cambio di orario' ) && false !== strpos( $board, 'Laboratorio avvisi' ) && false !== strpos( $board, 'Portate l' ), 'bacheca: l\'iscritto trova l\'avviso nell\'area riservata' );
apse_ok( false !== strpos( $as( $u_f, '[apsemplice_avvisi]' ), 'Cambio di orario' ), 'bacheca: il socio vede anche gli avvisi delle attività dei suoi ospiti' );
apse_ok( false !== strpos( $as( (int) $ida['wp_user_id'], '[apsemplice_avvisi]' ), 'Nessun avviso recente' ), 'bacheca: chi non è iscritto non vede avvisi' );
$vol_html = $as( $u_vol, '[apsemplice_area_soci]' );
apse_ok( false !== strpos( $vol_html, 'Invia un avviso agli iscritti' ) && false !== strpos( $vol_html, 'apse_front_notice' ), 'area volontari: il modulo per inviare un avviso sotto ogni attività che tiene' );
apse_ok( false === strpos( $as( $uq, '[apsemplice_area_soci]' ), 'Invia un avviso agli iscritti' ), 'area soci: chi non tiene attività non ha il modulo' );
apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Avvisi agli iscritti', array( 'id' => $nc ) );

// eventi: destinatari per data
$ev_rc = Notices::recipients( $tev, $tev_s );
apse_ok( 1 === count( $ev_rc ), 'avvisi evento: i prenotati della data (qui un ospite, tramite il socio che lo ospita)' );
$wpdb->update( Db::t( 'sessions' ), array( 'session_date' => gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ) ), array( 'id' => $tev_s ) );
apse_ok( 0 === count( Notices::recipients( $tev, $tev_s ) ) && 0 === count( Notices::recipients( $tev ) ), 'avvisi evento: le date già passate non hanno destinatari' );
$wpdb->update( Db::t( 'sessions' ), array( 'session_date' => $today ), array( 'id' => $tev_s ) );
wp_set_current_user( $u_tre );
$n_before = count( $mails );
$m2       = $front::do_notice( array( 'activity_id' => $tev, 'session_id' => $tev_s, 'subject' => 'Si entra dal cortile', 'body' => 'Ingresso dal portone laterale.' ) );
apse_ok( false !== strpos( $m2, 'Avviso inviato a 1 persona' ) && count( $mails ) === $n_before + 1 && false !== strpos( (string) $mails[ $n_before ]['message'], 'Avviso di Tina' ), 'avvisi evento: chi gestisce l\'evento invia a una sola data' );
$_GET['apse_session'] = (string) $tev_s;
$det2 = $as( $u_tre, '[apsemplice_ingressi]' );
unset( $_GET['apse_session'] );
apse_ok( false !== strpos( $det2, 'Invia un avviso agli iscritti' ) && false !== strpos( $det2, 'a questa data' ), 'ingressi: sotto la lista dei prenotati il modulo dell\'avviso per quella data' );
remove_all_filters( 'pre_wp_mail' );
wp_set_current_user( 1 );

// ---------- Soci senza email: attivazione dell'accesso con un link ----------
wp_set_current_user( 1 );
$_SERVER['REQUEST_METHOD'] = 'GET';
$Act = '\ApSemplice\Frontend\Activation';
$nulla = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Senza', 'last_name' => 'Nulla' ) );
apse_ok( empty( $people->get( $nulla )['wp_user_id'] ) && null === $people->get( $nulla )['email'], 'soci: anche senza email, cellulare e tessera si può registrare (si completa dopo)' );
$nm = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Nora', 'last_name' => 'Senzamail', 'phone' => '334 1234567', 'card_number' => '881' ) );
$cm = $people->create( array( 'type' => 'volunteer', 'first_name' => 'Carlo', 'last_name' => 'Soloturnessera', 'card_number' => '882' ) );
$np = $people->get( $nm );
apse_ok( empty( $np['wp_user_id'] ) && null === $np['email'] && empty( $people->get( $cm )['wp_user_id'] ), 'soci senza email: registrati con cellulare o con la sola tessera, senza utente WordPress finché non si attivano' );
apse_ok( ! $people->is_active_member( $nm ), 'socio senza email: nessun utente fittizio e nessuna email finta' );

// il link
$link  = $Act::url( $nm );
parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $q_act );
$param = (string) $q_act['apse_activate'];
apse_ok( 'ok' === $Act::resolve( $param )['status'] && false !== strpos( $Act::page( $param ), 'Ciao Nora' ) && false !== strpos( $Act::page( $param ), 'name="password2"' ) && false !== strpos( $Act::page( $param ), '334 1234567' ), 'link di attivazione: pagina con email, cellulare (già compilato) e password' );
apse_ok( 'expired' === $Act::resolve( $param, time() + 40 * DAY_IN_SECONDS )['status'] && 'invalid' === $Act::resolve( 'boh' )['status'] && 'invalid' === $Act::resolve( preg_replace( '/^\d+\./', ( $nm + 1 ) . '.', $param ) )['status'], 'link: scaduto dopo 30 giorni, falso o di un altro socio = non vale' );
apse_ok( false !== strpos( $Act::page( 'boh' ), 'Attivazione non disponibile' ), 'link non valido: pagina di errore' );

// l'attivazione: controlli
$ok_post = array( 'email' => 'Nora.Senzamail@Example.com', 'phone' => '334 1234567', 'password' => 'Segreta123!', 'password2' => 'Segreta123!', 'tax_code' => 'RSSMRA80A01H501U', 'address' => 'Via Dante 4', 'zip' => '40100', 'city' => 'Bologna' );
$bad     = function ( array $over ) use ( $Act, $param, $ok_post ) {
	$r = $Act::complete( $param, array_merge( $ok_post, $over ) );
	return $r['ok'] ? '' : $r['error'];
};
apse_ok( false !== strpos( $bad( array( 'password2' => 'diversa' ) ), 'non coincidono' ) && false !== strpos( $bad( array( 'password' => 'corta', 'password2' => 'corta' ) ), '8 caratteri' ) && false !== strpos( $bad( array( 'email' => 'non-una-email' ) ), 'email valido' ) && false !== strpos( $bad( array( 'phone' => '12' ) ), 'cellulare' ), 'attivazione: password diverse o corte, email o cellulare non validi sono rifiutati' );
apse_ok( false !== strpos( $bad( array( 'email' => 'esistente@example.com' ) ), 'già registrata' ) && empty( $people->get( $nm )['wp_user_id'] ), 'attivazione: un\'email già di un utente del sito è rifiutata (non si collega nessun account esistente)' );

// l'attivazione riesce
$res = $Act::complete( $param, $ok_post );
$u   = get_userdata( (int) $res['user_id'] );
$np2 = $people->get( $nm );
apse_ok( $res['ok'] && $u && 'nora.senzamail@example.com' === $u->user_email && in_array( Plugin::ROLE_MEMBER, (array) $u->roles, true ) && (int) $np2['wp_user_id'] === (int) $u->ID && 'nora.senzamail@example.com' === $np2['email'], 'attivazione: nasce l\'utente WordPress (ruolo socio) con l\'email scelta, collegato al socio' );
apse_ok( wp_check_password( 'Segreta123!', $u->user_pass, $u->ID ), 'attivazione: la password è quella scelta dal socio' );
apse_ok( 'done' === $Act::resolve( $param )['status'] && false !== strpos( $Act::page( $param ), 'Attivazione non disponibile' ), 'attivazione: il link non serve più dopo l\'uso' );
apse_ok( false !== strpos( $as( (int) $u->ID, '[apsemplice_tessera]' ), 'Nora' ), 'attivazione: il socio vede subito la sua area riservata' );
apse_ok( in_array( 'person.activated', array_column( Audit::recent( 500 ), 'action' ), true ), 'registro azioni: attivazione tracciata' );

// amministrazione: elenco, filtro e scheda
wp_set_current_user( 1 );
$list = apse_render( array( Admin\PeoplePage::class, 'render_list' ), 'Soloturnessera', array( 'status' => 'noaccess' ) );
apse_ok( false !== strpos( $list, 'Senza accesso' ) && false === strpos( $list, 'Senzamail' ), 'elenco soci: il filtro "Senza accesso" mostra chi non si è ancora attivato (non chi l\'ha fatto)' );
$ed = apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Accesso all\'area riservata', array( 'id' => $cm ) );
apse_ok( false !== strpos( $ed, 'apse_activate=' ) && false !== strpos( $ed, 'Senza cellulare' ), 'scheda socio senza cellulare: il link da copiare' );
$people->update( $cm, array( 'phone' => '335 7654321' ) );
$ed2 = apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Accesso all\'area riservata', array( 'id' => $cm ) );
apse_ok( false !== strpos( $ed2, 'https://wa.me/393357654321?text=' ), 'scheda socio: pulsante WhatsApp con il messaggio di invito' );
$list2 = apse_render( array( Admin\PeoplePage::class, 'render_list' ), 'Invia link', array( 'status' => 'noaccess' ) );
apse_ok( false !== strpos( $list2, 'wa.me/393357654321' ), 'elenco soci: pulsante "Invia link" su WhatsApp accanto a chi è senza accesso' );
$inv = $Act::invite_text( $people->get( $cm ) );
apse_ok( false !== strpos( $inv, 'apse_activate=' ) && false !== strpos( $inv, 'Carlo' ), 'messaggio di invito: nome e link' );
apse_ok( false !== strpos( apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Facoltativa', array( 'type' => 'ordinary' ) ), 'Facoltativa' ), 'modulo socio: l\'email non è più obbligatoria' );

// aggiungere l'email dopo: l'accesso si crea subito
$people->update( $cm, array( 'email' => 'carlo.soloturnessera@example.com' ) );
apse_ok( ! empty( $people->get( $cm )['wp_user_id'] ), 'se poi si aggiunge l\'email l\'utente WordPress si crea subito' );

// import di soci senza email
$csv     = $mkf( 'senzamail.csv', "Nome;Cognome;Cellulare;Tessera\r\nElio;Telefonico;336 1112233;\r\nPia;Tesserata;;883\r\nNora;Senzamail;334 1234567;\r\n" );
$pv      = ImportService::preview_file( $csv, 'senzamail.csv', array() );
$acts_pv = array_column( $pv['people']['plan'], 'action' );
apse_ok( array( 'create', 'create', 'update' ) === $acts_pv, 'import: soci senza email creati col cellulare o la tessera; chi ha già il cellulare si riconosce (aggiorna)' );
ImportService::apply( $pv, array() );
$elio = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE last_name = 'Telefonico'", ARRAY_A );
apse_ok( $elio && empty( $elio['wp_user_id'] ) && null === $elio['email'], 'import: socio senza email registrato, senza utente fittizio' );
@unlink( $csv );

// ---------- Primo accesso: il socio sceglie la sua password ----------
wp_set_current_user( 1 );
$FA    = '\ApSemplice\Frontend\FirstAccess';
$fa_ml = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$fa_ml ) {
		$fa_ml[] = $atts;
		return true;
	},
	10,
	2
);
$q_mail = (string) $people->get( $q )['email'];
apse_ok( true === $FA::request( $q_mail ) && 1 === count( $fa_ml ) && $q_mail === (string) ( (array) $fa_ml[0]['to'] )[0], 'primo accesso: a un socio con accesso arriva la email con il link' );
apse_ok( false !== strpos( (string) $fa_ml[0]['subject'], 'Primo accesso' ) && false !== strpos( (string) $fa_ml[0]['message'], 'scegli la tua password' ) && 0 === strpos( (string) $fa_ml[0]['message'], 'Ciao Quinto' ), 'primo accesso: email con le parole giuste (non "recupero password")' );
preg_match( '/key=([A-Za-z0-9]+)&login=([^\s]+)/', (string) $fa_ml[0]['message'], $km );
$chk = check_password_reset_key( $km[1], rawurldecode( $km[2] ) );
apse_ok( $chk instanceof WP_User && (int) $chk->ID === (int) $people->get( $q )['wp_user_id'], 'primo accesso: il link è quello valido di WordPress per scegliere la password di quel socio' );
$n = count( $fa_ml );
$admin_mail = (string) get_userdata( 1 )->user_email;
$plain_id   = wp_create_user( 'soloutente', 'x-Pass-123456', 'solo.utente@example.com' );
apse_ok( false === $FA::request( 'sconosciuta@example.com' ) && false === $FA::request( 'non-una-email' ) && false === $FA::request( '' ) && false === $FA::request( $admin_mail ) && false === $FA::request( 'solo.utente@example.com' ) && count( $fa_ml ) === $n, 'primo accesso: email sconosciute, amministratori e utenti che non sono soci non ricevono nulla' );
apse_ok( false !== strpos( $FA::page( \ApSemplice\Frontend\FirstAccess::MESSAGE ), 'Richiesta ricevuta' ) && false !== strpos( $FA::page(), 'Mandami il link' ) && false !== strpos( $FA::page(), 'name="phone"' ) && false !== strpos( $FA::page(), 'name="email"' ) && false !== strpos( $FA::page(), 'name="name"' ), 'primo accesso: pagina con nome, email e cellulare e la risposta uguale per tutti' );

// primo accesso: email conosciuta -> link; cellulare conosciuto -> si crea/aggiorna l'utente; altrimenti la segreteria
$AR   = '\ApSemplice\AccessRequests';
$n_ml = count( $fa_ml );
apse_ok( 'invalid' === $FA::submit( '', 'a@example.com', '339 1230000' ) && 'invalid' === $FA::submit( 'Tizio', 'non-email', '339 1230000' ) && 'invalid' === $FA::submit( 'Tizio', 'a@example.com', '12' ) && count( $fa_ml ) === $n_ml, 'primo accesso: servono nome, email valida e cellulare' );
apse_ok( 'emailed' === $FA::submit( 'Quinto', $q_mail, '339 9990000' ) && count( $fa_ml ) === $n_ml + 1 && array() === $AR::all(), 'primo accesso: email di un socio -> arriva il link, niente in coda' );

$elio_id = (int) $elio['id'];
apse_ok( 'change_queued' === $FA::submit( 'Elio Telefonico', 'Elio.Nuovo@example.com', '+39 336 1112233' ) && count( $fa_ml ) === $n_ml + 1, 'primo accesso: il solo cellulare di un socio senza accesso non basta -> nessun utente creato, nessuna email' );
$elio_now = $people->get( $elio_id );
apse_ok( empty( $elio_now['wp_user_id'] ) && null === $elio_now['email'] && false === get_user_by( 'email', 'elio.nuovo@example.com' ), 'primo accesso: nessun accesso collegato a chi conosce solo il cellulare' );
$rev = array_values( array_filter( $AR::pending(), function ( $p ) use ( $elio_id ) { return 'change' === $p['kind'] && (int) $p['person_id'] === $elio_id; } ) );
apse_ok( 1 === count( $rev ), 'primo accesso: la segreteria vede la richiesta da verificare' );
$FA::approve_change( (string) $rev[0]['id'] );
$elio_now = $people->get( $elio_id );
apse_ok( ! empty( $elio_now['wp_user_id'] ) && 'elio.nuovo@example.com' === $elio_now['email'] && count( $fa_ml ) === $n_ml + 2 && 'elio.nuovo@example.com' === (string) ( (array) $fa_ml[ $n_ml + 1 ]['to'] )[0], 'primo accesso: solo dopo l\'approvazione della segreteria si crea l\'utente e il link va all\'email indicata' );

$people->update( $q, array( 'phone' => '338 5550101' ) );
$n_ml = count( $fa_ml );
apse_ok( 'change_queued' === $FA::submit( 'Quinto', 'quinto.nuova@example.com', '338 5550101' ) && count( $fa_ml ) === $n_ml && $q_mail === (string) $people->get( $q )['email'], 'primo accesso: cellulare di chi ha già un accesso -> nessuna modifica automatica, richiesta alla segreteria' );
$chg = array_values( array_filter( $AR::pending(), function ( $p ) use ( $q ) { return 'change' === $p['kind'] && (int) $p['person_id'] === $q; } ) );
apse_ok( 1 === count( $chg ), 'primo accesso: richiesta di cambio email in coda' );

apse_ok( 'unknown_queued' === $FA::submit( 'Mario Sconosciuto', 'mario.sc@example.com', '339 8887766' ) && count( $fa_ml ) === $n_ml, 'primo accesso: né email né cellulare noti -> richiesta alla segreteria, nessuna email' );
$dup1 = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Dup', 'last_name' => 'Uno', 'phone' => '337 0000001' ) );
$dup2 = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Dup', 'last_name' => 'Due', 'phone' => '337 0000001' ) );
apse_ok( 'unknown_queued' === $FA::submit( 'Dup', 'dup@example.com', '337 0000001' ) && 'unknown_queued' === $FA::submit( 'Ospite', 'og@example.com', (string) $people->get( $g_id )['phone'] ) && empty( $people->get( $dup1 )['wp_user_id'] ), 'primo accesso: cellulare di più soci o di un ospite -> mai indovinare, va alla segreteria' );
apse_ok( 'unknown_queued' === $FA::submit( 'Admin', $admin_mail, '339 1112222' ) && count( $fa_ml ) === $n_ml, 'primo accesso: l\'email di un amministratore non riceve nulla' );

// primo accesso: chi si attiva dal sito deve completare il profilo; finché manca non prenota né paga online
apse_ok( 1 === (int) $people->get( $q )['profile_due'] && $people->profile_blocks( $people->get( $q ) ), 'primo accesso: il socio senza indirizzo e codice fiscale risulta da completare e bloccato' );
wp_set_current_user( $uq );
apse_ok( false !== strpos( (string) apse_throws( function () { \ApSemplice\Frontend\Actions::do_pay( array( 'items' => array() ) ); } ), 'completa i tuoi dati' ) && false !== strpos( (string) apse_throws( function () { \ApSemplice\Frontend\Actions::do_book( array( 'session_id' => 1 ) ); } ), 'completa i tuoi dati' ), 'profilo incompleto: prenotare e pagare online sono bloccati con un messaggio chiaro' );
apse_ok( false !== strpos( $as( $uq, '[apsemplice_profilo]' ), 'Completa i tuoi dati' ) && false !== strpos( $as( $uq, '[apsemplice_profilo]' ), 'name="address"' ), 'profilo incompleto: l\'area mostra cosa manca e il modulo con l\'indirizzo' );
apse_ok( null !== apse_throws( function () { \ApSemplice\Frontend\Actions::do_profile( array( 'tax_code' => 'ABC', 'address' => 'Via Verdi 5', 'zip' => '00100', 'city' => 'Roma' ) ); } ), 'profilo: un codice fiscale non valido è rifiutato' );
Settings::update( array( 'limits' => array( 'profile_gate' => 0 ) ) );
apse_ok( ! $people->profile_blocks( $people->get( $q ) ), 'profilo incompleto: il blocco si può spegnere dalle impostazioni' );
Settings::update( array( 'limits' => array() ) );
$msg_p = \ApSemplice\Frontend\Actions::do_profile( array( 'phone' => '338 5550101', 'tax_code' => 'rssmra80a01h501u', 'address' => 'Via Verdi 5', 'zip' => '00100', 'city' => 'Roma', 'province' => 'rm' ) );
apse_ok( false !== strpos( $msg_p, 'completato' ) && 0 === (int) $people->get( $q )['profile_due'] && ! $people->profile_blocks( $people->get( $q ) ) && 'RSSMRA80A01H501U' === $people->get( $q )['tax_code'], 'profilo completato dal socio: il blocco cade e i dati sono salvati' );
wp_set_current_user( 1 );
// limite per email: nessuno può inondare la casella di un socio
delete_transient( 'apse_fa_m_' . md5( strtolower( $q_mail ) ) );
Settings::update( array( 'limits' => array( 'first_access_per_email' => 2 ) ) );
$n_lim = count( $fa_ml );
$FA::request( $q_mail );
$FA::request( $q_mail );
$FA::request( $q_mail );
apse_ok( count( $fa_ml ) === $n_lim + 2, 'primo accesso: oltre il limite orario per email non partono altri messaggi' );
delete_transient( 'apse_fa_m_' . md5( strtolower( $q_mail ) ) );
Settings::update( array( 'limits' => array() ) );
$dash = apse_render( array( Admin\DashboardPage::class, 'render' ), 'Richieste di accesso' );
apse_ok( false !== strpos( $dash, 'Mario Sconosciuto' ) && false !== strpos( $dash, 'wa.me/393398887766' ) && false !== strpos( $dash, 'quinto.nuova@example.com' ) && false !== strpos( $dash, 'apse_access_approve' ), 'riepilogo: richieste con WhatsApp (sconosciuti), approvazione (cambio email) e controllo (da cellulare)' );
$act = new ReflectionMethod( Admin\Actions::class, 'access_approve' );
$act->setAccessible( true );
$act->invoke( null, array( 'id' => $chg[0]['id'] ) );
apse_ok( 'quinto.nuova@example.com' === strtolower( (string) $people->get( $q )['email'] ), 'riepilogo: approvando il cambio, l\'email del socio si aggiorna' );
apse_ok( 'quinto.nuova@example.com' === strtolower( (string) get_userdata( (int) $people->get( $q )['wp_user_id'] )->user_email ), 'riepilogo: approvando il cambio, si aggiorna anche l\'utente WordPress (ruoli: ' . implode( ',', get_userdata( (int) $people->get( $q )['wp_user_id'] )->roles ) . ')' );
apse_ok( ! isset( $AR::all()[ $chg[0]['id'] ] ) && count( array_filter( $fa_ml, function ( $m ) { return 'quinto.nuova@example.com' === (string) ( (array) $m['to'] )[0] && false !== strpos( (string) $m['message'], 'scegli la tua password' ); } ) ) === 1, 'riepilogo: approvando il cambio, la richiesta si chiude e il link va alla nuova email' );
$unk = array_values( array_filter( $AR::pending(), function ( $p ) { return 'unknown' === $p['kind']; } ) );
$done = new ReflectionMethod( Admin\Actions::class, 'access_done' );
$done->setAccessible( true );
$done->invoke( null, array( 'id' => $unk[0]['id'] ) );
apse_ok( ! isset( $AR::all()[ $unk[0]['id'] ] ), 'riepilogo: "fatto" chiude la richiesta' );
apse_ok( false !== strpos( (string) apply_filters( 'login_message', '' ), 'Primo accesso' ) && false !== strpos( (string) apply_filters( 'login_message', '' ), 'apse_first_access=1' ), 'primo accesso: il link compare nella pagina di accesso di WordPress' );
apse_ok( false !== strpos( $as( 0, '[apsemplice_area_soci]' ), 'Primo accesso' ), 'primo accesso: il link compare anche nell\'area riservata, prima di "Password dimenticata"' );
remove_all_filters( 'pre_wp_mail' );
wp_set_current_user( 1 );

// ---------- Conti: rinomina, saldo di partenza, chiusura ----------
wp_set_current_user( 1 );
$acc_id = $ledger->add_account( 'Conto prova', 'bank', 10000 );
$bal_of = function ( int $id, bool $closed = false ) use ( $ledger ) {
	foreach ( $ledger->balances( null, $closed ) as $b ) {
		if ( (int) $b['id'] === $id ) {
			return $b['balance'];
		}
	}
	return null;
};
$ledger->update_account( $acc_id, 'Conto rinominato', 'cash', 25000 );
apse_ok( 'Conto rinominato' === $ledger->account( $acc_id )['name'] && 'cash' === $ledger->account( $acc_id )['type'] && 25000 === $bal_of( $acc_id ), 'conti: si rinominano e il saldo di partenza cambia il saldo attuale' );
$threw = false;
try {
	$ledger->update_account( $acc_id, '  ', 'cash', 0 );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw, 'conti: il nome è obbligatorio' );
$threw = false;
try {
	$ledger->close_account( $acc_id );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw && null !== $ledger->account( $acc_id ), 'conti: non si chiude un conto con dei soldi dentro' );
$ledger->update_account( $acc_id, 'Conto rinominato', 'cash', 0 );
$ledger->close_account( $acc_id );
apse_ok( null === $ledger->account( $acc_id ) && ! in_array( $acc_id, array_map( 'intval', array_column( $ledger->accounts(), 'id' ) ), true ) && in_array( $acc_id, array_map( 'intval', array_column( $ledger->accounts( true ), 'id' ) ), true ), 'conti: chiuso, esce dagli elenchi dei movimenti ma resta consultabile' );
$threw = false;
try {
	$ledger->record_expense( array( 'date' => $today, 'account_id' => $acc_id, 'method' => 'cash', 'category_id' => $cat['general_cost'], 'amount_cents' => 100, 'description' => 'x' ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw, 'conti: un conto chiuso non accetta movimenti' );
$ledger->reopen_account( $acc_id );
apse_ok( null !== $ledger->account( $acc_id ), 'conti: si può riaprire' );

// ---------- Fondi per il rimborso del volontario ----------
$funds  = Plugin::funds();
$fvol   = $people->create( array( 'type' => 'volunteer', 'first_name' => 'Vera', 'last_name' => 'Rimborso', 'email' => 'vera.rimborso@example.com' ) );
$fpayer = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Pagante', 'last_name' => 'Fondo', 'email' => 'pagante.fondo@example.com' ) );
$threw  = false;
try {
	$acts->create( array( 'name' => 'Senza istruttore', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 2000, 'fund_mode' => 'percent', 'fund_value' => 2500 ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw, 'fondo: la quota per il rimborso richiede l\'istruttore' );
$threw = false;
try {
	$acts->create( array( 'name' => 'Troppo', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 2000, 'instructor_person_id' => $fvol, 'fund_mode' => 'percent', 'fund_value' => 10100 ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw, 'fondo: la percentuale non supera il 100%' );
$fcorso = $acts->create( array( 'name' => 'Corso Rimborsi', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 2000, 'instructor_person_id' => $fvol, 'fund_mode' => 'percent', 'fund_value' => 2500 ) );
$av0    = $funds->available();
$cash0  = $bal_of( (int) $cash['id'] );
$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $fpayer, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 2000, 'activity_id' => $fcorso, 'competence_month' => $month ) ) ) );
$fl = array_values(
	array_filter(
		$funds->all(),
		function ( $f ) use ( $fcorso ) {
			return (int) $f['activity_id'] === $fcorso;
		}
	)
);
apse_ok( 1 === count( $fl ) && 'Rimborso Vera Rimborso — Corso Rimborsi' === $fl[0]['name'] && 500 === $fl[0]['balance'], 'fondo: il pagamento accantona il 25% in "Rimborso (volontario) — (corso)"' );
apse_ok( $cash0 + 2000 === $bal_of( (int) $cash['id'] ), 'fondo: la cassa riceve comunque tutto il pagamento' );
$av1 = $funds->available();
apse_ok( $av1['accounts'] === $av0['accounts'] + 2000 && $av1['funds'] === $av0['funds'] + 500 && $av1['available'] === $av0['available'] + 1500, 'fondo: disponibilità reale = saldi meno fondi' );
$acts->update( $fcorso, array( 'fund_mode' => 'fixed', 'fund_value' => 200 ) );
$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $fpayer, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 1000, 'activity_id' => $fcorso, 'competence_month' => $month ) ) ) );
apse_ok( 700 === $funds->get( (int) $fl[0]['id'] )['balance'], 'fondo: con l\'importo fisso si accantonano 2,00 € per pagamento' );
$last_tx = (int) $wpdb->get_var( 'SELECT id FROM ' . Db::t( 'transactions' ) . ' WHERE activity_id = ' . $fcorso . ' ORDER BY id DESC LIMIT 1' );
$ledger->void( $last_tx, 'errore' );
apse_ok( 500 === $funds->get( (int) $fl[0]['id'] )['balance'], 'fondo: annullando il pagamento in prima nota si annulla anche la sua quota' );
$threw = false;
try {
	$funds->release( (int) $fl[0]['id'], 99999, $today );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
$av_pre = $funds->available();
$funds->release( (int) $fl[0]['id'], 100, $today );
apse_ok( $threw && 400 === $funds->get( (int) $fl[0]['id'] )['balance'] && $funds->available()['available'] === $av_pre['available'] + 100, 'fondo: si libera una quota solo fino al saldo e torna nella disponibilità' );
$dash = apse_render( array( Admin\DashboardPage::class, 'render' ), 'Disponibilità reale' );
apse_ok( false !== strpos( $dash, 'Rimborso Vera Rimborso' ), 'fondo: la dashboard mostra disponibilità reale e fondi' );
apse_render( array( Admin\AccountsPage::class, 'render' ), 'Estingui il fondo' );
$rep = Plugin::reports()->period( $today, $today );
apse_ok( $rep['funds_total'] === $funds->total( $today ) && $rep['available'] === $rep['closing_total'] - $rep['funds_total'], 'fondo: il rendiconto riporta la disponibilità reale' );
$av_before = $funds->available();
$exp_tx    = $funds->settle( (int) $fl[0]['id'], (int) $cash['id'], 'cash', $today );
$av_after  = $funds->available();
$exp_row   = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'transactions' ) . ' WHERE id = ' . $exp_tx, ARRAY_A );
apse_ok( $exp_tx > 0 && 'expense' === $exp_row['type'] && 400 === (int) $exp_row['amount_cents'] && (int) $fvol === (int) $exp_row['person_id'], 'fondo estinto: l\'uscita del rimborso è registrata in prima nota' );
apse_ok(
	$av_after['available'] === $av_before['available'] && $av_after['accounts'] === $av_before['accounts'] - 400 && null !== $funds->get( (int) $fl[0]['id'] )['closed_at']
	&& ! array_filter(
		$funds->all(),
		function ( $f ) use ( $fl ) {
			return (int) $f['id'] === (int) $fl[0]['id'];
		}
	),
	'fondo estinto: la disponibilità reale non cambia (il rimborso era già accantonato) e il fondo sparisce'
);
apse_render( array( Admin\AccountsPage::class, 'render' ), 'Conti' );
// fondo creato a mano
$av_m0  = $funds->available();
$man_id = $funds->create( 'Gita sociale', 5000 );
apse_ok( 5000 === $funds->get( $man_id )['balance'] && $funds->available()['available'] === $av_m0['available'] - 5000, 'fondo a mano: si crea con una somma già accantonata e scala la disponibilità reale' );
$funds->deposit( $man_id, 1000, $today );
apse_ok( 6000 === $funds->get( $man_id )['balance'], 'fondo a mano: si può accantonare altro' );
$threw = false;
try {
	$funds->create( '  ' );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw, 'fondo a mano: serve il nome' );
$funds->settle( $man_id, (int) $cash['id'], 'cash', $today );
apse_ok( null !== $funds->get( $man_id )['closed_at'], 'fondo a mano: si estingue registrando la spesa' );
apse_render( array( Admin\AccountsPage::class, 'render' ), 'Nuovo fondo' );
apse_render( array( Admin\ReportsPage::class, 'render' ), 'Disponibilità reale' );

// ---------- Menu a cinque voci, schede, interruttori, cassa rapida ----------
wp_set_current_user( 1 );
apse_ok( 'apse-money' === Admin\Admin::menu_item_of( 'apse-income' ) && 'apse-money' === Admin\Admin::menu_item_of( 'apse-ledger' ) && 'apse-money' === Admin\Admin::menu_item_of( 'apse-accounts' ) && 'apse-accounting' === Admin\Admin::menu_item_of( 'apse-years' ) && 'apse-accounting' === Admin\Admin::menu_item_of( 'apse-fivepm' ) && 'apse-settings' === Admin\Admin::menu_item_of( 'apse-payments' ) && 'apse-settings' === Admin\Admin::menu_item_of( 'apse-card' ) && 'apse-people' === Admin\Admin::menu_item_of( 'apse-person' ) && 'apse-activities' === Admin\Admin::menu_item_of( 'apse-activity' ) && 'apse' === Admin\Admin::menu_item_of( 'apse' ), 'menu: ogni pagina appartiene a una delle voci principali' );
$tabs = Admin\Admin::tabs( 'apse-income' );
apse_ok( false !== strpos( $tabs, 'Prima nota' ) && false !== strpos( $tabs, 'Conti e fondi' ) && false !== strpos( $tabs, 'nav-tab-active' ) && '' === Admin\Admin::tabs( 'apse' ) && false !== strpos( Admin\Admin::tabs( 'apse-tools' ), 'Calendari' ) && false !== strpos( Admin\Admin::tabs( 'apse-tools' ), 'Importa da Excel/CSV' ) && false !== strpos( Admin\Admin::tabs( 'apse-tools' ), 'Esporta' ), 'menu: la Contabilità ha le sue schede, gli Strumenti raccolgono importazioni, esportazioni e calendari, la Bacheca nessuna' );
apse_ok( false !== strpos( Admin\Admin::tabs( 'apse-card' ), 'Soldi' ) && false !== strpos( Admin\Admin::tabs( 'apse-audit' ), 'Registro azioni' ) && false !== strpos( Admin\Admin::tabs( 'apse-settings' ), 'Sistema' ), 'menu: pagamenti, tessera/wallet e registro stanno nelle Impostazioni' );
$GLOBALS['submenu'] = array();
Admin\Admin::menu();
$visible = array_column( $GLOBALS['submenu']['apse'] ?? array(), 0 );
apse_ok( array( 'Bacheca', 'Rubrica', 'Corsi ed eventi', 'Soldi', 'Contabilità', 'Registri', 'Strumenti', 'Impostazioni' ) === $visible, 'menu: solo otto voci (' . implode( ', ', $visible ) . ')' );

// interruttori: tutto spento di default
Settings::update( array( 'wallet_enabled' => 0, 'ticket_qr_enabled' => 0 ) );
apse_ok( null === Wallet::apple_config() && null === Wallet::google_config() && '' === Wallet::buttons( $people->get( $founder ) ), 'wallet spento: nessuna configurazione attiva e nessun pulsante' );
$card_off = apse_render( array( Admin\CardPage::class, 'render' ), 'Cosa vuoi usare' );
apse_ok( false === strpos( $card_off, 'Google Wallet</h2>' ) && false !== strpos( $card_off, 'Biglietti QR delle prenotazioni' ), 'impostazioni: con il wallet spento non si mostrano le credenziali' );
$ticket_off = apse_render( array( Admin\ActivitiesPage::class, 'render_list' ), 'Crea attività' );
apse_ok( false === strpos( $ticket_off, 'Genera un QR per ogni prenotazione' ), 'biglietti QR spenti: l\'opzione non compare nella scheda evento' );
Settings::update( array( 'wallet_enabled' => 1, 'ticket_qr_enabled' => 1 ) );
$ticket_on = apse_render( array( Admin\ActivitiesPage::class, 'render_list' ), 'Crea attività' );
apse_ok( false !== strpos( $ticket_on, 'Genera un QR per ogni prenotazione' ), 'biglietti QR accesi: l\'opzione compare' );

// cassa rapida
$qc = new ReflectionMethod( Admin\Actions::class, 'quick_cash' );
$qc->setAccessible( true );
$bal_now = function () use ( $ledger ) {
	return array_sum( array_column( $ledger->balances(), 'balance' ) );
};
$b0 = $bal_now();
$qc->invoke( null, array( 'category_id' => $ledger->category_id_of_kind( 'donation' ), 'amount' => '12,50', 'description' => 'Offerta in cassa', 'method' => 'cash', 'account_id' => (int) $cash['id'] ) );
apse_ok( $b0 + 1250 === $bal_now(), 'cassa rapida: l\'incasso entra in cassa' );
$qc->invoke( null, array( 'category_id' => $ledger->category_id_of_kind( 'general_cost' ), 'amount' => '5,00', 'description' => 'Cancelleria', 'method' => 'cash', 'account_id' => (int) $cash['id'] ) );
apse_ok( $b0 + 750 === $bal_now(), 'cassa rapida: la spesa esce dalla cassa' );
$threw = false;
try {
	$qc->invoke( null, array( 'category_id' => $ledger->category_id_of_kind( 'membership' ), 'amount' => '5,00', 'description' => 'x', 'method' => 'cash', 'account_id' => (int) $cash['id'] ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw, 'cassa rapida: le quote e le attività si registrano dall\'incasso completo' );
$dash = apse_render( array( Admin\DashboardPage::class, 'render' ), 'Cassa rapida' );
apse_ok( false !== strpos( $dash, 'apse_quick_cash' ), 'bacheca: la cassa rapida è in prima pagina' );
apse_render( array( Admin\PeoplePage::class, 'render_list' ), 'Contatti' );

// ---------- Conto = modalità di pagamento, sconti e iscrizione a fine anno ----------
wp_set_current_user( 1 );
$bank_id = $ledger->add_account( 'Conto banca prova', 'bank', 0 );
$pos_id  = $ledger->add_account( 'POS prova', 'pos', 0 );
$method_of = function ( int $tx ) use ( $wpdb ) {
	return $wpdb->get_var( 'SELECT method FROM ' . Db::t( 'transactions' ) . ' WHERE id = ' . $tx );
};
$e1 = $ledger->record_expense( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'category_id' => $cat['general_cost'], 'amount_cents' => 100, 'description' => 'a' ) );
$e2 = $ledger->record_expense( array( 'date' => $today, 'account_id' => $bank_id, 'category_id' => $cat['general_cost'], 'amount_cents' => 100, 'description' => 'b' ) );
$e3 = $ledger->record_expense( array( 'date' => $today, 'account_id' => $pos_id, 'category_id' => $cat['general_cost'], 'amount_cents' => 100, 'description' => 'c' ) );
apse_ok( 'cash' === $method_of( $e1 ) && 'bank_transfer' === $method_of( $e2 ) && 'pos' === $method_of( $e3 ), 'pagamenti: senza modalità la decide il conto (cassa = contanti, conto = bonifico, POS = carta)' );
$t_id = $ledger->record_transfer( $today, (int) $cash['id'], $bank_id, 100, '' );
apse_ok( 2 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . ' WHERE transfer_id = %s', $t_id ) ), 'giroconto: senza modalità si registra comunque' );
apse_ok( false === strpos( apse_render( array( Admin\ExpensePage::class, 'render' ), 'Pagato dal conto' ), 'name="method"' ) && false === strpos( apse_render( array( Admin\TransferPage::class, 'render' ), 'Giroconto' ), 'name="method"' ) && false === strpos( apse_render( array( Admin\IncomePage::class, 'render' ), 'apse-income-data' ), 'name="method"' ), 'moduli: niente scelta della modalità, basta il conto' );

// sconto: la voce conta come pagata anche se si incassa meno
$sc_p   = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Sconta', 'last_name' => 'Open', 'email' => 'sconta.open@example.com' ) );
$sc_c   = $acts->create( array( 'name' => 'Corso Sconto', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 2000 ) );
$acts->enroll( $sc_c, $sc_p, $month );
$inc0 = Plugin::reports()->period( $today, $today )['total_income'];
$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'person_id' => $sc_p, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 1500, 'activity_id' => $sc_c, 'competence_month' => $month ) ) ) );
$st = $acts->status_for_person( $sc_p )[0]['summary'];
apse_ok( empty( $st['regular'] ), 'sconto: senza sconto, 15,00 su 20,00 non bastano' );
$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'person_id' => $sc_p, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 0, 'discount_cents' => 500, 'discount_note' => 'open day', 'activity_id' => $sc_c, 'competence_month' => $month ) ) ) );
$st = $acts->status_for_person( $sc_p )[0]['summary'];
apse_ok( ! empty( $st['regular'] ), 'sconto: 15,00 incassati + 5,00 di sconto = mensilità pagata' );
$inc1 = Plugin::reports()->period( $today, $today )['total_income'];
apse_ok( $inc1 === $inc0 + 1500, 'sconto: non è un\'entrata, il rendiconto conta solo ciò che è stato incassato' );
$desc = (string) $wpdb->get_var( 'SELECT description FROM ' . Db::t( 'transactions' ) . ' WHERE activity_id = ' . $sc_c . ' AND discount_cents = 500' );
apse_ok( false !== strpos( $desc, 'sconto' ) && false !== strpos( $desc, 'open day' ), 'sconto: in prima nota resta il motivo' );
$threw = false;
try {
	$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'person_id' => $sc_p, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 0, 'activity_id' => $sc_c, 'competence_month' => $month ) ) ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw, 'incasso: importo zero senza sconto non vale' );

// quota associativa automatica: va all'anno solare più recente creato; se non è quello in corso, l'anno in corso è in omaggio
$fy_p = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Fine', 'last_name' => 'Anno', 'email' => 'fine.anno@example.com' ) );
$si   = new ReflectionMethod( Admin\Actions::class, 'save_income' );
$si->setAccessible( true );
$cy = (int) substr( $today, 0, 4 );
\ApSemplice\FiscalYears::create( $cy + 1 );
$si->invoke( null, array( 'date' => $today, 'account_id' => (string) $cash['id'], 'person_id' => (string) $fy_p, 'lines' => array( array( 'category_id' => (string) $cat['membership'], 'amount' => '10,00' ) ) ) );
$fy_years = $wpdb->get_col( 'SELECT social_year FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = ' . $fy_p . ' AND deleted_at IS NULL ORDER BY social_year' );
apse_ok( array( (string) $cy, (string) ( $cy + 1 ) ) === $fy_years, 'quota automatica: un nuovo socio paga l\'anno più recente e ha l\'anno in corso in omaggio (' . implode( ',', $fy_years ) . ')' );
apse_ok( $people->is_active_member( $fy_p ), 'quota automatica: il socio è attivo subito' );

// iscrizione rapida dalla Bacheca
$qe = new ReflectionMethod( Admin\Actions::class, 'quick_enroll' );
$qe->setAccessible( true );
$qe_c = $acts->create( array( 'name' => 'Corso Rapido', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 1000 ) );
$qe->invoke( null, array( 'person_id' => (string) $fy_p, 'target' => 'a:' . $qe_c ) );
apse_ok( in_array( $qe_c, $acts->active_activity_ids( $fy_p ), true ), 'bacheca: iscrizione rapida a un corso' );
$threw = false;
try {
	$qe->invoke( null, array( 'person_id' => (string) $fy_p, 'target' => '' ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw, 'bacheca: serve scegliere a cosa iscrivere' );
$dash = apse_render( array( Admin\DashboardPage::class, 'render' ), 'Iscrizione a corsi ed eventi' );
apse_ok( false === strpos( $dash, '>Giroconto<' ) && false !== strpos( $dash, 'apse_quick_enroll' ), 'bacheca: niente giroconto, c\'è l\'iscrizione' );

// ---------- Corsi: rinnovo automatico, dovuto dalla prima lezione del mese ----------
$wd_c = $acts->create( array( 'name' => 'Corso Martedì', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 3000, 'lesson_weekday' => 2 ) );
apse_ok( 2 === (int) $acts->get( $wd_c )['lesson_weekday'], 'corso: il giorno della lezione si salva' );
$acts->update( $wd_c, array( 'lesson_weekday' => 4 ) );
apse_ok( 4 === (int) $acts->get( $wd_c )['lesson_weekday'], 'corso: il giorno della lezione si modifica' );
$acts->enroll( $wd_c, $fy_p, $month );
$wd_s = $acts->status_for_person( $fy_p );
$wd_row = array_values( array_filter( $wd_s, function ( $r ) use ( $wd_c ) {
	return (int) $r['activity']['id'] === $wd_c;
} ) )[0];
$first = \ApSemplice\PaymentCalc::first_lesson( substr( $today, 0, 7 ), 4 );
apse_ok( $first <= $today ? 3000 === $wd_row['summary']['total_due'] : ( 0 === $wd_row['summary']['total_due'] && $first === $wd_row['summary']['upcoming']['date'] ), 'corso: la mensilità del mese in corso è dovuta solo dalla prima lezione (' . $first . ')' );
$dash = apse_render( array( Admin\DashboardPage::class, 'render' ), 'Pagamenti da incassare' );
apse_ok( false !== strpos( $dash, 'Corso Rapido' ) && false !== strpos( $dash, 'apse-income' ), 'bacheca: le mensilità già dovute compaiono con il pulsante per incassare' );
$inc_page = apse_render( array( Admin\IncomePage::class, 'render' ), 'apse-income-data', array( 'person_id' => (string) $fy_p ) );
apse_ok( false !== strpos( $inc_page, 'value="' . $fy_p . '" selected' ) || false !== strpos( $inc_page, "value='" . $fy_p . "' selected" ) || false !== strpos( $inc_page, 'selected=\'selected\'' ), 'incasso: la persona arriva già scelta dalla bacheca' );
apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Disdici il rinnovo', array( 'id' => $wd_c ) );

// ---------- Corsi a pagamento unico, date di fine, calendario ----------
$once_p = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Scrittura', 'last_name' => 'Creativa', 'email' => 'scrittura.creativa@example.com' ) );
$once_c = $acts->create( array( 'name' => 'Scrittura creativa', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 12000, 'billing' => 'once', 'lesson_weekday' => 3, 'lesson_start' => '18:30', 'lesson_end' => '20:00', 'location' => 'Sala Rossa', 'starts_on' => $today, 'ends_on' => gmdate( 'Y-m-d', strtotime( $today . ' +70 days' ) ) ) );
$oc = $acts->get( $once_c );
apse_ok( 'once' === $oc['billing'] && '18:30' === $oc['lesson_start'] && 'Sala Rossa' === $oc['location'] && $today === $oc['starts_on'], 'corso: pagamento unico, orario, luogo e date si salvano' );
$threw = false;
try {
	$acts->create( array( 'name' => 'Orario sbagliato', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 100, 'lesson_weekday' => 2, 'lesson_start' => '20:00', 'lesson_end' => '19:00' ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw, 'corso: l\'orario di fine deve essere dopo quello di inizio' );
$acts->enroll( $once_c, $once_p, $month );
$os = $acts->status_for_person( $once_p )[0]['summary'];
apse_ok( 12000 === $os['total_due'] && empty( $os['regular'] ), 'pagamento unico: la quota intera è dovuta subito all\'iscrizione' );
$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'person_id' => $once_p, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 5000, 'activity_id' => $once_c, 'competence_month' => $month ) ) ) );
$os = $acts->status_for_person( $once_p )[0]['summary'];
apse_ok( 5000 === $os['total_paid'] && -7000 === $os['balance'], 'pagamento unico: si può versare a rate' );
$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'person_id' => $once_p, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 7000, 'activity_id' => $once_c, 'competence_month' => $month ) ) ) );
apse_ok( ! empty( $acts->status_for_person( $once_p )[0]['summary']['regular'] ), 'pagamento unico: completato il totale è in regola' );
apse_ok( false !== strpos( apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'pagamento unico', array( 'id' => $once_c ) ), 'una tantum' ), 'scheda corso: si legge che è una tantum' );

$wk = \ApSemplice\Calendar::weekly_dates( '2026-01-01', '2026-01-31', 3 );
apse_ok( array( '2026-01-07', '2026-01-14', '2026-01-21', '2026-01-28' ) === $wk && array() === \ApSemplice\Calendar::weekly_dates( '2026-02-01', '2026-01-01', 3 ), 'calendario: i mercoledì di gennaio 2026' );
$occ = \ApSemplice\Calendar::occurrences( $today, gmdate( 'Y-m-d', strtotime( $today . ' +120 days' ) ) );
$mine = array_values( array_filter( $occ, function ( $o ) use ( $once_c ) {
	return (int) $o['activity_id'] === $once_c;
} ) );
$last_end = gmdate( 'Y-m-d', strtotime( $today . ' +70 days' ) );
apse_ok( count( $mine ) >= 9 && count( $mine ) <= 11 && $mine[0]['date'] >= $today && end( $mine )['date'] <= $last_end && '18:30' === $mine[0]['start'] && 'Sala Rossa' === $mine[0]['location'], 'calendario: le lezioni del corso vanno dall\'inizio alla fine indicati (' . count( $mine ) . ' incontri)' );
Settings::update( array( 'ical_enabled' => 0 ) );
$token1 = \ApSemplice\Calendar::token();
apse_ok( 32 === strlen( $token1 ) && $token1 === \ApSemplice\Calendar::token() && false !== strpos( \ApSemplice\Calendar::feed_url(), $token1 ), 'calendario: indirizzo segreto stabile' );
\ApSemplice\Calendar::regenerate_token();
apse_ok( \ApSemplice\Calendar::token() !== $token1, 'calendario: si può cambiare l\'indirizzo' );
$ics = \ApSemplice\Calendar::ics();
apse_ok( 0 === strpos( $ics, "BEGIN:VCALENDAR\r\n" ) && false !== strpos( $ics, 'SUMMARY:Scrittura creativa' ) && false !== strpos( $ics, 'LOCATION:Sala Rossa' ) && false === strpos( $ics, 'scrittura.creativa@example.com' ), 'calendario: il file iCalendar ha i corsi ma nessun dato personale' );
$save_ical = new ReflectionMethod( Admin\Actions::class, 'save_ical' );
$save_ical->setAccessible( true );
$save_ical->invoke( null, array( 'ical_enabled' => '1' ) );
apse_ok( \ApSemplice\Calendar::enabled(), 'calendario: il gestore lo pubblica dalle impostazioni' );
$cal_html = apse_render( array( Admin\CalendarPage::class, 'render' ), 'Collegamento a Google Calendar' );
apse_ok( false !== strpos( $cal_html, \ApSemplice\Calendar::token() ) && false !== strpos( $cal_html, 'Scrittura creativa' ), 'calendario: la pagina mostra il mese e l\'indirizzo da incollare in Google Calendar' );
$save_ical->invoke( null, array() );
apse_ok( ! \ApSemplice\Calendar::enabled(), 'calendario: spento di default / si può spegnere' );

// ---------- Più lezioni a settimana, link ai calendari, tessera fino al 31 dicembre ----------
$ms_c = $acts->create( array( 'name' => 'Teatro doppio', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 4000, 'lesson_slots' => array( array( 'day' => 4, 'start' => '19:00', 'end' => '20:00' ), array( 'day' => 1, 'start' => '20:00', 'end' => '21:30' ) ) ) );
$ms = \ApSemplice\ActivityService::slots( $acts->get( $ms_c ) );
apse_ok( 2 === count( $ms ) && 1 === $ms[0]['day'] && '20:00' === $ms[0]['start'] && 4 === $ms[1]['day'] && '19:00' === $ms[1]['start'], 'corso: più giorni a settimana (lunedì alle 20 e giovedì alle 19), in ordine' );
$ms_occ = array_values(
	array_filter(
		\ApSemplice\Calendar::occurrences( $today, gmdate( 'Y-m-d', strtotime( $today . ' +14 days' ) ) ),
		function ( $o ) use ( $ms_c ) {
			return (int) $o['activity_id'] === $ms_c;
		}
	)
);
$ms_days = array_unique(
	array_map(
		function ( $o ) {
			return (int) ( new DateTimeImmutable( $o['date'] ) )->format( 'N' );
		},
		$ms_occ
	)
);
sort( $ms_days );
apse_ok( count( $ms_occ ) >= 3 && array( 1, 4 ) === array_values( $ms_days ), 'calendario: il corso compare in tutti e due i giorni (' . count( $ms_occ ) . ' lezioni in due settimane)' );
$acts->update( $ms_c, array( 'lesson_slots' => array( array( 'day' => 2, 'start' => '18:00', 'end' => '19:00' ) ) ) );
apse_ok( 1 === count( \ApSemplice\ActivityService::slots( $acts->get( $ms_c ) ) ) && 2 === (int) $acts->get( $ms_c )['lesson_weekday'], 'corso: le lezioni si modificano' );
$acts->update( $ms_c, array( 'lesson_weekday' => 5 ) );
apse_ok( array( 5 ) === \ApSemplice\ActivityService::slot_days( $acts->get( $ms_c ) ), 'corso: la modifica con il solo giorno resta valida' );
$form_html = apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Come si paga', array( 'id' => $ms_c ) );
apse_ok( false !== strpos( $form_html, 'apse-when' ) && false !== strpos( $form_html, 'data-rows' ) && false !== strpos( $form_html, 'Aggiungi data' ) && false === strpos( $form_html, 'slot_days' ), 'scheda corso: il programma è a righe dinamiche (data, orario, ricorrente)' );
$pos_enrolled = strpos( $form_html, 'Iscritti e pagamenti' );
$pos_data     = strpos( $form_html, 'Dati dell' );
apse_ok( false !== strpos( $form_html, 'Iscrivi un socio o un ospite' ) && false !== $pos_enrolled && $pos_enrolled < $pos_data, 'scheda corso: gli iscritti stanno nella prima colonna, sotto il modulo di iscrizione' );

Settings::update( array( 'ical_enabled' => 1 ) );
$one_url = \ApSemplice\Calendar::feed_url( $once_c );
$all_url = \ApSemplice\Calendar::feed_url();
apse_ok( false !== strpos( $one_url, '&a=' . $once_c ) && false === strpos( $all_url, '&a=' ), 'calendario: indirizzo per la singola attività e per tutte' );
apse_ok( 0 === strpos( \ApSemplice\Calendar::webcal_url( 'https://sito.example/?apse_ical=x' ), 'webcal://' ) && 0 === strpos( \ApSemplice\Calendar::google_add_url( $all_url ), 'https://calendar.google.com/calendar/r?cid=webcal' ), 'calendario: link per Google, Apple e Outlook' );
$ics_one = \ApSemplice\Calendar::ics( null, $once_c );
apse_ok( false !== strpos( $ics_one, 'Scrittura creativa' ) && false === strpos( $ics_one, 'SUMMARY:Teatro doppio' ) && false !== strpos( \ApSemplice\Calendar::ics(), 'SUMMARY:Teatro doppio' ), 'calendario: il file di una sola attività contiene solo quella' );
$det = apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Solo questa attività', array( 'id' => $once_c ) );
apse_ok( false !== strpos( $det, 'calendar.google.com' ) && false !== strpos( $det, 'Tutte le attività' ), 'scheda attività: link al suo calendario e a quello di tutte' );
Settings::update( array( 'ical_enabled' => 0 ) );

// tessera associativa: scade sempre il 31 dicembre
$mb_p = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Tessera', 'last_name' => 'Dicembre', 'email' => 'tessera.dicembre@example.com' ) );
$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'person_id' => $mb_p, 'lines' => array( array( 'category_id' => $cat['membership'], 'amount_cents' => 1000 ) ) ) );
apse_ok( substr( $today, 0, 4 ) . '-12-31' === $people->active_until( $mb_p ), 'tessera: la scadenza è il 31 dicembre (' . $people->active_until( $mb_p ) . ')' );
apse_ok( substr( $today, 0, 4 ) === Settings::membership_year()->label() && ( (int) substr( $today, 0, 4 ) + 1 ) . '' === Settings::membership_year()->next()->label(), 'tessera: l\'anno della tessera è l\'anno solare' );
$founder_until = $people->active_until( $founder );
apse_ok( null !== $founder_until && $founder_until > ( (int) substr( $today, 0, 4 ) + 5 ) . '-01-01', 'tessera: il socio fondatore resta fuori da questa regola' );

// ---------- Anni solari: quota automatica, anni chiusi, fondi ----------
$count_mb = function ( int $pid ) use ( $wpdb ) {
	return $wpdb->get_col( 'SELECT social_year FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = ' . $pid . ' AND deleted_at IS NULL ORDER BY social_year' );
};
$pay_q = function ( int $pid ) use ( $si, $cash, $today, $cat ) {
	$si->invoke( null, array( 'date' => $today, 'account_id' => (string) $cash['id'], 'person_id' => (string) $pid, 'lines' => array( array( 'category_id' => (string) $cat['membership'], 'amount' => '10,00' ) ) ) );
};
$p_cov = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Anno', 'last_name' => 'Coperto', 'email' => 'anno.coperto@example.com' ) );
$people->set_membership( $p_cov, (string) $cy, true, 'manual' );
apse_ok( array( 'year' => (string) ( $cy + 1 ), 'free' => null ) === $people->membership_plan( $p_cov, $today ), 'quota automatica: chi ha l\'anno in corso rinnova in anticipo, senza regali' );
$pay_q( $p_cov );
apse_ok( array( (string) $cy, (string) ( $cy + 1 ) ) === $count_mb( $p_cov ), 'quota automatica: il socio in regola paga solo l\'anno più recente' );
$p_lap = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Anno', 'last_name' => 'Scaduto', 'email' => 'anno.scaduto@example.com' ) );
$people->set_membership( $p_lap, (string) ( $cy - 1 ), true, 'manual' );
apse_ok( array( 'year' => (string) $cy, 'free' => null ) === $people->membership_plan( $p_lap, $today ), 'quota automatica: chi non ha rinnovato l\'anno in corso paga prima quello, niente omaggio' );
$pay_q( $p_lap );
apse_ok( array( (string) ( $cy - 1 ), (string) $cy ) === $count_mb( $p_lap ), 'quota automatica: il socio scaduto rinnova l\'anno in corso (poi la segreteria gestisce il resto)' );
$p_new = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Anno', 'last_name' => 'Nuovo', 'email' => 'anno.nuovo@example.com' ) );
apse_ok( array( 'year' => (string) ( $cy + 1 ), 'free' => (string) $cy ) === $people->membership_plan( $p_new, $today ), 'quota automatica: il nuovo socio paga l\'anno più recente e ha l\'anno in corso in omaggio' );
$dash_new = apse_render( array( Admin\DashboardPage::class, 'render' ), 'Pagamenti da incassare' );
apse_ok( false !== strpos( $dash_new, 'Anno Nuovo' ) && false !== strpos( $dash_new, 'Quota associativa ' . ( $cy + 1 ) ), 'bacheca: i nuovi soci che devono pagare compaiono nei pagamenti da incassare' );
// annullando l'incasso salta anche l'anno in omaggio
$p_void = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Anno', 'last_name' => 'Annullato', 'email' => 'anno.annullato@example.com' ) );
$pay_q( $p_void );
apse_ok( 2 === count( $count_mb( $p_void ) ), 'quota automatica: due anni dopo l\'incasso' );
$void_tx = (int) $wpdb->get_var( 'SELECT id FROM ' . Db::t( 'transactions' ) . ' WHERE person_id = ' . $p_void . ' ORDER BY id DESC LIMIT 1' );
$ledger->void( $void_tx, 'prova' );
apse_ok( array() === $count_mb( $p_void ), 'quota automatica: annullando l\'incasso si annullano anche i due anni' );

// anni solari: creare, chiudere, riaprire
$fy_far = $cy - 6;
while ( \ApSemplice\FiscalYears::get( $fy_far ) ) {
	--$fy_far; // un anno passato che non esiste ancora
}
\ApSemplice\FiscalYears::create( $fy_far );
$threw = false;
try {
	\ApSemplice\FiscalYears::create( $fy_far );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw && \ApSemplice\FiscalYears::is_open( $fy_far ), 'anni solari: si crea aperto e non si crea due volte' );
$far_date = $fy_far . '-06-15';
$ledger->record_expense( array( 'date' => $far_date, 'account_id' => (int) $cash['id'], 'category_id' => $cat['general_cost'], 'amount_cents' => 100, 'description' => 'anno lontano' ) );
\ApSemplice\FiscalYears::close( $fy_far );
foreach (
	array(
		'spesa' => function () use ( $ledger, $far_date, $cash, $cat ) {
			$ledger->record_expense( array( 'date' => $far_date, 'account_id' => (int) $cash['id'], 'category_id' => $cat['general_cost'], 'amount_cents' => 100, 'description' => 'x' ) );
		},
		'incasso' => function () use ( $ledger, $far_date, $cash, $cat ) {
			$ledger->record_receipt( array( 'date' => $far_date, 'account_id' => (int) $cash['id'], 'lines' => array( array( 'category_id' => $cat['other_income'], 'amount_cents' => 100 ) ) ) );
		},
		'giroconto' => function () use ( $ledger, $far_date, $cash ) {
			$ledger->record_transfer( $far_date, (int) $cash['id'], (int) $GLOBALS['wpdb']->get_var( 'SELECT id FROM ' . Db::t( 'accounts' ) . ' WHERE id <> ' . (int) $cash['id'] . ' AND closed_at IS NULL LIMIT 1' ), 100, '' );
		},
	) as $what => $fn
) {
	$threw = false;
	try {
		$fn();
	} catch ( \InvalidArgumentException $e ) {
		$threw = false !== strpos( $e->getMessage(), 'chiuso' );
	}
	apse_ok( $threw, 'anno chiuso: non accetta ' . $what );
}
$far_tx = (int) $wpdb->get_var( 'SELECT id FROM ' . Db::t( 'transactions' ) . " WHERE tx_date = '$far_date' ORDER BY id DESC LIMIT 1" );
$threw = false;
try {
	$ledger->void( $far_tx, 'x' );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw, 'anno chiuso: non si annullano i suoi movimenti' );
\ApSemplice\FiscalYears::reopen( $fy_far );
$ledger->record_expense( array( 'date' => $far_date, 'account_id' => (int) $cash['id'], 'category_id' => $cat['general_cost'], 'amount_cents' => 100, 'description' => 'riaperto' ) );
apse_ok( true, 'anno riaperto: accetta di nuovo i movimenti' );
$threw = false;
try {
	$ledger->record_expense( array( 'date' => ( $cy + 9 ) . '-01-10', 'account_id' => (int) $cash['id'], 'category_id' => $cat['general_cost'], 'amount_cents' => 100, 'description' => 'non creato' ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = false !== strpos( $e->getMessage(), 'non è stato creato' );
}
apse_ok( $threw, 'anni solari: si registra solo negli anni creati' );

// fondi e chiusura dell'anno: un anno finito con fondi non rimborsati non si chiude
$fyear_fund = $funds->create( 'Fondo anno', 0 );
$funds->deposit( $fyear_fund, 4000, $fy_far . '-06-01' );
$yr = $funds->yearly();
apse_ok( 4000 === $yr['per_fund'][ $fyear_fund ][ $fy_far ]['unsettled'] && $funds->unsettled_until_year( $fy_far ) >= 4000, 'fondi: per ogni anno solare si vede quanto resta da rimborsare' );
$threw = false;
try {
	\ApSemplice\FiscalYears::close( $fy_far );
} catch ( \InvalidArgumentException $e ) {
	$threw = false !== strpos( $e->getMessage(), 'fondi' );
}
apse_ok( $threw && \ApSemplice\FiscalYears::is_open( $fy_far ), 'anni solari: con fondi non rimborsati l\'anno non si chiude' );
$rep_far = Plugin::reports()->period( $fy_far . '-01-01', $fy_far . '-12-31' );
apse_ok( $rep_far['fund_accrued'] >= 4000 && array() !== array_filter( $rep_far['expenses'], function ( $x ) {
	return 0 === strpos( $x['name'], 'Accantonamenti' );
} ), 'anno solare: i fondi accantonati sono uscite dell\'anno anche se non rimborsati' );
$rep_a = Plugin::reports()->period( $cy . '-01-01', $cy . '-12-31' );
$funds->settle( $fyear_fund, (int) $cash['id'], 'cash', $today );
$rep_b = Plugin::reports()->period( $cy . '-01-01', $cy . '-12-31' );
apse_ok( $rep_b['total_expense'] === $rep_a['total_expense'], 'anno solare: pagare il rimborso non conta due volte (' . $rep_a['total_expense'] . ' = ' . $rep_b['total_expense'] . ')' );
\ApSemplice\FiscalYears::close( $fy_far );
apse_ok( ! \ApSemplice\FiscalYears::is_open( $fy_far ), 'anni solari: rimborsato tutto, l\'anno finito si chiude' );
\ApSemplice\FiscalYears::reopen( $fy_far );
// l'anno in corso non si chiude e si apre da solo
$threw = false;
try {
	\ApSemplice\FiscalYears::close( $cy );
} catch ( \InvalidArgumentException $e ) {
	$threw = false !== strpos( $e->getMessage(), 'in corso' );
}
apse_ok( $threw && \ApSemplice\FiscalYears::is_open( $cy ), 'anni solari: l\'anno in corso non si chiude' );
$threw = false;
try {
	\ApSemplice\FiscalYears::close( $cy + 1 );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw, 'anni solari: un anno non ancora iniziato non si chiude' );
$wpdb->update( Db::t( 'fiscal_years' ), array( 'status' => 'closed' ), array( 'year' => $cy ) ); // simulo un anno in corso rimasto chiuso
$ledger->record_expense( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'category_id' => $cat['general_cost'], 'amount_cents' => 100, 'description' => 'anno in corso riaperto da solo' ) );
apse_ok( \ApSemplice\FiscalYears::is_open( $cy ), 'anni solari: se l\'anno in corso non è aperto, si apre da solo al primo movimento' );
$wpdb->delete( Db::t( 'fiscal_years' ), array( 'year' => $cy ) );
delete_option( 'apse_fy_auto' );
\ApSemplice\FiscalYears::maybe_open_current();
apse_ok( \ApSemplice\FiscalYears::is_open( $cy ) && (string) $cy === get_option( 'apse_fy_auto' ), 'anni solari: all\'inizio dell\'anno il nuovo anno si apre in automatico' );
apse_render( array( Admin\YearsPage::class, 'render' ), 'Crea anno solare' );
$rep_html = apse_render( array( Admin\ReportsPage::class, 'render' ), 'Conti e liquidità' );
apse_ok( strpos( $rep_html, 'Conti e liquidità' ) < strpos( $rep_html, 'Anno sociale (attività)' ) && strpos( $rep_html, 'Anno sociale (attività)' ) < strpos( $rep_html, 'Anno solare (commercialista)' ), 'report: prima conti e liquidità, poi anno sociale, poi anno solare' );
apse_render( array( Admin\ReportsPage::class, 'render' ), 'Fondi per anno solare', array( 'mode' => 'solar' ) );

// ---------- Soci sospesi (inattivi) ----------
$sp_a = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Sospeso', 'last_name' => 'Manuale', 'email' => 'sospeso.manuale@example.com' ) );
$people->set_membership( $sp_a, (string) ( $cy - 1 ), true, 'manual' );
$dash_sp = apse_render( array( Admin\DashboardPage::class, 'render' ), 'Soci da rinnovare' );
apse_ok( false !== strpos( $dash_sp, 'Sospeso Manuale' ) && false !== strpos( $dash_sp, 'apse_suspend_member' ) && false !== strpos( $dash_sp, 'Incassa' ), 'bacheca: i soci che non hanno rinnovato hanno i pulsanti Incassa e Sospendi' );
$susp = new ReflectionMethod( Admin\Actions::class, 'suspend_member' );
$susp->setAccessible( true );
$susp->invoke( null, array( 'id' => (string) $sp_a ) );
apse_ok( $people->is_suspended( $sp_a ) && ! $people->is_active_member( $sp_a ), 'sospensione: il socio diventa inattivo' );
$dash_sp2 = apse_render( array( Admin\DashboardPage::class, 'render' ), 'Cassa rapida' );
apse_ok( false === strpos( $dash_sp2, '>Sospeso Manuale</a>' ), 'sospensione: i soci inattivi non si vedono più negli elenchi della bacheca' );
$threw = false;
try {
	$pay_q( $sp_a );
} catch ( \InvalidArgumentException $e ) {
	$threw = false !== strpos( $e->getMessage(), 'sospeso' );
}
apse_ok( $threw, 'sospensione: prima di incassare la quota va riattivato a mano' );
$qe_susp = new ReflectionMethod( Admin\Actions::class, 'quick_enroll' );
$qe_susp->setAccessible( true );
$threw = false;
try {
	$qe_susp->invoke( null, array( 'person_id' => (string) $sp_a, 'target' => 'a:' . $qe_c ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = false !== strpos( $e->getMessage(), 'sospeso' );
}
apse_ok( $threw, 'sospensione: un socio sospeso non si iscrive a corsi né eventi' );
$list_sp = apse_render( array( Admin\PeoplePage::class, 'render_list' ), 'Sospeso (inattivo)', array( 'status' => 'suspended' ) );
apse_ok( false !== strpos( $list_sp, 'Sospeso Manuale' ) || false !== strpos( $list_sp, 'Manuale' ), 'elenco soci: filtro dei sospesi' );
$react = new ReflectionMethod( Admin\Actions::class, 'reactivate_member' );
$react->setAccessible( true );
$react->invoke( null, array( 'id' => (string) $sp_a ) );
apse_ok( ! $people->is_suspended( $sp_a ), 'sospensione: si riattiva a mano' );
$pay_q( $sp_a );
apse_ok( $people->is_active_member( $sp_a ), 'sospensione: dopo la riattivazione si incassa il rinnovo e il socio è attivo' );

// sospensione in blocco: scaduti da oltre 8 mesi
$old_a = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Molto', 'last_name' => 'Scaduto', 'email' => 'molto.scaduto@example.com' ) );
$people->set_membership( $old_a, (string) ( $cy - 3 ), true, 'manual' );
$recent_a = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Appena', 'last_name' => 'Scaduto', 'email' => 'appena.scaduto@example.com' ) );
$people->set_membership( $recent_a, (string) ( $cy - 1 ), true, 'manual' );
$stale_ids = array_column( $people->expired_for_months( 8 ), 'id' );
apse_ok( in_array( (string) $old_a, array_map( 'strval', $stale_ids ), true ), 'sospensione in blocco: chi è scaduto da anni è tra i candidati' );
$list_btn = apse_render( array( Admin\PeoplePage::class, 'render_list' ), 'Sospendi soci scaduti da oltre 8 mesi' );
apse_ok( false !== strpos( $list_btn, 'apse_suspend_expired' ), 'elenco soci: pulsante "sospendi soci scaduti da oltre 8 mesi"' );
$n_susp = $people->suspend_expired( 8 );
apse_ok( $n_susp >= 1 && $people->is_suspended( $old_a ), 'sospensione in blocco: i soci scaduti da molto diventano inattivi' );
$founder_ok = ! $people->is_suspended( $founder );
apse_ok( $founder_ok, 'sospensione in blocco: i fondatori non si toccano' );
apse_ok( 0 === $people->suspend_expired( 8 ), 'sospensione in blocco: rilanciarla non cambia nulla' );

// ---------- Dopo l'iscrizione si apre l'incasso già compilato ----------
$p_inv   = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Iscritto', 'last_name' => 'Senzatessera', 'email' => 'iscritto.senzatessera@example.com' ) );
$res_inv = $qe->invoke( null, array( 'person_id' => (string) $p_inv, 'target' => 'a:' . $qe_c ) );
apse_ok( false !== strpos( $res_inv[0], 'apse-income' ) && false !== strpos( $res_inv[0], 'person_id=' . $p_inv ) && false !== strpos( $res_inv[0], 'due=1' ), 'iscrizione: con la tessera non valida si apre subito l\'incasso con quella persona' );
$free_c   = $acts->create( array( 'name' => 'Corso gratuito', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 0 ) );
$res_free = $qe->invoke( null, array( 'person_id' => (string) $p_cov, 'target' => 'a:' . $free_c ) );
apse_ok( false === strpos( $res_free[0], 'apse-income' ), 'iscrizione: se non c\'è nulla da incassare si resta in bacheca' );
$res_paid = $qe->invoke( null, array( 'person_id' => (string) $p_cov, 'target' => 'a:' . $qe_c ) );
apse_ok( false !== strpos( $res_paid[0], 'apse-income' ) && false !== strpos( $res_paid[1], 'incasso' ), 'iscrizione: con la mensilità dovuta si apre l\'incasso (anche con la tessera in regola)' );

// ---------- Prenotazione di un evento dalla segreteria: se c'è da incassare si apre l'incasso ----------
$bk = new ReflectionMethod( Admin\Actions::class, 'book' );
$bk->setAccessible( true );
$ev_paid = $mkev( 'Serata a pagamento', 700, null );
$ev_free = $mkev( 'Serata libera', 0, null );
$s_paid  = $first_session( $ev_paid );
$s_free  = $first_session( $ev_free );
$res_bp = $bk->invoke( null, array( 'session_id' => (string) $s_paid, 'activity_id' => (string) $ev_paid, 'person_id' => (string) $p_cov ) );
apse_ok( false !== strpos( $res_bp[0], 'apse-income' ) && false !== strpos( $res_bp[0], 'person_id=' . $p_cov ) && false !== strpos( $res_bp[0], 'due=1' ), 'prenotazione evento a pagamento: si apre l\'incasso con il contributo da versare' );
$res_bf = $bk->invoke( null, array( 'session_id' => (string) $s_free, 'activity_id' => (string) $ev_free, 'person_id' => (string) $p_cov ) );
apse_ok( false === strpos( $res_bf[0], 'apse-income' ) && false !== strpos( $res_bf[1], 'Prenotazione registrata' ), 'prenotazione evento gratuito con la tessera in regola: si resta nella scheda dell\'evento' );
$p_nocard = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Evento', 'last_name' => 'Senzatessera', 'email' => 'evento.senzatessera@example.com' ) );
$res_bn = $bk->invoke( null, array( 'session_id' => (string) $s_free, 'activity_id' => (string) $ev_free, 'person_id' => (string) $p_nocard ) );
apse_ok( false !== strpos( $res_bn[0], 'apse-income' ) && false !== strpos( $res_bn[0], 'person_id=' . $p_nocard ), 'prenotazione evento gratuito con la tessera non valida: si apre l\'incasso (la quota associativa)' );

// ---------- Cassa per più persone ----------
$grp = new ReflectionMethod( Admin\Actions::class, 'save_group_cash' );
$grp->setAccessible( true );
$ev_g  = $mkev( 'Cena di gruppo', 800, 1200 );
$s_g   = $first_session( $ev_g );
$g_exp = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Familiare', 'last_name' => 'Scaduto', 'email' => 'familiare.scaduto@example.com' ) );
$people->set_membership( $g_exp, (string) ( $cy - 2 ), true, 'manual' );
$g_sus = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Familiare', 'last_name' => 'Sospeso', 'email' => 'familiare.sospeso@example.com' ) );
$people->set_membership( $g_sus, (string) ( $cy - 2 ), true, 'manual' );
$people->suspend( $g_sus );
$tx_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) );
$line_ev   = array( array( 'kind' => 'event', 'session_id' => (string) $s_g, 'activity_id' => (string) $ev_g, 'amount' => '8,00' ) );
$res_g     = $grp->invoke(
	null,
	array(
		'payer_id' => (string) $p_cov, 'date' => $today, 'account_id' => (string) $cash['id'],
		'people'   => array(
			array( 'id' => (string) $p_cov, 'lines' => $line_ev ),
			array( 'id' => (string) $g_exp, 'lines' => $line_ev ),
			array( 'id' => (string) $g_sus, 'lines' => $line_ev ),
			array( 'new' => '1', 'first' => 'Amico', 'last' => 'Ospite', 'phone' => '338 7776655', 'lines' => array( array( 'kind' => 'event', 'session_id' => (string) $s_g, 'activity_id' => (string) $ev_g, 'amount' => '12,00' ) ) ),
		),
	)
);
$g_rows = $wpdb->get_results( 'SELECT * FROM ' . Db::t( 'transactions' ) . ' WHERE session_id = ' . $s_g . ' AND voided_at IS NULL ORDER BY id', ARRAY_A );
$g_guest = $wpdb->get_row( "SELECT * FROM " . Db::t( 'people' ) . " WHERE last_name = 'Ospite' AND first_name = 'Amico'", ARRAY_A );
apse_ok( 4 === count( $g_rows ) && 1 === count( array_unique( array_column( $g_rows, 'receipt_id' ) ) ), 'cassa multipla: un solo incasso con quattro voci' );
apse_ok( 3 === count( array_filter( $g_rows, function ( $r ) use ( $p_cov ) {
	return (int) $r['payer_person_id'] === $p_cov;
} ) ) && null === $g_rows[0]['payer_person_id'], 'cassa multipla: le voci per gli altri ricordano chi ha pagato' );
apse_ok( $g_guest && 'guest' === $g_guest['type'] && (int) $g_guest['host_person_id'] === $p_cov, 'cassa multipla: il nuovo ospite è creato e collegato a chi paga' );
apse_ok( $acts->has_active_booking( $s_g, $p_cov ) && $acts->has_active_booking( $s_g, $g_exp ) && $acts->has_active_booking( $s_g, $g_sus ) && $acts->has_active_booking( $s_g, (int) $g_guest['id'] ), 'cassa multipla: tutti prenotati, anche il socio sospeso e quello con la tessera scaduta (l\'eccezione per gli eventi)' );
apse_ok( false !== strpos( $res_g[1], '4 voci per 4 persone' ) && false !== strpos( $res_g[1], '36,00' ), 'cassa multipla: messaggio con voci, persone e totale' );
$ledger_html = apse_render( array( Admin\LedgerPage::class, 'render' ), 'pagato da' );
apse_ok( false !== strpos( $ledger_html, 'pagato da' ), 'prima nota: si vede chi ha pagato per un altro' );

// i corsi: tessera in regola, oppure quota nello stesso incasso
$line_co = array( array( 'kind' => 'course', 'activity_id' => (string) $qe_c, 'month' => $month, 'amount' => '10,00' ) );
$threw   = false;
try {
	$grp->invoke( null, array( 'payer_id' => (string) $p_cov, 'date' => $today, 'account_id' => (string) $cash['id'], 'people' => array( array( 'id' => (string) $g_exp, 'lines' => $line_co ) ) ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = false !== strpos( $e->getMessage(), 'tessera' );
}
apse_ok( $threw, 'cassa multipla: per un corso la tessera deve essere in regola' );
$grp->invoke( null, array( 'payer_id' => (string) $p_cov, 'date' => $today, 'account_id' => (string) $cash['id'], 'people' => array( array( 'id' => (string) $g_exp, 'lines' => array_merge( array( array( 'kind' => 'membership', 'amount' => '10,00' ) ), $line_co ) ) ) ) );
apse_ok( $people->is_active_member( $g_exp ) && in_array( $qe_c, $acts->active_activity_ids( $g_exp ), true ), 'cassa multipla: con la quota nello stesso incasso il corso è consentito' );
$threw = false;
try {
	$grp->invoke( null, array( 'payer_id' => (string) $p_cov, 'date' => $today, 'account_id' => (string) $cash['id'], 'people' => array( array( 'id' => (string) $g_sus, 'lines' => $line_co ) ) ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw, 'cassa multipla: un socio sospeso non si iscrive a un corso' );
$threw = false;
try {
	$grp->invoke( null, array( 'payer_id' => (string) $p_cov, 'date' => $today, 'account_id' => (string) $cash['id'], 'people' => array( array( 'id' => (string) $g_sus, 'lines' => array( array( 'kind' => 'membership', 'amount' => '10,00' ) ) ) ) ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = false !== strpos( $e->getMessage(), 'sospeso' );
}
apse_ok( $threw, 'cassa multipla: la quota di un socio sospeso richiede prima la riattivazione' );

// tutto o niente
$tx_mid = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) );
$ev_g2  = $mkev( 'Seconda cena', 500, null );
$s_g2   = $first_session( $ev_g2 );
$threw  = false;
try {
	$grp->invoke(
		null,
		array(
			'payer_id' => (string) $p_cov, 'date' => $today, 'account_id' => (string) $cash['id'],
			'people'   => array(
				array( 'id' => (string) $p_cov, 'lines' => array( array( 'kind' => 'event', 'session_id' => (string) $s_g2, 'activity_id' => (string) $ev_g2, 'amount' => '5,00' ) ) ),
				array( 'id' => (string) $g_sus, 'lines' => $line_co ),
			),
		)
	);
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw && $tx_mid === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) ) && ! $acts->has_active_booking( $s_g2, $p_cov ), 'cassa multipla: se una voce non va, non resta scritto nulla (nemmeno le prenotazioni)' );
$grp_html = apse_render( array( Admin\GroupCashPage::class, 'render' ), 'Cassa per più persone' );
apse_ok( false !== strpos( $grp_html, 'apse-group-data' ) && false !== strpos( $grp_html, 'Nuovo ospite' ) && false !== strpos( $grp_html, 'apse_save_group_cash' ), 'cassa multipla: la pagina ha chi paga, le persone e il nuovo ospite' );
apse_ok( false !== strpos( Admin\Admin::tabs( 'apse-group' ), 'Cassa per più persone' ), 'cassa multipla: è una scheda della Contabilità' );

// ---------- Solo rimborsi: una voce unica per soci e volontari ----------
apse_ok( ! isset( \ApSemplice\Labels::category_kinds()['instructor_reimbursement'] ) && 'Rimborso spese socio/volontario' === \ApSemplice\Labels::category_kinds()['member_reimbursement'][0], 'voci: niente compensi né istruttori, solo "Rimborso spese socio/volontario"' );
$reimb_names = array_column( array_filter( $ledger->categories(), function ( $c ) {
	return 'member_reimbursement' === $c['kind'];
} ), 'name' );
apse_ok( array( 'Rimborso spese socio/volontario' ) === array_values( $reimb_names ), 'voci: nel piano dei conti c\'è una sola voce di rimborso' );
$wpdb->insert( Db::t( 'categories' ), array( 'name' => 'Compenso vecchio', 'kind' => 'instructor_reimbursement', 'fiscal_group' => 'Uscite' ) );
$old_cat = (int) $wpdb->insert_id;
$wpdb->insert( Db::t( 'transactions' ), array( 'tx_date' => $today, 'type' => 'expense', 'amount_cents' => 1234, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'category_id' => $old_cat, 'description' => 'compenso storico', 'created_at' => Db::now() ) );
$old_tx = (int) $wpdb->insert_id;
apse_ok( 1 === \ApSemplice\Install::migrate_reimbursements(), 'migrazione: la vecchia voce "compenso/istruttore" si unisce a quella dei rimborsi' );
$moved = $wpdb->get_row( 'SELECT category_id FROM ' . Db::t( 'transactions' ) . ' WHERE id = ' . $old_tx, ARRAY_A );
$gone  = $wpdb->get_var( 'SELECT deleted_at FROM ' . Db::t( 'categories' ) . ' WHERE id = ' . $old_cat );
apse_ok( (int) $moved['category_id'] === (int) $cat['member_reimbursement'] && null !== $gone, 'migrazione: i movimenti passano alla voce unica e la vecchia sparisce' );
apse_ok( 0 === \ApSemplice\Install::migrate_reimbursements(), 'migrazione: rilanciarla non cambia nulla' );
$wpdb->delete( Db::t( 'transactions' ), array( 'id' => $old_tx ) );
$fund_cat = $wpdb->get_var( 'SELECT c.kind FROM ' . Db::t( 'transactions' ) . ' t JOIN ' . Db::t( 'categories' ) . " c ON c.id = t.category_id WHERE t.person_id IS NOT NULL AND t.description LIKE '%(fondo estinto)' ORDER BY t.id DESC LIMIT 1" );
apse_ok( 'member_reimbursement' === $fund_cat, 'fondi: il rimborso del fondo estinto va nella voce dei rimborsi' );

// ---------- Staff degli eventi: incasso sul posto e contatore dei posti ----------
wp_set_current_user( 1 );
$kar   = $mkev( 'Serata karaoke', 500, 800, array( 'session' => array( 'session_date' => $today, 'capacity' => 2 ) ) );
$kar_s = $first_session( $kar );
$tre_cash_before = $acts->staff_can_cash( $kar, $tre_p );
$acts->add_staff( $kar, $tre_p );
apse_ok( ! $tre_cash_before && ! $acts->staff_can_cash( $kar, $tre_p ) && user_can( $u_tre, 'apse_manage_event', $kar ) && ! user_can( $u_tre, 'apse_door_cash', $kar ) && user_can( 1, 'apse_door_cash', $kar ), 'staff: all\'inizio controlla gli ingressi ma non incassa sul posto; gli amministratori sì' );
$acts->set_staff_cash( $kar, $tre_p, true );
apse_ok( user_can( $u_tre, 'apse_door_cash', $kar ) && ! user_can( $u_tre, 'apse_door_cash', $paid_ev ) && ! user_can( $uq, 'apse_door_cash', $kar ), 'staff: con l\'incasso abilitato incassa sul posto solo per quell\'evento' );
$seat = $acts->seats( $kar_s );
apse_ok( 2 === $seat['capacity'] && 0 === $seat['taken'] && 2 === $seat['free'], 'posti: contatore con evento vuoto' );
$acts->book( $kar_s, $q );
apse_ok( 1 === $acts->seats( $kar_s )['free'], 'posti: una prenotazione lascia un posto libero' );
wp_set_current_user( $u_tre );
$lm_id = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Luca', 'last_name' => 'Lastminute', 'email' => 'luca.lastminute@example.com' ) );
$people->set_membership( $lm_id, Settings::membership_year()->label(), true );
$edo_id = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Edo', 'last_name' => 'Scaduto', 'email' => 'edo.scaduto@example.com' ) );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front, $kar_s, $edo_id ) { $front::do_door( array( 'session_id' => $kar_s, 'person_id' => $edo_id, 'pay' => '1' ) ); } ), 'non è in regola' ) && ! $acts->has_active_booking( $kar_s, $edo_id ), 'ingresso sul posto: un socio con la tessera scaduta non viene prenotato (deve rinnovare)' );
$dm = $front::do_door( array( 'session_id' => $kar_s, 'person_id' => $lm_id, 'pay' => '1' ) );
$lm = $people->get( $lm_id );
$guest_door = $people->create( array( 'type' => 'guest', 'first_name' => 'Gina', 'last_name' => 'Ospite', 'phone' => '333 7770009', 'host_person_id' => $founder ) );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front, $kar_s, $guest_door ) { $front::do_door( array( 'session_id' => $kar_s, 'person_id' => $guest_door, 'pay' => '1' ) ); } ), 'solo dai soci' ) && null !== apse_throws( function () use ( $front, $kar_s ) { $front::do_door( array( 'session_id' => $kar_s, 'new_first_name' => 'Nuovo', 'new_last_name' => 'Ospite', 'new_phone' => '333 7770010', 'host_person_id' => 1 ) ); } ) && ! $acts->has_active_booking( $kar_s, $guest_door ), 'ingresso sul posto: lo staff non incassa dagli ospiti (nemmeno nuovi), li gestisce la segreteria' );
$cash_acc = $wpdb->get_var( 'SELECT account_id FROM ' . Db::t( 'transactions' ) . ' WHERE session_id = ' . $kar_s . ' AND person_id = ' . (int) $lm['id'] . ' AND voided_at IS NULL' );
apse_ok( $lm && 'ordinary' === $lm['type'] && false !== strpos( $dm, 'incassati' ) && 500 === (int) $wpdb->get_var( 'SELECT COALESCE(SUM(amount_cents),0) FROM ' . Db::t( 'transactions' ) . ' WHERE session_id = ' . $kar_s . ' AND person_id = ' . (int) $lm['id'] . " AND type = 'income' AND voided_at IS NULL" ) && 'cash' === $wpdb->get_var( 'SELECT type FROM ' . Db::t( 'accounts' ) . ' WHERE id = ' . (int) $cash_acc ) && 0 === $acts->seats( $kar_s )['free'], 'ingresso sul posto: l\'socio non prenotato viene prenotato, paga in contanti ed entra; i posti finiscono' );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front, $kar_s, $founder ) { $front::do_door( array( 'session_id' => $kar_s, 'person_id' => $founder, 'pay' => '1' ) ); } ), 'Posti esauriti' ), 'ingresso sul posto: a posti esauriti viene rifiutato' );
$_GET['apse_session'] = (string) $kar_s;
$kdet = $as( $u_tre, '[apsemplice_ingressi]' );
unset( $_GET['apse_session'] );
apse_ok( false !== strpos( $kdet, 'Posti esauriti' ) && false !== strpos( $kdet, '2 prenotati su 2' ) && false === strpos( $kdet, 'Prenota, incassa' ), 'area soci: il contatore mostra i posti esauriti e il modulo scompare' );
$wpdb->update( Db::t( 'sessions' ), array( 'capacity' => 5 ), array( 'id' => $kar_s ) );
$_GET['apse_session'] = (string) $kar_s;
$kdet = $as( $u_tre, '[apsemplice_ingressi]' );
unset( $_GET['apse_session'] );
apse_ok( false !== strpos( $kdet, 'Posti liberi: 3' ) && false !== strpos( $kdet, 'Prenota, incassa' ), 'area soci: con posti liberi il contatore e il modulo di incasso sono visibili a chi può incassare' );
$acts->set_staff_cash( $kar, $tre_p, false );
$_GET['apse_session'] = (string) $kar_s;
$kdet = $as( $u_tre, '[apsemplice_ingressi]' );
unset( $_GET['apse_session'] );
apse_ok( false !== strpos( $kdet, 'Posti liberi: 3' ) && false === strpos( $kdet, 'Prenota, incassa' ) && null !== apse_throws( function () use ( $front, $kar_s, $q ) { $front::do_door( array( 'session_id' => $kar_s, 'person_id' => $q ) ); } ), 'staff senza incasso: vede i posti ma non può vendere sul posto' );
wp_set_current_user( 1 );
$acts->remove_staff( $kar, $tre_p );

// ---------- Tesoriere che incassa, cariche del consiglio ----------
wp_set_current_user( 1 );
Access::set_treasurer( $u_tre, true );
apse_ok( user_can( $u_tre, 'apse_collect', 0 ) && ! user_can( $uq, 'apse_collect', 0 ), 'tesoriere: può incassare, un socio qualsiasi no' );
$tc_p   = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Tina', 'last_name' => 'Cassiera', 'email' => 'tina.cassiera@example.com' ) );
$tc_ev  = $mkev( 'Tombola', 700, 900 );
$tc_s   = $first_session( $tc_ev );
wp_set_current_user( $u_tre );
$front::do_collect( array( 'person_id' => $tc_p, 'account_id' => (int) $cash['id'], 'lines' => array( array( 'what' => 'm', 'amount' => '10' ), array( 'what' => 's:' . $tc_s, 'amount' => '7' ), array( 'what' => '', 'amount' => '' ) ) ) );
$tc_tot = (int) $wpdb->get_var( 'SELECT COALESCE(SUM(amount_cents),0) FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND person_id = $tc_p" );
apse_ok( 1700 === $tc_tot && $people->has_membership( $tc_p, Settings::membership_year()->label() ), 'tesoriere: incassa quota associativa ed evento in un solo incasso' );
apse_ok( null !== apse_throws( function () use ( $front, $tc_p, $cash ) { $front::do_collect( array( 'person_id' => $tc_p, 'account_id' => (int) $cash['id'], 'lines' => array( array( 'what' => '', 'amount' => '' ) ) ) ); } ), 'tesoriere: senza voci non incassa' );
$tc2 = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Ugo', 'last_name' => 'Senzatessera', 'email' => 'ugo.senzatessera@example.com' ) );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front, $tc2, $tc_s, $cash ) { $front::do_collect( array( 'person_id' => $tc2, 'account_id' => (int) $cash['id'], 'lines' => array( array( 'what' => 's:' . $tc_s, 'amount' => '7' ) ) ) ); } ), 'non è in regola' ) && ! $acts->has_active_booking( $tc_s, $tc2 ), 'tesoriere: un evento a un socio con la tessera scaduta solo insieme al rinnovo' );
$front::do_collect( array( 'person_id' => $tc2, 'account_id' => (int) $cash['id'], 'lines' => array( array( 'what' => 'm', 'amount' => '10' ), array( 'what' => 's:' . $tc_s, 'amount' => '7' ) ) ) );
apse_ok( $acts->has_active_booking( $tc_s, $tc2 ) && $people->has_membership( $tc2, Settings::membership_year()->label() ), 'tesoriere: rinnovo ed evento nello stesso incasso vanno a buon fine' );
wp_set_current_user( $uq );
apse_ok( null !== apse_throws( function () use ( $front, $tc_p, $cash ) { $front::do_collect( array( 'person_id' => $tc_p, 'account_id' => (int) $cash['id'], 'lines' => array( array( 'what' => 'm', 'amount' => '10' ) ) ) ); } ), 'incasso dall\'area soci: un socio qualsiasi viene rifiutato' );
wp_set_current_user( 1 );
Access::set_treasurer( $u_tre, false );

// cariche
apse_ok( 7 === Settings::councillors(), 'consiglio: sette consiglieri di default' );
$bp = array();
for ( $i = 0; $i < 10; $i++ ) {
	$bp[ $i ] = $people->create( array( 'type' => 0 === $i % 2 ? 'ordinary' : 'founder', 'first_name' => 'Cons' . $i, 'last_name' => 'Direttivo' . $i, 'email' => "cons$i@example.com" ) );
	if ( 'ordinary' === $people->get( $bp[ $i ] )['type'] ) {
		$people->set_membership( $bp[ $i ], Settings::membership_year()->label(), true );
	}
}
$people->set_board_role( $bp[0], 'president' );
apse_ok( null !== apse_throws( function () use ( $people, $bp ) { $people->set_board_role( $bp[1], 'president' ); } ), 'consiglio: un solo presidente' );
$people->set_board_role( $bp[1], 'vice_president' );
apse_ok( null !== apse_throws( function () use ( $people, $bp ) { $people->set_board_role( $bp[2], 'vice_president' ); } ), 'consiglio: un solo vicepresidente' );
for ( $i = 2; $i < 9; $i++ ) {
	$people->set_board_role( $bp[ $i ], 'councillor' );
}
apse_ok( false !== strpos( (string) apse_throws( function () use ( $people, $bp ) { $people->set_board_role( $bp[9], 'councillor' ); } ), 'Posti già coperti' ), 'consiglio: massimo sette consiglieri' );
Settings::update( array( 'board_councillors' => 8 ) );
$people->set_board_role( $bp[9], 'councillor' );
apse_ok( 10 === count( $people->board() ) && 'president' === $people->board()[0]['board_role'] && 'vice_president' === $people->board()[1]['board_role'], 'consiglio: il numero dei consiglieri si cambia nelle impostazioni; elenco ordinato per carica' );
Settings::update( array( 'board_councillors' => 7 ) );
// ruoli: presidente e vice come la segreteria; staff dell'ente; tesoriere (iscrizioni e vendita eventi)
$bp0_u = (int) $people->get( $bp[0] )['wp_user_id'];
$bp1_u = (int) $people->get( $bp[1] )['wp_user_id'];
$bp5_u = (int) $people->get( $bp[5] )['wp_user_id'];
apse_ok( user_can( $bp0_u, Plugin::CAP_OPS ) && user_can( $bp1_u, Plugin::CAP_OPS ) && ! user_can( $bp5_u, Plugin::CAP_OPS ) && ! user_can( $bp0_u, Plugin::CAP ), 'ruoli: presidente e vicepresidente agiscono come la segreteria, un consigliere no, nessuno diventa amministratore del sito' );
apse_ok( Access::user_can( $bp0_u, 'apse_manage_event', $yoga ) && Access::user_can( $bp1_u, 'apse_collect', 0 ) && ! Access::user_can( $bp5_u, 'apse_collect', 0 ), 'ruoli: il presidente ha i permessi della segreteria, il consigliere nessuno' );
apse_ok( 'https://x.example/' === Gatekeeper::filter_login_redirect( 'https://x.example/', '', get_userdata( $bp0_u ) ) && Gatekeeper::filter_login_redirect( 'https://x.example/', '', get_userdata( $bp5_u ) ) !== 'https://x.example/', 'ruoli: il presidente entra nell\'amministrazione, il consigliere resta nell\'area soci' );
$people->set_board_role( $bp[0], null );
apse_ok( ! user_can( $bp0_u, Plugin::CAP_OPS ), 'ruoli: tolta la carica, i permessi della segreteria finiscono' );
$people->set_board_role( $bp[0], 'president' );
$stf_p = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Sara', 'last_name' => 'Staffer', 'email' => 'sara.staffer@example.com' ) );
$people->set_membership( $stf_p, Settings::membership_year()->label(), true );
$stf_u = (int) $people->get( $stf_p )['wp_user_id'];
apse_ok( ! Access::user_can( $stf_u, 'apse_manage_event', $yoga ), 'staff: un socio qualunque non verifica gli ingressi' );
Access::set_entity_staff( $stf_u, true );
apse_ok( Access::user_can( $stf_u, 'apse_manage_event', $yoga ) && ! Access::user_can( $stf_u, 'apse_door_cash', $yoga ) && ! Access::user_can( $stf_u, 'apse_collect', 0 ) && ! Access::user_can( $stf_u, 'apse_add_expense', 0 ) && ! Access::user_can( $stf_u, 'apse_register_member', 0 ), 'staff dell\'ente: verifica gli ingressi di tutti gli eventi, non incassa né registra spese né iscrive' );
Access::set_entity_staff( $stf_u, false );
apse_ok( ! Access::user_can( $stf_u, 'apse_manage_event', $yoga ), 'staff: tolto il ruolo, niente più accessi' );
Access::set_treasurer( $u_tre, true );
apse_ok( Access::user_can( $u_tre, 'apse_door_cash', $yoga ) && Access::user_can( $u_tre, 'apse_register_member', 0 ) && Access::user_can( $u_tre, 'apse_collect', 0 ) && ! Access::user_can( $u_tre, 'apse_manage_event', $yoga ) && ! Access::user_can( $stf_u, 'apse_door_cash', $yoga ), 'tesoriere: incassa, registra spese, iscrive e vende gli eventi; non verifica gli ingressi' );
$nm_lv = 0;
$nm_fl = 0;
foreach ( \ApSemplice\Levels::all( true ) as $nm_l ) {
	if ( 'ordinary' === $nm_l['base_type'] && ! $nm_lv ) {
		$nm_lv = (int) $nm_l['id'];
	}
	if ( 'founder' === $nm_l['base_type'] && ! $nm_fl ) {
		$nm_fl = (int) $nm_l['id'];
	}
}
wp_set_current_user( $u_tre );
$nm_msg = \ApSemplice\Frontend\Actions::do_new_member( array( 'first_name' => 'Nino', 'last_name' => 'Nuovoiscritto', 'level_id' => (string) $nm_lv ) );
$nm_found = $people->search( array( 'q' => 'Nuovoiscritto' ) );
apse_ok( false !== strpos( $nm_msg, 'Socio registrato' ) && 1 === count( $nm_found ) && 'ordinary' === $nm_found[0]['type'], 'tesoriere: iscrive un nuovo socio dall\'area riservata con solo nome e cognome' );
apse_ok( null !== apse_throws( function () use ( $nm_fl ) { \ApSemplice\Frontend\Actions::do_new_member( array( 'first_name' => 'Fausto', 'last_name' => 'Fondatore', 'level_id' => (string) $nm_fl ) ); } ) && null !== apse_throws( function () use ( $nm_lv ) { \ApSemplice\Frontend\Actions::do_new_member( array( 'first_name' => '', 'last_name' => 'Senza', 'level_id' => (string) $nm_lv ) ); } ), 'tesoriere: non crea fondatori né persone senza nome' );
wp_set_current_user( $stf_u );
apse_ok( null !== apse_throws( function () use ( $nm_lv ) { \ApSemplice\Frontend\Actions::do_new_member( array( 'first_name' => 'Abusivo', 'last_name' => 'Test', 'level_id' => (string) $nm_lv ) ); } ), 'iscrizione dall\'area riservata: chi non è tesoriere non può' );
wp_set_current_user( 1 );
Access::set_treasurer( $u_tre, false );
$rm = Admin\TechPage::matrix();
$rh = array_map( function ( $h ) { return $h['roles']; }, Admin\TechPage::role_holders() );
apse_ok( 8 === count( $rm['columns'] ) && 'Presidente e vice' === $rm['columns'][2] && count( $rh ) >= 2, 'ruoli: la pagina elenca la matrice dei permessi e chi ha un ruolo' );
$vol_b =$people->create( array( 'type' => 'volunteer', 'first_name' => 'Vera', 'last_name' => 'Volontaria', 'email' => 'vera.volontaria@example.com' ) );
$exp_b = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Ugo', 'last_name' => 'Scaduto', 'email' => 'ugo.scaduto@example.com' ) );
apse_ok( null !== apse_throws( function () use ( $people, $vol_b ) { $people->set_board_role( $vol_b, 'councillor' ); } ) && null !== apse_throws( function () use ( $people, $guest_door ) { $people->set_board_role( $guest_door, 'councillor' ); } ), 'consiglio: solo soci fondatori e ordinari (non volontari né ospiti)' );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $people, $exp_b ) { $people->set_board_role( $exp_b, 'president' ); } ), 'in regola' ), 'consiglio: serve la tessera in regola' );
$people->set_board_role( $bp[0], null );
apse_ok( '' === (string) $people->get( $bp[0] )['board_role'] && 9 === count( $people->board() ), 'consiglio: si toglie la carica' );
apse_render( array( Admin\PeoplePage::class, 'render_list' ), 'Consiglio direttivo' );
apse_render( array( Admin\SettingsPage::class, 'render' ), 'consiglieri' );
apse_ok( has_action( 'admin_post_apse_set_board_role' ), 'consiglio: azione di assegnazione registrata' );

// ---------- Revisione di sicurezza: messaggi firmati, shortcode riservati, importi enormi ----------
wp_set_current_user( 1 );
$f_url = \ApSemplice\Flash::url( 'https://example.org/area/', 'apsf', 'Spesa registrata.' );
parse_str( (string) wp_parse_url( $f_url, PHP_URL_QUERY ), $f_q );
$old_get = $_GET;
$_GET    = $f_q;
$f_ok    = \ApSemplice\Flash::read( 'apsf' );
$_GET    = array( 'apsf_err' => 'Il tuo conto è sospeso: chiama il 333 0000000' );
$f_fake  = \ApSemplice\Flash::read( 'apsf' );
$_GET    = array( 'apsf_ok' => 'Altro testo', 'apsf_sig' => (string) ( $f_q['apsf_sig'] ?? '' ) );
$f_alt   = \ApSemplice\Flash::read( 'apsf' );
$_GET    = array( 'apsf_err' => 'Spesa registrata.', 'apsf_sig' => (string) ( $f_q['apsf_sig'] ?? '' ) );
$f_kind  = \ApSemplice\Flash::read( 'apsf' );
$_GET    = $old_get;
apse_ok( 'Spesa registrata.' === $f_ok['ok'] && '' === $f_ok['err'] && '' === $f_fake['err'] && '' === $f_fake['ok'] && '' === $f_alt['ok'] && '' === $f_kind['err'], 'messaggi di esito: si mostrano solo quelli firmati dal sito (un testo messo in un link viene ignorato)' );
$GLOBALS['apse_probe'] = 0;
add_shortcode( 'apse_probe', function () { $GLOBALS['apse_probe']++; return 'SEGRETO'; } );
wp_set_current_user( 0 );
$gate = do_shortcode( '[apsemplice_riservato accesso="soci"][apse_probe][/apsemplice_riservato]' );
wp_set_current_user( 1 );
apse_ok( 0 === $GLOBALS['apse_probe'] && false === strpos( $gate, 'SEGRETO' ) && false !== strpos( $gate, 'apsf-gate' ), 'contenuto riservato: gli shortcode dentro non vengono nemmeno eseguiti per chi non ha diritto' );
apse_ok( 'SEGRETO' === do_shortcode( '[apsemplice_riservato accesso="soci"][apse_probe][/apsemplice_riservato]' ) && 1 === $GLOBALS['apse_probe'], 'contenuto riservato: per chi ha diritto (amministratore) si vede' );
remove_shortcode( 'apse_probe' );
apse_ok( null === \ApSemplice\Money::parse( '99999999999999999999' ) && null === \ApSemplice\Money::parse( '-5000000000' ) && 1050 === \ApSemplice\Money::parse( '10,50' ) && 100000000000 === \ApSemplice\Money::parse( '1.000.000.000' ), 'importi: i valori assurdi sono rifiutati, quelli normali no' );

// ---------- Tesoriere: corsi e cassa per più persone; staff: cassa per più soci ----------
wp_set_current_user( 1 );
Access::set_treasurer( $u_tre, true );
$mkm = function ( string $first, string $last ) use ( $people ) {
	$id = $people->create( array( 'type' => 'ordinary', 'first_name' => $first, 'last_name' => $last, 'email' => strtolower( $first . '.' . $last ) . '@example.com' ) );
	$people->set_membership( $id, Settings::membership_year()->label(), true );
	return $id;
};
$acq   = $acts->create( array( 'name' => 'Acquerello', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 2000, 'guest_fee_cents' => 3500 ) );
$tc3   = $mkm( 'Carla', 'Corsista' );
$tc4   = $mkm( 'Dario', 'Corsista' );
wp_set_current_user( $u_tre );
$front::do_collect( array( 'person_id' => $tc3, 'account_id' => (int) $cash['id'], 'lines' => array( array( 'what' => 'k:' . $acq, 'amount' => '20' ) ) ) );
$tc3_in = (int) $wpdb->get_var( 'SELECT COALESCE(SUM(amount_cents),0) FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND person_id = $tc3 AND activity_id = $acq" );
apse_ok( in_array( $acq, $acts->active_activity_ids( $tc3 ), true ) && 2000 === $tc3_in, 'tesoriere: incassa un corso (si iscrive da solo e incassa il mese)' );
$gm = $front::do_group_collect(
	array(
		'payer_id' => $tc3, 'account_id' => (int) $cash['id'],
		'rows'     => array(
			array( 'person' => '', 'first' => 'Gina', 'last' => 'Nuovaospite', 'phone' => '333 6660001', 'what' => 's:' . $tc_s, 'amount' => '' ),
			array( 'person' => (string) $tc4, 'what' => 'k:' . $acq, 'amount' => '' ),
			array( 'person' => (string) $tc4, 'what' => 's:' . $tc_s, 'amount' => '' ),
			array( 'person' => '', 'what' => '' ),
		),
	)
);
$gina = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE last_name = 'Nuovaospite'", ARRAY_A );
apse_ok( $gina && 'guest' === $gina['type'] && (int) $gina['host_person_id'] === $tc3 && $acts->has_active_booking( $tc_s, (int) $gina['id'] ) && $acts->has_active_booking( $tc_s, $tc4 ) && in_array( $acq, $acts->active_activity_ids( $tc4 ), true ) && false !== strpos( $gm, '2 persone' ) && false !== strpos( $gm, '36,00' ), 'tesoriere: cassa per più persone (nuovo ospite, evento e corso, importi standard, un solo incasso)' );
$gp = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT receipt_id) FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND payer_person_id = $tc3 AND person_id <> $tc3" );
apse_ok( 1 === $gp, 'tesoriere: la cassa per più persone è un solo incasso intestato a chi paga' );
wp_set_current_user( $uq );
apse_ok( null !== apse_throws( function () use ( $front, $tc3, $cash ) { $front::do_group_collect( array( 'payer_id' => $tc3, 'account_id' => (int) $cash['id'], 'rows' => array( array( 'person' => (string) $tc3, 'what' => 'm', 'amount' => '10' ) ) ) ); } ), 'cassa per più persone: un socio qualsiasi viene rifiutato' );
wp_set_current_user( 1 );
Access::set_treasurer( $u_tre, false );

// staff: cassa per più soci sul posto
$quiz   = $mkev( 'Serata quiz', 500, 800, array( 'session' => array( 'session_date' => $today, 'capacity' => 10 ) ) );
$quiz_s = $first_session( $quiz );
$acts->add_staff( $quiz, $tre_p );
$sa1 = $mkm( 'Sara', 'Quiz' );
$sa2 = $mkm( 'Saul', 'Quiz' );
$sa3 = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Sonia', 'last_name' => 'Scaduta', 'email' => 'sonia.scaduta@example.com' ) );
$sg  = $people->create( array( 'type' => 'guest', 'first_name' => 'Gino', 'last_name' => 'Quizospite', 'phone' => '333 6660002', 'host_person_id' => $sa1 ) );
wp_set_current_user( $u_tre );
apse_ok( null !== apse_throws( function () use ( $front, $quiz_s, $sa1, $sa2, $cash ) { $front::do_door_group( array( 'session_id' => $quiz_s, 'payer_id' => $sa1, 'account_id' => (int) $cash['id'], 'rows' => array( array( 'person' => (string) $sa1 ), array( 'person' => (string) $sa2 ) ) ) ); } ), 'staff senza incasso abilitato: niente cassa per più soci' );
$acts->set_staff_cash( $quiz, $tre_p, true );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front, $quiz_s, $sa1, $sa3, $cash ) { $front::do_door_group( array( 'session_id' => $quiz_s, 'payer_id' => $sa1, 'account_id' => (int) $cash['id'], 'rows' => array( array( 'person' => (string) $sa1 ), array( 'person' => (string) $sa3 ) ) ) ); } ), 'non è in regola' ) && ! $acts->has_active_booking( $quiz_s, $sa1 ), 'staff: un socio con la tessera scaduta blocca tutto, nulla resta scritto' );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front, $quiz_s, $sa1, $sg, $cash ) { $front::do_door_group( array( 'session_id' => $quiz_s, 'payer_id' => $sa1, 'account_id' => (int) $cash['id'], 'rows' => array( array( 'person' => (string) $sg ) ) ) ); } ), 'solo dai soci' ), 'staff: gli ospiti non si incassano nella cassa per più soci' );
$bank = $ledger->default_account_for( 'bank' );
apse_ok( 'bank' === $bank['type'] && null !== apse_throws( function () use ( $front, $quiz_s, $sa1, $bank ) { $front::do_door_group( array( 'session_id' => $quiz_s, 'payer_id' => $sa1, 'account_id' => (int) $bank['id'], 'rows' => array( array( 'person' => (string) $sa1 ) ) ) ); } ), 'staff: solo contanti o POS' );
$sm = $front::do_door_group( array( 'session_id' => $quiz_s, 'payer_id' => $sa1, 'account_id' => (int) $cash['id'], 'rows' => array( array( 'person' => (string) $sa1 ), array( 'person' => (string) $sa2 ), array( 'person' => (string) $sa2 ) ) ) );
$sq = (int) $wpdb->get_var( 'SELECT COALESCE(SUM(amount_cents),0) FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND session_id = $quiz_s" );
apse_ok( 1000 === $sq && $acts->has_active_booking( $quiz_s, $sa1 ) && $acts->has_active_booking( $quiz_s, $sa2 ) && false !== strpos( $sm, '2 soci' ) && ! empty( $acts->booking( $quiz_s, $sa1 )['checked_in_at'] ), 'staff: un socio paga per due soci, importi calcolati dal sito, ingressi registrati' );
wp_set_current_user( $uq );
apse_ok( null !== apse_throws( function () use ( $front, $quiz_s, $sa1, $cash ) { $front::do_door_group( array( 'session_id' => $quiz_s, 'payer_id' => $sa1, 'account_id' => (int) $cash['id'], 'rows' => array( array( 'person' => (string) $sa1 ) ) ) ); } ), 'cassa per più soci sul posto: chi non è staff viene rifiutato' );
wp_set_current_user( 1 );
$acts->remove_staff( $quiz, $tre_p );
$_GET['apse_session'] = (string) $quiz_s;
$acts->add_staff( $quiz, $tre_p );
$acts->set_staff_cash( $quiz, $tre_p, true );
$qdet = $as( $u_tre, '[apsemplice_ingressi]' );
unset( $_GET['apse_session'] );
apse_ok( false !== strpos( $qdet, 'apse_front_door_group' ), 'area soci: lo staff abilitato vede il modulo per più soci' );
$acts->remove_staff( $quiz, $tre_p );

// ---------- Promemoria, privacy, ricevute ----------
wp_set_current_user( 1 );
$rm = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$rm ) {
		$atts['_exists'] = array();
		foreach ( (array) ( $atts['attachments'] ?? array() ) as $f ) {
			$atts['_exists'][] = file_exists( $f ) ? basename( $f ) : '';
		}
		$rm[] = $atts;
		return true;
	},
	10,
	2
);
$mails_to = function ( string $email ) use ( &$rm ) {
	$n = 0;
	foreach ( $rm as $m ) {
		if ( in_array( $email, (array) $m['to'], true ) ) {
			$n++;
		}
	}
	return $n;
};
$mail_text = function ( string $email ) use ( &$rm ) {
	$t = '';
	foreach ( $rm as $m ) {
		if ( in_array( $email, (array) $m['to'], true ) ) {
			$t .= $m['subject'] . "\n" . $m['message'] . "\n";
		}
	}
	return $t;
};
$ym_label = Settings::membership_year()->label();

// --- promemoria
Settings::update( array( 'reminders_enabled' => 0, 'reminders_membership' => 1, 'reminders_membership_days' => 30, 'reminders_dues' => 0, 'reminders_events' => 1 ) );
$rp   = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Rita', 'last_name' => 'Promemoria', 'email' => 'rita.promemoria@example.com' ) );
$rp2  = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Raul', 'last_name' => 'Scaduto', 'email' => 'raul.scaduto@example.com' ) );
$people->set_membership( $rp, $ym_label, true );
$people->set_membership( $rp2, $ym_label, true );
$wpdb->update( Db::t( 'memberships' ), array( 'valid_to' => gmdate( 'Y-m-d', strtotime( $today . ' +10 days' ) ) ), array( 'person_id' => $rp ) );
$wpdb->update( Db::t( 'memberships' ), array( 'valid_to' => gmdate( 'Y-m-d', strtotime( $today . ' -3 days' ) ) ), array( 'person_id' => $rp2 ) );
$r_off = \ApSemplice\Reminders::run( $today );
apse_ok( array( 0, 0, 0 ) === array_values( $r_off ) && 0 === $mails_to( 'rita.promemoria@example.com' ), 'promemoria: spenti di default, nessuna email' );
$prev = \ApSemplice\Reminders::run( $today, false );
apse_ok( $prev['membership'] >= 2 && 0 === $mails_to( 'rita.promemoria@example.com' ), 'promemoria: l\'anteprima conta ma non manda nulla' );
$rm_ev   = $mkev( 'Gita sociale', 0, null, array( 'session' => array( 'session_date' => gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) ), 'start_time' => '09:30', 'location' => 'Piazza Grande', 'capacity' => 20 ) ) );
$rm_s    = $first_session( $rm_ev );
$rp_g    = $people->create( array( 'type' => 'guest', 'first_name' => 'Gianna', 'last_name' => 'Ospitedirita', 'phone' => '333 4440001', 'host_person_id' => $rp ) );
$acts->book( $rm_s, $rp );
$acts->book( $rm_s, $rp_g );
Settings::update( array( 'reminders_enabled' => 1 ) );
$r_on = \ApSemplice\Reminders::run( $today );
$t_rita = $mail_text( 'rita.promemoria@example.com' );
apse_ok( false !== strpos( $t_rita, 'sta per scadere' ) && false !== strpos( $t_rita, 'Gita sociale' ) && false !== strpos( $t_rita, 'per Gianna Ospitedirita' ) && false !== strpos( $mail_text( 'raul.scaduto@example.com' ), 'è scaduta' ), 'promemoria: tessera in scadenza, scaduta da poco, evento di domani anche per l\'ospite (via il socio)' );
$n_rita = $mails_to( 'rita.promemoria@example.com' );
\ApSemplice\Reminders::run( $today );
apse_ok( $n_rita === $mails_to( 'rita.promemoria@example.com' ) && $r_on['events'] >= 2, 'promemoria: ogni promemoria si manda una sola volta' );
// corsi mensili: dopo l'ultima lezione del mese, promemoria a chi non ha pagato il mese dopo
$cur_m = substr( $today, 0, 7 );
$tgt_m = gmdate( 'Y-m', strtotime( $cur_m . '-01 +1 month' ) );
if ( in_array( $tgt_m, Settings::social_year()->months(), true ) ) {
	Settings::update( array( 'reminders_dues' => 1, 'reminders_membership' => 0, 'reminders_events' => 0 ) );
	$mk_course = function ( string $name, string $day ) use ( $acts, $sy_label, $cur_m ) {
		return $acts->create( array( 'name' => $name, 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 2000, 'lesson_slots' => array( array( 'type' => 'single', 'date' => $cur_m . '-' . $day, 'start' => '18:00', 'end' => '19:00' ) ) ) );
	};
	$c_done  = $mk_course( 'Corso già finito nel mese', '10' );
	$c_later = $mk_course( 'Corso con lezione ancora da fare', '28' );
	$dm1 = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Dina', 'last_name' => 'Nonpagato', 'email' => 'dina.nonpagato@example.com' ) );
	$dm2 = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Dino', 'last_name' => 'Pagato', 'email' => 'dino.pagato@example.com' ) );
	foreach ( array( $dm1, $dm2 ) as $pid ) {
		$acts->enroll( $c_done, $pid, $cur_m );
		$acts->enroll( $c_later, $pid, $cur_m );
	}
	$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'person_id' => $dm2, 'lines' => array( array( 'category_id' => $ledger->category_id_of_kind( 'activity_fee' ), 'amount_cents' => 2000, 'activity_id' => $c_done, 'competence_month' => $tgt_m ) ) ) );
	$d_before = $mails_to( 'dina.nonpagato@example.com' );
	\ApSemplice\Reminders::run( $cur_m . '-09' );
	apse_ok( $d_before === $mails_to( 'dina.nonpagato@example.com' ), 'promemoria corsi: prima dell\'ultima lezione del mese non parte nulla' );
	\ApSemplice\Reminders::run( $cur_m . '-11' );
	$t_dina = $mail_text( 'dina.nonpagato@example.com' );
	apse_ok( false !== strpos( $t_dina, 'Corso già finito nel mese' ) && false === strpos( $t_dina, 'ancora da fare' ) && 0 === $mails_to( 'dino.pagato@example.com' ), 'promemoria corsi: dopo l\'ultima lezione, solo a chi non ha pagato il mese dopo, e solo per il corso con lezioni finite' );
	$d_n = $mails_to( 'dina.nonpagato@example.com' );
	\ApSemplice\Reminders::run( $cur_m . '-12' );
	apse_ok( $d_n === $mails_to( 'dina.nonpagato@example.com' ), 'promemoria corsi: un solo promemoria per corso e mese' );
	\ApSemplice\Reminders::run( $cur_m . '-29' );
	apse_ok( false !== strpos( $mail_text( 'dina.nonpagato@example.com' ), 'ancora da fare' ), 'promemoria corsi: il corso con lezione più tardi riceve il promemoria dopo la sua ultima lezione' );
	Settings::update( array( 'reminders_dues' => 0, 'reminders_membership' => 1, 'reminders_events' => 1 ) );
}
$last = \ApSemplice\Reminders::last_run();
apse_ok( ! empty( $last['at'] ), 'promemoria: l\'ultimo invio è registrato' );
Settings::update( array( 'reminders_enabled' => 0, 'reminders_dues' => 1 ) );
apse_render( array( Admin\CommsPage::class, 'render' ), 'Promemoria per email' );

// --- privacy
$pv = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Gianni', 'last_name' => 'Privacy', 'email' => 'gianni.privacy@example.com', 'phone' => '333 4440002', 'tax_code' => 'PRVGNN80A01H501Z' ) );
$pv_uid = (int) $people->get( $pv )['wp_user_id'];
$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'person_id' => $pv, 'lines' => array( array( 'category_id' => $ledger->category_id_of_kind( 'donation' ), 'amount_cents' => 500, 'description' => 'Offerta di Gianni Privacy' ) ) ) );
\ApSemplice\Privacy::set_consent( $pv, 'paper' );
$ex = \ApSemplice\Privacy::export( $pv );
apse_ok( \ApSemplice\Privacy::has_consent( $people->get( $pv ) ) && 'gianni.privacy@example.com' === $ex['anagrafica']['email'] && 1 === count( $ex['pagamenti'] ) && 'paper' === $people->get( $pv )['privacy_consent_source'], 'privacy: consenso registrato e dati esportati' );
apse_ok( \ApSemplice\Privacy::can_export( $pv ) && ! \ApSemplice\Privacy::can_export( 0 ), 'privacy: l\'amministratore può scaricare i dati' );
$pv_g = $people->create( array( 'type' => 'guest', 'first_name' => 'Ospite', 'last_name' => 'Dipv', 'phone' => '333 4440003', 'host_person_id' => $pv ) );
apse_ok( false !== strpos( \ApSemplice\Privacy::blocker( $pv ), 'ospiti' ) && null !== apse_throws( function () use ( $pv ) { \ApSemplice\Privacy::anonymize( $pv ); } ), 'privacy: con ospiti collegati non si anonimizza' );
$people->delete( $pv_g );
$tx_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . " WHERE person_id = $pv" );
\ApSemplice\Privacy::anonymize( $pv );
$pv_row = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE id = $pv", ARRAY_A );
$pv_desc = (string) $wpdb->get_var( 'SELECT description FROM ' . Db::t( 'transactions' ) . " WHERE person_id = $pv LIMIT 1" );
apse_ok( 'Persona' === $pv_row['first_name'] && null === $pv_row['email'] && null === $pv_row['phone'] && null === $pv_row['tax_code'] && ! empty( $pv_row['anonymized_at'] ) && false === get_user_by( 'id', $pv_uid ), 'privacy: anonimizzazione toglie dati personali e utente del sito' );
apse_ok( $tx_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . " WHERE person_id = $pv" ) && false === strpos( $pv_desc, 'Gianni' ) && false !== strpos( $pv_desc, '[anonimizzato]' ), 'privacy: i movimenti restano, il nome nelle descrizioni no' );
apse_ok( ! in_array( $pv, array_map( 'intval', array_column( $people->search(), 'id' ) ), true ), 'privacy: le persone anonimizzate non compaiono negli elenchi' );
$pv2 = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Pia', 'last_name' => 'Iscritta', 'email' => 'pia.iscritta@example.com' ) );
$acts->enroll( $corso, $pv2, Settings::social_year()->clamp( substr( $today, 0, 7 ) ) );
apse_ok( false !== strpos( \ApSemplice\Privacy::blocker( $pv2 ), 'corso' ), 'privacy: chi è iscritto a un corso non si anonimizza' );
$old = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Oscar', 'last_name' => 'Antico', 'email' => 'oscar.antico@example.com' ) );
$wpdb->update( Db::t( 'people' ), array( 'created_at' => '2015-01-01 10:00:00', 'joined_on' => '2015-01-01' ), array( 'id' => $old ) );
$cand = array_column( \ApSemplice\Privacy::retention_candidates(), 'id' );
apse_ok( in_array( $old, $cand, true ) && ! in_array( $pv2, $cand, true ), 'privacy: gli ex soci inattivi da anni sono proposti, gli altri no' );
apse_ok( false !== strpos( apse_render( array( Admin\CommsPage::class, 'render' ), 'Ex soci da anonimizzare' ), 'Oscar Antico' ), 'privacy: la pagina elenca gli ex soci da anonimizzare' );
apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Consenso non registrato', array( 'id' => $pv2 ) );
$wpdb->delete( Db::t( 'enrollments' ), array( 'person_id' => $pv2 ) );
// consenso all'attivazione dell'accesso
Settings::update( array( 'privacy_url' => 'https://example.org/privacy' ) );
$ada = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Ada', 'last_name' => 'Attiva' ) );
parse_str( (string) wp_parse_url( \ApSemplice\Frontend\Activation::url( $ada ), PHP_URL_QUERY ), $act_q );
$ada_post = array( 'email' => 'ada.attiva@example.com', 'phone' => '333 4440004', 'password' => 'password-sicura-1', 'password2' => 'password-sicura-1', 'tax_code' => 'rssmra80a01h501u', 'address' => 'Via Roma 1', 'zip' => '00100', 'city' => 'Roma', 'province' => 'rm' );
$ada_no   = \ApSemplice\Frontend\Activation::complete( (string) $act_q['apse_activate'], $ada_post );
apse_ok( ! $ada_no['ok'] && false !== strpos( $ada_no['error'], 'informativa' ) && empty( $people->get( $ada )['wp_user_id'] ), 'attivazione: con l\'informativa configurata serve accettarla' );
$ada_yes = \ApSemplice\Frontend\Activation::complete( (string) $act_q['apse_activate'], array_merge( $ada_post, array( 'privacy_ok' => '1' ) ) );
apse_ok( $ada_yes['ok'] && 'web' === $people->get( $ada )['privacy_consent_source'], 'attivazione: il consenso viene registrato' );
$ada_row = $people->get( $ada );
apse_ok( 'RSSMRA80A01H501U' === $ada_row['tax_code'] && 'Via Roma 1' === $ada_row['address'] && '00100' === $ada_row['zip'] && 'Roma' === $ada_row['city'] && 'RM' === $ada_row['province'] && $people->profile_complete( $ada_row ), 'attivazione: indirizzo e codice fiscale scritti dal socio vengono salvati (codice fiscale in maiuscolo, provincia in sigla)' );
Settings::update( array( 'privacy_url' => '' ) );
// attivazione: i dati sono obbligatori e il codice fiscale deve coincidere con quello della segreteria
$ac3 = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Cleo', 'last_name' => 'Controllata', 'tax_code' => 'VRDGPP75B10F205B' ) );
parse_str( (string) wp_parse_url( \ApSemplice\Frontend\Activation::url( $ac3 ), PHP_URL_QUERY ), $ac3_q );
$ac3_post = array( 'email' => 'cleo.controllata@example.com', 'phone' => '333 4440088', 'password' => 'password-sicura-3', 'password2' => 'password-sicura-3', 'tax_code' => 'VRDGPP75B10F205B', 'address' => 'Via Torino 3', 'zip' => '10100', 'city' => 'Torino' );
$ac3_try  = function ( array $over ) use ( $ac3_q, $ac3_post ) {
	$post = array_merge( $ac3_post, $over );
	foreach ( $over as $k => $v ) {
		if ( null === $v ) {
			unset( $post[ $k ] );
		}
	}
	return \ApSemplice\Frontend\Activation::complete( (string) $ac3_q['apse_activate'], $post );
};
$ac3_page = \ApSemplice\Frontend\Activation::page( (string) $ac3_q['apse_activate'] );
apse_ok( false !== strpos( $ac3_page, 'name="tax_code"' ) && false !== strpos( $ac3_page, 'name="address"' ) && false !== strpos( $ac3_page, 'name="zip"' ) && false !== strpos( $ac3_page, 'name="city"' ) && false === strpos( $ac3_page, 'VRDGPP75B10F205B' ), 'attivazione: il modulo chiede codice fiscale e indirizzo e non mostra il codice fiscale già registrato' );
$r1 = $ac3_try( array( 'tax_code' => null ) );
$r2 = $ac3_try( array( 'tax_code' => 'ABCDEF12G34H567I' ) );
$r3 = $ac3_try( array( 'tax_code' => 'RSSMRA80A01H501U' ) );
$r4 = $ac3_try( array( 'address' => '' ) );
$r5 = $ac3_try( array( 'city' => null ) );
apse_ok( ! $r1['ok'] && false !== strpos( $r1['error'], 'obbligatorio' ) && ! $r2['ok'] && false !== strpos( $r2['error'], 'non è valido' ) && ! $r4['ok'] && ! $r5['ok'] && empty( $people->get( $ac3 )['wp_user_id'] ), 'attivazione: senza codice fiscale valido, indirizzo e comune l\'accesso non si attiva' );
apse_ok( ! $r3['ok'] && false !== strpos( $r3['error'], 'non corrisponde' ) && empty( $people->get( $ac3 )['wp_user_id'] ), 'attivazione: il codice fiscale scritto deve coincidere con quello registrato dalla segreteria (un link ricevuto per errore non basta)' );
$r6 = $ac3_try( array() );
apse_ok( $r6['ok'] && ! empty( $people->get( $ac3 )['wp_user_id'] ) && '10100' === $people->get( $ac3 )['zip'], 'attivazione: con i dati giusti l\'accesso si attiva e l\'indirizzo è salvato' );
apse_ok( false === strpos( \ApSemplice\Frontend\Activation::page( 'link-non-valido' ), 'name="tax_code"' ), 'attivazione: la pagina con un link non valido non mostra il modulo' );
Settings::update( array( 'limits' => array( 'profile_required' => 0 ) ) );
$ac4 = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Dino', 'last_name' => 'Dispensato' ) );
parse_str( (string) wp_parse_url( \ApSemplice\Frontend\Activation::url( $ac4 ), PHP_URL_QUERY ), $ac4_q );
$r7 = \ApSemplice\Frontend\Activation::complete( (string) $ac4_q['apse_activate'], array( 'email' => 'dino.dispensato@example.com', 'phone' => '333 4440099', 'password' => 'password-sicura-4', 'password2' => 'password-sicura-4' ) );
apse_ok( $r7['ok'], 'attivazione: con l\'obbligo disattivato dalle impostazioni ci si attiva anche senza i dati' );
Settings::update( array( 'limits' => array() ) );

// --- ricevute
$rc_p   = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Rosa', 'last_name' => 'Ricevuta', 'email' => 'rosa.ricevuta@example.com', 'tax_code' => 'RCVRSO80A41H501X' ) );
$rc_uid = (int) $people->get( $rc_p )['wp_user_id'];
Settings::update( array( 'receipt_footer' => 'Operazione di prova per i test.' ) );
$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'person_id' => $rc_p, 'lines' => array(
	array( 'category_id' => $ledger->category_id_of_kind( 'membership' ), 'amount_cents' => 1000 ),
	array( 'category_id' => $ledger->category_id_of_kind( 'donation' ), 'amount_cents' => 500, 'description' => 'Per il progetto estate' ),
) ) );
$rc_list = \ApSemplice\Receipts::list_for_payer( $rc_p );
apse_ok( 1 === count( $rc_list ) && 1500 === $rc_list[0]['cents'], 'ricevute: un incasso con più voci è una sola ricevuta' );
$rc1 = \ApSemplice\Receipts::build( $rc_list[0]['key'] );
$rc1b = \ApSemplice\Receipts::build( $rc_list[0]['key'] );
$yr   = substr( $today, 0, 4 );
apse_ok( 0 === strpos( $rc1['pdf'], '%PDF-1.4' ) && false !== strpos( $rc1['pdf'], 'RICEVUTA DI PAGAMENTO' ) && false !== strpos( $rc1['pdf'], 'Rosa Ricevuta' ) && false !== strpos( $rc1['pdf'], 'RCVRSO80A41H501X' ) && false !== strpos( $rc1['pdf'], 'Quota associativa' ) && false !== strpos( $rc1['pdf'], 'progetto estate' ) && false !== strpos( $rc1['pdf'], '15,00' ) && false !== strpos( $rc1['pdf'], 'Operazione di prova' ), 'ricevute: il PDF contiene chi, cosa, quanto e la riga in fondo' );
apse_ok( $rc1['number'] === $rc1b['number'] && 1 === preg_match( '#^1/' . $yr . '$#', $rc1['number'] ), 'ricevute: il numero progressivo si assegna una volta e resta' );
$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'person_id' => $rc_p, 'lines' => array( array( 'category_id' => $ledger->category_id_of_kind( 'donation' ), 'amount_cents' => 2000, 'description' => 'Offerta' ) ) ) );
$rc_list2 = \ApSemplice\Receipts::list_for_payer( $rc_p );
$don_key  = '';
foreach ( $rc_list2 as $r ) {
	if ( 2000 === $r['cents'] ) {
		$don_key = $r['key'];
	}
}
$rc2 = \ApSemplice\Receipts::build( $don_key );
apse_ok( 2 === count( $rc_list2 ) && 1 === preg_match( '#^2/' . $yr . '$#', $rc2['number'] ) && false !== strpos( $rc2['pdf'], 'EROGAZIONE LIBERALE' ), 'ricevute: la seconda ha il numero successivo; solo donazioni = ricevuta di erogazione liberale' );
$st = \ApSemplice\Receipts::statement( $rc_p, (int) $yr );
apse_ok( 3500 === $st['total_cents'] && false !== strpos( $st['pdf'], 'ATTESTAZIONE DEI VERSAMENTI' ) && false !== strpos( $st['pdf'], 'Erogazioni liberali' ) && null !== apse_throws( function () use ( $rc_p ) { \ApSemplice\Receipts::statement( $rc_p, 1999 ); } ), 'ricevute: attestazione annuale con i totali; un anno senza versamenti non si emette' );
apse_ok( false !== strpos( \ApSemplice\Receipts::url( 'abc' ), 'action=apse_receipt' ) && false !== strpos( \ApSemplice\Receipts::statement_url( $rc_p, 2026 ), 'action=apse_statement' ), 'ricevute: indirizzi di scarico con controllo' );
wp_set_current_user( $rc_uid );
$rc_ok = \ApSemplice\Receipts::can_view( $rc_list[0]['key'] ) && \ApSemplice\Receipts::can_statement( $rc_p );
$front_rc = $as( $rc_uid, '[apsemplice_ricevute]' );
wp_set_current_user( $uq );
$rc_no = \ApSemplice\Receipts::can_view( $rc_list[0]['key'] ) || \ApSemplice\Receipts::can_statement( $rc_p );
wp_set_current_user( 1 );
apse_ok( $rc_ok && ! $rc_no && false !== strpos( $front_rc, 'Ricevuta PDF' ) && false !== strpos( $front_rc, 'Attestazione' ), 'ricevute: le vede chi ha pagato (e l\'amministratore), non un altro socio; nell\'area soci c\'è l\'elenco' );
$rm = array();
\ApSemplice\Receipts::email( $rc_list[0]['key'] );
apse_ok( 1 === count( $rm ) && in_array( 'rosa.ricevuta@example.com', (array) $rm[0]['to'], true ) && ! empty( $rm[0]['_exists'][0] ) && 0 === strpos( $rm[0]['_exists'][0], 'ricevuta-' ), 'ricevute: invio per email con il PDF allegato' );
$wpdb->update( Db::t( 'transactions' ), array( 'voided_at' => Db::now(), 'void_reason' => 'prova' ), array( 'receipt_id' => $wpdb->get_var( 'SELECT receipt_id FROM ' . Db::t( 'transactions' ) . " WHERE person_id = $rc_p AND amount_cents = 2000" ) ) );
apse_ok( null !== apse_throws( function () use ( $don_key ) { \ApSemplice\Receipts::build( $don_key ); } ), 'ricevute: un incasso annullato non ha ricevuta' );
apse_render( array( Admin\LedgerPage::class, 'render' ), 'Ricevuta PDF' );
apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Ricevute e attestazioni', array( 'id' => $rc_p ) );
Settings::update( array( 'receipt_footer' => '' ) );
remove_all_filters( 'pre_wp_mail' );

// ---------- Testi personalizzabili ----------
wp_set_current_user( 1 );
\ApSemplice\Texts::save_overrides( array() );
$tx_cat   = \ApSemplice\Texts::catalog();
$tx_texts = array_column( $tx_cat, 'text' );
$tx_by    = array_column( $tx_cat, 'group', 'text' );
$tx_groups = array_count_values( array_column( $tx_cat, 'group' ) );
echo "TESTI: " . count( $tx_cat ) . ' nel catalogo; per gruppo: ' . wp_json_encode( $tx_groups, JSON_UNESCAPED_UNICODE ) . "\n";
foreach ( array_keys( $tx_groups ) as $gname ) {
	$sample = array();
	foreach ( $tx_cat as $i => $c ) {
		if ( $c['group'] === $gname && 0 === $i % 17 && count( $sample ) < 14 ) {
			$sample[] = $c['text'];
		}
	}
	echo "  [$gname] " . implode( ' | ', $sample ) . "\n";
}
foreach ( $tx_cat as $c ) { // elenco completo nel registro del CI, per la revisione dei testi
	echo 'TXT ' . wp_json_encode( array( $c['group'], $c['text'] ), JSON_UNESCAPED_UNICODE ) . "\n";
}
apse_ok( count( $tx_cat ) > 300 &&in_array( 'Il mio profilo', $tx_texts, true ) && in_array( 'Le mie ricevute', $tx_texts, true ) && in_array( 'RICEVUTA DI PAGAMENTO', $tx_texts, true ), 'testi: il catalogo ricavato dal codice contiene i testi del sito' );
apse_ok( 'Area soci e pagine pubbliche' === $tx_by['Il mio profilo'] && 'Ricevute e attestazioni (PDF)' === $tx_by['RICEVUTA DI PAGAMENTO'], 'testi: ogni testo ha il suo gruppo' );
$tx_bad = array();
foreach ( $tx_texts as $t ) {
	if ( preg_match( '/^(apse_|apsf-|SELECT |INSERT )|<|\$|::|->/', $t ) ) {
		$tx_bad[] = $t;
	}
}
apse_ok( array() === $tx_bad, 'testi: nel catalogo niente codice, query o classi' . ( $tx_bad ? ' (' . implode( ' | ', array_slice( $tx_bad, 0, 5 ) ) . ')' : '' ) );

// sostituzione nell'area soci, nei messaggi, nelle email e nei PDF
\ApSemplice\Texts::save_overrides( array( 'Il mio profilo' => 'La mia scheda', 'Ospite aggiunto.' => 'Fatto: ospite inserito', 'RICEVUTA DI PAGAMENTO' => 'RICEVUTA N. TEST' ) );
$tx_front = $as( $u_f, '[apsemplice_profilo]' );
apse_ok( false !== strpos( $tx_front, 'La mia scheda' ) && false === strpos( $tx_front, 'Il mio profilo' ), 'testi: la sostituzione compare nell\'area soci' );
parse_str( (string) wp_parse_url( \ApSemplice\Flash::url( 'https://example.org/a/', 'apsf', 'Ospite aggiunto.' ), PHP_URL_QUERY ), $tx_q );
$tx_old = $_GET;
$_GET   = $tx_q;
$tx_fl  = \ApSemplice\Flash::read( 'apsf' );
$_GET   = $tx_old;
apse_ok( 'Fatto: ospite inserito' === $tx_fl['ok'], 'testi: la sostituzione vale anche nei messaggi di conferma' );
$tx_mail = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$tx_mail ) {
		$tx_mail[] = $atts;
		return true;
	},
	10,
	2
);
\ApSemplice\Texts::save_overrides( array_merge( \ApSemplice\Texts::overrides(), array( 'Promemoria di prova' => 'Promemoria cambiato' ) ) );
\ApSemplice\Texts::mail( 'a@example.com', 'Promemoria di prova', 'Corpo: Promemoria di prova.' );
apse_ok( 1 === count( $tx_mail ) && 'Promemoria cambiato' === $tx_mail[0]['subject'] && 'Corpo: Promemoria cambiato.' === $tx_mail[0]['message'], 'testi: la sostituzione vale nelle email (oggetto e testo)' );
remove_all_filters( 'pre_wp_mail' );
$tx_pdf = \ApSemplice\Receipts::build( $rc_list[0]['key'] );
apse_ok( false !== strpos( $tx_pdf['pdf'], 'RICEVUTA N. TEST' ) && false === strpos( $tx_pdf['pdf'], 'RICEVUTA DI PAGAMENTO' ) && null === \ApSemplice\Pdf::$filter, 'testi: la sostituzione vale nei PDF' );
apse_ok( false !== strpos( \ApSemplice\Texts::html( '<h3>Il mio profilo</h3><input placeholder="Il mio profilo">' ), '<h3>La mia scheda</h3><input placeholder="La mia scheda">' ), 'testi: in amministrazione la pagina passa dalla stessa sostituzione' );

// esportazione e importazione
$tx_csv = \ApSemplice\Texts::export_csv( true );
apse_ok( 0 === strpos( $tx_csv, "\xEF\xBB\xBFGruppo;Originale;Versione in uso;Personalizzato" ) && false !== strpos( $tx_csv, 'Il mio profilo;Il mio profilo;La mia scheda' ) && false !== strpos( $tx_csv, 'Aggiunte a mano;Promemoria di prova;Promemoria di prova;Promemoria cambiato' ), 'testi: esportazione CSV con i soli personalizzati' );
$tx_all = \ApSemplice\Texts::export_csv();
apse_ok( substr_count( $tx_all, "\r\n" ) > count( $tx_cat ), 'testi: esportazione CSV di tutti i testi' );
$tx_file = wp_tempnam( 'apse-testi' );
file_put_contents( $tx_file, str_replace( 'Il mio profilo;La mia scheda', 'Il mio profilo;La mia area personale', $tx_csv ) );
$tx_sheets = \ApSemplice\SheetReader::read( $tx_file, 'testi.csv' );
unlink( $tx_file );
$tx_res = \ApSemplice\Texts::import_rows( $tx_sheets[0]['rows'] );
$tx_ov  = \ApSemplice\Texts::overrides();
apse_ok( 'La mia area personale' === $tx_ov['Il mio profilo'] && 1 === $tx_res['set'] && 'Promemoria cambiato' === $tx_ov['Promemoria di prova'], 'testi: importazione del file modificato (CSV con BOM e punto e virgola)' );
$tx_res2 = \ApSemplice\Texts::import_rows( array( array( 'Gruppo', 'Originale', 'Personalizzato' ), array( 'x', 'Il mio profilo', '' ), array( 'x', 'Frase inventata dal test', 'Frase nuova' ), array( 'x', 'ab', 'xx' ) ) );
$tx_ov2 = \ApSemplice\Texts::overrides();
apse_ok( ! isset( $tx_ov2['Il mio profilo'] ) && 'Frase nuova' === $tx_ov2['Frase inventata dal test'] && 1 === $tx_res2['removed'] && 1 === $tx_res2['manual'] && 1 === $tx_res2['ignored'], 'testi: Personalizzato vuoto ripristina, una frase nuova diventa aggiunta a mano, righe troppo corte ignorate' );
apse_ok( null !== apse_throws( function () { \ApSemplice\Texts::import_rows( array( array( 'a', 'b' ), array( '1', '2' ) ) ); } ), 'testi: un file senza le colonne giuste viene rifiutato' );

// pagina e azioni dell'amministrazione
$tx_save = new ReflectionMethod( Admin\Actions::class, 'save_texts' );
$tx_save->invoke( null, array( 't' => array( md5( 'Il mio profilo' ) => 'Nuova area', md5( 'testo che non esiste nel catalogo' ) => 'x' ) ) );
apse_ok( 'Nuova area' === \ApSemplice\Texts::overrides()['Il mio profilo'] && ! isset( \ApSemplice\Texts::overrides()['testo che non esiste nel catalogo'] ), 'testi: salvataggio dalla pagina (solo testi veri)' );
$tx_add = new ReflectionMethod( Admin\Actions::class, 'add_text' );
$tx_add->invoke( null, array( 'original' => 'Pezzo mancante', 'custom' => 'Pezzo nuovo' ) );
apse_ok( 'Pezzo nuovo' === \ApSemplice\Texts::overrides()['Pezzo mancante'] && null !== apse_throws( function () use ( $tx_add ) { $tx_add->invoke( null, array( 'original' => 'ab', 'custom' => 'x' ) ); } ), 'testi: aggiunta di una sostituzione a mano' );
$tx_html = apse_render( array( Admin\TextsPage::class, 'render' ), 'Esporta tutti i testi', array( 'page' => 'apse-texts', 'q' => 'Il mio profilo' ) );
apse_ok( false !== strpos( $tx_html, 'Il mio profilo' ) && false !== strpos( $tx_html, 'Nuova area' ) && false !== strpos( $tx_html, 'apse_import_texts' ), 'testi: la pagina elenca originali e personalizzati e permette l\'importazione' );
apse_render( array( Admin\TextsPage::class, 'render' ), 'Nessun testo con questi filtri', array( 'page' => 'apse-texts', 'q' => 'zzzzqqqq' ) );
$tx_reset = new ReflectionMethod( Admin\Actions::class, 'reset_texts' );
$tx_reset->invoke( null, array() );
apse_ok( array() === \ApSemplice\Texts::overrides(), 'testi: ripristino di tutti i testi originali' );
apse_ok( ( \ApSemplice\Plugin::load_for_action( 'apse_export_texts' ) && has_action( 'admin_post_apse_export_texts' ) ) && has_action( 'admin_post_apse_import_texts' ), 'testi: azioni registrate' );

// ---------- Tipo di ente e termini ----------
wp_set_current_user( 1 );
\ApSemplice\Texts::save_overrides( array() );
$tm_mail = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$tm_mail ) {
		$tm_mail[] = $atts;
		return true;
	},
	10,
	2
);
$tm_def = $as( $u_f, '[apsemplice_ospiti]' );
Settings::update( array( 'entity_type' => 'comitato', 'member_term' => 'iscritto' ) );
$tm_front = $as( $u_f, '[apsemplice_ospiti]' );
apse_ok( false !== strpos( $tm_def, 'senza essere soci' ) && false !== strpos( $tm_front, 'senza essere iscritti' ) && false === strpos( $tm_front, 'senza essere soci' ), 'ente e termini: l\'area soci si adatta ("soci" diventa "iscritti")' );
\ApSemplice\Texts::mail( 'a@example.com', 'Tessera dell\'associazione', "Il socio dell'associazione. Area: https://example.org/area-soci/" );
apse_ok( 1 === count( $tm_mail ) && 'Tessera del comitato' === $tm_mail[0]['subject'] && "L'iscritto del comitato. Area: https://example.org/area-soci/" === $tm_mail[0]['message'], 'ente e termini: email adattate (articoli giusti, indirizzi web intatti)' );
$tm_pdf = \ApSemplice\Receipts::build( $rc_list[0]['key'] );
apse_ok( false !== strpos( $tm_pdf['pdf'], 'Per il comitato' ) && false === strpos( $tm_pdf['pdf'], 'Per l\'associazione' ), 'ente e termini: anche i PDF' );
parse_str( (string) wp_parse_url( \ApSemplice\Flash::url( 'https://example.org/a/', 'apsf', 'Il socio è stato aggiunto all\'associazione.' ), PHP_URL_QUERY ), $tm_q );
$tm_old = $_GET;
$_GET   = $tm_q;
$tm_fl  = \ApSemplice\Flash::read( 'apsf' );
$_GET   = $tm_old;
apse_ok( "L’iscritto è stato aggiunto al comitato." === $tm_fl['ok'], 'ente e termini: anche i messaggi di conferma' );
$tm_page = apse_render( array( Admin\TextsPage::class, 'render' ), 'Tipo di ente e termini', array( 'page' => 'apse-texts' ) );
apse_ok( false !== strpos( $tm_page, 'ha rinnovato la tessera del comitato' ), 'ente e termini: la pagina mostra l\'anteprima adattata' );
Settings::update( array( 'entity_type' => 'associazione', 'member_term' => 'socio' ) );
$tm_back = $as( $u_f, '[apsemplice_ospiti]' );
apse_ok( false !== strpos( $tm_back, 'senza essere soci' ), 'ente e termini: tornando ai valori di serie i testi sono quelli originali' );
$tm_save = new ReflectionMethod( Admin\Actions::class, 'save_terms' );
$tm_save->invoke( null, array( 'entity_type' => 'museo', 'entity_types_custom' => "museo;m\nfondazione;f", 'member_term' => 'tesserata', 'member_terms_custom' => 'tesserata;tesserate;f' ) );
apse_ok( 'museo' === Settings::get( 'entity_type' ) && 'tesserata' === Settings::get( 'member_term' ) && 'Il museo e le tesserate' === \ApSemplice\Texts::plain( "L'associazione e i soci" ), 'ente e termini: tipo di ente e termine aggiunti a mano' );
apse_ok( null !== apse_throws( function () use ( $tm_save ) { $tm_save->invoke( null, array( 'entity_type' => 'inesistente', 'member_term' => 'socio' ) ); } ), 'ente e termini: una scelta fuori elenco viene rifiutata' );
$tm_save->invoke( null, array( 'preset' => 'femminile' ) );
apse_ok( 'associazione' === Settings::get( 'entity_type' ) && 'socia' === Settings::get( 'member_term' ) && 'Socia fondatrice, socie sospese e la socia' === \ApSemplice\Texts::plain( 'Socio fondatore, soci sospesi e il socio' ), 'versione base al femminile (associazione, socie), con le qualifiche al femminile' );
$tm_save->invoke( null, array( 'preset' => 'maschile' ) );
apse_ok( 'comitato' === Settings::get( 'entity_type' ) && 'socio' === Settings::get( 'member_term' ) && 'Il comitato e i soci' === \ApSemplice\Texts::plain( "L'associazione e i soci" ), 'versione base al maschile (comitato, soci)' );
apse_ok( null !== apse_throws( function () use ( $tm_save ) { $tm_save->invoke( null, array( 'preset' => 'neutra' ) ); } ), 'versione base: una versione inesistente viene rifiutata' );
// la personalizzazione lavora sulla versione in uso: il testo scelto non viene adattato una seconda volta
Settings::update( array( 'member_term' => 'iscritto' ) );
\ApSemplice\Texts::save_overrides( array( 'Il mio profilo' => 'La scheda del socio' ) );
$tm_cust = $as( $u_f, '[apsemplice_profilo]' );
apse_ok( false !== strpos( $tm_cust, 'La scheda del socio' ) && false === strpos( $tm_cust, 'La scheda dell&#039;iscritto' ), 'testi: il testo personalizzato resta com\'è scritto (non viene adattato di nuovo)' );
\ApSemplice\Texts::save_overrides( array() );
Settings::update( array( 'entity_type' => 'associazione', 'entity_types_custom' => '', 'member_term' => 'socio', 'member_terms_custom' => '' ) );
remove_all_filters( 'pre_wp_mail' );

// ---------- Segreteria, regolamento, lista d'attesa ----------
wp_set_current_user( 1 );
$mkmem = function ( string $first, string $last ) use ( $people ) {
	$id = $people->create( array( 'type' => 'ordinary', 'first_name' => $first, 'last_name' => $last, 'email' => strtolower( $first . '.' . $last ) . '@example.com' ) );
	$people->set_membership( $id, Settings::membership_year()->label(), true );
	return array( $id, (int) $people->get( $id )['wp_user_id'] );
};

// segreteria
list( $sec_p, $sec_u ) = $mkmem( 'Sara', 'Segreteria' );
$set_sec = new ReflectionMethod( Admin\Actions::class, 'set_secretary' );
$set_sec->invoke( null, array( 'id' => $sec_p, 'enabled' => '1' ) );
apse_ok( in_array( Plugin::ROLE_SECRETARY, (array) get_userdata( $sec_u )->roles, true ) && user_can( $sec_u, Plugin::CAP_OPS ) && ! user_can( $sec_u, Plugin::CAP ) && Access::is_admin_user( $sec_u ), 'segreteria: opera sul plugin ma non è amministratore' );
apse_ok( Plugin::CAP === Admin\Actions::required_cap( 'apse_save_settings' ) && Plugin::CAP === Admin\Actions::required_cap( 'apse_privacy_anonymize' ) && Plugin::CAP === Admin\Actions::required_cap( 'apse_save_texts' ) && Plugin::CAP_OPS === Admin\Actions::required_cap( 'apse_save_person' ) && Plugin::CAP_OPS === Admin\Actions::required_cap( 'apse_save_income' ), 'segreteria: impostazioni, privacy e testi solo agli amministratori; soci e incassi anche alla segreteria' );
wp_set_current_user( $sec_u );
$tabs_sec = Admin\Admin::tabs( 'apse-accounting' );
$set_tabs = Admin\Admin::tabs( 'apse-settings' );
wp_set_current_user( 1 );
$tabs_adm = Admin\Admin::tabs( 'apse-accounting' );
apse_ok( false !== strpos( $tabs_sec, 'page=apse-accounting' ) && false === strpos( $tabs_sec, 'page=apse-years' ) && false !== strpos( $tabs_adm, 'page=apse-years' ) && false === strpos( $set_tabs, 'page=apse-texts' ), 'segreteria: non vede le schede riservate agli amministratori' );
wp_set_current_user( $sec_u );
$sec_page = apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Privacy', array( 'id' => $sec_p ) );
wp_set_current_user( 1 );
apse_ok( false === strpos( $sec_page, 'Anonimizza' ) && false === strpos( $sec_page, 'Tesoriere' ) && false === strpos( $sec_page, 'apse_set_secretary' ), 'segreteria: nella scheda del socio non compaiono i comandi riservati' );
$adm_page = apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Segreteria', array( 'id' => $sec_p ) );
apse_ok( false !== strpos( $adm_page, 'apse_set_secretary' ), 'segreteria: l\'amministratore la assegna dalla scheda del socio' );
apse_ok( null !== apse_throws( function () use ( $set_sec, $guest_door ) { $set_sec->invoke( null, array( 'id' => $guest_door, 'enabled' => '1' ) ); } ), 'segreteria: un ospite non può farne parte' );
$set_sec->invoke( null, array( 'id' => $sec_p ) );
apse_ok( ! user_can( $sec_u, Plugin::CAP_OPS ) && ! Access::is_admin_user( $sec_u ), 'segreteria: si può togliere' );

// regolamento
list( $rg_p, $rg_u ) = $mkmem( 'Rino', 'Regolato' );
$rg_ev = $mkev( 'Cena del regolamento', 500, 800 );
$rg_s  = $first_session( $rg_ev );
Settings::update( array( 'rules_enabled' => 0 ) );
apse_ok( \ApSemplice\Regulation::accepted( $people->get( $rg_p ) ) && ! \ApSemplice\Regulation::enabled(), 'regolamento: spento di default, nessuna accettazione richiesta' );
Settings::update( array( 'rules_enabled' => 1, 'rules_title' => 'Regolamento interno', 'rules_text' => "Art. 1 - I soci rispettano gli spazi.\nArt. 2 - Si prenota in anticipo.", 'rules_version' => '1', 'rules_block_booking' => 1 ) );
apse_ok( \ApSemplice\Regulation::enabled() && ! \ApSemplice\Regulation::accepted( $people->get( $rg_p ) ) && \ApSemplice\Regulation::applies_to( $people->get( $rg_p ) ) && ! \ApSemplice\Regulation::applies_to( $people->get( $guest_door ) ), 'regolamento: i soci devono accettarlo, gli ospiti no' );
wp_set_current_user( $rg_u );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front, $rg_s, $rg_p ) { $front::do_book( array( 'session_id' => $rg_s, 'person_id' => $rg_p ) ); } ), 'accettare il regolamento interno' ), 'regolamento: senza accettazione non si prenota' );
$rg_front = $as( $rg_u, '[apsemplice_regolamento]' );
apse_ok( false !== strpos( $rg_front, 'Regolamento interno' ) && false !== strpos( $rg_front, 'Art. 2' ) && false !== strpos( $rg_front, 'Ho letto e accetto' ), 'regolamento: in area soci compare il testo con la casella di accettazione' );
apse_ok( null !== apse_throws( function () use ( $front ) { $front::do_accept_rules( array() ); } ), 'regolamento: serve spuntare la casella' );
$front::do_accept_rules( array( 'rules_ok' => '1' ) );
$rg_row = $people->get( $rg_p );
apse_ok( \ApSemplice\Regulation::accepted( $rg_row ) && 'web' === $rg_row['rules_accepted_source'] && '1' === $rg_row['rules_accepted_version'], 'regolamento: accettazione registrata con versione e modalità' );
$front::do_book( array( 'session_id' => $rg_s, 'person_id' => $rg_p ) );
apse_ok( $acts->has_active_booking( $rg_s, $rg_p ) && '' === trim( strip_tags( $as( $rg_u, '[apsemplice_regolamento]' ) ) ), 'regolamento: dopo l\'accettazione si prenota e il riquadro sparisce' );
wp_set_current_user( 1 );
Settings::update( array( 'rules_version' => '2' ) );
apse_ok( ! \ApSemplice\Regulation::accepted( $people->get( $rg_p ) ) && false !== strpos( $as( $rg_u, '[apsemplice_regolamento]' ), 'aggiornato' ), 'regolamento: cambiando versione tutti devono accettare di nuovo' );
$norules = array_map( 'intval', array_column( $people->search( array( 'status' => 'norules' ) ), 'id' ) );
apse_ok( in_array( $rg_p, $norules, true ) && ! in_array( $guest_door, $norules, true ), 'regolamento: filtro "regolamento non accettato" nella Rubrica' );
Settings::update( array( 'rules_block_booking' => 0 ) );
$rg_ev2 = $mkev( 'Seconda cena', 0, null );
wp_set_current_user( $rg_u );
$front::do_book( array( 'session_id' => $first_session( $rg_ev2 ), 'person_id' => $rg_p ) );
wp_set_current_user( 1 );
apse_ok( $acts->has_active_booking( $first_session( $rg_ev2 ), $rg_p ), 'regolamento: se non blocca le prenotazioni si può prenotare anche senza accettazione' );
Settings::update( array( 'rules_block_booking' => 1 ) );
$rg_rec = new ReflectionMethod( Admin\Actions::class, 'rules_record' );
$rg_rec->invoke( null, array( 'id' => $rg_p, 'mode' => 'paper' ) );
apse_ok( \ApSemplice\Regulation::accepted( $people->get( $rg_p ) ) && 'paper' === $people->get( $rg_p )['rules_accepted_source'] && '2' === $people->get( $rg_p )['rules_accepted_version'], 'regolamento: l\'amministrazione registra l\'accettazione su carta' );
$rg_rec->invoke( null, array( 'id' => $rg_p, 'mode' => 'clear' ) );
apse_ok( ! \ApSemplice\Regulation::accepted( $people->get( $rg_p ) ), 'regolamento: l\'accettazione si può rimuovere' );
// accettazione all'attivazione dell'accesso
$ad2 = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Ugo', 'last_name' => 'Attivato' ) );
parse_str( (string) wp_parse_url( \ApSemplice\Frontend\Activation::url( $ad2 ), PHP_URL_QUERY ), $ad2_q );
$ad2_post = array( 'email' => 'ugo.attivato@example.com', 'phone' => '333 4440077', 'password' => 'password-sicura-2', 'password2' => 'password-sicura-2', 'tax_code' => 'VRDGPP75B10F205B', 'address' => 'Via Milano 2', 'zip' => '20100', 'city' => 'Milano' );
$ad2_no   = \ApSemplice\Frontend\Activation::complete( (string) $ad2_q['apse_activate'], $ad2_post );
apse_ok( ! $ad2_no['ok'] && false !== strpos( $ad2_no['error'], 'regolamento' ) && false !== strpos( \ApSemplice\Frontend\Activation::page( (string) $ad2_q['apse_activate'] ), 'rules_ok' ), 'regolamento: l\'attivazione richiede di accettarlo' );
$ad2_yes = \ApSemplice\Frontend\Activation::complete( (string) $ad2_q['apse_activate'], array_merge( $ad2_post, array( 'rules_ok' => '1' ) ) );
apse_ok( $ad2_yes['ok'] && \ApSemplice\Regulation::accepted( $people->get( $ad2 ) ), 'regolamento: accettato all\'attivazione' );
apse_render( array( Admin\CommsPage::class, 'render' ), 'Regolamento attivo' );
apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Regolamento interno', array( 'id' => $rg_p ) );
Settings::update( array( 'rules_enabled' => 0, 'rules_text' => '', 'rules_title' => 'Regolamento', 'rules_version' => '1' ) );

// lista d'attesa
$wl_mail = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$wl_mail ) {
		$wl_mail[] = $atts;
		return true;
	},
	10,
	2
);
$wl_date = gmdate( 'Y-m-d', strtotime( $today . ' +6 days' ) );
$wl_ev   = $mkev( 'Serata esaurita', 300, null, array( 'session' => array( 'session_date' => $wl_date, 'capacity' => 1 ) ) );
$wl_s    = $first_session( $wl_ev );
list( $wa, $wa_u ) = $mkmem( 'Anna', 'Attesa' );
list( $wb, $wb_u ) = $mkmem( 'Bruno', 'Attesa' );
list( $wc, $wc_u ) = $mkmem( 'Carla', 'Attesa' );
$acts->book( $wl_s, $wa );
apse_ok( null !== apse_throws( function () use ( $front, $wl_s, $wb ) { wp_set_current_user( 0 ); $front::do_waitlist_join( array( 'session_id' => $wl_s, 'person_id' => $wb ) ); } ), 'lista d\'attesa: serve un socio che la chiede' );
wp_set_current_user( $wb_u );
$front::do_waitlist_join( array( 'session_id' => $wl_s, 'person_id' => $wb ) );
wp_set_current_user( $wc_u );
$wl_msg = $front::do_waitlist_join( array( 'session_id' => $wl_s ) );
apse_ok( 1 === \ApSemplice\Waitlist::position( $wl_s, $wb ) && 2 === \ApSemplice\Waitlist::position( $wl_s, $wc ) && false !== strpos( $wl_msg, 'posizione 2' ) && 2 === \ApSemplice\Waitlist::count( $wl_s ), 'lista d\'attesa: ci si mette in coda a posti finiti, in ordine di arrivo' );
apse_ok( null !== apse_throws( function () use ( $front, $wl_s ) { $front::do_waitlist_join( array( 'session_id' => $wl_s ) ); } ), 'lista d\'attesa: non ci si iscrive due volte' );
$wl_free = $mkev( 'Serata con posti', 300, null, array( 'session' => array( 'session_date' => $wl_date, 'capacity' => 5 ) ) );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $front, $wl_free, $first_session ) { $front::do_waitlist_join( array( 'session_id' => $first_session( $wl_free ) ) ); } ), 'posti liberi' ), 'lista d\'attesa: con posti liberi si prenota direttamente' );
$wl_page = $as( $wc_u, '[apsemplice_attivita id="' . $wl_ev . '"]' );
apse_ok( false !== strpos( $wl_page, 'apse_front_waitlist_leave' ) && false !== strpos( $wl_page, 'in lista d' ), 'lista d\'attesa: nell\'elenco eventi si vede la posizione e il pulsante per uscire' );
wp_set_current_user( 1 );
$wl_adm = apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'apse_waitlist_remove', array( 'id' => $wl_ev ) );
$wl_mail = array();
$acts->cancel_booking( $wl_s, $wa );
apse_ok( $acts->has_active_booking( $wl_s, $wb ) && ! $acts->has_active_booking( $wl_s, $wc ) && null === \ApSemplice\Waitlist::position( $wl_s, $wb ) && 1 === \ApSemplice\Waitlist::position( $wl_s, $wc ), 'lista d\'attesa: se qualcuno annulla entra il primo della lista' );
$wl_to = array();
foreach ( $wl_mail as $m ) {
	$wl_to[] = implode( ',', (array) $m['to'] ) . '|' . $m['subject'];
}
apse_ok( in_array( 'bruno.attesa@example.com|Posto disponibile: Serata esaurita', $wl_to, true ), 'lista d\'attesa: chi entra riceve una email' );
$acts->cancel_booking( $wl_s, $wb );
apse_ok( $acts->has_active_booking( $wl_s, $wc ) && 0 === \ApSemplice\Waitlist::count( $wl_s ), 'lista d\'attesa: poi entra il secondo' );
// più posti: entrano quelli in coda
$wl_ev2 = $mkev( 'Serata che cresce', 0, null, array( 'session' => array( 'session_date' => $wl_date, 'capacity' => 1 ) ) );
$wl_s2  = $first_session( $wl_ev2 );
$acts->book( $wl_s2, $wa );
\ApSemplice\Waitlist::join( $wl_s2, $wb );
$acts->update_session( $wl_s2, array( 'capacity' => 2 ) );
apse_ok( $acts->has_active_booking( $wl_s2, $wb ), 'lista d\'attesa: aumentando i posti entra chi era in coda' );
// chi non può più essere prenotato viene saltato
$wl_ev3 = $mkev( 'Serata con salti', 0, null, array( 'session' => array( 'session_date' => $wl_date, 'capacity' => 1 ) ) );
$wl_s3  = $first_session( $wl_ev3 );
$acts->book( $wl_s3, $wa );
\ApSemplice\Waitlist::join( $wl_s3, $wb );
\ApSemplice\Waitlist::join( $wl_s3, $wc );
$wpdb->update( Db::t( 'memberships' ), array( 'valid_to' => gmdate( 'Y-m-d', strtotime( $today . ' -10 days' ) ) ), array( 'person_id' => $wb ) );
$acts->cancel_booking( $wl_s3, $wa );
apse_ok( ! $acts->has_active_booking( $wl_s3, $wb ) && $acts->has_active_booking( $wl_s3, $wc ) && null === \ApSemplice\Waitlist::position( $wl_s3, $wb ), 'lista d\'attesa: chi ha la tessera scaduta viene saltato e entra il successivo' );
// uscire dalla lista e ospiti
$wl_ev4 = $mkev( 'Serata ospiti', 0, null, array( 'session' => array( 'session_date' => $wl_date, 'capacity' => 1 ) ) );
$wl_s4  = $first_session( $wl_ev4 );
$acts->book( $wl_s4, $wa );
$wl_g = $people->create( array( 'type' => 'guest', 'first_name' => 'Gigi', 'last_name' => 'Inattesa', 'phone' => '333 4440088', 'host_person_id' => $wc ) );
wp_set_current_user( $wc_u );
$front::do_waitlist_join( array( 'session_id' => $wl_s4, 'person_id' => $wl_g ) );
$front::do_waitlist_leave( array( 'session_id' => $wl_s4, 'person_id' => $wl_g ) );
wp_set_current_user( 1 );
apse_ok( null === \ApSemplice\Waitlist::position( $wl_s4, $wl_g ), 'lista d\'attesa: si esce dalla lista' );
\ApSemplice\Waitlist::join( $wl_s4, $wl_g, $wc );
$wl_mail = array();
$acts->cancel_booking( $wl_s4, $wa );
$wl_guest_to = array();
foreach ( $wl_mail as $m ) {
	$wl_guest_to[] = implode( ',', (array) $m['to'] ) . '|' . $m['message'];
}
apse_ok( $acts->has_active_booking( $wl_s4, $wl_g ) && 1 === count( $wl_guest_to ) && 0 === strpos( $wl_guest_to[0], 'carla.attesa@example.com|' ) && false !== strpos( $wl_guest_to[0], 'per Gigi Inattesa' ), 'lista d\'attesa: un ospite in coda entra e la email va al socio che lo ospita' );
remove_all_filters( 'pre_wp_mail' );

// ---------- Comunicazioni a gruppi ----------
wp_set_current_user( 1 );
$bc_mail = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$bc_mail ) {
		if ( ! empty( $GLOBALS['bc_fail'] ) && in_array( $GLOBALS['bc_fail'], (array) $atts['to'], true ) ) {
			return false;
		}
		$bc_mail[] = $atts;
		return true;
	},
	10,
	2
);
$bc_to = function () use ( &$bc_mail ) {
	$out = array();
	foreach ( $bc_mail as $m ) {
		$out = array_merge( $out, (array) $m['to'] );
	}
	return $out;
};
$mkb = function ( string $first, string $last ) use ( $people ) {
	$id = $people->create( array( 'type' => 'ordinary', 'first_name' => $first, 'last_name' => $last, 'email' => strtolower( $first . '.' . $last ) . '@example.com' ) );
	return $id;
};
$bm_act = $mkb( 'Ada', 'Gruppoattiva' );
$bm_exp = $mkb( 'Ugo', 'Grupposcaduto' );
$people->set_membership( $bm_act, Settings::membership_year()->label(), true );
$people->set_membership( $bm_exp, Settings::membership_year()->label(), true );
$wpdb->update( Db::t( 'memberships' ), array( 'valid_to' => gmdate( 'Y-m-d', strtotime( $today . ' -20 days' ) ) ), array( 'person_id' => $bm_exp ) );
$bm_guest = $people->create( array( 'type' => 'guest', 'first_name' => 'Gina', 'last_name' => 'Gruppoospite', 'phone' => '333 4441234', 'host_person_id' => $bm_act ) );
$r_act = array_column( \ApSemplice\Broadcasts::recipients( 'members_active' )['list'], 'email' );
$r_exp = array_column( \ApSemplice\Broadcasts::recipients( 'members_expired' )['list'], 'email' );
$r_gst = \ApSemplice\Broadcasts::recipients( 'guests' )['list'];
$r_soon = array_column( \ApSemplice\Broadcasts::recipients( 'members_expiring' )['list'], 'email' );
apse_ok( in_array( 'ada.gruppoattiva@example.com', $r_act, true ) && ! in_array( 'ugo.grupposcaduto@example.com', $r_act, true ) && in_array( 'ugo.grupposcaduto@example.com', $r_exp, true ) && in_array( 'ugo.grupposcaduto@example.com', $r_soon, true ), 'comunicazioni: i gruppi dei soci (in regola, scaduti, in scadenza) sono quelli giusti' );
$gst_mails = array_column( $r_gst, 'email' );
apse_ok( in_array( 'ada.gruppoattiva@example.com', $gst_mails, true ), 'comunicazioni: un ospite senza email riceve tramite il socio che lo ospita' );
$r_course = array_column( \ApSemplice\Broadcasts::recipients( 'activity', $acq )['list'], 'email' );
$r_sess   = array_column( \ApSemplice\Broadcasts::recipients( 'session', $tc_s )['list'], 'email' );
apse_ok( in_array( 'carla.corsista@example.com', $r_course, true ) && in_array( 'dario.corsista@example.com', $r_course, true ) && in_array( 'dario.corsista@example.com', $r_sess, true ), 'comunicazioni: iscritti a un corso e prenotati a una data' );
// invio piccolo
$bc_mail = array();
$b1 = \ApSemplice\Broadcasts::create( 'Avviso del corso', 'Ciao {nome}, domani il corso inizia alle 18. A presto da {associazione}.', 'activity', $acq );
$b1r = \ApSemplice\Broadcasts::get( $b1 );
$m_carla = '';
foreach ( $bc_mail as $m ) {
	if ( in_array( 'carla.corsista@example.com', (array) $m['to'], true ) ) {
		$m_carla = $m['message'];
	}
}
apse_ok( 'done' === $b1r['status'] && (int) $b1r['sent'] === (int) $b1r['total'] && (int) $b1r['total'] >= 2 && false !== strpos( $m_carla, 'Ciao Carla,' ) && false !== strpos( $m_carla, 'Comunicazione di servizio' ), 'comunicazioni: ogni destinatario riceve la propria email personalizzata' );
// invio grande: a gruppi di 25 e il resto in background
$bc_mail = array();
$b2  = \ApSemplice\Broadcasts::create( 'Avviso a tutti i soci', 'Un messaggio per tutti.', 'members_all' );
$b2a = \ApSemplice\Broadcasts::get( $b2 );
apse_ok( (int) $b2a['total'] > \ApSemplice\Broadcasts::BATCH && 'sending' === $b2a['status'] && \ApSemplice\Broadcasts::BATCH === (int) $b2a['sent'] && count( $bc_mail ) === \ApSemplice\Broadcasts::BATCH, 'comunicazioni: i primi 25 partono subito, gli altri restano in coda' );
for ( $i = 0; $i < 40 && 'sending' === \ApSemplice\Broadcasts::get( $b2 )['status']; $i++ ) {
	\ApSemplice\Broadcasts::process_all();
}
$b2b = \ApSemplice\Broadcasts::get( $b2 );
apse_ok( 'done' === $b2b['status'] && (int) $b2b['sent'] === (int) $b2b['total'] && 0 === (int) $b2b['failed'] && count( $bc_mail ) === (int) $b2b['total'], 'comunicazioni: il resto parte in background fino al completamento (una email a persona)' );
// email non partite e nuovo tentativo
$GLOBALS['bc_fail'] = 'dario.corsista@example.com';
$b3  = \ApSemplice\Broadcasts::create( 'Avviso con errore', 'Prova.', 'activity', $acq );
$b3a = \ApSemplice\Broadcasts::get( $b3 );
apse_ok( 1 === (int) $b3a['failed'] && (int) $b3a['sent'] === (int) $b3a['total'] - 1, 'comunicazioni: le email non partite sono contate' );
$GLOBALS['bc_fail'] = '';
apse_ok( 1 === \ApSemplice\Broadcasts::retry_failed( $b3 ) && 0 === (int) \ApSemplice\Broadcasts::get( $b3 )['failed'] && 'done' === \ApSemplice\Broadcasts::get( $b3 )['status'], 'comunicazioni: si possono riprovare' );
// controlli
apse_ok( null !== apse_throws( function () { \ApSemplice\Broadcasts::create( '', 'testo', 'members_all' ); } ) && null !== apse_throws( function () { \ApSemplice\Broadcasts::create( 'Oggetto', 'testo', 'gruppo_inesistente' ); } ) && null !== apse_throws( function () { \ApSemplice\Broadcasts::create( 'Oggetto', str_repeat( 'x', 6000 ), 'members_all' ); } ), 'comunicazioni: oggetto e testo obbligatori, gruppo valido, lunghezza limitata' );
$empty_s = $first_session( $mkev( 'Evento senza prenotati', 0, null ) );
apse_ok( false !== strpos( (string) apse_throws( function () use ( $empty_s ) { \ApSemplice\Broadcasts::create( 'Oggetto', 'testo', 'session', $empty_s ); } ), 'Nessun destinatario' ), 'comunicazioni: un gruppo senza destinatari viene rifiutato' );
$bc_mail = array();
\ApSemplice\Broadcasts::send_test( 'Oggetto prova', 'Testo di prova' );
apse_ok( 1 === count( $bc_mail ) && 0 === strpos( $bc_mail[0]['subject'], '[Prova] ' ), 'comunicazioni: la prova va solo all\'utente collegato' );
// pagine e azioni
$bc_send = new ReflectionMethod( Admin\Actions::class, 'broadcast_send' );
$bc_res  = $bc_send->invoke( null, array( 'audience' => 'activity:' . $acq, 'subject' => 'Dalla pagina', 'body' => 'Testo dalla pagina' ) );
apse_ok( false !== strpos( $bc_res[1], 'Comunicazione avviata' ) && array( 'activity', $acq ) === Admin\MessagesPage::parse_audience( 'activity:' . $acq ) && array( 'members_all', 0 ) === Admin\MessagesPage::parse_audience( 'members_all' ), 'comunicazioni: invio dalla pagina' );
apse_render( array( Admin\MessagesPage::class, 'render' ), 'Storico' );
apse_render( array( Admin\MessagesPage::class, 'render' ), 'Destinatari:', array( 'audience' => 'members_active', 'subject' => 'Oggetto', 'body' => 'Testo', 'preview' => '1' ) );
apse_render( array( Admin\MessagesPage::class, 'render' ), 'Tutte le comunicazioni', array( 'view' => $b1 ) );
wp_set_current_user( $sec_u );
apse_ok( false !== strpos( Admin\Admin::tabs( 'apse-people' ), 'page=apse-messages' ), 'comunicazioni: la segreteria le vede' );
wp_set_current_user( 1 );
remove_all_filters( 'pre_wp_mail' );

// ---------- Calendario nell'area soci ----------
apse_ok( isset( \ApSemplice\Frontend\Shortcodes::VIEWS['calendario'] ), 'sito: esiste la vista calendario' );
$cal_front = $as( $u_ord, '[apsemplice_calendario]' );
apse_ok( false !== strpos( $cal_front, 'apsf-calendar' ) && false !== strpos( $cal_front, 'Calendario' ), 'sito: il socio vede il calendario del mese' );
$_GET['apsf_m'] = substr( $today, 0, 7 );
$cal_month = $as( $u_ord, '[apsemplice_calendario]' );
apse_ok( false !== strpos( $cal_month, 'Scrittura creativa' ) || false !== strpos( $cal_month, 'Nessuna lezione' ) || false !== strpos( $cal_month, 'Teatro' ) || false !== strpos( $cal_month, 'apsf-list' ), 'sito: il calendario elenca le lezioni del mese' );
unset( $_GET['apsf_m'] );
apse_ok( false !== strpos( $as( $u_ord, '[apsemplice_area_soci]' ), 'apsf-calendar' ), 'sito: il calendario è anche nell\'area soci' );
apse_ok( false === strpos( $as( 0, '[apsemplice_calendario]' ), 'apsf-calendar' ), 'sito: senza accesso il calendario non si vede' );

// ---------- Calcolatrice del resto ----------
$dash_c = apse_render( array( Admin\DashboardPage::class, 'render' ), 'Cassa rapida' );
apse_ok( false !== strpos( $dash_c, 'data-apse-change' ) && false !== strpos( $dash_c, 'apse-cashbox' ) && false !== strpos( $dash_c, 'apse-tendered' ), 'cassa rapida: c\'è la calcolatrice del resto per i contanti' );
$walk_c = apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Ingresso senza prenotazione', array( 'id' => $event ) );
apse_ok( false !== strpos( $walk_c, 'data-guest-fee' ) && false !== strpos( $walk_c, 'apse-cashbox' ), 'ingresso sul posto: calcolatrice del resto con il contributo di soci e ospiti' );
apse_ok( false !== strpos( apse_render( array( Admin\IncomePage::class, 'render' ), 'apse-income-data' ), 'id="apse-cash"' ), 'incasso: la calcolatrice del resto c\'è già' );

// ---------- Date dinamiche: date uniche, giorni ricorrenti, data di fine ----------
$rec_c = $acts->create( array( 'name' => 'Laboratorio serale', 'social_year' => $sy_label, 'kind' => 'recurring', 'fee_cents' => 500 ) );
$base_d = gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) );
$end_d  = gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) );
$n_add  = $acts->add_dates( $rec_c, array( array( 'type' => 'weekly', 'days' => array( 2, 5 ), 'from' => '19:00', 'to' => '20:00', 'start' => $base_d, 'end' => $end_d ) ), 'Sala Blu', 20 );
$rec_s  = $acts->sessions( $rec_c );
$wd_ok  = true;
foreach ( $rec_s as $s ) {
	$wd = (int) ( new DateTimeImmutable( $s['session_date'] ) )->format( 'N' );
	$wd_ok = $wd_ok && ( 2 === $wd || 5 === $wd ) && '19:00' === $s['start_time'] && '20:00' === $s['end_time'] && 'Sala Blu' === $s['location'] && 20 === (int) $s['capacity'];
}
apse_ok( $n_add >= 8 && $n_add <= 10 && count( $rec_s ) === $n_add && $wd_ok, 'date: tutti i martedì e venerdì dalle 19 alle 20 fino alla data di fine (' . $n_add . ' date)' );
$again = $acts->add_dates( $rec_c, array( array( 'type' => 'weekly', 'days' => array( 2, 5 ), 'from' => '19:00', 'to' => '20:00', 'start' => $base_d, 'end' => $end_d ), array( 'type' => 'single', 'date' => $end_d, 'from' => '10:00', 'to' => '11:00' ) ) );
apse_ok( $again <= 1, 'date: le date già presenti non si duplicano' );
$threw = false;
try {
	$acts->add_dates( $rec_c, array( array( 'type' => 'weekly', 'days' => array( 1 ), 'start' => $base_d ) ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw, 'date: i giorni ricorrenti vogliono la data di fine' );
$ad = new ReflectionMethod( Admin\Actions::class, 'add_dates' );
$ad->setAccessible( true );
$ad->invoke( null, array( 'activity_id' => (string) $rec_c, 'when' => array( array( 'type' => 'single', 'date' => gmdate( 'Y-m-d', strtotime( $today . ' +200 days' ) ), 'from' => '15:00', 'to' => '20:00' ), array( 'type' => 'single', 'date' => '' ) ), 'location' => 'Piazza', 'capacity' => '' ) );
$open = array_values( array_filter( $acts->sessions( $rec_c ), function ( $s ) {
	return 'Piazza' === $s['location'];
} ) );
apse_ok( 1 === count( $open ) && '15:00' === $open[0]['start_time'] && '20:00' === $open[0]['end_time'], 'date: una data unica dalle 15 alle 20 dal modulo (la riga vuota si ignora)' );

// creazione dal modulo con il programma a regole
$sa = new ReflectionMethod( Admin\Actions::class, 'save_activity' );
$sa->setAccessible( true );
$od_date = gmdate( 'Y-m-d', strtotime( $today . ' +40 days' ) );
$res_od  = $sa->invoke( null, array( 'name' => 'Open day', 'social_year' => $sy_label, 'kind' => 'event', 'fee' => '0', 'when' => array( array( 'type' => 'single', 'date' => $od_date, 'from' => '15:00', 'to' => '20:00' ) ), 'location' => 'Sede' ) );
$od_id   = (int) preg_replace( '/\D/', '', (string) wp_parse_url( $res_od[0], PHP_URL_QUERY ) ?: '0' );
parse_str( (string) wp_parse_url( $res_od[0], PHP_URL_QUERY ), $q_od );
$od_s = $acts->sessions( (int) $q_od['id'] );
apse_ok( 1 === count( $od_s ) && $od_date === $od_s[0]['session_date'] && '20:00' === $od_s[0]['end_time'] && 'Sede' === $od_s[0]['location'], 'evento: open day del 20 settembre dalle 15 alle 20 creato dal modulo' );
$threw = false;
try {
	$sa->invoke( null, array( 'name' => 'Evento doppio', 'social_year' => $sy_label, 'kind' => 'event', 'fee' => '0', 'when' => array( array( 'type' => 'single', 'date' => $od_date ), array( 'type' => 'single', 'date' => gmdate( 'Y-m-d', strtotime( $od_date . ' +1 day' ) ) ) ) ) );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
apse_ok( $threw, 'evento una tantum: una sola data (per più date serve l\'evento ricorrente)' );
$res_rc = $sa->invoke( null, array( 'name' => 'Corso serale a date', 'social_year' => $sy_label, 'kind' => 'recurring', 'fee' => '5,00', 'when' => array( array( 'type' => 'weekly', 'days' => array( '2', '5' ), 'from' => '19:00', 'to' => '20:00', 'start' => $base_d, 'end' => $end_d ) ) ) );
parse_str( (string) wp_parse_url( $res_rc[0], PHP_URL_QUERY ), $q_rc );
apse_ok( count( $acts->sessions( (int) $q_rc['id'] ) ) >= 8 && false !== strpos( $res_rc[1], 'date' ), 'evento ricorrente: creato con le date dei martedì e venerdì' );

// il calendario conosce l'orario di fine delle date
$occ_end = array_values( array_filter( \ApSemplice\Calendar::occurrences( $od_date, $od_date ), function ( $o ) use ( $q_od ) {
	return (int) $o['activity_id'] === (int) $q_od['id'];
} ) );
apse_ok( 1 === count( $occ_end ) && '20:00' === $occ_end[0]['end'], 'calendario: le date degli eventi hanno anche l\'orario di fine' );

// corso tutti i giorni dal modulo
$ev_c = $sa->invoke( null, array( 'name' => 'Corso ogni giorno', 'social_year' => $sy_label, 'kind' => 'course', 'fee' => '30', 'when' => array( array( 'date' => $base_d, 'from' => '10:00', 'to' => '11:00', 'recurring' => '1', 'repeat' => 'daily', 'end' => $end_d ), array( 'date' => $od_date, 'from' => '15:00', 'to' => '16:00' ) ) ) );
parse_str( (string) wp_parse_url( $ev_c[0], PHP_URL_QUERY ), $q_ev );
$evd = \ApSemplice\ActivityService::slot_days( $acts->get( (int) $q_ev['id'] ) );
apse_ok( array( 1, 2, 3, 4, 5, 6, 7 ) === $evd, 'corso: si può tenere tutti i giorni' );
$cform = apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Come si paga', array( 'id' => (int) $q_ev['id'] ) );
$rows_ev  = \ApSemplice\ActivityService::schedule_rows( $acts->get( (int) $q_ev['id'] ), $today );
$dates_ev = \ApSemplice\ActivityService::lesson_dates( $acts->get( (int) $q_ev['id'] ) );
apse_ok( 8 === count( $rows_ev ) && 1 === count( $dates_ev ) && $od_date === $dates_ev[0]['date'] && false !== strpos( $cform, 'data-rows' ), 'corso: sette giorni ricorrenti più una data unica, riletti nel modulo' );
$occ_ev = array_values(
	array_filter(
		\ApSemplice\Calendar::occurrences( $base_d, $end_d ),
		function ( $o ) use ( $q_ev ) {
			return (int) $o['activity_id'] === (int) $q_ev['id'];
		}
	)
);
apse_ok( count( $occ_ev ) >= 29 && count( $occ_ev ) <= 31 && '10:00' === $occ_ev[0]['start'], 'calendario: il corso tutti i giorni compare ogni giorno fino alla data di fine (' . count( $occ_ev ) . ')' );
$nform = apse_render( array( Admin\ActivitiesPage::class, 'render_list' ), 'Quando *' );
apse_ok( false !== strpos( $nform, 'apse-when' ) && false !== strpos( $nform, 'Aggiungi data' ) && false !== strpos( $nform, 'ricorrente' ), 'nuova attività: righe dinamiche con la spunta ricorrente' );
// doposcuola: ogni martedì ricorrente e un solo venerdì
$dop = $sa->invoke(
	null,
	array(
		'name' => 'Doposcuola', 'social_year' => $sy_label, 'kind' => 'course', 'fee' => '40', 'billing' => 'monthly',
		'when' => array(
			array( 'date' => gmdate( 'Y-m-d', strtotime( 'next tuesday', strtotime( $today ) ) ), 'from' => '15:00', 'to' => '17:00', 'recurring' => '1', 'repeat' => 'weekly', 'end' => $end_d ),
			array( 'date' => gmdate( 'Y-m-d', strtotime( 'next friday', strtotime( $today ) ) ), 'from' => '15:00', 'to' => '17:00' ),
		),
	)
);
parse_str( (string) wp_parse_url( $dop[0], PHP_URL_QUERY ), $q_dop );
$dop_a = $acts->get( (int) $q_dop['id'] );
apse_ok( array( 2 ) === \ApSemplice\ActivityService::slot_days( $dop_a ) && 1 === count( \ApSemplice\ActivityService::lesson_dates( $dop_a ) ) && 'monthly' === $dop_a['billing'], 'doposcuola: una riga ricorrente (martedì) e una data unica (un venerdì)' );
$dop_occ = array_values(
	array_filter(
		\ApSemplice\Calendar::occurrences( $today, $end_d ),
		function ( $o ) use ( $q_dop ) {
			return (int) $o['activity_id'] === (int) $q_dop['id'];
		}
	)
);
$dop_fri = array_filter(
	$dop_occ,
	function ( $o ) {
		return 5 === (int) ( new DateTimeImmutable( $o['date'] ) )->format( 'N' );
	}
);
apse_ok( count( $dop_occ ) >= 4 && 1 === count( $dop_fri ), 'doposcuola: tutti i martedì fino alla fine e un solo venerdì in calendario' );

// ---------- Incassa dalla bacheca: le voci dovute si compilano da sole ----------
$au_html = apse_render( array( Admin\IncomePage::class, 'render' ), 'apse-income-data', array( 'person_id' => (string) $fy_p, 'due' => '1' ) );
apse_ok( false !== strpos( $au_html, '"autofill":true' ), 'incassa: dal pulsante della bacheca la pagina si autocompila' );
apse_ok( false === strpos( apse_render( array( Admin\IncomePage::class, 'render' ), 'apse-income-data', array( 'person_id' => (string) $fy_p ) ), '"autofill":true' ), 'incasso aperto a mano: nessuna compilazione automatica' );
$dash_due = apse_render( array( Admin\DashboardPage::class, 'render' ), 'Pagamenti da incassare' );
apse_ok( false !== strpos( $dash_due, 'due=1' ), 'bacheca: il pulsante Incassa chiede la compilazione automatica' );

// ---------- Tessera: migrazione delle iscrizioni dall'anno sociale all'anno solare ----------
$mg_p = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Vecchia', 'last_name' => 'Iscrizione', 'email' => 'vecchia.iscrizione@example.com' ) );
$mg_y = (int) substr( $today, 0, 4 );
$people->set_membership( $mg_p, ( $mg_y - 1 ) . '/' . $mg_y, true, 'manual' );
apse_ok( $mg_y . '-08-31' === $people->active_until( $mg_p ), 'migrazione: la vecchia iscrizione scadeva il 31 agosto' );
$mg_q = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Doppia', 'last_name' => 'Iscrizione', 'email' => 'doppia.iscrizione@example.com' ) );
$people->set_membership( $mg_q, ( $mg_y - 1 ) . '/' . $mg_y, true, 'manual' );
$people->set_membership( $mg_q, (string) $mg_y, true, 'manual' );
$mg_n = \ApSemplice\Install::migrate_membership_years();
apse_ok( $mg_n >= 2 && $mg_y . '-12-31' === $people->active_until( $mg_p ), 'migrazione: ora la tessera scade il 31 dicembre (' . $people->active_until( $mg_p ) . ')' );
apse_ok( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = ' . $mg_q . ' AND deleted_at IS NULL' ) && $mg_y . '-12-31' === $people->active_until( $mg_q ), 'migrazione: se c\'è già l\'anno solare resta quello, senza doppioni' );
apse_ok( 0 === \ApSemplice\Install::migrate_membership_years(), 'migrazione: rilanciarla non cambia nulla' );
apse_ok( 1 === preg_match( '/^[0-9.]+\.\d{6,}$/', Plugin::asset_version( 'admin.js' ) ), 'script: la versione cambia a ogni aggiornamento (nessuna cache vecchia)' );

// ---------- Render di tutte le pagine ----------
$_SERVER['REQUEST_METHOD'] = 'GET';
apse_render( array( Admin\DashboardPage::class, 'render' ), 'Disponibilità' );
apse_render( array( Admin\PeoplePage::class, 'render_list' ), 'Fondi' );
apse_render( array( Admin\PeoplePage::class, 'render_list' ), 'Veronica', array( 'type' => 'volunteer', 'q' => 'volta' ) );
apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Tessera e iscrizione', array( 'id' => $founder ) );
apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Attività e pagamenti', array( 'id' => $vol ) );
apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Nuovo ospite', array( 'type' => 'guest' ) );
apse_render( array( Admin\ActivitiesPage::class, 'render_list' ), 'Yoga' );
apse_render( array( Admin\ActivitiesPage::class, 'render_list' ), 'Serata giochi' );
apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Data e prenotazioni', array( 'id' => $event ) );
apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Date e prenotazioni', array( 'id' => $rec ) );
apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Iscritti e pagamenti', array( 'id' => $corso ) );
apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Eventi e prenotazioni', array( 'id' => $founder ) );
apse_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Iscritti e pagamenti', array( 'id' => $yoga ) );
apse_render( array( Admin\IncomePage::class, 'render' ), 'apse-income-data' );
apse_render( array( Admin\ExpensePage::class, 'render' ), 'Registra spesa' );
apse_render( array( Admin\TransferPage::class, 'render' ), 'Registra giroconto' );
apse_render( array( Admin\LedgerPage::class, 'render' ), 'Rimborso istruttrice' );
apse_render( array( Admin\AccountsPage::class, 'render' ), 'Verifica saldo' );
apse_render( array( Admin\ReportsPage::class, 'render' ), 'Saldi dei conti' );
apse_render( array( Admin\ReportsPage::class, 'render' ), 'Soci iscritti', array( 'mode' => 'social' ) );
apse_render( array( Admin\SettingsPage::class, 'render' ), 'Chiave di licenza' );
apse_render( array( Admin\SettingsPage::class, 'render' ), 'Crea le pagine standard' );
apse_render( array( Admin\AuditPage::class, 'render' ), 'Registro azioni' );
apse_render( array( Admin\ImportPage::class, 'render' ), 'Importa da Excel o CSV' );

// ---------- Livelli di socio, quote differenziate e nucleo familiare ----------
wp_set_current_user( 1 );
$lv_seed = \ApSemplice\Levels::all();
$lv_bases = array_unique( array_column( $lv_seed, 'base_type' ) );
sort( $lv_bases );
apse_ok( 3 === count( $lv_bases ) && count( $lv_seed ) >= 3 && ! in_array( 'guest', $lv_bases, true ), 'livelli: ce n\'è uno per ogni base fin dall\'inizio' );
$lv_rows = array();
foreach ( $lv_seed as $l ) {
	$lv_rows[] = array( 'id' => $l['id'], 'name' => $l['name'], 'base_type' => $l['base_type'], 'fee' => null === $l['fee_cents'] ? '' : \ApSemplice\Money::plain( (int) $l['fee_cents'] ), 'active' => 1 );
}
$lv_rows[] = array( 'id' => 0, 'name' => 'Socio ridotto', 'base_type' => 'ordinary', 'fee' => '5,00', 'active' => 1 );
$lv_rows[] = array( 'id' => 0, 'name' => 'Sostenitore', 'base_type' => 'ordinary', 'fee' => '', 'active' => 1 );
\ApSemplice\Levels::save( $lv_rows );
$lv_by = array();
foreach ( \ApSemplice\Levels::all() as $l ) {
	$lv_by[ $l['name'] ] = $l;
}
apse_ok( isset( $lv_by['Socio ridotto'], $lv_by['Sostenitore'] ) && 500 === (int) $lv_by['Socio ridotto']['fee_cents'] && null === $lv_by['Sostenitore']['fee_cents'], 'livelli: si aggiungono con o senza quota propria' );
$lv_ridotto = (int) $lv_by['Socio ridotto']['id'];
$lv_p1 = $mkb( 'Rita', 'Livelloridotto' );
$people->set_level_and_family( $lv_p1, $lv_ridotto, null );
$lv_row1 = $people->get( $lv_p1 );
apse_ok( 'Socio ridotto' === \ApSemplice\Levels::label( $lv_row1 ) && 500 === \ApSemplice\Levels::fee_for( $lv_row1 ), 'livelli: nome e quota vengono dal livello' );
$lv_p2 = $mkb( 'Sara', 'Livellodefault' );
$lv_row2 = $people->get( $lv_p2 );
apse_ok( 'Socio ordinario' === \ApSemplice\Levels::label( $lv_row2 ) || 0 === strpos( \ApSemplice\Levels::label( $lv_row2 ), 'Soc' ), 'livelli: senza livello vale il nome della base' );
apse_ok( (int) Settings::get( 'membership_fee_cents' ) === \ApSemplice\Levels::fee_for( $lv_row2 ), 'livelli: senza livello vale la quota predefinita' );
apse_ok( null !== apse_throws( function () use ( $people, $lv_ridotto ) {
	$v = $people->create( array( 'type' => 'volunteer', 'first_name' => 'Vito', 'last_name' => 'Livellosbagliato', 'email' => 'vito.livellosbagliato@example.com' ) );
	$people->set_level_and_family( $v, $lv_ridotto, null );
} ), 'livelli: il livello deve avere la stessa base del tipo' );
// nucleo familiare
Settings::update( array( 'family_discount_pct' => 20 ) );
$lv_head = $mkb( 'Franco', 'Capofamiglia' );
$lv_fam  = $mkb( 'Franca', 'Capofamiglia' );
$people->set_level_and_family( $lv_fam, null, $lv_head );
$fee_default = (int) Settings::get( 'membership_fee_cents' );
apse_ok( $fee_default === \ApSemplice\Levels::fee_for( $people->get( $lv_head ) ) && (int) round( $fee_default * 0.8 ) === \ApSemplice\Levels::fee_for( $people->get( $lv_fam ) ), 'famiglia: il capofamiglia paga la quota piena, il familiare quella ridotta' );
$people->set_level_and_family( $lv_p1, $lv_ridotto, $lv_head );
apse_ok( 400 === \ApSemplice\Levels::fee_for( $people->get( $lv_p1 ) ), 'famiglia: lo sconto si applica alla quota del livello' );
$lv_third = $mkb( 'Terzo', 'Capofamiglia' );
apse_ok( null !== apse_throws( function () use ( $people, $lv_third, $lv_fam ) { $people->set_level_and_family( $lv_third, null, $lv_fam ); } ), 'famiglia: un familiare non può fare da capofamiglia' );
apse_ok( null !== apse_throws( function () use ( $people, $lv_head ) { $people->set_level_and_family( $lv_head, null, $lv_head ); } ), 'famiglia: nessuno è capofamiglia di se stesso' );
apse_ok( null !== apse_throws( function () use ( $people, $lv_head, $lv_third ) { $people->set_level_and_family( $lv_head, null, $lv_third ); } ), 'famiglia: chi ha già dei familiari non diventa familiare' );
apse_ok( 2 <= count( $people->family_of( $lv_head ) ), 'famiglia: elenco dei familiari' );
$lv_guest = $people->create( array( 'type' => 'guest', 'first_name' => 'Gino', 'last_name' => 'Livelloospite', 'phone' => '333 5550123', 'host_person_id' => $lv_head ) );
$people->set_level_and_family( $lv_guest, $lv_ridotto, $lv_head );
$lv_grow = $people->get( $lv_guest );
apse_ok( empty( $lv_grow['level_id'] ) && empty( $lv_grow['family_head_id'] ), 'livelli: gli ospiti non hanno livello né nucleo' );
// livello in uso: non si cancella, si disattiva
$lv_rows2 = array();
foreach ( \ApSemplice\Levels::all() as $l ) {
	if ( 'Socio ridotto' === $l['name'] || 'Sostenitore' === $l['name'] ) {
		continue;
	}
	$lv_rows2[] = array( 'id' => $l['id'], 'name' => $l['name'], 'base_type' => $l['base_type'], 'fee' => '', 'active' => 1 );
}
\ApSemplice\Levels::save( $lv_rows2 );
$lv_after = array();
foreach ( \ApSemplice\Levels::all() as $l ) {
	$lv_after[ $l['name'] ] = $l;
}
apse_ok( isset( $lv_after['Socio ridotto'] ) && 0 === (int) $lv_after['Socio ridotto']['active'] && ! isset( $lv_after['Sostenitore'] ), 'livelli: se ha dei soci resta disattivato, altrimenti viene tolto' );
apse_ok( null !== apse_throws( function () { \ApSemplice\Levels::save( array( array( 'id' => 0, 'name' => 'Solo uno', 'base_type' => 'ordinary', 'fee' => '', 'active' => 1 ) ) ); } ), 'livelli: serve almeno un livello attivo per ogni base' );
apse_ok( null !== apse_throws( function () { \ApSemplice\Levels::save( array( array( 'id' => 0, 'name' => 'A', 'base_type' => 'guest', 'fee' => '', 'active' => 1 ) ) ); } ), 'livelli: la base deve essere un tipo di socio' );
apse_ok( Admin\Actions::required_cap( 'apse_save_levels' ) === Plugin::CAP, 'livelli: li modifica solo l\'amministratore' );
apse_render( array( Admin\SettingsPage::class, 'render' ), 'Livelli di socio' );
apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Nucleo familiare', array( 'id' => $lv_fam ) );
Settings::update( array( 'family_discount_pct' => 0 ) );

// ---------- Libro soci, verbali, volontari e assicurazione, presenze, rendiconto ----------
wp_set_current_user( 1 );
$rg_p = $mkb( 'Livia', 'Libroregistro' );
$bk_all = \ApSemplice\MemberBook::rows();
$bk_ids = array_column( $bk_all, 'id' );
apse_ok( in_array( $rg_p, $bk_ids, true ) && ! in_array( $guest, $bk_ids, true ) && $bk_all[0]['n'] === 1 && count( $bk_all ) === count( $bk_ids ), 'libro soci: tutti i soci con numero progressivo, senza ospiti' );
\ApSemplice\MemberBook::set_left( $rg_p, $today, 'recesso' );
$bk_left = array_column( \ApSemplice\MemberBook::rows( 'left' ), 'id' );
$bk_in   = array_column( \ApSemplice\MemberBook::rows( 'in_force' ), 'id' );
apse_ok( in_array( $rg_p, $bk_left, true ) && ! in_array( $rg_p, $bk_in, true ) && in_array( $rg_p, array_column( \ApSemplice\MemberBook::rows(), 'id' ), true ), 'libro soci: il socio cessato resta nel libro con la sua data' );
apse_ok( null !== apse_throws( function () use ( $rg_p ) { \ApSemplice\MemberBook::set_left( $rg_p, gmdate( 'Y-m-d', strtotime( '+5 days' ) ) ); } ) && null !== apse_throws( function () use ( $rg_p ) { \ApSemplice\MemberBook::set_left( $rg_p, '2001-01-01' ); } ) && null !== apse_throws( function () use ( $guest, $today ) { \ApSemplice\MemberBook::set_left( $guest, $today ); } ), 'libro soci: la cessazione non può essere futura, precedente all\'ingresso o di un ospite' );
$bk_doc = \ApSemplice\Docs::book();
$bk_csv = \ApSemplice\Docs::book( '', 'csv' );
apse_ok( 0 === strpos( $bk_doc['body'], '%PDF-1.4' ) && false !== strpos( $bk_doc['body'], 'LIBRO DEI SOCI' ) && false !== strpos( $bk_csv['body'], 'Libroregistro Livia' ) && false !== strpos( $bk_csv['body'], 'recesso' ), 'libro soci: PDF e CSV' );
\ApSemplice\MemberBook::set_left( $rg_p, '' );
apse_ok( in_array( $rg_p, array_column( \ApSemplice\MemberBook::rows( 'in_force' ), 'id' ), true ), 'libro soci: la cessazione si toglie' );
// cessazione: chi ha lasciato l'associazione non è più un socio attivo
$lf_p = $mkb( 'Lia', 'Lasciata' );
$people->set_membership( $lf_p, Settings::membership_year()->label(), true );
apse_ok( $people->is_active_member( $lf_p ), 'cessazione: prima della cessazione il socio è attivo' );
\ApSemplice\MemberBook::set_left( $lf_p, $today, 'recesso' );
apse_ok( ! $people->is_active_member( $lf_p ) && $people->is_suspended( $lf_p ), 'cessazione: chi ha lasciato non è più un socio attivo' );
apse_ok( null !== apse_throws( function () use ( $people, $lf_p ) { $people->reactivate( $lf_p ); } ), 'cessazione: non si riattiva finché c\'è la cessazione' );
\ApSemplice\MemberBook::set_left( $lf_p, '' );
apse_ok( $people->is_active_member( $lf_p ) && ! $people->is_suspended( $lf_p ), 'cessazione: tolta, il socio torna in carica' );
$lf_f = $people->create( array( 'type' => 'founder', 'first_name' => 'Fabio', 'last_name' => 'Fondatorelasciato', 'email' => 'fabio.fondatorelasciato@example.com', 'joined_on' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ) ) );
apse_ok( $people->is_active_member( $lf_f ), 'cessazione: il fondatore è sempre in regola' );
\ApSemplice\MemberBook::set_left( $lf_f, $today, 'dimissioni' );
apse_ok( ! $people->is_active_member( $lf_f ), 'cessazione: il fondatore che ha lasciato non è più attivo' );
\ApSemplice\MemberBook::set_left( $lf_f, '' );
apse_ok( $people->is_active_member( $lf_f ), 'cessazione: il fondatore riammesso torna sempre in regola' );
// campi troppo lunghi e date impossibili sono rifiutati prima di arrivare al database
apse_ok( null !== apse_throws( function () use ( $people ) { $people->create( array( 'type' => 'ordinary', 'first_name' => str_repeat( 'a', 130 ), 'last_name' => 'Lungo', 'email' => 'nome.lungo@example.com' ) ); } ), 'persone: un nome troppo lungo è rifiutato' );
apse_ok( null !== apse_throws( function () use ( $people ) { $people->create( array( 'type' => 'ordinary', 'first_name' => 'Data', 'last_name' => 'Impossibile', 'email' => 'data.impossibile@example.com', 'joined_on' => '2023-02-30' ) ); } ), 'persone: una data di ingresso impossibile è rifiutata' );
// l'anno della tessera in un incasso deve avere la forma di un anno
apse_ok( null !== apse_throws( function () use ( $ledger, $today, $cash, $cat, $lf_p ) { $ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $lf_p, 'lines' => array( array( 'category_id' => $cat['membership'], 'amount_cents' => 1000, 'social_year' => 'qualcosa' ) ) ) ); } ), 'incasso: l\'anno della tessera non valido è rifiutato' );
// blocchi con nome: l'operazione parte e restituisce il suo risultato; l'eccezione passa e il blocco si libera
apse_ok( 42 === \ApSemplice\Db::with_lock( 'apse_test_lock', function () { return 42; } ), 'blocco: restituisce il risultato' );
apse_ok( null !== apse_throws( function () { \ApSemplice\Db::with_lock( 'apse_test_lock', function () { throw new \InvalidArgumentException( 'errore di prova' ); } ); } ) && 7 === \ApSemplice\Db::with_lock( 'apse_test_lock', function () { return 7; } ), 'blocco: dopo un errore il blocco è libero' );
apse_render( array( Admin\RegistersPage::class, 'render_book' ), 'Libro soci' );
apse_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Cessazione', array( 'id' => $rg_p ) );
// verbali
$mn_y  = substr( $today, 0, 4 );
$mn_1  = \ApSemplice\Minutes::save( 0, array( 'kind' => 'assembly', 'meeting_date' => $mn_y . '-03-10', 'place' => 'Sede', 'title' => 'Approvazione del bilancio', 'attendees' => 'Presenti 12 soci', 'agenda' => '1. Bilancio', 'body' => "Si approva il bilancio.\nVoti favorevoli: tutti.", 'approved_on' => '' ), 1 );
$mn_2  = \ApSemplice\Minutes::save( 0, array( 'kind' => 'assembly', 'meeting_date' => $mn_y . '-05-12', 'title' => 'Seconda assemblea', 'body' => 'Si discute.' ), 1 );
$mn_3  = \ApSemplice\Minutes::save( 0, array( 'kind' => 'board', 'meeting_date' => $mn_y . '-02-01', 'title' => 'Riunione del consiglio', 'body' => 'Si decide.' ), 1 );
apse_ok( $mn_y . '' === substr( \ApSemplice\Minutes::get( $mn_1 )['number'], 2 ) && '1/' . $mn_y === \ApSemplice\Minutes::get( $mn_1 )['number'] && '2/' . $mn_y === \ApSemplice\Minutes::get( $mn_2 )['number'] && '1/' . $mn_y === \ApSemplice\Minutes::get( $mn_3 )['number'], 'verbali: numerazione progressiva per tipo e anno, in ordine di data' );
apse_ok( 2 === count( \ApSemplice\Minutes::all( 'assembly', (int) $mn_y ) ) && null !== apse_throws( function () { \ApSemplice\Minutes::save( 0, array( 'kind' => 'altro', 'meeting_date' => '2026-01-01', 'title' => 'x', 'body' => 'x' ) ); } ) && null !== apse_throws( function () { \ApSemplice\Minutes::save( 0, array( 'kind' => 'board', 'meeting_date' => '2026-13-45', 'title' => 'x', 'body' => 'x' ) ); } ) && null !== apse_throws( function () { \ApSemplice\Minutes::save( 0, array( 'kind' => 'board', 'meeting_date' => '2026-01-01', 'title' => '', 'body' => 'x' ) ); } ) && null !== apse_throws( function () { \ApSemplice\Minutes::save( 0, array( 'kind' => 'board', 'meeting_date' => '2026-01-01', 'title' => 'x', 'body' => '' ) ); } ), 'verbali: tipo, data, titolo e contenuto obbligatori' );
\ApSemplice\Minutes::save( $mn_1, array( 'kind' => 'assembly', 'meeting_date' => $mn_y . '-03-10', 'place' => 'Sede', 'title' => 'Approvazione del bilancio', 'body' => 'Testo corretto.', 'approved_on' => $mn_y . '-05-12' ), 1 );
$mn_pdf = \ApSemplice\Docs::minute( $mn_1 );
apse_ok( 0 === strpos( $mn_pdf['body'], '%PDF-1.4' ) && false !== strpos( $mn_pdf['body'], 'VERBALE N. 1/' . $mn_y ) && false !== strpos( $mn_pdf['body'], 'Approvazione del bilancio' ) && false !== strpos( $mn_pdf['body'], 'Testo corretto.' ) && false !== strpos( $mn_pdf['body'], 'letto e approvato' ) && false !== strpos( $mn_pdf['body'], 'Il Segretario' ), 'verbali: il PDF ha numero, titolo, testo, approvazione e firme' );
\ApSemplice\Minutes::delete( $mn_3 );
apse_ok( null === \ApSemplice\Minutes::get( $mn_3 ) && null !== apse_throws( function () use ( $mn_3 ) { \ApSemplice\Docs::minute( $mn_3 ); } ), 'verbali: un verbale eliminato non compare più' );
apse_render( array( Admin\RegistersPage::class, 'render_minutes' ), 'Approvazione del bilancio' );
apse_render( array( Admin\RegistersPage::class, 'render_minutes' ), 'Stampa il PDF', array( 'view' => $mn_1 ) );
apse_render( array( Admin\RegistersPage::class, 'render_minutes' ), 'Crea il verbale', array( 'new' => '1' ) );
// volontari e assicurazione
apse_ok( ! Settings::insurance_volunteers() && ! Settings::insurance_association(), 'assicurazioni: spente di default' );
apse_render( array( Admin\RegistersPage::class, 'render_volunteers' ), 'Le assicurazioni sono spente' );
Settings::update( array( 'insurance_volunteers' => 1, 'insurance_association' => 1 ) );
$in_day = function ( int $d ) use ( $today ) {
	return gmdate( 'Y-m-d', strtotime( $today . ' ' . ( $d >= 0 ? '+' : '' ) . $d . ' days' ) );
};
$st_of = function () use ( $vol ) {
	foreach ( \ApSemplice\Insurance::register() as $r ) {
		if ( (int) $r['person']['id'] === $vol ) {
			return $r['status'];
		}
	}
	return null;
};
apse_ok( 'none' === $st_of(), 'assicurazione: senza polizza il volontario risulta scoperto' );
\ApSemplice\Insurance::add( $vol, 'Assicurazioni Prova', 'P-100', $in_day( -400 ), $in_day( -35 ), '' );
apse_ok( 'expired' === $st_of(), 'assicurazione: polizza scaduta' );
$ins_soon = \ApSemplice\Insurance::add( $vol, 'Assicurazioni Prova', 'P-101', $in_day( -5 ), $in_day( 10 ), '' );
apse_ok( 'expiring' === $st_of(), 'assicurazione: polizza in scadenza' );
apse_render( array( Admin\DashboardPage::class, 'render' ), 'Assicurazione dei volontari' );
\ApSemplice\Insurance::add( $vol, 'Assicurazioni Prova', 'P-102', $in_day( -5 ), $in_day( 200 ), 'rinnovo' );
apse_ok( 'valid' === $st_of() && 3 === count( \ApSemplice\Insurance::for_person( $vol ) ), 'assicurazione: vale la polizza che copre di più' );
apse_ok( null !== apse_throws( function () use ( $vol, $in_day ) { \ApSemplice\Insurance::add( $vol, 'X', '', $in_day( 10 ), $in_day( 1 ), '' ); } ) && null !== apse_throws( function () use ( $vol, $in_day ) { \ApSemplice\Insurance::add( $vol, '', '', $in_day( 1 ), $in_day( 10 ), '' ); } ) && null !== apse_throws( function () use ( $guest, $in_day ) { \ApSemplice\Insurance::add( $guest, 'X', '', $in_day( 1 ), $in_day( 10 ), '' ); } ), 'assicurazione: date coerenti, compagnia obbligatoria, solo per i soci' );
\ApSemplice\Insurance::delete( $ins_soon );
apse_ok( 2 === count( \ApSemplice\Insurance::for_person( $vol ) ), 'assicurazione: si elimina una polizza' );
$vl_doc = \ApSemplice\Docs::volunteers();
apse_ok( false !== strpos( $vl_doc['body'], 'REGISTRO DEI VOLONTARI' ) && false !== strpos( \ApSemplice\Docs::volunteers( 'csv' )['body'], 'Assicurazioni Prova' ), 'assicurazione: PDF e CSV del registro' );
apse_render( array( Admin\RegistersPage::class, 'render_volunteers' ), 'Registra una polizza' );
// polizze dell'associazione
apse_ok( 'none' === \ApSemplice\AssocPolicies::rc_status(), 'polizze associazione: senza responsabilità civile risulta scoperta' );
apse_render( array( Admin\DashboardPage::class, 'render' ), 'polizza di responsabilità civile' );
$pl_old = \ApSemplice\AssocPolicies::add( 'rc', 'Compagnia RC', 'RC-1', $in_day( -400 ), $in_day( -35 ) );
apse_ok( 'expired' === \ApSemplice\AssocPolicies::rc_status(), 'polizze associazione: scaduta' );
\ApSemplice\AssocPolicies::add( 'rc', 'Compagnia RC', 'RC-2', $in_day( -5 ), $in_day( 10 ), '350,00', 'Massimale 1.000.000 €' );
apse_ok( 'expiring' === \ApSemplice\AssocPolicies::rc_status(), 'polizze associazione: in scadenza' );
\ApSemplice\AssocPolicies::add( 'rc', 'Compagnia RC', 'RC-3', $in_day( -5 ), $in_day( 300 ), '360,00', 'Massimale 1.000.000 €' );
\ApSemplice\AssocPolicies::add( 'accident', 'Compagnia Infortuni', 'INF-1', $in_day( -5 ), $in_day( 300 ) );
$pl_st = \ApSemplice\AssocPolicies::statuses();
apse_ok( 'valid' === \ApSemplice\AssocPolicies::rc_status() && 'RC-3' === $pl_st['rc']['policy']['policy_no'] && 36000 === (int) $pl_st['rc']['policy']['premium_cents'] && 'valid' === $pl_st['accident']['status'] && 'none' === $pl_st['other']['status'], 'polizze associazione: vale quella che copre di più, per tipo' );
apse_ok( null !== apse_throws( function () use ( $in_day ) { \ApSemplice\AssocPolicies::add( 'strana', 'X', '', $in_day( 1 ), $in_day( 5 ) ); } ) && null !== apse_throws( function () use ( $in_day ) { \ApSemplice\AssocPolicies::add( 'rc', '', '', $in_day( 1 ), $in_day( 5 ) ); } ) && null !== apse_throws( function () use ( $in_day ) { \ApSemplice\AssocPolicies::add( 'rc', 'X', '', $in_day( 9 ), $in_day( 5 ) ); } ) && null !== apse_throws( function () use ( $in_day ) { \ApSemplice\AssocPolicies::add( 'rc', 'X', '', $in_day( 1 ), $in_day( 5 ), 'tanto' ); } ), 'polizze associazione: tipo, compagnia, date e premio validi' );
\ApSemplice\AssocPolicies::delete( $pl_old );
apse_ok( 3 === count( \ApSemplice\AssocPolicies::all() ), 'polizze associazione: si elimina una polizza' );
apse_render( array( Admin\RegistersPage::class, 'render_volunteers' ), 'Polizze dell\'associazione' );
apse_ok( 'Assicurazioni' === ( Admin\Admin::GROUPS['apse-book'][1]['apse-volunteers'] ?? '' ), 'assicurazioni: la scheda si chiama Assicurazioni' );
// presenze
$at_day = (int) gmdate( 'N', strtotime( $today ) );
$at_c   = $acts->create( array( 'name' => 'Corso presenze', 'social_year' => $sy_label, 'instructor_person_id' => $vol, 'monthly_fee_cents' => 1000, 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => $at_day, 'start' => '18:00', 'end' => '19:00', 'from' => substr( $today, 0, 8 ) . '01' ) ) ) );
$at_a   = $mkb( 'Anna', 'Presenteregistro' );
$at_b   = $mkb( 'Bruno', 'Assenteregistro' );
$acts->enroll( $at_c, $at_a, $month );
$acts->enroll( $at_c, $at_b, $month );
$at_act = $acts->get( $at_c );
apse_ok( in_array( $today, \ApSemplice\Attendance::lesson_dates( $at_act, substr( $today, 0, 8 ) . '01', $today ), true ) && 2 === count( \ApSemplice\Attendance::roster( $at_act, $today ) ), 'presenze: le lezioni del corso e gli iscritti attesi' );
apse_ok( 2 === \ApSemplice\Attendance::save( $at_c, $today, array( $at_a ), 1 ) && array( $at_a => true, $at_b => false ) == \ApSemplice\Attendance::marks( $at_c, $today ), 'presenze: si segna chi c\'era e chi no' );
\ApSemplice\Attendance::save( $at_c, $today, array( $at_a, $at_b ), 1 );
apse_ok( array( $at_a => true, $at_b => true ) == \ApSemplice\Attendance::marks( $at_c, $today ), 'presenze: registrare di nuovo sostituisce le presenze' );
\ApSemplice\Attendance::save( $at_c, $today, array( $at_a ), 1 );
apse_ok( null !== apse_throws( function () use ( $at_c, $in_day, $at_a ) { \ApSemplice\Attendance::save( $at_c, $in_day( 7 ), array( $at_a ) ); } ) && null !== apse_throws( function () use ( $at_c, $in_day, $at_a ) { \ApSemplice\Attendance::save( $at_c, $in_day( -1 ), array( $at_a ) ); } ) && null !== apse_throws( function () use ( $at_a ) { \ApSemplice\Attendance::save( 999999, '2026-01-01', array( $at_a ) ); } ), 'presenze: non in anticipo, non in un giorno senza lezione, solo attività esistenti' );
$at_sum = \ApSemplice\Attendance::summary( $at_c, substr( $today, 0, 4 ) . '-01-01', $today );
$at_by  = array();
foreach ( $at_sum['people'] as $r ) {
	$at_by[ $r['person_id'] ] = $r['presenze'] . '/' . $r['totale'];
}
apse_ok( array( $today ) === $at_sum['dates'] && '1/1' === $at_by[ $at_a ] && '0/1' === $at_by[ $at_b ], 'presenze: riepilogo del periodo' );
$at_doc = \ApSemplice\Docs::attendance( $at_c, substr( $today, 0, 4 ) . '-01-01', $today );
apse_ok( false !== strpos( $at_doc['body'], 'REGISTRO PRESENZE' ) && false !== strpos( $at_doc['body'], 'Presenteregistro Anna' ) && false !== strpos( $at_doc['body'], 'Corso presenze' ) && false !== strpos( \ApSemplice\Docs::attendance( $at_c, substr( $today, 0, 4 ) . '-01-01', $today, 'csv' )['body'], 'Assenteregistro Bruno' ) && null !== apse_throws( function () use ( $at_c ) { \ApSemplice\Docs::attendance( $at_c, 'ieri', 'oggi' ); } ), 'presenze: PDF e CSV del registro' );
apse_render( array( Admin\RegistersPage::class, 'render_attendance' ), 'Registra le presenze', array( 'activity' => $at_c, 'ym' => substr( $today, 0, 7 ), 'date' => $today ) );
// rendiconto
$rd_y = (int) substr( $today, 0, 4 );
$rd   = \ApSemplice\Statement::data( $rd_y );
apse_ok( $rd['result']['cur'] === $rd['total_income']['cur'] - $rd['total_expense']['cur'] && $rd['total_income']['cur'] === array_sum( array_column( $rd['income'], 'cur' ) ) && $rd['total_expense']['cur'] === array_sum( array_column( $rd['expenses'], 'cur' ) ) && in_array( $rd_y, \ApSemplice\Statement::years(), true ), 'rendiconto: totali, avanzo e anni coerenti' );
\ApSemplice\Statement::save_notes( $rd_y, "Anno positivo.\nNessuna criticità." );
$rd_doc = \ApSemplice\Docs::statement( $rd_y );
apse_ok( 0 === strpos( $rd_doc['body'], '%PDF-1.4' ) && false !== strpos( $rd_doc['body'], 'RENDICONTO PER CASSA' ) && false !== strpos( $rd_doc['body'], 'ENTRATE' ) && false !== strpos( $rd_doc['body'], 'USCITE' ) && false !== strpos( $rd_doc['body'], 'Anno positivo.' ) && false !== strpos( $rd_doc['body'], 'Il Tesoriere' ) && false !== strpos( $rd_doc['body'], 'CASSA E CONTI' ), 'rendiconto: il PDF ha entrate, uscite, cassa, relazione e firme' );
apse_ok( null !== apse_throws( function () { \ApSemplice\Docs::statement( 1999 ); } ) && null !== apse_throws( function () use ( $rd_y ) { \ApSemplice\Statement::save_notes( $rd_y, str_repeat( 'x', 6000 ) ); } ), 'rendiconto: anno valido e relazione di lunghezza limitata' );
apse_render( array( Admin\RegistersPage::class, 'render_statement' ), 'Relazione sull\'andamento della gestione', array( 'year' => $rd_y ) );
// accessi
$set_sec->invoke( null, array( 'id' => $sec_p, 'enabled' => '1' ) );
wp_set_current_user( $sec_u );
apse_ok( false !== strpos( Admin\Admin::tabs( 'apse-book' ), 'page=apse-minutes' ) && false !== strpos( Admin\Admin::tabs( 'apse-accounting' ), 'page=apse-statement' ) && Admin\Actions::required_cap( 'apse_save_levels' ) === Plugin::CAP && current_user_can( Plugin::CAP_OPS ), 'registri: la segreteria li vede' );
wp_set_current_user( 1 );
apse_ok( ( \ApSemplice\Plugin::load_for_action( 'apse_doc' ) && has_action( 'admin_post_apse_doc' ) ) && has_action( 'admin_post_apse_minute_save' ) && has_action( 'admin_post_apse_attendance_save' ) && false !== strpos( \ApSemplice\Docs::url( 'book' ), 'action=apse_doc' ), 'registri: download e azioni registrati' );

// ---------- 5x1000, guida iniziale, lingue ----------
wp_set_current_user( 1 );
$fp_cf = Settings::get( 'tax_code' );
apse_ok( ! \ApSemplice\FivePerMille::enabled() && '' === \ApSemplice\Frontend\Views::five_per_mille(), '5x1000: spento di default, il messaggio non compare' );
apse_render( array( Admin\FivePmPage::class, 'render' ), 'Il 5x1000 è spento' );
Settings::update( array( 'fivepm_enabled' => 1, 'tax_code' => '12345678901', 'association_name' => 'Associazione Cinque' ) );
$fp_view = \ApSemplice\Frontend\Views::five_per_mille();
apse_ok( false !== strpos( $fp_view, '12345678901' ) && false !== strpos( $fp_view, 'Associazione Cinque' ) && false !== strpos( do_shortcode( '[apsemplice_cinquepermille]' ), '12345678901' ), '5x1000: il messaggio ha il codice fiscale ed è disponibile come shortcode' );
Settings::update( array( 'fivepm_text' => 'Firma per {associazione} con {codice_fiscale}.' ) );
apse_ok( 'Firma per Associazione Cinque con 12345678901.' === \ApSemplice\FivePerMille::text(), '5x1000: messaggio personalizzabile con i segnaposto' );
Settings::update( array( 'tax_code' => '' ) );
apse_ok( '' === \ApSemplice\Frontend\Views::five_per_mille(), '5x1000: senza codice fiscale il messaggio non compare' );
Settings::update( array( 'tax_code' => '12345678901' ) );
$fp_1 = \ApSemplice\FivePerMille::add( 2023, '1.234,56', 80, $in_day( -300 ) );
$fp_2 = \ApSemplice\FivePerMille::add( 2022, '900', 60, $in_day( -400 ) );
$fp_r1 = \ApSemplice\FivePerMille::get( $fp_1 );
$fp_r2 = \ApSemplice\FivePerMille::get( $fp_2 );
apse_ok( 123456 === (int) $fp_r1['amount_cents'] && 'open' === \ApSemplice\FivePerMille::status( $fp_r1 ) && 'overdue' === \ApSemplice\FivePerMille::status( $fp_r2 ), '5x1000: contributi con scadenza del rendiconto a 12 mesi' );
apse_ok( 1 === count( \ApSemplice\FivePerMille::alerts() ) && 'overdue' === \ApSemplice\FivePerMille::alerts()[0]['status'], '5x1000: avviso per il rendiconto scaduto' );
apse_render( array( Admin\DashboardPage::class, 'render' ), '5x1000: 1 contributo' );
apse_ok( null !== apse_throws( function () { \ApSemplice\FivePerMille::add( 2023, '10', 1, '2026-01-01' ); } ) && null !== apse_throws( function () { \ApSemplice\FivePerMille::add( 1990, '10', 1, '2026-01-01' ); } ) && null !== apse_throws( function () { \ApSemplice\FivePerMille::add( 2021, '0', 1, '2026-01-01' ); } ) && null !== apse_throws( function () { \ApSemplice\FivePerMille::add( 2021, '10', 1, 'ieri' ); } ), '5x1000: anno già registrato, anno, importo e data validi' );
\ApSemplice\FivePerMille::report( $fp_2, $today, 'Acquisto di materiali per i corsi.' );
apse_ok( 'reported' === \ApSemplice\FivePerMille::status( \ApSemplice\FivePerMille::get( $fp_2 ) ) && 0 === count( \ApSemplice\FivePerMille::alerts() ) && null !== apse_throws( function () use ( $fp_1, $today ) { \ApSemplice\FivePerMille::report( $fp_1, $today, '' ); } ), '5x1000: il rendiconto chiude l\'avviso (e non può essere vuoto)' );
apse_render( array( Admin\FivePmPage::class, 'render' ), 'Prepara il promemoria per i soci' );
apse_render( array( Admin\FivePmPage::class, 'render' ), 'Contributi ricevuti' );
\ApSemplice\FivePerMille::delete( $fp_1 );
apse_ok( 1 === count( \ApSemplice\FivePerMille::all() ), '5x1000: si elimina un contributo' );
Settings::update( array( 'fivepm_enabled' => 0, 'fivepm_text' => '', 'tax_code' => $fp_cf ) );
// guida iniziale
$gd = \ApSemplice\Guide::steps();
apse_ok( 7 === count( $gd ) && 7 === \ApSemplice\Guide::progress()['total'] && count( \ApSemplice\Guide::options() ) >= 6, 'guida: sette passi e le funzioni facoltative' );
apse_render( array( Admin\GuidePage::class, 'render' ), 'Domande frequenti' );
apse_render( array( Admin\DashboardPage::class, 'render' ), 'Configurazione iniziale' );
Admin\RegistersActions::guide_dismiss( array( 'on' => '1' ) );
apse_ok( \ApSemplice\Guide::dismissed( 1 ) && ! \ApSemplice\Guide::show_banner( 1 ), 'guida: l\'avviso in Bacheca si può nascondere' );
Admin\RegistersActions::guide_dismiss( array( 'on' => '0' ) );
apse_ok( ! \ApSemplice\Guide::dismissed( 1 ), 'guida: e rimostrare' );
// lingue
$lg = \ApSemplice\Languages::available();
apse_ok( 'Italiano' === $lg['it'] && isset( $lg['en'] ) && 'it' === \ApSemplice\Languages::current() && 'Quota associativa' === \ApSemplice\Texts::plain( 'Quota associativa' ), 'lingue: l\'italiano è quella di partenza e il pacchetto inglese è incluso' );
Settings::update( array( 'language' => 'en' ) );
\ApSemplice\Texts::flush();
apse_ok( 'en' === \ApSemplice\Languages::current() && 'Membership fee' === \ApSemplice\Texts::plain( 'Quota associativa' ), 'lingue: i testi passano all\'inglese' );
apse_ok( '<p>Save</p>' === \ApSemplice\Texts::html( '<p>Salva</p>' ) && '<p>Salvare il file</p>' === \ApSemplice\Texts::html( '<p>Salvare il file</p>' ) && '<p>Salvataggio</p>' === \ApSemplice\Texts::html( '<p>Salvataggio</p>' ), 'lingue: le parole brevi si traducono solo se sono il testo intero' );
apse_ok( 'Membership fee: 10' === \ApSemplice\Texts::plain( 'Quota associativa: 10' ) && false !== strpos( \ApSemplice\Texts::html( '<a title="Salva">Quota associativa</a>' ), 'title="Save">Membership fee' ), 'lingue: le frasi si traducono anche dentro altro testo e negli attributi' );
\ApSemplice\Texts::save_overrides( array( 'Quota associativa' => 'Annual dues' ) );
apse_ok( 'Annual dues' === \ApSemplice\Texts::plain( 'Quota associativa' ), 'lingue: le personalizzazioni vincono sulla traduzione' );
\ApSemplice\Texts::save_overrides( array() );
apse_render( array( Admin\TextsPage::class, 'render' ), 'Usa questa lingua' );
Settings::update( array( 'language' => 'it' ) );
\ApSemplice\Texts::flush();
apse_ok( 'Quota associativa' === \ApSemplice\Texts::plain( 'Quota associativa' ) && '<p>Salva</p>' === \ApSemplice\Texts::html( '<p>Salva</p>' ), 'lingue: tornando all\'italiano i testi restano originali' );
$lg_n = \ApSemplice\Languages::save_pack( 'fr', 'Français', array( 'Salva' => 'Enregistrer', 'Quota associativa' => 'Cotisation' ) );
Settings::update( array( 'language' => 'fr' ) );
\ApSemplice\Texts::flush();
apse_ok( 2 === $lg_n && isset( \ApSemplice\Languages::available()['fr'] ) && 'Cotisation' === \ApSemplice\Texts::plain( 'Quota associativa' ) && '<p>Enregistrer</p>' === \ApSemplice\Texts::html( '<p>Salva</p>' ), 'lingue: si carica un pacchetto e lo si sceglie' );
$lg_pairs = \ApSemplice\Languages::pairs_from_rows( array( array( 'Gruppo', 'Originale', 'Traduzione' ), array( 'x', 'Quota associativa', 'Beitrag' ), array( 'x', 'Salva', '' ) ) );
apse_ok( array( 'Quota associativa' => 'Beitrag' ) === $lg_pairs && null !== apse_throws( function () { \ApSemplice\Languages::pairs_from_rows( array( array( 'A', 'B' ) ) ); } ) && null !== apse_throws( function () { \ApSemplice\Languages::save_pack( 'it', 'Italiano', array( 'Salva' => 'x' ) ); } ) && null !== apse_throws( function () { \ApSemplice\Languages::save_pack( 'xx1', 'Strana', array( 'Salva' => 'x' ) ); } ) && null !== apse_throws( function () { \ApSemplice\Languages::save_pack( 'de', '', array( 'Salva' => 'Speichern' ) ); } ), 'lingue: lettura del file di traduzione e controlli sul codice e sul nome' );
\ApSemplice\Languages::delete_pack( 'fr' );
Settings::update( array( 'language' => 'it' ) );
\ApSemplice\Texts::flush();
apse_ok( ! isset( \ApSemplice\Languages::available()['fr'] ) && 'it' === \ApSemplice\Languages::current() && Admin\Actions::required_cap( 'apse_import_language' ) === Plugin::CAP && Admin\Actions::required_cap( 'apse_save_language' ) === Plugin::CAP, 'lingue: si elimina un pacchetto caricato; scegliere e caricare è riservato agli amministratori' );

// ---------- Impostazioni in sezioni, ruoli, interruttori e WooCommerce ----------
wp_set_current_user( 1 );
$st_tabs = Admin\Admin::tabs( 'apse-settings' );
$tc_tabs = Admin\Admin::tabs( 'apse-tech' );
apse_ok( false !== strpos( $st_tabs, 'Ente e fiscalità' ) && false !== strpos( $st_tabs, 'Soci e identità' ) && false !== strpos( $st_tabs, 'Soldi' ) && false !== strpos( $st_tabs, 'Contabilità' ) && false !== strpos( $st_tabs, 'Sistema' ) && false !== strpos( Admin\Admin::tabs( 'apse-roles' ), 'Ruoli e accessi' ) && false !== strpos( $tc_tabs, 'Integrazioni' ) && false !== strpos( $tc_tabs, 'Copia di sicurezza' ), 'impostazioni: sezioni per ambito (ente e fiscalità, soci e identità, soldi, contabilità, comunicazioni, sistema) con le loro schede' );
foreach ( array( 'apse-tech', 'apse-roles', 'apse-acct' ) as $pg ) {
	apse_ok( in_array( $pg, Admin\Admin::ADMIN_ONLY, true ), 'impostazioni: ' . $pg . ' è riservata agli amministratori' );
}
apse_render( array( Admin\SettingsPage::class, 'render' ), 'Funzioni attive' );
apse_render( array( Admin\TechPage::class, 'render_integrations' ), 'WooCommerce' );
apse_render( array( Admin\TechPage::class, 'render_roles' ), 'Come la segreteria' );
apse_render( array( Admin\TechPage::class, 'render_accounting' ), 'Report e rendiconto' );
// ruoli
$ro = Admin\TechPage::roles();
apse_ok( isset( $ro['editor'] ) && ! isset( $ro['administrator'] ) && ! isset( $ro['apse_secretary'] ) && ! isset( $ro['apse_member'] ), 'ruoli: elenco dei ruoli modificabili (senza amministratore, segreteria e soci)' );
Admin\TechActions::save_roles( array( 'roles' => array( 'editor' ) ) );
apse_ok( get_role( 'editor' )->has_cap( Plugin::CAP_OPS ) && ! get_role( 'editor' )->has_cap( Plugin::CAP ), 'ruoli: un altro ruolo può avere l\'accesso operativo, senza quello da amministratore' );
Admin\TechActions::save_roles( array() );
apse_ok( ! get_role( 'editor' )->has_cap( Plugin::CAP_OPS ) && null !== apse_throws( function () { Admin\TechPage::set_role_access( 'administrator', false ); } ) && null !== apse_throws( function () { Admin\TechPage::set_role_access( 'ruolo_inesistente', true ); } ), 'ruoli: l\'accesso si toglie; amministratore e ruoli inesistenti si rifiutano' );
// interruttori
$ft = Settings::all();
Settings::update( array( 'card_enabled' => 0 ) );
$ft_p = $people->get( $rg_p );
apse_ok( '' === \ApSemplice\Frontend\Views::section_card( $ft_p ), 'funzioni: la tessera digitale si spegne dalle impostazioni' );
Settings::update( array( 'card_enabled' => 1 ) );
apse_ok( '' !== \ApSemplice\Frontend\Views::section_card( $ft_p ) && isset( Admin\SettingsPage::FEATURES['fivepm_enabled'], Admin\SettingsPage::FEATURES['insurance_volunteers'], Admin\SettingsPage::FEATURES['card_qr_enabled'] ), 'funzioni: e si riaccende; le altre funzioni sono nello stesso elenco' );
// contabilità: report e rendiconto
Admin\TechActions::save_acct( array() );
apse_ok( false === strpos( Admin\Admin::tabs( 'apse-accounting' ), 'page=apse-reports' ) && false === strpos( Admin\Admin::tabs( 'apse-accounting' ), 'page=apse-statement' ), 'contabilità: spegnendo i report le schede spariscono' );
apse_render( array( Admin\ReportsPage::class, 'render' ), 'Report e rendiconto sono spenti' );
apse_render( array( Admin\RegistersPage::class, 'render_statement' ), 'Report e rendiconto sono spenti' );
Admin\TechActions::save_acct( array( 'reports_enabled' => '1' ) );
apse_ok( false !== strpos( Admin\Admin::tabs( 'apse-accounting' ), 'page=apse-reports' ), 'contabilità: e tornano' );
// WooCommerce
if ( ! \ApSemplice\WooBridge::active() ) {
	apse_ok( null !== apse_throws( function () { Admin\TechActions::save_woo( array( 'woo_enabled' => '1' ) ); } ) && ! \ApSemplice\WooBridge::enabled(), 'woocommerce: se il negozio non c\'è l\'integrazione non si accende' );
} else {
	update_option( 'woocommerce_currency', 'USD' );
	apse_ok( ! \ApSemplice\WooBridge::currency_is_euro() && false !== strpos( (string) apse_throws( function () { \ApSemplice\WooBridge::checkout_url( array( array( 'type' => 'membership', 'person_id' => 1, 'amount_cents' => 1000, 'key' => 'q:1:2026', 'label' => 'Prova', 'person_name' => 'X' ) ), 'valuta' ); } ), 'euro' ), 'woocommerce: con un negozio che non usa l\'euro i pagamenti delle quote sono rifiutati' );
	update_option( 'woocommerce_currency', 'EUR' );
	apse_ok( \ApSemplice\WooBridge::currency_is_euro(), 'woocommerce: il negozio in euro è accettato' );
	$wc_mk = function ( string $name, string $price, string $type = 'simple' ) {
		$p = 'variable' === $type ? new WC_Product_Variable() : new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( 'publish' );
		if ( 'simple' === $type ) {
			$p->set_regular_price( $price );
			$p->set_virtual( true );
		}
		return (int) $p->save();
	};
	$wc_q   = $wc_mk( 'Quota associativa (negozio)', '25' );
	$wc_c   = $wc_mk( 'Corso presenze (negozio)', '20' );
	$wc_def = $wc_mk( 'Pagamento generico', '1' );
	$wc_var = $wc_mk( 'Prodotto variabile', '', 'variable' );
	$lv_ordn = 0;
	foreach ( \ApSemplice\Levels::all( true ) as $l ) {
		if ( 'ordinary' === $l['base_type'] && ! $lv_ordn ) {
			$lv_ordn = (int) $l['id'];
		}
	}
	\ApSemplice\WooLinks::set( 'level', $lv_ordn, $wc_q );
	\ApSemplice\WooLinks::set( 'activity', $at_c, $wc_c );
	apse_ok( $wc_q === \ApSemplice\WooLinks::product_id( 'level', $lv_ordn ) && $wc_c === \ApSemplice\WooLinks::product_id( 'activity', $at_c ) && count( \ApSemplice\WooBridge::products() ) >= 3, 'woocommerce: quote e attività collegate a prodotti' );
	apse_ok( null !== apse_throws( function () use ( $lv_ordn ) { \ApSemplice\WooLinks::set( 'level', $lv_ordn, 999999 ); } ) && null !== apse_throws( function () use ( $lv_ordn, $wc_var ) { \ApSemplice\WooLinks::set( 'level', $lv_ordn, $wc_var ); } ) && null !== apse_throws( function () use ( $wc_q ) { \ApSemplice\WooLinks::set( 'level', 999999, $wc_q ); } ) && null !== apse_throws( function () use ( $wc_q ) { \ApSemplice\WooLinks::set( 'strano', 1, $wc_q ); } ), 'woocommerce: prodotto inesistente o variabile, livello inesistente e tipo non valido si rifiutano' );
	apse_ok( ! \ApSemplice\WooBridge::enabled() && '' === Plugin::payments()->provider(), 'woocommerce: spento finché non lo attivi dalle impostazioni' );
	Admin\TechActions::save_woo( array( 'woo_enabled' => '1', 'woo_default_product' => (string) $wc_def ) );
	apse_ok( \ApSemplice\WooBridge::enabled() && 'woocommerce' === Plugin::payments()->provider() && $wc_def === (int) Settings::get( 'woo_default_product' ), 'woocommerce: attivato dalle impostazioni, al posto di Stripe e PayPal' );
	// configurazione guidata con WooCommerce: crea e collega i prodotti
	$wz_r = \ApSemplice\Wizard::apply( array( 'payment_choice' => 'woocommerce', 'product_level' => array( $lv_ordn => 'new' ), 'product_default' => 'new' ) );
	$wz_new = \ApSemplice\WooLinks::product_id( 'level', $lv_ordn );
	$wz_prod = wc_get_product( $wz_new );
	apse_ok( $wz_new > 0 && $wz_new !== $wc_q && $wz_prod && $wz_prod->is_type( 'simple' ) && $wz_prod->is_virtual() && 'publish' === $wz_prod->get_status() && (int) Settings::get( 'woo_default_product' ) !== $wc_def && 'woocommerce' === Settings::get( 'payment_provider' ), 'configurazione guidata: crea i prodotti WooCommerce e li collega a quota e voce generica' );
	apse_ok( $wz_new > 0 && count( $wz_r ) >= 3, 'configurazione guidata: riepilogo di ciò che è stato fatto' );
	\ApSemplice\WooLinks::set( 'level', $lv_ordn, $wc_q );
	Settings::update( array( 'woo_default_product' => $wc_def ) );
	// una voce: la quota del socio
	$wc_p   = $mkb( 'Walter', 'Negozio' );
	$wc_uid = (int) $people->get( $wc_p )['wp_user_id'];
	$wc_dues = Plugin::payments()->dues_for( $people->get( $wc_p ) );
	$wc_fee  = \ApSemplice\Levels::fee_for( $people->get( $wc_p ) );
	$wc_sy   = (string) ( array_values( $wc_dues )[0]['social_year'] ?? '' );
	apse_ok( 1 === count( $wc_dues ) && $wc_fee === (int) array_values( $wc_dues )[0]['amount_cents'] && $wc_fee > 0, 'woocommerce: le voci dovute sono quelle di sempre (quota associativa)' );
	wp_set_current_user( $wc_uid );
	$wc_url = '';
	$wc_err = '';
	try {
		$wc_url = Plugin::payments()->create_checkout( $people->get( $wc_p ), $wc_uid, array_keys( $wc_dues ), home_url( '/' ) );
	} catch ( \Throwable $e ) {
		$wc_err = $e->getMessage();
	}
	apse_ok( '' === $wc_err && $wc_url === wc_get_checkout_url(), 'woocommerce: il pagamento porta al checkout del negozio' . ( '' !== $wc_err ? ' (' . $wc_err . ')' : '' ) );
	$wc_cart = WC()->cart;
	$wc_cart->calculate_totals();
	$wc_lines = array_values( $wc_cart->get_cart() );
	apse_ok( 1 === count( $wc_lines ) && $wc_q === (int) $wc_lines[0]['product_id'] && $wc_fee === (int) $wc_lines[0]['apse_cents'] && abs( (float) $wc_lines[0]['data']->get_price() - $wc_fee / 100 ) < 0.001, 'woocommerce: nel carrello c\'è il prodotto della quota con il prezzo calcolato dal server' );
	$wc_pay = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'payments' ) . " WHERE provider = 'woocommerce' ORDER BY id DESC LIMIT 1", ARRAY_A );
	apse_ok( $wc_pay && 'pending' === $wc_pay['status'] && $wc_fee === (int) $wc_pay['amount_cents'] && (int) $wc_pay['payer_person_id'] === $wc_p, 'woocommerce: il pagamento resta in attesa finché l\'ordine non è pagato' );
	$wc_order = function ( string $public, int $product, float $price ) use ( $wc_uid ) {
		$o  = wc_create_order( array( 'customer_id' => $wc_uid ) );
		$id = $o->add_product( wc_get_product( $product ), 1, array( 'subtotal' => $price, 'total' => $price ) );
		wc_add_order_item_meta( $id, '_apse_pay', $public );
		$o->calculate_totals();
		$o->save();
		return $o;
	};
	$tx_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) );
	$wc_o = $wc_order( $wc_pay['public_id'], $wc_q, $wc_fee / 100 );
	apse_ok( $wc_fee === ( \ApSemplice\WooBridge::paid_by_payment( $wc_o )[ $wc_pay['public_id'] ] ?? 0 ), 'woocommerce: l\'importo pagato per le voci APSemplice si legge dall\'ordine' );
	$wc_o->set_status( 'processing' );
	$wc_o->save();
	$wc_after = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'payments' ) . ' WHERE id = ' . (int) $wc_pay['id'], ARRAY_A );
	apse_ok( 'paid' === $wc_after['status'] && 0 === (int) $wc_after['review'] && $wc_fee === (int) $wc_after['allocated_cents'] && $tx_before + 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) ) && $people->has_membership( $wc_p, $wc_sy ), 'woocommerce: ordine pagato => incasso in prima nota e tessera rinnovata' );
	\ApSemplice\WooBridge::on_paid( $wc_o->get_id() );
	$wc_o->set_status( 'completed' );
	$wc_o->save();
	apse_ok( $tx_before + 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) ), 'woocommerce: lo stesso ordine non si registra due volte' );
	// importo diverso: i soldi entrano comunque, da controllare
	$wc_p2   = $mkb( 'Ornella', 'Negozio' );
	$wc_uid2 = (int) $people->get( $wc_p2 )['wp_user_id'];
	wp_set_current_user( $wc_uid2 );
	$wc_d2 = Plugin::payments()->dues_for( $people->get( $wc_p2 ) );
	Plugin::payments()->create_checkout( $people->get( $wc_p2 ), $wc_uid2, array_keys( $wc_d2 ), home_url( '/' ) );
	$wc_pay2 = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'payments' ) . " WHERE provider = 'woocommerce' ORDER BY id DESC LIMIT 1", ARRAY_A );
	$wc_o2   = $wc_order( $wc_pay2['public_id'], $wc_q, $wc_fee / 100 + 3 );
	$wc_o2->set_status( 'completed' );
	$wc_o2->save();
	$wc_a2 = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'payments' ) . ' WHERE id = ' . (int) $wc_pay2['id'], ARRAY_A );
	apse_ok( 'paid' === $wc_a2['status'] && 1 === (int) $wc_a2['review'] && ! $people->has_membership( $wc_p2, $wc_sy ), 'woocommerce: importo diverso da quello atteso => registrato come non abbinato e da controllare' );
	// ordine annullato
	$wc_p3   = $mkb( 'Ugo', 'Negozio' );
	$wc_uid3 = (int) $people->get( $wc_p3 )['wp_user_id'];
	wp_set_current_user( $wc_uid3 );
	$wc_d3 = Plugin::payments()->dues_for( $people->get( $wc_p3 ) );
	Plugin::payments()->create_checkout( $people->get( $wc_p3 ), $wc_uid3, array_keys( $wc_d3 ), home_url( '/' ) );
	$wc_pay3 = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'payments' ) . " WHERE provider = 'woocommerce' ORDER BY id DESC LIMIT 1", ARRAY_A );
	$wc_o3   = $wc_order( $wc_pay3['public_id'], $wc_q, $wc_fee / 100 );
	$wc_o3->set_status( 'cancelled' );
	$wc_o3->save();
	apse_ok( 'cancelled' === $wpdb->get_var( 'SELECT status FROM ' . Db::t( 'payments' ) . ' WHERE id = ' . (int) $wc_pay3['id'] ), 'woocommerce: ordine annullato => il pagamento in attesa si chiude' );
	// contrassegno: l'ordine nasce "in lavorazione" senza incasso; si registra solo a ordine completato
	$wc_p4   = $mkb( 'Cora', 'Contrassegno' );
	$wc_uid4 = (int) $people->get( $wc_p4 )['wp_user_id'];
	wp_set_current_user( $wc_uid4 );
	$wc_d4 = Plugin::payments()->dues_for( $people->get( $wc_p4 ) );
	Plugin::payments()->create_checkout( $people->get( $wc_p4 ), $wc_uid4, array_keys( $wc_d4 ), home_url( '/' ) );
	$wc_pay4 = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'payments' ) . " WHERE provider = 'woocommerce' ORDER BY id DESC LIMIT 1", ARRAY_A );
	$wc_o4   = $wc_order( $wc_pay4['public_id'], $wc_q, $wc_fee / 100 );
	$wc_o4->set_payment_method( 'cod' );
	$wc_o4->set_status( 'processing' );
	$wc_o4->save();
	apse_ok( 'pending' === $wpdb->get_var( 'SELECT status FROM ' . Db::t( 'payments' ) . ' WHERE id = ' . (int) $wc_pay4['id'] ) && ! $people->has_membership( $wc_p4, $wc_sy ), 'woocommerce: un ordine in contrassegno "in lavorazione" non è un incasso: nessuna quota registrata' );
	$wc_o4->set_status( 'completed' );
	$wc_o4->save();
	apse_ok( 'paid' === $wpdb->get_var( 'SELECT status FROM ' . Db::t( 'payments' ) . ' WHERE id = ' . (int) $wc_pay4['id'] ) && $people->has_membership( $wc_p4, $wc_sy ), 'woocommerce: a ordine completato (denaro incassato alla consegna) la quota si registra' );
	wp_set_current_user( 1 );
	apse_render( array( Admin\PaymentsPage::class, 'render' ), 'WooCommerce' );
	// un ordine senza voci APSemplice non cambia nulla
	$tx_n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) );
	$wc_x = wc_create_order();
	$wc_x->add_product( wc_get_product( $wc_q ), 1 );
	$wc_x->calculate_totals();
	$wc_x->set_status( 'completed' );
	$wc_x->save();
	apse_ok( $tx_n === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) ), 'woocommerce: gli ordini del negozio senza voci APSemplice non vengono toccati' );
	// voce senza prodotto
	wp_set_current_user( 1 );
	$wc_fake = array( 'type' => 'booking', 'person_id' => $wc_p, 'session_id' => 1, 'activity_id' => 999999, 'amount_cents' => 500, 'key' => 'b:1:' . $wc_p, 'label' => 'Prova', 'person_name' => 'X' );
	apse_ok( $wc_def === \ApSemplice\WooLinks::product_for_item( $wc_fake ), 'woocommerce: una voce senza prodotto proprio usa quello generico' );
	Settings::update( array( 'woo_default_product' => 0 ) );
	apse_ok( 0 === \ApSemplice\WooLinks::product_for_item( $wc_fake ) && false !== strpos( (string) apse_throws( function () use ( $wc_fake ) { \ApSemplice\WooBridge::checkout_url( array( $wc_fake ), 'prova' ); } ), 'non sono collegate' ), 'woocommerce: senza prodotto generico le voci non collegate non si pagano online' );
	apse_render( array( Admin\TechPage::class, 'render_integrations' ), 'Quote associative' );
	Admin\TechActions::save_woo( array() );
	apse_ok( ! \ApSemplice\WooBridge::enabled() && '' === Plugin::payments()->provider(), 'woocommerce: si spegne dalle impostazioni' );
}
wp_set_current_user( 1 );

// ---------- App installabile (PWA) e notifiche push ----------
wp_set_current_user( 1 );
$pw_accent = Settings::get( 'accent_color' );
$pw_name   = Settings::get( 'association_name' );
apse_ok( ! \ApSemplice\Pwa::enabled() && ! \ApSemplice\Push::enabled() && 404 === \ApSemplice\Pwa::response( 'apse-manifest.webmanifest' )['status'], 'app: spenta di default, il manifest non c\'è' );
apse_ok( false !== strpos( \ApSemplice\Pwa::response( 'apse-sw.js' )['body'], 'unregister' ) && '' === \ApSemplice\Frontend\Views::section_app( $people->get( $rg_p ) ), 'app: spenta, il service worker si toglie da solo e l\'area non mostra nulla' );
Settings::update( array( 'pwa_enabled' => 1, 'push_enabled' => 1, 'association_name' => 'Assoc App', 'accent_color' => '#336699' ) );
$pw_man = json_decode( \ApSemplice\Pwa::response( 'apse-manifest.webmanifest' )['body'], true );
apse_ok( 200 === \ApSemplice\Pwa::response( 'apse-manifest.webmanifest' )['status'] && 'Assoc App' === $pw_man['name'] && 'standalone' === $pw_man['display'] && '#336699' === $pw_man['theme_color'] && false !== strpos( $pw_man['start_url'], 'source=pwa' ) && ! empty( $pw_man['icons'] ) && 'image/png' === $pw_man['icons'][0]['type'] && $pw_man['scope'] === home_url( '/' ), 'app: il manifest ha nome, colore, indirizzo di partenza e icone' );
apse_ok( 'apse-sw.js' === \ApSemplice\Pwa::route_of( (string) wp_parse_url( home_url( '/apse-sw.js' ), PHP_URL_PATH ) ) && 'apse-manifest.webmanifest' === \ApSemplice\Pwa::route_of( (string) wp_parse_url( home_url( '/apse-manifest.webmanifest?x=1' ), PHP_URL_PATH ) ) && null === \ApSemplice\Pwa::route_of( '/qualcosa-altro' ) && null === \ApSemplice\Pwa::route_of( '/apse-sw.js.php' ), 'app: gli indirizzi dell\'app si riconoscono (e solo quelli)' );
$pw_sw = \ApSemplice\Pwa::response( 'apse-sw.js' );
apse_ok( false !== strpos( $pw_sw['body'], "addEventListener('push'" ) && false !== strpos( $pw_sw['body'], "addEventListener('notificationclick'" ) && false !== strpos( $pw_sw['body'], 'apse-offline.html' ) && false !== strpos( $pw_sw['body'], 'const NAME = "Assoc App"' ) && false === strpos( $pw_sw['body'], '%VER%' ) && false === strpos( $pw_sw['body'], '%AREA%' ) && 0 === strpos( $pw_sw['type'], 'application/javascript' ), 'app: il service worker gestisce offline e notifiche' );
apse_ok( 0 === strpos( \ApSemplice\Pwa::response( 'apse-icon-192.png' )['body'], "\x89PNG" ) && false !== strpos( \ApSemplice\Pwa::response( 'apse-offline.html' )['body'], 'Sei offline' ), 'app: icona generata e pagina offline' );
ob_start();
\ApSemplice\Pwa::print_head();
$pw_head = ob_get_clean();
\ApSemplice\Pwa::enqueue();
apse_ok( false !== strpos( $pw_head, 'rel="manifest"' ) && false !== strpos( $pw_head, 'theme-color' ) && false !== strpos( $pw_head, 'apple-touch-icon' ) && wp_script_is( 'apse-pwa', 'enqueued' ), 'app: le pagine del sito collegano manifest, colore e script' );
$pw_card = \ApSemplice\Frontend\Views::section_app( $people->get( $rg_p ) );
apse_ok( false !== strpos( $pw_card, 'data-apse-install' ) && false !== strpos( $pw_card, 'data-apse-push-on' ), 'app: nell\'area soci compaiono installazione e notifiche' );
Settings::update( array( 'push_enabled' => 0 ) );
apse_ok( false === strpos( \ApSemplice\Frontend\Views::section_app( $people->get( $rg_p ) ), 'data-apse-push-on' ) && ! \ApSemplice\Push::enabled(), 'app: senza notifiche resta solo l\'installazione' );
Settings::update( array( 'push_enabled' => 1 ) );
// notifiche
if ( ! \ApSemplice\WebPush::supported() ) {
	apse_ok( ! \ApSemplice\Push::enabled(), 'notifiche: senza openssl non si accendono' );
} else {
	$pw_v1 = \ApSemplice\Push::public_key();
	apse_ok( 87 === strlen( $pw_v1 ) && $pw_v1 === \ApSemplice\Push::public_key() && false === strpos( (string) wp_json_encode( get_option( \ApSemplice\Push::OPT_VAPID ) ), 'BEGIN' ), 'notifiche: chiavi del sito create una volta e la privata è cifrata' );
	$pw_uid = (int) $people->get( $at_a )['wp_user_id'];
	$pw_mk  = function ( string $endpoint ) {
		$k = \ApSemplice\WebPush::new_keypair();
		return array( 'endpoint' => $endpoint, 'keys' => array( 'p256dh' => \ApSemplice\WebPush::b64u( $k['public'] ), 'auth' => \ApSemplice\WebPush::b64u( random_bytes( 16 ) ) ) );
	};
	$pw_ep1 = 'https://fcm.googleapis.com/fcm/send/prova-1';
	$pw_s1  = \ApSemplice\Push::subscribe( $pw_uid, $pw_mk( $pw_ep1 ), 'Browser di prova' );
	apse_ok( $pw_s1 > 0 && \ApSemplice\Push::subscribed( $pw_uid ) && $pw_s1 === \ApSemplice\Push::subscribe( $pw_uid, $pw_mk( $pw_ep1 ) ) && 1 === \ApSemplice\Push::count(), 'notifiche: un dispositivo si collega una sola volta' );
	apse_ok( null !== apse_throws( function () use ( $pw_uid, $pw_mk ) { \ApSemplice\Push::subscribe( $pw_uid, $pw_mk( 'https://evil.example.com/x' ) ); } ) && null !== apse_throws( function () use ( $pw_uid, $pw_mk ) { \ApSemplice\Push::subscribe( $pw_uid, $pw_mk( 'http://fcm.googleapis.com/x' ) ); } ) && null !== apse_throws( function () use ( $pw_uid ) { \ApSemplice\Push::subscribe( $pw_uid, array( 'endpoint' => 'https://fcm.googleapis.com/x', 'keys' => array( 'p256dh' => 'corta', 'auth' => 'x' ) ) ); } ), 'notifiche: solo servizi di push noti e chiavi valide' );
	$pw_cap = array();
	\ApSemplice\WebPush::$http = function ( $url, $args ) use ( &$pw_cap ) {
		$pw_cap[] = array( $url, $args );
		return array( 'code' => 201 );
	};
	$pw_n = \ApSemplice\Push::notify_users( array( $pw_uid ), 'Titolo di prova', 'Un testo abbastanza lungo per la notifica' );
	apse_ok( 1 === $pw_n && 1 === count( $pw_cap ) && $pw_ep1 === $pw_cap[0][0] && 0 === strpos( $pw_cap[0][1]['headers']['Authorization'], 'vapid t=' ) && false !== strpos( $pw_cap[0][1]['headers']['Authorization'], ', k=' . $pw_v1 ) && 'aes128gcm' === $pw_cap[0][1]['headers']['Content-Encoding'] && strlen( $pw_cap[0][1]['body'] ) > 100 && ! empty( $wpdb->get_var( 'SELECT last_ok_at FROM ' . Db::t( 'push_subs' ) . ' WHERE id = ' . $pw_s1 ) ), 'notifiche: il messaggio parte cifrato e firmato verso il servizio di push' );
	// la comunicazione a un gruppo arriva anche come notifica
	$pw_cap = array();
	$wpdb->query( 'DELETE FROM ' . Db::t( 'push_subs' ) . ' WHERE user_id <> ' . $pw_uid );
	\ApSemplice\Broadcasts::create( 'Avviso con notifica', 'Ciao {nome}, questo è un avviso.', 'activity', $at_c );
	$pw_hit = 0;
	foreach ( $pw_cap as $c ) {
		if ( $c[0] === $pw_ep1 ) {
			$pw_hit++;
		}
	}
	apse_ok( 1 === $pw_hit, 'notifiche: le comunicazioni a gruppi arrivano anche sul telefono di chi le ha attivate' );
	// dispositivo che ha tolto le notifiche, servizio in errore
	\ApSemplice\WebPush::$http = function () { return array( 'code' => 410 ); };
	\ApSemplice\Push::notify_users( array( $pw_uid ), 'x', 'y' );
	apse_ok( ! \ApSemplice\Push::subscribed( $pw_uid ), 'notifiche: un dispositivo che non esiste più viene tolto' );
	\ApSemplice\Push::subscribe( $pw_uid, $pw_mk( $pw_ep1 ) );
	\ApSemplice\WebPush::$http = function () { return array( 'code' => 500 ); };
	for ( $i = 0; $i < 5; $i++ ) {
		\ApSemplice\Push::notify_users( array( $pw_uid ), 'x', 'y' );
	}
	apse_ok( ! \ApSemplice\Push::subscribed( $pw_uid ), 'notifiche: dopo troppi errori di seguito il dispositivo viene tolto' );
	// limite di dispositivi per utente
	for ( $i = 0; $i < \ApSemplice\Push::MAX_PER_USER; $i++ ) {
		\ApSemplice\Push::subscribe( $pw_uid, $pw_mk( 'https://fcm.googleapis.com/fcm/send/lim-' . $i ) );
	}
	apse_ok( null !== apse_throws( function () use ( $pw_uid, $pw_mk ) { \ApSemplice\Push::subscribe( $pw_uid, $pw_mk( 'https://fcm.googleapis.com/fcm/send/oltre' ) ); } ), 'notifiche: un numero massimo di dispositivi per utente' );
	\ApSemplice\Push::unsubscribe( $pw_uid, 'https://fcm.googleapis.com/fcm/send/lim-0' );
	apse_ok( \ApSemplice\Push::MAX_PER_USER - 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'push_subs' ) . ' WHERE user_id = ' . $pw_uid ), 'notifiche: un dispositivo si scollega' );
	// notifica di prova dall'amministrazione
	\ApSemplice\Push::subscribe( 1, $pw_mk( 'https://updates.push.services.mozilla.com/wpush/v2/prova-admin' ) );
	$pw_cap = array();
	\ApSemplice\WebPush::$http = function ( $url, $args ) use ( &$pw_cap ) {
		$pw_cap[] = $url;
		return array( 'code' => 201 );
	};
	$pw_t = Admin\TechActions::push_test( array() );
	apse_ok( false !== strpos( $pw_t[1], 'inviata a 1 dispositivo' ) && array( 'https://updates.push.services.mozilla.com/wpush/v2/prova-admin' ) === $pw_cap, 'notifiche: la notifica di prova va ai dispositivi dell\'utente collegato' );
	\ApSemplice\Push::reset_keys();
	apse_ok( 0 === \ApSemplice\Push::count() && 87 === strlen( \ApSemplice\Push::public_key() ) && $pw_v1 !== \ApSemplice\Push::public_key(), 'notifiche: rigenerando le chiavi i dispositivi vanno ricollegati' );
	\ApSemplice\WebPush::$http = null;
}
Admin\TechActions::save_app( array( 'pwa_enabled' => '1', 'push_enabled' => '1', 'pwa_name' => 'Mia App', 'pwa_short_name' => 'MiaApp' ) );
apse_ok( 'Mia App' === \ApSemplice\Pwa::app_name() && 'MiaApp' === \ApSemplice\Pwa::short_name(), 'app: nome e nome breve si impostano da qui' );
apse_render( array( Admin\AppPage::class, 'render' ), 'App installabile (PWA)' );
apse_ok( in_array( 'apse-app', Admin\Admin::ADMIN_ONLY, true ) && false !== strpos( Admin\Admin::tabs( 'apse-roles' ), 'Comunicazioni' ) && isset( Admin\SettingsPage::FEATURES['pwa_enabled'], Admin\SettingsPage::FEATURES['push_enabled'] ) && isset( \ApSemplice\Frontend\Shortcodes::VIEWS['app'] ) && shortcode_exists( 'apsemplice_app' ), 'app: scheda riservata agli amministratori, interruttori e shortcode' );
Admin\TechActions::save_app( array() );
Settings::update( array( 'pwa_name' => '', 'pwa_short_name' => '', 'accent_color' => $pw_accent, 'association_name' => $pw_name, 'push_enabled' => 0, 'pwa_enabled' => 0 ) );
$wpdb->query( 'DELETE FROM ' . Db::t( 'push_subs' ) );

// ---------- Correzioni dal controllo di sicurezza ----------
\ApSemplice\Languages::save_pack( 'de', 'Deutsch', array( 'Salva' => '<b onclick="x()">Speichern</b>', 'Quota associativa' => 'Beitrag "<i>"' ) );
Settings::update( array( 'language' => 'de' ) );
\ApSemplice\Texts::flush();
$sx_html = \ApSemplice\Texts::html( '<p>Salva</p><a title="Salva">Quota associativa</a>' );
apse_ok( false === strpos( $sx_html, '<b' ) && false === strpos( $sx_html, '<i>' ) && false !== strpos( $sx_html, '&lt;b onclick' ) && false !== strpos( $sx_html, 'title="&lt;b onclick' ) && false === strpos( $sx_html, 'title="<' ), 'sicurezza: una traduzione non può inserire markup nelle pagine (né rompere gli attributi)' );
Settings::update( array( 'language' => 'it' ) );
\ApSemplice\Languages::delete_pack( 'de' );
\ApSemplice\Texts::flush();
$sx_b = \ApSemplice\Broadcasts::create( "Oggetto\r\nBcc: spia@example.com", 'Testo.', 'activity', $at_c );
apse_ok( 'Oggetto Bcc: spia@example.com' === \ApSemplice\Broadcasts::get( $sx_b )['subject'] && false === strpos( \ApSemplice\Broadcasts::get( $sx_b )['subject'], "\n" ), 'sicurezza: l\'oggetto di una comunicazione è sempre su una riga' );
$sx_p   = $mkb( 'Ada', 'Daanonimizzare' );
$sx_uid = (int) $people->get( $sx_p )['wp_user_id'];
\ApSemplice\Insurance::add( $sx_p, 'Compagnia Segreta', 'POL-999', $in_day( -5 ), $in_day( 100 ), 'nota riservata' );
$wpdb->insert( Db::t( 'broadcast_rcpt' ), array( 'broadcast_id' => $sx_b, 'person_id' => $sx_p, 'email' => 'ada.daanonimizzare@example.com', 'name' => 'Ada Daanonimizzare', 'status' => 'sent' ) );
$sx_c = $mkb( 'Carlo', 'Familiare' );
$people->set_level_and_family( $sx_c, null, $sx_p );
if ( \ApSemplice\WebPush::supported() && $sx_uid ) {
	$sx_k = \ApSemplice\WebPush::new_keypair();
	\ApSemplice\Push::subscribe( $sx_uid, array( 'endpoint' => 'https://fcm.googleapis.com/fcm/send/anon-1', 'keys' => array( 'p256dh' => \ApSemplice\WebPush::b64u( $sx_k['public'] ), 'auth' => \ApSemplice\WebPush::b64u( random_bytes( 16 ) ) ) ) );
}
$sx_export = \ApSemplice\Privacy::export( $sx_p );
apse_ok( 'POL-999' === ( $sx_export['assicurazioni'][0]['numero'] ?? '' ) && array_key_exists( 'presenze', $sx_export ), 'privacy: l\'esportazione dei dati comprende polizze e presenze' );
// copie del nome in altre tabelle: pagamenti online, fondi, avvisi, dati degli import
$wpdb->insert( Db::t( 'payments' ), array( 'public_id' => 'anon-test-' . $sx_p, 'provider' => 'stripe', 'status' => 'paid', 'amount_cents' => 1000, 'payer_person_id' => $sx_p, 'payer_user_id' => $sx_uid, 'items' => wp_json_encode( array( array( 'type' => 'membership', 'person_id' => $sx_p, 'person_name' => 'Ada Daanonimizzare', 'amount_cents' => 1000 ) ) ), 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
$sx_fund = \ApSemplice\Plugin::funds()->create( 'Rimborso Ada Daanonimizzare — gita', 0, $sx_p );
$wpdb->insert( Db::t( 'notices' ), array( 'activity_id' => $at_c, 'author_user_id' => $sx_uid, 'author_name' => 'Ada Daanonimizzare', 'subject' => 'Prova', 'body' => 'Prova', 'created_at' => Db::now() ) );
$wpdb->insert( Db::t( 'import_batches' ), array( 'created_at' => Db::now(), 'source' => 'prova-anonimizzazione', 'summary' => '{}', 'data' => wp_json_encode( array( 'people_updated' => array( $sx_p => array( 'first_name' => 'Ada', 'email' => 'ada.vecchia@example.com' ) ) ) ) ) );
\ApSemplice\Privacy::anonymize( $sx_p );
apse_ok(
	false === strpos( (string) $wpdb->get_var( 'SELECT items FROM ' . Db::t( 'payments' ) . " WHERE public_id = 'anon-test-$sx_p'" ), 'Daanonimizzare' )
	&& false === strpos( (string) $wpdb->get_var( 'SELECT name FROM ' . Db::t( 'funds' ) . ' WHERE id = ' . (int) $sx_fund ), 'Daanonimizzare' )
	&& 'Persona anonimizzata' === (string) $wpdb->get_var( 'SELECT author_name FROM ' . Db::t( 'notices' ) . ' WHERE author_user_id = ' . $sx_uid . ' LIMIT 1' )
	&& false === strpos( (string) $wpdb->get_var( 'SELECT data FROM ' . Db::t( 'import_batches' ) . " WHERE source = 'prova-anonimizzazione'" ), 'ada.vecchia' ),
	'privacy: l\'anonimizzazione toglie il nome anche da pagamenti online, fondi, avvisi e dati degli import'
);
$wpdb->delete( Db::t( 'payments' ), array( 'public_id' => 'anon-test-' . $sx_p ) );
$wpdb->delete( Db::t( 'funds' ), array( 'id' => (int) $sx_fund ) );
$wpdb->delete( Db::t( 'notices' ), array( 'author_user_id' => $sx_uid ) );
$wpdb->delete( Db::t( 'import_batches' ), array( 'source' => 'prova-anonimizzazione' ) );
apse_ok( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'insurance' ) . ' WHERE person_id = ' . $sx_p ) && '' === (string) $wpdb->get_var( 'SELECT email FROM ' . Db::t( 'broadcast_rcpt' ) . ' WHERE person_id = ' . $sx_p . ' LIMIT 1' ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'push_subs' ) . ' WHERE user_id = ' . $sx_uid ) && null === $people->get( $sx_c )['family_head_id'], 'privacy: l\'anonimizzazione toglie polizze, destinatari delle comunicazioni, dispositivi e legami familiari' );

// ---------- Copia di sicurezza e ripristino (in fondo: tocca tutte le tabelle) ----------

wp_set_current_user( 1 );
delete_option( \ApSemplice\Backup::OPT_LAST );
apse_render( array( Admin\DashboardPage::class, 'render' ), 'Non hai ancora scaricato una copia di sicurezza' );
$bk_tables = \ApSemplice\Backup::tables();
apse_ok( in_array( 'apse_people', $bk_tables, true ) && in_array( 'apse_transactions', $bk_tables, true ) && in_array( 'apse_waitlist', $bk_tables, true ) && in_array( 'apse_broadcasts', $bk_tables, true ), 'copia: elenco delle tabelle del plugin' );
Attachments::prepare_dir();
$bk_file = Attachments::dir() . '/' . str_repeat( 'a', 32 );
file_put_contents( $bk_file, 'contenuto allegato' );
Settings::update( array( 'association_name' => 'Associazione di prova' ) );
$bk_zip = \ApSemplice\Backup::export( true );
$za     = new ZipArchive();
$za->open( $bk_zip );
$man    = json_decode( (string) $za->getFromName( 'manifest.json' ), true );
$ppl    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) );
$txn    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) );
$p_lines = array_filter( explode( "\n", (string) $za->getFromName( 'tabelle/apse_people.jsonl' ) ) );
$settings_json = json_decode( (string) $za->getFromName( 'impostazioni.json' ), true );
$has_att = false !== $za->locateName( 'allegati/' . str_repeat( 'a', 32 ) );
$za->close();
apse_ok( 'apsemplice' === $man['plugin'] && (int) $man['tables']['apse_people'] === $ppl && count( $p_lines ) === $ppl && $has_att && 'Associazione di prova' === $settings_json['settings']['association_name'] && ! isset( $settings_json['settings']['stripe_secret_key'] ) && \ApSemplice\Backup::last() > 0, 'copia: il file contiene tabelle, impostazioni (senza chiavi segrete), allegati e indice' );
apse_ok( $man === \ApSemplice\Backup::inspect( $bk_zip ), 'copia: il file si riconosce come copia valida' );
// file non validi
$bad1 = wp_tempnam( 'apse-bad' );
file_put_contents( $bad1, 'non è uno zip' );
$bad2 = wp_tempnam( 'apse-bad' );
$zb = new ZipArchive();
$zb->open( $bad2, ZipArchive::OVERWRITE );
$zb->addFromString( 'manifest.json', wp_json_encode( array( 'plugin' => 'altro', 'tables' => array( 'x' => 1 ) ) ) );
$zb->close();
$bad3 = wp_tempnam( 'apse-bad' );
$zc = new ZipArchive();
$zc->open( $bad3, ZipArchive::OVERWRITE );
$zc->addFromString( 'manifest.json', wp_json_encode( array( 'plugin' => 'apsemplice', 'db_version' => '9999', 'tables' => array( 'apse_people' => 1 ) ) ) );
$zc->close();
$bad4 = wp_tempnam( 'apse-bad' );
$zd = new ZipArchive();
$zd->open( $bad4, ZipArchive::OVERWRITE );
$zd->addFromString( 'manifest.json', wp_json_encode( array( 'plugin' => 'apsemplice', 'db_version' => '1', 'tables' => array( 'apse_tabella_finta' => 1 ) ) ) );
$zd->close();
$rej = 0;
foreach ( array( $bad1, $bad2, $bad3, $bad4 ) as $bf ) {
	$rej += null !== apse_throws( function () use ( $bf ) { \ApSemplice\Backup::restore( $bf ); } ) ? 1 : 0;
	@unlink( $bf );
}
apse_ok( 4 === $rej, 'ripristino: file non validi, di altri plugin, più recenti o con tabelle sconosciute vengono rifiutati' );
// modifiche dopo la copia, poi ripristino
$victim = (int) $wpdb->get_var( 'SELECT id FROM ' . Db::t( 'people' ) . " WHERE last_name = 'Corsista' ORDER BY id LIMIT 1" );
$victim_row = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE id = $victim", ARRAY_A );
$wpdb->delete( Db::t( 'people' ), array( 'id' => $victim ) );
$wpdb->insert( Db::t( 'transactions' ), array( 'tx_date' => $today, 'type' => 'income', 'amount_cents' => 777, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'category_id' => (int) $ledger->category_id_of_kind( 'other_income' ), 'description' => 'riga di prova da cancellare', 'created_at' => Db::now() ) );
Settings::update( array( 'association_name' => 'Nome cambiato dopo la copia' ) );
\ApSemplice\Texts::save_overrides( array( 'Frase di prova' => 'Frase cambiata' ) );
unlink( $bk_file );
Settings::update( array( 'stripe_secret_key' => 'sk_test_0123456789abcdef' ) );
$res = \ApSemplice\Backup::restore( $bk_zip );
$back_row = $wpdb->get_row( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE id = $victim", ARRAY_A );
apse_ok( $ppl === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) ) && $txn === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) ) && $back_row === $victim_row && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . " WHERE description = 'riga di prova da cancellare'" ), 'ripristino: i dati tornano esattamente come nella copia (anche i record cancellati e identici campo per campo)' );
apse_ok( 'Associazione di prova' === Settings::get( 'association_name' ) && array() === \ApSemplice\Texts::overrides() && Settings::has_secret( 'stripe_secret_key' ), 'ripristino: impostazioni e testi tornano come nella copia; le chiavi segrete del sito restano' );
apse_ok( is_file( $bk_file ) && 'contenuto allegato' === file_get_contents( $bk_file ) && $res['files'] >= 1 && $res['rows'] > 0 && $res['tables'] === count( $man['tables'] ), 'ripristino: gli allegati tornano al loro posto' );
$saved = \ApSemplice\Backup::saved();
apse_ok( count( $saved ) >= 1 && 0 === strpos( $saved[0]['name'], 'prima-del-ripristino-' ) && $res['safety'] === $saved[0]['name'], 'ripristino: prima viene salvata una copia dello stato precedente' );
apse_ok( 1 === preg_match( '/^prima-del-ripristino-\d{8}-\d{6}-[0-9a-f]{16}\.zip$/', $saved[0]['name'] ), 'sicurezza: il nome della copia salvata non è indovinabile' );
// le copie salvate sul sito scadono da sole
$bk_old = \ApSemplice\Backup::dir() . '/prima-del-ripristino-20200102-000000-0123456789abcdef.zip';
file_put_contents( $bk_old, 'x' );
touch( $bk_old, time() - 25 * HOUR_IN_SECONDS );
$bk_new = \ApSemplice\Backup::dir() . '/prima-del-ripristino-20200103-000000-fedcba9876543210.zip';
file_put_contents( $bk_new, 'x' );
apse_ok( 1 <= \ApSemplice\Backup::purge_old() && ! file_exists( $bk_old ) && file_exists( $bk_new ), 'copie di sicurezza: dopo 24 ore si cancellano da sole, quelle recenti restano' );
wp_delete_file( $bk_new );
$bk_legacy = \ApSemplice\Backup::dir() . '/prima-del-ripristino-20200101-000000.zip';
copy( $bk_zip, $bk_legacy );
$bk_names = array_column( \ApSemplice\Backup::saved(), 'name' );
apse_ok( ! file_exists( $bk_legacy ) && ! in_array( 'prima-del-ripristino-20200101-000000.zip', $bk_names, true ) && 1 === count( preg_grep( '/^prima-del-ripristino-20200101-000000-[0-9a-f]{16}\.zip$/', $bk_names ) ), 'sicurezza: le copie con il vecchio nome prevedibile vengono rinominate' );
$zs = new ZipArchive();
$zs->open( \ApSemplice\Backup::dir() . '/' . $saved[0]['name'] );
$safety_man = json_decode( (string) $zs->getFromName( 'manifest.json' ), true );
$zs->close();
apse_ok( 'apsemplice' === $safety_man['plugin'], 'ripristino: la copia di sicurezza è a sua volta un file valido' );
Settings::clear_secret( 'stripe_secret_key' );
// tutto o niente: una copia con una riga rovinata non cambia nulla
$broken = wp_tempnam( 'apse-bad' );
$zbk = new ZipArchive();
$zbk->open( $broken, ZipArchive::OVERWRITE );
$zbk->addFromString( 'manifest.json', wp_json_encode( array( 'plugin' => 'apsemplice', 'db_version' => (string) \ApSemplice\Install::DB_VERSION, 'tables' => array( 'apse_people' => 1 ) ) ) );
$zbk->addFromString( 'tabelle/apse_people.jsonl', "{ non json\n" );
$zbk->close();
$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) );
$err = '';
try {
	\ApSemplice\Backup::restore( $broken );
} catch ( \Throwable $e ) {
	$err = $e->getMessage();
}
unlink( $broken );
apse_ok( false !== strpos( $err, 'Nessun dato è stato cambiato' ) && $before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) ), 'ripristino: se qualcosa non va non cambia nulla' );
unlink( $bk_zip );
if ( is_file( $bk_file ) ) {
	unlink( $bk_file );
}
apse_ok( false !== strpos( \ApSemplice\Backup::download_url( true ), 'action=apse_backup' ) && ( \ApSemplice\Plugin::load_for_action( 'apse_backup' ) && has_action( 'admin_post_apse_backup' ) ) && Admin\Actions::required_cap( 'apse_backup_restore' ) === Plugin::CAP, 'copia: indirizzo di scarico con controllo e ripristino riservato agli amministratori' );
apse_render( array( Admin\BackupPage::class, 'render' ), 'Ripristino' );

// ---------- Azzeramento dei dati (ultimo: cancella tutto) ----------
// un amministratore con una password nota (wp_set_current_user non ricarica l'utente già in uso: se ne crea uno nuovo)
$rs_admin = wp_insert_user( array( 'user_login' => 'azzeratore', 'user_pass' => 'Azzera-Pass-123', 'user_email' => 'azzeratore@example.com', 'role' => 'administrator' ) );
wp_set_current_user( (int) $rs_admin );
$rs_prev = \ApSemplice\Reset::preview();
apse_ok( $rs_prev['people'] > 0 && $rs_prev['transactions'] > 0 && $rs_prev['activities'] > 0 && $rs_prev['users'] > 0, 'azzeramento: l\'anteprima conta ciò che verrebbe cancellato' );
$_GET = array();
ob_start();
Admin\ResetPage::render();
$rs_p1 = (string) ob_get_clean();
$_GET = array( 'step' => '2', 'mode' => 'data', 'users' => '1' );
ob_start();
Admin\ResetPage::render();
$rs_p2 = (string) ob_get_clean();
$_GET = array();
apse_ok( false !== strpos( $rs_p1, 'Operazione definitiva' ) && false !== strpos( $rs_p1, 'Ripristino di fabbrica' ) && false !== strpos( $rs_p1, 'Solo i dati' ) && false === strpos( $rs_p1, 'name="password"' ), 'azzeramento: primo passaggio con le due scelte e nessun campo di conferma' );
apse_ok( false !== strpos( $rs_p2, 'AZZERA TUTTO' ) && false !== strpos( $rs_p2, 'name="password"' ) && false !== strpos( $rs_p2, 'name="phrase"' ) && false !== strpos( $rs_p2, 'name="confirm"' ) && false !== strpos( $rs_p2, 'tutta la prima nota' ) && false !== strpos( $rs_p2, 'copia completa' ), 'azzeramento: secondo passaggio con il messaggio chiaro, la frase, la password e la spunta' );
apse_ok( in_array( 'apse-reset', Admin\Admin::ADMIN_ONLY, true ) && isset( Admin\TechActions::ACTIONS['apse_reset_all'] ) && 'apse-settings' === Admin\Admin::menu_item_of( 'apse-reset' ), 'azzeramento: riservato agli amministratori, tra le impostazioni di sistema' );
// avvisi chiudibili: si chiudono per qualche giorno, poi tornano
$dm_prev = get_current_user_id();
wp_set_current_user( 1 );
delete_user_meta( 1, Admin\Dismiss::META );
$dm_html = Admin\Dismiss::html( 'prova', 'warning', 'Testo <a href="#">link</a>', 7 );
apse_ok( false !== strpos( $dm_html, 'is-dismissible' ) && false !== strpos( $dm_html, 'data-apse-key="prova"' ) && false !== strpos( $dm_html, 'data-apse-days="7"' ), 'avvisi chiudibili: l\'avviso ha la X e ricorda chi è' );
Admin\Dismiss::dismiss( 'prova', 7 );
apse_ok( '' === Admin\Dismiss::html( 'prova', 'warning', 'Testo', 7 ) && '' !== Admin\Dismiss::html( 'altro', 'warning', 'Testo', 7 ), 'avvisi chiudibili: chiuso non ricompare, gli altri sì' );
$dm_meta = (array) get_user_meta( 1, Admin\Dismiss::META, true );
apse_ok( isset( $dm_meta['prova'] ) && $dm_meta['prova'] > time() + 6 * DAY_IN_SECONDS && $dm_meta['prova'] <= time() + 7 * DAY_IN_SECONDS + 5, 'avvisi chiudibili: la chiusura dura i giorni indicati' );
update_user_meta( 1, Admin\Dismiss::META, array( 'prova' => time() - 10 ) );
apse_ok( '' !== Admin\Dismiss::html( 'prova', 'warning', 'Testo', 7 ), 'avvisi chiudibili: scaduta la chiusura l\'avviso ricompare' );
delete_user_meta( 1, Admin\Dismiss::META );
wp_set_current_user( $dm_prev );
// aspetto: logo e colori, di default quelli del sito, personalizzabili
$lk_save = new ReflectionMethod( Admin\Actions::class, 'save_look' );
$lk_save->setAccessible( true );
Settings::update( array( 'accent_color' => '', 'secondary_color' => '', 'logo_id' => 0 ) );
apse_ok( \ApSemplice\Frontend\Assets::accent( '#111111' ) === ( '' !== \ApSemplice\Frontend\Assets::theme_accent() ? \ApSemplice\Frontend\Assets::theme_accent() : '#111111' ) && \ApSemplice\Frontend\Assets::secondary( '#222222' ) === ( '' !== \ApSemplice\Frontend\Assets::theme_color( 'secondary' ) ? \ApSemplice\Frontend\Assets::theme_color( 'secondary' ) : '#222222' ), 'aspetto: senza scelte si usano i colori del sito' );
$lk_att = wp_insert_attachment( array( 'post_title' => 'logo-test', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), 'logo-test.png' );
$lk_save->invoke( null, array( 'logo_id' => (string) $lk_att, 'primary_custom' => '1', 'accent_color' => '#336699', 'secondary_custom' => '1', 'secondary_color' => '#cc6600' ) );
apse_ok( '#336699' === Settings::get( 'accent_color' ) && '#cc6600' === Settings::get( 'secondary_color' ) && $lk_att === (int) Settings::get( 'logo_id' ), 'aspetto: colori e logo personalizzati si salvano' );
$lk_css = \ApSemplice\Frontend\Assets::inline_css();
apse_ok( false !== strpos( $lk_css, '--apsf-accent:#336699' ) && false !== strpos( $lk_css, '--apsf-accent-2:#cc6600' ), 'aspetto: i colori scelti diventano lo stile del front-end' );
$lk_save->invoke( null, array( 'logo_id' => '0', 'accent_color' => '#336699' ) );
apse_ok( '' === Settings::get( 'accent_color' ) && '' === Settings::get( 'secondary_color' ) && 0 === (int) Settings::get( 'logo_id' ), 'aspetto: senza la spunta si torna ai colori e al logo del sito' );
apse_ok( null !== apse_throws( function () use ( $lk_save ) { $lk_save->invoke( null, array( 'primary_custom' => '1', 'accent_color' => 'rosso' ) ); } ) && null !== apse_throws( function () use ( $lk_save ) { $lk_save->invoke( null, array( 'logo_id' => '999999' ) ); } ), 'aspetto: colore non valido e logo che non è un\'immagine sono rifiutati' );
apse_render( array( Admin\AppearancePage::class, 'render' ), 'Colore principale' );
wp_delete_attachment( $lk_att, true );
// donazioni con PayPal
apse_ok( '' === \ApSemplice\Donations::clean_account( 'non una mail' ) && 'abc@example.com' === \ApSemplice\Donations::clean_account( ' ABC@Example.com ' ) && 'AB12CD34EF56G' === \ApSemplice\Donations::clean_account( 'AB12CD34EF56G' ), 'donazioni: conto PayPal valido solo se email o ID commerciante' );
apse_ok( '5;7,50;10' === \ApSemplice\Donations::clean_amounts( '10; 5 7,50;0;abc;10' ) && '' === \ApSemplice\Donations::clean_amounts( 'niente' ), 'donazioni: gli importi si ripuliscono, ordinano e senza doppioni' );
Settings::update( array( 'donate_enabled' => 1, 'donate_paypal' => '' ) );
apse_ok( '' === \ApSemplice\Frontend\Views::donate(), 'donazioni: senza conto PayPal il modulo non compare' );
$dn_save = new ReflectionMethod( Admin\Actions::class, 'save_donate' );
$dn_save->setAccessible( true );
apse_ok( null !== apse_throws( function () use ( $dn_save ) { $dn_save->invoke( null, array( 'donate_enabled' => '1', 'donate_paypal' => 'xx' ) ); } ), 'donazioni: non si accendono senza un conto valido' );
$dn_save->invoke( null, array( 'donate_enabled' => '1', 'donate_paypal' => 'donazioni@example.org', 'donate_amounts' => '5;15', 'donate_purpose' => 'Per il tetto' ) );
$dn_html = do_shortcode( '[apsemplice_donazioni]' );
apse_ok( false !== strpos( $dn_html, 'https://www.paypal.com/donate' ) && false !== strpos( $dn_html, 'value="donazioni@example.org"' ) && false !== strpos( $dn_html, 'value="15.00"' ) && false !== strpos( $dn_html, 'Per il tetto' ) && false !== strpos( $dn_html, 'name="currency_code" value="EUR"' ), 'donazioni: il modulo porta a PayPal con conto, causale e importi' );
apse_render( array( Admin\DonatePage::class, 'render' ), 'Conto PayPal' );
$dn_save->invoke( null, array( 'donate_paypal' => 'donazioni@example.org' ) );
apse_ok( '' === do_shortcode( '[apsemplice_donazioni]' ), 'donazioni: spente il modulo sparisce' );
// tolleranza per il pagamento: il posto non versato si libera, chi ha pagato resta
$hd_a = $acts->create( array( 'name' => 'Cena con tolleranza', 'social_year' => $ev_sy, 'kind' => 'event', 'fee_cents' => 1000, 'hold_hours' => 24, 'session' => array( 'session_date' => $today, 'capacity' => 2 ) ) );
$hd_s = (int) $acts->sessions( $hd_a )[0]['id'];
apse_ok( 24 === (int) $acts->get( $hd_a )['hold_hours'], 'tolleranza: le ore si salvano con l\'evento' );
$hd_p1 = $ev_mk( 'Ugo', 'Tardivo' );
$hd_p2 = $ev_mk( 'Lia', 'Puntuale' );
$hd_p3 = $ev_mk( 'Gino', 'InAttesa' );
$acts->book( $hd_s, $hd_p1 );
$acts->book( $hd_s, $hd_p2 );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::t( 'bookings' ) . ' SET created_at = %s WHERE session_id = %d', gmdate( 'Y-m-d H:i:s', time() - 48 * HOUR_IN_SECONDS ), $hd_s ) );
$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $hd_p2, 'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 1000, 'activity_id' => $hd_a, 'session_id' => $hd_s ) ) ) );
apse_ok( 1 === \ApSemplice\Holds::release_expired(), 'tolleranza: scaduto il termine si libera solo il posto non versato' );
apse_ok( 'booked' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Db::t( 'bookings' ) . ' WHERE session_id = %d AND person_id = %d', $hd_s, $hd_p2 ) ) && 'booked' !== $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Db::t( 'bookings' ) . ' WHERE session_id = %d AND person_id = %d', $hd_s, $hd_p1 ) ), 'tolleranza: chi ha già pagato mantiene il posto' );
$wpdb->update( Db::t( 'activities' ), array( 'hold_hours' => 0 ), array( 'id' => $hd_a ) );
$acts->book( $hd_s, $hd_p3 );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::t( 'bookings' ) . ' SET created_at = %s WHERE session_id = %d', gmdate( 'Y-m-d H:i:s', time() - 48 * HOUR_IN_SECONDS ), $hd_s ) );
apse_ok( 0 === \ApSemplice\Holds::release_expired(), 'tolleranza: con 0 ore nessun posto viene liberato' );
$rs_ok = array( 'confirm' => '1', 'phrase' => 'AZZERA TUTTO', 'password' => 'Azzera-Pass-123', 'mode' => 'data', 'users' => '1' );
$rs_people0 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) );
apse_ok( null !== apse_throws( function () use ( $rs_ok ) { Admin\TechActions::reset_all( array_diff_key( $rs_ok, array( 'confirm' => 1 ) ) ); } ) && null !== apse_throws( function () use ( $rs_ok ) { Admin\TechActions::reset_all( array_merge( $rs_ok, array( 'phrase' => 'azzera' ) ) ); } ) && null !== apse_throws( function () use ( $rs_ok ) { Admin\TechActions::reset_all( array_merge( $rs_ok, array( 'password' => 'sbagliata' ) ) ); } ) && null !== apse_throws( function () use ( $rs_ok ) { Admin\TechActions::reset_all( array_merge( $rs_ok, array( 'password' => '' ) ) ); } ) && $rs_people0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) ), 'azzeramento: senza spunta, con la frase sbagliata o con la password sbagliata non si cancella nulla' );
Settings::update( array( 'association_name' => 'Associazione da azzerare' ) );
$rs_audit0 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'audit_log' ) );
$rs_saved0 = count( \ApSemplice\Backup::saved() );
$rs_mu  = \ApSemplice\Reset::member_only_users();
apse_ok( null !== apse_throws( function () use ( $rs_ok ) { Admin\TechActions::reset_all( $rs_ok ); } ) && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) ) > 0, 'azzeramento: senza aver scaricato una copia non si cancella nulla' );
\ApSemplice\Backup::mark_downloaded();
$rs_msg = Admin\TechActions::reset_all( $rs_ok );
apse_ok( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'activities' ) ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'receipts' ) ), 'azzeramento dei dati: soci, attività, prima nota, prenotazioni e ricevute cancellati' );
apse_ok( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'accounts' ) ) >= 2 && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'categories' ) ) >= 8 && count( \ApSemplice\Levels::all() ) >= 2 && \ApSemplice\FiscalYears::is_open( \ApSemplice\FiscalYears::current() ), 'azzeramento dei dati: conti, voci, livelli e anno solare predefiniti ricreati' );
apse_ok( 'Associazione da azzerare' === Settings::get( 'association_name' ) && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'audit_log' ) ) >= $rs_audit0 && in_array( 'system.reset', array_column( Audit::recent( 5 ), 'action' ), true ), 'azzeramento dei dati: impostazioni e registro azioni restano, con la registrazione dell\'azzeramento' );
apse_ok( 0 === count( \ApSemplice\Backup::saved() ) && ! \ApSemplice\Backup::downloaded_recently(), 'azzeramento: sul sito non resta nessuna copia e il permesso di scarico si consuma' );
apse_ok( false !== get_userdata( 1 ) && $rs_mu && ! array_filter( $rs_mu, function ( $uid ) { return false !== get_userdata( $uid ); } ) && false !== strpos( $rs_msg[1], 'Dati azzerati' ) && $rs_msg[0] === Admin\Ui::url( 'apse' ), 'azzeramento: gli amministratori restano, gli accessi dei soci (solo ruolo «Socio APS») sono eliminati' );
$rs_new = Plugin::people()->create( array( 'type' => 'ordinary', 'first_name' => 'Primo', 'last_name' => 'Dopo' ) );
apse_ok( 1 === (int) $rs_new, 'azzeramento: la numerazione riparte da 1' );
// ripristino di fabbrica
Settings::update( array( 'association_name' => 'Da cancellare del tutto', 'tax_code' => '12345678903' ) );
update_option( 'apse_pages', array( 'area' => 1 ) );
\ApSemplice\Backup::mark_downloaded();
$rs_msg2 = Admin\TechActions::reset_all( array_merge( $rs_ok, array( 'mode' => 'factory', 'users' => '' ) ) );
apse_ok( '' === (string) Settings::get( 'association_name' ) && '' === (string) Settings::get( 'tax_code' ) && false === get_option( 'apse_pages', false ) && \ApSemplice\Wizard::pending() && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'audit_log' ) ) < $rs_audit0 && in_array( 'system.reset', array_column( Audit::recent( 10 ), 'action' ), true ) && false !== strpos( $rs_msg2[1], 'Ripristino di fabbrica completato' ) && false !== strpos( $rs_msg2[0], 'apse-wizard' ), 'ripristino di fabbrica: impostazioni, pagine, registro azioni cancellati e configurazione guidata riaperta' );
apse_ok( ! $GLOBALS['apse_warnings'], 'nessun warning/notice/deprecation PHP dal plugin' . ( $GLOBALS['apse_warnings'] ? ': ' . implode( ' | ', array_slice( $GLOBALS['apse_warnings'], 0, 5 ) ) : '' ) );

WP_CLI::success( 'Tutti i controlli sono passati.' );
