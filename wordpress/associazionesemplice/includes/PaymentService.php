<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Pagamenti online su pagina ospitata dal gateway: Stripe Checkout oppure PayPal.
 *
 * Flusso: il socio sceglie le voci da pagare nell'area soci → il server RICALCOLA importi e voci → crea il pagamento sul gateway
 * e manda il socio alla pagina del gateway → al ritorno (e con il webhook di Stripe, e con un controllo periodico) il pagamento
 * viene confermato direttamente dal gateway → l'incasso entra in prima nota (quota associativa, mensilità, prenotazioni) sul
 * conto "Stripe" o "PayPal". La conferma è idempotente: lo stesso pagamento non si registra due volte.
 *
 * I dati della carta non passano mai dal sito.
 */
class PaymentService extends OfflinePayments {

	const PENDING_AFTER_MINUTES = 10;
	const EXPIRE_AFTER_DAYS     = 3;
	const STUCK_AFTER_MINUTES   = 15;

	/** Stati da cui un pagamento si può ancora registrare: il gateway può confermare i soldi anche dopo che il sito lo aveva chiuso (annullo, scadenza). */
	const OPEN_STATUSES = array( 'created', 'pending', 'cancelled', 'expired', 'failed' );

	/** @var callable|null rete iniettabile per i test */
	private $http = null;

	public function set_http( ?callable $http ): void {
		$this->http = $http;
	}

	private function http(): callable {
		return $this->http ?: array( Gateways::class, 'wp_http' );
	}


	// ---------- Configurazione ----------

	/**
	 * Gateway utilizzabili ora: stripe e/o paypal (anche insieme), oppure woocommerce. Un gateway con la configurazione incompleta non compare
	 * (l'altro, se a posto, funziona lo stesso).
	 *
	 * @return string[]
	 */
	public function providers(): array {
		$c = Settings::payment_config();
		if ( PaymentConfig::WOOCOMMERCE === $c['payment_provider'] ) {
			return WooBridge::active() ? array( PaymentConfig::WOOCOMMERCE ) : array();
		}
		$out = array();
		foreach ( PaymentConfig::gateways_of( (string) $c['payment_provider'] ) as $g ) {
			if ( array() === PaymentConfig::validate_gateway( $c, $g )['errors'] ) {
				$out[] = $g;
			}
		}
		return $out;
	}

	/** Il primo gateway utilizzabile (o '' se non ce n'è): per i controlli che non dipendono dalla scelta. */
	public function provider(): string {
		return $this->providers()[0] ?? '';
	}

	public function enabled(): bool {
		return array() !== $this->providers() && Edition::has( 'payments' );
	}


	public function get_by_public( string $public_id ): ?array {
		$row = $this->db()->get_row( $this->db()->prepare( 'SELECT * FROM ' . Db::t( 'payments' ) . ' WHERE public_id = %s', $public_id ), ARRAY_A );
		return $row ?: null;
	}

