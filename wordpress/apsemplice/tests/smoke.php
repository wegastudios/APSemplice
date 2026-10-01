<?php
/**
 * Test di fumo dentro un WordPress reale:
 *   wp eval-file wp-content/plugins/apsemplice/tests/smoke.php
 * Crea dati di prova, verifica le regole principali e fa il render di tutte le pagine di amministrazione.
 * Esce con errore al primo controllo fallito.
 */

use ApSemplice\Access;
use ApSemplice\Admin;
use ApSemplice\Audit;
use ApSemplice\Gatekeeper;
use ApSemplice\Gateways;
use ApSemplice\PaymentConfig;
use ApSemplice\Secrets;
use ApSemplice\License;
use ApSemplice\Db;
use ApSemplice\Labels;
use ApSemplice\MemberType;
use ApSemplice\PeopleCsv;
use ApSemplice\Plugin;
use ApSemplice\Settings;

$GLOBALS['aps_warnings'] = array();
set_error_handler(
	function ( $no, $str, $file, $line ) {
		if ( false !== strpos( str_replace( '\\', '/', $file ), '/apsemplice/' ) ) {
			$GLOBALS['aps_warnings'][] = "$str ($file:$line)";
		}
		return false;
	}
);

function aps_ok( $cond, string $msg ): void {
	if ( ! $cond ) {
		WP_CLI::error( 'FAIL: ' . $msg );
	}
	WP_CLI::log( 'ok - ' . $msg );
}

function aps_throws( callable $f ): ?string {
	try {
		$f();
	} catch ( \InvalidArgumentException $e ) {
		return $e->getMessage();
	}
	return null;
}

function aps_render( callable $page, string $must_contain, array $get = array() ): string {
	$old = $_GET;
	$_GET = array_merge( array(), $get );
	ob_start();
	$page();
	$html = ob_get_clean();
	$_GET = $old;
	aps_ok( false !== strpos( $html, $must_contain ), 'pagina: ' . $must_contain );
	return $html;
}

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// ---------- Installazione ----------
foreach ( array( 'people', 'memberships', 'accounts', 'categories', 'activities', 'enrollments', 'transactions', 'cash_counts' ) as $t ) {
	aps_ok( Db::t( $t ) === $wpdb->get_var( "SHOW TABLES LIKE '" . Db::t( $t ) . "'" ), "tabella $t" );
}
aps_ok( null !== get_role( Plugin::ROLE_MEMBER ), 'ruolo Socio APS' );
aps_ok( get_role( 'administrator' )->has_cap( Plugin::CAP ), 'gli amministratori hanno la capability' );
aps_ok( ! get_role( 'subscriber' )->has_cap( Plugin::CAP ), 'gli altri ruoli no' );
aps_ok( count( Plugin::ledger()->accounts() ) >= 2, 'conti iniziali' );
aps_ok( count( Plugin::ledger()->categories() ) >= 9, 'categorie iniziali' );

wp_set_current_user( 1 );
$people   = Plugin::people();
$acts     = Plugin::activities();
$ledger   = Plugin::ledger();
$reports  = Plugin::reports();
$today    = Db::today();
$year     = substr( $today, 0, 4 );
$sy       = Settings::social_year();
$month    = $sy->clamp( substr( $today, 0, 7 ) );
$cash     = $ledger->default_account_for( 'cash' );
$bank     = $ledger->default_account_for( 'bank' );
$start_balance = array_sum( array_column( $ledger->balances(), 'balance' ) );

// ---------- Soci e utenti WordPress ----------
$founder = $people->create( array( 'type' => 'founder', 'card_number' => '1', 'first_name' => 'Fulvia', 'last_name' => 'Fondi', 'email' => 'Fulvia@Example.com' ) );
$fp      = $people->get( $founder );
aps_ok( ! empty( $fp['wp_user_id'] ), 'il socio fondatore ha un utente WordPress' );
$wpu = get_userdata( (int) $fp['wp_user_id'] );
aps_ok( 'fulvia@example.com' === $wpu->user_email, 'email dell\'utente = email del socio (minuscola)' );
aps_ok( array( Plugin::ROLE_MEMBER ) === array_values( $wpu->roles ), 'ruolo Socio APS, nessun accesso admin' );
aps_ok( ! user_can( $wpu, Plugin::CAP ), 'il socio non può gestire il plugin' );
aps_ok( $people->is_active_member( $founder ), 'fondatore: tessera sempre valida' );
aps_ok( $people->active_until( $founder ) > gmdate( 'Y-m-d', strtotime( '+90 years' ) ), 'fondatore: scadenza a 99 anni' );

aps_ok( null !== aps_throws( function () use ( $people ) { $people->create( array( 'type' => 'ordinary', 'first_name' => 'A', 'last_name' => 'B', 'email' => '' ) ); } ), 'email obbligatoria per i soci' );
$msg = (string) aps_throws( function () use ( $people ) { $people->create( array( 'type' => 'ordinary', 'card_number' => '1', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.com' ) ); } );
aps_ok( '' !== $msg && false !== strpos( $msg, 'Fondi' ), 'tessera duplicata rifiutata e il messaggio dice a chi appartiene' );
aps_ok( null !== aps_throws( function () use ( $people ) { $people->create( array( 'type' => 'ordinary', 'card_number' => '9', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'FULVIA@example.com' ) ); } ), 'email duplicata rifiutata' );
aps_ok( '2' === $people->next_free_card(), 'prossima tessera libera' );

$vol = $people->create( array( 'type' => 'volunteer', 'card_number' => '2', 'first_name' => 'Vera', 'last_name' => 'Volta', 'email' => 'vera@example.com' ) );
$ord = $people->create( array( 'type' => 'ordinary', 'card_number' => '3', 'first_name' => 'Omar', 'last_name' => 'Ordini', 'email' => 'omar@example.com' ) );

$existing_user = wp_create_user( 'esistente', 'x-Pass-123456', 'esistente@example.com' );
$linked        = $people->create( array( 'type' => 'ordinary', 'card_number' => '4', 'first_name' => 'Elia', 'last_name' => 'Esistente', 'email' => 'esistente@example.com' ) );
aps_ok( (int) $existing_user === (int) $people->get( $linked )['wp_user_id'], 'utente WordPress esistente collegato' );
aps_ok( array( 'subscriber' ) === array_values( get_userdata( $existing_user )->roles ), 'il ruolo dell\'utente esistente non viene toccato' );
aps_ok( null !== aps_throws( function () use ( $people ) { $people->create( array( 'type' => 'ordinary', 'first_name' => 'Doppio', 'last_name' => 'Utente', 'email' => 'vera@example.com' ) ); } ), 'stesso utente non collegabile a due soci' );

// ---------- Ospiti ----------
aps_ok( null !== aps_throws( function () use ( $people ) { $people->create( array( 'type' => 'guest', 'first_name' => 'Gino', 'last_name' => 'Ospiti' ) ); } ), 'l\'ospite richiede il socio ospitante' );
$guest = $people->create( array( 'type' => 'guest', 'first_name' => 'Gino', 'last_name' => 'Ospiti', 'host_person_id' => $ord ) );
aps_ok( empty( $people->get( $guest )['wp_user_id'] ), 'l\'ospite non ha utente WordPress' );
aps_ok( null !== aps_throws( function () use ( $people, $guest ) { $people->create( array( 'type' => 'guest', 'first_name' => 'X', 'last_name' => 'Y', 'host_person_id' => $guest ) ); } ), 'un ospite non può ospitare' );
aps_ok( 1 === count( $people->guests_of( $ord ) ), 'ospiti del socio' );
aps_ok( null !== aps_throws( function () use ( $people, $ord ) { $people->delete( $ord ); } ), 'non si elimina un socio con ospiti' );
aps_ok( ! $people->is_active_member( $guest ), 'l\'ospite non è socio' );

// ---------- Aggiornamento e sincronizzazione utente ----------
$people->update( $vol, array( 'email' => 'vera.volta@example.com', 'first_name' => 'Veronica' ) );
$vu = get_userdata( (int) $people->get( $vol )['wp_user_id'] );
aps_ok( 'vera.volta@example.com' === $vu->user_email && 'Veronica' === $vu->first_name, 'modifica sincronizzata sull\'utente WordPress' );
$people->update( $linked, array( 'email' => 'elia.nuova@example.com' ) );
aps_ok( 'esistente@example.com' === get_userdata( $existing_user )->user_email, 'email di un utente non "solo socio" non viene cambiata' );
aps_ok( null !== aps_throws( function () use ( $people, $ord ) { $people->update( $ord, array( 'type' => 'guest', 'host_person_id' => 1 ) ); } ), 'un socio non diventa ospite' );

// ---------- Attività ----------
aps_ok( null !== aps_throws( function () use ( $acts, $sy, $ord ) { $acts->create( array( 'name' => 'Yoga', 'social_year' => $sy->label(), 'instructor_person_id' => $ord ) ); } ), 'istruttore ordinario rifiutato' );
aps_ok( null !== aps_throws( function () use ( $acts, $sy, $founder ) { $acts->create( array( 'name' => 'Yoga', 'social_year' => $sy->label(), 'instructor_person_id' => $founder ) ); } ), 'istruttore fondatore rifiutato' );
$yoga = $acts->create( array( 'name' => 'Yoga', 'social_year' => $sy->label(), 'instructor_person_id' => $vol, 'monthly_fee_cents' => 2000 ) );
aps_ok( $yoga > 0, 'attività tenuta da socio e volontario' );

$acts->enroll( $yoga, $ord, $month );
$acts->enroll( $yoga, $guest, $month );
aps_ok( 2 === $acts->active_participants( $yoga ), 'iscritti attivi (socio e ospite)' );
$st = $acts->status_for_activity( $yoga );
aps_ok( 2 === count( $st ) && -2000 === $st[0]['summary']['balance'], 'prima del pagamento: da versare 20,00' );
aps_ok( $month === $acts->first_unpaid_month( $yoga, $ord ), 'primo mese non pagato' );

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
aps_ok( 2 === $n, 'incasso con due voci' );
aps_ok( $people->is_active_member( $ord ), 'la quota associativa iscrive il socio' );
$ord_status = $acts->status_for_person( $ord );
aps_ok( 1 === count( $ord_status ) && 0 === $ord_status[0]['summary']['balance'] && $ord_status[0]['summary']['regular'], 'mensilità pagata: in regola' );
aps_ok( null === $acts->first_unpaid_month( $yoga, $ord ), 'nessun mese da pagare' );

aps_ok( null !== aps_throws( function () use ( $ledger, $today, $cash, $cat, $founder ) { $ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $founder, 'lines' => array( array( 'category_id' => $cat['membership'], 'amount_cents' => 1000 ) ) ) ); } ), 'il fondatore non paga la quota' );
aps_ok( null !== aps_throws( function () use ( $ledger, $today, $cash, $cat, $guest ) { $ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $guest, 'lines' => array( array( 'category_id' => $cat['membership'], 'amount_cents' => 1000 ) ) ) ); } ), 'l\'ospite non paga la quota associativa' );
aps_ok( null !== aps_throws( function () use ( $ledger, $today, $cash, $cat ) { $ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'lines' => array( array( 'category_id' => $cat['membership'], 'amount_cents' => 1000 ) ) ) ); } ), 'quota associativa senza socio rifiutata' );
aps_ok( null !== aps_throws( function () use ( $ledger, $today, $cash, $cat ) { $ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'lines' => array( array( 'category_id' => $cat['donation'], 'amount_cents' => 0 ) ) ) ); } ), 'importo zero rifiutato' );

