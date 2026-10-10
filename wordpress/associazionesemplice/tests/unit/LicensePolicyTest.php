<?php
use AssociazioneSemplice\License;
use AssociazioneSemplice\LicensePolicy as P;
use PHPUnit\Framework\TestCase;

final class LicensePolicyTest extends TestCase {

	public function test_standby_and_active_have_no_restrictions(): void {
		foreach ( array( P::STATUS_STANDBY, P::STATUS_ACTIVE ) as $s ) {
			$r = P::evaluate( $s, null, '2026-10-01' );
			$this->assertSame( P::POPUP_NONE, $r['popup'], $s );
			$this->assertSame( array(), $r['blocked'], $s );
		}
	}

	public function test_first_week_popup_is_closable_but_services_are_blocked_from_day_zero(): void {
		$r = P::evaluate( P::STATUS_UNPAID, '2026-10-01', '2026-10-01' );
		$this->assertSame( P::POPUP_CLOSABLE, $r['popup'] );
		$this->assertSame( 7, $r['days_left'] );
		$this->assertContains( 'export', $r['blocked'] );
		$this->assertContains( 'member_area', $r['blocked'] );
	}

	public function test_popup_is_closable_for_exactly_seven_days(): void {
		$this->assertSame( P::POPUP_CLOSABLE, P::evaluate( P::STATUS_UNPAID, '2026-10-01', '2026-10-07' )['popup'] );
		$this->assertSame( 1, P::evaluate( P::STATUS_UNPAID, '2026-10-01', '2026-10-07' )['days_left'] );
		$locked = P::evaluate( P::STATUS_UNPAID, '2026-10-01', '2026-10-08' );
		$this->assertSame( P::POPUP_LOCKED, $locked['popup'] );
		$this->assertSame( 0, $locked['days_left'] );
	}

	public function test_released_domain_is_treated_like_unpaid(): void {
		$r = P::evaluate( P::STATUS_UNLICENSED, '2026-10-01', '2026-10-20' );
		$this->assertSame( P::POPUP_LOCKED, $r['popup'] );
		$this->assertSame( License::FEATURES, $r['blocked'] );
		$this->assertStringContainsString( 'associato', P::message( P::STATUS_UNLICENSED ) );
		$this->assertStringContainsString( 'pagamento', P::message( P::STATUS_UNPAID ) );
	}

	public function test_missing_or_future_start_date_is_lenient(): void {
		$this->assertSame( P::POPUP_CLOSABLE, P::evaluate( P::STATUS_UNPAID, null, '2026-10-01' )['popup'] );
		$this->assertSame( P::POPUP_CLOSABLE, P::evaluate( P::STATUS_UNPAID, '2026-12-01', '2026-10-01' )['popup'] );
	}

	public function test_advanced_features_include_export_and_member_area(): void {
		$this->assertContains( 'export', License::FEATURES );
		$this->assertContains( 'member_area', License::FEATURES );
	}
}
