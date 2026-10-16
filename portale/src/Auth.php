<?php
namespace Portale;

/** Accesso senza password: un link monouso (15 minuti) inviato per email. Il link di conferma della licenza free funziona allo stesso modo. */
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
}