// L'ospite paga solo la mensilità (e il pagamento è in contanti)
$ledger->record_receipt(
	array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $guest,
		'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 2000, 'activity_id' => $yoga, 'competence_month' => $month ) ) )
);
aps_ok( $acts->status_for_person( $guest )[0]['summary']['regular'], 'l\'ospite paga solo l\'attività' );

$cash_balance = $ledger->expected_balance( (int) $cash['id'], $today );
aps_ok( $start_balance + 5000 === array_sum( array_column( $ledger->balances(), 'balance' ) ), 'saldo totale = iniziale + 50,00 incassati' );

// ---------- Spesa, giroconto, annullamento, verifica cassa ----------
$ledger->record_expense( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'category_id' => $cat['instructor_reimbursement'], 'amount_cents' => 1500, 'activity_id' => $yoga, 'person_id' => $vol, 'description' => 'Rimborso istruttrice' ) );
$ledger->record_transfer( $today, (int) $cash['id'], (int) $bank['id'], 1000, 'bank_transfer', 'Versamento' );
$by_id = array();
foreach ( $ledger->balances() as $b ) {
	$by_id[ (int) $b['id'] ] = $b['balance'];
}
aps_ok( $cash_balance - 1500 - 1000 === $by_id[ (int) $cash['id'] ], 'cassa dopo spesa e giroconto' );
aps_ok( $start_balance + 5000 - 1500 === array_sum( $by_id ), 'il giroconto non cambia il totale' );

$tx_ids = $wpdb->get_col( 'SELECT id FROM ' . Db::t( 'transactions' ) . " WHERE type = 'transfer_out'" );
$ledger->void( (int) $tx_ids[0], 'prova' );
aps_ok( $cash_balance - 1500 === $ledger->expected_balance( (int) $cash['id'], $today ) && $start_balance + 5000 - 1500 === array_sum( array_column( $ledger->balances(), 'balance' ) ), 'annullare un giroconto annulla entrambe le righe' );

$mem_tx = (int) $wpdb->get_var( 'SELECT transaction_id FROM ' . Db::t( 'memberships' ) . ' WHERE person_id = ' . (int) $ord . ' AND deleted_at IS NULL' );
$ledger->void( $mem_tx, 'errore' );
aps_ok( ! $people->is_active_member( $ord ), 'annullando la quota si toglie l\'iscrizione' );
aps_ok( null !== aps_throws( function () use ( $ledger, $mem_tx ) { $ledger->void( $mem_tx, 'di nuovo' ); } ), 'non si annulla due volte' );

$expected = $ledger->expected_balance( (int) $cash['id'], $today );
$diff     = $ledger->record_cash_count( (int) $cash['id'], $today, $expected + 500, true );
aps_ok( 500 === $diff && $expected + 500 === $ledger->expected_balance( (int) $cash['id'], $today ), 'verifica cassa con rettifica riallinea il saldo' );

// ---------- Permessi derivati dai dati (Access) ----------
$u_admin = 1;
$u_vol   = (int) $people->get( $vol )['wp_user_id'];
$u_ord   = (int) $people->get( $ord )['wp_user_id'];
$other   = $acts->create( array( 'name' => 'Teatro', 'social_year' => $sy->label(), 'monthly_fee_cents' => 1000 ) );
aps_ok( user_can( $u_admin, 'aps_notify_activity', $yoga ), 'admin: può avvisare qualsiasi attività' );
aps_ok( user_can( $u_vol, 'aps_notify_activity', $yoga ) && user_can( $u_vol, 'aps_view_participants', $yoga ), 'il volontario istruttore gestisce i suoi iscritti' );
aps_ok( ! user_can( $u_vol, 'aps_notify_activity', $other ), 'il volontario non gestisce attività altrui' );
aps_ok( ! user_can( $u_ord, 'aps_notify_activity', $yoga ) && ! user_can( $u_ord, 'aps_view_participants', $yoga ), 'il socio ordinario non gestisce iscritti' );
aps_ok( user_can( $u_ord, 'aps_view_person', $ord ) && ! user_can( $u_ord, 'aps_view_person', $vol ), 'il socio vede solo sé stesso' );
aps_ok( user_can( $u_ord, 'aps_view_activity', $yoga ) && ! user_can( $u_ord, 'aps_view_activity', $other ), 'il socio vede le attività a cui è iscritto' );
aps_ok( user_can( $u_ord, 'aps_add_guest', $ord ) && ! user_can( $u_vol, 'aps_add_guest', $ord ), 'ogni socio aggiunge ospiti solo per sé' );
aps_ok( ! user_can( 0, 'aps_view_person', $ord ), 'utente anonimo: nessun permesso' );
aps_ok( ! user_can( $u_ord, Plugin::CAP ), 'un socio non ha la capability di amministrazione' );

// ---------- REST API ----------
function aps_rest( int $user, string $route ): WP_REST_Response {
	wp_set_current_user( $user );
	return rest_do_request( new WP_REST_Request( 'GET', $route ) );
}
$r = aps_rest( $u_ord, '/apsemplice/v1/me' );
aps_ok( 200 === $r->get_status() && $ord === $r->get_data()['person']['id'] && false === $r->get_data()['is_admin'], 'REST /me: socio' );
aps_ok( true === aps_rest( $u_admin, '/apsemplice/v1/me' )->get_data()['is_admin'], 'REST /me: amministratore' );
aps_ok( in_array( aps_rest( 0, '/apsemplice/v1/me' )->get_status(), array( 401, 403 ), true ), 'REST /me: senza login rifiutato' );
$r = aps_rest( $u_ord, '/apsemplice/v1/me/activities' );
aps_ok( 200 === $r->get_status() && 1 === count( $r->get_data()['activities'] ) && 'Yoga' === $r->get_data()['activities'][0]['activity'], 'REST /me/activities' );
aps_ok( 404 === aps_rest( 1, '/apsemplice/v1/me/activities' )->get_status(), 'REST /me/activities: utente senza socio -> 404' );

$r = aps_rest( $u_vol, "/apsemplice/v1/activities/$yoga/participants" );
aps_ok( 200 === $r->get_status() && 2 === count( $r->get_data()['participants'] ), 'REST participants: il volontario istruttore vede gli iscritti' );
aps_ok( ! isset( $r->get_data()['participants'][0]['email'] ) && ! isset( $r->get_data()['participants'][0]['balance'] ), 'REST participants: il volontario NON vede contatti né pagamenti' );
aps_ok( isset( aps_rest( $u_admin, "/apsemplice/v1/activities/$yoga/participants" )->get_data()['participants'][0]['email'] ), 'REST participants: l\'amministratore vede i contatti' );
aps_ok( 403 === aps_rest( $u_ord, "/apsemplice/v1/activities/$yoga/participants" )->get_status(), 'REST participants: il socio è rifiutato' );
aps_ok( 403 === aps_rest( $u_vol, "/apsemplice/v1/activities/$other/participants" )->get_status(), 'REST participants: attività di un altro rifiutata' );
aps_ok( 404 === aps_rest( $u_admin, '/apsemplice/v1/activities/999999/participants' )->get_status(), 'REST participants: attività inesistente' );
aps_ok( 200 === aps_rest( $u_ord, "/apsemplice/v1/people/$ord" )->get_status() && 403 === aps_rest( $u_ord, "/apsemplice/v1/people/$vol" )->get_status(), 'REST people: solo la propria scheda' );
wp_set_current_user( 1 );

