<?php
use AssociazioneSemplice\PaymentItems as I;
use AssociazioneSemplice\PayPalApi;
use AssociazioneSemplice\StripeApi;
use AssociazioneSemplice\StripeWebhook;
use PHPUnit\Framework\TestCase;

final class PaymentItemsTest extends TestCase {
	private function items(): array {
		return array(
			array( 'type' => 'membership', 'person_id' => 7, 'social_year' => '2026/2027', 'amount_cents' => 1000, 'label' => 'Quota associativa 2026/2027', 'person_name' => 'Omar Ordini' ),
			array( 'type' => 'course_month', 'person_id' => 7, 'activity_id' => 4, 'month' => '2026-10', 'amount_cents' => 2000, 'label' => 'Yoga — ottobre 2026', 'person_name' => 'Omar Ordini' ),
			array( 'type' => 'booking', 'person_id' => 9, 'session_id' => 12, 'amount_cents' => 800, 'label' => 'Cena sociale', 'person_name' => 'Gia Ospite' ),
		);
	}

	public function test_keys_round_trip(): void {
		foreach ( $this->items() as $item ) {
			$parsed = I::parse( I::key( $item ) );
			$this->assertNotNull( $parsed, I::key( $item ) );
			$this->assertSame( $item['type'], $parsed['type'] );
			$this->assertSame( $item['person_id'], $parsed['person_id'] );
		}
		$this->assertSame( 'q:7:2026/2027', I::key( $this->items()[0] ) );
		$this->assertSame( 'm:4:7:2026-10', I::key( $this->items()[1] ) );
		$this->assertSame( 'b:12:9', I::key( $this->items()[2] ) );
	}

	public function test_invalid_keys_are_rejected(): void {
		foreach ( array( '', 'x:1:2', 'b:1', 'b:a:2', 'm:1:2:2026-13', 'm:1:2:ottobre', 'q:1:abcd', 'b:1:2:3', "b:1:2' OR 1=1" ) as $k ) {
			$this->assertNull( I::parse( $k ), $k );
		}
	}

	public function test_total_grouping_and_decimal(): void {
		$items = $this->items();
		$this->assertSame( 3800, I::total( $items ) );
		$g = I::group_by_person( $items );
		$this->assertSame( array( 7, 9 ), array_keys( $g ) );
		$this->assertCount( 2, $g[7] );
		$this->assertSame( '12.50', I::decimal( 1250 ) );
		$this->assertSame( '0.05', I::decimal( 5 ) );
		$this->assertSame( '100.00', I::decimal( 10000 ) );
	}

	public function test_names(): void {
		$this->assertSame( 'Cena sociale — Gia Ospite', I::line_name( $this->items()[2] ) );
		$this->assertSame( 'APS Prova: 3 voci', I::summary( $this->items(), 'APS Prova' ) );
		$this->assertSame( 'Cena sociale — Gia Ospite', I::summary( array( $this->items()[2] ) ) );
	}
}

final class StripeWebhookTest extends TestCase {
	const SECRET = 'whsec_test_secret';

	public function test_valid_signature(): void {
		$payload = '{"id":"evt_1","type":"checkout.session.completed"}';
		$header  = StripeWebhook::header( $payload, self::SECRET, 1700000000 );
		$this->assertTrue( StripeWebhook::verify( $payload, $header, self::SECRET, 1700000100 ) );
	}

	public function test_tampered_payload_wrong_secret_and_stale_timestamp_fail(): void {
		$payload = '{"amount":500}';
		$header  = StripeWebhook::header( $payload, self::SECRET, 1700000000 );
		$this->assertFalse( StripeWebhook::verify( '{"amount":5000}', $header, self::SECRET, 1700000000 ), 'contenuto modificato' );
		$this->assertFalse( StripeWebhook::verify( $payload, $header, 'whsec_altro', 1700000000 ), 'segreto sbagliato' );
		$this->assertFalse( StripeWebhook::verify( $payload, $header, self::SECRET, 1700000000 + 301 ), 'troppo vecchia' );
		$this->assertFalse( StripeWebhook::verify( $payload, $header, self::SECRET, 1700000000 - 301 ), 'dal futuro' );
	}

	public function test_malformed_or_missing_headers_fail(): void {
		$p = '{}';
		foreach ( array( '', 'garbage', 't=abc,v1=ff', 't=1700000000', 'v1=ff', 't=1700000000,v1=' ) as $h ) {
			$this->assertFalse( StripeWebhook::verify( $p, $h, self::SECRET, 1700000000 ), $h );
		}
		$this->assertFalse( StripeWebhook::verify( $p, StripeWebhook::header( $p, '', 1700000000 ), '', 1700000000 ), 'segreto non configurato' );
	}

	public function test_several_signatures_one_valid(): void {
		$p      = '{"a":1}';
		$valid  = StripeWebhook::sign( $p, self::SECRET, 1700000000 );
		$header = 't=1700000000,v1=' . str_repeat( '0', 64 ) . ',v1=' . $valid . ',v0=zzz';
		$this->assertTrue( StripeWebhook::verify( $p, $header, self::SECRET, 1700000000 ) );
	}
}

