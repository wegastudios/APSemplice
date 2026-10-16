<?php
namespace Portale;

/**
 * Impostazioni modificabili dal pannello (prezzi, piani, durate…). I valori di config.php sono i default:
 * quelli salvati nel database li sostituiscono all'avvio ({@see Settings::load}).
 */
final class Settings {

	/** Limiti configurabili: chiave, default, minimo, massimo e spiegazione dei rischi. */
	const LIMITS = array(
		'duration_month' => array( 31, 28, 62, 'Giorni di validità della licenza a ogni pagamento mensile. Se alzi: il cliente ha più margine ma una disdetta o un mancato pagamento lascia la licenza attiva più a lungo. Se abbassi: un pagamento in ritardo la fa scadere prima.' ),
		'duration_year'  => array( 366, 360, 400, 'Giorni di validità a ogni pagamento annuale. Stesso compromesso del mensile.' ),
		'duration_free'  => array( 3650, 30, 3650, 'Giorni di validità della licenza gratuita. Se abbassi: le licenze gratuite scadono prima e vanno rinnovate (a mano).' ),
		'renew_window'   => array( 5, 1, 30, 'Un pagamento PayPal conta come rinnovo solo negli ultimi N giorni di validità. Se alzi: un primo pagamento tardivo potrebbe contare due volte. Se abbassi: un rinnovo anticipato di PayPal potrebbe non essere registrato.' ),
		'reminder_days'  => array( 14, 0, 60, 'Giorni prima della scadenza in cui parte l\'email «licenza in scadenza» (0 = non inviare). Se alzi: più tempo ai clienti ma più email. Se abbassi: meno margine per rinnovare.' ),
		'trial_days'     => array( 30, 1, 90, 'Giorni di prova gratuita del piano Pro, una sola volta per licenza gratuita. Se alzi: più clienti si convincono ma lo stesso dominio usa il Pro gratis più a lungo. Se abbassi: la prova può essere troppo breve per valutare il piano.' ),
	);

	/** Applica al Config i valori salvati. Da chiamare dopo la connessione al database. */
	public static function load( Db $db ): void {
		$plans = Config::get( 'plans', array() );
		foreach ( self::read( $db, 'plans' ) as $code => $over ) {
			if ( isset( $plans[ $code ] ) && is_array( $over ) ) {
				$plans[ $code ] = self::mergePlan( $plans[ $code ], $over );
			}
		}
		Config::set( 'plans', $plans );

		$g = self::read( $db, 'general' );
		self::apply( 'duration_days.month', $g, 'duration_month' );
		self::apply( 'duration_days.year', $g, 'duration_year' );
		self::apply( 'duration_days.free', $g, 'duration_free' );
		self::apply( 'renew_window_days', $g, 'renew_window' );
		self::apply( 'reminder_days', $g, 'reminder_days' );
		self::apply( 'trial_days', $g, 'trial_days' );
		if ( array_key_exists( 'trial_enabled', $g ) ) {
			Config::set( 'trial_enabled', (bool) $g['trial_enabled'] );
		}
		if ( ! empty( $g['trial_plan'] ) ) {
			Config::set( 'trial_plan', (string) $g['trial_plan'] );
		}
		if ( array_key_exists( 'indexable', $g ) ) {
			Config::set( 'indexable', (bool) $g['indexable'] );
		}
		if ( ! empty( $g['mail_from'] ) ) {
			Config::set( 'mail_from', (string) $g['mail_from'] );
		}
	}

	private static function apply( string $path, array $g, string $key ): void {
		if ( isset( $g[ $key ] ) ) {
			Config::set( $path, self::clamp( $key, $g[ $key ] ) );
		}
	}

	/** Valore corrente di un limite (da config o da pannello) dentro i margini consentiti. */
	public static function limit( string $key ): int {
		$paths = array( 'duration_month' => 'duration_days.month', 'duration_year' => 'duration_days.year', 'duration_free' => 'duration_days.free', 'renew_window' => 'renew_window_days', 'reminder_days' => 'reminder_days', 'trial_days' => 'trial_days' );
		$v     = Config::get( $paths[ $key ], null );
		return self::clamp( $key, null === $v ? self::LIMITS[ $key ][0] : $v );
	}

	/** @param mixed $v */
	public static function clamp( string $key, $v ): int {
		$l = self::LIMITS[ $key ];
		return max( $l[1], min( $l[2], (int) $v ) );
	}

	/** @param array $in dati del modulo */
	public static function saveGeneral( Db $db, array $in ): void {
		$g = array();
		foreach ( array_keys( self::LIMITS ) as $k ) {
			$g[ $k ] = self::clamp( $k, $in[ $k ] ?? self::LIMITS[ $k ][0] );
		}
		$g['indexable']     = ! empty( $in['indexable'] );
		$g['trial_enabled'] = ! empty( $in['trial_enabled'] );
		$plan               = (string) ( $in['trial_plan'] ?? '' );
		$g['trial_plan']    = ( 'free' !== $plan && null !== Config::get( 'plans.' . $plan ) ) ? $plan : 'full';
		$mail           = trim( (string) ( $in['mail_from'] ?? '' ) );
		$g['mail_from'] = filter_var( $mail, FILTER_VALIDATE_EMAIL ) ? $mail : '';
		self::write( $db, 'general', $g );
	}

