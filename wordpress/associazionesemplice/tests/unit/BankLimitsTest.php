<?php
use AssociazioneSemplice\Iban;
use AssociazioneSemplice\Limits;
use AssociazioneSemplice\PaymentConfig as P;
use AssociazioneSemplice\PayPalApi;
use AssociazioneSemplice\StripeApi;
use AssociazioneSemplice\TaxCode;
use AssociazioneSemplice\TextGuard;
use PHPUnit\Framework\TestCase;

final class IbanTest extends TestCase {
	public function test_valid_ibans(): void {
		foreach ( array( 'IT60X0542811101000000123456', 'it60 x054 2811 1010 0000 0123 456', 'GB82 WEST 1234 5698 7654 32', 'DE89370400440532013000', 'FR1420041010050500013M02606' ) as $iban ) {
			$this->assertTrue( Iban::is_valid( $iban ), $iban );
		}
	}

	public function test_invalid_ibans(): void {
		foreach ( array( '', 'IT60X0542811101000000123457', 'IT60X054281110100000012345', 'XX', '1234567890', 'IT60X0542811101000000123456789', 'IT12', 'GB82WEST12345698765433' ) as $iban ) {
			$this->assertFalse( Iban::is_valid( $iban ), $iban );
		}
	}

	public function test_italian_ibans_have_27_characters(): void {
		$this->assertFalse( Iban::is_valid( 'IT60X054281110100000012345' ), 'troppo corto per l\'Italia' );
	}

	public function test_format_and_mask(): void {
		$this->assertSame( 'IT60 X054 2811 1010 0000 0123 456', Iban::format( 'it60x0542811101000000123456' ) );
		$this->assertSame( 'IT60X0542811101000000123456', Iban::normalize( 'IT60 X054-2811.1010 0000 0123 456' ) );
		$this->assertSame( 'IT** **** 3456', Iban::mask( 'IT60X0542811101000000123456' ) );
		$this->assertSame( '', Iban::mask( 'IT' ) );
	}
}

final class TaxCodeTest extends TestCase {
	public function test_valid_codes(): void {
		foreach ( array( 'RSSMRA80A01H501U', 'rssmra80a01h501u', ' RSSMRA80A01H501U ', 'VRDGPP75B10F205B', 'RSSMRA80A01H50MM' ) as $cf ) {
			$this->assertTrue( TaxCode::is_valid( $cf ), $cf );
		}
	}

	public function test_invalid_codes(): void {
		foreach ( array( '', 'RSSMRA80A01H501X', 'RSSMRA80A01H501', 'RSSMRA80A01H501UU', 'ABCDEF12G34H567I', '12345678901', 'RSSMRA80Z01H501U', 'RSSMRA8OA01H501U' ) as $cf ) {
			$this->assertFalse( TaxCode::is_valid( $cf ), $cf );
		}
	}

	public function test_normalize(): void {
		$this->assertSame( 'RSSMRA80A01H501U', TaxCode::normalize( ' rssmra 80a01 h501u ' ) );
	}
}

final class TextGuardTest extends TestCase {
	public function test_links_are_detected(): void {
		foreach ( array( 'Vai su https://esempio.it/paga', 'scrivi a www.esempio.it', 'bit.ly/abc123', 'paga su pay.example.com/x', 'chiama wa.me/393331234567', 'Clicca t.me/qualcuno' ) as $t ) {
			$this->assertTrue( TextGuard::has_link( $t ), $t );
		}
	}

	public function test_plain_texts_pass(): void {
		foreach ( array( 'Stasera si comincia alle 19.', "Portate l'acqua.\nA domani!", 'Lezione alle 18.30 in sala grande', 'Il file è allegato al corso.pdf', 'Costo 10,50 euro.' ) as $t ) {
			$this->assertFalse( TextGuard::has_payment_hint( $t ), $t );
		}
	}

	public function test_ibans_are_detected(): void {
		foreach ( array( 'Bonifico a IT60X0542811101000000123456', 'IBAN: IT60 X054 2811 1010 0000 0123 456', 'it60x0542811101000000123456' ) as $t ) {
			$this->assertTrue( TextGuard::has_iban( $t ), $t );
			$this->assertTrue( TextGuard::has_payment_hint( $t ), $t );
		}
	}
}

