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
aps_render( array( Admin\ActivitiesPage::class, 'render_detail' ), 'Iscritti e pagamenti', array( 'id' => $yoga ) );
aps_render( array( Admin\IncomePage::class, 'render' ), 'aps-income-data' );
aps_render( array( Admin\ExpensePage::class, 'render' ), 'Registra spesa' );
aps_render( array( Admin\TransferPage::class, 'render' ), 'Registra giroconto' );
aps_render( array( Admin\LedgerPage::class, 'render' ), 'Rimborso istruttrice' );
aps_render( array( Admin\AccountsPage::class, 'render' ), 'Verifica saldo' );
aps_render( array( Admin\ReportsPage::class, 'render' ), 'Saldi dei conti' );
aps_render( array( Admin\ReportsPage::class, 'render' ), 'Soci iscritti', array( 'mode' => 'social' ) );
aps_render( array( Admin\SettingsPage::class, 'render' ), 'Chiave di licenza' );
aps_render( array( Admin\AuditPage::class, 'render' ), 'Registro azioni' );
aps_render( array( Admin\ImportPage::class, 'render' ), 'Importa soci da CSV' );

aps_ok( ! $GLOBALS['aps_warnings'], 'nessun warning/notice/deprecation PHP dal plugin' . ( $GLOBALS['aps_warnings'] ? ': ' . implode( ' | ', array_slice( $GLOBALS['aps_warnings'], 0, 5 ) ) : '' ) );

WP_CLI::success( 'Tutti i controlli sono passati.' );