// ---------- Area riservata: i soci restano fuori da wp-admin ----------
wp_set_current_user( $u_ord );
aps_ok( false === apply_filters( 'show_admin_bar', true ), 'barra di amministrazione nascosta ai soci' );
wp_set_current_user( 1 );
aps_ok( true === apply_filters( 'show_admin_bar', true ), 'barra di amministrazione visibile agli amministratori' );
aps_ok( Gatekeeper::area_url() === apply_filters( 'login_redirect', '/wp-admin/', '', get_userdata( $u_ord ) ), 'dopo il login il socio va all\'area riservata' );
aps_ok( '/wp-admin/' === apply_filters( 'login_redirect', '/wp-admin/', '', get_userdata( 1 ) ), 'l\'amministratore non viene dirottato' );
aps_ok( '/wp-admin/' === apply_filters( 'login_redirect', '/wp-admin/', '', get_userdata( $existing_user ) ), 'chi ha altri ruoli non viene dirottato' );

// ---------- Registro azioni e licenza ----------
$actions = array_column( Audit::recent( 1000 ), 'action' );
foreach ( array( 'person.created', 'person.updated', 'membership.set', 'membership.removed', 'activity.created', 'activity.enrolled', 'tx.created', 'tx.voided', 'cashcount.recorded' ) as $a ) {
	aps_ok( in_array( $a, $actions, true ), "registro azioni: $a" );
}
aps_ok( 1 === (int) Audit::recent( 1 )[0]['user_id'], 'il registro ricorda chi ha agito' );
Settings::update( array( 'license_key' => '  ABC-123  ', 'member_area_page_id' => 0 ) );
aps_ok( 'ABC-123' === License::key() && License::allows( 'online_payments' ), 'licenza: chiave salvata, funzioni consentite (standby)' );
aps_ok( '' !== License::status()['domain'], 'licenza: dominio del sito' );
$inst1 = License::installation();
aps_ok( $inst1['id'] === License::installation()['id'] && ! $inst1['moved'], 'licenza: l\'id dell\'installazione è stabile' );
update_option( License::OPT_INSTALL_URL, 'https://produzione-originale.example.it' ); // simula un database copiato da un altro indirizzo
$inst2 = License::installation();
aps_ok( $inst2['moved'] && $inst2['id'] !== $inst1['id'], 'licenza: una copia su un altro indirizzo diventa una nuova installazione' );
aps_ok( $inst2['id'] === License::installation()['id'], 'licenza: il nuovo id poi resta stabile' );

// ---------- Licenza non in regola: popup e blocchi ----------
aps_ok( License::allows( 'export' ) && License::allows( 'member_area' ) && '' === Admin\LicenseNotice::html(), 'licenza in standby: nessun blocco e nessun popup' );
License::set_state( 'unpaid', $today );
$pol = License::policy();
aps_ok( 'closable' === $pol['popup'] && 7 === $pol['days_left'], 'licenza non pagata: popup chiudibile per 7 giorni' );
aps_ok( ! License::allows( 'export' ) && ! License::allows( 'member_area' ), 'licenza non pagata: export e accesso soci bloccati subito' );
aps_ok( false !== strpos( Admin\LicenseNotice::html(), 'aps-overlay-close' ), 'popup con pulsante di chiusura' );
aps_ok( false !== strpos( Admin\Exports::link( 'people', array(), 'Esporta' ), 'disabled' ), 'pulsanti di esportazione disattivati' );
aps_ok( user_can( 1, 'aps_view_participants', $yoga ), 'amministratore: i permessi restano' );
aps_ok( ! user_can( $u_vol, 'aps_view_participants', $yoga ) && ! user_can( $u_ord, 'aps_view_person', $ord ), 'volontari e soci: nessun permesso' );
$r = aps_rest( $u_ord, '/apsemplice/v1/me' );
aps_ok( 403 === $r->get_status() && 'aps_license_required' === $r->get_data()['code'], 'REST: i soci ricevono "servizio sospeso"' );
aps_ok( 403 === aps_rest( $u_vol, "/apsemplice/v1/activities/$yoga/participants" )->get_status(), 'REST: il volontario è sospeso' );
aps_ok( 200 === aps_rest( 1, '/apsemplice/v1/me' )->get_status(), 'REST: l\'amministratore resta operativo' );
License::set_state( 'unpaid', gmdate( 'Y-m-d', strtotime( $today . ' -7 days' ) ) );
aps_ok( 'locked' === License::policy()['popup'] && false === strpos( Admin\LicenseNotice::html(), 'aps-overlay-close' ), 'dopo una settimana il popup non si chiude più' );
License::set_state( 'unpaid', gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) ) );
aps_ok( 'closable' === License::policy()['popup'] && 1 === License::policy()['days_left'], 'al sesto giorno è ancora chiudibile' );
License::set_state( 'unlicensed', $today );
aps_ok( false !== strpos( Admin\LicenseNotice::html(), 'non risulta più associato' ), 'dominio non più associato: messaggio dedicato' );
License::set_state( 'active' );
aps_ok( License::allows( 'export' ) && '' === Admin\LicenseNotice::html() && 200 === aps_rest( $u_ord, '/apsemplice/v1/me' )->get_status(), 'licenza regolarizzata: tutto torna disponibile' );
delete_option( License::OPT_STATE );
wp_set_current_user( 1 );

// ---------- Cancellazione da attività ----------
$acts->cancel( $yoga, $guest, $month );
aps_ok( 1 === $acts->active_participants( $yoga ), 'cancellato dall\'attività' );
aps_ok( 1 === count( $acts->status_for_person( $guest ) ), 'la cancellazione conserva la storia' );

// ---------- Report ----------
$p = $reports->period( $year . '-01-01', $year . '-12-31' );
aps_ok( $p['closing_total'] === array_sum( array_column( $ledger->balances(), 'balance' ) ), 'report: saldo finale = saldi dei conti' );
aps_ok( $p['total_income'] - $p['total_expense'] === $p['result'], 'report: avanzo' );
$s = $reports->social_year( $sy );
aps_ok( 1 === ( $s['members_by_type']['founder'] ?? 0 ), 'report anno sociale: 1 fondatore' );
aps_ok( ! isset( $s['members_by_type']['volunteer'] ), 'report anno sociale: il volontario senza quota non è iscritto' );
aps_ok( 2 === count( $s['activities'] ), 'report anno sociale: due attività (Yoga e Teatro)' );
$yoga_sum = array_values( array_filter( $s['activities'], function ( $a ) { return 'Yoga' === $a['activity']['name']; } ) )[0];
aps_ok( 4000 === $yoga_sum['income'] && 1500 === $yoga_sum['cost'] && 2500 === $yoga_sum['margin'], 'report anno sociale: incassi 40,00, costi 15,00, resta 25,00' );

// ---------- Tipi di attività: evento una tantum, ricorrente, corso con contributo ospiti ----------
$ev_date = gmdate( 'Y-m-d', strtotime( $today . ' +10 days' ) );
aps_ok( null !== aps_throws( function () use ( $acts, $sy_label ) { $acts->create( array( 'name' => 'Senza data', 'social_year' => $sy_label, 'kind' => 'event' ) ); } ), 'evento una tantum: la data è obbligatoria' );
$event = $acts->create(
	array(
		'name' => 'Serata giochi', 'social_year' => $sy_label, 'kind' => 'event', 'instructor_person_id' => $vol, 'fee_cents' => 500, 'guest_fee_cents' => 800,
		'session' => array( 'session_date' => $ev_date, 'start_time' => '21:00', 'location' => 'Sede', 'capacity' => 2 ),
	)
);
$ev_sessions = $acts->sessions( $event );
$sid         = (int) $ev_sessions[0]['id'];
aps_ok( 1 === count( $ev_sessions ) && '21:00' === $ev_sessions[0]['start_time'], 'evento una tantum: una data creata' );
aps_ok( null !== aps_throws( function () use ( $acts, $event, $ev_date ) { $acts->add_session( $event, array( 'session_date' => $ev_date ) ); } ), 'evento una tantum: una sola data' );
aps_ok( null !== aps_throws( function () use ( $acts, $event, $ord, $month ) { $acts->enroll( $event, $ord, $month ); } ), 'agli eventi ci si prenota, non ci si iscrive per mesi' );

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
aps_ok( 500 === (int) $bk[ $founder ]['fee_due_cents'] && 800 === (int) $bk[ $guest ]['fee_due_cents'], 'evento: contributo soci 5,00 e contributo ospiti 8,00' );
aps_ok( null !== aps_throws( function () use ( $acts, $sid, $founder ) { $acts->book( $sid, $founder ); } ), 'non si prenota due volte' );
$msg = (string) aps_throws( function () use ( $acts, $sid, $ord ) { $acts->book( $sid, $ord ); } );
aps_ok( false !== stripos( $msg, 'esauriti' ), 'capienza: posti esauriti' );

$pay = function ( int $person, int $cents, ?int $session, ?int $activity = null ) use ( $ledger, $today, $cash, $cat, $event ) {
	return $ledger->record_receipt(
		array(
			'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $person,
			'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => $cents, 'activity_id' => $activity ?: $event, 'session_id' => $session ) ),
		)
	);
};
aps_ok( null !== aps_throws( function () use ( $pay, $founder ) { $pay( $founder, 500, null ); } ), 'contributo evento senza la data: rifiutato' );
aps_ok( null !== aps_throws( function () use ( $pay, $vol, $sid ) { $pay( $vol, 500, $sid ); } ), 'contributo di chi non è prenotato: rifiutato' );
aps_ok( null !== aps_throws( function () use ( $pay, $ord, $sid, $yoga ) { $pay( $ord, 500, $sid, $yoga ); } ), 'un corso non accetta la data di un evento' );
$pay( $founder, 500, $sid );
$pay( $guest, 300, $sid );
$bk = $by_person( $sid );
aps_ok( 'paid' === $bk[ $founder ]['state'] && 'partial' === $bk[ $guest ]['state'] && 500 === $bk[ $guest ]['remaining'], 'pagamenti evento: socio pagato, ospite parziale (resta 5,00)' );
$unpaid = $acts->unpaid_bookings_for_person( $guest );
aps_ok( 1 === count( $unpaid ) && 500 === $unpaid[0]['remaining'], 'incasso: l\'evento da pagare viene proposto' );
aps_ok( 0 === count( $acts->unpaid_bookings_for_person( $founder ) ), 'incasso: niente da proporre se già pagato' );

