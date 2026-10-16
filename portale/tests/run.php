<?php
// Prove del portale senza dipendenze: php tests/run.php (esce con codice 1 se qualcosa fallisce).
require __DIR__ . '/../src/bootstrap.php';

use Portale\{Billing, Config, Db, Domain, Http, Mailer, Stripe, UserError, WegaClient, WegaException, Webhooks};

$fails = 0;
function check( string $name, bool $ok ): void {
	global $fails;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n";
	if ( ! $ok ) {
		++$fails;
	}
}
function throwsUser( callable $f ): ?string {
	try {
		$f();
	} catch ( UserError $e ) {
		return $e->getMessage();
	}
	return null;
}

Config::load( array(
	'base_url' => 'https://portale.test', 'app_key' => base64_encode( str_repeat( 'k', 32 ) ),
	'stripe' => array( 'secret_key' => 'sk_test', 'webhook_secret' => 'whsec_test' ),
	'paypal' => array( 'live' => false, 'client_id' => 'id', 'secret' => 's', 'webhook_id' => 'WH1' ),
	'duration_days' => array( 'month' => 31, 'year' => 366, 'free' => 3650 ), 'renew_window_days' => 5,
	'plans' => array(
		'free' => array( 'label' => 'Free', 'plan_name' => 'Free', 'prices' => array() ),
		'full' => array( 'label' => 'Pro Full', 'plan_name' => 'Pro Full', 'prices' => array(
			'year' => array( 'cents' => 11900, 'stripe_price' => 'price_1', 'paypal_plan' => 'P-1' ),
		) ),
	),
) );

// --- Dominio -------------------------------------------------------------
check( 'dominio: schema, www, percorso e porta', 'esempio.it' === Domain::normalize( 'https://WWW.Esempio.it:8080/a/b?x=1' ) );
check( 'dominio: sottodominio resta', 'staging.esempio.it' === Domain::normalize( 'staging.esempio.it' ) );
check( 'dominio: IP rifiutato', '' === Domain::normalize( '192.168.1.5' ) );
check( 'dominio: wildcard rifiutato', '' === Domain::normalize( '*.esempio.it' ) );
check( 'dominio: senza punto rifiutato', '' === Domain::normalize( 'localhost' ) );
check( 'dominio: spazi e punti finali', 'esempio.it' === Domain::normalize( '  esempio.it. ' ) );

// --- Stripe: firma ---------------------------------------------------------
$payload = '{"id":"evt_1"}';
$t       = 1700000000;
$sig     = 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $payload, 'whsec_test' );
check( 'stripe: firma valida', Stripe::verify( $payload, $sig, 'whsec_test', $t + 10 ) );
check( 'stripe: firma con corpo alterato', ! Stripe::verify( $payload . ' ', $sig, 'whsec_test', $t + 10 ) );
check( 'stripe: firma scaduta', ! Stripe::verify( $payload, $sig, 'whsec_test', $t + 1000 ) );
check( 'stripe: segreto vuoto', ! Stripe::verify( $payload, $sig, '', $t ) );

// --- Server finto -----------------------------------------------------------
$calls = array();
$state = array( 'expires' => '2027-01-01T00:00:00Z', 'bind_error' => null );
Http::$transport = function ( $method, $url, $headers, $body ) use ( &$calls, &$state ) {
	$calls[] = array( $method, $url, null === $body ? null : ( json_decode( $body, true ) ?: $body ) );
	if ( false !== strpos( $url, '/licenses/renew' ) ) {
		return array( 200, json_encode( array( 'data' => array( 'expires_at' => '2028-01-01T00:00:00Z' ) ) ) );
	}
	if ( false !== strpos( $url, '/binding' ) ) {
		return $state['bind_error']
			? array( 429, json_encode( array( 'error' => array( 'code' => $state['bind_error'], 'message' => 'x' ) ) ) )
			: array( 200, json_encode( array( 'data' => array() ) ) );
	}
	if ( false !== strpos( $url, '/licenses' ) ) {
		return array( 201, json_encode( array( 'data' => array( 'license_id' => 'lic_1', 'license_key' => 'WEGA-AAAA', 'expires_at' => $state['expires'] ) ) ) );
	}
	if ( false !== strpos( $url, '/oauth2/token' ) ) {
		return array( 200, '{"access_token":"tok"}' );
	}
	if ( false !== strpos( $url, 'verify-webhook-signature' ) ) {
		return array( 200, '{"verification_status":"SUCCESS"}' );
	}
	if ( false !== strpos( $url, '/checkout/sessions' ) ) {
		return array( 200, '{"url":"https://stripe.test/pay"}' );
	}
	if ( false !== strpos( $url, '/v2/checkout/orders' ) ) {
		return array( 200, '{"id":"ORD1","links":[{"rel":"approve","href":"https://paypal.test/approve"}]}' );
	}
	if ( false !== strpos( $url, '/v1/billing/subscriptions' ) ) {
		return array( 200, '{"links":[{"rel":"approve","href":"https://paypal.test/sub"}]}' );
	}
	return array( 404, '{}' );
};
$mails = array();
Mailer::$sender = function ( $to, $subject, $body ) use ( &$mails ) {
	$mails[] = array( $to, $subject, $body );
	return true;
};

$db = new Db( new PDO( 'sqlite::memory:' ) );
$db->migrate();
$billing = new Billing( $db, new WegaClient( 'https://license.test/api/v1', 'wic_x.y' ) );

// --- Licenza free -----------------------------------------------------------
list( $cust, $sub ) = $billing->startFree( 'Anna@Esempio.it', 'https://www.mia-associazione.it/' );
check( 'free: cliente in minuscolo', 'anna@esempio.it' === $cust['email'] );
check( 'free: in attesa finché non conferma', 'pending' === $billing->subscription( $sub['id'] )['status'] && empty( $billing->subscription( $sub['id'] )['license_id'] ) );
check( 'free: dominio già preso da altri', null !== throwsUser( function () use ( $billing ) {
	$billing->startFree( 'altro@esempio.it', 'mia-associazione.it' );
} ) );
check( 'free: dominio non valido', null !== throwsUser( function () use ( $billing ) {
	$billing->startFree( 'x@esempio.it', '10.0.0.1' );
} ) );
check( 'email non valida', null !== throwsUser( function () use ( $billing ) {
	$billing->startFree( 'non-una-email', 'esempio.org' );
} ) );

