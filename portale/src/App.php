<?php
namespace Portale;

/** Router e pagine del portale (nessun framework). Il pannello di gestione è in {@see Admin}. */
final class App {

	/** @var Db */
	private $db;
	/** @var Billing */
	private $billing;
	/** @var Auth */
	private $auth;

	public function __construct( Db $db, Billing $billing, Auth $auth ) {
		$this->db      = $db;
		$this->billing = $billing;
		$this->auth    = $auth;
	}

	public function run( string $method, string $path ): void {
		$path = '/' . trim( $path, '/' );
		try {
			if ( 'POST' === $method && 0 === strpos( $path, '/webhook/' ) ) {
				$this->webhook( $path );
				return;
			}
			if ( 'POST' === $method && ! $this->csrfOk() ) {
				throw new UserError( 'Sessione scaduta: ricarica la pagina e riprova.' );
			}
			$this->route( $method, $path );
		} catch ( UserError $e ) {
			http_response_code( 422 );
			View::render( 'message', array( 'title' => 'Non è andata a buon fine', 'text' => $e->getMessage() ) );
		} catch ( \Throwable $e ) {
			error_log( '[portale] ' . $e->getMessage() );
			http_response_code( 500 );
			View::render( 'message', array( 'title' => 'Qualcosa è andato storto', 'text' => 'Riprova tra qualche minuto. Se il problema continua scrivici.' ) );
		}
	}

