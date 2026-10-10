<?php
use AssociazioneSemplice\CancelPolicy as C;
use PHPUnit\Framework\TestCase;

final class CancelPolicyTest extends TestCase {
	private $tz;

	protected function setUp(): void {
		$this->tz = new DateTimeZone( 'Europe/Rome' );
	}

	private function now( string $s ): DateTimeImmutable {
		return new DateTimeImmutable( $s, $this->tz );
	}

	private function ev( int $fee, bool $cancellable, ?string $policy, string $now, string $date = '2026-10-20', ?string $time = '21:00', string $default = C::H48 ): array {
		return C::evaluate( $fee, $cancellable, $policy, $default, $date, $time, $this->now( $now ) );
	}

	public function test_free_events_can_always_be_cancelled_until_they_start(): void {
		$r = $this->ev( 0, false, null, '2026-10-20 20:59:00' );
		$this->assertTrue( $r['allowed'] );
		$this->assertSame( C::REASON_FREE, $r['reason'] );
		$this->assertTrue( $this->ev( 0, false, null, '2026-10-01 09:00:00' )['allowed'] );
	}

	public function test_nothing_can_be_cancelled_once_the_event_started(): void {
		foreach ( array( 0, 500 ) as $fee ) {
			$r = $this->ev( $fee, true, C::H24, '2026-10-20 21:00:00' );
			$this->assertFalse( $r['allowed'], "quota $fee" );
			$this->assertSame( C::REASON_STARTED, $r['reason'] );
		}
	}

	public function test_paid_events_are_not_cancellable_by_default(): void {
		$r = $this->ev( 500, false, null, '2026-10-01 09:00:00' );
		$this->assertFalse( $r['allowed'] );
		$this->assertSame( C::REASON_NOT_CANCELLABLE, $r['reason'] );
	}

	public function test_cancellable_paid_event_with_24h_policy(): void {
		$this->assertTrue( $this->ev( 500, true, C::H24, '2026-10-19 21:00:00' )['allowed'], 'esattamente 24 ore prima' );
		$late = $this->ev( 500, true, C::H24, '2026-10-19 21:00:01' );
		$this->assertFalse( $late['allowed'] );
		$this->assertSame( C::REASON_DEADLINE, $late['reason'] );
		$this->assertSame( '2026-10-19 21:00', $late['deadline']->format( 'Y-m-d H:i' ) );
	}

	public function test_48h_and_one_week_policies(): void {
		$this->assertTrue( $this->ev( 500, true, C::H48, '2026-10-18 21:00:00' )['allowed'] );
		$this->assertFalse( $this->ev( 500, true, C::H48, '2026-10-18 21:00:01' )['allowed'] );
		$this->assertTrue( $this->ev( 500, true, C::D7, '2026-10-13 21:00:00' )['allowed'] );
		$this->assertFalse( $this->ev( 500, true, C::D7, '2026-10-14 08:00:00' )['allowed'] );
	}

	public function test_default_policy_is_used_when_the_event_has_none(): void {
		$this->assertTrue( $this->ev( 500, true, null, '2026-10-18 20:00:00', '2026-10-20', '21:00', C::H48 )['allowed'] );
		$this->assertFalse( $this->ev( 500, true, null, '2026-10-18 20:00:00', '2026-10-20', '21:00', C::D7 )['allowed'] );
		$this->assertTrue( $this->ev( 500, true, 'boh', '2026-10-18 20:00:00' )['allowed'], 'valore non valido: si usa il predefinito' );
	}

	public function test_event_without_time_starts_at_midnight(): void {
		$this->assertTrue( $this->ev( 500, true, C::H24, '2026-10-18 23:59:00', '2026-10-20', null )['allowed'] );
		$this->assertFalse( $this->ev( 500, true, C::H24, '2026-10-19 00:00:01', '2026-10-20', null )['allowed'] );
	}

	public function test_name_change_is_possible_until_the_event_starts(): void {
		$this->assertTrue( C::can_transfer( '2026-10-20', '21:00', $this->now( '2026-10-20 20:59:00' ) ) );
		$this->assertFalse( C::can_transfer( '2026-10-20', '21:00', $this->now( '2026-10-20 21:00:00' ) ) );
	}

	public function test_policies_and_messages(): void {
		$this->assertSame( array( '24h', '48h', '7d' ), array_keys( C::labels() ) );
		$this->assertTrue( C::is_valid( '7d' ) );
		$this->assertFalse( C::is_valid( '3h' ) );
		$this->assertFalse( C::is_valid( null ) );
		$this->assertSame( 168, C::hours( C::D7 ) );
		$this->assertStringContainsString( 'cambiare il nominativo', C::message( $this->ev( 500, false, null, '2026-10-01 09:00:00' ) ) );
		$this->assertStringContainsString( '19/10/2026 21:00', C::message( $this->ev( 500, true, C::H24, '2026-10-19 22:00:00' ) ) );
		$this->assertStringContainsString( 'quando vuoi', C::message( $this->ev( 0, false, null, '2026-10-01 09:00:00' ) ) );
	}
}
