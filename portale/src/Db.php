<?php
namespace Portale;

/** Accesso al database (SQLite o MySQL) con uno schema scritto in SQL comune a entrambi. */
final class Db {

	/** @var \PDO */
	private $pdo;

	public function __construct( \PDO $pdo ) {
		$this->pdo = $pdo;
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$this->pdo->setAttribute( \PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC );
	}

	public static function connect( array $cfg ): self {
		$dsn = (string) $cfg['dsn'];
		if ( 0 === strpos( $dsn, 'sqlite:' ) && ':memory:' !== substr( $dsn, 7 ) ) {
			$dir = dirname( substr( $dsn, 7 ) );
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0750, true );
			}
		}
		return new self( new \PDO( $dsn, $cfg['user'] ?? null, $cfg['pass'] ?? null ) );
	}

	public function run( string $sql, array $args = array() ): \PDOStatement {
		$st = $this->pdo->prepare( $sql );
		$st->execute( $args );
		return $st;
	}

	public function one( string $sql, array $args = array() ): ?array {
		$r = $this->run( $sql, $args )->fetch();
		return $r ?: null;
	}

	public function all( string $sql, array $args = array() ): array {
		return $this->run( $sql, $args )->fetchAll();
	}

	public static function id(): string {
		return bin2hex( random_bytes( 12 ) );
	}

	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public function migrate(): void {
		$stmts = array(
			'CREATE TABLE IF NOT EXISTS customers (
				id VARCHAR(32) PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, created_at VARCHAR(19) NOT NULL)',
			'CREATE TABLE IF NOT EXISTS login_tokens (
				token_hash VARCHAR(64) PRIMARY KEY, customer_id VARCHAR(32) NOT NULL, subscription_id VARCHAR(32) NULL,
				expires_at VARCHAR(19) NOT NULL, used_at VARCHAR(19) NULL)',
			'CREATE TABLE IF NOT EXISTS subscriptions (
				id VARCHAR(32) PRIMARY KEY, customer_id VARCHAR(32) NOT NULL, plan_code VARCHAR(40) NOT NULL,
				period VARCHAR(8) NOT NULL, provider VARCHAR(12) NOT NULL, provider_ref VARCHAR(120) NULL,
				provider_customer VARCHAR(120) NULL, status VARCHAR(12) NOT NULL, domain VARCHAR(190) NOT NULL,
				license_id VARCHAR(64) NULL, license_key_enc TEXT NULL, expires_at VARCHAR(19) NULL,
				cancel_at_period_end INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL)',
			'CREATE INDEX idx_sub_ref ON subscriptions (provider, provider_ref)',
			'CREATE INDEX idx_sub_domain ON subscriptions (domain)',
			'CREATE TABLE IF NOT EXISTS events (
				provider VARCHAR(12) NOT NULL, event_id VARCHAR(120) NOT NULL, processed_at VARCHAR(19) NOT NULL,
				PRIMARY KEY (provider, event_id))',
		);
		foreach ( $stmts as $sql ) {
			try {
				$this->pdo->exec( $sql );
			} catch ( \PDOException $e ) {
				// CREATE INDEX non ha IF NOT EXISTS su MySQL: se esiste già va bene.
				if ( 0 !== strpos( $sql, 'CREATE INDEX' ) ) {
					throw $e;
				}
			}
		}
	}

	/** Segna un evento dei provider come elaborato; false se lo era già (i provider ripetono le consegne). */
	public function claimEvent( string $provider, string $eventId ): bool {
		try {
			$this->run( 'INSERT INTO events (provider, event_id, processed_at) VALUES (?,?,?)', array( $provider, $eventId, self::now() ) );
			return true;
		} catch ( \PDOException $e ) {
			return false;
		}
	}

	/** Se l'elaborazione fallisce l'evento va rilasciato, così la riconsegna del provider lo riprova. */
	public function releaseEvent( string $provider, string $eventId ): void {
		$this->run( 'DELETE FROM events WHERE provider = ? AND event_id = ?', array( $provider, $eventId ) );
	}
}
