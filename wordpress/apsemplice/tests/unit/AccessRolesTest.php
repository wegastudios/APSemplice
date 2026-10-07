<?php
use ApSemplice\Access;
use PHPUnit\Framework\TestCase;

final class AccessRolesTest extends TestCase {

	private function member( string $type = 'ordinary' ): array {
		return array( 'id' => 9, 'type' => $type );
	}

	public function test_entity_staff_checks_entrances_of_every_event(): void {
		$this->assertTrue( Access::decide( 'apse_manage_event', false, $this->member(), array( 'is_entity_staff' => true ) ) );
		$this->assertFalse( Access::decide( 'apse_manage_event', false, $this->member(), array() ) );
	}

	public function test_entity_staff_does_not_cash_or_manage_money(): void {
		$ctx = array( 'is_entity_staff' => true );
		foreach ( array( 'apse_door_cash', 'apse_collect', 'apse_add_expense', 'apse_register_member' ) as $a ) {
			$this->assertFalse( Access::decide( $a, false, $this->member(), $ctx ), $a );
		}
	}

	public function test_event_staff_cashes_only_when_enabled(): void {
		$this->assertFalse( Access::decide( 'apse_door_cash', false, $this->member(), array( 'is_staff' => true ) ) );
		$this->assertTrue( Access::decide( 'apse_door_cash', false, $this->member(), array( 'can_cash' => true ) ) );
		$this->assertTrue( Access::decide( 'apse_manage_event', false, $this->member(), array( 'is_staff' => true ) ) );
	}

	public function test_treasurer_sells_events_collects_and_registers_but_does_not_check_entrances(): void {
		$ctx = array( 'is_treasurer' => true );
		foreach ( array( 'apse_door_cash', 'apse_collect', 'apse_add_expense', 'apse_register_member' ) as $a ) {
			$this->assertTrue( Access::decide( $a, false, $this->member(), $ctx ), $a );
		}
		$this->assertFalse( Access::decide( 'apse_manage_event', false, $this->member(), $ctx ) );
	}

	public function test_guest_never_gets_a_role_permission(): void {
		$guest = $this->member( 'guest' );
		foreach ( array( 'apse_door_cash', 'apse_collect', 'apse_register_member', 'apse_manage_event' ) as $a ) {
			$this->assertFalse( Access::decide( $a, false, $guest, array( 'is_treasurer' => true, 'is_entity_staff' => true, 'can_cash' => true ) ), $a );
		}
	}

	public function test_registering_members_is_a_declared_ability(): void {
		$this->assertContains( 'apse_register_member', Access::ABILITIES );
	}
}
