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
		self::loadSecrets( $db );
		foreach ( array( 'tracking', 'legal' ) as $k ) {
			$v = self::read( $db, $k );
			if ( $v ) {
				Config::set( $k, $v );
			}
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

	// --- Chiavi dei servizi (cifrate nel database) ---------------------------------------------------------------

	/** Campo => [percorso in Config, controllo del formato, etichetta]. */
	const SECRETS = array(
		'stripe_secret'  => array( 'stripe.secret_key', '/^(sk|rk)_(test|live)_[A-Za-z0-9]{10,200}$/', 'La chiave segreta Stripe deve iniziare con «sk_test_» o «sk_live_».' ),
		'stripe_webhook' => array( 'stripe.webhook_secret', '/^whsec_[A-Za-z0-9]{10,200}$/', 'Il segreto del webhook Stripe deve iniziare con «whsec_».' ),
		'paypal_client'  => array( 'paypal.client_id', '/^[A-Za-z0-9_-]{20,200}$/', 'Il Client ID PayPal non sembra valido.' ),
		'paypal_secret'  => array( 'paypal.secret', '/^[A-Za-z0-9_-]{20,300}$/', 'Il segreto PayPal non sembra valido.' ),
		'paypal_webhook' => array( 'paypal.webhook_id', '/^[A-Za-z0-9]{10,60}$/', 'L\'ID del webhook PayPal non sembra valido.' ),
		'wega_token'     => array( 'wega.token', '/^wic_[a-f0-9]{8,}\.[A-Za-z0-9_-]{20,}$/', 'Il token Wega deve avere la forma «wic_…».«segreto».' ),
	);

	/** @var array valori presenti in config.php prima di applicare quelli del pannello */
	private static $fromFile = array();

	private static function loadSecrets( Db $db ): void {
		$sealed = self::read( $db, 'secrets' );
		foreach ( self::SECRETS as $k => $def ) {
			if ( ! isset( self::$fromFile[ $k ] ) ) {
				self::$fromFile[ $k ] = (string) Config::get( $def[0], '' );
			}
			if ( ! empty( $sealed[ $k ] ) ) {
				try {
					Config::set( $def[0], Secrets::open( (string) $sealed[ $k ] ) );
				} catch ( \Throwable $e ) {
					error_log( '[portale] chiave «' . $k . '» non leggibile: ' . $e->getMessage() );
				}
			}
		}
		$pay = self::read( $db, 'payments' );
		if ( array_key_exists( 'paypal_live', $pay ) ) {
			Config::set( 'paypal.live', (bool) $pay['paypal_live'] );
		}
	}

	/** Per il modulo: per ogni chiave se c'è, da dove viene e gli ultimi caratteri (mai il valore intero). */
	public static function secretsStatus( Db $db ): array {
		$sealed = self::read( $db, 'secrets' );
		$out    = array();
		foreach ( self::SECRETS as $k => $def ) {
			$val = '';
			try {
				$val = ! empty( $sealed[ $k ] ) ? Secrets::open( (string) $sealed[ $k ] ) : '';
			} catch ( \Throwable $e ) {
				$val = '';
			}
			$fromPanel = '' !== $val;
			$val       = $fromPanel ? $val : ( self::$fromFile[ $k ] ?? '' );
			$out[ $k ] = array( 'set' => '' !== $val, 'mask' => '' !== $val ? '••••' . substr( $val, -4 ) : '', 'source' => $fromPanel ? 'pannello' : ( '' !== $val ? 'config.php' : '' ) );
		}
		$out['paypal_live'] = (bool) Config::get( 'paypal.live', false );
		return $out;
	}

	/** Salva le chiavi dal modulo: vuoto = non cambia, «rimuovi» = cancella. @return array nomi dei campi cambiati */
	public static function saveSecrets( Db $db, array $in ): array {
		$sealed  = self::read( $db, 'secrets' );
		$changed = array();
		foreach ( self::SECRETS as $k => $def ) {
			$v = trim( (string) ( $in[ $k ] ?? '' ) );
			if ( ! empty( $in[ 'remove_' . $k ] ) ) {
				unset( $sealed[ $k ] );
				$changed[] = $k;
			} elseif ( '' !== $v ) {
				if ( ! preg_match( $def[1], $v ) ) {
					throw new UserError( $def[2] );
				}
				$sealed[ $k ] = Secrets::seal( $v );
				$changed[]    = $k;
			}
		}
		self::write( $db, 'secrets', $sealed );
		// La modalità PayPal si cambia solo dal modulo PayPal (che manda anche questo campo nascosto).
		if ( isset( $in['paypal_live_present'] ) ) {
			self::write( $db, 'payments', array( 'paypal_live' => ! empty( $in['paypal_live'] ) ) );
		}
		return $changed;
	}

	// --- Cookie, script di monitoraggio, pagine legali ---------------------------------------------------------------

	public static function saveTracking( Db $db, array $in ): void {
		$cat  = function ( $v ) { return in_array( $v, array( 'analytics', 'marketing', 'necessary' ), true ) ? $v : 'analytics'; };
		$new  = array(
			'head' => Tracking::validate( (string) ( $in['head'] ?? '' ) ), 'head_cat' => $cat( $in['head_cat'] ?? '' ),
			'foot' => Tracking::validate( (string) ( $in['foot'] ?? '' ) ), 'foot_cat' => $cat( $in['foot_cat'] ?? '' ),
			'banner_text' => mb_substr( trim( (string) ( $in['banner_text'] ?? '' ) ), 0, 600 ),
		);
		$old       = self::read( $db, 'tracking' );
		$same      = isset( $old['head'] ) && $old['head'] === $new['head'] && $old['foot'] === $new['foot'] && $old['head_cat'] === $new['head_cat'] && $old['foot_cat'] === $new['foot_cat'];
		// Se cambiano gli script chi aveva già scelto viene interpellato di nuovo.
		$new['rev'] = $same ? (int) ( $old['rev'] ?? 1 ) : time();
		self::write( $db, 'tracking', $new );
	}

	public static function saveLegal( Db $db, array $in ): void {
		$t     = function ( $k, int $max ) use ( $in ) { return mb_substr( trim( (string) ( $in[ $k ] ?? '' ) ), 0, $max ); };
		$email = $t( 'email', 190 );
		self::write( $db, 'legal', array(
			'titolare' => $t( 'titolare', 160 ), 'indirizzo' => $t( 'indirizzo', 240 ), 'piva' => $t( 'piva', 40 ),
			'email' => ( '' === $email || filter_var( $email, FILTER_VALIDATE_EMAIL ) ) ? $email : '',
			'privacy_body' => $t( 'privacy_body', 30000 ), 'cookie_body' => $t( 'cookie_body', 30000 ),
		) );
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
