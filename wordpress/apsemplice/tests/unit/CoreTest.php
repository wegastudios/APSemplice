<?php
use ApSemplice\CashChange;
use ApSemplice\Money;
use ApSemplice\PaymentCalc;
use ApSemplice\SocialYear;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase {
	public function test_parses_italian_input(): void {
		$this->assertSame( 1250, Money::parse( '12,50' ) );
		$this->assertSame( 1250, Money::parse( '12.50' ) );
		$this->assertSame( 150000, Money::parse( '1.500' ) );
		$this->assertSame( 150000, Money::parse( '1.500,00' ) );
		$this->assertSame( 1000, Money::parse( '€ 10' ) );
		$this->assertSame( 1235, Money::parse( '12,35' ) );
	}

	public function test_rejects_garbage(): void {
		$this->assertNull( Money::parse( '' ) );
		$this->assertNull( Money::parse( null ) );
		$this->assertNull( Money::parse( 'abc' ) );
		$this->assertNull( Money::parse( '1,5,5' ) );
	}

	public function test_formats(): void {
		$this->assertSame( '1.234,50 €', Money::format( 123450 ) );
		$this->assertSame( '-0,05 €', Money::format( -5 ) );
		$this->assertSame( '1234,50', Money::plain( 123450 ) );
		$this->assertSame( '-0,05', Money::plain( -5 ) );
	}
}

final class SocialYearTest extends TestCase {
	public function test_september_starts_new_year(): void {
		$this->assertSame( 2025, SocialYear::for_date( '2025-09-01', 9 )->start_year );
		$this->assertSame( 2025, SocialYear::for_date( '2026-08-31', 9 )->start_year );
		$this->assertSame( 2024, SocialYear::for_date( '2025-08-31', 9 )->start_year );
	}

	public function test_range_label_months(): void {
		$y = new SocialYear( 2025, 9 );
		$this->assertSame( '2025/2026', $y->label() );
		$this->assertSame( '2025-09-01', $y->start()->format( 'Y-m-d' ) );
		$this->assertSame( '2026-08-31', $y->end()->format( 'Y-m-d' ) );
		$months = $y->months();
		$this->assertCount( 12, $months );
		$this->assertSame( '2025-09', $months[0] );
		$this->assertSame( '2026-08', $months[11] );
	}

	public function test_from_label_and_clamp(): void {
		$y = SocialYear::from_label( '2025/2026', 9 );
		$this->assertSame( 2025, $y->start_year );
		$this->assertSame( '2025-09', $y->clamp( '2025-01' ) );
		$this->assertSame( '2026-08', $y->clamp( '2027-01' ) );
		$this->assertSame( '2026-02', $y->clamp( '2026-02' ) );
	}
}

final class CashChangeTest extends TestCase {
	public function test_exact_payment_has_no_change(): void {
		$r = CashChange::compute( 1500, 1500 );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 0, $r['change'] );
		$this->assertSame( array(), $r['breakdown'] );
	}

	public function test_change_is_split_in_denominations(): void {
		$r = CashChange::compute( 1250, 2000 ); // resto 7,50 = 5 + 2 + 0,50
		$this->assertSame( 750, $r['change'] );
		$this->assertSame( array( array( 500, 1 ), array( 200, 1 ), array( 50, 1 ) ), $r['breakdown'] );
	}

	public function test_insufficient_cash(): void {
		$r = CashChange::compute( 2000, 1500 );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 500, $r['missing'] );
	}

	public function test_quick_tenders(): void {
		$q = CashChange::quick_tenders( 1250 );
		$this->assertSame( 1250, $q[0] );
		foreach ( $q as $v ) {
			$this->assertGreaterThanOrEqual( 1250, $v );
		}
	}
}

final class PaymentCalcTest extends TestCase {
	public function test_due_from_start_until_today(): void {
		$s = PaymentCalc::compute( 1000, '2025-10', null, '2025-12', new SocialYear( 2025, 9 ), array( '2025-10' => 1000, '2025-11' => 500 ) );
		$this->assertSame( 3000, $s['total_due'] );
		$this->assertSame( 1500, $s['total_paid'] );
		$this->assertSame( -1500, $s['balance'] );
		$this->assertFalse( $s['regular'] );
		$this->assertSame( array( '2025-11', '2025-12' ), array_column( $s['unpaid_months'], 'month' ) );
		$this->assertSame( PaymentCalc::PARTIAL, $s['months'][1]['state'] );
	}