final class StripeApiTest extends TestCase {
	private $items = array(
		array( 'type' => 'booking', 'person_id' => 9, 'session_id' => 12, 'amount_cents' => 800, 'label' => 'Cena sociale', 'person_name' => 'Gia Ospite' ),
		array( 'type' => 'membership', 'person_id' => 7, 'social_year' => '2026/2027', 'amount_cents' => 1000, 'label' => 'Quota associativa', 'person_name' => 'Omar' ),
	);

	public function test_checkout_params(): void {
		$p = StripeApi::checkout_params( $this->items, 'PUB-1', 'omar@example.com', 'https://s.it/ok', 'https://s.it/ko' );
		$this->assertSame( 'payment', $p['mode'] );
		$this->assertSame( 'PUB-1', $p['client_reference_id'] );
		$this->assertSame( 'PUB-1', $p['metadata']['asem_payment'] );
		$this->assertSame( 'omar@example.com', $p['customer_email'] );
		$this->assertCount( 2, $p['line_items'] );
		$this->assertSame( 800, $p['line_items'][0]['price_data']['unit_amount'] );
		$this->assertSame( 'eur', $p['line_items'][0]['price_data']['currency'] );
		$this->assertArrayNotHasKey( 'customer_email', StripeApi::checkout_params( $this->items, 'P', '', 'a', 'b' ) );
	}

	public function test_create_session_request_and_response(): void {
		$seen = null;
		$http = function ( $method, $url, $headers, $body ) use ( &$seen ) {
			$seen = compact( 'method', 'url', 'headers', 'body' );
			return array( 'code' => 200, 'body' => '{"id":"cs_test_1","url":"https://checkout.stripe.com/c/pay/cs_test_1"}' );
		};
		$params = StripeApi::checkout_params( $this->items, 'PUB-1', '', 'https://s.it/ok', 'https://s.it/ko' );
		$r      = StripeApi::create_session( $http, 'sk_test_1', $params, 'PUB-1' );
		$this->assertSame( array( 'id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1' ), $r );
		$this->assertSame( 'POST', $seen['method'] );
		$this->assertSame( 'https://api.stripe.com/v1/checkout/sessions', $seen['url'] );
		$this->assertSame( 'Bearer sk_test_1', $seen['headers']['Authorization'] );
		$this->assertSame( 'PUB-1', $seen['headers']['Idempotency-Key'] );
		parse_str( $seen['body'], $sent );
		$this->assertSame( '800', $sent['line_items'][0]['price_data']['unit_amount'] );
		$this->assertSame( 'PUB-1', $sent['client_reference_id'] );
		$this->assertSame( 'https://s.it/ok', $sent['success_url'] );
	}

	public function test_errors_become_exceptions_without_leaking_the_key(): void {
		$http = function () {
			return array( 'code' => 402, 'body' => '{"error":{"message":"Your card was declined."}}' );
		};
		try {
			StripeApi::create_session( $http, 'sk_test_SEGRETA', array(), 'k' );
			$this->fail( 'attesa eccezione' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'declined', $e->getMessage() );
			$this->assertStringNotContainsString( 'sk_test_SEGRETA', $e->getMessage() );
		}
		$this->expectException( RuntimeException::class );
		StripeApi::create_session( function () {
			return array( 'code' => 200, 'body' => '{"id":"cs_1"}' );
		}, 'sk', array(), 'k' );
	}

	public function test_retrieve_and_paid_detection(): void {
		$seen = null;
		$http = function ( $m, $u, $h, $b ) use ( &$seen ) {
			$seen = array( $m, $u );
			return array( 'code' => 200, 'body' => '{"id":"cs_1","payment_status":"paid","amount_total":1800,"payment_intent":"pi_9"}' );
		};
		$s = StripeApi::retrieve_session( $http, 'sk', 'cs_1' );
		$this->assertSame( array( 'GET', 'https://api.stripe.com/v1/checkout/sessions/cs_1' ), $seen );
		$this->assertTrue( StripeApi::is_paid( $s ) );
		$this->assertFalse( StripeApi::is_paid( array( 'payment_status' => 'unpaid' ) ) );
		$this->assertFalse( StripeApi::is_paid( array() ) );
	}
}

final class PayPalApiTest extends TestCase {
	private $items = array(
		array( 'type' => 'booking', 'person_id' => 9, 'session_id' => 12, 'amount_cents' => 850, 'label' => 'Cena sociale', 'person_name' => 'Gia' ),
		array( 'type' => 'membership', 'person_id' => 7, 'social_year' => '2026/2027', 'amount_cents' => 1000, 'label' => 'Quota', 'person_name' => 'Omar' ),
	);

	/** Risposte in sequenza; registra le richieste. */
	private function seq( array $responses, ?array &$log = null ): callable {
		$log = array();
		return function ( $method, $url, $headers, $body ) use ( &$responses, &$log ) {
			$log[] = compact( 'method', 'url', 'headers', 'body' );
			return array_shift( $responses );
		};
	}

