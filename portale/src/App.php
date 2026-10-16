<?php
namespace Portale;

/** Router e pagine del portale (nessun framework). */
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
			$this->view( 'message', array( 'title' => 'Non è andata a buon fine', 'text' => $e->getMessage() ) );
		} catch ( \Throwable $e ) {
			error_log( '[portale] ' . $e->getMessage() );
			http_response_code( 500 );
			$this->view( 'message', array( 'title' => 'Qualcosa è andato storto', 'text' => 'Riprova tra qualche minuto. Se il problema continua scrivici.' ) );
		}
	}

	private function route( string $method, string $path ): void {
		$get = 'GET' === $method;
		if ( $get && '/' === $path ) {
			$this->view( 'home', array( 'plans' => Config::get( 'plans', array() ) ) );
		} elseif ( $get && '/free' === $path ) {
			$this->view( 'free', array() );
		} elseif ( ! $get && '/free' === $path ) {
			list( $cust, $sub ) = $this->billing->startFree( (string) $this->in( 'email' ), (string) $this->in( 'domain' ) );
			if ( ! $this->auth->canIssue( $cust['id'] ) ) {
				throw new UserError( 'Hai richiesto troppi link: riprova tra un po\'.' );
			}
			$this->sendLink( $cust, $this->auth->issue( $cust['id'], $sub['id'] ), 'Conferma la tua licenza gratuita', 'Per attivare la licenza gratuita di Associazione Semplice per ' . $sub['domain'] . ' apri questo link entro 15 minuti' );
			$this->view( 'message', array( 'title' => 'Controlla la posta', 'text' => 'Ti abbiamo scritto un link per confermare l\'email e ricevere la chiave.' ) );
		} elseif ( $get && '/acquista' === $path ) {
			$plan   = (string) ( $_GET['plan'] ?? '' );
			$period = (string) ( $_GET['period'] ?? '' );
			if ( ! Config::price( $plan, $period ) ) {
				throw new UserError( 'Piano non disponibile.' );
			}
			$this->view( 'buy', array( 'plan' => $plan, 'period' => $period, 'price' => Config::price( $plan, $period ), 'label' => Config::get( 'plans.' . $plan . '.label' ) ) );
		} elseif ( ! $get && '/acquista' === $path ) {
			$url = $this->billing->startPaid( (string) $this->in( 'email' ), (string) $this->in( 'domain' ), (string) $this->in( 'plan' ), (string) $this->in( 'period' ), (string) $this->in( 'provider' ) );
			header( 'Location: ' . $url, true, 303 );
		} elseif ( $get && '/grazie' === $path ) {
			$this->view( 'message', array( 'title' => 'Grazie!', 'text' => 'Appena il pagamento è confermato ti scriviamo un link per entrare nell\'area riservata e vedere la chiave di licenza.' ) );
		} elseif ( $get && '/accedi' === $path ) {
			$this->view( 'login', array() );
		} elseif ( ! $get && '/accedi' === $path ) {
			$email = strtolower( trim( (string) $this->in( 'email' ) ) );
			$cust  = $this->db->one( 'SELECT * FROM customers WHERE email = ?', array( $email ) );
			// Stessa risposta se l'email non esiste: non si rivela chi è cliente.
			if ( $cust && $this->auth->canIssue( $cust['id'] ) ) {
				$this->sendLink( $cust, $this->auth->issue( $cust['id'] ), 'Il tuo accesso ad Associazione Semplice', 'Per entrare nell\'area riservata apri questo link entro 15 minuti' );
			}
			$this->view( 'message', array( 'title' => 'Controlla la posta', 'text' => 'Se l\'indirizzo è registrato, ti abbiamo mandato un link per entrare.' ) );
		} elseif ( $get && 0 === strpos( $path, '/entra/' ) ) {
			// Il link apre una pagina con un pulsante: i programmi antispam che "visitano" i link non lo consumano.
			$this->view( 'enter', array( 'token' => substr( $path, 7 ) ) );
		} elseif ( ! $get && '/entra' === $path ) {
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
			header( 'Location: /account', true, 303 );
		} elseif ( $get && '/account' === $path ) {
			$cid = $this->customerId();
			$this->view( 'account', array( 'subs' => $this->billing->forCustomer( $cid ), 'email' => $this->customerEmail( $cid ) ) );
		} elseif ( ! $get && '/account/dominio' === $path ) {
			$this->billing->changeDomain( (string) $this->in( 'id' ), $this->customerId(), (string) $this->in( 'domain' ) );
			header( 'Location: /account', true, 303 );
		} elseif ( ! $get && '/account/annulla' === $path ) {
			$this->billing->cancel( (string) $this->in( 'id' ), $this->customerId() );
			header( 'Location: /account', true, 303 );
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
			$this->view( 'message', array( 'title' => 'Pagina non trovata', 'text' => 'Questa pagina non esiste.' ) );
		}
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

	private function sendLink( array $cust, string $token, string $subject, string $intro ): void {
		$url = rtrim( (string) Config::get( 'base_url' ), '/' ) . '/entra/' . $token;
		Mailer::send( $cust['email'], $subject, $intro . ":\n\n" . $url . "\n\nSe non sei stato tu, ignora questo messaggio.\n" );
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

	private function view( string $name, array $vars ): void {
		extract( $vars, EXTR_SKIP );
		ob_start();
		require __DIR__ . '/../views/' . $name . '.php';
		$content = ob_get_clean();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );
		header( 'Referrer-Policy: same-origin' );
		require __DIR__ . '/../views/layout.php';
	}
}