	public function test_first_lesson_of_the_month(): void {
		$this->assertSame( '2025-10-07', PaymentCalc::first_lesson( '2025-10', 2 ) ); // il 1° ottobre 2025 è un mercoledì: primo martedì = 7
		$this->assertSame( '2025-10-01', PaymentCalc::first_lesson( '2025-10', 3 ) );
		$this->assertSame( '2025-10-05', PaymentCalc::first_lesson( '2025-10', 7 ) );
		$this->assertSame( '2025-10-01', PaymentCalc::first_lesson( '2025-10', 0 ), 'senza giorno indicato: dal 1° del mese' );
	}

	public function test_several_weekly_lessons_use_the_earliest_first_lesson(): void {
		// ottobre 2025: lunedì 6, giovedì 2 -> la prima lezione del mese è giovedì 2
		$this->assertSame( '2025-10-02', PaymentCalc::first_lesson( '2025-10', array( 1, 4 ) ) );
		$this->assertSame( '2025-10-01', PaymentCalc::first_lesson( '2025-10', array() ) );
		$s = PaymentCalc::compute( 1000, '2025-10', null, '2025-10', new SocialYear( 2025, 9 ), array(), array( 1, 4 ), '2025-10-02' );
		$this->assertSame( 1000, $s['total_due'] );
		$s = PaymentCalc::compute( 1000, '2025-10', null, '2025-10', new SocialYear( 2025, 9 ), array(), array( 1, 4 ), '2025-10-01' );
		$this->assertSame( 0, $s['total_due'] );
	}

	public function test_new_month_is_due_from_the_first_lesson(): void {
		$year = new SocialYear( 2025, 9 );
		$s    = PaymentCalc::compute( 1000, '2025-09', null, '2025-10', $year, array( '2025-09' => 1000 ), 2, '2025-10-03' );
		$this->assertSame( 1000, $s['total_due'], 'ottobre non è ancora dovuto: la prima lezione è il 7' );
		$this->assertTrue( $s['regular'] );
		$this->assertSame( array( 'month' => '2025-10', 'date' => '2025-10-07', 'fee' => 1000 ), $s['upcoming'] );
		$s = PaymentCalc::compute( 1000, '2025-09', null, '2025-10', $year, array( '2025-09' => 1000 ), 2, '2025-10-07' );
		$this->assertSame( 2000, $s['total_due'], 'il giorno della prima lezione la mensilità è dovuta' );
		$this->assertFalse( $s['regular'] );
		$this->assertNull( $s['upcoming'] );
		$s = PaymentCalc::compute( 1000, '2025-09', null, '2025-10', $year, array( '2025-09' => 1000, '2025-10' => 1000 ), 2, '2025-10-03' );
		$this->assertSame( PaymentCalc::ADVANCE, $s['months'][1]['state'], 'pagata in anticipo' );
		$s = PaymentCalc::compute( 1000, '2025-09', null, '2025-10', $year, array( '2025-09' => 1000 ) );
		$this->assertSame( 2000, $s['total_due'], 'senza giorno della lezione: dal 1° del mese, come prima' );
	}

	public function test_cancelled_enrollment_stops_owing(): void {
		$s = PaymentCalc::compute( 1000, '2025-10', '2025-11', '2026-03', new SocialYear( 2025, 9 ), array() );
		$this->assertSame( 2000, $s['total_due'] );
	}

	public function test_advance_payment_is_credit(): void {
		$s = PaymentCalc::compute( 1000, '2025-10', null, '2025-10', new SocialYear( 2025, 9 ), array( '2025-10' => 1000, '2025-11' => 1000 ) );
		$this->assertSame( 1000, $s['balance'] );
		$this->assertTrue( $s['regular'] );
		$this->assertSame( PaymentCalc::ADVANCE, $s['months'][1]['state'] );
	}

	public function test_nothing_due_before_start(): void {
		$s = PaymentCalc::compute( 1000, '2026-01', null, '2025-11', new SocialYear( 2025, 9 ), array() );
		$this->assertSame( 0, $s['total_due'] );
		$this->assertSame( array(), $s['months'] );
	}
}
