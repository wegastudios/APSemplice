<?php
use ApSemplice\Access;
use PHPUnit\Framework\TestCase;

final class AccessBookTest extends TestCase {
	public function test_members_book_for_themselves_and_for_their_guests_only(): void {
		$me = array( 'id' => 7, 'type' => 'ordinary' );
		$this->assertTrue( Access::decide( 'apse_book_for', false, $me, array( 'person_id' => 7 ) ), 'sé stesso' );
		$this->assertTrue( Access::decide( 'apse_book_for', false, $me, array( 'person_id' => 9, 'host_person_id' => 7 ) ), 'un proprio ospite' );
		$this->assertFalse( Access::decide( 'apse_book_for', false, $me, array( 'person_id' => 9, 'host_person_id' => 8 ) ), 'l\'ospite di un altro' );
		$this->assertFalse( Access::decide( 'apse_book_for', false, $me, array( 'person_id' => 9 ) ), 'un altro socio' );
	}

	public function test_admin_can_always_book_and_guests_never_log_in(): void {
		$this->assertTrue( Access::decide( 'apse_book_for', true, null, array( 'person_id' => 9 ) ) );
		$this->assertFalse( Access::decide( 'apse_book_for', false, array( 'id' => 7, 'type' => 'guest' ), array( 'person_id' => 7 ) ) );
		$this->assertFalse( Access::decide( 'apse_book_for', false, null, array( 'person_id' => 7 ) ) );
	}

	public function test_treasurer_can_add_expenses_only_with_the_flag(): void {
		$member    = array( 'id' => 7, 'type' => 'ordinary' );
		$volunteer = array( 'id' => 8, 'type' => 'volunteer' );
		$this->assertTrue( Access::decide( 'apse_add_expense', false, $member, array( 'is_treasurer' => true ) ) );
		$this->assertTrue( Access::decide( 'apse_add_expense', false, $volunteer, array( 'is_treasurer' => true ) ) );
		$this->assertFalse( Access::decide( 'apse_add_expense', false, $member, array() ), 'senza il permesso' );
		$this->assertFalse( Access::decide( 'apse_add_expense', false, array( 'id' => 9, 'type' => 'guest' ), array( 'is_treasurer' => true ) ), 'un ospite no' );
		$this->assertFalse( Access::decide( 'apse_add_expense', false, null, array( 'is_treasurer' => true ) ), 'chi non è un socio no' );
		$this->assertTrue( Access::decide( 'apse_add_expense', true, null, array() ), 'l\'amministratore sì' );
	}

	public function test_event_managers(): void {
		$volunteer = array( 'id' => 8, 'type' => 'volunteer' );
		$member    = array( 'id' => 7, 'type' => 'ordinary' );
		$guest     = array( 'id' => 9, 'type' => 'guest' );
		$this->assertTrue( Access::decide( 'apse_manage_event', false, $volunteer, array( 'instructor_person_id' => 8 ) ), 'l\'istruttore' );
		$this->assertTrue( Access::decide( 'apse_manage_event', false, $member, array( 'instructor_person_id' => 8, 'is_staff' => true ) ), 'un socio indicato come gestore' );
		$this->assertFalse( Access::decide( 'apse_manage_event', false, $member, array( 'instructor_person_id' => 8 ) ), 'un socio qualunque' );
		$this->assertFalse( Access::decide( 'apse_manage_event', false, $volunteer, array( 'instructor_person_id' => 5 ) ), 'un volontario che non tiene l\'evento' );
		$this->assertFalse( Access::decide( 'apse_manage_event', false, $guest, array( 'is_staff' => true ) ), 'un ospite mai' );
		$this->assertFalse( Access::decide( 'apse_manage_event', false, null, array( 'is_staff' => true ) ), 'chi non è socio' );
		$this->assertTrue( Access::decide( 'apse_manage_event', true, null, array() ), 'l\'amministratore' );
	}
}