final class LimitsTest extends TestCase {
	public function test_every_limit_is_well_formed(): void {
		foreach ( Limits::defs() as $key => $d ) {
			$this->assertArrayHasKey( $d['group'], Limits::GROUPS, $key );
			$this->assertGreaterThanOrEqual( $d['min'], $d['default'], $key );
			$this->assertLessThanOrEqual( $d['max'], $d['default'], $key );
			$this->assertLessThan( $d['max'], $d['min'], $key );
			foreach ( array( 'label', 'unit', 'what', 'raise', 'lower' ) as $f ) {
				$this->assertNotSame( '', trim( (string) $d[ $f ] ), "$key: $f" );
			}
		}
	}

	public function test_defaults_keep_the_values_the_plugin_always_used(): void {
		$this->assertSame( 20, Limits::default_of( 'broadcast_per_day' ) );
		$this->assertSame( 25, Limits::default_of( 'broadcast_batch' ) );
		$this->assertSame( 5, Limits::default_of( 'notice_per_day' ) );
		$this->assertSame( 45, Limits::default_of( 'notice_board_days' ) );
		$this->assertSame( 10, Limits::default_of( 'first_access_per_ip' ) );
		$this->assertSame( 15, Limits::default_of( 'activation_per_ip' ) );
		$this->assertSame( 30, Limits::default_of( 'activation_days' ) );
		$this->assertSame( 200, Limits::default_of( 'access_requests_max' ) );
		$this->assertSame( 8, Limits::default_of( 'push_time_budget' ) );
		$this->assertSame( 10, Limits::default_of( 'push_max_devices' ) );
		$this->assertSame( 50, Limits::default_of( 'pay_min_cents' ) );
		$this->assertSame( 10, Limits::default_of( 'pay_pending_minutes' ) );
		$this->assertSame( 3, Limits::default_of( 'pay_expire_days' ) );
		$this->assertSame( 5000, Limits::default_of( 'import_max_rows' ) );
		$this->assertSame( 20, Limits::default_of( 'import_max_mb' ) );
		$this->assertSame( 10, Limits::default_of( 'attach_max_per_tx' ) );
		$this->assertSame( 10, Limits::default_of( 'attach_max_mb' ) );
		$this->assertSame( 3, Limits::default_of( 'backup_keep' ) );
		$this->assertSame( 8, Limits::default_of( 'suspend_after_months' ) );
		$this->assertSame( 7, Limits::default_of( 'reminders_expired_days' ) );
		$this->assertSame( 0, Limits::default_of( 'notice_links' ), 'link e IBAN negli avvisi dei volontari: non consentiti' );
		$this->assertSame( 1, Limits::default_of( 'profile_required' ) );
		$this->assertSame( 1, Limits::default_of( 'profile_gate' ) );
	}

	public function test_values_are_kept_inside_the_safety_range(): void {
		$this->assertSame( 500, Limits::clamp( 'broadcast_per_day', 100000 ) );
		$this->assertSame( 1, Limits::clamp( 'broadcast_per_day', 0 ) );
		$this->assertSame( 1, Limits::clamp( 'broadcast_per_day', -7 ) );
		$this->assertSame( 50, Limits::clamp( 'pay_min_cents', 1 ), 'mai sotto il minimo dei gateway' );
		$this->assertSame( 1, Limits::clamp( 'notice_links', 5 ) );
		$this->assertSame( 0, Limits::clamp( 'sconosciuto', 5 ) );
	}

	public function test_sanitize_drops_unknown_keys_and_junk(): void {
		$out = Limits::sanitize( array( 'broadcast_batch' => '100', 'sconosciuto' => 5, 'notice_per_day' => 'tanti', 'backup_keep' => 999 ) );
		$this->assertSame( array( 'broadcast_batch' => 100, 'backup_keep' => 30 ), $out );
		$this->assertSame( array(), Limits::sanitize( array() ) );
	}
}

final class MultiGatewayConfigTest extends TestCase {
	private function both( array $over = array() ): array {
		return array_merge(
			array(
				'payment_provider' => 'stripe_paypal', 'stripe_mode' => 'test', 'stripe_publishable_key' => 'pk_test_51Abc123', 'stripe_secret_key' => 'sk_test_51Abc123', 'stripe_webhook_secret' => 'whsec_abc123',
				'paypal_mode' => 'sandbox', 'paypal_client_id' => str_repeat( 'A', 40 ), 'paypal_client_secret' => str_repeat( 'b', 40 ),
			),
			$over
		);
	}

