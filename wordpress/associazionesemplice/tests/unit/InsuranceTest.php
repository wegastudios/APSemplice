<?php
use AssociazioneSemplice\Insurance;
use PHPUnit\Framework\TestCase;

final class InsuranceTest extends TestCase {

	private function policy( string $from, string $to ): array {
		return array( 'valid_from' => $from, 'valid_to' => $to );
	}

	public function test_no_policy(): void {
		$this->assertSame( Insurance::NONE, Insurance::status_of( null, '2026-10-06' ) );
	}

	public function test_valid_policy(): void {
		$this->assertSame( Insurance::VALID, Insurance::status_of( $this->policy( '2026-01-01', '2026-12-31' ), '2026-10-06' ) );
	}

	public function test_policy_expiring_within_thirty_days(): void {
		$this->assertSame( Insurance::EXPIRING, Insurance::status_of( $this->policy( '2026-01-01', '2026-10-30' ), '2026-10-06' ) );
		$this->assertSame( Insurance::EXPIRING, Insurance::status_of( $this->policy( '2026-01-01', '2026-11-05' ), '2026-10-06' ) );
		$this->assertSame( Insurance::VALID, Insurance::status_of( $this->policy( '2026-01-01', '2026-11-06' ), '2026-10-06' ) );
	}

	public function test_last_day_is_still_covered(): void {
		$this->assertSame( Insurance::EXPIRING, Insurance::status_of( $this->policy( '2026-01-01', '2026-10-06' ), '2026-10-06' ) );
	}

	public function test_expired_policy(): void {
		$this->assertSame( Insurance::EXPIRED, Insurance::status_of( $this->policy( '2025-01-01', '2025-12-31' ), '2026-10-06' ) );
	}

	public function test_coverage_not_started_yet_counts_as_none(): void {
		$this->assertSame( Insurance::NONE, Insurance::status_of( $this->policy( '2027-01-01', '2027-12-31' ), '2026-10-06' ) );
	}
}
