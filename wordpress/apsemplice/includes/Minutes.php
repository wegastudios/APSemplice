<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/** Libro dei verbali: assemblee dei soci e riunioni del consiglio direttivo, con numerazione progressiva per tipo e anno. */
final class Minutes {

	const ASSEMBLY = 'assembly';
	const BOARD    = 'board';

	const MAX_BODY = 60000;

	public static function kinds(): array {
		return array( self::ASSEMBLY => 'Assemblea dei soci', self::BOARD => 'Consiglio direttivo' );
	}

	private static function db(): \wpdb {
		return Db::db();
	}

	private static function date( $v ): ?string {
		$v  = trim( (string) $v );
		$dt = \DateTime::createFromFormat( 'Y-m-d', $v );
		return $dt && $dt->format( 'Y-m-d' ) === $v ? $v : null;
	}

	private static function clean( array $in ): array {
		$kind = (string) ( $in['kind'] ?? '' );
		if ( ! isset( self::kinds()[ $kind ] ) ) {
			throw new \InvalidArgumentException( 'Scegli il tipo di riunione.' );
		}
		$date = self::date( $in['meeting_date'] ?? '' );
		if ( ! $date ) {
			throw new \InvalidArgumentException( 'Indica la data della riunione.' );
		}
		$title = trim( sanitize_text_field( (string) ( $in['title'] ?? '' ) ) );
		if ( '' === $title ) {
			throw new \InvalidArgumentException( 'Indica un titolo (ad esempio «Approvazione del bilancio»).' );
		}
		$body = trim( sanitize_textarea_field( (string) ( $in['body'] ?? '' ) ) );
		if ( '' === $body ) {
			throw new \InvalidArgumentException( 'Il verbale non ha contenuto.' );
		}
		if ( mb_strlen( $body ) > self::MAX_BODY ) {
			throw new \InvalidArgumentException( 'Il testo è troppo lungo.' );
		}
		$approved = trim( (string) ( $in['approved_on'] ?? '' ) );
		if ( '' !== $approved && ! self::date( $approved ) ) {
			throw new \InvalidArgumentException( 'Data di approvazione non valida.' );
		}
		return array(
			'kind'         => $kind,
			'meeting_date' => $date,
			'place'        => mb_substr( trim( sanitize_text_field( (string) ( $in['place'] ?? '' ) ) ), 0, 190 ),
			'title'        => mb_substr( $title, 0, 190 ),
			'attendees'    => mb_substr( trim( sanitize_textarea_field( (string) ( $in['attendees'] ?? '' ) ) ), 0, 5000 ),
			'agenda'       => mb_substr( trim( sanitize_textarea_field( (string) ( $in['agenda'] ?? '' ) ) ), 0, 5000 ),
			'body'         => $body,
			'approved_on'  => '' === $approved ? null : $approved,
		);
	}

	/** @throws \InvalidArgumentException */
	public static function save( int $id, array $in, ?int $user_id = null ): int {
		$d = self::clean( $in );
		if ( $id ) {
			if ( ! self::get( $id ) ) {
				throw new \InvalidArgumentException( 'Verbale non trovato.' );
			}
			self::db()->update( Db::t( 'minutes' ), $d + array( 'updated_at' => Db::now() ), array( 'id' => $id ) );
			Audit::log( 'minutes.updated', 'minutes', $id, array( 'kind' => $d['kind'] ) );
			return $id;
		}
		$ok = self::db()->insert( Db::t( 'minutes' ), $d + array( 'created_by' => $user_id, 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
		if ( ! $ok ) {
			throw new \InvalidArgumentException( 'Impossibile salvare il verbale (errore del database).' );
		}
		$id = (int) self::db()->insert_id;
		Audit::log( 'minutes.created', 'minutes', $id, array( 'kind' => $d['kind'] ) );
		return $id;
	}

	public static function delete( int $id ): void {
		if ( ! self::get( $id ) ) {
			throw new \InvalidArgumentException( 'Verbale non trovato.' );
		}
		self::db()->update( Db::t( 'minutes' ), array( 'deleted_at' => Db::now() ), array( 'id' => $id ) );
		Audit::log( 'minutes.deleted', 'minutes', $id, array() );
	}

	public static function get( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'minutes' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $r ? self::with_number( $r ) : null;
	}

	/** Numero progressivo del verbale nel suo tipo e anno (1/2026, 2/2026…), in ordine di data. */
	private static function with_number( array $r ): array {
		$year        = substr( (string) $r['meeting_date'], 0, 4 );
		$n           = (int) self::db()->get_var(
			self::db()->prepare(
				'SELECT COUNT(*) FROM ' . Db::t( 'minutes' ) . ' WHERE deleted_at IS NULL AND kind = %s AND YEAR(meeting_date) = %d AND (meeting_date < %s OR (meeting_date = %s AND id <= %d))',
				$r['kind'],
				(int) $year,
				$r['meeting_date'],
				$r['meeting_date'],
				(int) $r['id']
			)
		);
		$r['number'] = $n . '/' . $year;
		return $r;
	}

	/** Verbali, dal più recente. @return array[] */
	public static function all( ?string $kind = null, ?int $year = null ): array {
		$where = array( 'deleted_at IS NULL' );
		$args  = array();
		if ( $kind && isset( self::kinds()[ $kind ] ) ) {
			$where[] = 'kind = %s';
			$args[]  = $kind;
		}
		if ( $year ) {
			$where[] = 'YEAR(meeting_date) = %d';
			$args[]  = $year;
		}
		$sql  = 'SELECT * FROM ' . Db::t( 'minutes' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY meeting_date DESC, id DESC';
		$rows = $args ? self::db()->get_results( self::db()->prepare( $sql, $args ), ARRAY_A ) : self::db()->get_results( $sql, ARRAY_A );
		return array_map( array( __CLASS__, 'with_number' ), $rows ?: array() );
	}
}
