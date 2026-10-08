<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

/** Cariche del consiglio direttivo: 1 presidente, 1 vicepresidente e un numero di consiglieri (impostabile). */
final class BoardRole {
	const PRESIDENT      = 'president';
	const VICE_PRESIDENT = 'vice_president';
	const COUNCILLOR     = 'councillor';

	public static function labels(): array {
		return array( self::PRESIDENT => 'Presidente', self::VICE_PRESIDENT => 'Vicepresidente', self::COUNCILLOR => 'Consigliere' );
	}

	public static function label( ?string $role ): string {
		return self::labels()[ (string) $role ] ?? '';
	}

	public static function is_valid( string $role ): bool {
		return isset( self::labels()[ $role ] );
	}

	/** Quanti posti ha ogni carica. */
	public static function seats( string $role, int $councillors ): int {
		return self::COUNCILLOR === $role ? max( 0, $councillors ) : 1;
	}

	/** Possono ricoprire una carica solo i soci fondatori e ordinari. */
	public static function eligible_type( string $type ): bool {
		return MemberType::FOUNDER === $type || MemberType::ORDINARY === $type;
	}
}
