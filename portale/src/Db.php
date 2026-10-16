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
			'CREATE TABLE IF NOT EXISTS settings (name VARCHAR(60) PRIMARY KEY, val TEXT NOT NULL)',
			'CREATE TABLE IF NOT EXISTS email_templates (
				event VARCHAR(40) PRIMARY KEY, subject VARCHAR(255) NOT NULL, body TEXT NOT NULL,
				enabled INTEGER NOT NULL DEFAULT 1, updated_at VARCHAR(19) NOT NULL)',
			'CREATE TABLE IF NOT EXISTS audit (
				id VARCHAR(32) PRIMARY KEY, at VARCHAR(19) NOT NULL, actor VARCHAR(190) NOT NULL, action VARCHAR(60) NOT NULL, detail TEXT NULL)',
			'CREATE TABLE IF NOT EXISTS rate_limits (bucket VARCHAR(80) NOT NULL, hour VARCHAR(10) NOT NULL, n INTEGER NOT NULL, PRIMARY KEY (bucket, hour))',
			'CREATE TABLE IF NOT EXISTS proxy_cache (k VARCHAR(64) PRIMARY KEY, body TEXT NOT NULL, at VARCHAR(19) NOT NULL)',
			'CREATE TABLE IF NOT EXISTS releases (product VARCHAR(8) NOT NULL, version VARCHAR(20) NOT NULL, size INTEGER NOT NULL, sha256 VARCHAR(64) NOT NULL, note VARCHAR(500) NULL, created_at VARCHAR(19) NOT NULL, PRIMARY KEY (product, version))',
			'CREATE TABLE IF NOT EXISTS downloads (id VARCHAR(32) PRIMARY KEY, customer_id VARCHAR(32) NOT NULL, at VARCHAR(19) NOT NULL, product VARCHAR(8) NOT NULL, version VARCHAR(20) NOT NULL)',
			'CREATE INDEX idx_dl_customer ON downloads (customer_id, at)',
			// Colonne aggiunte dopo la prima versione: se esistono già l'errore si ignora.
			'ALTER TABLE subscriptions ADD COLUMN reminded_for VARCHAR(19) NULL',
			'ALTER TABLE subscriptions ADD COLUMN note VARCHAR(255) NULL',
			'ALTER TABLE subscriptions ADD COLUMN mode VARCHAR(10) NULL',
			'ALTER TABLE subscriptions ADD COLUMN renews VARCHAR(32) NULL',
			'ALTER TABLE subscriptions ADD COLUMN trial_used_at VARCHAR(19) NULL',
		);
		foreach ( $stmts as $sql ) {
			try {
				$this->pdo->exec( $sql );
			} catch ( \PDOException $e ) {
				// CREATE INDEX e ADD COLUMN non hanno "IF NOT EXISTS" ovunque: se esistono già va bene.
				if ( 0 !== strpos( $sql, 'CREATE INDEX' ) && 0 !== strpos( $sql, 'ALTER TABLE' ) ) {
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