	public function test_both_gateways_are_listed_and_validated_together(): void {
		$this->assertSame( array( 'stripe', 'paypal' ), P::gateways_of( 'stripe_paypal' ) );
		$this->assertSame( array( 'stripe' ), P::gateways_of( 'stripe' ) );
		$this->assertSame( array(), P::gateways_of( 'woocommerce' ) );
		$this->assertSame( array(), P::gateways_of( 'none' ) );
		$this->assertArrayHasKey( 'stripe_paypal', P::providers() );
		$this->assertSame( array(), P::validate( $this->both() )['errors'] );
		$this->assertNotEmpty( P::validate( $this->both( array( 'paypal_client_id' => '' ) ) )['errors'] );
		$this->assertNotEmpty( P::validate( $this->both( array( 'stripe_secret_key' => '' ) ) )['errors'] );
	}

	public function test_each_gateway_can_be_checked_alone(): void {
		$bad_paypal = $this->both( array( 'paypal_client_secret' => '' ) );
		$this->assertSame( array(), P::validate_gateway( $bad_paypal, 'stripe' )['errors'] );
		$this->assertNotEmpty( P::validate_gateway( $bad_paypal, 'paypal' )['errors'] );
	}

	public function test_live_mode_needs_https(): void {
		$live = $this->both( array( 'stripe_mode' => 'live', 'stripe_publishable_key' => 'pk_live_51Abc123', 'stripe_secret_key' => 'sk_live_51Abc123', 'paypal_mode' => 'live' ) );
		$this->assertSame( array(), P::validate( $live )['errors'], 'senza l\'informazione sul sito non si blocca (test e uso locale)' );
		$this->assertSame( array(), P::validate( array_merge( $live, array( 'site_https' => 1 ) ) )['errors'] );
		$http = P::validate( array_merge( $live, array( 'site_https' => 0 ) ) )['errors'];
		$this->assertCount( 2, $http, 'sia Stripe sia PayPal in modalità reale rifiutati su http' );
		$this->assertSame( array(), P::validate( $this->both( array( 'site_https' => 0 ) ) )['errors'], 'in modalità di prova http va bene' );
	}

	public function test_labels_and_notes_default_and_custom(): void {
		$this->assertSame( 'Paga con carta', P::label( 'stripe' ) );
		$this->assertSame( 'Paga con PayPal', P::label( 'paypal' ) );
		$this->assertSame( 'Paga a rate con PayPal', P::label( 'paypal', array( 'pay_label_paypal' => ' Paga a rate con PayPal ' ) ) );
		$this->assertSame( 'Paga con carta', P::label( 'stripe', array( 'pay_label_stripe' => '   ' ) ) );
		$this->assertStringContainsString( 'Stripe', P::note( 'stripe' ) );
		$this->assertStringContainsString( 'PayPal', P::note( 'paypal' ) );
		$this->assertSame( 'In tre rate.', P::note( 'paypal', array( 'pay_note_paypal' => 'In tre rate.' ) ) );
	}

	public function test_only_euro_counts(): void {
		$this->assertSame( 1000, StripeApi::paid_eur_cents( array( 'currency' => 'eur', 'amount_total' => 1000 ) ) );
		$this->assertSame( 1000, StripeApi::paid_eur_cents( array( 'amount_total' => 1000 ) ), 'senza valuta indicata (non succede) si fida dell\'importo' );
		$this->assertSame( 0, StripeApi::paid_eur_cents( array( 'currency' => 'usd', 'amount_total' => 1000 ) ) );
		$order = function ( string $cur ) {
			return array( 'purchase_units' => array( array( 'payments' => array( 'captures' => array( array( 'status' => 'COMPLETED', 'amount' => array( 'currency_code' => $cur, 'value' => '12.50' ) ) ) ) ) ) );
		};
		$this->assertSame( 1250, PayPalApi::captured_cents( $order( 'EUR' ) ) );
		$this->assertSame( 0, PayPalApi::captured_cents( $order( 'USD' ) ) );
	}
}
