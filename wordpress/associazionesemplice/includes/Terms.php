<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Tipo di ente e termini per chi partecipa. I testi del plugin sono scritti per un'«associazione» con «soci»: scegliendo un altro tipo di ente
 * (comitato, circolo, onlus…) o un altro termine (iscritti, sostenitori…) i testi si adattano da soli, con gli articoli giusti
 * («l'associazione» → «il comitato», «del socio» → «dell'iscritto», «ai soci» → «alle iscritte»…).
 * Si sostituiscono solo parole intere (mai «soci» dentro «sociale» o «associazione»); gli indirizzi web e email non si toccano.
 */
final class Terms {

	const DEFAULT_ENTITY = 'associazione';
	const DEFAULT_MEMBER = 'socio';

	/** Tipi di ente proposti: nome usato nei testi => genere (f femminile, m maschile). */
	const ENTITIES = array(
		'associazione'   => 'f',
		'ente no profit' => 'm',
		'onlus'          => 'f',
		'comitato'       => 'm',
		'circolo'        => 'm',
	);

	/** Termini per chi partecipa: singolare => [plurale, genere]. */
	const MEMBERS = array(
		'socio'        => array( 'soci', 'm' ),
		'iscritto'     => array( 'iscritti', 'm' ),
		'sostenitore'  => array( 'sostenitori', 'm' ),
		'componente'   => array( 'componenti', 'm' ),
		'socia'        => array( 'socie', 'f' ),
	);

	/** Due versioni base: tipo di ente e termine per chi partecipa. */
	const PRESETS = array(
		'femminile' => array( 'associazione', 'socia' ),
		'maschile'  => array( 'comitato', 'socio' ),
	);

	/** Aggettivi e qualifiche che seguono il termine e cambiano al femminile: maschile => femminile. */
	const ADJECTIVES = array(
		'fondatore' => 'fondatrice', 'fondatori' => 'fondatrici', 'ordinario' => 'ordinaria', 'ordinari' => 'ordinarie', 'sospeso' => 'sospesa', 'sospesi' => 'sospese',
		'attivo' => 'attiva', 'attivi' => 'attive', 'inattivo' => 'inattiva', 'inattivi' => 'inattive', 'scaduto' => 'scaduta', 'scaduti' => 'scadute',
		'iscritto' => 'iscritta', 'iscritti' => 'iscritte', 'volontario' => 'volontaria', 'volontari' => 'volontarie', 'e volontario' => 'e volontaria', 'e volontari' => 'e volontarie',
		'nuovo' => 'nuova', 'nuovi' => 'nuove', 'registrato' => 'registrata', 'registrati' => 'registrate', 'invitato' => 'invitata', 'invitati' => 'invitate',
	);

	/** @var array|null */
	private static $cache = null;

	// ---------- Elenchi e scelta ----------

