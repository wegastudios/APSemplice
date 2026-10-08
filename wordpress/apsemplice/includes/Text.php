<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || exit;

final class Text {

	/** Minuscolo, senza accenti né simboli: "N. Tessera" => "ntessera". */
	public static function normalize( string $s ): string {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$s = strtr(
			$s,
			array(
				'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
				'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
				'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
			)
		);
		return (string) preg_replace( '/[^a-z0-9]/', '', $s );
	}

	/** Minuscolo con supporto UTF-8, per confronti senza maiuscole/minuscole. */
	public static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}
}
