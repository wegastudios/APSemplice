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

echo $fails ? "\n$fails prove fallite\n" : "\nTutte le prove sono passate\n";
exit( $fails ? 1 : 0 );
