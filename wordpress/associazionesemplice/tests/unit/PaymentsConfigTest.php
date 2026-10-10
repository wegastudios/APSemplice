<?php
use AssociazioneSemplice\Gateways;
use AssociazioneSemplice\PaymentConfig as P;
use AssociazioneSemplice\Secrets;
use PHPUnit\Framework\TestCase;

final class SecretsTest extends TestCase {
	public function test_round_trip(): void {
		$enc = Secrets::encrypt( 'sk_test_abc123', 'salt-di-prova' );
		$this->assertStringStartsWith( 'enc1:', $enc );
		$this->assertStringNotContainsString( 'sk_test_abc123', $enc );
		$this->assertSame( 'sk_test_abc123', Secrets::decrypt( $enc, 'salt-di-prova' ) );
	}

	public function test_each_encryption_is_different_but_decrypts_the_same(): void {
		$a = Secrets::encrypt( 'x', 'k' );
		$b = Secrets::encrypt( 'x', 'k' );
		$this->assertNotSame( $a, $b );
		$this->assertSame( 'x', Secrets::decrypt( $b, 'k' ) );
	}

	public function test_wrong_key_or_tampering_gives_null(): void {
		$enc = Secrets::encrypt( 'segreto', 'k1' );
		$this->assertNull( Secrets::decrypt( $enc, 'k2' ) );
		$this->assertNull( Secrets::decrypt( substr( $enc, 0, -3 ) . 'AAA', 'k1' ) );
		$this->assertNull( Secrets::decrypt( 'enc1:non-base64-!!', 'k1' ) );
		$this->assertNull( Secrets::decrypt( 'in chiaro', 'k1' ) );
		$this->assertNull( Secrets::decrypt( '', 'k1' ) );
	}

	public function test_empty_stays_empty_and_mask_hides_the_key(): void {
		$this->assertSame( '', Secrets::encrypt( '', 'k' ) );
		$this->assertSame( '••••3456', Secrets::mask( 'sk_test_123456' ) );
		$this->assertSame( '', Secrets::mask( '' ) );
		$this->assertTrue( Secrets::is_encrypted( Secrets::encrypt( 'a', 'k' ) ) );
		$this->assertFalse( Secrets::is_encrypted( 'a' ) );
	}
}

final class PaymentConfigTest extends TestCase {
	private function stripe( array $over = array() ): array {
		return array_merge(
			array( 'payment_provider' => 'stripe', 'stripe_mode' => 'test', 'stripe_publishable_key' => 'pk_test_51Abc123', 'stripe_secret_key' => 'sk_test_51Abc123', 'stripe_webhook_secret' => 'whsec_abc123' ),
			$over
		);
	}

	public function test_none_and_woocommerce_need_no_keys(): void {
		$this->assertSame( array(), P::validate( array( 'payment_provider' => 'none' ) )['errors'] );
		$w = P::validate( array( 'payment_provider' => 'woocommerce' ) );
		$this->assertSame( array(), $w['errors'] );
		$this->assertNotEmpty( $w['warnings'], 'avvisa che WooCommerce non è ancora collegato' );
		$this->assertNotEmpty( P::validate( array( 'payment_provider' => 'boh' ) )['errors'] );
	}

	public function test_valid_stripe_test_configuration(): void {
		$r = P::validate( $this->stripe() );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( array(), $r['warnings'] );
	}

	public function test_stripe_requires_keys_with_the_right_prefixes(): void {
		$this->assertNotEmpty( P::validate( $this->stripe( array( 'stripe_publishable_key' => '' ) ) )['errors'] );
		$this->assertNotEmpty( P::validate( $this->stripe( array( 'stripe_secret_key' => '' ) ) )['errors'] );
		$this->assertNotEmpty( P::validate( $this->stripe( array( 'stripe_secret_key' => 'pk_test_51Abc123' ) ) )['errors'], 'scambiate' );
		$this->assertNotEmpty( P::validate( $this->stripe( array( 'stripe_webhook_secret' => 'abc' ) ) )['errors'] );
		$this->assertSame( array(), P::validate( $this->stripe( array( 'stripe_secret_key' => 'rk_test_51Abc123' ) ) )['errors'], 'chiavi con restrizioni' );
	}

	public function test_stripe_mode_must_match_the_keys(): void {
		$this->assertNotEmpty( P::validate( $this->stripe( array( 'stripe_mode' => 'live' ) ) )['errors'], 'chiavi di prova in modalità reale' );
		$live = P::validate( $this->stripe( array( 'stripe_mode' => 'live', 'stripe_publishable_key' => 'pk_live_51Abc123', 'stripe_secret_key' => 'sk_live_51Abc123' ) ) );
		$this->assertSame( array(), $live['errors'] );
		$this->assertNotEmpty( $live['warnings'], 'avvisa che i pagamenti saranno veri' );
	}

