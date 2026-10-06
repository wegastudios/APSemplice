<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Notifiche push ai dispositivi dei soci: sottoscrizioni (una per browser o telefono) e invio.
 * Spente di default; si accendono da Impostazioni → Tecniche → App e notifiche. Le email continuano a partire come sempre.
 */
final class Push {

	const OPT_VAPID    = 'apse_vapid';
	const MAX_PER_USER = 10;
	const MAX_SEND     = 300;
	const TIME_BUDGET  = 8.0; // secondi al massimo per ogni invio

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function enabled(): bool {
		return (bool) Settings::get( 'pwa_enabled' ) && (bool) Settings::get( 'push_enabled' ) && License::allows( 'official_notices' ) && License::allows( 'pwa' ) && WebPush::supported();
	}

	// ---------- Chiavi VAPID ----------

	/** @return array{public:string,pem:string} chiavi del sito (create alla prima richiesta; la privata è cifrata nel database) */
	public static function vapid(): array {
		$v = get_option( self::OPT_VAPID, array() );
		if ( is_array( $v ) && ! empty( $v['public'] ) && ! empty( $v['private'] ) ) {
			$pem = Secrets::decrypt( (string) $v['private'], wp_salt( 'auth' ) );
			if ( null !== $pem && '' !== $pem ) {
				return array( 'public' => (string) $v['public'], 'pem' => $pem );
			}
		}
		$k = WebPush::new_keypair();
		update_option( self::OPT_VAPID, array( 'public' => WebPush::b64u( $k['public'] ), 'private' => Secrets::encrypt( $k['pem'], wp_salt( 'auth' ) ) ), false );
		return array( 'public' => WebPush::b64u( $k['public'] ), 'pem' => $k['pem'] );
	}

	public static function public_key(): string {
		return self::vapid()['public'];
	}

	/** Nuove chiavi: tutte le sottoscrizioni esistenti smettono di funzionare e vanno rifatte. */
	public static function reset_keys(): void {
		delete_option( self::OPT_VAPID );
		self::db()->query( 'DELETE FROM ' . Db::t( 'push_subs' ) );
		Audit::log( 'push.keys_reset', 'settings', 0, array() );
	}

	// ---------- Sottoscrizioni ----------

	/** @throws \InvalidArgumentException */
	public static function subscribe( int $user_id, array $sub, string $user_agent = '' ): int {
		$endpoint = (string) ( $sub['endpoint'] ?? '' );
		$keys     = (array) ( $sub['keys'] ?? array() );
		$p256dh   = (string) ( $keys['p256dh'] ?? '' );
		$auth     = (string) ( $keys['auth'] ?? '' );
		if ( ! WebPush::allowed_endpoint( $endpoint ) ) {
			throw new \InvalidArgumentException( 'Questo dispositivo non può ricevere notifiche (servizio di push non riconosciuto).' );
		}
		$pub = WebPush::unb64u( $p256dh );
		if ( 65 !== strlen( $pub ) || "\x04" !== $pub[0] || strlen( WebPush::unb64u( $auth ) ) < 16 || strlen( $auth ) > 40 ) {
			throw new \InvalidArgumentException( 'Sottoscrizione non valida.' );
		}
		$tbl  = Db::t( 'push_subs' );
		$hash = sha1( $endpoint );
		$id   = (int) self::db()->get_var( self::db()->prepare( "SELECT id FROM $tbl WHERE endpoint_hash = %s", $hash ) );
		$data = array( 'user_id' => $user_id, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth, 'ua' => mb_substr( $user_agent, 0, 190 ), 'failures' => 0 );
		if ( $id ) {
			self::db()->update( $tbl, $data, array( 'id' => $id ) );
			return $id;
		}
		if ( (int) self::db()->get_var( self::db()->prepare( "SELECT COUNT(*) FROM $tbl WHERE user_id = %d", $user_id ) ) >= self::MAX_PER_USER ) {
			throw new \InvalidArgumentException( 'Hai già ' . self::MAX_PER_USER . ' dispositivi con le notifiche attive: disattivane qualcuno.' );
		}
		self::db()->insert( $tbl, $data + array( 'endpoint_hash' => $hash, 'created_at' => Db::now() ) );
		return (int) self::db()->insert_id;
	}

	public static function unsubscribe( int $user_id, string $endpoint ): void {
		self::db()->delete( Db::t( 'push_subs' ), array( 'user_id' => $user_id, 'endpoint_hash' => sha1( $endpoint ) ) );
	}

	public static function subscribed( int $user_id ): bool {
		return (bool) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'push_subs' ) . ' WHERE user_id = %d', $user_id ) );
	}

	public static function count(): int {
		return (int) self::db()->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'push_subs' ) );
	}

	// ---------- Invio ----------

	/**
	 * Manda una notifica ai dispositivi di alcuni utenti. @return int notifiche consegnate al servizio di push
	 *
	 * @param int[] $user_ids
	 */
	public static function notify_users( array $user_ids, string $title, string $body, string $url = '' ): int {
		if ( ! self::enabled() ) {
			return 0;
		}
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( ! $user_ids ) {
			return 0;
		}
		$tbl  = Db::t( 'push_subs' );
		$subs = self::db()->get_results( "SELECT * FROM $tbl WHERE user_id IN (" . implode( ',', $user_ids ) . ') ORDER BY id LIMIT ' . self::MAX_SEND, ARRAY_A ) ?: array();
		if ( ! $subs ) {
			return 0;
		}
		$payload = (string) wp_json_encode(
			array(
				'title' => mb_substr( trim( wp_strip_all_tags( $title ) ), 0, 80 ),
				'body'  => mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ) ), 0, 240 ),
				'url'   => '' !== $url ? $url : Gatekeeper::area_url(),
				'icon'  => Pwa::icon_url( 192 ),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		$v       = self::vapid();
		$subject = 'mailto:' . sanitize_email( (string) get_option( 'admin_email', 'info@example.org' ) );
		$sent    = 0;
		$until   = microtime( true ) + self::TIME_BUDGET;
		foreach ( $subs as $s ) {
			if ( microtime( true ) > $until ) {
				break; // le notifiche non devono rallentare l'invio delle email: chi resta indietro le riceve comunque per email
			}
			try {
				$r = WebPush::send( $s, $payload, $v['public'], $v['pem'], $subject );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( $r['ok'] ) {
				$sent++;
				self::db()->update( $tbl, array( 'last_ok_at' => Db::now(), 'failures' => 0 ), array( 'id' => (int) $s['id'] ) );
			} elseif ( $r['gone'] ) {
				self::db()->delete( $tbl, array( 'id' => (int) $s['id'] ) ); // il dispositivo ha tolto le notifiche
			} else {
				$f = (int) $s['failures'] + 1;
				if ( $f >= 5 ) {
					self::db()->delete( $tbl, array( 'id' => (int) $s['id'] ) );
				} else {
					self::db()->update( $tbl, array( 'failures' => $f ), array( 'id' => (int) $s['id'] ) );
				}
			}
		}
		return $sent;
	}

	/** Come notify_users, partendo dall'indirizzo email del destinatario (chi ha un utente collegato). */
	public static function notify_email( string $email, string $title, string $body, string $url = '' ): int {
		if ( ! self::enabled() || '' === $email ) {
			return 0;
		}
		$u = get_user_by( 'email', $email );
		return $u ? self::notify_users( array( (int) $u->ID ), $title, $body, $url ) : 0;
	}
}