	public function test_access_token(): void {
		$log = null;
		$tok = PayPalApi::access_token( $this->seq( array( array( 'code' => 200, 'body' => '{"access_token":"A21"}' ) ), $log ), false, 'ID', 'SEC' );
		$this->assertSame( 'A21', $tok );
		$this->assertSame( 'https://api-m.sandbox.paypal.com/v1/oauth2/token', $log[0]['url'] );
		$this->assertSame( 'Basic ' . base64_encode( 'ID:SEC' ), $log[0]['headers']['Authorization'] );
		PayPalApi::access_token( $this->seq( array( array( 'code' => 200, 'body' => '{"access_token":"B"}' ) ), $log ), true, 'ID', 'SEC' );
		$this->assertSame( 'https://api-m.paypal.com/v1/oauth2/token', $log[0]['url'] );
		$this->expectException( RuntimeException::class );
		PayPalApi::access_token( $this->seq( array( array( 'code' => 401, 'body' => '{"error_description":"Client Authentication failed"}' ) ) ), false, 'ID', 'SEC' );
	}

	public function test_order_payload_totals_match(): void {
		$p    = PayPalApi::order_payload( $this->items, 'PUB-1', 'https://s.it/ok', 'https://s.it/ko', 'APS Prova' );
		$unit = $p['purchase_units'][0];
		$this->assertSame( 'CAPTURE', $p['intent'] );
		$this->assertSame( '18.50', $unit['amount']['value'] );
		$this->assertSame( '18.50', $unit['amount']['breakdown']['item_total']['value'] );
		$this->assertSame( 'PUB-1', $unit['custom_id'] );
		$this->assertCount( 2, $unit['items'] );
		$this->assertSame( '8.50', $unit['items'][0]['unit_amount']['value'] );
		$this->assertSame( 'https://s.it/ok', $p['payment_source']['paypal']['experience_context']['return_url'] );
		$this->assertSame( 'PAY_NOW', $p['payment_source']['paypal']['experience_context']['user_action'] );
	}

	public function test_create_order_finds_the_approval_link(): void {
		$log  = null;
		$body = '{"id":"ORD1","links":[{"rel":"self","href":"https://api/self"},{"rel":"payer-action","href":"https://www.sandbox.paypal.com/checkoutnow?token=ORD1"}]}';
		$r    = PayPalApi::create_order( $this->seq( array( array( 'code' => 201, 'body' => $body ) ), $log ), false, 'TOK', array( 'intent' => 'CAPTURE' ), 'REQ-1' );
		$this->assertSame( array( 'id' => 'ORD1', 'url' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORD1' ), $r );
		$this->assertSame( 'Bearer TOK', $log[0]['headers']['Authorization'] );
		$this->assertSame( 'REQ-1', $log[0]['headers']['PayPal-Request-Id'] );
		$this->assertSame( '{"intent":"CAPTURE"}', $log[0]['body'] );
		$this->expectException( RuntimeException::class );
		PayPalApi::create_order( $this->seq( array( array( 'code' => 201, 'body' => '{"id":"X","links":[]}' ) ) ), false, 'TOK', array(), 'R' );
	}

	public function test_capture_and_amounts(): void {
		$order = '{"id":"ORD1","status":"COMPLETED","purchase_units":[{"payments":{"captures":[{"id":"CAP9","status":"COMPLETED","amount":{"currency_code":"EUR","value":"18.50"}}]}}]}';
		$log   = null;
		$o     = PayPalApi::capture_order( $this->seq( array( array( 'code' => 201, 'body' => $order ) ), $log ), false, 'TOK', 'ORD1' );
		$this->assertSame( 'POST', $log[0]['method'] );
		$this->assertSame( 'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORD1/capture', $log[0]['url'] );
		$this->assertTrue( PayPalApi::is_completed( $o ) );
		$this->assertSame( 1850, PayPalApi::captured_cents( $o ) );
		$this->assertSame( 'CAP9', PayPalApi::capture_id( $o ) );
		$this->assertFalse( PayPalApi::is_completed( array( 'status' => 'APPROVED' ) ) );
		$this->assertSame( 0, PayPalApi::captured_cents( array() ) );
	}

	public function test_already_captured_order_is_reread(): void {
		$order = '{"status":"COMPLETED","purchase_units":[{"payments":{"captures":[{"id":"C","status":"COMPLETED","amount":{"value":"5.00"}}]}}]}';
		$log   = null;
		$http  = $this->seq( array( array( 'code' => 422, 'body' => '{"details":[{"issue":"ORDER_ALREADY_CAPTURED"}]}' ), array( 'code' => 200, 'body' => $order ) ), $log );
		$o     = PayPalApi::capture_order( $http, false, 'TOK', 'ORD1' );
		$this->assertCount( 2, $log );
		$this->assertSame( 'GET', $log[1]['method'] );
		$this->assertSame( 500, PayPalApi::captured_cents( $o ) );
	}

	public function test_capture_failure_is_an_exception(): void {
		$this->expectException( RuntimeException::class );
		PayPalApi::capture_order( $this->seq( array( array( 'code' => 500, 'body' => '' ) ) ), false, 'TOK', 'ORD1' );
	}
}
