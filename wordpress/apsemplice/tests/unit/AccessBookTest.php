<?php
use ApSemplice\Access;
use PHPUnit\Framework\TestCase;

final class AccessBookTest extends TestCase {
	public function test_members_book_for_themselves_and_for_their_guests_only(): void {
		$me = array( 'id' => 7, 'type' => 'ordinary' );
		$this->assertTrue( Access::decide( 'aps_book_for', false, $me, array( 'person_id' => 7 ) ), 'sé stesso' );
		$this->assertTrue( Access::decide( 'aps_book_for', false, $me, array( 'person_id' => 9, 'host_person_id' => 7 ) ), 'un proprio ospite' );
		$this->assertFalse( Access::decide( 'aps_book_for', false, $me, array( 'person_id' => 9, 'host_person_id' => 8 ) ), 'l\'ospite di un altro' );
		$this->assertFalse( Access::decide( 'aps_book_for', false, $me, array( 'person_id' => 9 ) ), 'un altro socio' );
	}

	public function test_admin_can_always_book_and_guests_never_log_in(): void {
		$this->assertTrue( Access::decide( 'aps_book_for', true, null, array( 'person_id' => 9 ) ) );
		$this->assertFalse( Access::decide( 'aps_book_for', false, array( 'id' => 7, 'type' => 'guest' ), array( 'person_id' => 7 ) ) );
		$this->assertFalse( Access::decide( 'aps_book_for', false, null, array( 'person_id' => 7 ) ) );
	}
}