	private function route( string $method, string $path ): void {
		$get = 'GET' === $method;
		if ( 0 === strpos( $path, '/admin' ) ) {
			( new Admin( $this->db, $this->billing, $this->customerId() ) )->handle( $method, $path );
		} elseif ( $get && '/' === $path ) {
			View::render( 'home', array( 'plans' => Config::get( 'plans', array() ), 'trial' => Config::get( 'trial_enabled', true ) ? Settings::limit( 'trial_days' ) : 0 ) );
		} elseif ( $get && '/robots.txt' === $path ) {
			View::headers( 'text/plain; charset=utf-8' );
			echo Config::get( 'indexable', false ) ? "User-agent: *\nAllow: /\nDisallow: /account\nDisallow: /admin\nDisallow: /entra\n" : "User-agent: *\nDisallow: /\n";
		} elseif ( $get && '/free' === $path ) {
			View::render( 'free', array() );
		} elseif ( ! $get && '/free' === $path ) {
			list( $cust, $sub ) = $this->billing->startFree( (string) $this->in( 'email' ), (string) $this->in( 'domain' ) );
			if ( ! $this->auth->canIssue( $cust['id'] ) ) {
				throw new UserError( 'Hai richiesto troppi link: riprova tra un po\'.' );
			}
			$this->sendLink( $cust, $this->auth->issue( $cust['id'], $sub['id'] ), 'free_confirm', array( 'dominio' => $sub['domain'] ) );
			View::render( 'message', array( 'title' => 'Controlla la posta', 'text' => 'Ti abbiamo scritto un link per confermare l\'email e ricevere la chiave.' ) );
		} elseif ( $get && '/acquista' === $path ) {
			$plan   = (string) ( $_GET['plan'] ?? '' );
			$period = (string) ( $_GET['period'] ?? '' );
			$price  = Config::price( $plan, $period );
			if ( ! $price ) {
				throw new UserError( 'Piano non disponibile.' );
			}
			View::render( 'buy', array(
				'plan' => $plan, 'period' => $period, 'price' => $price, 'label' => Config::get( 'plans.' . $plan . '.label' ),
				'methods' => $this->billing->methods( $price ), 'days' => Settings::limit( 'year' === $period ? 'duration_year' : 'duration_month' ),
			) );
		} elseif ( ! $get && '/acquista' === $path ) {
			list( $provider, $mode ) = array_pad( explode( ':', (string) $this->in( 'method' ), 2 ), 2, '' );
			$url = $this->billing->startPaid( (string) $this->in( 'email' ), (string) $this->in( 'domain' ), (string) $this->in( 'plan' ), (string) $this->in( 'period' ), $provider, $mode );
			header( 'Location: ' . $url, true, 303 );
		} elseif ( $get && '/paypal/ritorno' === $path ) {
			$this->paypalReturn( (string) ( $_GET['token'] ?? '' ) );
		} elseif ( $get && '/grazie' === $path ) {
			View::render( 'message', array( 'title' => 'Grazie!', 'text' => 'Appena il pagamento è confermato ti scriviamo un link per entrare nell\'area riservata e vedere la chiave di licenza.' ) );
		} elseif ( $get && '/accedi' === $path ) {
			View::render( 'login', array() );
		} elseif ( ! $get && '/accedi' === $path ) {
			$email = strtolower( trim( (string) $this->in( 'email' ) ) );
			$cust  = $this->db->one( 'SELECT * FROM customers WHERE email = ?', array( $email ) );
			// Stessa risposta se l'email non esiste: non si rivela chi è cliente.
			if ( $cust && $this->auth->canIssue( $cust['id'] ) ) {
				$this->sendLink( $cust, $this->auth->issue( $cust['id'] ), 'login_link' );
			}
			View::render( 'message', array( 'title' => 'Controlla la posta', 'text' => 'Se l\'indirizzo è registrato, ti abbiamo mandato un link per entrare.' ) );
		} elseif ( $get && 0 === strpos( $path, '/entra/' ) ) {
			// Il link apre una pagina con un pulsante: i programmi antispam che "visitano" i link non lo consumano.
			View::render( 'enter', array( 'token' => substr( $path, 7 ) ) );
		} elseif ( ! $get && '/entra' === $path ) {
			$this->enter();
		} elseif ( $get && '/account' === $path ) {
			$this->account();
		} elseif ( ! $get && '/account/dominio' === $path ) {
			$this->billing->changeDomain( (string) $this->in( 'id' ), $this->customerId(), (string) $this->in( 'domain' ) );
			header( 'Location: /account', true, 303 );
		} elseif ( ! $get && '/account/annulla' === $path ) {
			$this->billing->cancel( (string) $this->in( 'id' ), $this->customerId() );
			header( 'Location: /account', true, 303 );
		} elseif ( ! $get && '/account/prova' === $path ) {
			$this->billing->startTrial( $this->customerId(), (string) $this->in( 'id' ) );
			header( 'Location: /account', true, 303 );
		} elseif ( ! $get && '/account/rinnova' === $path ) {
			$url = $this->billing->startRenewal( (string) $this->in( 'id' ), $this->customerId(), (string) $this->in( 'provider' ) );
			header( 'Location: ' . $url, true, 303 );
		} elseif ( ! $get && '/account/fatturazione' === $path ) {
			$sub = $this->billing->subscription( (string) $this->in( 'id' ) );
			if ( ! $sub || $sub['customer_id'] !== $this->customerId() || empty( $sub['provider_customer'] ) || 'stripe' !== $sub['provider'] ) {
				throw new UserError( 'Portale di fatturazione non disponibile per questo abbonamento.' );
			}
			header( 'Location: ' . Stripe::portalUrl( $sub['provider_customer'] ), true, 303 );
		} elseif ( ! $get && '/esci' === $path ) {
			$_SESSION = array();
			session_destroy();
			header( 'Location: /', true, 303 );
		} else {
			http_response_code( 404 );
			View::render( 'message', array( 'title' => 'Pagina non trovata', 'text' => 'Questa pagina non esiste.' ) );
		}
	}

