<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Livelli di socio: l'associazione ne definisce quanti vuole, ognuno con il suo nome, la sua base
 * (fondatore, ordinario, socio e volontario) e, se serve, una quota propria.
 *
 * La base decide il comportamento (tessera sempre rinnovata, attività, cariche); il livello decide il nome mostrato e la quota.
 * Chi non ha un livello scelto usa il nome della base e la quota predefinita.
 * I soci dello stesso nucleo familiare (capofamiglia + familiari) pagano la quota ridotta della percentuale impostata, il capofamiglia la quota piena.
 */
final class Levels {

	const MAX = 40;

	/** Le basi tra cui scegliere (l'ospite non ha livelli). */
	public static function bases(): array {
		$out = array();
		foreach ( MemberType::member_types() as $t ) {
			$out[ $t ] = MemberType::label( $t );
		}
		return $out;
	}

	private static function db(): \wpdb {
		return Db::db();
	}

	/** @return array[] livelli in ordine; $only_active = solo quelli proponibili per i nuovi soci */
	public static function all( bool $only_active = false ): array {
		$rows = self::db()->get_results( 'SELECT * FROM ' . Db::t( 'member_levels' ) . ( $only_active ? ' WHERE active = 1' : '' ) . ' ORDER BY sort_order, id', ARRAY_A ) ?: array();
		return $rows;
	}