	/** Righe "a;b;c" (una per riga) => array di campi puliti. */
	public static function parse_lines( string $text, int $fields ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) ?: array() as $line ) {
			$p = array_map( 'trim', explode( ';', $line ) );
			if ( count( $p ) < $fields || in_array( '', array_slice( $p, 0, $fields ), true ) ) {
				continue;
			}
			$out[] = array_slice( $p, 0, $fields );
		}
		return $out;
	}

	private static function gender( string $g ): string {
		return 'f' === strtolower( trim( $g ) ) ? 'f' : 'm';
	}

	/** @return array nome => genere (di serie e aggiunti a mano) */
	public static function entity_types( string $custom = '' ): array {
		$out = self::ENTITIES;
		foreach ( self::parse_lines( $custom, 2 ) as $l ) {
			$out[ self::lower( $l[0] ) ] = self::gender( $l[1] );
		}
		return $out;
	}

	/** @return array singolare => [plurale, genere] */
	public static function member_terms( string $custom = '' ): array {
		$out = self::MEMBERS;
		foreach ( self::parse_lines( $custom, 3 ) as $l ) {
			$out[ self::lower( $l[0] ) ] = array( self::lower( $l[1] ), self::gender( $l[2] ) );
		}
		return $out;
	}

	private static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	private static function ucfirst( string $s ): string {
		if ( '' === $s ) {
			return $s;
		}
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $s, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $s, 1, null, 'UTF-8' ) : ucfirst( $s );
	}

	// ---------- Articoli ----------

	private static function vowel( string $w ): bool {
		if ( in_array( self::lower( $w ), array( 'onlus' ), true ) ) {
			return false; // si dice "la onlus", non "l'onlus"
		}
		return (bool) preg_match( '/^[aeiouàèéìòùh]/iu', $w );
	}

	/** Maschili che vogliono "lo": z, gn, ps, x, y, s + consonante. */
	private static function special( string $w ): bool {
		return (bool) preg_match( '/^(z|gn|ps|x|y|s[^aeiouàèéìòù\W])/iu', $w );
	}

	/**
	 * Una parola con il suo articolo (determinativo o articolato con una preposizione).
	 *
	 * @param string $prep '' | di | a | da | in | su
	 */
	public static function with_article( string $w, string $g, bool $plural, string $prep = '' ): string {
		$forms = array(
			'sf'  => array( '' => 'la', 'di' => 'della', 'a' => 'alla', 'da' => 'dalla', 'in' => 'nella', 'su' => 'sulla' ),
			'sfv' => array( '' => "l'", 'di' => "dell'", 'a' => "all'", 'da' => "dall'", 'in' => "nell'", 'su' => "sull'" ),
			'sm'  => array( '' => 'il', 'di' => 'del', 'a' => 'al', 'da' => 'dal', 'in' => 'nel', 'su' => 'sul' ),
			'sms' => array( '' => 'lo', 'di' => 'dello', 'a' => 'allo', 'da' => 'dallo', 'in' => 'nello', 'su' => 'sullo' ),
			'smv' => array( '' => "l'", 'di' => "dell'", 'a' => "all'", 'da' => "dall'", 'in' => "nell'", 'su' => "sull'" ),
			'pf'  => array( '' => 'le', 'di' => 'delle', 'a' => 'alle', 'da' => 'dalle', 'in' => 'nelle', 'su' => 'sulle' ),
			'pm'  => array( '' => 'i', 'di' => 'dei', 'a' => 'ai', 'da' => 'dai', 'in' => 'nei', 'su' => 'sui' ),
			'pms' => array( '' => 'gli', 'di' => 'degli', 'a' => 'agli', 'da' => 'dagli', 'in' => 'negli', 'su' => 'sugli' ),
		);
		if ( $plural ) {
			$k = 'f' === $g ? 'pf' : ( self::vowel( $w ) || self::special( $w ) ? 'pms' : 'pm' );
		} elseif ( 'f' === $g ) {
			$k = self::vowel( $w ) ? 'sfv' : 'sf';
		} else {
			$k = self::vowel( $w ) ? 'smv' : ( self::special( $w ) ? 'sms' : 'sm' );
		}
		$art = $forms[ $k ][ $prep ];
		return $art . ( "'" === substr( $art, -1 ) ? '' : ' ' ) . $w;
	}

	/** Articolo indeterminativo: un / uno / una / un'. */
	public static function indefinite( string $w, string $g ): string {
		if ( 'f' === $g ) {
			return self::vowel( $w ) ? "un'" . $w : 'una ' . $w;
		}
		return self::special( $w ) ? 'uno ' . $w : 'un ' . $w;
	}

	// ---------- Regole di sostituzione ----------

	/**
	 * Frasi originali => sostituzioni, per un ente e un termine. Vuoto se sono quelli di serie.
	 *
	 * @param string $entity    nome del tipo di ente (es. "comitato")
	 * @param string $e_gender  f | m
	 * @param string $sing      termine al singolare (es. "iscritto")
	 * @param string $plur      termine al plurale
	 * @param string $m_gender  f | m
	 */
	public static function build_map( string $entity, string $e_gender, string $sing, string $plur, string $m_gender ): array {
		$map = array();
		if ( self::DEFAULT_ENTITY !== $entity || 'f' !== $e_gender ) {
			$w = $entity;
			$map["l'associazione"]      = self::with_article( $w, $e_gender, false );
			$map["dell'associazione"]   = self::with_article( $w, $e_gender, false, 'di' );
			$map["all'associazione"]    = self::with_article( $w, $e_gender, false, 'a' );
			$map["dall'associazione"]   = self::with_article( $w, $e_gender, false, 'da' );
			$map["nell'associazione"]   = self::with_article( $w, $e_gender, false, 'in' );
			$map["sull'associazione"]   = self::with_article( $w, $e_gender, false, 'su' );
			$map["un'associazione"]     = self::indefinite( $w, $e_gender );
			$map["quest'associazione"]  = 'f' === $e_gender ? ( self::vowel( $w ) ? "quest'" . $w : 'questa ' . $w ) : 'questo ' . $w;
			$map['associazione']        = $w;
		}
		if ( self::DEFAULT_MEMBER !== $sing || 'soci' !== $plur || 'm' !== $m_gender ) {
			$f = 'f' === $m_gender;
			$map['il socio']        = self::with_article( $sing, $m_gender, false );
			$map['del socio']       = self::with_article( $sing, $m_gender, false, 'di' );
			$map['al socio']        = self::with_article( $sing, $m_gender, false, 'a' );
			$map['dal socio']       = self::with_article( $sing, $m_gender, false, 'da' );
			$map['nel socio']       = self::with_article( $sing, $m_gender, false, 'in' );
			$map['sul socio']       = self::with_article( $sing, $m_gender, false, 'su' );
			$map['un socio']        = self::indefinite( $sing, $m_gender );
			$map['questo socio']    = ( $f ? 'questa ' : 'questo ' ) . $sing;
			$map['nuovo socio']     = ( $f ? 'nuova ' : 'nuovo ' ) . $sing;
			$map['altro socio']     = ( $f ? 'altra ' : 'altro ' ) . $sing;
			$map['i soci']          = self::with_article( $plur, $m_gender, true );
			$map['dei soci']        = self::with_article( $plur, $m_gender, true, 'di' );
			$map['ai soci']         = self::with_article( $plur, $m_gender, true, 'a' );
			$map['dai soci']        = self::with_article( $plur, $m_gender, true, 'da' );
			$map['nei soci']        = self::with_article( $plur, $m_gender, true, 'in' );
			$map['sui soci']        = self::with_article( $plur, $m_gender, true, 'su' );
			$map['tutti i soci']    = ( $f ? 'tutte ' : 'tutti ' ) . self::with_article( $plur, $m_gender, true );
			$map['altri soci']      = ( $f ? 'altre ' : 'altri ' ) . $plur;
			$map['nuovi soci']      = ( $f ? 'nuove ' : 'nuovi ' ) . $plur;
			if ( $f ) { // qualifiche al femminile: socio fondatore → socia fondatrice
				foreach ( self::ADJECTIVES as $am => $af ) {
					$pl = 'i' === substr( $am, -1 ) && ' ' !== substr( $am, 0, 1 );
					$map[ ( $pl ? 'soci ' : 'socio ' ) . $am ] = ( $pl ? $plur : $sing ) . ' ' . $af;
				}
			}
			$map['socio']           = $sing;
			$map['soci']            = $plur;
		}
		// maiuscola iniziale: "Il socio…", "Associazione", "Soci"
		foreach ( $map as $from => $to ) {
			$map[ self::ucfirst( $from ) ] = self::ucfirst( $to );
		}
		return $map;
	}

	/** Applica una mappa di frasi a un testo, solo su parole intere e fuori da indirizzi web e email. */
	public static function apply_map( string $s, array $map ): string {
		if ( ! $map || '' === $s ) {
			return $s;
		}
		$keys = array_keys( $map );
		usort(
			$keys,
			function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		$alt = implode( '|', array_map( function ( $k ) {
			return preg_quote( (string) $k, '/' );
		}, $keys ) );
		$re  = '/(?<![\p{L}\p{N}])(' . $alt . ')(?![\p{L}\p{N}])/u';
		// indirizzi web ed email restano com'erano
		$parts = preg_split( '#(https?://\S+|[\w.+\-]+@[\w\-]+\.[\w.\-]+)#u', $s, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $s;
		}
		foreach ( $parts as $i => $p ) {
			if ( 0 === $i % 2 && '' !== $p ) {
				$parts[ $i ] = (string) preg_replace_callback(
					$re,
					function ( $m ) use ( $map ) {
						return $map[ $m[1] ];
					},
					$p
				);
			}
		}
		return implode( '', $parts );
	}

	// ---------- Impostazioni attuali ----------

	/** Sostituzioni attive secondo le impostazioni (vuote se tutto è di serie). */
	public static function map(): array {
		if ( null === self::$cache ) {
			$s       = Settings::all();
			$ents    = self::entity_types( (string) $s['entity_types_custom'] );
			$ent     = isset( $ents[ (string) $s['entity_type'] ] ) ? (string) $s['entity_type'] : self::DEFAULT_ENTITY;
			$mems    = self::member_terms( (string) $s['member_terms_custom'] );
			$mem     = isset( $mems[ (string) $s['member_term'] ] ) ? (string) $s['member_term'] : self::DEFAULT_MEMBER;
			self::$cache = self::build_map( $ent, $ents[ $ent ] ?? 'f', $mem, $mems[ $mem ][0] ?? 'soci', $mems[ $mem ][1] ?? 'm' );
		}
		return self::$cache;
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/** Adatta un testo semplice (email, PDF, messaggi) al tipo di ente e ai termini scelti. */
	public static function apply( string $s ): string {
		return self::apply_map( $s, self::map() );
	}
}
