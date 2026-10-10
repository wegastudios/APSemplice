<?php
use AssociazioneSemplice\Visibility as V;
use PHPUnit\Framework\TestCase;

final class VisibilityTest extends TestCase {
	private function ctx( array $over = array() ): array {
		return array_merge(
			array( 'is_admin' => false, 'logged_in' => true, 'member_area_allowed' => true, 'active_member' => true, 'person_type' => 'ordinary',
				'required_activity_ids' => array(), 'my_activity_ids' => array(), 'taught_activity_ids' => array() ),
			$over
		);
	}

	public function test_public_is_visible_to_everyone_even_without_license(): void {
		$this->assertTrue( V::decide( V::PUBLIC_, array() ) );
		$this->assertTrue( V::decide( V::PUBLIC_, $this->ctx( array( 'logged_in' => false, 'member_area_allowed' => false ) ) ) );
	}

	public function test_admin_sees_everything(): void {
		foreach ( array( V::MEMBERS, V::VOLUNTEERS, V::ACTIVITY ) as $rule ) {
			$this->assertTrue( V::decide( $rule, $this->ctx( array( 'is_admin' => true, 'logged_in' => false, 'active_member' => false, 'required_activity_ids' => array( 5 ) ) ) ), $rule );
		}
	}

	public function test_anonymous_sees_no_restricted_content(): void {
		foreach ( array( V::MEMBERS, V::VOLUNTEERS, V::ACTIVITY ) as $rule ) {
			$this->assertFalse( V::decide( $rule, $this->ctx( array( 'logged_in' => false ) ) ), $rule );
		}
		$this->assertSame( 'login', V::denial_reason( $this->ctx( array( 'logged_in' => false ) ) ) );
	}

	public function test_members_need_a_valid_card(): void {
		$this->assertTrue( V::decide( V::MEMBERS, $this->ctx() ) );
		$this->assertFalse( V::decide( V::MEMBERS, $this->ctx( array( 'active_member' => false ) ) ), 'tessera scaduta' );
	}

	public function test_volunteers_only(): void {
		$this->assertFalse( V::decide( V::VOLUNTEERS, $this->ctx() ) );
		$this->assertTrue( V::decide( V::VOLUNTEERS, $this->ctx( array( 'person_type' => 'volunteer' ) ) ) );
		$this->assertFalse( V::decide( V::VOLUNTEERS, $this->ctx( array( 'person_type' => 'volunteer', 'active_member' => false ) ) ) );
	}

	public function test_activity_rule_needs_participation_in_one_of_the_activities(): void {
		$base = array( 'required_activity_ids' => array( 5, 6 ) );
		$this->assertFalse( V::decide( V::ACTIVITY, $this->ctx( $base ) ), 'socio non iscritto' );
		$this->assertTrue( V::decide( V::ACTIVITY, $this->ctx( array_merge( $base, array( 'my_activity_ids' => array( 6 ) ) ) ) ), 'iscritto a una delle due' );
		$this->assertFalse( V::decide( V::ACTIVITY, $this->ctx( array_merge( $base, array( 'my_activity_ids' => array( 7 ) ) ) ) ), 'iscritto a un\'altra attività' );
	}

	public function test_the_instructor_sees_content_of_the_activity_they_teach(): void {
		$ctx = $this->ctx( array( 'person_type' => 'volunteer', 'required_activity_ids' => array( 5 ), 'taught_activity_ids' => array( 5 ) ) );
		$this->assertTrue( V::decide( V::ACTIVITY, $ctx ) );
		$this->assertFalse( V::decide( V::ACTIVITY, array_merge( $ctx, array( 'taught_activity_ids' => array( 9 ) ) ) ) );
	}

	public function test_activity_rule_without_activities_behaves_like_members_only(): void {
		$this->assertTrue( V::decide( V::ACTIVITY, $this->ctx() ) );
		$this->assertFalse( V::decide( V::ACTIVITY, $this->ctx( array( 'active_member' => false ) ) ) );
	}

	public function test_suspended_license_hides_restricted_content_from_members(): void {
		$ctx = $this->ctx( array( 'member_area_allowed' => false ) );
		foreach ( array( V::MEMBERS, V::VOLUNTEERS, V::ACTIVITY ) as $rule ) {
			$this->assertFalse( V::decide( $rule, $ctx ), $rule );
		}
		$this->assertSame( 'license', V::denial_reason( $ctx ) );
	}

	public function test_unknown_rule_is_denied_and_labels_exist(): void {
		$this->assertFalse( V::decide( 'boh', $this->ctx() ) );
		$this->assertTrue( V::is_valid( 'members' ) );
		$this->assertSame( 'Iscritti: Yoga', V::short_label( V::ACTIVITY, array( 'Yoga' ) ) );
		$this->assertSame( 'Pubblico', V::short_label( V::PUBLIC_ ) );
	}
}