$active = $billing->activate( $sub['id'] );
$create = null;
foreach ( $calls as $c ) {
	if ( 'POST' === $c[0] && substr( $c[1], -9 ) === '/licenses' ) {
		$create = $c[2];
	}
}
check( 'free: licenza tipo 3 con dominio e riferimento idempotente', $create && 3 === $create['type'] && 'mia-associazione.it' === $create['domain'] && 'sub-' . $sub['id'] === $create['external_ref'] && 3650 === $create['duration_days'] && 'Free' === $create['plan_name'] );
check( 'free: attiva e scadenza salvata', 'active' === $active['status'] && '2027-01-01 00:00:00' === $active['expires_at'] );
check( 'free: chiave cifrata nel database', false === strpos( $active['license_key_enc'], 'WEGA-AAAA' ) && 'WEGA-AAAA' === Portale\Secrets::open( $active['license_key_enc'] ) );
$n = count( $calls );
$billing->activate( $sub['id'] );
check( 'free: riattivare non richiama il server', count( $calls ) === $n );

// --- Cambio dominio -----------------------------------------------------------
$billing->changeDomain( $sub['id'], $cust['id'], 'nuovo.it' );
check( 'dominio: cambio salvato', 'nuovo.it' === $billing->subscription( $sub['id'] )['domain'] );
check( 'dominio: cliente diverso rifiutato', null !== throwsUser( function () use ( $billing, $sub ) {
	$billing->changeDomain( $sub['id'], 'altro', 'x.it' );
} ) );
$state['bind_error'] = 'BINDING_CHANGE_LIMIT';
$msg = throwsUser( function () use ( $billing, $sub, $cust ) {
	$billing->changeDomain( $sub['id'], $cust['id'], 'ancora.it' );
} );
check( 'dominio: limite 3 cambi spiegato', null !== $msg && false !== strpos( $msg, '3 volte' ) && 'nuovo.it' === $billing->subscription( $sub['id'] )['domain'] );
$state['bind_error'] = null;

