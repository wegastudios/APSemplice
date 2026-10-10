<?php
use AssociazioneSemplice\Schedule;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase {

	public function test_single_date_with_times(): void {
		$d = Schedule::expand( array( array( 'type' => 'single', 'date' => '2026-09-20', 'from' => '15:00', 'to' => '20:00' ) ) );
		$this->assertSame( array( array( 'date' => '2026-09-20', 'start' => '15:00', 'end' => '20:00' ) ), $d );
	}

	public function test_tuesdays_and_fridays_until_the_end_date(): void {
		$d = Schedule::expand( array( array( 'type' => 'weekly', 'days' => array( 2, 5 ), 'from' => '19:00', 'to' => '20:00', 'start' => '2026-09-01', 'end' => '2026-09-30' ) ) );
		// settembre 2026: martedì 1, 8, 15, 22, 29 - venerdì 4, 11, 18, 25
		$this->assertSame( array( '2026-09-01', '2026-09-04', '2026-09-08', '2026-09-11', '2026-09-15', '2026-09-18', '2026-09-22', '2026-09-25', '2026-09-29' ), array_column( $d, 'date' ) );
		$this->assertSame( '19:00', $d[0]['start'] );
	}

	public function test_every_day_of_the_week(): void {
		$d = Schedule::expand( array( array( 'type' => 'weekly', 'days' => array( 1, 2, 3, 4, 5, 6, 7 ), 'from' => '10:00', 'to' => '11:00', 'start' => '2026-03-01', 'end' => '2026-03-14' ) ) );
		$this->assertCount( 14, $d );
	}

	public function test_open_day_plus_course_and_no_duplicates(): void {
		$d = Schedule::expand(
			array(
				array( 'type' => 'single', 'date' => '2026-09-08', 'from' => '19:00', 'to' => '20:00' ),
				array( 'type' => 'weekly', 'days' => array( 2 ), 'from' => '19:00', 'to' => '20:00', 'start' => '2026-09-01', 'end' => '2026-09-15' ),
			)
		);
		$this->assertSame( array( '2026-09-01', '2026-09-08', '2026-09-15' ), array_column( $d, 'date' ), 'lo stesso giorno e orario non si ripete' );
	}

	public function test_errors(): void {
		foreach (
			array(
				array( array( 'type' => 'single', 'date' => 'boh' ) ),
				array( array( 'type' => 'weekly', 'days' => array(), 'start' => '2026-01-01', 'end' => '2026-02-01' ) ),
				array( array( 'type' => 'weekly', 'days' => array( 1 ), 'start' => '2026-01-01' ) ),
				array( array( 'type' => 'weekly', 'days' => array( 1 ), 'start' => '2026-02-01', 'end' => '2026-01-01' ) ),
				array( array( 'type' => 'single', 'date' => '2026-01-01', 'from' => '20:00', 'to' => '19:00' ) ),
				array( array( 'type' => 'altro' ) ),
			) as $rows
		) {
			try {
				Schedule::expand( $rows );
				$this->fail( 'doveva dare errore' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_too_many_dates(): void {
		$this->expectException( \InvalidArgumentException::class );
		Schedule::expand( array( array( 'type' => 'weekly', 'days' => array( 1, 2, 3, 4, 5, 6, 7 ), 'start' => '2026-01-01', 'end' => '2027-12-31' ) ), 100 );
	}

	public function test_course_slots_from_rows_and_back(): void {
		$slots = Schedule::slots( array( array( 'days' => array( 2, 5 ), 'from' => '19:00', 'to' => '20:00' ), array( 'days' => array( 1 ), 'from' => '20:00', 'to' => '' ) ) );
		$this->assertCount( 3, $slots );
		$rows = Schedule::rows_from_slots( $slots );
		$this->assertSame( array( 2, 5 ), $rows[0]['days'] );
		$this->assertSame( array( 1 ), $rows[1]['days'] );
	}
}
