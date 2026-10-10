<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Le quattro figure gestite dall'associazione.
 *
 *  - founder   Socio fondatore: tessera sempre rinnovata (scadenza a 99 anni, configurabile).
 *  - ordinary  Socio ordinario: tessera valida per anno sociale, rinnovata con la quota.
 *  - volunteer Socio e volontario: come l'ordinario, e può tenere le attività.
 *  - guest     Ospite di un socio: non è socio, partecipa alle attività tramite un socio.
 */
final class MemberType {
	const FOUNDER   = 'founder';
	const ORDINARY  = 'ordinary';
	const VOLUNTEER = 'volunteer';
	const GUEST     = 'guest';

	public static function labels(): array {
		return array(
			self::FOUNDER   => 'Socio fondatore',
			self::ORDINARY  => 'Socio ordinario',
			self::VOLUNTEER => 'Socio e volontario',
			self::GUEST     => 'Ospite di un socio',
		);
	}

	/** Tipi che sono soci veri (tutti tranne l'ospite). */
	public static function member_types(): array {
		return array( self::FOUNDER, self::ORDINARY, self::VOLUNTEER );
	}

	public static function label( string $type ): string {
		$l = self::labels();
		return $l[ $type ] ?? $type;
	}

	public static function is_valid( string $type ): bool {
		return isset( self::labels()[ $type ] );
	}

	public static function is_member( string $type ): bool {
		return in_array( $type, self::member_types(), true );
	}

	/** Solo i "soci e volontari" tengono le attività. */
	public static function can_teach( string $type ): bool {
		return self::VOLUNTEER === $type;
	}

	/** Il fondatore ha la tessera sempre rinnovata, senza pagare la quota ogni anno. */
	public static function is_auto_renewed( string $type ): bool {
		return self::FOUNDER === $type;
	}

	/** Gli iscritti sono utenti WordPress: l'email è obbligatoria. L'ospite no. */
	public static function requires_email( string $type ): bool {
		return self::is_member( $type );
	}

	public static function requires_host( string $type ): bool {
		return self::GUEST === $type;
	}

	/** Riconosce il tipo da testo libero (import CSV). Null se non riconosciuto. */
	public static function from_text( string $text ): ?string {
		$n = Text::normalize( $text );
		$map = array(
			'fondatore' => self::FOUNDER, 'sociofondatore' => self::FOUNDER, 'fondatrice' => self::FOUNDER,
			'ordinario' => self::ORDINARY, 'socioordinario' => self::ORDINARY, 'ordinaria' => self::ORDINARY, 'socio' => self::ORDINARY,
			'volontario' => self::VOLUNTEER, 'volontaria' => self::VOLUNTEER, 'sociovolontario' => self::VOLUNTEER,
			'socioevolontario' => self::VOLUNTEER, 'socievolontari' => self::VOLUNTEER, 'sociaevolontaria' => self::VOLUNTEER,
			'ospite' => self::GUEST, 'ospitediunsocio' => self::GUEST,
		);
		return $map[ $n ] ?? null;
	}
}
