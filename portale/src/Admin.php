<?php
namespace Portale;

/**
 * Pannello di gestione: stesso accesso dei clienti (link per email), ma solo per gli indirizzi elencati in `admins` di config.php.
 * Ogni richiesta ricontrolla l'indirizzo; ogni modifica finisce nel registro.
 */
final class Admin {

	/** @var Db */
	private $db;
	/** @var Billing */
	private $billing;
	/** @var string */
	private $email;

	/** Amministratore = indirizzo in `admins` di config.php oppure con ruolo admin nello staff (il ruolo «solo supporto» non basta). */
	public static function isAdminEmail( string $email, ?Db $db = null ): bool {
		return Staff::ADMIN === Staff::role( $db, $email );
	}

	public function __construct( Db $db, Billing $billing, string $customerId ) {
		$this->db      = $db;
		$this->billing = $billing;
		$row           = $db->one( 'SELECT email FROM customers WHERE id = ?', array( $customerId ) );
		$this->email   = $row ? (string) $row['email'] : '';
		if ( ! self::isAdminEmail( $this->email, $db ) ) {
			$_SESSION['admin'] = false;
			http_response_code( 403 );
			throw new UserError( 'Questa area è riservata alla gestione.' );
		}
		$_SESSION['admin'] = true;
	}

	public function handle( string $method, string $path ): void {
		$get = 'GET' === $method;
		$p   = explode( '/', trim( substr( $path, 6 ), '/' ) ); // dopo «/admin»
		$a   = $p[0] ?? '';
		$b   = $p[1] ?? '';
		$c   = $p[2] ?? '';

		if ( '' === $a && $get ) {
			$this->dashboard();
		} elseif ( 'licenze' === $a ) {
			if ( $get && '' === $b ) {
				$this->licenses();
			} elseif ( 'nuova' === $b ) {
				$get ? $this->show( 'new_license', 'licenze', array( 'plans' => Config::get( 'plans', array() ) ) ) : $this->issue();
			} elseif ( $get && '' !== $b ) {
				$this->licenseDetail( $b );
			} elseif ( ! $get && '' !== $c ) {
				$this->licenseAction( $b, $c );
			} else {
				$this->notFound();
			}
		} elseif ( 'wega' === $a ) {
			$this->wegaSync( $get );
		} elseif ( 'staff' === $a ) {
			$this->staff( $get, $b, $c );
		} elseif ( 'clienti' === $a && $get ) {
			$this->customers();
		} elseif ( 'clienti' === $a && ! $get && '' !== $b && 'elimina' === $c ) {
			$this->deleteCustomer( $b );
		} elseif ( 'prezzi' === $a ) {
			if ( $get ) {
				$this->show( 'prices', 'prezzi', array( 'plans' => Config::get( 'plans', array() ) ) );
			} elseif ( '' !== $b ) {
				$this->savePlan( $b );
			} else {
				$this->notFound();
			}
		} elseif ( 'email' === $a ) {
			if ( $get && '' === $b ) {
				$this->emails();
			} elseif ( '' !== $b ) {
				$get ? $this->emailEditor( $b ) : $this->emailAction( $b );
			} else {
				$this->notFound();
			}
		} elseif ( 'impostazioni' === $a ) {
			if ( $get ) {
				$this->show( 'settings', 'impostazioni', array( 'g' => Settings::general(), 'limits' => Settings::LIMITS, 'plans' => Config::get( 'plans', array() ), 'cfg' => $this->configStatus() ) );
			} else {
				Settings::saveGeneral( $this->db, $_POST );
				$this->done( 'settings', 'Impostazioni salvate.', '/admin/impostazioni' );
			}
		} elseif ( 'pagamenti' === $a ) {
			if ( $get ) {
				$this->show( 'payments', 'pagamenti', array( 's' => Settings::secretsStatus( $this->db ), 'base' => rtrim( (string) Config::get( 'base_url' ), '/' ) ) );
			} elseif ( 'prova' === $b ) {
				list( $ok, $msg ) = Connections::test( $c );
				$this->done( 'test_' . $c, ( $ok ? '✓ ' : '✗ ' ) . $msg, '/admin/pagamenti' );
			} else {
				$changed = Settings::saveSecrets( $this->db, $_POST );
				$this->done( 'secrets', $changed ? 'Chiavi salvate (' . implode( ', ', $changed ) . ').' : 'Impostazioni di pagamento salvate.', '/admin/pagamenti' );
			}
		} elseif ( 'cookie' === $a ) {
			if ( $get ) {
				$this->show( 'cookies', 'cookie', array( 't' => Tracking::conf(), 'cats' => Tracking::CATEGORIES ) );
			} else {
				Settings::saveTracking( $this->db, $_POST );
				$this->done( 'tracking', 'Cookie e script salvati.', '/admin/cookie' );
			}
		} elseif ( 'legale' === $a ) {
			if ( $get ) {
				$this->show( 'legal_edit', 'legale', array( 'vars' => Legal::vars(), 'legal' => (array) Config::get( 'legal', array() ) ) );
			} else {
				Settings::saveLegal( $this->db, $_POST );
				$this->done( 'legal', 'Pagine legali salvate.', '/admin/legale' );
			}
		} elseif ( 'versioni' === $a ) {
			if ( $get ) {
				$rows = array();
				foreach ( array_keys( Releases::PRODUCTS ) as $p ) {
					$rows[ $p ] = Releases::all( $this->db, $p );
				}
				$this->show( 'releases', 'versioni', array( 'rows' => $rows, 'keep' => Settings::limit( 'releases_keep' ) ) );
			} elseif ( 'elimina' === $b ) {
				Releases::remove( $this->db, (string) ( $_POST['product'] ?? '' ), (string) ( $_POST['version'] ?? '' ), $this->email );
				$this->done( 'release_remove', 'Versione eliminata.', '/admin/versioni' );
			} else {
				$f = $_FILES['package'] ?? null;
				if ( ! $f || UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( (string) $f['tmp_name'] ) ) {
					throw new UserError( 'Caricamento non riuscito: scegli lo zip del plugin (controlla anche il limite di upload_max_filesize del server).' );
				}
				$row = Releases::add( $this->db, (string) ( $_POST['product'] ?? '' ), (string) $f['tmp_name'], (string) ( $_POST['note'] ?? '' ), $this->email );
				$this->done( 'release_add', 'Versione ' . $row['version'] . ' pubblicata.', '/admin/versioni' );
			}
		} elseif ( 'registro' === $a && $get ) {
			$this->show( 'audit', 'registro', array( 'rows' => Audit::recent( $this->db, 200 ) ) );
		} else {
			$this->notFound();
		}
	}