$acts->cancel_booking( $sid, $founder );
$acts->book( $sid, $ord ); // il posto liberato si può riprenotare
aps_ok( 2 === (int) $acts->sessions( $event )[0]['booked_count'], 'annullando una prenotazione si libera il posto' );
aps_ok( 2 === $acts->active_participants( $event ), 'partecipanti dell\'evento' );

// evento ricorrente gratuito per i soci
$rec = $acts->create( array( 'name' => 'Aperitivo del giovedì', 'social_year' => $sy_label, 'kind' => 'recurring', 'fee_cents' => 0, 'guest_fee_cents' => 300 ) );
$rec_to = gmdate( 'Y-m-d', strtotime( $ev_date . ' +21 days' ) );
aps_ok( 4 === $acts->generate_weekly( $rec, $ev_date, $rec_to, '19:30', 'Bar', 20 ), 'ricorrente: quattro date settimanali' );
aps_ok( 0 === $acts->generate_weekly( $rec, $ev_date, $rec_to ), 'ricorrente: nessun duplicato rigenerando' );
aps_ok( null !== aps_throws( function () use ( $acts, $rec ) { $acts->generate_weekly( $rec, '2026-01-10', '2026-01-01' ); } ), 'ricorrente: periodo al contrario rifiutato' );
aps_ok( null !== aps_throws( function () use ( $acts, $yoga, $ev_date ) { $acts->add_session( $yoga, array( 'session_date' => $ev_date ) ); } ), 'i corsi non hanno date' );
$rec_sessions = $acts->sessions( $rec );
$s1           = (int) $rec_sessions[0]['id'];
$s2           = (int) $rec_sessions[1]['id'];
$acts->book( $s1, $ord );
$acts->book( $s1, $guest );
$acts->book( $s2, $ord ); // iscrizione al singolo evento: ogni data si prenota separatamente
$bk = $by_person( $s1 );
aps_ok( 'free' === $bk[ $ord ]['state'] && 300 === (int) $bk[ $guest ]['fee_due_cents'], 'ricorrente: gratuito per i soci, 3,00 per gli ospiti' );
aps_ok( 2 === $acts->active_participants( $rec ), 'ricorrente: persone distinte' );
aps_ok( 1 === count( $acts->bookings_for_session( $s2 ) ), 'ricorrente: ogni data ha le sue prenotazioni' );
$acts->cancel_session( $s2 );
$cancelled = array_filter( $acts->bookings_for_person( $ord ), function ( $b ) use ( $s2 ) { return (int) $b['session_id'] === $s2 && ! $b['active']; } );
aps_ok( 1 === count( $cancelled ), 'data annullata: la prenotazione non conta più' );
aps_ok( null !== aps_throws( function () use ( $acts, $s2, $vol ) { $acts->book( $s2, $vol ); } ), 'non si prenota una data annullata' );

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
aps_ok( -2000 === $cs[ $ord ]['balance'] && -3500 === $cs[ $guest ]['balance'], 'corso: mensilità soci 20,00 e ospiti 35,00' );
$acts->update( $corso, array( 'guest_fee_cents' => 0 ) );
$cs = $course_summary();
aps_ok( 0 === $cs[ $guest ]['balance'] && -2000 === $cs[ $ord ]['balance'], 'corso: contributo ospiti 0 = gratuito per gli ospiti' );
$acts->update( $corso, array( 'guest_fee_cents' => null ) );
aps_ok( 2000 === $acts->fee_for( $acts->get( $corso ), 'guest' ), 'corso: contributo ospiti non impostato = come i soci' );
$acts->update( $corso, array( 'kind' => 'event' ) );
aps_ok( 'course' === $acts->get( $corso )['kind'], 'il tipo non cambia dopo la creazione' );

// REST per eventi e prenotazioni
$r = aps_rest( $u_vol, "/apsemplice/v1/activities/$event/sessions" );
aps_ok( 200 === $r->get_status() && 1 === count( $r->get_data()['sessions'] ) && 2 === $r->get_data()['sessions'][0]['capacity'], 'REST sessions: il volontario istruttore vede le date' );
aps_ok( 403 === aps_rest( $u_ord, "/apsemplice/v1/activities/$rec/sessions" )->get_status(), 'REST sessions: chi non è iscritto/istruttore è rifiutato' );
$r = aps_rest( $u_vol, "/apsemplice/v1/sessions/$sid/bookings" );
aps_ok( 200 === $r->get_status() && ! isset( $r->get_data()['bookings'][0]['email'] ) && ! isset( $r->get_data()['bookings'][0]['state'] ), 'REST bookings: il volontario vede solo i nomi' );
aps_ok( isset( aps_rest( 1, "/apsemplice/v1/sessions/$sid/bookings" )->get_data()['bookings'][0]['state'] ), 'REST bookings: l\'amministratore vede anche i pagamenti' );
aps_ok( 403 === aps_rest( $u_ord, "/apsemplice/v1/sessions/$sid/bookings" )->get_status(), 'REST bookings: il socio è rifiutato' );
$r = aps_rest( $u_vol, "/apsemplice/v1/activities/$event/participants" );
aps_ok( 200 === $r->get_status() && 2 === count( $r->get_data()['participants'] ), 'REST participants: anche per gli eventi' );
$r = aps_rest( $u_ord, '/apsemplice/v1/me/bookings' );
aps_ok( 200 === $r->get_status() && count( $r->get_data()['bookings'] ) >= 3, 'REST /me/bookings' );
wp_set_current_user( 1 );


// ---------- Front-end: shortcode, area soci, prenotazioni dal sito, contenuti riservati ----------
$people->set_membership( $ord, $sy_label, true );
$people->set_membership( $vol, $sy_label, true );
$u_f = (int) $people->get( $founder )['wp_user_id'];
foreach ( array_keys( \ApSemplice\Frontend\Shortcodes::VIEWS ) as $slug ) {
	aps_ok( shortcode_exists( 'apsemplice_' . $slug ), "shortcode apsemplice_$slug registrato" );
}
aps_ok( shortcode_exists( 'apsemplice_riservato' ), 'shortcode apsemplice_riservato registrato' );
$as = function ( int $user, string $shortcode ) {
	wp_set_current_user( $user );
	return do_shortcode( $shortcode );
};

// Area soci per ruolo
$html = $as( 0, '[apsemplice_area_soci]' );
aps_ok( false !== strpos( $html, 'loginform' ), 'area soci: l\'anonimo vede il modulo di accesso' );
$html = $as( $u_ord, '[apsemplice_area_soci]' );
aps_ok( false !== strpos( $html, 'Omar' ) && false !== strpos( $html, 'Tessera n.' ) && false !== strpos( $html, 'Le mie attività' ) && false !== strpos( $html, 'I miei ospiti' ) && false !== strpos( $html, 'Il mio profilo' ), 'area soci: il socio vede tessera, attività, ospiti e profilo' );
aps_ok( false === strpos( $html, 'Le attività che tengo' ), 'area soci: il socio non vede la parte dei volontari' );
$html_vol = $as( $u_vol, '[apsemplice_area_soci]' );
aps_ok( false !== strpos( $html_vol, 'Le attività che tengo' ) && false !== strpos( $html_vol, 'Yoga' ), 'area soci: il volontario vede le attività che tiene' );
aps_ok( false === strpos( $html_vol, 'omar@example.com' ), 'area soci: il volontario non vede le email degli iscritti' );
aps_ok( false !== strpos( $as( 1, '[apsemplice_area_soci]' ), 'amministratore' ), 'area soci: l\'amministratore senza scheda riceve un messaggio chiaro' );
aps_ok( false !== strpos( $as( $u_ord, '[apsemplice_tessera]' ), 'apsf-memcard' ), 'shortcode tessera' );
aps_ok( false !== strpos( $as( $u_vol, '[apsemplice_area_volontari]' ), 'Yoga' ), 'shortcode area volontari (volontario)' );
aps_ok( false !== strpos( $as( $u_ord, '[apsemplice_area_volontari]' ), 'riservata ai soci e volontari' ), 'shortcode area volontari (socio semplice)' );

// Elenco attività pubblico e prenotazioni
$html = $as( 0, '[apsemplice_attivita]' );
aps_ok( false !== strpos( $html, 'Yoga' ) && false !== strpos( $html, 'Serata giochi' ) && false !== strpos( $html, 'Accedi per prenotarti' ), 'attività: pubblico, con invito ad accedere per prenotarsi' );
aps_ok( false !== strpos( $html, 'Soci 5,00' ) && false !== strpos( $html, 'ospiti 8,00' ), 'attività: contributo soci e ospiti visibile' );
$html = $as( 0, '[apsemplice_attivita tipo="evento"]' );
aps_ok( false !== strpos( $html, 'Serata giochi' ) && false === strpos( $html, 'Yoga' ), 'attività: filtro per tipo' );
aps_ok( false !== strpos( $as( $u_f, '[apsemplice_attivita tipo="ricorrente"]' ), 'aps_front_book' ), 'attività: il socio con tessera valida vede il pulsante Prenotati' );
aps_ok( false !== strpos( $as( 0, '[apsemplice_prossimi_eventi limite="3"]' ), 'apsf-upcoming' ), 'prossimi eventi' );
aps_ok( false !== strpos( $as( 0, '[apsemplice_accesso]' ), 'loginform' ) && '' === $as( $u_f, '[apsemplice_accesso]' ), 'accesso: solo per chi non è collegato' );

