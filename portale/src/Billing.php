<?php
namespace Portale;

/** Abbonamenti: dalla scelta del piano alla licenza sul server Wega, rinnovi, cambio dominio e disdetta. */
final class Billing {

	/** @var Db */
	private $db;
	/** @var WegaClient */
	private $wega;

	public function __construct( Db $db, WegaClient $wega ) {
		$this->db   = $db;
		$this->wega = $wega;
	}

	public function customer( string $email ): array {
		$email = strtolower( trim( $email ) );
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) || strlen( $email ) > 190 ) {
			throw new UserError( 'Indirizzo email non valido.' );
		}
		$c = $this->db->one( 'SELECT * FROM customers WHERE email = ?', array( $email ) );
		if ( $c ) {
			return $c;
		}
		$c = array( 'id' => Db::id(), 'email' => $email, 'created_at' => Db::now() );
		$this->db->run( 'INSERT INTO customers (id, email, created_at) VALUES (?,?,?)', array_values( $c ) );
		return $c;
	}

	public function subscription( string $id ): ?array {
		return $this->db->one( 'SELECT * FROM subscriptions WHERE id = ?', array( $id ) );
	}

	public function byProviderRef( string $provider, string $ref ): ?array {
		return '' === $ref ? null : $this->db->one( 'SELECT * FROM subscriptions WHERE provider = ? AND provider_ref = ?', array( $provider, $ref ) );
	}

	public function forCustomer( string $customerId ): array {
		return $this->db->all( "SELECT * FROM subscriptions WHERE customer_id = ? AND status <> 'pending' ORDER BY created_at DESC", array( $customerId ) );
	}

	/** Un dominio può avere un solo abbonamento attivo o in attesa, e di un solo cliente (la chiave della licenza è riservata). */
	private function assertDomainFree( string $domain, string $customerId, ?string $exceptId = null ): void {
		$row = $this->db->one(
			"SELECT id FROM subscriptions WHERE domain = ? AND customer_id <> ? AND status IN ('pending','active','past_due') AND id <> ?",
			array( $domain, $customerId, (string) $exceptId )
		);
		if ( $row ) {
			throw new UserError( 'Questo dominio risulta già registrato da un altro indirizzo email.' );
		}
	}

	private function create( string $email, string $domainInput, string $plan, string $period, string $provider ): array {
		$domain = Domain::normalize( $domainInput );
		if ( '' === $domain ) {
			throw new UserError( 'Dominio non valido: scrivi per esempio «miaassociazione.it», senza http:// e senza indirizzi IP.' );
		}
		$cust = $this->customer( $email );
		$this->assertDomainFree( $domain, $cust['id'] );
		$sub = array(
			'id' => Db::id(), 'customer_id' => $cust['id'], 'plan_code' => $plan, 'period' => $period, 'provider' => $provider,
			'status' => 'pending', 'domain' => $domain, 'created_at' => Db::now(), 'updated_at' => Db::now(),
		);
		$this->db->run(
			'INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
			array( $sub['id'], $sub['customer_id'], $sub['plan_code'], $sub['period'], $sub['provider'], $sub['status'], $sub['domain'], $sub['created_at'], $sub['updated_at'] )
		);
		return array( 'customer' => $cust, 'sub' => $sub );
	}

	/** Licenza gratuita: si attiva solo dopo la conferma dell'email (link). @return array [customer, sub] */
	public function startFree( string $email, string $domain ): array {
		$r = $this->create( $email, $domain, 'free', 'free', 'free' );
		return array( $r['customer'], $r['sub'] );
	}

	/** @return string indirizzo a cui mandare il cliente per pagare */
	public function startPaid( string $email, string $domain, string $plan, string $period, string $provider ): string {
		$price = Config::price( $plan, $period );
		if ( 'free' === $plan || ! $price || ! in_array( $provider, array( 'stripe', 'paypal' ), true ) ) {
			throw new UserError( 'Piano non disponibile.' );
		}
		$ref = 'stripe' === $provider ? (string) ( $price['stripe_price'] ?? '' ) : (string) ( $price['paypal_plan'] ?? '' );
		if ( '' === $ref ) {
			throw new UserError( 'Questo metodo di pagamento non è ancora disponibile per il piano scelto.' );
		}
		$r = $this->create( $email, $domain, $plan, $period, $provider );
		return 'stripe' === $provider
			? Stripe::checkoutUrl( $r['sub']['id'], $r['customer']['email'], $ref )
			: PayPal::approvalUrl( $r['sub']['id'], $r['customer']['email'], $ref );
	}

	private function days( string $period ): int {
		$d = (int) Config::get( 'duration_days.' . $period, 0 );
		return max( 1, min( 3650, $d ?: ( 'year' === $period ? 366 : ( 'free' === $period ? 3650 : 31 ) ) ) );
	}

	/** Emette la licenza (tipo 3, legata al dominio) al primo pagamento o alla conferma dell'email. Ripetibile senza effetti. */
	public function activate( string $subId, ?string $providerRef = null, ?string $providerCustomer = null ): array {
		$sub = $this->subscription( $subId );
		if ( ! $sub ) {
			throw new UserError( 'Abbonamento sconosciuto.' );
		}
		if ( $providerRef ) {
			$this->db->run( 'UPDATE subscriptions SET provider_ref = ?, provider_customer = COALESCE(?, provider_customer) WHERE id = ?', array( $providerRef, $providerCustomer, $subId ) );
		}
		if ( ! empty( $sub['license_id'] ) ) {
			$this->db->run( "UPDATE subscriptions SET status = 'active', updated_at = ? WHERE id = ? AND status <> 'canceled'", array( Db::now(), $subId ) );
			return $this->subscription( $subId );
		}
		$cust = $this->db->one( 'SELECT email FROM customers WHERE id = ?', array( $sub['customer_id'] ) );
		$lic  = $this->wega->createLicense( array(
			'type'          => 3,
			'plan_name'     => (string) Config::get( 'plans.' . $sub['plan_code'] . '.plan_name', $sub['plan_code'] ),
			'customer_id'   => $cust['email'],
			'duration_days' => $this->days( $sub['period'] ),
			'domain'        => $sub['domain'],
			'external_ref'  => 'sub-' . $sub['id'],
		) );
		$this->db->run(
			"UPDATE subscriptions SET status = 'active', license_id = ?, license_key_enc = ?, expires_at = ?, updated_at = ? WHERE id = ?",
			array( (string) $lic['license_id'], Secrets::seal( (string) $lic['license_key'] ), $this->date( $lic['expires_at'] ?? null ), Db::now(), $subId )
		);
		return $this->subscription( $subId );
	}

	/**
	 * Registra un pagamento di rinnovo.
	 *
	 * @param int|null $windowDays se indicato il rinnovo vale solo negli ultimi N giorni di validità (PayPal manda lo stesso evento
	 *                             anche per il primo pagamento, già coperto dall'attivazione)
	 */
	public function renew( string $subId, ?int $windowDays = null, ?int $now = null ): bool {
		$sub = $this->subscription( $subId );
		if ( ! $sub || empty( $sub['license_key_enc'] ) || 'canceled' === $sub['status'] ) {
			return false;
		}
		$now = $now ?? time();
		if ( null !== $windowDays && ! empty( $sub['expires_at'] ) && strtotime( $sub['expires_at'] . ' UTC' ) - $now > $windowDays * 86400 ) {
			return false;
		}
		$lic = $this->wega->renew( Secrets::open( $sub['license_key_enc'] ) );
		$this->db->run( "UPDATE subscriptions SET status = 'active', expires_at = ?, updated_at = ? WHERE id = ?", array( $this->date( $lic['expires_at'] ?? null ), Db::now(), $subId ) );
		return true;
	}

	public function setStatus( string $subId, string $status, ?bool $cancelAtEnd = null ): void {
		$this->db->run( 'UPDATE subscriptions SET status = ?, updated_at = ? WHERE id = ?', array( $status, Db::now(), $subId ) );
		if ( null !== $cancelAtEnd ) {
			$this->db->run( 'UPDATE subscriptions SET cancel_at_period_end = ? WHERE id = ?', array( $cancelAtEnd ? 1 : 0, $subId ) );
		}
	}

	/** Cambio di dominio dall'area riservata (il server consente 3 cambi ogni 30 giorni). */
	public function changeDomain( string $subId, string $customerId, string $domainInput ): void {
		$sub = $this->subscription( $subId );
		if ( ! $sub || $sub['customer_id'] !== $customerId || empty( $sub['license_key_enc'] ) ) {
			throw new UserError( 'Abbonamento non trovato.' );
		}
		$domain = Domain::normalize( $domainInput );
		if ( '' === $domain ) {
			throw new UserError( 'Dominio non valido.' );
		}
		if ( $domain === $sub['domain'] ) {
			return;
		}
		$this->assertDomainFree( $domain, $customerId, $subId );
		try {
			$this->wega->changeDomain( (string) $sub['license_id'], Secrets::open( $sub['license_key_enc'] ), $domain );
		} catch ( WegaException $e ) {
			$map = array(
				'BINDING_LOCKED'       => 'Il cambio dominio è bloccato per questa licenza.',
				'BINDING_CHANGE_LIMIT' => 'Hai già cambiato dominio 3 volte negli ultimi 30 giorni: riprova più avanti.',
				'INVALID_DOMAIN'       => 'Dominio non accettato dal server delle licenze.',
				'REVOKED'              => 'La licenza è stata revocata.',
			);
			throw new UserError( $map[ $e->errorCode ] ?? 'Non riesco a cambiare il dominio adesso: riprova tra poco.' );
		}
		$this->db->run( 'UPDATE subscriptions SET domain = ?, updated_at = ? WHERE id = ?', array( $domain, Db::now(), $subId ) );
	}

	/** Disdetta a fine periodo: la licenza resta valida fino alla scadenza già pagata, poi non viene rinnovata. */
	public function cancel( string $subId, string $customerId ): void {
		$sub = $this->subscription( $subId );
		if ( ! $sub || $sub['customer_id'] !== $customerId ) {
			throw new UserError( 'Abbonamento non trovato.' );
		}
		if ( 'stripe' === $sub['provider'] && ! empty( $sub['provider_ref'] ) ) {
			Stripe::cancelAtPeriodEnd( $sub['provider_ref'] );
		} elseif ( 'paypal' === $sub['provider'] && ! empty( $sub['provider_ref'] ) ) {
			PayPal::cancel( $sub['provider_ref'] );
		}
		$this->setStatus( $subId, $sub['status'], true );
	}

	/** La data del server (ISO 8601 UTC) in "Y-m-d H:i:s". */
	private function date( $iso ): ?string {
		$t = is_string( $iso ) ? strtotime( $iso ) : false;
		return false === $t ? null : gmdate( 'Y-m-d H:i:s', $t );
	}
}