	// --- Pagine ----------------------------------------------------------------------------------------------------------

	private function dashboard(): void {
		$now   = Db::now();
		$count = function ( string $where, array $args = array() ) {
			$r = $this->db->one( 'SELECT COUNT(*) AS n FROM subscriptions WHERE license_key_enc IS NOT NULL AND ' . $where, $args );
			return (int) ( $r['n'] ?? 0 );
		};
		$paid = $this->db->all( "SELECT * FROM subscriptions WHERE license_key_enc IS NOT NULL AND provider IN ('stripe','paypal') AND status IN ('active','past_due')" );
		$mrr  = 0;
		$rec  = 0;
		foreach ( $paid as $s ) {
			if ( ! $this->billing->isRecurring( $s ) || $s['cancel_at_period_end'] ) {
				continue;
			}
			$price = Config::get( 'plans.' . $s['plan_code'] . '.prices.' . $s['period'] . '.cents' );
			if ( $price ) {
				$mrr += 'year' === $s['period'] ? $price / 12 : $price;
				++$rec;
			}
		}
		$byPlan = $this->db->all( "SELECT plan_code, provider, COUNT(*) AS n FROM subscriptions WHERE license_key_enc IS NOT NULL AND status = 'active' AND expires_at > ? GROUP BY plan_code, provider", array( $now ) );
		$this->show( 'dashboard', 'cruscotto', array(
			'active'   => $count( "status = 'active' AND expires_at > ?", array( $now ) ),
			'paying'   => $rec,
			'mrr'      => (int) round( $mrr ),
			'trials'   => $count( "provider = 'trial' AND status = 'active' AND expires_at > ?", array( $now ) ),
			'expiring' => $count( "status = 'active' AND provider <> 'free' AND expires_at > ? AND expires_at <= ?", array( $now, gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 ) ) ),
			'past_due' => $count( "status = 'past_due'" ),
			'new30'    => (int) ( $this->db->one( 'SELECT COUNT(*) AS n FROM customers WHERE created_at >= ?', array( gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 ) ) )['n'] ?? 0 ),
			'by_plan'  => $byPlan,
			'recent'   => $this->db->all( "SELECT s.*, c.email FROM subscriptions s JOIN customers c ON c.id = s.customer_id WHERE s.license_key_enc IS NOT NULL ORDER BY s.created_at DESC LIMIT 8" ),
			'cfg'      => $this->configStatus(),
		) );
	}

	/** Cosa manca per vendere davvero (mostrato nel cruscotto e nelle impostazioni). */
	private function configStatus(): array {
		return array(
			'wega'   => '' !== (string) Config::get( 'wega.token', '' ),
			'stripe' => '' !== (string) Config::get( 'stripe.secret_key', '' ) && '' !== (string) Config::get( 'stripe.webhook_secret', '' ),
			'paypal' => '' !== (string) Config::get( 'paypal.client_id', '' ) && '' !== (string) Config::get( 'paypal.webhook_id', '' ),
			'key'    => '' !== (string) Config::get( 'app_key', '' ),
		);
	}

	private function licenses(): void {
		$q      = trim( (string) ( $_GET['q'] ?? '' ) );
		$status = (string) ( $_GET['stato'] ?? '' );
		$page   = max( 1, (int) ( $_GET['p'] ?? 1 ) );
		$where  = array( '1=1' );
		$args   = array();
		if ( '' !== $q ) {
			$where[] = '(c.email LIKE ? OR s.domain LIKE ?)';
			$like    = '%' . str_replace( array( '%', '_' ), array( '', '' ), $q ) . '%';
			array_push( $args, $like, $like );
		}
		if ( in_array( $status, array( 'active', 'pending', 'past_due', 'canceled', 'merged' ), true ) ) {
			$where[] = 's.status = ?';
			$args[]  = $status;
		}
		$per  = 40;
		$rows = $this->db->all(
			'SELECT s.*, c.email FROM subscriptions s JOIN customers c ON c.id = s.customer_id WHERE ' . implode( ' AND ', $where ) . ' ORDER BY s.created_at DESC LIMIT ' . ( $per + 1 ) . ' OFFSET ' . ( ( $page - 1 ) * $per ),
			$args
		);
		$more = count( $rows ) > $per;
		$this->show( 'licenses', 'licenze', array( 'rows' => array_slice( $rows, 0, $per ), 'q' => $q, 'status' => $status, 'page' => $page, 'more' => $more, 'billing' => $this->billing ) );
	}

	private function licenseDetail( string $id ): void {
		$sub = $this->sub( $id );
		$key = '';
		try {
			$key = ! empty( $sub['license_key_enc'] ) ? Secrets::open( $sub['license_key_enc'] ) : '';
		} catch ( \Throwable $e ) {
			$key = '';
		}
		$remote = null;
		if ( isset( $_GET['verifica'] ) && '' !== $key ) {
			$wega = new WegaClient( (string) Config::get( 'wega.base_url' ), (string) Config::get( 'wega.token' ) );
			try {
				$remote = array( 'info' => $wega->show( (string) $sub['license_id'] ), 'check' => $wega->validate( $key, $sub['domain'] ) );
			} catch ( WegaException $e ) {
				$remote = array( 'error' => $e->errorCode . ' — ' . $e->getMessage() );
			}
		}
		$cust = $this->db->one( 'SELECT * FROM customers WHERE id = ?', array( $sub['customer_id'] ) );
		$this->show( 'license', 'licenze', array( 'sub' => $sub, 'key' => $key, 'customer' => $cust, 'remote' => $remote, 'billing' => $this->billing ) );
	}

	private function licenseAction( string $id, string $action ): void {
		$sub = $this->sub( $id );
		$to  = '/admin/licenze/' . $id;
		switch ( $action ) {
			case 'rinnova':
				if ( ! $this->billing->renew( $id, null, null, ! empty( $_POST['notify'] ) ) ) {
					throw new UserError( 'Questa licenza non si può rinnovare (manca la chiave o è annullata).' );
				}
				$this->done( 'renew', 'Licenza ' . $sub['domain'] . ' rinnovata di un periodo.', $to, $id );
				break;
			case 'dominio':
				$this->billing->changeDomain( $id, $sub['customer_id'], (string) ( $_POST['domain'] ?? '' ) );
				$this->done( 'domain', $sub['domain'] . ' → ' . Domain::normalize( (string) ( $_POST['domain'] ?? '' ) ), $to, $id );
				break;
			case 'disdici':
				$this->billing->cancel( $id, $sub['customer_id'] );
				$this->done( 'cancel', 'Rinnovo disdetto per ' . $sub['domain'] . '.', $to, $id );
				break;
			case 'stato':
				$st = (string) ( $_POST['status'] ?? '' );
				if ( ! in_array( $st, array( 'active', 'canceled' ), true ) ) {
					throw new UserError( 'Stato non valido.' );
				}
				$warn = $this->billing->mirror( $sub, 'canceled' === $st ? 'suspend' : 'restore' );
				$this->billing->setStatus( $id, $st );
				$this->done( 'status', 'Stato di ' . $sub['domain'] . ': ' . $st . ( $warn ? ' — ' . $warn : '' ), $to, $id );
				break;
			case 'elimina':
				$deleted = $this->billing->deleteSubscription( $id );
				$this->done( 'sub_delete', 'Licenza di ' . $sub['domain'] . ' eliminata dal portale.' . ( ! empty( $deleted['_warning'] ) ? ' ' . $deleted['_warning'] : '' ), '/admin/licenze', $id );
				break;
			case 'chiave':
				if ( ! $this->billing->recoverKey( $id ) ) {
					throw new UserError( 'Non risulta nessuna chiave da recuperare per questa licenza.' );
				}
				$this->done( 'key_recover', 'Chiave di ' . $sub['domain'] . ' recuperata da Wega.', $to, $id );
				break;
			case 'nota':
				$this->db->run( 'UPDATE subscriptions SET note = ? WHERE id = ?', array( mb_substr( trim( (string) ( $_POST['note'] ?? '' ) ), 0, 255 ), $id ) );
				$this->done( 'note', 'Nota aggiornata.', $to, $id );
				break;
			default:
				$this->notFound();
		}
	}

	private function issue(): void {
		$days = max( 1, min( 3650, (int) ( $_POST['days'] ?? 365 ) ) );
		$sub  = $this->billing->issueManual(
			(string) ( $_POST['email'] ?? '' ), (string) ( $_POST['domain'] ?? '' ), (string) ( $_POST['plan'] ?? '' ), $days,
			(string) ( $_POST['note'] ?? '' ), ! empty( $_POST['notify'] )
		);
		$this->done( 'issue', $sub['domain'] . ' · ' . $sub['plan_code'] . ' · ' . $days . ' giorni', '/admin/licenze/' . $sub['id'], $sub['id'] );
	}

	private function customers(): void {
		$rows = $this->db->all(
			'SELECT c.*, (SELECT COUNT(*) FROM subscriptions s WHERE s.customer_id = c.id) AS licenses, (SELECT GROUP_CONCAT(s.domain) FROM subscriptions s WHERE s.customer_id = c.id) AS domains
			 FROM customers c ORDER BY c.created_at DESC LIMIT 300'
		);
		$this->show( 'customers', 'clienti', array( 'rows' => $rows ) );
	}

	/** Confronto con il server Wega (elenco delle licenze del portale) e importazione di quelle mancanti. */
	private function wegaSync( bool $get ): void {
		try {
			$rows = WegaSync::fetchAll( $this->billing->wega() );
		} catch ( WegaException $e ) {
			if ( in_array( $e->httpStatus, array( 401, 403 ), true ) ) {
				$msg = 'Il token del portale non ha il permesso licenses:list: abilitalo dal pannello Wega (Portali).';
			} elseif ( in_array( $e->httpStatus, array( 404, 405 ), true ) ) {
				$msg = 'Il server Wega non offre ancora l\'elenco delle licenze (GET /licenses): vedi docs/WEGA-richiesta-elenco-licenze.md.';
			} else {
				$msg = 'Il server Wega ha risposto: ' . $e->errorCode . ' (HTTP ' . $e->httpStatus . ') ' . $e->getMessage();
			}
			$this->show( 'wega', 'wega', array( 'error' => $msg, 'rows' => array(), 'orphans' => array() ) );
			return;
		}
		$cmp = WegaSync::compare( $this->db, $rows );
		if ( $get ) {
			$this->show( 'wega', 'wega', array( 'error' => '', 'rows' => $cmp, 'orphans' => WegaSync::orphans( $this->db, $rows ) ) );
			return;
		}
		$only = (string) ( $_POST['id'] ?? '' );
		$done = 0;
		$skip = 0;
		foreach ( $cmp as $r ) {
			if ( 'missing' !== $r['_state'] || ( '' !== $only && (string) $r['id'] !== $only ) ) {
				continue;
			}
			try {
				WegaSync::import( $this->db, $this->billing, $r );
				++$done;
			} catch ( UserError $e ) {
				++$skip;
			}
		}
		$this->done( 'wega_import', $done . ' licenze importate da Wega' . ( $skip ? ', ' . $skip . ' saltate' : '' ) . '.', '/admin/wega' );
	}

	/** Staff: elenco, aggiunta/cambio ruolo (POST /admin/staff) e rimozione (POST /admin/staff/rimuovi). */
	private function staff( bool $get, string $b, string $c ): void {
		if ( $get && '' === $b ) {
			$this->show( 'staff', 'staff', array( 'rows' => Staff::all( $this->db ), 'labels' => Staff::LABELS, 'sso' => SupportSso::enabled(), 'support_url' => SupportSso::supportUrl() ) );
		} elseif ( ! $get && '' === $b ) {
			$email = Staff::set( $this->db, $this->billing, (string) ( $_POST['email'] ?? '' ), (string) ( $_POST['role'] ?? '' ) );
			$this->done( 'staff_set', $email . ' · ' . Staff::LABELS[ (string) $_POST['role'] ], '/admin/staff' );
		} elseif ( ! $get && 'rimuovi' === $b ) {
			$email = strtolower( trim( (string) ( $_POST['email'] ?? '' ) ) );
			if ( $email === strtolower( $this->email ) ) {
				throw new UserError( 'Non puoi togliere te stesso dallo staff.' );
			}
			Staff::remove( $this->db, $email );
			$this->done( 'staff_remove', $email . ' tolto dallo staff.', '/admin/staff' );
		} else {
			$this->notFound();
		}
	}

	private function deleteCustomer( string $id ): void {
		$c = $this->db->one( 'SELECT email FROM customers WHERE id = ?', array( $id ) );
		if ( ! $c ) {
			$this->notFound();
			return;
		}
		if ( 0 === strcasecmp( $c['email'], $this->email ) ) {
			throw new UserError( 'Non puoi eliminare il tuo stesso account mentre sei dentro.' );
		}
		$n = $this->billing->deleteCustomer( $id );
		$this->done( 'customer_delete', 'Cliente ' . $c['email'] . ' eliminato (' . $n . ' licenze).' . ( $this->billing->warning ? ' ' . $this->billing->warning : '' ), '/admin/clienti' );
	}

	private function savePlan( string $code ): void {
		Settings::savePlan( $this->db, $code, $_POST );
		$this->done( 'plan', 'Piano «' . $code . '» salvato.', '/admin/prezzi#' . $code );
	}

	private function emails(): void {
		$rows = array();
		foreach ( Emails::events() as $k => $def ) {
			$rows[ $k ] = $def + array( 'tpl' => Emails::template( $this->db, $k ), 'always' => in_array( $k, Emails::ALWAYS_ON, true ) );
		}
		$this->show( 'emails', 'email', array( 'rows' => $rows ) );
	}

	private function emailEditor( string $event ): void {
		$def = Emails::events()[ $event ] ?? null;
		if ( ! $def ) {
			$this->notFound();
			return;
		}
		$this->show( 'email_edit', 'email', array( 'event' => $event, 'def' => $def, 'tpl' => Emails::template( $this->db, $event ), 'always' => in_array( $event, Emails::ALWAYS_ON, true ), 'sample' => $this->sampleVars() ) );
	}

	private function emailAction( string $event ): void {
		if ( ! isset( Emails::events()[ $event ] ) ) {
			$this->notFound();
			return;
		}
		$to = '/admin/email/' . $event;
		if ( isset( $_POST['reset'] ) ) {
			Emails::reset( $this->db, $event );
			$this->done( 'email_reset', 'Modello «' . $event . '» ripristinato.', $to );
			return;
		}
		$subject = (string) ( $_POST['subject'] ?? '' );
		$body    = (string) ( $_POST['body'] ?? '' );
		$enabled = in_array( $event, Emails::ALWAYS_ON, true ) || ! empty( $_POST['enabled'] );
		Emails::save( $this->db, $event, $subject, $body, $enabled );
		$unknown = array_merge( Emails::unknownVars( $event, $subject ), Emails::unknownVars( $event, $body ) );
		$msg     = 'Modello salvato.';
		if ( isset( $_POST['test'] ) ) {
			Emails::sendTest( $this->db, $event, $this->email, $this->sampleVars() );
			$msg .= ' Email di prova inviata a ' . $this->email . '.';
		}
		if ( $unknown ) {
			$msg .= ' Attenzione: segnaposto sconosciuti per questo evento: {' . implode( '}, {', array_unique( $unknown ) ) . '}.';
		}
		$this->done( 'email', $msg, $to, $event, $enabled ? '' : ' (spenta)' );
	}

	private function sampleVars(): array {
		return array(
			'link' => rtrim( (string) Config::get( 'base_url' ), '/' ) . '/entra/ESEMPIO', 'dominio' => 'miaassociazione.it', 'vecchio_dominio' => 'vecchio-sito.it',
			'piano' => 'Pro Full', 'scadenza' => date( 'd/m/Y', time() + 30 * 86400 ), 'email' => $this->email,
			'account_url' => rtrim( (string) Config::get( 'base_url' ), '/' ) . '/account',
		);
	}

	// --- Utilità ------------------------------------------------------------------------------------------------------------

	private function sub( string $id ): array {
		$s = $this->billing->subscription( $id );
		if ( ! $s ) {
			http_response_code( 404 );
			throw new UserError( 'Licenza non trovata.' );
		}
		return $s;
	}

	private function show( string $view, string $nav, array $vars = array() ): void {
		View::render( 'admin/' . $view, $vars + array( 'nav' => $nav, 'flash' => $this->flash(), 'admin_email' => $this->email ) );
	}

	private function flash(): string {
		$f = (string) ( $_SESSION['flash'] ?? '' );
		unset( $_SESSION['flash'] );
		return $f;
	}

	/** Registra l'azione, mostra il messaggio alla pagina successiva e rimanda lì. */
	private function done( string $action, string $message, string $to, string $detail = '', string $extra = '' ): void {
		Audit::log( $this->db, $this->email, $action, trim( $message . ' ' . ( '' !== $detail && $detail !== $message ? '[' . $detail . ']' : '' ) . $extra ) );
		$_SESSION['flash'] = $message;
		header( 'Location: ' . $to, true, 303 );
	}

	private function notFound(): void {
		http_response_code( 404 );
		View::render( 'message', array( 'title' => 'Pagina non trovata', 'text' => 'Questa pagina non esiste.' ) );
	}
}
