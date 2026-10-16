<?php
namespace Portale;

/**
 * Abbonamenti e licenze: dalla scelta del piano alla licenza sul server Wega.
 * Tre modi di avere una licenza: gratuita (con conferma email), a pagamento (ricorrente o singolo, Stripe o PayPal),
 * prova Pro (una volta per licenza gratuita) ed emissione manuale dal pannello.
 */
final class Billing {

	/** @var Db */
	private $db;
	/** @var WegaClient */
	private $wega;

	public function wega(): WegaClient {
		return $this->wega;
	}

	public function __construct( Db $db, WegaClient $wega ) {
		$this->db   = $db;
		$this->wega = $wega;
	}

	// --- Lettura ------------------------------------------------------------------------------------------------

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

	/** Licenze visibili al cliente: niente richieste mai pagate né pagamenti confluiti in un rinnovo. */
	public function forCustomer( string $customerId ): array {
		return $this->db->all( "SELECT * FROM subscriptions WHERE customer_id = ? AND status NOT IN ('pending','merged') ORDER BY created_at DESC", array( $customerId ) );
	}

	/** Rinnovo automatico da parte del provider (Stripe/PayPal in modalità ricorrente)? */
	public function isRecurring( array $sub ): bool {
		return in_array( $sub['provider'], array( 'stripe', 'paypal' ), true ) && 'once' !== ( $sub['mode'] ?? 'recurring' );
	}

	/** Come si può pagare questo prezzo: elenco di [provider, modo]. Il ricorrente richiede l'id su Stripe/PayPal, il singolo no. */
	public function methods( ?array $price ): array {
		$out = array();
		if ( ! $price ) {
			return $out;
		}
		$stripe = '' !== (string) Config::get( 'stripe.secret_key', '' );
		$paypal = '' !== (string) Config::get( 'paypal.client_id', '' ) && '' !== (string) Config::get( 'paypal.secret', '' );
		$once   = false !== ( $price['once'] ?? true );
		if ( $stripe && ! empty( $price['stripe_price'] ) ) {
			$out[] = array( 'stripe', 'recurring' );
		}
		if ( $paypal && ! empty( $price['paypal_plan'] ) ) {
			$out[] = array( 'paypal', 'recurring' );
		}
		if ( $stripe && $once ) {
			$out[] = array( 'stripe', 'once' );
		}
		if ( $paypal && $once ) {
			$out[] = array( 'paypal', 'once' );
		}
		return $out;
	}

	// --- Creazione ----------------------------------------------------------------------------------------------

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

	private function insertSub( string $customerId, string $plan, string $period, string $provider, string $domain, string $mode, ?string $renews = null ): array {
		$sub = array(
			'id' => Db::id(), 'customer_id' => $customerId, 'plan_code' => $plan, 'period' => $period, 'provider' => $provider,
			'status' => 'pending', 'domain' => $domain, 'mode' => $mode, 'renews' => $renews, 'created_at' => Db::now(), 'updated_at' => Db::now(),
		);
		$this->db->run(
			'INSERT INTO subscriptions (id, customer_id, plan_code, period, provider, status, domain, mode, renews, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
			array_values( $sub )
		);
		return $sub;
	}

	private function create( string $email, string $domainInput, string $plan, string $period, string $provider, string $mode ): array {
		$domain = Domain::normalize( $domainInput );
		if ( '' === $domain ) {
			throw new UserError( 'Dominio non valido: scrivi per esempio «miaassociazione.it», senza http:// e senza indirizzi IP.' );
		}
		$cust = $this->customer( $email );
		$this->assertDomainFree( $domain, $cust['id'] );
		return array( 'customer' => $cust, 'sub' => $this->insertSub( $cust['id'], $plan, $period, $provider, $domain, $mode ) );
	}

	/** Licenza gratuita: si attiva solo dopo la conferma dell'email (link). @return array [customer, sub] */
	public function startFree( string $email, string $domain ): array {
		$r = $this->create( $email, $domain, 'free', 'free', 'free', 'once' );
		return array( $r['customer'], $r['sub'] );
	}

