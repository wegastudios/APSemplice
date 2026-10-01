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
apse_ok( count( Plugin::ledger()->categories() ) >= 9, 'categorie iniziali' );

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
apse_ok( ! empty( $fp['wp_user_id'] ), 'il socio fondatore ha un utente WordPress' );
$wpu = get_userdata( (int) $fp['wp_user_id'] );
apse_ok( 'fulvia@example.com' === $wpu->user_email, 'email dell\'utente = email del socio (minuscola)' );
apse_ok( array( Plugin::ROLE_MEMBER ) === array_values( $wpu->roles ), 'ruolo Socio APS, nessun accesso admin' );
apse_ok( ! user_can( $wpu, Plugin::CAP ), 'il socio non può gestire il plugin' );
apse_ok( $people->is_active_member( $founder ), 'fondatore: tessera sempre valida' );
apse_ok( $people->active_until( $founder ) > gmdate( 'Y-m-d', strtotime( '+90 years' ) ), 'fondatore: scadenza a 99 anni' );

apse_ok( null !== apse_throws( function () use ( $people ) { $people->create( array( 'type' => 'ordinary', 'first_name' => 'A', 'last_name' => 'B', 'email' => '' ) ); } ), 'email obbligatoria per i soci' );
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
$guest = $people->create( array( 'type' => 'guest', 'first_name' => 'Gino', 'last_name' => 'Ospiti', 'host_person_id' => $ord ) );
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
$ledger->record_expense( array( 'date' => $today, 'account_id' => (int) $cash['id'], 'method' => 'cash', 'category_id' => $cat['instructor_reimbursement'], 'amount_cents' => 1500, 'activity_id' => $yoga, 'person_id' => $vol, 'description' => 'Rimborso istruttrice' ) );
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

// ---------- Licenza non in regola: popup e blocchi ----------
apse_ok( License::allows( 'export' ) && License::allows( 'member_area' ) && '' === Admin\LicenseNotice::html(), 'licenza in standby: nessun blocco e nessun popup' );
License::set_state( 'unpaid', $today );
$pol = License::policy();
apse_ok( 'closable' === $pol['popup'] && 7 === $pol['days_left'], 'licenza non pagata: popup chiudibile per 7 giorni' );
apse_ok( ! License::allows( 'export' ) && ! License::allows( 'member_area' ), 'licenza non pagata: export e accesso soci bloccati subito' );
apse_ok( false !== strpos( Admin\LicenseNotice::html(), 'apse-overlay-close' ), 'popup con pulsante di chiusura' );
apse_ok( false !== strpos( Admin\Exports::link( 'people', array(), 'Esporta' ), 'disabled' ), 'pulsanti di esportazione disattivati' );
apse_ok( user_can( 1, 'apse_view_participants', $yoga ), 'amministratore: i permessi restano' );
apse_ok( ! user_can( $u_vol, 'apse_view_participants', $yoga ) && ! user_can( $u_ord, 'apse_view_person', $ord ), 'volontari e soci: nessun permesso' );
$r = apse_rest( $u_ord, '/apsemplice/v1/me' );
apse_ok( 403 === $r->get_status() && 'apse_license_required' === $r->get_data()['code'], 'REST: i soci ricevono "servizio sospeso"' );
apse_ok( 403 === apse_rest( $u_vol, "/apsemplice/v1/activities/$yoga/participants" )->get_status(), 'REST: il volontario è sospeso' );
apse_ok( 200 === apse_rest( 1, '/apsemplice/v1/me' )->get_status(), 'REST: l\'amministratore resta operativo' );
License::set_state( 'unpaid', gmdate( 'Y-m-d', strtotime( $today . ' -7 days' ) ) );
apse_ok( 'locked' === License::policy()['popup'] && false === strpos( Admin\LicenseNotice::html(), 'apse-overlay-close' ), 'dopo una settimana il popup non si chiude più' );
License::set_state( 'unpaid', gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) ) );
apse_ok( 'closable' === License::policy()['popup'] && 1 === License::policy()['days_left'], 'al sesto giorno è ancora chiudibile' );
License::set_state( 'unlicensed', $today );
apse_ok( false !== strpos( Admin\LicenseNotice::html(), 'non risulta più associato' ), 'dominio non più associato: messaggio dedicato' );
License::set_state( 'active' );
apse_ok( License::allows( 'export' ) && '' === Admin\LicenseNotice::html() && 200 === apse_rest( $u_ord, '/apsemplice/v1/me' )->get_status(), 'licenza regolarizzata: tutto torna disponibile' );
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
$front::do_add_guest( array( 'first_name' => 'Gia', 'last_name' => 'Ospite', 'phone' => '333' ) );
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
$front::do_profile( array( 'phone' => '3331112222', 'tax_code' => 'abcdef12g34h567i' ) );
apse_ok( '3331112222' === $people->get( $founder )['phone'] && 'ABCDEF12G34H567I' === $people->get( $founder )['tax_code'], 'sito: il socio aggiorna il proprio profilo' );
License::set_state( 'unpaid', $today );
apse_ok( null !== apse_throws( function () use ( $front, $s3, $founder ) { $front::do_book( array( 'session_id' => $s3, 'person_id' => $founder ) ); } ), 'licenza non in regola: le azioni dei soci sono sospese' );
apse_ok( false !== strpos( $as( $u_f, '[apsemplice_area_soci]' ), 'sospeso' ), 'licenza non in regola: l\'area soci mostra "servizio sospeso"' );
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
License::set_state( 'unpaid', $today );
apse_ok( ! $sees( $p_mem, $u_ord ) && $sees( $p_pub, $u_ord ) && $sees( $p_mem, 1 ), 'riservati: licenza non in regola = i soci non vedono i contenuti riservati' );
delete_option( License::OPT_STATE );

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

