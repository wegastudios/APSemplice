<?php
namespace Portale;

/** Accesso: email e password, oppure un link monouso (15 minuti) inviato per email (primo accesso e password dimenticata). Il link di conferma della licenza free funziona allo stesso modo. */
final class Auth {

	const TTL = 900;

	/** @var Db */
	private $db;

	public function __construct( Db $db ) {
		$this->db = $db;
	}

	/** @return string token da mettere nel link (nel database resta solo l'impronta) */
	public function issue( string $customerId, ?string $subscriptionId = null ): string {
		$raw = bin2hex( random_bytes( 24 ) );
		$this->db->run(
			'INSERT INTO login_tokens (token_hash, customer_id, subscription_id, expires_at) VALUES (?,?,?,?)',
			array( hash( 'sha256', $raw ), $customerId, $subscriptionId, gmdate( 'Y-m-d H:i:s', time() + self::TTL ) )
		);
		return $raw;
	}

	/** Al massimo 5 link all'ora per cliente (contro l'invio ripetuto di email a terzi). */
	public function canIssue( string $customerId ): bool {
		$row = $this->db->one( 'SELECT COUNT(*) AS n FROM login_tokens WHERE customer_id = ? AND expires_at > ?', array( $customerId, gmdate( 'Y-m-d H:i:s', time() - 3600 + self::TTL ) ) );
		return (int) ( $row['n'] ?? 0 ) < 5;
	}

	/** @return array|null ['customer_id', 'subscription_id'] se valido e non ancora usato */
	public function consume( string $raw ): ?array {
		$h   = hash( 'sha256', $raw );
		$row = $this->db->one( 'SELECT * FROM login_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', array( $h, Db::now() ) );
		if ( ! $row ) {
			return null;
		}
		// L'aggiornamento condizionato evita che due richieste insieme usino lo stesso link.
		$st = $this->db->run( 'UPDATE login_tokens SET used_at = ? WHERE token_hash = ? AND used_at IS NULL', array( Db::now(), $h ) );
		return 1 === $st->rowCount() ? array( 'customer_id' => $row['customer_id'], 'subscription_id' => $row['subscription_id'] ) : null;
	}

	// --- Password -------------------------------------------------------------------------------------------------------

	/** La password si riduce a un'impronta di lunghezza fissa prima di bcrypt, che altrimenti ignora tutto dopo 72 byte. */
	private static function prehash( string $password ): string {
		return base64_encode( hash( 'sha256', $password, true ) );
	}

	/** Imposta o cambia la password. @throws UserError se non rispetta le regole */
	public function setPassword( string $customerId, string $password, string $email = '' ): void {
		$min = Settings::limit( 'password_min' );
		if ( mb_strlen( $password ) < $min ) {
			throw new UserError( 'La password deve avere almeno ' . $min . ' caratteri.' );
		}
		if ( mb_strlen( $password ) > 200 ) {
			throw new UserError( 'La password è troppo lunga (massimo 200 caratteri).' );
		}
		if ( '' !== $email && 0 === strcasecmp( $password, $email ) ) {
			throw new UserError( 'La password non può essere uguale all\'email.' );
		}
		$this->db->run( 'UPDATE customers SET password_hash = ? WHERE id = ?', array( password_hash( self::prehash( $password ), PASSWORD_DEFAULT ), $customerId ) );
	}

	public function hasPassword( string $customerId ): bool {
		$r = $this->db->one( 'SELECT password_hash FROM customers WHERE id = ?', array( $customerId ) );
		return $r && ! empty( $r['password_hash'] );
	}

	public function checkPassword( string $customerId, string $password ): bool {
		$r = $this->db->one( 'SELECT password_hash FROM customers WHERE id = ?', array( $customerId ) );
		return $r && ! empty( $r['password_hash'] ) && password_verify( self::prehash( $password ), (string) $r['password_hash'] );
	}

	/**
	 * Accesso con email e password. Stesso errore per email sconosciuta, cliente senza password e password sbagliata
	 * (e un controllo a vuoto, così i tempi non rivelano chi è cliente). Dopo troppi errori l'accesso si ferma per un'ora.
	 * @return array riga del cliente
	 * @throws UserError
	 */
	public function loginWithPassword( string $email, string $password, string $ip, ?int $now = null ): array {
		$now   = $now ?? time();
		$hour  = gmdate( 'YmdH', $now );
		$max   = Settings::limit( 'login_fails' );
		$email = strtolower( trim( $email ) );
		$bEmail = 'login:e:' . hash( 'sha256', $email );
		$bIp    = 'login:i:' . hash( 'sha256', $ip );
		if ( $this->count( $bEmail, $hour ) >= $max || $this->count( $bIp, $hour ) >= $max * 4 ) {
			throw new UserError( 'Troppi tentativi di accesso: riprova tra un\'ora, oppure fatti mandare il link per email.' );
		}
		$cust = $this->db->one( 'SELECT * FROM customers WHERE email = ?', array( $email ) );
		$hash = $cust && ! empty( $cust['password_hash'] ) ? (string) $cust['password_hash'] : '';
		static $dummy = null;
		if ( '' === $hash && null === $dummy ) {
			$dummy = password_hash( 'x', PASSWORD_DEFAULT );
		}
		$ok = password_verify( self::prehash( $password ), '' !== $hash ? $hash : (string) $dummy ) && '' !== $hash;
		if ( ! $ok ) {
			$this->bump( $bEmail, $hour );
			$this->bump( $bIp, $hour );
			throw new UserError( 'Email o password non corrette.' );
		}
		$this->db->run( 'DELETE FROM rate_limits WHERE bucket = ?', array( $bEmail ) );
		if ( password_needs_rehash( $hash, PASSWORD_DEFAULT ) ) {
			$this->db->run( 'UPDATE customers SET password_hash = ? WHERE id = ?', array( password_hash( self::prehash( $password ), PASSWORD_DEFAULT ), $cust['id'] ) );
		}
		return $cust;
	}

	private function count( string $bucket, string $hour ): int {
		$r = $this->db->one( 'SELECT n FROM rate_limits WHERE bucket = ? AND hour = ?', array( $bucket, $hour ) );
		return $r ? (int) $r['n'] : 0;
	}

	private function bump( string $bucket, string $hour ): void {
		if ( $this->db->one( 'SELECT 1 AS x FROM rate_limits WHERE bucket = ? AND hour = ?', array( $bucket, $hour ) ) ) {
			$this->db->run( 'UPDATE rate_limits SET n = n + 1 WHERE bucket = ? AND hour = ?', array( $bucket, $hour ) );
		} else {
			$this->db->run( 'INSERT INTO rate_limits (bucket, hour, n) VALUES (?,?,1)', array( $bucket, $hour ) );
		}
	}
}
