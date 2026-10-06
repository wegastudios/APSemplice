<?php
use ApSemplice\FundYears;
use PHPUnit\Framework\TestCase;

final class FundYearsTest extends TestCase {

	public function test_refunds_cover_the_oldest_year_first(): void {
		$rows = FundYears::by_year(
			array(
				array( 'kind' => 'share', 'cents' => 10000, 'year' => 2026 ),
				array( 'kind' => 'share', 'cents' => 5000, 'year' => 2027 ),
				array( 'kind' => 'payout', 'cents' => 12000, 'year' => 2027 ),
			)
		);
		$this->assertSame( array( 'accrued' => 10000, 'settled' => 10000, 'unsettled' => 0 ), $rows[2026] );
		$this->assertSame( array( 'accrued' => 5000, 'settled' => 2000, 'unsettled' => 3000 ), $rows[2027] );
	}

	public function test_nothing_refunded(): void {
		$rows = FundYears::by_year( array( array( 'kind' => 'share', 'cents' => 700, 'year' => 2026 ) ) );
		$this->assertSame( 700, $rows[2026]['unsettled'] );
	}

	public function test_released_amounts_count_as_settled(): void {
		$rows = FundYears::by_year( array( array( 'kind' => 'share', 'cents' => 1000, 'year' => 2026 ), array( 'kind' => 'release', 'cents' => 400, 'year' => 2026 ) ) );
		$this->assertSame( 600, $rows[2026]['unsettled'] );
	}

	public function test_totals_across_funds(): void {
		$a = FundYears::by_year( array( array( 'kind' => 'share', 'cents' => 1000, 'year' => 2026 ) ) );
		$b = FundYears::by_year( array( array( 'kind' => 'share', 'cents' => 500, 'year' => 2026 ), array( 'kind' => 'payout', 'cents' => 500, 'year' => 2026 ) ) );
		$t = FundYears::totals( array( $a, $b ) );
		$this->assertSame( array( 'accrued' => 1500, 'settled' => 500, 'unsettled' => 1000 ), $t[2026] );
	}

	public function test_no_entries(): void {
		$this->assertSame( array(), FundYears::by_year( array() ) );
	}
}
