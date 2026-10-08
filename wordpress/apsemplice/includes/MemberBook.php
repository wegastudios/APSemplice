<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Libro soci: l'elenco progressivo di tutti i soci con la data di ingresso e, se c'è stato, di cessazione (recesso, esclusione, decesso).
 * Gli ospiti non sono soci e non compaiono.
 */
final class MemberBook {

	const IN_FORCE = 'in_force';
	const LEFT     = 'left';

	private static function db(): \wpdb {
		return Db::db();
	}

	/** Registra (o toglie, con data vuota) la cessazione di un socio. @throws \InvalidArgumentException */
	public static function set_left( int $person_id, string $date, string $reason = '' ): void {
		$p = Plugin::people()->get( $person_id );
		if ( ! $p || ! MemberType::is_member( $p['type'] ) ) {
			throw new \InvalidArgumentException( 'La cessazione si registra per un socio.' );
		}
		$date = trim( $date );
		if ( '' === $date ) {
			self::db()->update( Db::t( 'people' ), array( 'left_on' => null, 'left_reason' => null, 'suspended_at' => null, 'updated_at' => Db::now() ), array( 'id' => $person_id ) );
			if ( MemberType::is_auto_renewed( $p['type'] ) ) {
				Plugin::people()->refresh_founder_membership( $person_id ); // il fondatore torna con la tessera sempre rinnovata
			}
			Audit::log( 'member.left_cleared', 'person', $person_id, array() );
			return;
		}
		$dt = \DateTime::createFromFormat( 'Y-m-d', $date );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
			throw new \InvalidArgumentException( 'Data di cessazione non valida.' );
		}
		if ( $date > Db::today() ) {
			throw new \InvalidArgumentException( 'La cessazione non può essere nel futuro.' );
		}
		if ( $date < (string) $p['joined_on'] ) {
			throw new \InvalidArgumentException( 'La cessazione non può essere prima dell\'ingresso (' . $p['joined_on'] . ').' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		// Chi ha lasciato l'associazione non è più un socio attivo: non prenota, non riceve promemoria né comunicazioni ai soci, non compare tra quelli da rinnovare.
		// Si usa lo stato «sospeso»; per il fondatore (sempre in regola) si ferma la tessera alla data di cessazione.
		self::db()->update( Db::t( 'people' ), array( 'left_on' => $date, 'left_reason' => mb_substr( trim( sanitize_text_field( $reason ) ), 0, 190 ), 'suspended_at' => ! empty( $p['suspended_at'] ) ? $p['suspended_at'] : Db::now(), 'updated_at' => Db::now() ), array( 'id' => $person_id ) );
		if ( MemberType::is_auto_renewed( $p['type'] ) ) {
			self::db()->query( self::db()->prepare( 'UPDATE ' . Db::t( 'memberships' ) . " SET valid_to = %s WHERE person_id = %d AND social_year = 'FOUNDER' AND deleted_at IS NULL", $date, $person_id ) );
		}
		Audit::log( 'member.left', 'person', $person_id, array( 'date' => $date ) );
	}

	/**
	 * Righe del libro soci in ordine di ingresso, con numero progressivo.
	 *
	 * @param string $filter '' tutti, in_force (in carica), left (cessati)
	 * @return array[]
	 */
	public static function rows( string $filter = '' ): array {
		$rows = self::db()->get_results(
			'SELECT * FROM ' . Db::t( 'people' ) . " WHERE deleted_at IS NULL AND type <> 'guest' ORDER BY joined_on, id",
			ARRAY_A
		) ?: array();
		$out = array();
		$n   = 0;
		foreach ( $rows as $p ) {
			$n++;
			$gone = ! empty( $p['left_on'] );
			if ( self::IN_FORCE === $filter && $gone ) {
				continue;
			}
			if ( self::LEFT === $filter && ! $gone ) {
				continue;
			}
			$anon = ! empty( $p['anonymized_at'] );
			$out[] = array(
				'n'           => $n,
				'id'          => (int) $p['id'],
				'name'        => $anon ? '(dati anonimizzati)' : trim( $p['last_name'] . ' ' . $p['first_name'] ),
				'tax_code'    => $anon ? '' : (string) $p['tax_code'],
				'card_number' => (string) $p['card_number'],
				'level'       => Levels::label( $p ),
				'joined_on'   => (string) $p['joined_on'],
				'left_on'     => $gone ? (string) $p['left_on'] : '',
				'left_reason' => (string) $p['left_reason'],
				'status'      => $gone ? self::LEFT : self::IN_FORCE,
			);
		}
		return $out;
	}
}
