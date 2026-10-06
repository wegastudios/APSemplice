<?php
namespace ApSemplice\Admin;

use ApSemplice\AssocPolicies;
use ApSemplice\Attendance;
use ApSemplice\Insurance;
use ApSemplice\MemberBook;
use ApSemplice\Minutes;
use ApSemplice\Plugin;
use ApSemplice\Statement;

defined( 'ABSPATH' ) || exit;

/** Azioni dei registri: cessazione dei soci, verbali, polizze dei volontari, presenze, relazione del rendiconto. Servono i permessi operativi (anche la segreteria). */
final class RegistersActions {

	const ACTIONS = array(
		'apse_member_left'      => 'member_left',
		'apse_minute_save'      => 'minute_save',
		'apse_minute_delete'    => 'minute_delete',
		'apse_insurance_add'    => 'insurance_add',
		'apse_insurance_delete' => 'insurance_delete',
		'apse_attendance_save'  => 'attendance_save',
		'apse_policy_add'       => 'policy_add',
		'apse_policy_delete'    => 'policy_delete',
		'apse_statement_notes'  => 'statement_notes',
	);

	public static function register(): void {
		foreach ( self::ACTIONS as $action => $method ) {
			add_action(
				'admin_post_' . $action,
				function () use ( $action, $method ) {
					if ( ! current_user_can( Plugin::CAP_OPS ) ) {
						wp_die( 'Non autorizzato.', 403 );
					}
					check_admin_referer( $action );
					$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
					$back = ! empty( $post['_back'] ) ? esc_url_raw( $post['_back'] ) : Ui::url( 'apse' );
					try {
						$res = self::$method( $post );
						Ui::redirect( $res[0], $res[1] );
					} catch ( \InvalidArgumentException $e ) {
						Ui::redirect( $back, '', $e->getMessage() );
					} catch ( \Throwable $e ) {
						Ui::redirect( $back, '', 'Errore imprevisto: ' . $e->getMessage() );
					}
				}
			);
		}
	}

	public static function member_left( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		MemberBook::set_left( $id, (string) ( $p['left_on'] ?? '' ), (string) ( $p['left_reason'] ?? '' ) );
		return array( Ui::url( 'apse-person', array( 'id' => $id ) ), '' === trim( (string) ( $p['left_on'] ?? '' ) ) ? 'Cessazione tolta: il socio torna in carica.' : 'Cessazione registrata nel libro soci.' );
	}

	public static function minute_save( array $p ): array {
		$id = Minutes::save( (int) ( $p['id'] ?? 0 ), $p, get_current_user_id() );
		return array( Ui::url( 'apse-minutes', array( 'view' => $id ) ), 'Verbale salvato.' );
	}

	public static function minute_delete( array $p ): array {
		Minutes::delete( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'apse-minutes' ), 'Verbale eliminato.' );
	}

	public static function insurance_add( array $p ): array {
		Insurance::add( (int) ( $p['person_id'] ?? 0 ), (string) ( $p['company'] ?? '' ), (string) ( $p['policy_no'] ?? '' ), (string) ( $p['valid_from'] ?? '' ), (string) ( $p['valid_to'] ?? '' ), (string) ( $p['notes'] ?? '' ) );
		return array( Ui::url( 'apse-volunteers' ), 'Polizza registrata.' );
	}

	public static function insurance_delete( array $p ): array {
		Insurance::delete( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'apse-volunteers' ), 'Polizza eliminata.' );
	}

	public static function policy_add( array $p ): array {
		AssocPolicies::add( (string) ( $p['kind'] ?? '' ), (string) ( $p['company'] ?? '' ), (string) ( $p['policy_no'] ?? '' ), (string) ( $p['valid_from'] ?? '' ), (string) ( $p['valid_to'] ?? '' ), (string) ( $p['premium'] ?? '' ), (string) ( $p['coverage'] ?? '' ) );
		return array( Ui::url( 'apse-volunteers' ), 'Polizza registrata.' );
	}

	public static function policy_delete( array $p ): array {
		AssocPolicies::delete( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'apse-volunteers' ), 'Polizza eliminata.' );
	}

	public static function attendance_save( array $p ): array {
		$aid  = (int) ( $p['activity_id'] ?? 0 );
		$date = (string) ( $p['date'] ?? '' );
		$n    = Attendance::save( $aid, $date, array_map( 'intval', (array) ( $p['present'] ?? array() ) ), get_current_user_id() );
		return array( Ui::url( 'apse-attendance', array( 'activity' => $aid, 'ym' => substr( $date, 0, 7 ), 'date' => $date ) ), 'Presenze registrate (' . $n . ' persone).' );
	}

	public static function statement_notes( array $p ): array {
		$year = (int) ( $p['year'] ?? 0 );
		Statement::save_notes( $year, (string) ( $p['notes'] ?? '' ) );
		return array( Ui::url( 'apse-statement', array( 'year' => $year ) ), 'Relazione salvata.' );
	}
}
