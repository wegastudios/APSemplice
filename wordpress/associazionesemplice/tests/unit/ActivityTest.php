<?php
use AssociazioneSemplice\ActivityKind;
use AssociazioneSemplice\Pricing;
use AssociazioneSemplice\Rules;
use PHPUnit\Framework\TestCase;

final class ActivityKindTest extends TestCase {
	public function test_three_kinds(): void {
		$this->assertSame( array( 'course', 'event', 'recurring' ), array_keys( ActivityKind::labels() ) );
		$this->assertTrue( ActivityKind::is_valid( 'event' ) );
		$this->assertFalse( ActivityKind::is_valid( 'boh' ) );
	}

	public function test_only_events_use_sessions(): void {
		$this->assertFalse( ActivityKind::uses_sessions( ActivityKind::COURSE ) );
		$this->assertTrue( ActivityKind::uses_sessions( ActivityKind::EVENT ) );
		$this->assertTrue( ActivityKind::uses_sessions( ActivityKind::RECURRING ) );
	}

	public function test_fee_unit(): void {
		$this->assertSame( '', ActivityKind::fee_unit( ActivityKind::COURSE ), 'a rinnovo mensile si intende al mese: nessuna dicitura' );
		$this->assertSame( 'una tantum', ActivityKind::fee_unit( ActivityKind::COURSE, 'once' ) );
		$this->assertSame( 'a evento', ActivityKind::fee_unit( ActivityKind::EVENT ) );
		$this->assertSame( 'a evento', ActivityKind::fee_unit( ActivityKind::RECURRING ) );
	}
}

final class PricingTest extends TestCase {
	public function test_members_pay_the_member_fee(): void {
		foreach ( array( 'founder', 'ordinary', 'volunteer' ) as $t ) {
			$this->assertSame( 500, Pricing::fee_for( 500, 800, $t ), $t );
		}
	}

	public function test_guests_pay_the_guest_fee_when_set(): void {
		$this->assertSame( 800, Pricing::fee_for( 500, 800, 'guest' ) );
		$this->assertSame( 500, Pricing::fee_for( 500, null, 'guest' ), 'non impostato = come i soci' );
		$this->assertSame( 0, Pricing::fee_for( 500, 0, 'guest' ), '0 = gratuito per gli ospiti' );
		$this->assertSame( 300, Pricing::fee_for( 0, 300, 'guest' ), 'gratuito per i soci ma non per gli ospiti' );
		$this->assertSame( 0, Pricing::fee_for( 0, null, 'guest' ) );
	}

	public function test_booking_state(): void {
		$this->assertSame( Pricing::FREE, Pricing::booking_state( 0, 0 ) );
		$this->assertSame( Pricing::UNPAID, Pricing::booking_state( 500, 0 ) );
		$this->assertSame( Pricing::PARTIAL, Pricing::booking_state( 500, 200 ) );
		$this->assertSame( Pricing::PAID, Pricing::booking_state( 500, 500 ) );
		$this->assertSame( Pricing::PAID, Pricing::booking_state( 500, 900 ) );
		$this->assertSame( 300, Pricing::remaining( 500, 200 ) );
		$this->assertSame( 0, Pricing::remaining( 500, 900 ) );
	}
}

final class ActivityRulesTest extends TestCase {
	public function test_kind_and_fees_are_validated(): void {
		$ok = array( 'name' => 'Serata', 'kind' => 'event', 'fee_cents' => 500, 'guest_fee_cents' => 800 );
		$this->assertSame( array(), Rules::validate_activity( $ok ) );
		$this->assertNotEmpty( Rules::validate_activity( array_merge( $ok, array( 'kind' => 'boh' ) ) ) );
		$this->assertNotEmpty( Rules::validate_activity( array_merge( $ok, array( 'fee_cents' => -1 ) ) ) );
		$this->assertNotEmpty( Rules::validate_activity( array_merge( $ok, array( 'guest_fee_cents' => -5 ) ) ) );
		$this->assertSame( array(), Rules::validate_activity( array_merge( $ok, array( 'guest_fee_cents' => null, 'fee_cents' => 0 ) ) ), 'gratuita' );
	}

	public function test_session_validation(): void {
		$this->assertSame( array(), Rules::validate_session( array( 'session_date' => '2026-10-12', 'start_time' => '21:00', 'capacity' => 10 ) ) );
		$this->assertSame( array(), Rules::validate_session( array( 'session_date' => '2026-10-12' ) ) );
		$this->assertNotEmpty( Rules::validate_session( array( 'session_date' => '' ) ) );
		$this->assertNotEmpty( Rules::validate_session( array( 'session_date' => '2026-02-30' ) ) );
		$this->assertNotEmpty( Rules::validate_session( array( 'session_date' => '2026-10-12', 'start_time' => '25:00' ) ) );
		$this->assertNotEmpty( Rules::validate_session( array( 'session_date' => '2026-10-12', 'capacity' => 0 ) ) );
		$this->assertSame( array(), Rules::validate_session( array( 'session_date' => '2026-10-12', 'capacity' => '' ) ) );
	}
}