// --- Stripe: acquisto, rinnovo, doppioni ------------------------------------
$hooks = new Webhooks( $db, $billing );
$send  = function ( array $event ) use ( $hooks ) {
	$body = json_encode( $event );
	$ts   = time();
	return $hooks->stripe( $body, 't=' . $ts . ',v1=' . hash_hmac( 'sha256', $ts . '.' . $body, 'whsec_test' ) );
};
$c2 = $billing->customer( 'bruno@esempio.it' );
$db->run( "INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, created_at, updated_at) VALUES ('s2', ?, 'full', 'year', 'stripe', 'pending', 'bruno.it', ?, ?)", array( $c2['id'], Db::now(), Db::now() ) );
$mails = array();
check( 'stripe: firma errata rifiutata', 400 === $hooks->stripe( '{"id":"e","type":"x"}', 't=1,v1=00' ) );
check( 'stripe: checkout completato', 200 === $send( array( 'id' => 'evt_a', 'type' => 'checkout.session.completed', 'data' => array( 'object' => array( 'mode' => 'subscription', 'client_reference_id' => 's2', 'subscription' => 'sub_9', 'customer' => 'cus_9' ) ) ) ) );
$s2 = $billing->subscription( 's2' );
check( 'stripe: attivo con riferimenti del provider', 'active' === $s2['status'] && 'sub_9' === $s2['provider_ref'] && 'cus_9' === $s2['provider_customer'] );
check( 'stripe: email con il link inviata una volta', 1 === count( $mails ) && 'bruno@esempio.it' === $mails[0][0] && false !== strpos( $mails[0][2], 'https://portale.test/entra/' ) );
$ev = array( 'id' => 'evt_b', 'type' => 'invoice.paid', 'data' => array( 'object' => array( 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_9' ) ) );
$send( $ev );
check( 'stripe: rinnovo aggiorna la scadenza', '2028-01-01 00:00:00' === $billing->subscription( 's2' )['expires_at'] );
$n = count( $calls );
check( 'stripe: consegna ripetuta ignorata', 200 === $send( $ev ) && count( $calls ) === $n );
$send( array( 'id' => 'evt_c', 'type' => 'invoice.paid', 'data' => array( 'object' => array( 'billing_reason' => 'subscription_create', 'subscription' => 'sub_9' ) ) ) );
check( 'stripe: primo pagamento non conta come rinnovo', count( $calls ) === $n );
$send( array( 'id' => 'evt_d', 'type' => 'invoice.payment_failed', 'data' => array( 'object' => array( 'subscription' => 'sub_9' ) ) ) );
check( 'stripe: pagamento fallito', 'past_due' === $billing->subscription( 's2' )['status'] );
$send( array( 'id' => 'evt_e', 'type' => 'customer.subscription.deleted', 'data' => array( 'object' => array( 'id' => 'sub_9' ) ) ) );
check( 'stripe: abbonamento annullato', 'canceled' === $billing->subscription( 's2' )['status'] );
$n = count( $calls );
$send( array( 'id' => 'evt_f', 'type' => 'invoice.paid', 'data' => array( 'object' => array( 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_9' ) ) ) );
check( 'stripe: nessun rinnovo se annullato', count( $calls ) === $n );

// --- PayPal: il primo pagamento non raddoppia ----------------------------------
$c3 = $billing->customer( 'carla@esempio.it' );
$db->run( "INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, created_at, updated_at) VALUES ('s3', ?, 'full', 'year', 'paypal', 'pending', 'carla.it', ?, ?)", array( $c3['id'], Db::now(), Db::now() ) );
$state['expires'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + 366 * 86400 );
$pp = function ( array $event ) use ( $hooks ) {
	return $hooks->paypal( json_encode( $event ), array( 'paypal-transmission-id' => 'x' ) );
};
$pp( array( 'id' => 'WH-1', 'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED', 'resource' => array( 'id' => 'I-1', 'custom_id' => 's3' ) ) );
check( 'paypal: attivato', 'active' === $billing->subscription( 's3' )['status'] && 'I-1' === $billing->subscription( 's3' )['provider_ref'] );
$n = count( $calls );
$pp( array( 'id' => 'WH-2', 'event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => array( 'billing_agreement_id' => 'I-1' ) ) );
$renewCalls = 0;
foreach ( array_slice( $calls, $n ) as $c ) {
	$renewCalls += false !== strpos( $c[1], '/licenses/renew' ) ? 1 : 0;
}
check( 'paypal: pagamento iniziale non rinnova', 0 === $renewCalls );
check( 'paypal: rinnovo vicino alla scadenza', $billing->renew( 's3', 5, time() + 363 * 86400 ) && '2028-01-01 00:00:00' === $billing->subscription( 's3' )['expires_at'] );
$pp( array( 'id' => 'WH-3', 'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED', 'resource' => array( 'id' => 'I-1' ) ) );
check( 'paypal: annullato', 'canceled' === $billing->subscription( 's3' )['status'] );

// --- Accesso con link ----------------------------------------------------------
$auth = new Portale\Auth( $db );
$tok  = $auth->issue( $c2['id'] );
check( 'accesso: link valido una volta sola', null !== $auth->consume( $tok ) && null === $auth->consume( $tok ) );
check( 'accesso: link inventato', null === $auth->consume( 'xyz' ) );

// --- Impostazioni salvate dal pannello -------------------------------------------------------------------------------
Config::set( 'trial_enabled', true );
Portale\Settings::savePlan( $db, 'full', array(
	'label' => 'Pro Completo', 'plan_name' => 'Pro Full', 'tagline' => 'Tutto', 'tag' => 'Top', 'features' => "Uno\n\nDue\n", 'visible' => '1',
	'price_month' => '15,50', 'stripe_month' => 'price_ABCDEF', 'paypal_month' => '', 'once_month' => '1',
	'price_year' => '150', 'stripe_year' => 'price_1ABCDEF', 'paypal_year' => 'P-1ABCDEF', 'once_year' => '1',
) );
Portale\Settings::load( $db );
check( 'impostazioni: prezzo e nome del piano sovrascritti', 1550 === Config::get( 'plans.full.prices.month.cents' ) && 'Pro Completo' === Config::get( 'plans.full.label' ) && array( 'Uno', 'Due' ) === Config::get( 'plans.full.features' ) );
check( 'impostazioni: prezzo non valido rifiutato', null !== throwsUser( function () use ( $db ) {
	Portale\Settings::savePlan( $db, 'full', array( 'price_month' => 'abc' ) );
} ) );
check( 'impostazioni: id Stripe non valido rifiutato', null !== throwsUser( function () use ( $db ) {
	Portale\Settings::savePlan( $db, 'full', array( 'price_month' => '5', 'stripe_month' => 'sbagliato' ) );
} ) );
Portale\Settings::saveGeneral( $db, array( 'duration_month' => 9999, 'reminder_days' => -4, 'trial_days' => 0, 'trial_plan' => 'free', 'indexable' => '' ) );
Portale\Settings::load( $db );
check( 'impostazioni: limiti sempre dentro i margini', 62 === Portale\Settings::limit( 'duration_month' ) && 0 === Portale\Settings::limit( 'reminder_days' ) && 1 === Portale\Settings::limit( 'trial_days' ) && 'full' === Config::get( 'trial_plan' ) && false === Config::get( 'indexable' ) );
Portale\Settings::saveGeneral( $db, array( 'trial_days' => 30, 'trial_plan' => 'full', 'trial_enabled' => '1', 'duration_month' => 31, 'reminder_days' => 14, 'duration_year' => 366, 'duration_free' => 3650, 'renew_window' => 5 ) );
Portale\Settings::load( $db );
check( 'impostazioni: ripristino dei valori', 31 === Portale\Settings::limit( 'duration_month' ) && 14 === Portale\Settings::limit( 'reminder_days' ) && 30 === Portale\Settings::limit( 'trial_days' ) );

// --- Modi di pagamento -----------------------------------------------------------------------------------------------------
$price = Config::price( 'full', 'year' );
check( 'pagamenti: quattro modi con Stripe e PayPal configurati', 4 === count( $billing->methods( $price ) ) );
$noOnce = $price;
$noOnce['once'] = false;
check( 'pagamenti: senza «singolo» restano i ricorrenti', array( array( 'stripe', 'recurring' ), array( 'paypal', 'recurring' ) ) === $billing->methods( $noOnce ) );
$noIds = array( 'cents' => 500 );
check( 'pagamenti: senza id restano i singoli', array( array( 'stripe', 'once' ), array( 'paypal', 'once' ) ) === $billing->methods( $noIds ) );
check( 'pagamenti: modo non disponibile rifiutato', null !== throwsUser( function () use ( $billing ) {
	$billing->startPaid( 'dora@esempio.it', 'dora.it', 'full', 'year', 'stripe', 'strano' );
} ) );
Portale\Settings::savePlan( $db, 'full', array( 'label' => 'Pro Completo', 'price_year' => '150', 'stripe_year' => 'price_1ABCDEF', 'paypal_year' => 'P-1ABCDEF', 'visible' => '' ) );
Portale\Settings::load( $db );
check( 'pagamenti: piano nascosto non si compra', null === Config::price( 'full', 'year' ) );
Portale\Settings::savePlan( $db, 'full', array( 'label' => 'Pro Completo', 'price_year' => '150', 'stripe_year' => 'price_1ABCDEF', 'paypal_year' => 'P-1ABCDEF', 'once_year' => '1', 'visible' => '1', 'price_month' => '15,50', 'stripe_month' => 'price_ABCDEF', 'once_month' => '1' ) );
Portale\Settings::load( $db );

// Pagamento singolo con carta: stesso flusso, nessun rinnovo automatico.
$url = $billing->startPaid( 'dora@esempio.it', 'Dora.it', 'full', 'year', 'stripe', 'once' );
$ds  = $db->one( "SELECT * FROM subscriptions WHERE domain = 'dora.it'" );
check( 'singolo: indirizzo di pagamento e riga in attesa', 'https://stripe.test/pay' === $url && 'pending' === $ds['status'] && 'once' === $ds['mode'] && 'stripe' === $ds['provider'] );
$mails = array();
$send( array( 'id' => 'evt_once', 'type' => 'checkout.session.completed', 'data' => array( 'object' => array( 'mode' => 'payment', 'payment_status' => 'paid', 'client_reference_id' => $ds['id'], 'payment_intent' => 'pi_1' ) ) ) );
$ds = $billing->subscription( $ds['id'] );
check( 'singolo: licenza emessa dopo il pagamento', 'active' === $ds['status'] && ! empty( $ds['license_key_enc'] ) && 'pi_1' === $ds['provider_ref'] );
check( 'singolo: non è un rinnovo automatico', false === $billing->isRecurring( $ds ) );
$unpaid = $send( array( 'id' => 'evt_unpaid', 'type' => 'checkout.session.completed', 'data' => array( 'object' => array( 'mode' => 'payment', 'payment_status' => 'unpaid', 'client_reference_id' => 'inesistente' ) ) ) );
check( 'singolo: pagamento non completato ignorato', 200 === $unpaid );
check( 'singolo: cancel rifiutata (niente rinnovo da disdire)', null !== throwsUser( function () use ( $billing, $ds ) {
	$billing->cancel( $ds['id'], $ds['customer_id'] );
} ) );

// Rinnovo di un pagamento singolo con PayPal: la chiave resta, la scadenza si sposta.
$url = $billing->startRenewal( $ds['id'], $ds['customer_id'], 'paypal' );
$rn  = $db->one( "SELECT * FROM subscriptions WHERE renews = ?", array( $ds['id'] ) );
check( 'rinnovo singolo: ordine PayPal creato e riga collegata', 'https://paypal.test/approve' === $url && 'ORD1' === $rn['provider_ref'] && 'pending' === $rn['status'] );
check( 'rinnovo singolo: cliente estraneo rifiutato', null !== throwsUser( function () use ( $billing, $ds ) {
	$billing->startRenewal( $ds['id'], 'altro', 'paypal' );
} ) );
$n = count( $calls );
$billing->completePayment( $rn['id'] );
$billing->completePayment( $rn['id'] );
$renews = 0;
foreach ( array_slice( $calls, $n ) as $c ) {
	$renews += false !== strpos( $c[1], '/licenses/renew' ) ? 1 : 0;
}
check( 'rinnovo singolo: un solo rinnovo anche se arriva due volte', 1 === $renews && 'merged' === $billing->subscription( $rn['id'] )['status'] );
check( 'rinnovo singolo: non compare tra le licenze del cliente', 1 === count( array_filter( $billing->forCustomer( $ds['customer_id'] ), function ( $s ) { return 'dora.it' === $s['domain']; } ) ) );

// Pagamento singolo con PayPal: ordine e ricorrente.
$url = $billing->startPaid( 'ugo@esempio.it', 'ugo.it', 'full', 'month', 'paypal', 'once' );
check( 'singolo PayPal: ordine creato', 'https://paypal.test/approve' === $url && 'ORD1' === $db->one( "SELECT provider_ref FROM subscriptions WHERE domain = 'ugo.it'" )['provider_ref'] );
$url = $billing->startPaid( 'eva@esempio.it', 'eva.it', 'full', 'year', 'paypal', 'recurring' );
check( 'ricorrente PayPal: abbonamento creato', 'https://paypal.test/sub' === $url );

// --- Prova Pro -------------------------------------------------------------------------------------------------------------------
$freeNow = $billing->subscription( $sub['id'] );
check( 'prova: disponibile per la licenza gratuita attiva', $billing->trialAvailable( $freeNow ) );
$mails = array();
$calls0 = count( $calls );
$trial  = $billing->startTrial( $cust['id'], $sub['id'] );
$tcall  = null;
foreach ( array_slice( $calls, $calls0 ) as $c ) {
	if ( 'POST' === $c[0] && substr( $c[1], -9 ) === '/licenses' ) {
		$tcall = $c[2];
	}
}
check( 'prova: licenza Pro a tempo sullo stesso dominio', $tcall && 30 === $tcall['duration_days'] && 'trial-' . $sub['id'] === $tcall['external_ref'] && $freeNow['domain'] === $tcall['domain'] && 'Pro Full' === $tcall['plan_name'] );
check( 'prova: sub di tipo prova con chiave propria', 'trial' === $trial['provider'] && 'active' === $trial['status'] && ! empty( $trial['license_key_enc'] ) );
check( 'prova: email di avvio inviata', 1 === count( $mails ) && false !== strpos( $mails[0][1], 'prova' ) );
check( 'prova: una sola volta per licenza', ! $billing->trialAvailable( $billing->subscription( $sub['id'] ) ) && null !== throwsUser( function () use ( $billing, $cust, $sub ) {
	$billing->startTrial( $cust['id'], $sub['id'] );
} ) );
check( 'prova: cliente estraneo rifiutato', null !== throwsUser( function () use ( $billing, $sub ) {
	$billing->startTrial( 'altro', $sub['id'] );
} ) );
Config::set( 'trial_enabled', false );
list( $c9, $s9 ) = $billing->startFree( 'zita@esempio.it', 'zita.it' );
$billing->activate( $s9['id'] );
check( 'prova: spenta dal pannello', ! $billing->trialAvailable( $billing->subscription( $s9['id'] ) ) );
Config::set( 'trial_enabled', true );
check( 'prova: di nuovo disponibile se riaccesa', $billing->trialAvailable( $billing->subscription( $s9['id'] ) ) );

// --- Emissione manuale e promemoria -------------------------------------------------------------------------------------------
$mails = array();
$man   = $billing->issueManual( 'regalo@esempio.it', 'regalo.it', 'full', 90, 'partner', true );
check( 'manuale: licenza emessa con nota e email di benvenuto', 'manual' === $man['provider'] && 'partner' === $man['note'] && 1 === count( $mails ) );
check( 'manuale: piano sconosciuto rifiutato', null !== throwsUser( function () use ( $billing ) {
	$billing->issueManual( 'x@esempio.it', 'x.it', 'inesistente', 30, '', false );
} ) );
$db->run( 'UPDATE subscriptions SET expires_at = ? WHERE id = ?', array( gmdate( 'Y-m-d H:i:s', time() + 5 * 86400 ), $man['id'] ) );
$mails = array();
check( 'promemoria: parte per la licenza che scade', 1 <= $billing->sendReminders() && 'regalo@esempio.it' === $mails[ count( $mails ) - 1 ][0] );
check( 'promemoria: non si ripete', 0 === $billing->sendReminders() );
$recurring = $db->one( "SELECT * FROM subscriptions WHERE id = 's2'" );
check( 'promemoria: nessun avviso per i rinnovi automatici', ! in_array( 's2', array_column( $db->all( "SELECT id FROM subscriptions WHERE reminded_for IS NOT NULL" ), 'id' ), true ) );

// --- Email: modelli e interruttori ---------------------------------------------------------------------------------------------
Portale\Emails::save( $db, 'renewed', 'Rinnovato {dominio}', 'Fino al {scadenza} per {dominio} {strano}', true );
$mails = array();
$billing->notify( 'renewed', $billing->subscription( $ds['id'] ) );
check( 'email: modello personalizzato con segnaposto sostituiti', 1 === count( $mails ) && 'Rinnovato dora.it' === $mails[0][1] && false !== strpos( $mails[0][2], 'per dora.it {strano}' ) && false !== strpos( $mails[0][2], 'associazionesemplice.it' ) );
check( 'email: segnaposto sconosciuti segnalati', array( 'strano' ) === Portale\Emails::unknownVars( 'renewed', 'ciao {strano} {dominio}' ) );
Portale\Emails::save( $db, 'renewed', 'Rinnovato', 'Testo', false );
$mails = array();
$billing->notify( 'renewed', $billing->subscription( $ds['id'] ) );
check( 'email: evento spento non parte', 0 === count( $mails ) );
Portale\Emails::save( $db, 'login_link', 'Accesso', 'Link: {link}', false );
Portale\Emails::send( $db, 'login_link', 'a@esempio.it', array( 'link' => 'https://x' ) );
check( 'email: link di accesso parte anche se «spento»', 1 === count( $mails ) && 'Link: https://x' === substr( $mails[0][2], 0, 15 ) );
Portale\Emails::reset( $db, 'login_link' );
check( 'email: ripristino del testo originale', ! Portale\Emails::template( $db, 'login_link' )['custom'] );
check( 'email: oggetto vuoto rifiutato', null !== throwsUser( function () use ( $db ) {
	Portale\Emails::save( $db, 'renewed', '  ', 'x', true );
} ) );
check( 'email: oggetto senza a capo (niente iniezione di intestazioni)', false === strpos( Portale\Emails::template( $db, 'welcome' )['subject'], "\n" ) );

// --- Pannello: chi può entrare --------------------------------------------------------------------------------------------------
Config::set( 'admins', array( 'Io@Esempio.it' ) );
check( 'admin: riconosciuto senza badare alle maiuscole', Portale\Admin::isAdminEmail( 'io@esempio.it' ) && ! Portale\Admin::isAdminEmail( 'altro@esempio.it' ) && ! Portale\Admin::isAdminEmail( '' ) );
Portale\Audit::log( $db, 'io@esempio.it', 'prova', 'dettaglio' );
check( 'registro: azione salvata', 'prova' === Portale\Audit::recent( $db, 5 )[0]['action'] );
check( 'robots: segnali di non indicizzazione di default', false === Config::get( 'indexable', false ) );

// --- Chiavi dal pannello ----------------------------------------------------------------------------------------------------------
$tokenOk = 'wic_3c6d8003fdd2b68f.d5S6nSoypyWsa5RmmMPApIHcrhCf5d_rB1Pr_6ftmO13bJlM';
$changed = Portale\Settings::saveSecrets( $db, array( 'stripe_secret' => ' sk_test_abcdefghij1234567890 ', 'wega_token' => $tokenOk, 'paypal_live_present' => '1', 'paypal_live' => '1' ) );
Portale\Settings::load( $db );
check( 'chiavi: salvate e applicate alla configurazione', array( 'stripe_secret', 'wega_token' ) === $changed && 'sk_test_abcdefghij1234567890' === Config::get( 'stripe.secret_key' ) && $tokenOk === Config::get( 'wega.token' ) && true === Config::get( 'paypal.live' ) );
$raw = $db->one( "SELECT val FROM settings WHERE name = 'secrets'" )['val'];
check( 'chiavi: nel database restano cifrate', false === strpos( $raw, 'sk_test_' ) && false === strpos( $raw, 'wic_3c6d' ) );
$st = Portale\Settings::secretsStatus( $db );
check( 'chiavi: si vedono solo gli ultimi caratteri', '••••7890' === $st['stripe_secret']['mask'] && 'pannello' === $st['stripe_secret']['source'] && 'config.php' === $st['paypal_secret']['source'] );
check( 'chiavi: formato sbagliato rifiutato', null !== throwsUser( function () use ( $db ) {
	Portale\Settings::saveSecrets( $db, array( 'stripe_secret' => 'non-una-chiave' ) );
} ) && null !== throwsUser( function () use ( $db ) {
	Portale\Settings::saveSecrets( $db, array( 'wega_token' => 'sbagliato' ) );
} ) );
Portale\Settings::saveSecrets( $db, array( 'stripe_webhook' => 'whsec_abcdefghijklmnop' ) );
Portale\Settings::load( $db );
check( 'chiavi: un modulo non cancella le altre né la modalità PayPal', 'sk_test_abcdefghij1234567890' === Config::get( 'stripe.secret_key' ) && 'whsec_abcdefghijklmnop' === Config::get( 'stripe.webhook_secret' ) && true === Config::get( 'paypal.live' ) );
Portale\Settings::saveSecrets( $db, array( 'remove_stripe_webhook' => '1' ) );
check( 'chiavi: rimozione', ! Portale\Settings::secretsStatus( $db )['stripe_webhook']['set'] || 'config.php' === Portale\Settings::secretsStatus( $db )['stripe_webhook']['source'] );

// Prova dei collegamenti (server finto).
$prev = Http::$transport;
$code = 200;
Http::$transport = function ( $m, $u ) use ( &$code ) {
	return array( $code, '{}' );
};
Config::set( 'paypal.client_id', 'AbcdefghijklmnopqrstUV' );
Config::set( 'paypal.secret', 'AbcdefghijklmnopqrstUV' );
$code = 200;
list( $okS ) = Portale\Connections::test( 'stripe' );
list( $okP ) = Portale\Connections::test( 'paypal' );
list( $okW ) = Portale\Connections::test( 'wega' );
check( 'collegamenti: tutto raggiungibile', $okS && $okP && $okW );
$code = 401;
check( 'collegamenti: chiave rifiutata (401) segnalata', ! Portale\Connections::test( 'stripe' )[0] && ! Portale\Connections::test( 'wega' )[0] && ! Portale\Connections::test( 'paypal' )[0] );
$code = 403;
check( 'collegamenti: server licenze con token valido ma permessi parziali va bene', Portale\Connections::test( 'wega' )[0] );
$code = 503;
check( 'collegamenti: server giù non è un successo', ! Portale\Connections::test( 'wega' )[0] );
check( 'collegamenti: nome sconosciuto', ! Portale\Connections::test( 'altro' )[0] );
Http::$transport = $prev;

// --- Script di monitoraggio e cookie ---------------------------------------------------------------------------------------------
Config::set( 'tracking', array() );
check( 'cookie: senza script niente banner', ! Portale\Tracking::banner() && '' === Portale\Tracking::slot( 'head' ) );
check( 'cookie: snippet che chiude il template rifiutato', null !== throwsUser( function () {
	Portale\Tracking::validate( '<script>x</script></TEMPLATE><script>evil()</script>' );
} ) );
Portale\Settings::saveTracking( $db, array( 'head' => '<script async src="https://t.example/a.js"></script>', 'head_cat' => 'analytics', 'foot' => '<script>necessario()</script>', 'foot_cat' => 'necessary' ) );
Portale\Settings::load( $db );
$rev1 = Portale\Tracking::conf()['rev'];
check( 'cookie: script statistico dentro un template inerte', 0 === strpos( Portale\Tracking::slot( 'head' ), '<template data-cc-cat="analytics" data-cc-slot="head">' ) && Portale\Tracking::banner() && array( 'analytics' ) === array_keys( Portale\Tracking::categories() ) );
check( 'cookie: script necessario stampato subito', '<script>necessario()</script>' === trim( Portale\Tracking::slot( 'foot' ) ) );
Portale\Settings::saveTracking( $db, array( 'head' => '<script async src="https://t.example/a.js"></script>', 'head_cat' => 'analytics', 'foot' => '<script>necessario()</script>', 'foot_cat' => 'necessary' ) );
Portale\Settings::load( $db );
check( 'cookie: salvare senza cambiare non richiede un nuovo consenso', $rev1 === Portale\Tracking::conf()['rev'] );
Portale\Settings::saveTracking( $db, array( 'head' => '<script>altro()</script>', 'head_cat' => 'marketing', 'foot' => '', 'foot_cat' => 'necessary' ) );
Portale\Settings::load( $db );
check( 'cookie: cambiare gli script aggiorna la revisione e le categorie', Portale\Tracking::conf()['rev'] >= $rev1 && array( 'marketing' ) === array_keys( Portale\Tracking::categories() ) );
check( 'cookie: categoria sconosciuta ripiega su «statistiche»', 'analytics' === ( function () use ( $db ) {
	Portale\Settings::saveTracking( $db, array( 'head' => '<b></b>', 'head_cat' => 'hack' ) );
	Portale\Settings::load( $db );
	return Portale\Tracking::conf()['head_cat'];
} )() );
Portale\Settings::saveTracking( $db, array() );
Portale\Settings::load( $db );

// --- Pagine legali -----------------------------------------------------------------------------------------------------------------------
Portale\Settings::saveLegal( $db, array( 'titolare' => 'Associazione <b>Prova</b>', 'email' => 'privacy@esempio.it', 'privacy_body' => "## Titolo\n\nUn <script>alert(1)</script> **forte** per {titolare}\n\n- uno\n- due" ) );
Portale\Settings::load( $db );
$html = Portale\Legal::html( Portale\Legal::body( 'privacy' ) );
check( 'legale: testo personalizzato con titoli, elenco e grassetto', false !== strpos( $html, '<h2>Titolo</h2>' ) && false !== strpos( $html, '<strong>forte</strong>' ) && false !== strpos( $html, '<ul><li>uno</li><li>due</li></ul>' ) );
check( 'legale: HTML inserito dall\'amministratore è neutralizzato', false === strpos( $html, '<script>' ) && false === strpos( $html, '<b>Prova' ) && false !== strpos( $html, '&lt;b&gt;Prova&lt;/b&gt;' ) );
check( 'legale: pagina cookie usa il testo di base se non personalizzata', ! Portale\Legal::isCustom( 'cookie' ) && false !== strpos( Portale\Legal::html( Portale\Legal::body( 'cookie' ) ), 'privacy@esempio.it' ) );
Portale\Settings::saveLegal( $db, array( 'email' => 'non-valida' ) );
Portale\Settings::load( $db );
check( 'legale: email non valida scartata, si torna ai valori di base', 'info@associazionesemplice.it' === Portale\Legal::vars()['email'] && ! Portale\Legal::isCustom( 'privacy' ) );

// --- Errori del server licenze -----------------------------------------------------
Http::$transport = function () {
	return array( 422, json_encode( array( 'error' => array( 'code' => 'INVALID_DOMAIN', 'message' => 'no' ) ) ) );
};
try {
	( new WegaClient( 'https://license.test/api/v1', 't' ) )->createLicense( array( 'type' => 3 ) );
	check( 'wega: errore propagato', false );
} catch ( WegaException $e ) {
	check( 'wega: errore propagato con codice', 'INVALID_DOMAIN' === $e->errorCode && 422 === $e->httpStatus );
}

// --- Rimbalzo delle verifiche del plugin ------------------------------------------------
$pdb    = new Db( new PDO( 'sqlite::memory:' ) );
$pdb->migrate();
$pcalls = 0;
Http::$transport = function ( $method, $url, $headers, $body ) use ( &$pcalls ) {
	++$pcalls;
	if ( false !== strpos( $url, 'jwks.json' ) ) {
		return array( 200, '{"keys":[{"kty":"OKP"}]}' );
	}
	$in = json_decode( (string) $body, true );
	if ( 'chiave-sconosciuta' === $in['license_key'] ) {
		return array( 404, json_encode( array( 'error' => array( 'code' => 'LICENSE_NOT_FOUND', 'message' => 'no' ) ) ) );
	}
	if ( 'chiave-guasta-1' === $in['license_key'] ) {
		return array( 500, '' );
	}
	return array( 200, json_encode( array( 'valid' => true, 'domain' => $in['domain'], 'attestation' => 'JWT.INTATTO.X' ) ) );
};
$proxy = new Portale\LicenseProxy( $pdb, new WegaClient( 'https://license.test/api/v1', 'tok-segreto' ) );
$ok    = '{"license_key":"chiave-valida-1","domain":"https://WWW.Esempio.it/x"}';
$r     = $proxy->validate( $ok, '1.1.1.1', 1700000000 );
$j     = json_decode( $r[1], true );
check( 'rimbalzo: risposta di Wega girata com\'è, dominio normalizzato', 200 === $r[0] && true === $j['valid'] && 'esempio.it' === $j['domain'] && 'JWT.INTATTO.X' === $j['attestation'] );
check( 'rimbalzo: il token non compare nella risposta', false === strpos( $r[1], 'tok-segreto' ) );
$before = $pcalls;
$proxy->validate( $ok, '1.1.1.1', 1700000100 );
check( 'rimbalzo: seconda richiesta dalla memoria', $pcalls === $before );
$proxy->validate( $ok, '1.1.1.1', 1700000400 );
check( 'rimbalzo: memoria scaduta, nuova richiesta a Wega', $pcalls === $before + 1 );
check( 'rimbalzo: JSON rotto rifiutato', 422 === $proxy->validate( 'nonjson', '2.2.2.2' )[0] );
check( 'rimbalzo: dominio locale rifiutato', 422 === $proxy->validate( '{"license_key":"chiave-valida-1","domain":"localhost"}', '2.2.2.2' )[0] );
check( 'rimbalzo: chiave corta rifiutata', 422 === $proxy->validate( '{"license_key":"abc","domain":"esempio.it"}', '2.2.2.2' )[0] );
$r = $proxy->validate( '{"license_key":"chiave-sconosciuta","domain":"esempio.it"}', '3.3.3.3', 1700000000 );
check( 'rimbalzo: errore 4xx di Wega girato con il suo codice', 404 === $r[0] && 'LICENSE_NOT_FOUND' === json_decode( $r[1], true )['error']['code'] );
$r = $proxy->validate( '{"license_key":"chiave-guasta-1","domain":"esempio.it"}', '4.4.4.4', 1700000000 );
check( 'rimbalzo: errore del server Wega diventa 502 (il plugin tiene lo stato)', 502 === $r[0] );
$last = 0;
for ( $i = 0; $i < 25; $i++ ) {
	$last = $proxy->validate( '{"license_key":"chiave-limite-0","domain":"esempio.it"}', '5.5.5.5', 1700000000 )[0];
}
check( 'rimbalzo: limite per chiave (20/ora) → 429 con Retry-After', 429 === $last && in_array( 'Retry-After: 3600', $proxy->validate( '{"license_key":"chiave-limite-0","domain":"esempio.it"}', '5.5.5.5', 1700000000 )[2], true ) );
check( 'rimbalzo: la finestra successiva riparte', 200 === $proxy->validate( '{"license_key":"chiave-limite-0","domain":"esempio.it"}', '5.5.5.5', 1700000000 + 7200 )[0] );
$r = $proxy->jwks( 1700000000 );
check( 'jwks: chiavi pubbliche girate', 200 === $r[0] && isset( json_decode( $r[1], true )['keys'] ) );
$before = $pcalls;
$proxy->jwks( 1700000100 );
check( 'jwks: seconda lettura dalla memoria', $pcalls === $before );
Http::$transport = function () { return array( 0, '' ); };
check( 'jwks: Wega irraggiungibile, si usa l\'ultima copia', 200 === $proxy->jwks( 1700000000 + 7200 )[0] );

// --- Pacchetti del plugin ------------------------------------------------------------------
if ( class_exists( 'ZipArchive' ) ) {
	$rdir = sys_get_temp_dir() . '/asem-rel-' . bin2hex( random_bytes( 4 ) );
	Config::set( 'releases_dir', $rdir );
	$rdb = new Db( new PDO( 'sqlite::memory:' ) );
	$rdb->migrate();
	$mkzip = function ( string $slug, string $ver, string $inner = '' ) {
		$f = tempnam( sys_get_temp_dir(), 'z' );
		$z = new ZipArchive();
		$z->open( $f, ZipArchive::OVERWRITE );
		$z->addFromString( ( '' !== $inner ? $inner : $slug ) . '/' . $slug . '.php', "<?php\n/**\n * Plugin Name: X\n * Version:           $ver\n */\n" );
		$z->close();
		return $f;
	};
	for ( $i = 1; $i <= 12; $i++ ) {
		Portale\Releases::add( $rdb, 'free', $mkzip( 'associazionesemplice', "1.0.$i" ), 'n', 'test' );
	}
	$vers = array_column( Portale\Releases::all( $rdb, 'free' ), 'version' );
	check( 'pacchetti: restano le ultime 10, dalla più recente', 10 === count( $vers ) && '1.0.12' === $vers[0] && '1.0.3' === $vers[9] );
	check( 'pacchetti: i file vecchi sono cancellati dal disco', ! is_file( $rdir . '/free/1.0.1.zip' ) && is_file( $rdir . '/free/1.0.12.zip' ) );
	check( 'pacchetti: 1.0.10 viene dopo 1.0.9 (ordine numerico)', array_search( '1.0.10', $vers, true ) < array_search( '1.0.9', $vers, true ) );
	check( 'pacchetti: versione già presente rifiutata', null !== throwsUser( function () use ( $rdb, $mkzip ) { Portale\Releases::add( $rdb, 'free', $mkzip( 'associazionesemplice', '1.0.12' ), '', 'test' ); } ) );
	check( 'pacchetti: zip del prodotto sbagliato rifiutato', null !== throwsUser( function () use ( $rdb, $mkzip ) { Portale\Releases::add( $rdb, 'pro', $mkzip( 'associazionesemplice', '2.0.0' ), '', 'test' ); } ) );
	check( 'pacchetti: file non zip rifiutato', null !== throwsUser( function () use ( $rdb ) { Portale\Releases::add( $rdb, 'free', __FILE__, '', 'test' ); } ) );
	Portale\Releases::add( $rdb, 'pro', $mkzip( 'associazionesemplice-pro', '1.1.4' ), '', 'test' );
	$active = array( 'status' => 'active', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'plan_code' => 'free' );
	$pro    = array( 'status' => 'active', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'plan_code' => 'full' );
	$lapsed = array( 'status' => 'active', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 ), 'plan_code' => 'full' );
	check( 'accesso: licenza gratuita → solo il gratuito', array( 'free' ) === Portale\Releases::entitled( array( $active ) ) );
	check( 'accesso: Pro attivo → gratuito e Pro', array( 'free', 'pro' ) === Portale\Releases::entitled( array( $active, $pro ) ) );
	check( 'accesso: Pro scaduto → niente', array() === Portale\Releases::entitled( array( $lapsed ) ) );
	check( 'accesso: senza licenze → niente', array() === Portale\Releases::entitled( array() ) );
	check( 'scarico: senza licenza Pro rifiutato', null !== throwsUser( function () use ( $rdb, $active ) { Portale\Releases::authorize( $rdb, 'c1', false, array( $active ), 'pro', '1.1.4' ); } ) );
	check( 'scarico: versione inesistente rifiutata', null !== throwsUser( function () use ( $rdb, $pro ) { Portale\Releases::authorize( $rdb, 'c1', false, array( $pro ), 'pro', '9.9.9' ); } ) );
	$now = 1700000000;
	for ( $i = 0; $i < 10; $i++ ) {
		Portale\Releases::authorize( $rdb, 'c1', false, array( $pro ), 'pro', '1.1.4', $now );
	}
	check( 'scarico: 11° scarico in 24 ore rifiutato', null !== throwsUser( function () use ( $rdb, $pro, $now ) { Portale\Releases::authorize( $rdb, 'c1', false, array( $pro ), 'pro', '1.1.4', $now ); } ) );
	check( 'scarico: il giorno dopo si riparte', is_file( Portale\Releases::authorize( $rdb, 'c1', false, array( $pro ), 'pro', '1.1.4', $now + 90000 ) ) );
	check( 'scarico: amministratore senza limiti né licenza', is_file( Portale\Releases::authorize( $rdb, 'c9', true, array(), 'pro', '1.1.4', $now ) ) );
	check( 'pacchetti: versione letta dall\'intestazione', '1.2.3' === Portale\Releases::headerVersion( "<?php\n/**\n * Version:           1.2.3\n */" ) );
	check( 'pacchetti: Stable tag non scambiato per Version', '' === Portale\Releases::headerVersion( "Stable tag: 1.2.3\nVersion: 1.2" ) );
}

// --- Accesso con password -----------------------------------------------------------------
$adb = new Db( new PDO( 'sqlite::memory:' ) );
$adb->migrate();
$auth = new Portale\Auth( $adb );
$adb->run( 'INSERT INTO customers (id, email, created_at) VALUES (?,?,?)', array( 'c1', 'socio@esempio.it', Db::now() ) );
check( 'password: senza password non si entra', 'Email o password non corrette.' === throwsUser( function () use ( $auth ) { $auth->loginWithPassword( 'socio@esempio.it', 'qualsiasi-cosa', '9.9.9.1' ); } ) );
check( 'password: troppo corta rifiutata', null !== throwsUser( function () use ( $auth ) { $auth->setPassword( 'c1', 'corta' ); } ) );
check( 'password: uguale all\'email rifiutata', null !== throwsUser( function () use ( $auth ) { $auth->setPassword( 'c1', 'socio@esempio.it', 'socio@esempio.it' ); } ) );
$auth->setPassword( 'c1', 'una password lunga abbastanza', 'socio@esempio.it' );
check( 'password: impostata e salvata come impronta', $auth->hasPassword( 'c1' ) && false === strpos( (string) $adb->one( 'SELECT password_hash FROM customers' )['password_hash'], 'lunga' ) );
check( 'password: accesso riuscito (email in maiuscolo e spazi)', 'c1' === $auth->loginWithPassword( '  Socio@Esempio.IT ', 'una password lunga abbastanza', '9.9.9.2' )['id'] );
check( 'password: password lunghissima non troncata (oltre 72 byte)', true === ( function () use ( $auth ) { $auth->setPassword( 'c1', str_repeat( 'a', 80 ) . 'X' ); return ! $auth->checkPassword( 'c1', str_repeat( 'a', 80 ) . 'Y' ) && $auth->checkPassword( 'c1', str_repeat( 'a', 80 ) . 'X' ); } )() );
$auth->setPassword( 'c1', 'una password lunga abbastanza', 'socio@esempio.it' );
$m1 = throwsUser( function () use ( $auth ) { $auth->loginWithPassword( 'socio@esempio.it', 'sbagliata-1', '9.9.9.3' ); } );
$m2 = throwsUser( function () use ( $auth ) { $auth->loginWithPassword( 'nessuno@esempio.it', 'sbagliata-1', '9.9.9.4' ); } );
check( 'password: stesso errore per email sconosciuta e password sbagliata', $m1 === $m2 && null !== $m1 );
for ( $i = 0; $i < 6; $i++ ) {
	throwsUser( function () use ( $auth ) { $auth->loginWithPassword( 'socio@esempio.it', 'sbagliata-x', '9.9.9.5' ); } );
}
check( 'password: dopo 5 errori si blocca anche con la password giusta', false !== strpos( (string) throwsUser( function () use ( $auth ) { $auth->loginWithPassword( 'socio@esempio.it', 'una password lunga abbastanza', '9.9.9.6' ); } ), 'Troppi tentativi' ) );
check( 'password: il blocco scade con l\'ora', 'c1' === $auth->loginWithPassword( 'socio@esempio.it', 'una password lunga abbastanza', '9.9.9.6', time() + 7200 )['id'] );

echo $fails ? "\n$fails prove fallite\n" : "\nTutte le prove sono passate\n";
exit( $fails ? 1 : 0 );