	private function enter(): void {
		$t = $this->auth->consume( (string) $this->in( 'token' ) );
		if ( ! $t ) {
			throw new UserError( 'Il link non è valido o è scaduto: richiedine uno nuovo.' );
		}
		if ( $t['subscription_id'] ) {
			$sub = $this->billing->subscription( $t['subscription_id'] );
			if ( $sub && 'free' === $sub['provider'] && $sub['customer_id'] === $t['customer_id'] ) {
				$this->billing->activate( $sub['id'] );
			}
		}
		session_regenerate_id( true );
		$_SESSION['customer'] = $t['customer_id'];
		$_SESSION['admin']    = Admin::isAdminEmail( $this->customerEmail( $t['customer_id'] ) );
		header( 'Location: ' . ( $_SESSION['admin'] ? '/admin' : '/account' ), true, 303 );
	}

	private function account(): void {
		$cid  = $this->customerId();
		$subs = $this->billing->forCustomer( $cid );
		$trial = array();
		foreach ( $subs as $s ) {
			$trial[ $s['id'] ] = $this->billing->trialAvailable( $s );
		}
		View::render( 'account', array(
			'subs' => $subs, 'email' => $this->customerEmail( $cid ), 'trial' => $trial, 'trial_days' => Settings::limit( 'trial_days' ),
			'trial_label' => (string) Config::get( 'plans.' . Config::get( 'trial_plan', 'full' ) . '.label', 'Pro' ),
			'billing' => $this->billing, 'is_admin' => ! empty( $_SESSION['admin'] ),
		) );
	}

	/** Il cliente torna da PayPal dopo aver approvato un pagamento singolo: qui si incassa l'ordine. */
	private function paypalReturn( string $orderId ): void {
		$sub = $this->billing->byProviderRef( 'paypal', $orderId );
		if ( ! $sub || 'once' !== $sub['mode'] ) {
			throw new UserError( 'Pagamento non trovato.' );
		}
		if ( 'pending' === $sub['status'] ) {
			if ( ! PayPal::captureOrder( $orderId ) ) {
				throw new UserError( 'PayPal non ha completato il pagamento. Non è stato addebitato nulla: riprova.' );
			}
			$this->billing->completePayment( $sub['id'] );
		}
		header( 'Location: /grazie', true, 303 );
	}

	private function webhook( string $path ): void {
		$payload = (string) file_get_contents( 'php://input' );
		$hooks   = new Webhooks( $this->db, $this->billing );
		if ( '/webhook/stripe' === $path ) {
			$code = $hooks->stripe( $payload, (string) ( $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '' ) );
		} elseif ( '/webhook/paypal' === $path ) {
			$h = array();
			foreach ( $_SERVER as $k => $v ) {
				if ( 0 === strpos( $k, 'HTTP_PAYPAL_' ) ) {
					$h[ strtolower( str_replace( '_', '-', substr( $k, 5 ) ) ) ] = (string) $v;
				}
			}
			$code = $hooks->paypal( $payload, $h );
		} else {
			$code = 404;
		}
		http_response_code( $code );
	}

	private function sendLink( array $cust, string $token, string $event, array $vars = array() ): void {
		Emails::send( $this->db, $event, $cust['email'], $vars + array( 'link' => rtrim( (string) Config::get( 'base_url' ), '/' ) . '/entra/' . $token ) );
	}

	/** @return mixed */
	private function in( string $key ) {
		return $_POST[ $key ] ?? null;
	}

	private function customerId(): string {
		if ( empty( $_SESSION['customer'] ) ) {
			header( 'Location: /accedi', true, 303 );
			exit;
		}
		return (string) $_SESSION['customer'];
	}

	private function customerEmail( string $id ): string {
		$c = $this->db->one( 'SELECT email FROM customers WHERE id = ?', array( $id ) );
		return $c ? (string) $c['email'] : '';
	}

	private function csrfOk(): bool {
		return ! empty( $_SESSION['csrf'] ) && hash_equals( (string) $_SESSION['csrf'], (string) ( $_POST['csrf'] ?? '' ) );
	}

	public static function csrf(): string {
		if ( empty( $_SESSION['csrf'] ) ) {
			$_SESSION['csrf'] = bin2hex( random_bytes( 16 ) );
		}
		return (string) $_SESSION['csrf'];
	}
}
