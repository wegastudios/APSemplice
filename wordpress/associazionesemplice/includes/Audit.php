<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Registro delle azioni: chi ha fatto cosa e quando. Serve soprattutto quando le persone che operano
 * sono più di una. Non contiene dati personali oltre all'id utente: i dettagli sono id e importi.
 */
final class Audit {

	public static function log( string $action, string $object_type = '', ?int $object_id = null, array $details = array() ): void {
		Db::db()->insert(
			Db::t( 'audit_log' ),
			array(
				'created_at'  => Db::now(),
				'user_id'     => get_current_user_id() ?: null,
				'action'      => substr( $action, 0, 60 ),
				'object_type' => substr( $object_type, 0, 30 ),
				'object_id'   => $object_id,
				'details'     => $details ? wp_json_encode( $details ) : null,
			)
		);
	}

	/** @return array[] dal più recente */
	public static function recent( int $limit = 100, string $action_prefix = '' ): array {
		$db  = Db::db();
		$sql = 'SELECT a.*, u.display_name FROM ' . Db::t( 'audit_log' ) . ' a LEFT JOIN ' . $db->users . ' u ON u.ID = a.user_id';
		$args = array();
		if ( '' !== $action_prefix ) {
			$sql   .= ' WHERE a.action LIKE %s';
			$args[] = $db->esc_like( $action_prefix ) . '%';
		}
		$sql   .= ' ORDER BY a.id DESC LIMIT %d';
		$args[] = max( 1, $limit );
		return $db->get_results( $db->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}
}