	/**
	 * Acquisto a pagamento.
	 *
	 * @param string $mode `recurring` (rinnovo automatico) oppure `once` (pagamento singolo, nessun rinnovo)
	 * @return string indirizzo a cui mandare il cliente per pagare
	 */
	public function startPaid( string $email, string $domain, string $plan, string $period, string $provider, string $mode = 'recurring' ): string {
		$price = Config::price( $plan, $period );
		if ( 'free' === $plan || ! $price || ! in_array( array( $provider, $mode ), $this->methods( $price ), true ) ) {
			throw new UserError( 'Questo metodo di pagamento non è disponibile per il piano scelto.' );
		}
		$r = $this->create( $email, $domain, $plan, $period, $provider, $mode );
		return $this->checkoutUrl( $r['sub'], $r['customer']['email'], $price, $provider, $mode );
	}

	/** Rinnovo di una licenza con pagamento singolo: stesso piano e stesso periodo, la chiave non cambia. */
	public function startRenewal( string $subId, string $customerId, string $provider ): string {
		$old = $this->subscription( $subId );
		if ( ! $old || $old['customer_id'] !== $customerId || empty( $old['license_key_enc'] ) || $this->isRecurring( $old ) || ! in_array( $old['provider'], array( 'stripe', 'paypal' ), true ) ) {
			throw new UserError( 'Questa licenza non si può rinnovare da qui.' );
		}
		$price = Config::price( $old['plan_code'], $old['period'] );
		if ( ! $price || ! in_array( array( $provider, 'once' ), $this->methods( $price ), true ) ) {
			throw new UserError( 'Questo metodo di pagamento non è disponibile.' );
		}
		$cust = $this->db->one( 'SELECT email FROM customers WHERE id = ?', array( $customerId ) );
		$sub  = $this->insertSub( $customerId, $old['plan_code'], $old['period'], $provider, $old['domain'], 'once', $old['id'] );
		return $this->checkoutUrl( $sub, (string) $cust['email'], $price, $provider, 'once' );
	}

	private function checkoutUrl( array $sub, string $email, array $price, string $provider, string $mode ): string {
		$label = (string) Config::get( 'plans.' . $sub['plan_code'] . '.label', $sub['plan_code'] ) . ( 'year' === $sub['period'] ? ' · 1 anno' : ' · 1 mese' );
		if ( 'recurring' === $mode ) {
			return 'stripe' === $provider
				? Stripe::checkoutUrl( $sub['id'], $email, (string) $price['stripe_price'] )
				: PayPal::approvalUrl( $sub['id'], $email, (string) $price['paypal_plan'] );
		}
		if ( 'stripe' === $provider ) {
			return Stripe::oneTimeUrl( $sub['id'], $email, (int) $price['cents'], $label );
		}
		list( $orderId, $url ) = PayPal::createOrder( $sub['id'], (int) $price['cents'], $label );
		$this->db->run( 'UPDATE subscriptions SET provider_ref = ? WHERE id = ?', array( $orderId, $sub['id'] ) );
		return $url;
	}

	// --- Attivazione e rinnovi -------------------------------------------------------------------------------------

	private function days( string $period ): int {
		$key = array( 'month' => 'duration_month', 'year' => 'duration_year', 'free' => 'duration_free' )[ $period ] ?? 'duration_month';
		return Settings::limit( $key );
	}