// Elementor (installato nel test): i widget si registrano e i controlli si costruiscono
apse_ok( class_exists( '\Elementor\Plugin' ), 'Elementor è presente nell\'ambiente di test' );
$el_widgets = \Elementor\Plugin::instance()->widgets_manager->get_widget_types();
apse_ok( isset( $el_widgets['apsemplice_view'] ) && isset( $el_widgets['apsemplice_reserved'] ), 'Elementor: widget APSemplice registrati' );
apse_ok( array_key_exists( 'view', $el_widgets['apsemplice_view']->get_controls() ) && array_key_exists( 'rule', $el_widgets['apsemplice_reserved']->get_controls() ), 'Elementor: i controlli dei widget si costruiscono' );
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
$front::do_add_guest( array( 'first_name' => 'Nico', 'last_name' => 'Ospite' ) );
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
$msg = $front::do_transfer_booking( array( 'session_id' => $paid_s, 'person_id' => $nico, 'new_first_name' => 'Nuovo', 'new_last_name' => 'Amico' ) );
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
Admin\SettingsPage::render();
$settings_html = ob_get_clean();
apse_ok( false === strpos( $settings_html, 'sk_test_51Abc1234' ) && false !== strpos( $settings_html, '••••1234' ) && false === strpos( $settings_html, 'whsec_abc1234' ), 'impostazioni: la chiave segreta non viene mai stampata, solo la maschera' );
apse_ok( false !== strpos( $settings_html, 'Verifica connessione Stripe' ) && false !== strpos( $settings_html, 'WooCommerce (non ancora collegato)' ) && false !== strpos( $settings_html, 'Termine predefinito per annullare' ), 'impostazioni: sezione pagamenti e cancellazioni' );
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
apse_ok( '' === Settings::get( 'accent_color' ) && '' === \ApSemplice\Frontend\Assets::inline_css(), 'colore non valido: si torna al colore del tema' );

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
	'payment_hint' => 'Ciao', 'gate_message' => '', 'payment_provider' => 'stripe', 'stripe_mode' => 'test',
	'stripe_publishable_key' => 'pk_test_51Zzz', 'stripe_secret_key' => 'sk_test_51Zzz', 'stripe_webhook_secret' => 'whsec_zzz',
	'accent_custom' => '1', 'accent_color' => '#336699',
);
$ss->invoke( null, $form );
apse_ok( '#336699' === Settings::get( 'accent_color' ) && 'Ciao' === Settings::payment_hint() && '24h' === Settings::get( 'cancel_policy_default' ) && 1200 === (int) Settings::get( 'membership_fee_cents' ) && 'sk_test_51Zzz' === Settings::secret( 'stripe_secret_key' ), 'modulo impostazioni: salva aspetto, messaggi, termini e chiavi' );
$form2 = array_merge( $form, array( 'stripe_secret_key' => '', 'stripe_webhook_secret' => '' ) );
unset( $form2['accent_custom'] );
$ss->invoke( null, $form2 );
apse_ok( '' === Settings::get( 'accent_color' ) && 'sk_test_51Zzz' === Settings::secret( 'stripe_secret_key' ), 'modulo senza la spunta del colore: torna al tema; chiave lasciata vuota: resta quella salvata' );
$ss->invoke( null, array_merge( $form2, array( 'clear_stripe_secret_key' => '1' ) ) );
apse_ok( ! Settings::has_secret( 'stripe_secret_key' ) && Settings::has_secret( 'stripe_webhook_secret' ), 'modulo: la spunta "rimuovi" toglie solo quella chiave' );