	public static function get( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'member_levels' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	/** Nome da mostrare per una persona (riga di `people`): il livello, o la base se non ne ha uno. */
	public static function label( array $person ): string {
		$lv = ! empty( $person['level_id'] ) ? self::get( (int) $person['level_id'] ) : null;
		if ( $lv && $lv['base_type'] === $person['type'] ) {
			return (string) $lv['name'];
		}
		return MemberType::label( (string) $person['type'] );
	}

	/** Livelli proponibili a un nuovo socio di quella base: tutti, oppure solo il primo se le quote diverse non ci sono (edizione gratuita o licenza scaduta). */
	public static function choices( string $base ): array {
		$out = array();
		foreach ( self::all( true ) as $lv ) {
			if ( $lv['base_type'] === $base ) {
				$out[] = $lv;
			}
		}
		return Edition::has( 'levels' ) ? $out : array_slice( $out, 0, 1 );
	}

	/** Quota associativa piena del livello (centesimi): quella del livello o, se vuota, la quota predefinita di Impostazioni. */
	public static function base_fee( ?array $person ): int {
		$default = (int) Settings::get( 'membership_fee_cents' );
		if ( ! Edition::has( 'levels' ) ) {
			return $default; // senza le quote diverse (edizione gratuita o licenza scaduta) c'è una quota sola
		}
		if ( ! $person || empty( $person['level_id'] ) ) {
			return $default;
		}
		$lv = self::get( (int) $person['level_id'] );
		if ( ! $lv || $lv['base_type'] !== $person['type'] || null === $lv['fee_cents'] ) {
			return $default;
		}
		return max( 0, (int) $lv['fee_cents'] );
	}

	/** Il socio fa parte di un nucleo come familiare di un capofamiglia ancora socio? */
	public static function is_family_member( ?array $person ): bool {
		if ( ! $person || empty( $person['family_head_id'] ) ) {
			return false;
		}
		$head = Plugin::people()->get( (int) $person['family_head_id'] );
		return $head && MemberType::is_member( $head['type'] ) && (int) $head['id'] !== (int) $person['id'];
	}

	/** Quota da versare per la tessera: quella del livello, ridotta se il socio è familiare di un capofamiglia. */
	public static function fee_for( ?array $person ): int {
		$fee = self::base_fee( $person );
		$pct = Settings::family_discount();
		if ( $fee > 0 && $pct > 0 && self::is_family_member( $person ) ) {
			$fee = (int) round( $fee * ( 100 - $pct ) / 100 );
		}
		return $fee;
	}

	/**
	 * Salva l'elenco dei livelli dal modulo: righe con id (se esistente), nome, base, quota (vuota = quella predefinita), attivo.
	 * I livelli usati da qualcuno non si cancellano: si disattivano. @throws \InvalidArgumentException
	 *
	 * @param array[] $rows
	 */
	public static function save( array $rows ): void {
		$db   = self::db();
		$tbl  = Db::t( 'member_levels' );
		$keep = array();
		$clean = array();
		$names = array();
		foreach ( $rows as $r ) {
			$name = trim( sanitize_text_field( (string) ( $r['name'] ?? '' ) ) );
			if ( '' === $name ) {
				continue; // riga vuota
			}
			$name = mb_substr( $name, 0, 80 );
			$base = (string) ( $r['base_type'] ?? '' );
			if ( ! MemberType::is_member( $base ) ) {
				throw new \InvalidArgumentException( 'Scegli la base del livello «' . $name . '».' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
			if ( isset( $names[ $key ] ) ) {
				throw new \InvalidArgumentException( 'Il livello «' . $name . '» è scritto due volte.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			$names[ $key ] = true;
			$fee_txt       = trim( (string) ( $r['fee'] ?? '' ) );
			$fee           = '' === $fee_txt ? null : Money::parse( $fee_txt );
			if ( '' !== $fee_txt && null === $fee ) {
				throw new \InvalidArgumentException( 'La quota del livello «' . $name . '» non è un importo valido.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			$clean[] = array( 'id' => (int) ( $r['id'] ?? 0 ), 'name' => $name, 'base_type' => $base, 'fee_cents' => $fee, 'active' => ! empty( $r['active'] ) ? 1 : 0 );
		}
		if ( count( $clean ) > self::MAX ) {
			throw new \InvalidArgumentException( 'Al massimo ' . self::MAX . ' livelli.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		foreach ( MemberType::member_types() as $t ) {
			$has = false;
			foreach ( $clean as $c ) {
				if ( $c['base_type'] === $t && $c['active'] ) {
					$has = true;
				}
			}
			if ( ! $has ) {
				throw new \InvalidArgumentException( 'Serve almeno un livello attivo per ogni base (manca: ' . MemberType::label( $t ) . ').' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
		}
		$now_sort = 0;
		foreach ( $clean as $c ) {
			$data = array( 'name' => $c['name'], 'base_type' => $c['base_type'], 'fee_cents' => $c['fee_cents'], 'active' => $c['active'], 'sort_order' => $now_sort++ );
			$old  = $c['id'] ? self::get( $c['id'] ) : null;
			if ( $old ) {
				if ( $old['base_type'] !== $c['base_type'] && self::in_use( (int) $old['id'] ) ) {
					throw new \InvalidArgumentException( 'La base del livello «' . $old['name'] . '» non si può cambiare finché ci sono soci con quel livello.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
				}
				$db->update( $tbl, $data, array( 'id' => (int) $old['id'] ) );
				$keep[] = (int) $old['id'];
			} else {
				$db->insert( $tbl, $data + array( 'created_at' => Db::now() ) );
				$keep[] = (int) $db->insert_id;
			}
		}
		foreach ( self::all() as $lv ) {
			if ( in_array( (int) $lv['id'], $keep, true ) ) {
				continue;
			}
			if ( self::in_use( (int) $lv['id'] ) ) {
				$db->update( $tbl, array( 'active' => 0 ), array( 'id' => (int) $lv['id'] ) ); // ha dei soci: resta, ma non è più proponibile
			} else {
				$db->delete( $tbl, array( 'id' => (int) $lv['id'] ) );
			}
		}
		Audit::log( 'levels.saved', 'settings', 0, array( 'levels' => count( $clean ) ) );
	}

	public static function in_use( int $id ): bool {
		return (bool) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) . ' WHERE deleted_at IS NULL AND level_id = %d', $id ) );
	}

	/** Livelli iniziali: uno per base, con la quota predefinita. */
	public static function seed(): void {
		$db  = self::db();
		$tbl = Db::t( 'member_levels' );
		if ( (int) $db->get_var( "SELECT COUNT(*) FROM $tbl" ) > 0 ) {
			return;
		}
		$i = 0;
		foreach ( MemberType::member_types() as $t ) {
			$db->insert( $tbl, array( 'name' => MemberType::label( $t ), 'base_type' => $t, 'fee_cents' => null, 'sort_order' => $i++, 'active' => 1, 'created_at' => Db::now() ) );
		}
	}
}
