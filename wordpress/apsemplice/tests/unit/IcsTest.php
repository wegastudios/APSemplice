<?php
use ApSemplice\Ics;
use ApSemplice\PaymentCalc;
use PHPUnit\Framework\TestCase;

final class IcsTest extends TestCase {

	public function test_escape(): void {
		$this->assertSame( 'Yoga\, livello 1\; sala A\\\\B\nsecondo piano', Ics::escape( "Yoga, livello 1; sala A\\B\nsecondo piano" ) );
	}

	public function test_long_lines_are_folded_without_breaking_utf8(): void {
		$line   = 'SUMMARY:' . str_repeat( 'è', 80 );
		$folded = Ics::fold( $line );
		foreach ( explode( "\r\n", $folded ) as $part ) {
			$this->assertLessThanOrEqual( 75, strlen( $part ) );
			$this->assertTrue( mb_check_encoding( $part, 'UTF-8' ) );
		}
		$this->assertSame( $line, str_replace( "\r\n ", '', $folded ) );
		$this->assertSame( 'SHORT', Ics::fold( 'SHORT' ) );
	}

	public function test_timed_event_is_converted_to_utc_with_daylight_saving(): void {
		$winter = Ics::event( array( 'uid' => 'a@x', 'summary' => 'Yoga', 'date' => '2026-01-13', 'start' => '18:30', 'end' => '19:30' ), 'Europe/Rome', '20260101T000000Z' );
		$this->assertStringContainsString( "DTSTART:20260113T173000Z\r\n", $winter );
		$this->assertStringContainsString( "DTEND:20260113T183000Z\r\n", $winter );
		$summer = Ics::event( array( 'uid' => 'a@x', 'summary' => 'Yoga', 'date' => '2026-07-14', 'start' => '18:30', 'end' => null ), 'Europe/Rome', '20260101T000000Z' );
		$this->assertStringContainsString( "DTSTART:20260714T163000Z\r\n", $summer );
		$this->assertStringContainsString( "DTEND:20260714T183000Z\r\n", $summer, 'senza orario di fine: due ore' );
	}

	public function test_all_day_event_and_calendar_wrapper(): void {
		$ics = Ics::calendar( 'APS — Corsi', array( array( 'uid' => 'b@x', 'summary' => 'Open day', 'date' => '2026-09-20', 'start' => null, 'end' => null, 'location' => 'Sala, 1' ) ), 'Europe/Rome', '20260101T000000Z' );
		$this->assertStringStartsWith( "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics );
		$this->assertStringContainsString( "DTSTART;VALUE=DATE:20260920\r\nDTEND;VALUE=DATE:20260921\r\n", $ics );
		$this->assertStringContainsString( "LOCATION:Sala\\, 1\r\n", $ics );
		$this->assertStringEndsWith( "END:VEVENT\r\nEND:VCALENDAR\r\n", $ics );
		$this->assertStringNotContainsString( "\n\n", str_replace( "\r\n", "\n", $ics ), 'nessuna riga vuota' );
	}

	public function test_one_off_course_payment(): void {
		$s = PaymentCalc::compute_once( 12000, '2025-10', null, array() );
		$this->assertSame( 12000, $s['total_due'] );
		$this->assertFalse( $s['regular'] );
		$this->assertSame( PaymentCalc::UNPAID, $s['months'][0]['state'] );
		$s = PaymentCalc::compute_once( 12000, '2025-10', null, array( '2025-10' => 5000 ) );
		$this->assertSame( PaymentCalc::PARTIAL, $s['months'][0]['state'] );
		$this->assertSame( -7000, $s['balance'] );
		$s = PaymentCalc::compute_once( 12000, '2025-10', null, array( '2025-10' => 7000, '2025-11' => 5000 ) );
		$this->assertTrue( $s['regular'], 'le rate si sommano, qualunque sia il mese di competenza' );
		$this->assertSame( array(), $s['unpaid_months'] );
		$s = PaymentCalc::compute_once( 12000, '2025-10', '2025-10', array() );
		$this->assertSame( 0, $s['total_due'], 'cancellato prima di pagare: nulla è dovuto' );
		$this->assertTrue( $s['regular'] );
	}
}