// Azioni dei soci
$front = '\ApSemplice\Frontend\Actions';
wp_set_current_user( $u_f );
$s3  = (int) $rec_sessions[2]['id'];
$msg = $front::do_book( array( 'session_id' => $s3, 'person_id' => $founder ) );
aps_ok( false !== strpos( $msg, 'Prenotazione registrata' ) && $acts->has_active_booking( $s3, $founder ), 'sito: il socio si prenota a un evento' );
$front::do_add_guest( array( 'first_name' => 'Gia', 'last_name' => 'Ospite', 'phone' => '333' ) );
$g_list = $people->guests_of( $founder );
aps_ok( 1 === count( $g_list ) && MemberType::GUEST === $g_list[0]['type'] && (int) $g_list[0]['host_person_id'] === $founder, 'sito: il socio aggiunge un proprio ospite' );
$g_id = (int) $g_list[0]['id'];
$front::do_book( array( 'session_id' => $s3, 'person_id' => $g_id ) );
$gb = $by_person( $s3 );
aps_ok( 300 === (int) $gb[ $g_id ]['fee_due_cents'] && 0 === (int) $gb[ $founder ]['fee_due_cents'], 'sito: l\'ospite prenotato paga il contributo ospiti' );
aps_ok( null !== aps_throws( function () use ( $front, $s3, $guest ) { $front::do_book( array( 'session_id' => $s3, 'person_id' => $guest ) ); } ), 'sito: non si prenota l\'ospite di un altro socio' );
$past_session = $acts->add_session( $rec, array( 'session_date' => gmdate( 'Y-m-d', strtotime( $today . ' -3 days' ) ) ) );
aps_ok( null !== aps_throws( function () use ( $front, $past_session, $founder ) { $front::do_book( array( 'session_id' => $past_session, 'person_id' => $founder ) ); } ), 'sito: non si prenota un evento già passato' );
wp_set_current_user( $u_ord );
aps_ok( null !== aps_throws( function () use ( $front, $s3, $founder ) { $front::do_book( array( 'session_id' => $s3, 'person_id' => $founder ) ); } ), 'sito: non si prenota un altro socio' );
$people->set_membership( $ord, $sy_label, false );
aps_ok( null !== aps_throws( function () use ( $front, $s3, $ord ) { $front::do_book( array( 'session_id' => $s3, 'person_id' => $ord ) ); } ), 'sito: con la tessera scaduta non ci si prenota' );
$people->set_membership( $ord, $sy_label, true );
wp_set_current_user( $u_f );
$msg = (string) aps_throws( function () use ( $front, $s3, $g_id ) { $front::do_cancel_booking( array( 'session_id' => $s3, 'person_id' => $g_id ) ); } );
aps_ok( false !== strpos( $msg, 'non è cancellabile' ) && $acts->has_active_booking( $s3, $g_id ), 'sito: la prenotazione a pagamento dell\'ospite non si annulla' );
$front::do_profile( array( 'phone' => '3331112222', 'tax_code' => 'abcdef12g34h567i' ) );
aps_ok( '3331112222' === $people->get( $founder )['phone'] && 'ABCDEF12G34H567I' === $people->get( $founder )['tax_code'], 'sito: il socio aggiorna il proprio profilo' );
License::set_state( 'unpaid', $today );
aps_ok( null !== aps_throws( function () use ( $front, $s3, $founder ) { $front::do_book( array( 'session_id' => $s3, 'person_id' => $founder ) ); } ), 'licenza non in regola: le azioni dei soci sono sospese' );
aps_ok( false !== strpos( $as( $u_f, '[apsemplice_area_soci]' ), 'sospeso' ), 'licenza non in regola: l\'area soci mostra "servizio sospeso"' );
delete_option( License::OPT_STATE );

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
aps_ok( $sees( $p_pub, 0 ), 'riservati: il contenuto pubblico lo vedono tutti' );
aps_ok( ! $sees( $p_mem, 0 ) && false !== strpos( $show( $p_mem, 0 ), 'Accedi' ), 'riservati: l\'anonimo vede l\'invito ad accedere, non il contenuto' );
aps_ok( $sees( $p_mem, $u_ord ) && ! $sees( $p_vol, $u_ord ), 'riservati: il socio vede "solo soci" ma non "solo volontari"' );
aps_ok( $sees( $p_vol, $u_vol ), 'riservati: il volontario vede "solo volontari"' );
aps_ok( $sees( $p_yoga, $u_ord ) && ! $sees( $p_teat, $u_ord ), 'riservati: il socio iscritto a Yoga vede il programma di Yoga ma non quello di Teatro' );
aps_ok( $sees( $p_yoga, $u_vol ) && ! $sees( $p_teat, $u_vol ), 'riservati: l\'istruttore vede solo i contenuti delle sue attività' );
aps_ok( $sees( $p_mem, $u_f ) && ! $sees( $p_vol, $u_f ) && ! $sees( $p_yoga, $u_f ), 'riservati: il fondatore vede "solo soci" ma non quello di attività a cui non è iscritto' );
aps_ok( $sees( $p_vol, 1 ) && $sees( $p_teat, 1 ) && $sees( $p_yoga, 1 ), 'riservati: l\'amministratore vede tutto' );
$people->set_membership( $ord, $sy_label, false );
aps_ok( ! $sees( $p_mem, $u_ord ) && $sees( $p_yoga, $u_ord ), 'riservati: tessera scaduta = niente "solo soci", ma resta il programma dell\'attività a cui è iscritto' );
$people->set_membership( $ord, $sy_label, true );
License::set_state( 'unpaid', $today );
aps_ok( ! $sees( $p_mem, $u_ord ) && $sees( $p_pub, $u_ord ) && $sees( $p_mem, 1 ), 'riservati: licenza non in regola = i soci non vedono i contenuti riservati' );
delete_option( License::OPT_STATE );

wp_set_current_user( 0 );
$rest = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $p_mem ) )->get_data();
aps_ok( '' === $rest['content']['rendered'] && ! empty( $rest['content']['protected'] ), 'riservati: l\'API REST non rivela il contenuto' );
aps_ok( false === strpos( get_the_excerpt( $p_mem ), 'RIASSUNTO' ), 'riservati: neanche il riassunto' );
wp_set_current_user( 1 );
$rest = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $p_mem ) )->get_data();
aps_ok( false !== strpos( $rest['content']['rendered'], 'SEGRETO' ), 'riservati: l\'amministratore legge il contenuto via REST' );

// Riquadro nell'editor e colonna
wp_set_current_user( 1 );
$p_edit = $mk( 'Da riservare', 'public' );
$_POST  = array( 'aps_access_nonce' => wp_create_nonce( 'aps_access_save' ), 'aps_access' => 'activity', 'aps_access_activities' => array( (string) $yoga ) );
\ApSemplice\Frontend\Restrict::save_meta_box( $p_edit, get_post( $p_edit ) );
aps_ok( 'activity' === get_post_meta( $p_edit, '_aps_access', true ) && array( $yoga ) === array_map( 'intval', (array) get_post_meta( $p_edit, '_aps_access_activities', true ) ), 'riquadro Accesso: salva regola e attività' );
ob_start();
\ApSemplice\Frontend\Restrict::print_column( 'aps_access', $p_edit );
aps_ok( false !== strpos( ob_get_clean(), 'Iscritti: Yoga' ), 'colonna Accesso negli elenchi' );
ob_start();
\ApSemplice\Frontend\Restrict::render_meta_box( get_post( $p_edit ) );
aps_ok( false !== strpos( ob_get_clean(), 'name="aps_access"' ), 'riquadro Accesso: si disegna' );
$_POST = array( 'aps_access_nonce' => wp_create_nonce( 'aps_access_save' ), 'aps_access' => 'public' );
\ApSemplice\Frontend\Restrict::save_meta_box( $p_edit, get_post( $p_edit ) );
aps_ok( '' === get_post_meta( $p_edit, '_aps_access', true ), 'riquadro Accesso: tornando pubblico la regola si toglie' );
$_POST = array( 'aps_access_nonce' => 'sbagliato', 'aps_access' => 'members' );
\ApSemplice\Frontend\Restrict::save_meta_box( $p_edit, get_post( $p_edit ) );
aps_ok( '' === get_post_meta( $p_edit, '_aps_access', true ), 'riquadro Accesso: nonce errato ignorato' );
$_POST = array();

