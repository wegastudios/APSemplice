<?php
namespace Portale;

/** Registro delle azioni fatte dal pannello di gestione. */
final class Audit {

	public static function log( Db $db, string $actor, string $action, string $detail = '' ): void {
		$db->run( 'INSERT INTO audit (id, at, actor, action, detail) VALUES (?,?,?,?,?)', array( Db::id(), Db::now(), substr( $actor, 0, 190 ), substr( $action, 0, 60 ), substr( $detail, 0, 1000 ) ) );
	}

	public static function recent( Db $db, int $limit = 100 ): array {
		return $db->all( 'SELECT * FROM audit ORDER BY at DESC, id DESC LIMIT ' . max( 1, min( 500, $limit ) ) );
	}
}