	/** Valori del modulo «Impostazioni» (già con i salvataggi applicati). */
	public static function general(): array {
		$out = array();
		foreach ( array_keys( self::LIMITS ) as $k ) {
			$out[ $k ] = self::limit( $k );
		}
		$out['indexable']     = (bool) Config::get( 'indexable', false );
		$out['trial_enabled'] = (bool) Config::get( 'trial_enabled', true );
		$out['trial_plan']    = (string) Config::get( 'trial_plan', 'full' );
		$out['mail_from'] = (string) Config::get( 'mail_from', '' );
		return $out;
	}

	/** Salva un piano dai dati del modulo (con controlli). @return array dati puliti */
	public static function savePlan( Db $db, string $code, array $in ): array {
		if ( null === Config::get( 'plans.' . $code ) ) {
			throw new UserError( 'Piano sconosciuto.' );
		}
		$text  = function ( $v, int $max ) { return mb_substr( trim( preg_replace( '/\s+/', ' ', (string) $v ) ), 0, $max ); };
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $in['features'] ?? '' ) ) as $l ) {
			$l = $text( $l, 120 );
			if ( '' !== $l && count( $lines ) < 12 ) {
				$lines[] = $l;
			}
		}
		$over = array(
			'label'     => $text( $in['label'] ?? '', 40 ) ?: (string) Config::get( 'plans.' . $code . '.label' ),
			'plan_name' => $text( $in['plan_name'] ?? '', 40 ) ?: (string) Config::get( 'plans.' . $code . '.plan_name' ),
			'tagline'   => $text( $in['tagline'] ?? '', 200 ),
			'tag'       => $text( $in['tag'] ?? '', 30 ),
			'features'  => $lines,
			'visible'   => ! empty( $in['visible'] ),
			'prices'    => array(),
		);
		if ( 'free' !== $code ) {
			foreach ( array( 'month', 'year' ) as $p ) {
				$euro = str_replace( ',', '.', trim( (string) ( $in[ 'price_' . $p ] ?? '' ) ) );
				if ( '' === $euro ) {
					$over['prices'][ $p ] = null;
					continue;
				}
				if ( ! is_numeric( $euro ) || $euro < 0 || $euro > 10000 ) {
					throw new UserError( 'Prezzo non valido: scrivi un importo in euro, per esempio 49,90.' );
				}
				$stripe = trim( (string) ( $in[ 'stripe_' . $p ] ?? '' ) );
				$paypal = trim( (string) ( $in[ 'paypal_' . $p ] ?? '' ) );
				if ( '' !== $stripe && ! preg_match( '/^price_[A-Za-z0-9]{6,100}$/', $stripe ) ) {
					throw new UserError( 'Il prezzo Stripe deve iniziare con «price_».' );
				}
				if ( '' !== $paypal && ! preg_match( '/^P-[A-Za-z0-9]{6,100}$/', $paypal ) ) {
					throw new UserError( 'Il piano PayPal deve iniziare con «P-».' );
				}
				$over['prices'][ $p ] = array( 'cents' => (int) round( (float) $euro * 100 ), 'stripe_price' => $stripe, 'paypal_plan' => $paypal, 'once' => ! empty( $in[ 'once_' . $p ] ) );
			}
		}
		$all          = self::read( $db, 'plans' );
		$all[ $code ] = $over;
		self::write( $db, 'plans', $all );
		return $over;
	}

	public static function mergePlan( array $base, array $over ): array {
		foreach ( array( 'label', 'plan_name', 'tagline', 'tag', 'features', 'visible' ) as $k ) {
			if ( array_key_exists( $k, $over ) ) {
				$base[ $k ] = $over[ $k ];
			}
		}
		foreach ( (array) ( $over['prices'] ?? array() ) as $p => $price ) {
			if ( null === $price ) {
				unset( $base['prices'][ $p ] );
			} elseif ( is_array( $price ) ) {
				$base['prices'][ $p ] = array_merge( $base['prices'][ $p ] ?? array(), $price );
			}
		}
		return $base;
	}

	private static function read( Db $db, string $name ): array {
		$row = $db->one( 'SELECT val FROM settings WHERE name = ?', array( $name ) );
		$v   = $row ? json_decode( (string) $row['val'], true ) : null;
		return is_array( $v ) ? $v : array();
	}

	private static function write( Db $db, string $name, array $value ): void {
		$db->run( 'DELETE FROM settings WHERE name = ?', array( $name ) );
		$db->run( 'INSERT INTO settings (name, val) VALUES (?,?)', array( $name, (string) json_encode( $value ) ) );
	}
}