// Parti di pagina: shortcode e blocchi
aps_ok( false === strpos( $as( 0, '[apsemplice_riservato accesso="soci"]INTERNO[/apsemplice_riservato]' ), 'INTERNO' ), 'parte riservata (shortcode): nascosta agli anonimi' );
aps_ok( false !== strpos( $as( $u_f, '[apsemplice_riservato accesso="soci"]INTERNO[/apsemplice_riservato]' ), 'INTERNO' ), 'parte riservata (shortcode): visibile ai soci' );
aps_ok( false !== strpos( $as( $u_ord, "[apsemplice_riservato accesso=\"attivita\" attivita=\"$yoga\"]X-YOGA[/apsemplice_riservato]" ), 'X-YOGA' ) && false === strpos( $as( $u_f, "[apsemplice_riservato accesso=\"attivita\" attivita=\"$yoga\"]X-YOGA[/apsemplice_riservato]" ), 'X-YOGA' ), 'parte riservata (shortcode): solo gli iscritti all\'attività' );
aps_ok( false !== strpos( $as( 0, '[apsemplice_riservato accesso="soci" messaggio="Solo per noi"]x[/apsemplice_riservato]' ), 'Solo per noi' ), 'parte riservata: messaggio personalizzato' );
$registry = WP_Block_Type_Registry::get_instance();
aps_ok( $registry->is_registered( 'apsemplice/vista' ) && $registry->is_registered( 'apsemplice/riservato' ), 'blocchi Gutenberg registrati' );
$block = function ( string $name, array $attrs, string $inner = '' ) {
	return render_block( array( 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => $inner, 'innerContent' => array( $inner ) ) );
};
wp_set_current_user( 0 );
aps_ok( false === strpos( $block( 'apsemplice/riservato', array( 'accesso' => 'members' ), '<p>BLK</p>' ), 'BLK' ), 'blocco Contenuto riservato: nascosto agli anonimi' );
wp_set_current_user( $u_f );
aps_ok( false !== strpos( $block( 'apsemplice/riservato', array( 'accesso' => 'members' ), '<p>BLK</p>' ), 'BLK' ), 'blocco Contenuto riservato: visibile ai soci' );
aps_ok( false !== strpos( $block( 'apsemplice/vista', array( 'vista' => 'attivita' ) ), 'Yoga' ), 'blocco APSemplice (vista attività)' );
wp_set_current_user( 1 );
aps_ok( file_exists( APS_DIR . 'assets/blocks.js' ) && file_exists( APS_DIR . 'assets/frontend.css' ), 'asset front-end presenti' );

// Pagine standard
$pages = new ReflectionMethod( Admin\Actions::class, 'create_pages' );
$pages->setAccessible( true );
$res1 = $pages->invoke( null, array() );
$saved_pages = (array) get_option( 'aps_pages', array() );
aps_ok( 3 === count( $saved_pages ) && false !== strpos( get_post( $saved_pages['area'] )->post_content, '[apsemplice_area_soci]' ), 'pagine standard create con gli shortcode' );
aps_ok( 'volunteers' === get_post_meta( $saved_pages['volontari'], '_aps_access', true ), 'la pagina Area volontari è riservata ai volontari' );
aps_ok( (int) $saved_pages['area'] === (int) Settings::get( 'member_area_page_id' ) && Gatekeeper::area_url() === get_permalink( $saved_pages['area'] ), 'l\'Area soci diventa la pagina di arrivo dopo il login' );
aps_ok( false !== strpos( $pages->invoke( null, array() )[1], 'esistono già' ), 'pagine standard: non si duplicano' );
aps_ok( false !== strpos( do_shortcode( get_post( $saved_pages['attivita'] )->post_content ), 'Yoga' ), 'la pagina Attività mostra le attività' );

// Elementor (installato nel test): i widget si registrano e i controlli si costruiscono
aps_ok( class_exists( '\Elementor\Plugin' ), 'Elementor è presente nell\'ambiente di test' );
$el_widgets = \Elementor\Plugin::instance()->widgets_manager->get_widget_types();
aps_ok( isset( $el_widgets['apsemplice_view'] ) && isset( $el_widgets['apsemplice_reserved'] ), 'Elementor: widget APSemplice registrati' );
aps_ok( array_key_exists( 'view', $el_widgets['apsemplice_view']->get_controls() ) && array_key_exists( 'rule', $el_widgets['apsemplice_reserved']->get_controls() ), 'Elementor: i controlli dei widget si costruiscono' );
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
	return aps_throws( function () use ( $front, $session, $person ) { $front::do_cancel_booking( array( 'session_id' => $session, 'person_id' => $person ) ); } );
};
wp_set_current_user( 1 );

// Evento gratuito: si annulla sempre
$free_ev = $mkev( 'Aperitivo gratuito', 0, null );
$free_s  = $first_session( $free_ev );
wp_set_current_user( $u_f );
$front::do_book( array( 'session_id' => $free_s, 'person_id' => $founder ) );
aps_ok( true === $acts->cancellation_for( $free_s, $founder )['allowed'] && null === $cancels( $free_s, $founder ), 'evento gratuito: si può sempre annullare' );
aps_ok( ! $acts->has_active_booking( $free_s, $founder ), 'evento gratuito: prenotazione annullata' );

// Evento a pagamento non cancellabile: niente annullo, ma cambio di nominativo
wp_set_current_user( 1 );
$paid_ev = $mkev( 'Cena sociale', 500, 800 );
$paid_s  = $first_session( $paid_ev );
wp_set_current_user( $u_f );
$front::do_book( array( 'session_id' => $paid_s, 'person_id' => $founder ) );
$ev = $acts->cancellation_for( $paid_s, $founder );
aps_ok( ! $ev['allowed'] && 'not_cancellable' === $ev['reason'] && $ev['can_transfer'], 'evento a pagamento: non cancellabile ma il nominativo si può cambiare' );
aps_ok( false !== strpos( (string) $cancels( $paid_s, $founder ), 'non è cancellabile' ), 'evento a pagamento: l\'annullo dal sito è rifiutato' );
wp_set_current_user( 1 );
$ledger->record_receipt( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $founder,
	'lines' => array( array( 'category_id' => $cat['activity_fee'], 'amount_cents' => 500, 'activity_id' => $paid_ev, 'session_id' => $paid_s ) ) ) );
wp_set_current_user( $u_f );