// una chiave cifrata da un altro sito (database copiato) non si legge qui, e il pannello lo dice
$opt                         = get_option( 'apse_settings' );
$opt['stripe_secret_key']    = Secrets::encrypt( 'sk_test_ALTRO', 'sale-di-un-altro-sito' );
update_option( 'apse_settings', $opt );
apse_ok( Settings::has_secret( 'stripe_secret_key' ) && Settings::secret_unreadable( 'stripe_secret_key' ) && '' === Settings::secret( 'stripe_secret_key' ), 'chiave cifrata da un altro sito: segnalata come non leggibile' );
ob_start();
Admin\SettingsPage::render();
$h = ob_get_clean();
apse_ok( false !== strpos( $h, 'non è leggibile su questo sito' ) && false === strpos( $h, 'wp-config' ) && false === strpos( $h, 'sk_test_ALTRO' ), 'pannello: avviso sulla chiave illeggibile, nessun file da modificare' );
apse_ok( false !== strpos( $h, 'Aspetto e messaggi del sito' ) && false !== strpos( $h, 'type="color"' ), 'pannello: sezione aspetto con selettore colore' );
Settings::clear_secret( 'stripe_secret_key' );
Settings::clear_secret( 'stripe_webhook_secret' );
Settings::update( array( 'payment_provider' => 'none', 'accent_color' => '', 'cancel_policy_default' => '48h' ) );
wp_set_current_user( 1 );
$csv = Admin\Exports::ledger( $year . '-01-01', $year . '-12-31' )[1];
apse_ok( false !== strpos( $csv, 'N. tessera' ) && false !== strpos( $csv, 'Rimborso' ), 'export prima nota' );
apse_ok( false !== strpos( Admin\Exports::people()[1], 'fulvia@example.com' ), 'export soci' );

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

Settings::update( array( 'payment_provider' => 'stripe', 'stripe_mode' => 'test', 'stripe_publishable_key' => 'pk_test_51Smoke1', 'stripe_secret_key' => 'sk_test_51Smoke1', 'stripe_webhook_secret' => 'whsec_smoke123' ) );
apse_ok( 'stripe' === $ps->provider() && $ps->enabled(), 'pagamenti online: Stripe attivo con la configurazione completa' );

// dati dedicati: un socio senza tessera valida con un ospite, un corso e un evento
$pm   = $people->create( array( 'type' => 'ordinary', 'first_name' => 'Pia', 'last_name' => 'Pagante', 'email' => 'pia.pagante@example.com' ) );
$pmp  = $people->get( $pm );
$u_pm = (int) $pmp['wp_user_id'];
$pg   = $people->create( array( 'type' => 'guest', 'first_name' => 'Gigi', 'last_name' => 'Pagante', 'host_person_id' => $pm ) );
$pcourse = $acts->create( array( 'name' => 'Ceramica', 'social_year' => $sy_label, 'kind' => 'course', 'fee_cents' => 2000, 'guest_fee_cents' => 1500 ) );
$acts->enroll( $pcourse, $pm, $month );
$acts->enroll( $pcourse, $pg, $month );
$pev   = $mkev( 'Cena online', 500, 800 );
$pev_s = $first_session( $pev );
$acts->book( $pev_s, $pm );
$acts->book( $pev_s, $pg );
$k_q   = "q:$pm:$sy_label";
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
$redirect = \ApSemplice\Frontend\Actions::do_pay( array( 'items' => array( "q:$q:$sy_label" ) ) );
apse_ok( 0 === strpos( $redirect, 'https://checkout.stripe.com/' ), 'azione dal sito: porta alla pagina di pagamento ospitata' );
$open = $latest();

// scadenza: un pagamento rimasto in sospeso per giorni
$wpdb->update( Db::t( 'payments' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-5 days' ) ) ), array( 'id' => (int) $open['id'] ) );
$res = $ps->check_pending( true );
apse_ok( $res['checked'] >= 1 && $res['expired'] >= 1 && 'expired' === $ps->get_by_public( $open['public_id'] )['status'], 'controllo periodico: i pagamenti mai confermati scadono dopo qualche giorno' );

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

// pagina di amministrazione e impostazioni
wp_set_current_user( 1 );
$_SERVER['REQUEST_METHOD'] = 'GET';
apse_render( array( Admin\PaymentsPage::class, 'render' ), 'Verifica i pagamenti in sospeso' );
$adm = apse_render( array( Admin\PaymentsPage::class, 'render' ), 'da controllare', array( 'review' => '1' ) );
apse_ok( false !== strpos( $adm, 'Pagamento online' ) || false !== strpos( $adm, 'Importo pagato' ), 'amministrazione: i pagamenti da controllare mostrano il motivo' );
apse_render( array( Admin\SettingsPage::class, 'render' ), 'checkout.session.async_payment_succeeded' );
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
apse_render( array( Admin\ImportPage::class, 'render' ), 'Importa soci da CSV' );

apse_ok( ! $GLOBALS['apse_warnings'], 'nessun warning/notice/deprecation PHP dal plugin' . ( $GLOBALS['apse_warnings'] ? ': ' . implode( ' | ', array_slice( $GLOBALS['apse_warnings'], 0, 5 ) ) : '' ) );

WP_CLI::success( 'Tutti i controlli sono passati.' );