	private function get( int $id ): ?array {
		$row = $this->db()->get_row( $this->db()->prepare( 'SELECT * FROM ' . Db::t( 'payments' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	private function update( int $id, array $data ): void {
		$data['updated_at'] = Db::now();
		$this->db()->update( Db::t( 'payments' ), $data, array( 'id' => $id ) );
	}

	/** Pagamenti, dal più recente. @param array $f status, payer_person_id, review */
	public function list( array $f = array(), int $limit = 100 ): array {
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $f['status'] ) ) {
			$where[] = 'p.status = %s';
			$args[]  = $f['status'];
		}
		if ( ! empty( $f['payer_person_id'] ) ) {
			$where[] = 'p.payer_person_id = %d';
			$args[]  = (int) $f['payer_person_id'];
		}
		if ( ! empty( $f['review'] ) ) {
			$where[] = 'p.review = 1';
		}
		$sql    = 'SELECT p.*, CONCAT(pe.first_name, " ", pe.last_name) AS payer_name FROM ' . Db::t( 'payments' ) . ' p LEFT JOIN ' . Db::t( 'people' ) . ' pe ON pe.id = p.payer_person_id WHERE ' . implode( ' AND ', $where ) . ' ORDER BY p.id DESC LIMIT %d';
		$args[] = max( 1, $limit );
		return $this->db()->get_results( $this->db()->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public function mark_reviewed( int $id ): void {
		$this->update( $id, array( 'review' => 0 ) );
		Audit::log( 'payment.reviewed', 'payment', $id );
	}

	// ---------- Creazione del pagamento ----------

	/**
	 * Crea il pagamento sul gateway e restituisce l'indirizzo della pagina di pagamento a cui mandare il socio.
	 *
	 * @param array    $actor     persona collegata all'utente che paga (socio)
	 * @param string[] $keys      chiavi delle voci scelte (si accettano solo quelle realmente dovute da lui o dai suoi ospiti)
	 * @param string   $back_url  pagina del sito a cui tornare dopo il pagamento
	 */
	public function create_checkout( array $actor, int $user_id, array $keys, string $back_url, string $provider = '' ): string {
		$available = $this->providers();
		if ( ! $available || ! Edition::has( 'payments' ) ) {
			throw new \InvalidArgumentException( 'I pagamenti online non sono attivi.' );
		}
		if ( '' === $provider ) {
			$provider = $available[0];
		} elseif ( ! in_array( $provider, $available, true ) ) {
			throw new \InvalidArgumentException( 'Questo metodo di pagamento non è disponibile.' );
		}
		// Un utente (o un programma automatico) non può creare centinaia di pagamenti pendenti sul gateway.
		$tbl  = Db::t( 'payments' );
		$open = (int) $this->db()->get_var( $this->db()->prepare( "SELECT COUNT(*) FROM $tbl WHERE payer_user_id = %d AND created_at >= %s", $user_id, gmdate( 'Y-m-d H:i:s', strtotime( Db::now() ) - HOUR_IN_SECONDS ) ) );
		if ( $open >= Limits::get( 'pay_open_per_hour' ) ) {
			throw new \InvalidArgumentException( 'Hai avviato molti pagamenti in poco tempo: aspetta un po\' prima di riprovare.' );
		}
		$dues  = $this->dues_for( $actor );
		$items = array();
		foreach ( array_unique( array_map( 'strval', $keys ) ) as $k ) {
			if ( isset( $dues[ $k ] ) ) {
				$items[] = $dues[ $k ];
			}
		}
		if ( ! $items ) {
			throw new \InvalidArgumentException( 'Scegli almeno una voce da pagare.' );
		}
		$total = PaymentItems::total( $items );
		$min = Limits::get( 'pay_min_cents' );
		if ( $total < $min ) {
			throw new \InvalidArgumentException( 'L\'importo minimo per pagare online è ' . Money::format( $min ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}

		$public = wp_generate_uuid4();
		$now    = Db::now();
		if ( PaymentConfig::WOOCOMMERCE === $provider ) { // negozio WooCommerce: le voci vanno nel carrello e si paga al checkout
			$url = WooBridge::checkout_url( $items, $public );
			$this->db()->insert(
				Db::t( 'payments' ),
				array(
					'public_id' => $public, 'provider' => $provider, 'status' => 'pending', 'amount_cents' => $total, 'currency' => 'EUR',
					'payer_person_id' => (int) $actor['id'], 'payer_user_id' => $user_id, 'items' => wp_json_encode( $items ), 'created_at' => $now, 'updated_at' => $now,
				)
			);
			$wid = (int) $this->db()->insert_id;
			Audit::log( 'payment.created', 'payment', $wid, array( 'provider' => $provider, 'cents' => $total, 'items' => count( $items ) ) );
			return $url;
		}
		$this->db()->insert(
			Db::t( 'payments' ),
			array(
				'public_id' => $public, 'provider' => $provider, 'status' => 'created', 'amount_cents' => $total, 'currency' => 'EUR',
				'payer_person_id' => (int) $actor['id'], 'payer_user_id' => $user_id, 'items' => wp_json_encode( $items ), 'created_at' => $now, 'updated_at' => $now,
			)
		);
		$id        = (int) $this->db()->insert_id;
		$success   = add_query_arg( array( 'asem_pay' => $public, 'asem_ret' => 'ok' ), $back_url );
		$cancel    = add_query_arg( array( 'asem_pay' => $public, 'asem_ret' => 'cancel' ), $back_url );
		$cfg       = Settings::payment_config();
		$brand     = (string) Settings::get( 'association_name' );
		try {
			if ( PaymentConfig::STRIPE === $provider ) {
				$params = StripeApi::checkout_params( $items, $public, (string) $actor['email'], $success, $cancel );
				$res    = StripeApi::create_session( $this->http(), $cfg['stripe_secret_key'], $params, $public );
			} else {
				$live  = 'live' === $cfg['paypal_mode'];
				$token = PayPalApi::access_token( $this->http(), $live, $cfg['paypal_client_id'], $cfg['paypal_client_secret'] );
				$res   = PayPalApi::create_order( $this->http(), $live, $token, PayPalApi::order_payload( $items, $public, $success, $cancel, $brand ), $public );
			}
		} catch ( \RuntimeException $e ) {
			$this->update( $id, array( 'status' => 'failed', 'error' => mb_substr( $e->getMessage(), 0, 250 ) ) );
			Audit::log( 'payment.failed', 'payment', $id, array( 'provider' => $provider ) );
			throw new \InvalidArgumentException( 'Il servizio di pagamento non risponde in questo momento. Riprova più tardi.' );
		}
		$this->update( $id, array( 'status' => 'pending', 'provider_ref' => $res['id'] ) );
		Audit::log( 'payment.created', 'payment', $id, array( 'provider' => $provider, 'cents' => $total, 'items' => count( $items ) ) );
		return $res['url'];
	}

	// ---------- Conferme ----------

	/** Ritorno del socio dalla pagina del gateway. @return string messaggio per l'utente */
	public function handle_return( string $public_id, string $ret, int $user_id ): string {
		$p = $this->get_by_public( $public_id );
		if ( ! $p || (int) $p['payer_user_id'] !== $user_id ) {
			throw new \InvalidArgumentException( 'Pagamento non trovato.' );
		}
		if ( 'paid' === $p['status'] ) {
			return 'Pagamento ricevuto: grazie!';
		}
		if ( 'cancel' === $ret ) {
			if ( in_array( $p['status'], array( 'created', 'pending' ), true ) ) {
				if ( $this->confirm_with_gateway( $p ) ) {
					return 'Pagamento ricevuto: grazie!'; // il gateway risulta pagato anche se l'indirizzo era quello dell'annullo: non si perde un incasso
				}
				$this->update( (int) $p['id'], array( 'status' => 'cancelled' ) );
			}
			return 'Pagamento annullato: non è stato addebitato nulla.';
		}
		$this->confirm_with_gateway( $p );
		$p = $this->get( (int) $p['id'] );
		if ( 'paid' === $p['status'] ) {
			return 'Pagamento ricevuto: grazie!';
		}
		return 'Pagamento in attesa di conferma dal gateway: non appena arriva, lo registriamo.';
	}

	/** Interroga il gateway e, se il pagamento risulta riuscito, lo registra. @return bool true se è stato registrato ora */
	private function confirm_with_gateway( array $p ): bool {
		if ( ! in_array( $p['status'], self::OPEN_STATUSES, true ) || empty( $p['provider_ref'] ) || PaymentConfig::WOOCOMMERCE === $p['provider'] ) {
			return false; // WooCommerce non si interroga: è il negozio a segnalare l'ordine pagato
		}
		$cfg = Settings::payment_config();
		try {
			if ( 'stripe' === $p['provider'] ) {
				$s = StripeApi::retrieve_session( $this->http(), $cfg['stripe_secret_key'], $p['provider_ref'] );
				if ( StripeApi::is_paid( $s ) ) {
					return $this->finalize( $p, StripeApi::paid_eur_cents( $s ), (string) ( $s['payment_intent'] ?? '' ) );
				}
				if ( 'expired' === ( $s['status'] ?? '' ) ) {
					$this->update( (int) $p['id'], array( 'status' => 'expired' ) );
				}
				return false;
			}
			$live  = 'live' === $cfg['paypal_mode'];
			$token = PayPalApi::access_token( $this->http(), $live, $cfg['paypal_client_id'], $cfg['paypal_client_secret'] );
			$order = PayPalApi::get_order( $this->http(), $live, $token, $p['provider_ref'] );
			if ( 'APPROVED' === ( $order['status'] ?? '' ) ) {
				$order = PayPalApi::capture_order( $this->http(), $live, $token, $p['provider_ref'] ); // l'utente ha approvato: si incassa
			}
			if ( PayPalApi::is_completed( $order ) ) {
				return $this->finalize( $p, PayPalApi::captured_cents( $order ), PayPalApi::capture_id( $order ) );
			}
		} catch ( \RuntimeException $e ) {
			$this->update( (int) $p['id'], array( 'error' => mb_substr( $e->getMessage(), 0, 250 ) ) );
		}
		return false;
	}

	/** Webhook di Stripe già verificato (firma). @return string esito per il log */
	public function handle_stripe_event( array $event ): string {
		$type = (string) ( $event['type'] ?? '' );
		$obj  = $event['data']['object'] ?? array();
		if ( 0 !== strpos( $type, 'checkout.session.' ) || ! is_array( $obj ) ) {
			return 'ignorato';
		}
		$public = (string) ( $obj['client_reference_id'] ?? ( $obj['metadata']['asem_payment'] ?? '' ) );
		$p      = '' !== $public ? $this->get_by_public( $public ) : null;
		if ( ! $p || 'stripe' !== $p['provider'] ) {
			return 'pagamento sconosciuto';
		}
		if ( isset( $event['livemode'] ) && (bool) $event['livemode'] !== ( 'live' === (string) Settings::get( 'stripe_mode' ) ) ) {
			return 'modalità diversa'; // un evento di prova non registra incassi veri (e viceversa)
		}
		if ( ! empty( $p['provider_ref'] ) && ! empty( $obj['id'] ) && $p['provider_ref'] !== $obj['id'] ) {
			return 'sessione diversa';
		}
		if ( in_array( $type, array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded' ), true ) && StripeApi::is_paid( $obj ) ) {
			$this->finalize( $p, StripeApi::paid_eur_cents( $obj ), (string) ( $obj['payment_intent'] ?? '' ) );
			return 'registrato';
		}
		if ( in_array( $type, array( 'checkout.session.expired', 'checkout.session.async_payment_failed' ), true ) && in_array( $p['status'], array( 'created', 'pending' ), true ) ) {
			$this->update( (int) $p['id'], array( 'status' => 'checkout.session.expired' === $type ? 'expired' : 'failed' ) );
			return 'chiuso';
		}
		return 'in attesa';
	}

	/** Lavoro periodico di WP-Cron (pianificato da Plugin::schedule_jobs()). */
	public static function check_pending_job(): void {
		Plugin::payments_engine()->check_pending();
	}

	/** Ricontrolla i pagamenti rimasti in sospeso (chiamato ogni ora da WP-Cron e dal pulsante in amministrazione). */
	public function check_pending( bool $include_recent = false ): array {
		$this->recover_stuck();
		$limit = gmdate( 'Y-m-d H:i:s', time() - ( $include_recent ? 0 : Limits::get( 'pay_pending_minutes' ) * 60 ) );
		$rows  = $this->db()->get_results(
			$this->db()->prepare( 'SELECT * FROM ' . Db::t( 'payments' ) . " WHERE status = 'pending' AND created_at <= %s ORDER BY id LIMIT 50", get_date_from_gmt( $limit ) ),
			ARRAY_A
		) ?: array();
		$out = array( 'checked' => 0, 'registered' => 0, 'expired' => 0 );
		foreach ( $rows as $p ) {
			$out['checked']++;
			if ( $this->confirm_with_gateway( $p ) ) {
				$out['registered']++;
				continue;
			}
			$fresh = $this->get( (int) $p['id'] );
			if ( 'pending' === $fresh['status'] && strtotime( $p['created_at'] ) < time() - Limits::get( 'pay_expire_days' ) * DAY_IN_SECONDS ) {
				$this->update( (int) $p['id'], array( 'status' => 'expired' ) );
				$out['expired']++;
			}
		}
		return $out;
	}

	/**
	 * Un pagamento rimasto "in registrazione" (il server si è fermato a metà): i soldi sono arrivati ma non si sa quanto sia stato scritto in prima nota.
	 * Non si riprova da soli (si rischierebbe di registrare due volte una parte): lo si segna "da controllare".
	 */
	private function recover_stuck(): void {
		$tbl   = Db::t( 'payments' );
		$limit = gmdate( 'Y-m-d H:i:s', strtotime( Db::now() ) - self::STUCK_AFTER_MINUTES * 60 );
		foreach ( $this->db()->get_results( $this->db()->prepare( "SELECT id FROM $tbl WHERE status = 'processing' AND updated_at <= %s LIMIT 50", $limit ), ARRAY_A ) ?: array() as $r ) {
			$this->update( (int) $r['id'], array( 'status' => 'paid', 'paid_at' => Db::now(), 'review' => 1, 'error' => 'Registrazione interrotta a metà: controlla la prima nota prima di correggere a mano.' ) );
			Audit::log( 'payment.stuck', 'payment', (int) $r['id'] );
		}
	}

	// ---------- Registrazione in prima nota ----------

	/**
	 * Registra l'incasso (una sola volta). Il pagamento è già arrivato: se qualcosa non torna (prenotazione annullata nel frattempo,
	 * importo diverso) i soldi entrano comunque come "pagamento online non abbinato" e il pagamento viene segnato "da controllare".
	 *
	 * @return bool true se questa chiamata ha registrato il pagamento
	 */
	private function finalize( array $p, int $paid_cents, string $provider_payment_id ): bool {
		$tbl     = Db::t( 'payments' );
		$open    = "'" . implode( "','", self::OPEN_STATUSES ) . "'";
		$claimed = $this->db()->query( $this->db()->prepare( "UPDATE $tbl SET status = 'processing', updated_at = %s WHERE id = %d AND status IN ($open)", Db::now(), (int) $p['id'] ) );
		if ( 1 !== (int) $claimed ) {
			return false; // già registrato (o in registrazione) da un altro passaggio
		}
		$items       = (array) json_decode( (string) $p['items'], true );
		$ledger      = Plugin::ledger();
		$account     = $ledger->online_account( $p['provider'] );
		$method      = $p['provider'];
		$today       = Db::today();
		$ref         = substr( (string) $p['provider_ref'], 0, 80 );
		$labels      = array( 'paypal' => 'PayPal', 'woocommerce' => 'WooCommerce' );
		$note        = 'Pagamento online (' . ( $labels[ $p['provider'] ] ?? 'Stripe' ) . ')';
		$allocated   = 0;
		$review      = false;
		$error       = null;
		$unallocated = 0;
		try {
			if ( $paid_cents !== (int) $p['amount_cents'] ) {
				$review      = true;
				$error       = 'Importo pagato (' . Money::format( $paid_cents ) . ') diverso da quello atteso (' . Money::format( (int) $p['amount_cents'] ) . ').';
				$unallocated = $paid_cents;
			} else {
				foreach ( PaymentItems::group_by_person( $items ) as $person_id => $group ) {
					$lines = array();
					foreach ( $group as $i ) {
						$lines[] = $this->line_for( $i, $note, $ledger );
					}
					try {
						$ledger->record_receipt( array( 'date' => $today, 'account_id' => $account, 'method' => $method, 'person_id' => (int) $person_id, 'document_ref' => $ref, 'lines' => $lines ) );
						$allocated += PaymentItems::total( $group );
					} catch ( \InvalidArgumentException $e ) {
						$review       = true;
						$error        = mb_substr( $e->getMessage(), 0, 250 );
						$unallocated += PaymentItems::total( $group );
					}
				}
			}
			if ( $unallocated > 0 ) {
				$ledger->record_receipt(
					array(
						'date' => $today, 'account_id' => $account, 'method' => $method, 'person_id' => (int) $p['payer_person_id'], 'document_ref' => $ref,
						'lines' => array( array( 'category_id' => $ledger->category_id_of_kind( 'other_income' ), 'amount_cents' => $unallocated, 'description' => $note . ' non abbinato a una voce' ) ),
					)
				);
			}
		} catch ( \Throwable $e ) {
			$review = true;
			$error  = mb_substr( 'Registrazione incompleta: ' . $e->getMessage(), 0, 250 );
		}
		$this->update( (int) $p['id'], array( 'status' => 'paid', 'paid_at' => Db::now(), 'provider_payment_id' => mb_substr( $provider_payment_id, 0, 120 ), 'allocated_cents' => $allocated, 'review' => $review ? 1 : 0, 'error' => $error ) );
		Audit::log( 'payment.paid', 'payment', (int) $p['id'], array( 'provider' => $p['provider'], 'cents' => $paid_cents, 'review' => $review ) );
		$this->send_receipt_email( $this->get( (int) $p['id'] ), $items );
		return true;
	}

	/** Ordine WooCommerce pagato: registra l'incasso del pagamento corrispondente (una sola volta).  bool true se registrato ora */
	public function finalize_external( string $public_id, int $paid_cents, string $ref ): bool {
		$p = $this->get_by_public( $public_id );
		if ( ! $p || PaymentConfig::WOOCOMMERCE !== $p['provider'] ) {
			return false;
		}
		$this->update( (int) $p['id'], array( 'provider_ref' => substr( $ref, 0, 120 ) ) );
		$p = $this->get( (int) $p['id'] );
		return $p ? $this->finalize( $p, $paid_cents, $ref ) : false;
	}

	/** Ordine WooCommerce annullato o fallito: il pagamento in attesa si chiude. */
	public function cancel_external( string $public_id ): void {
		$p = $this->get_by_public( $public_id );
		if ( $p && PaymentConfig::WOOCOMMERCE === $p['provider'] && in_array( $p['status'], array( 'created', 'pending' ), true ) ) {
			$this->update( (int) $p['id'], array( 'status' => 'cancelled' ) );
			Audit::log( 'payment.cancelled', 'payment', (int) $p['id'], array( 'provider' => 'woocommerce' ) );
		}
	}

	private function line_for( array $i, string $note, LedgerService $ledger ): array {
		switch ( $i['type'] ) {
			case PaymentItems::MEMBERSHIP:
				return array( 'category_id' => $ledger->category_id_of_kind( 'membership' ), 'amount_cents' => (int) $i['amount_cents'], 'social_year' => $i['social_year'], 'description' => $note );
			case PaymentItems::COURSE_MONTH:
				return array( 'category_id' => $ledger->category_id_of_kind( 'activity_fee' ), 'amount_cents' => (int) $i['amount_cents'], 'activity_id' => (int) $i['activity_id'], 'competence_month' => $i['month'], 'description' => $note );
			default:
				return array( 'category_id' => $ledger->category_id_of_kind( 'activity_fee' ), 'amount_cents' => (int) $i['amount_cents'], 'activity_id' => (int) $i['activity_id'], 'session_id' => (int) $i['session_id'], 'description' => $note );
		}
	}

	/** Ricevuta via email al socio che ha pagato (se l'invio non riesce non succede nulla: il pagamento è già registrato). */
	private function send_receipt_email( ?array $p, array $items ): void {
		$payer = $p ? Plugin::people()->get( (int) $p['payer_person_id'] ) : null;
		if ( ! $p || ! $payer || empty( $payer['email'] ) ) {
			return;
		}
		$assoc = (string) Settings::get( 'association_name' );
		$lines = array_map( function ( $i ) {
			return '- ' . PaymentItems::line_name( $i ) . ': ' . Money::format( (int) $i['amount_cents'] );
		}, $items );
		$body  = 'Ciao ' . $payer['first_name'] . ",\n\nabbiamo ricevuto il tuo pagamento online" . ( '' !== $assoc ? ' per ' . $assoc : '' ) . ".\n\n" . implode( "\n", $lines )
			. "\n\nTotale: " . Money::format( (int) $p['amount_cents'] ) . "\nData: " . ( new \DateTimeImmutable( (string) $p['paid_at'] ) )->format( 'd/m/Y' ) . "\nRiferimento: " . $p['public_id'] . "\n\nGrazie!";
		\AssociazioneSemplice\Texts::mail( $payer['email'], 'Ricevuta del pagamento' . ( '' !== $assoc ? ' — ' . $assoc : '' ), $body );
	}
}