	/**
	 * Emette la licenza (tipo 3, legata al dominio). Ripetibile senza effetti: `external_ref` rende idempotente anche il server.
	 *
	 * @param int|null    $days   durata se diversa da quella del periodo (prova, emissione manuale)
	 * @param string|null $extRef riferimento univoco per il server licenze
	 */
	public function activate( string $subId, ?string $providerRef = null, ?string $providerCustomer = null, ?int $days = null, ?string $extRef = null ): array {
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
			'duration_days' => max( 1, min( 3650, $days ?? $this->days( $sub['period'] ) ) ),
			'domain'        => $sub['domain'],
			'external_ref'  => $extRef ?? 'sub-' . $sub['id'],
		) );
		$this->db->run(
			"UPDATE subscriptions SET status = 'active', license_id = ?, license_key_enc = ?, expires_at = ?, updated_at = ? WHERE id = ?",
			array( (string) $lic['license_id'], Secrets::seal( (string) $lic['license_key'] ), $this->date( $lic['expires_at'] ?? null ), Db::now(), $subId )
		);
		return $this->subscription( $subId );
	}

	/**
	 * Un pagamento è andato a buon fine (webhook o ritorno dal provider): emette la licenza e avvisa il cliente.
	 * Se era il rinnovo di una licenza esistente, la rinnova e lascia la chiave com'è. Ripetibile senza effetti.
	 */
	public function completePayment( string $subId, ?string $providerRef = null, ?string $providerCustomer = null ): void {
		$sub = $this->subscription( $subId );
		if ( ! $sub ) {
			throw new UserError( 'Abbonamento sconosciuto.' );
		}
		if ( ! empty( $sub['renews'] ) ) {
			// L'aggiornamento condizionato garantisce un solo rinnovo anche se webhook e ritorno arrivano insieme.
			$st = $this->db->run( "UPDATE subscriptions SET status = 'merged', provider_ref = COALESCE(?, provider_ref), updated_at = ? WHERE id = ? AND status = 'pending'", array( $providerRef, Db::now(), $subId ) );
			if ( 1 === $st->rowCount() ) {
				try {
					$this->renew( (string) $sub['renews'] );
				} catch ( \Throwable $e ) {
					$this->db->run( "UPDATE subscriptions SET status = 'pending' WHERE id = ?", array( $subId ) );
					throw $e;
				}
			}
			return;
		}
		$first = empty( $sub['license_id'] );
		$sub   = $this->activate( $subId, $providerRef, $providerCustomer );
		if ( $first && ! empty( $sub['license_id'] ) ) {
			$this->welcome( $sub );
		}
	}

	/** Email di benvenuto con il link per vedere la chiave nell'area riservata. */
	public function welcome( array $sub ): void {
		$cust = $this->db->one( 'SELECT * FROM customers WHERE id = ?', array( $sub['customer_id'] ) );
		if ( $cust ) {
			$token = ( new Auth( $this->db ) )->issue( $cust['id'] );
			$this->notify( 'welcome', $sub, array( 'link' => rtrim( (string) Config::get( 'base_url' ), '/' ) . '/entra/' . $token ) );
		}
	}

	/**
	 * Registra un rinnovo (aggiunge alla licenza la sua durata).
	 *
	 * @param int|null $windowDays se indicato vale solo negli ultimi N giorni di validità (PayPal manda lo stesso evento anche
	 *                             per il primo pagamento, già coperto dall'attivazione)
	 */
	public function renew( string $subId, ?int $windowDays = null, ?int $now = null, bool $notify = true ): bool {
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
		if ( $notify ) {
			$this->notify( 'renewed', $this->subscription( $subId ) );
		}
		return true;
	}

	public function setStatus( string $subId, string $status, ?bool $cancelAtEnd = null ): void {
		$this->db->run( 'UPDATE subscriptions SET status = ?, updated_at = ? WHERE id = ?', array( $status, Db::now(), $subId ) );
		if ( null !== $cancelAtEnd ) {
			$this->db->run( 'UPDATE subscriptions SET cancel_at_period_end = ? WHERE id = ?', array( $cancelAtEnd ? 1 : 0, $subId ) );
		}
	}

	// --- Prova Pro ------------------------------------------------------------------------------------------------------

	/** La prova si può attivare una volta sola per licenza gratuita (e una sola volta per dominio). */
	public function trialAvailable( array $free ): bool {
		if ( ! Config::get( 'trial_enabled', true ) || 'free' !== $free['provider'] || 'active' !== $free['status'] || empty( $free['license_id'] ) || ! empty( $free['trial_used_at'] ) ) {
			return false;
		}
		$plan = (string) Config::get( 'trial_plan', 'full' );
		if ( 'free' === $plan || null === Config::get( 'plans.' . $plan ) ) {
			return false;
		}
		return null === $this->db->one( "SELECT id FROM subscriptions WHERE domain = ? AND provider = 'trial'", array( $free['domain'] ) );
	}

	/** Attiva la prova Pro: una licenza Pro a tempo sullo stesso dominio, con una chiave sua. @return array la licenza di prova */
	public function startTrial( string $customerId, string $freeSubId ): array {
		$free = $this->subscription( $freeSubId );
		if ( ! $free || $free['customer_id'] !== $customerId || ! $this->trialAvailable( $free ) ) {
			throw new UserError( 'La prova gratuita non è disponibile per questa licenza.' );
		}
		// Il segno «prova usata» si mette prima e in modo atomico: un doppio clic non emette due prove.
		$st = $this->db->run( 'UPDATE subscriptions SET trial_used_at = ? WHERE id = ? AND trial_used_at IS NULL', array( Db::now(), $freeSubId ) );
		if ( 1 !== $st->rowCount() ) {
			throw new UserError( 'La prova gratuita è già stata usata.' );
		}
		$sub = null;
		try {
			$sub = $this->insertSub( $customerId, (string) Config::get( 'trial_plan', 'full' ), 'trial', 'trial', $free['domain'], 'once' );
			$sub = $this->activate( $sub['id'], null, null, Settings::limit( 'trial_days' ), 'trial-' . $freeSubId );
		} catch ( \Throwable $e ) {
			$this->db->run( 'UPDATE subscriptions SET trial_used_at = NULL WHERE id = ?', array( $freeSubId ) );
			if ( $sub ) {
				$this->db->run( "DELETE FROM subscriptions WHERE id = ? AND license_id IS NULL", array( $sub['id'] ) );
			}
			throw $e;
		}
		$this->notify( 'trial_started', $sub );
		return $sub;
	}

	// --- Emissione manuale (pannello) -----------------------------------------------------------------------------------

	/** Licenza regalata o concordata fuori dal portale. */
	public function issueManual( string $email, string $domain, string $plan, int $days, string $note, bool $notify ): array {
		if ( null === Config::get( 'plans.' . $plan ) ) {
			throw new UserError( 'Piano sconosciuto.' );
		}
		$r   = $this->create( $email, $domain, $plan, 'manual', 'manual', 'once' );
		$this->db->run( 'UPDATE subscriptions SET note = ? WHERE id = ?', array( mb_substr( $note, 0, 255 ), $r['sub']['id'] ) );
		$sub = $this->activate( $r['sub']['id'], null, null, max( 1, min( 3650, $days ) ) );
		if ( $notify ) {
			$this->welcome( $sub );
		}
		return $sub;
	}

	// --- Dominio, disdetta -------------------------------------------------------------------------------------------------

	/** Cambio di dominio (il server consente 3 cambi ogni 30 giorni). */
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
		$this->notify( 'domain_changed', $this->subscription( $subId ), array( 'vecchio_dominio' => $sub['domain'] ) );
	}

	/** Disdetta a fine periodo: la licenza resta valida fino alla scadenza già pagata, poi non viene rinnovata. */
	public function cancel( string $subId, string $customerId ): void {
		$sub = $this->subscription( $subId );
		if ( ! $sub || $sub['customer_id'] !== $customerId ) {
			throw new UserError( 'Abbonamento non trovato.' );
		}
		if ( ! $this->isRecurring( $sub ) ) {
			throw new UserError( 'Questa licenza non ha un rinnovo automatico da disdire.' );
		}
		if ( ! empty( $sub['provider_ref'] ) ) {
			'stripe' === $sub['provider'] ? Stripe::cancelAtPeriodEnd( $sub['provider_ref'] ) : PayPal::cancel( $sub['provider_ref'] );
		}
		$this->setStatus( $subId, $sub['status'], true );
		$this->notify( 'canceled', $this->subscription( $subId ) );
	}

	// --- Email --------------------------------------------------------------------------------------------------------------

	/** Manda l'email di un evento al cliente della licenza (rispetta i modelli e l'interruttore del pannello). */
	public function notify( string $event, array $sub, array $extra = array() ): bool {
		$cust = $this->db->one( 'SELECT email FROM customers WHERE id = ?', array( $sub['customer_id'] ) );
		if ( ! $cust ) {
			return false;
		}
		return Emails::send( $this->db, $event, (string) $cust['email'], $extra + array(
			'dominio'  => $sub['domain'],
			'piano'    => (string) Config::get( 'plans.' . $sub['plan_code'] . '.label', $sub['plan_code'] ),
			'scadenza' => ! empty( $sub['expires_at'] ) ? date( 'd/m/Y', strtotime( $sub['expires_at'] . ' UTC' ) ) : '—',
		) );
	}

	/**
	 * Avvisi di scadenza per le licenze che non si rinnovano da sole (pagamento singolo, prova, emissione manuale, disdette).
	 * Da lanciare una volta al giorno (bin/cron.php). @return int email inviate
	 */
	public function sendReminders( ?int $now = null ): int {
		$days = Settings::limit( 'reminder_days' );
		if ( $days < 1 ) {
			return 0;
		}
		$now  = $now ?? time();
		$rows = $this->db->all(
			"SELECT * FROM subscriptions WHERE license_key_enc IS NOT NULL AND provider <> 'free' AND status IN ('active','past_due')
			 AND expires_at IS NOT NULL AND expires_at > ? AND expires_at <= ? AND (reminded_for IS NULL OR reminded_for <> expires_at)",
			array( gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now + $days * 86400 ) )
		);
		$sent = 0;
		foreach ( $rows as $sub ) {
			if ( $this->isRecurring( $sub ) && ! $sub['cancel_at_period_end'] ) {
				continue;
			}
			$this->notify( 'trial' === $sub['provider'] ? 'trial_ending' : 'expiring', $sub );
			$this->db->run( 'UPDATE subscriptions SET reminded_for = ? WHERE id = ?', array( $sub['expires_at'], $sub['id'] ) );
			++$sent;
		}
		return $sent;
	}

	/** La data del server (ISO 8601 UTC) in "Y-m-d H:i:s". */
	private function date( $iso ): ?string {
		$t = is_string( $iso ) ? strtotime( $iso ) : false;
		return false === $t ? null : gmdate( 'Y-m-d H:i:s', $t );
	}

	// --- Eliminazione (gestione) ---------------------------------------------------------------------------------------

	/** Motivo per cui una licenza non si può eliminare, o null. Non tocca il server Wega né i provider. */
	public function undeletableReason( array $sub ): ?string {
		if ( in_array( $sub['provider'], array( 'stripe', 'paypal' ), true ) && in_array( $sub['status'], array( 'active', 'past_due' ), true ) && $this->isRecurring( $sub ) && ! $sub['cancel_at_period_end'] ) {
			return 'Ha un rinnovo automatico attivo presso ' . ( 'stripe' === $sub['provider'] ? 'Stripe' : 'PayPal' ) . ': disdicilo prima (altrimenti il cliente continuerebbe a pagare).';
		}
		return null;
	}

	/** Elimina la licenza dal portale: il dominio torna libero. La licenza sul server Wega resta com'è. */
	public function deleteSubscription( string $subId ): array {
		$sub = $this->subscription( $subId );
		if ( ! $sub ) {
			throw new UserError( 'Licenza non trovata.' );
		}
		if ( $reason = $this->undeletableReason( $sub ) ) {
			throw new UserError( $reason );
		}
		$this->db->run( 'DELETE FROM subscriptions WHERE id = ?', array( $subId ) );
		return $sub;
	}

	/** Elimina il cliente con licenze, link di accesso e registro degli scarichi. @return int licenze eliminate */
	public function deleteCustomer( string $customerId ): int {
		$subs = $this->db->all( 'SELECT * FROM subscriptions WHERE customer_id = ?', array( $customerId ) );
		foreach ( $subs as $s ) {
			if ( $reason = $this->undeletableReason( $s ) ) {
				throw new UserError( $s['domain'] . ': ' . $reason );
			}
		}
		foreach ( array( 'subscriptions', 'login_tokens', 'downloads' ) as $t ) {
			$this->db->run( 'DELETE FROM ' . $t . ' WHERE customer_id = ?', array( $customerId ) );
		}
		$this->db->run( 'DELETE FROM customers WHERE id = ?', array( $customerId ) );
		return count( $subs );
	}
}