// il socio cambia il nominativo a favore di un proprio ospite (contributo ospiti 8,00): integra 3,00
$front::do_add_guest( array( 'first_name' => 'Nico', 'last_name' => 'Ospite' ) );
$nico = 0;
foreach ( $people->guests_of( $founder ) as $g ) {
	if ( 'Nico' === $g['first_name'] ) {
		$nico = (int) $g['id'];
	}
}
$msg = $front::do_transfer_booking( array( 'session_id' => $paid_s, 'person_id' => $founder, 'to_person_id' => $nico ) );
$bk  = $by_person( $paid_s );
aps_ok( false !== strpos( $msg, 'Da integrare' ) && false !== strpos( $msg, '3,00' ), 'cambio nominativo a un ospite: da integrare 3,00' );
aps_ok( 800 === (int) $bk[ $nico ]['fee_due_cents'] && 500 === $bk[ $nico ]['paid'] && 300 === $bk[ $nico ]['remaining'] && 'partial' === $bk[ $nico ]['state'], 'il pagamento già fatto passa all\'ospite' );
aps_ok( ! $bk[ $founder ]['active'] && 'transferred' === $bk[ $founder ]['status'] && 0 === $bk[ $founder ]['paid'], 'la prenotazione del socio risulta trasferita' );
aps_ok( 1 === (int) $acts->sessions( $paid_ev )[0]['booked_count'], 'i posti occupati non cambiano' );
aps_ok( 500 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(amount_cents) FROM ' . Db::t( 'transactions' ) . ' WHERE session_id = %d AND person_id = %d AND voided_at IS NULL', $paid_s, $nico ) ), 'il pagamento è intestato al nuovo partecipante' );
aps_ok( false !== strpos( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT description FROM ' . Db::t( 'transactions' ) . ' WHERE session_id = %d AND person_id = %d LIMIT 1', $paid_s, $nico ) ), 'intestato da' ), 'nota di trasferimento nella descrizione del pagamento' );

// ...e poi a un nuovo ospite indicato per nome (resta lo stesso contributo, niente da integrare)
$msg = $front::do_transfer_booking( array( 'session_id' => $paid_s, 'person_id' => $nico, 'new_first_name' => 'Nuovo', 'new_last_name' => 'Amico' ) );
aps_ok( false !== strpos( $msg, 'Da integrare' ) && false !== strpos( $msg, '3,00' ), 'cambio verso un nuovo ospite indicato per nome: resta da integrare 3,00' );
$amico = 0;
foreach ( $people->guests_of( $founder ) as $g ) {
	if ( 'Amico' === $g['last_name'] ) {
		$amico = (int) $g['id'];
	}
}
aps_ok( $amico > 0 && $acts->has_active_booking( $paid_s, $amico ) && ! $acts->has_active_booking( $paid_s, $nico ), 'il nuovo ospite creato dal cambio nominativo è prenotato' );
aps_ok( null !== aps_throws( function () use ( $front, $paid_s, $amico, $guest ) { $front::do_transfer_booking( array( 'session_id' => $paid_s, 'person_id' => $amico, 'to_person_id' => $guest ) ); } ), 'non si intesta a un ospite di un altro socio' );
aps_ok( null !== aps_throws( function () use ( $front, $paid_s, $amico ) { $front::do_transfer_booking( array( 'session_id' => $paid_s, 'person_id' => $amico ) ); } ), 'cambio nominativo senza destinatario rifiutato' );

// non si cambia nominativo dopo l'inizio dell'evento
wp_set_current_user( 1 );
$past_ev_id = $acts->create( array( 'name' => 'Evento passato', 'social_year' => $sy_label, 'kind' => 'recurring', 'fee_cents' => 500 ) );
$past_s     = $acts->add_session( $past_ev_id, array( 'session_date' => gmdate( 'Y-m-d', strtotime( $today . ' -2 days' ) ) ) );
$acts->book( $past_s, $founder );
wp_set_current_user( $u_f );
aps_ok( false !== stripos( (string) aps_throws( function () use ( $front, $past_s, $founder, $amico ) { $front::do_transfer_booking( array( 'session_id' => $past_s, 'person_id' => $founder, 'to_person_id' => $amico ) ); } ), 'iniziato' ), 'dopo l\'inizio il nominativo non si cambia più' );

// l'amministratore può cambiare nominativo a chiunque (anche a evento iniziato)
wp_set_current_user( 1 );
$acts->transfer_booking( $past_s, $founder, $vol, false );
aps_ok( $acts->has_active_booking( $past_s, $vol ) && ! $acts->has_active_booking( $past_s, $founder ), 'amministratore: cambio nominativo senza vincoli di tempo' );
aps_ok( null !== aps_throws( function () use ( $acts, $past_s, $vol ) { $acts->transfer_booking( $past_s, $vol, $vol, false ); } ), 'non si cambia verso la stessa persona' );

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
aps_ok( 1 === (int) $acts->get( $can7 )['cancellable'] && '7d' === $acts->get( $can7 )['cancel_policy'], 'evento cancellabile: salvato con il suo termine' );
aps_ok( $acts->cancellation_for( $can7_s, $founder )['allowed'], 'cancellabile (7 giorni), evento tra 10 giorni: si annulla' );
aps_ok( ! $acts->cancellation_for( $late_s, $founder )['allowed'] && 'deadline_passed' === $acts->cancellation_for( $late_s, $founder )['reason'], 'cancellabile (7 giorni), evento tra 3 giorni: termine scaduto' );
aps_ok( $acts->cancellation_for( $c24_s, $founder )['allowed'], 'cancellabile (24 ore), evento tra 3 giorni: si annulla' );
aps_ok( $acts->cancellation_for( $cdef_s, $founder )['allowed'], 'termine predefinito (48 ore), evento tra 3 giorni: si annulla' );
Settings::update( array( 'cancel_policy_default' => '7d' ) );
aps_ok( ! $acts->cancellation_for( $cdef_s, $founder )['allowed'], 'predefinito portato a una settimana: ora il termine è scaduto' );
Settings::update( array( 'cancel_policy_default' => 'boh' ) );
aps_ok( '48h' === Settings::get( 'cancel_policy_default' ), 'termine predefinito non valido: torna a 48 ore' );
wp_set_current_user( $u_f );
aps_ok( null === $cancels( $can7_s, $founder ) && ! $acts->has_active_booking( $can7_s, $founder ), 'cancellabile: l\'annullo dal sito funziona nei termini' );
aps_ok( false !== strpos( (string) $cancels( $late_s, $founder ), 'scaduto' ), 'cancellabile: dopo il termine l\'annullo è rifiutato con il motivo' );
wp_set_current_user( 1 );
$acts->cancel_booking( $late_s, $founder );
aps_ok( ! $acts->has_active_booking( $late_s, $founder ), 'amministratore: può sempre annullare' );
$html = $as( $u_f, '[apsemplice_area_soci]' );
aps_ok( false !== strpos( $html, 'Cambia nominativo' ) && false !== strpos( $html, 'aps_front_transfer_booking' ), 'area soci: pulsante Cambia nominativo' );
aps_ok( false !== strpos( $html, 'non è cancellabile' ) || false !== strpos( $html, 'Puoi annullare fino al' ), 'area soci: spiega se e fino a quando si può annullare' );

// Pagina attività: creazione con cancellabilità e pagina scheda
aps_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Cancellabile anche se a pagamento', array( 'id' => $can7 ) );
aps_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Cambia nominativo', array( 'id' => $paid_ev ) );
aps_render( array( Admin\ActivitiesPage::class, 'render_list' ), 'Cancellabile anche se a pagamento' );
$pc = new ReflectionMethod( Admin\Actions::class, 'save_activity' );
$pc->setAccessible( true );
$res = $pc->invoke( null, array( 'name' => 'Da modulo', 'social_year' => $sy_label, 'kind' => 'event', 'fee' => '4,00', 'guest_fee' => '6,00', 'cancellable' => '1', 'cancel_policy' => '24h', 'session_date' => gmdate( 'Y-m-d', strtotime( $today . ' +20 days' ) ), 'start_time' => '18:30', 'capacity' => '3' ) );
preg_match( '/id=(\d+)/', $res[0], $mm );
$form_ev = $acts->get( (int) $mm[1] );
aps_ok( 400 === (int) $form_ev['fee_cents'] && 600 === (int) $form_ev['guest_fee_cents'] && 1 === (int) $form_ev['cancellable'] && '24h' === $form_ev['cancel_policy'], 'modulo attività: evento cancellabile con termine e contributo ospiti' );
$pc->invoke( null, array( 'id' => $form_ev['id'], 'name' => 'Da modulo', 'social_year' => $sy_label, 'fee' => '4,00', 'guest_fee' => '', 'cancel_policy' => '' ) );
$form_ev = $acts->get( (int) $form_ev['id'] );
aps_ok( 0 === (int) $form_ev['cancellable'] && null === $form_ev['cancel_policy'] && null === $form_ev['guest_fee_cents'], 'modulo attività: togliere la spunta e il contributo ospiti salva davvero' );

// Impostazioni dei pagamenti: Stripe / PayPal in alternativa a WooCommerce, chiavi cifrate
Settings::update( array( 'payment_provider' => 'stripe', 'stripe_mode' => 'test', 'stripe_publishable_key' => 'pk_test_51Abc1234', 'stripe_secret_key' => 'sk_test_51Abc1234', 'stripe_webhook_secret' => 'whsec_abc1234' ) );
$raw = get_option( 'aps_settings' );
aps_ok( Secrets::is_encrypted( $raw['stripe_secret_key'] ) && false === strpos( wp_json_encode( $raw ), 'sk_test_51Abc1234' ) && false === strpos( wp_json_encode( $raw ), 'whsec_abc1234' ), 'chiavi segrete: nel database sono cifrate' );
aps_ok( 'sk_test_51Abc1234' === Settings::secret( 'stripe_secret_key' ) && Settings::has_secret( 'stripe_webhook_secret' ), 'chiavi segrete: si leggono in chiaro solo dal codice' );
Settings::update( array( 'stripe_secret_key' => '' ) );
aps_ok( 'sk_test_51Abc1234' === Settings::secret( 'stripe_secret_key' ), 'chiave segreta lasciata vuota nel modulo: resta quella salvata' );
Settings::update( array( 'stripe_secret_key' => 'sk_test_NUOVA9876' ) );
aps_ok( 'sk_test_NUOVA9876' === Settings::secret( 'stripe_secret_key' ), 'chiave segreta nuova: sostituisce la vecchia' );
Settings::update( array( 'stripe_secret_key' => 'sk_test_51Abc1234' ) );
aps_ok( array() === PaymentConfig::validate( Settings::payment_config() )['errors'], 'configurazione Stripe valida' );
$http_seen = null;
$fake      = function ( $method, $url, $headers, $body ) use ( &$http_seen ) {
	$http_seen = array( $method, $url, $headers );
	return array( 'code' => 200, 'body' => '{"livemode":false}' );
};
$g = Gateways::test( 'stripe', Settings::payment_config(), $fake );
aps_ok( $g['ok'] && 'Bearer sk_test_51Abc1234' === $http_seen[2]['Authorization'], 'prova Stripe: usa la chiave salvata (rete simulata)' );
ob_start();
Admin\SettingsPage::render();
$settings_html = ob_get_clean();
aps_ok( false === strpos( $settings_html, 'sk_test_51Abc1234' ) && false !== strpos( $settings_html, '••••1234' ) && false === strpos( $settings_html, 'whsec_abc1234' ), 'impostazioni: la chiave segreta non viene mai stampata, solo la maschera' );
aps_ok( false !== strpos( $settings_html, 'Verifica connessione Stripe' ) && false !== strpos( $settings_html, 'WooCommerce (non ancora collegato)' ) && false !== strpos( $settings_html, 'Termine predefinito per annullare' ), 'impostazioni: sezione pagamenti e cancellazioni' );
Settings::update( array( 'payment_provider' => 'paypal', 'paypal_mode' => 'sandbox', 'paypal_client_id' => str_repeat( 'A', 40 ), 'paypal_client_secret' => str_repeat( 'b', 40 ) ) );
aps_ok( array() === PaymentConfig::validate( Settings::payment_config() )['errors'], 'configurazione PayPal valida' );
Settings::update( array( 'payment_provider' => 'bogus' ) );
aps_ok( 'none' === Settings::get( 'payment_provider' ), 'gateway non valido: si torna a "nessuno"' );
Settings::clear_secret( 'stripe_secret_key' );
aps_ok( ! Settings::has_secret( 'stripe_secret_key' ) && '' === Settings::secret( 'stripe_secret_key' ), 'chiave segreta rimossa' );
aps_ok( in_array( 'settings.secret_cleared', array_column( Audit::recent( 50 ), 'action' ), true ) && false === strpos( wp_json_encode( Audit::recent( 200 ) ), 'sk_test' ), 'registro azioni: nessuna chiave dentro' );
Settings::update( array( 'payment_provider' => 'none' ) );
wp_set_current_user( 1 );

// ---------- Tutto dal pannello: aspetto, messaggi, chiavi (nessun file da modificare) ----------
wp_set_current_user( 1 );
Settings::update( array( 'accent_color' => '#C0392B' ) );
aps_ok( '#c0392b' === Settings::get( 'accent_color' ), 'colore d\'accento: salvato normalizzato' );
$css = \ApSemplice\Frontend\Assets::inline_css();
aps_ok( false !== strpos( $css, '--apsf-accent:#c0392b' ) && false !== strpos( $css, '--apsf-accent-text:#ffffff' ), 'colore d\'accento: diventa lo stile del front-end, con testo leggibile' );
Settings::update( array( 'accent_color' => 'rosso' ) );
aps_ok( '' === Settings::get( 'accent_color' ) && '' === \ApSemplice\Frontend\Assets::inline_css(), 'colore non valido: si torna al colore del tema' );

Settings::update( array( 'payment_hint' => 'Paga con bonifico a IT00X.' ) );
aps_ok( false !== strpos( $as( $u_ord, '[apsemplice_area_soci]' ), 'Paga con bonifico a IT00X.' ), 'invito al pagamento: testo scelto nelle impostazioni' );
Settings::update( array( 'payment_hint' => '   ' ) );
aps_ok( Settings::DEFAULT_PAYMENT_HINT === Settings::payment_hint(), 'invito al pagamento vuoto: torna quello predefinito' );

Settings::update( array( 'gate_message' => 'Area dedicata ai nostri soci.' ) );
aps_ok( false !== strpos( $show( $p_mem, 0 ), 'Area dedicata ai nostri soci.' ), 'messaggio sui contenuti riservati: testo scelto nelle impostazioni' );
Settings::update( array( 'gate_message' => '' ) );
aps_ok( false !== strpos( $show( $p_mem, 0 ), 'Contenuto riservato ai soci' ), 'messaggio sui contenuti riservati vuoto: automatico' );

License::set_state( 'unpaid', $today, 'https://licenze.example/paga?k=1' );
aps_ok( false !== strpos( Admin\LicenseNotice::html(), 'https://licenze.example/paga?k=1' ), 'popup licenza: usa l\'indirizzo di pagamento comunicato dal servizio' );
delete_option( License::OPT_STATE );
aps_ok( false === strpos( Admin\LicenseNotice::html(), 'licenze.example' ), 'popup licenza: niente popup con la licenza in regola' );

// il modulo delle impostazioni salva tutto
$ss = new ReflectionMethod( Admin\Actions::class, 'save_settings' );
$ss->setAccessible( true );
$form = array(
	'association_name' => 'APS Prova', 'social_year_start_month' => '9', 'membership_fee' => '12,00', 'founder_years' => '99',
	'member_area_page_id' => (string) Settings::get( 'member_area_page_id' ), 'cancel_policy_default' => '24h',
	'payment_hint' => 'Ciao', 'gate_message' => '', 'payment_provider' => 'stripe', 'stripe_mode' => 'test',
	'stripe_publishable_key' => 'pk_test_51Zzz', 'stripe_secret_key' => 'sk_test_51Zzz', 'stripe_webhook_secret' => 'whsec_zzz',
	'accent_custom' => '1', 'accent_color' => '#336699',
);
$ss->invoke( null, $form );
aps_ok( '#336699' === Settings::get( 'accent_color' ) && 'Ciao' === Settings::payment_hint() && '24h' === Settings::get( 'cancel_policy_default' ) && 1200 === (int) Settings::get( 'membership_fee_cents' ) && 'sk_test_51Zzz' === Settings::secret( 'stripe_secret_key' ), 'modulo impostazioni: salva aspetto, messaggi, termini e chiavi' );
$form2 = array_merge( $form, array( 'stripe_secret_key' => '', 'stripe_webhook_secret' => '' ) );
unset( $form2['accent_custom'] );
$ss->invoke( null, $form2 );
aps_ok( '' === Settings::get( 'accent_color' ) && 'sk_test_51Zzz' === Settings::secret( 'stripe_secret_key' ), 'modulo senza la spunta del colore: torna al tema; chiave lasciata vuota: resta quella salvata' );
$ss->invoke( null, array_merge( $form2, array( 'clear_stripe_secret_key' => '1' ) ) );
aps_ok( ! Settings::has_secret( 'stripe_secret_key' ) && Settings::has_secret( 'stripe_webhook_secret' ), 'modulo: la spunta "rimuovi" toglie solo quella chiave' );

// una chiave cifrata da un altro sito (database copiato) non si legge qui, e il pannello lo dice
$opt                         = get_option( 'aps_settings' );
$opt['stripe_secret_key']    = Secrets::encrypt( 'sk_test_ALTRO', 'sale-di-un-altro-sito' );
update_option( 'aps_settings', $opt );
aps_ok( Settings::has_secret( 'stripe_secret_key' ) && Settings::secret_unreadable( 'stripe_secret_key' ) && '' === Settings::secret( 'stripe_secret_key' ), 'chiave cifrata da un altro sito: segnalata come non leggibile' );
ob_start();
Admin\SettingsPage::render();
$h = ob_get_clean();
aps_ok( false !== strpos( $h, 'non è leggibile su questo sito' ) && false === strpos( $h, 'wp-config' ) && false === strpos( $h, 'sk_test_ALTRO' ), 'pannello: avviso sulla chiave illeggibile, nessun file da modificare' );
aps_ok( false !== strpos( $h, 'Aspetto e messaggi del sito' ) && false !== strpos( $h, 'type="color"' ), 'pannello: sezione aspetto con selettore colore' );
Settings::clear_secret( 'stripe_secret_key' );
Settings::clear_secret( 'stripe_webhook_secret' );
Settings::update( array( 'payment_provider' => 'none', 'accent_color' => '', 'cancel_policy_default' => '48h' ) );
wp_set_current_user( 1 );
$csv = Admin\Exports::ledger( $year . '-01-01', $year . '-12-31' )[1];
aps_ok( false !== strpos( $csv, 'N. tessera' ) && false !== strpos( $csv, 'Rimborso' ), 'export prima nota' );
aps_ok( false !== strpos( Admin\Exports::people()[1], 'fulvia@example.com' ), 'export soci' );

// ---------- Import CSV: piano ----------
$existing = array();
foreach ( $people->search() as $e ) {
	$existing[] = array( 'id' => (int) $e['id'], 'card' => $e['card_number'], 'first' => $e['first_name'], 'last' => $e['last_name'], 'email' => $e['email'], 'tax' => $e['tax_code'] );
}
$parsed = PeopleCsv::parse( "Tessera;Tipo;Nome;Cognome;Email\n3;ordinario;Omar;Ordini;omar@example.com\n50;volontario;Nuova;Persona;nuova@example.com\n3;ordinario;Dup;Licato;dup@example.com\n" );
$plan   = PeopleCsv::plan( $parsed['rows'], $existing );
aps_ok( 'update' === $plan[0]['action'] && 'create' === $plan[1]['action'] && 'error' === $plan[2]['action'], 'import: aggiorna, crea, scarta duplicati' );

// ---------- Eliminazione ----------
$people->delete( $guest );
$people->delete( $ord );
$again = $people->create( array( 'type' => 'ordinary', 'card_number' => '3', 'first_name' => 'Nuovo', 'last_name' => 'Titolare', 'email' => 'omar@example.com' ) );
aps_ok( $again > 0, 'eliminando un socio si libera tessera ed email' );

// ---------- Render di tutte le pagine ----------
$_SERVER['REQUEST_METHOD'] = 'GET';
aps_render( array( Admin\DashboardPage::class, 'render' ), 'Disponibilità' );
aps_render( array( Admin\PeoplePage::class, 'render_list' ), 'Fondi' );
aps_render( array( Admin\PeoplePage::class, 'render_list' ), 'Veronica', array( 'type' => 'volunteer', 'q' => 'volta' ) );
aps_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Tessera e iscrizione', array( 'id' => $founder ) );
aps_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Attività e pagamenti', array( 'id' => $vol ) );
aps_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Nuovo ospite', array( 'type' => 'guest' ) );
aps_render( array( Admin\ActivitiesPage::class, 'render_list' ), 'Yoga' );
aps_render( array( Admin\ActivitiesPage::class, 'render_list' ), 'Serata giochi' );
aps_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Data e prenotazioni', array( 'id' => $event ) );
aps_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Date e prenotazioni', array( 'id' => $rec ) );
aps_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Iscritti e pagamenti', array( 'id' => $corso ) );
aps_render( array( Admin\PeoplePage::class, 'render_edit' ), 'Eventi e prenotazioni', array( 'id' => $founder ) );
aps_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Iscritti e pagamenti', array( 'id' => $yoga ) );
aps_render( array( Admin\IncomePage::class, 'render' ), 'aps-income-data' );
aps_render( array( Admin\ExpensePage::class, 'render' ), 'Registra spesa' );
aps_render( array( Admin\TransferPage::class, 'render' ), 'Registra giroconto' );
aps_render( array( Admin\LedgerPage::class, 'render' ), 'Rimborso istruttrice' );
aps_render( array( Admin\AccountsPage::class, 'render' ), 'Verifica saldo' );
aps_render( array( Admin\ReportsPage::class, 'render' ), 'Saldi dei conti' );
aps_render( array( Admin\ReportsPage::class, 'render' ), 'Soci iscritti', array( 'mode' => 'social' ) );
aps_render( array( Admin\SettingsPage::class, 'render' ), 'Chiave di licenza' );
aps_render( array( Admin\SettingsPage::class, 'render' ), 'Crea le pagine standard' );
aps_render( array( Admin\AuditPage::class, 'render' ), 'Registro azioni' );
aps_render( array( Admin\ImportPage::class, 'render' ), 'Importa soci da CSV' );

aps_ok( ! $GLOBALS['aps_warnings'], 'nessun warning/notice/deprecation PHP dal plugin' . ( $GLOBALS['aps_warnings'] ? ': ' . implode( ' | ', array_slice( $GLOBALS['aps_warnings'], 0, 5 ) ) : '' ) );

WP_CLI::success( 'Tutti i controlli sono passati.' );
