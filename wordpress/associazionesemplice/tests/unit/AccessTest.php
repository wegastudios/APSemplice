<?php
use AssociazioneSemplice\Access;
use AssociazioneSemplice\Gatekeeper;
use AssociazioneSemplice\License;
use PHPUnit\Framework\TestCase;

final class AccessTest extends TestCase {
	private function person( int $id, string $type ): array {
		return array( 'id' => $id, 'type' => $type );
	}

	public function test_admin_can_do_everything(): void {
		foreach ( Access::ABILITIES as $a ) {
			$this->assertTrue( Access::decide( $a, true, null, array() ), $a );
		}
	}

	public function test_anonymous_or_non_member_can_do_nothing(): void {
		foreach ( Access::ABILITIES as $a ) {
			$this->assertFalse( Access::decide( $a, false, null, array( 'person_id' => 1 ) ), $a );
		}
	}

	public function test_members_only_touch_their_own_data(): void {
		$me = $this->person( 7, 'ordinary' );
		foreach ( array( 'asem_view_person', 'asem_edit_own_profile', 'asem_view_payments' ) as $a ) {
			$this->assertTrue( Access::decide( $a, false, $me, array( 'person_id' => 7 ) ), $a );
			$this->assertFalse( Access::decide( $a, false, $me, array( 'person_id' => 8 ) ), $a );
		}
	}

	public function test_only_members_can_add_guests_for_themselves(): void {
		$this->assertTrue( Access::decide( 'asem_add_guest', false, $this->person( 7, 'ordinary' ), array( 'person_id' => 7 ) ) );
		$this->assertTrue( Access::decide( 'asem_add_guest', false, $this->person( 7, 'founder' ), array( 'person_id' => 7 ) ) );
		$this->assertFalse( Access::decide( 'asem_add_guest', false, $this->person( 7, 'ordinary' ), array( 'person_id' => 8 ) ) );
		$this->assertFalse( Access::decide( 'asem_add_guest', false, $this->person( 7, 'guest' ), array( 'person_id' => 7 ) ) );
	}

	public function test_volunteer_is_scoped_to_own_activities(): void {
		$vol = $this->person( 3, 'volunteer' );
		foreach ( array( 'asem_view_participants', 'asem_notify_activity', 'asem_view_activity' ) as $a ) {
			$this->assertTrue( Access::decide( $a, false, $vol, array( 'instructor_person_id' => 3 ) ), $a );
			$this->assertFalse( Access::decide( $a, false, $vol, array( 'instructor_person_id' => 4 ) ), $a );
		}
	}

	public function test_non_volunteers_never_get_instructor_powers(): void {
		foreach ( array( 'ordinary', 'founder', 'guest' ) as $t ) {
			$p = $this->person( 3, $t );
			$this->assertFalse( Access::decide( 'asem_notify_activity', false, $p, array( 'instructor_person_id' => 3 ) ), $t );
			$this->assertFalse( Access::decide( 'asem_view_participants', false, $p, array( 'instructor_person_id' => 3 ) ), $t );
		}
	}

	public function test_enrolled_members_can_view_the_activity_but_not_its_participants(): void {
		$me = $this->person( 7, 'ordinary' );
		$this->assertTrue( Access::decide( 'asem_view_activity', false, $me, array( 'instructor_person_id' => 3, 'is_enrolled' => true ) ) );
		$this->assertFalse( Access::decide( 'asem_view_activity', false, $me, array( 'instructor_person_id' => 3, 'is_enrolled' => false ) ) );
		$this->assertFalse( Access::decide( 'asem_view_participants', false, $me, array( 'instructor_person_id' => 3, 'is_enrolled' => true ) ) );
	}

	public function test_unknown_ability_is_denied(): void {
		$this->assertFalse( Access::decide( 'asem_boh', false, $this->person( 1, 'volunteer' ), array( 'person_id' => 1 ) ) );
	}
}

final class GatekeeperTest extends TestCase {
	public function test_member_only_users_are_kept_out_of_admin(): void {
		$this->assertTrue( Gatekeeper::is_member_only( array( 'asem_member' ) ) );
		$this->assertFalse( Gatekeeper::is_member_only( array( 'asem_member', 'editor' ) ) );
		$this->assertFalse( Gatekeeper::is_member_only( array( 'administrator' ) ) );
		$this->assertFalse( Gatekeeper::is_member_only( array() ) );
	}
}

final class LicenseTest extends TestCase {
	public function test_domain_is_normalised(): void {
		$this->assertSame( 'esempio.it', License::domain( 'https://www.Esempio.it/percorso?x=1' ) );
		$this->assertSame( 'blog.esempio.it', License::domain( 'http://blog.esempio.it' ) );
		$this->assertSame( 'esempio.it', License::domain( 'esempio.it/qualcosa' ) );
		$this->assertSame( '', License::domain( '' ) );
	}

}