	public function test_missing_webhook_secret_is_only_a_warning(): void {
		$r = P::validate( $this->stripe( array( 'stripe_webhook_secret' => '' ) ) );
		$this->assertSame( array(), $r['errors'] );
		$this->assertNotEmpty( $r['warnings'] );
	}

	public function test_paypal(): void {
		$ok = array( 'payment_provider' => 'paypal', 'paypal_mode' => 'sandbox', 'paypal_client_id' => str_repeat( 'A', 40 ), 'paypal_client_secret' => str_repeat( 'b', 40 ) );
		$this->assertSame( array(), P::validate( $ok )['errors'] );
		$this->assertNotEmpty( P::validate( array_merge( $ok, array( 'paypal_client_id' => '' ) ) )['errors'] );
		$this->assertNotEmpty( P::validate( array_merge( $ok, array( 'paypal_client_secret' => 'corto' ) ) )['errors'] );
		$this->assertNotEmpty( P::validate( array_merge( $ok, array( 'paypal_mode' => 'live' ) ) )['warnings'] );
	}
}

final class GatewaysTest extends TestCase {
	private function http( int $code, string $body, ?array &$seen = null ): callable {
		return function ( $method, $url, $headers, $payload ) use ( $code, $body, &$seen ) {
			$seen = compact( 'method', 'url', 'headers', 'payload' );
			return array( 'code' => $code, 'body' => $body );
		};
	}

	public function test_stripe_success_uses_the_balance_endpoint_with_the_bearer_key(): void {
		$seen = null;
		$r    = Gateways::test( 'stripe', array( 'stripe_secret_key' => 'sk_test_1', 'stripe_mode' => 'test' ), $this->http( 200, '{"livemode":false}', $seen ) );
		$this->assertTrue( $r['ok'], $r['message'] );
		$this->assertSame( 'GET', $seen['method'] );
		$this->assertSame( 'https://api.stripe.com/v1/balance', $seen['url'] );
		$this->assertSame( 'Bearer sk_test_1', $seen['headers']['Authorization'] );
	}

	public function test_stripe_detects_a_live_key_in_test_mode(): void {
		$r = Gateways::test( 'stripe', array( 'stripe_secret_key' => 'sk_live_1', 'stripe_mode' => 'test' ), $this->http( 200, '{"livemode":true}' ) );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'REALE', $r['message'] );
	}

	public function test_stripe_invalid_key_and_missing_key(): void {
		$this->assertFalse( Gateways::test( 'stripe', array( 'stripe_secret_key' => 'sk_test_x' ), $this->http( 401, '{"error":{"message":"Invalid API Key"}}' ) )['ok'] );
		$this->assertStringContainsString( 'non è valida', Gateways::test( 'stripe', array( 'stripe_secret_key' => 'sk_test_x' ), $this->http( 401, '{}' ) )['message'] );
		$this->assertFalse( Gateways::test( 'stripe', array(), $this->http( 200, '{}' ) )['ok'] );
	}

	public function test_paypal_requests_a_token_on_the_right_host(): void {
		$seen = null;
		$cfg  = array( 'paypal_client_id' => 'ID123', 'paypal_client_secret' => 'SEC456', 'paypal_mode' => 'sandbox' );
		$r    = Gateways::test( 'paypal', $cfg, $this->http( 200, '{"access_token":"A21"}', $seen ) );
		$this->assertTrue( $r['ok'], $r['message'] );
		$this->assertSame( 'POST', $seen['method'] );
		$this->assertSame( 'https://api-m.sandbox.paypal.com/v1/oauth2/token', $seen['url'] );
		$this->assertSame( 'Basic ' . base64_encode( 'ID123:SEC456' ), $seen['headers']['Authorization'] );
		$this->assertSame( 'grant_type=client_credentials', $seen['payload'] );
		Gateways::test( 'paypal', array_merge( $cfg, array( 'paypal_mode' => 'live' ) ), $this->http( 200, '{"access_token":"A"}', $seen ) );
		$this->assertSame( 'https://api-m.paypal.com/v1/oauth2/token', $seen['url'] );
	}

	public function test_paypal_failures(): void {
		$cfg = array( 'paypal_client_id' => 'ID', 'paypal_client_secret' => 'SEC' );
		$this->assertStringContainsString( 'non validi', Gateways::test( 'paypal', $cfg, $this->http( 401, '{}' ) )['message'] );
		$this->assertFalse( Gateways::test( 'paypal', $cfg, $this->http( 500, '' ) )['ok'] );
		$this->assertFalse( Gateways::test( 'paypal', array(), $this->http( 200, '{}' ) )['ok'] );
	}

	public function test_network_errors_and_unknown_provider_are_reported_not_thrown(): void {
		$boom = function () {
			throw new RuntimeException( 'timeout' );
		};
		$r = Gateways::test( 'stripe', array( 'stripe_secret_key' => 'sk_test_1' ), $boom );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'timeout', $r['message'] );
		$this->assertFalse( Gateways::test( 'none', array(), $boom )['ok'] );
	}
}
