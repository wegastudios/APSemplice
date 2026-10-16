<?php
namespace Portale;

/** Webhook di Stripe e PayPal: verifica la firma, ignora le consegne ripetute, aggiorna abbonamento e licenza. */
final class Webhooks {

	/** @var Db */
	private $db;
	/** @var Billing */
	private $billing;

	public function __construct( Db $db, Billing $billing ) {
		$this->db      = $db;
		$this->billing = $billing;
	}

	/** @return int codice HTTP da restituire (un errore fa riprovare il provider) */
	public function stripe( string $payload, string $signature ): int {
		if ( ! Stripe::verify( $payload, $signature, (string) Config::get( 'stripe.webhook_secret', '' ) ) ) {
			return 400;
		}
		$ev = json_decode( $payload, true );
		if ( ! is_array( $ev ) || empty( $ev['id'] ) || empty( $ev['type'] ) ) {
			return 400;
		}
		return $this->once( 'stripe', (string) $ev['id'], function () use ( $ev ) {
			$o = $ev['data']['object'] ?? array();
			switch ( $ev['type'] ) {
				case 'checkout.session.completed':
					if ( empty( $o['client_reference_id'] ) ) {
						break;
					}
					if ( 'subscription' === ( $o['mode'] ?? '' ) ) {
						$this->billing->completePayment( (string) $o['client_reference_id'], (string) ( $o['subscription'] ?? '' ), (string) ( $o['customer'] ?? '' ) ?: null );
					} elseif ( 'payment' === ( $o['mode'] ?? '' ) && 'paid' === ( $o['payment_status'] ?? '' ) ) {
						$this->billing->completePayment( (string) $o['client_reference_id'], (string) ( $o['payment_intent'] ?? '' ) ?: null );
					}
					break;
				case 'invoice.paid':
					// Il primo pagamento è già coperto dall'attivazione; i successivi sono rinnovi.
					if ( 'subscription_cycle' === ( $o['billing_reason'] ?? '' ) ) {
						$sub = $this->billing->byProviderRef( 'stripe', (string) ( $o['subscription'] ?? '' ) );
						if ( $sub ) {
							$this->billing->renew( $sub['id'] );
						}
					}
					break;
				case 'invoice.payment_failed':
					$sub = $this->billing->byProviderRef( 'stripe', (string) ( $o['subscription'] ?? '' ) );
					if ( $sub && 'canceled' !== $sub['status'] ) {
						$this->billing->setStatus( $sub['id'], 'past_due' );
						$this->billing->notify( 'payment_failed', $sub );
					}
					break;
				case 'customer.subscription.updated':
					$sub = $this->billing->byProviderRef( 'stripe', (string) ( $o['id'] ?? '' ) );
					if ( $sub ) {
						$this->billing->setStatus( $sub['id'], $sub['status'], ! empty( $o['cancel_at_period_end'] ) );
					}
					break;
				case 'customer.subscription.deleted':
					$sub = $this->billing->byProviderRef( 'stripe', (string) ( $o['id'] ?? '' ) );
					if ( $sub ) {
						$this->billing->setStatus( $sub['id'], 'canceled' );
						// Se il cliente aveva già disdetto ha già ricevuto l'email: avviso solo le chiusure decise dal provider.
						if ( ! $sub['cancel_at_period_end'] ) {
							$this->billing->notify( 'canceled', $sub );
						}
					}
					break;
			}
		} );
	}

	/** @param array $headers intestazioni in minuscolo */
	public function paypal( string $payload, array $headers ): int {
		if ( ! PayPal::verify( $payload, $headers ) ) {
			return 400;
		}
		$ev = json_decode( $payload, true );
		if ( ! is_array( $ev ) || empty( $ev['id'] ) || empty( $ev['event_type'] ) ) {
			return 400;
		}
		return $this->once( 'paypal', (string) $ev['id'], function () use ( $ev ) {
			$r = $ev['resource'] ?? array();
			switch ( $ev['event_type'] ) {
				case 'BILLING.SUBSCRIPTION.ACTIVATED':
					if ( ! empty( $r['custom_id'] ) ) {
						$this->billing->completePayment( (string) $r['custom_id'], (string) ( $r['id'] ?? '' ) );
					}
					break;
				case 'PAYMENT.CAPTURE.COMPLETED':
					// Pagamento singolo: normalmente già registrato al ritorno del cliente; qui è la rete di sicurezza.
					if ( ! empty( $r['custom_id'] ) ) {
						$this->billing->completePayment( (string) $r['custom_id'] );
					}
					break;
				case 'PAYMENT.SALE.COMPLETED':
					$sub = $this->billing->byProviderRef( 'paypal', (string) ( $r['billing_agreement_id'] ?? '' ) );
					if ( $sub ) {
						$this->billing->renew( $sub['id'], Settings::limit( 'renew_window' ) );
					}
					break;
				case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
					$sub = $this->billing->byProviderRef( 'paypal', (string) ( $r['id'] ?? '' ) );
					if ( $sub && 'canceled' !== $sub['status'] ) {
						$this->billing->setStatus( $sub['id'], 'past_due' );
						$this->billing->notify( 'payment_failed', $sub );
					}
					break;
				case 'BILLING.SUBSCRIPTION.CANCELLED':
				case 'BILLING.SUBSCRIPTION.EXPIRED':
				case 'BILLING.SUBSCRIPTION.SUSPENDED':
					$sub = $this->billing->byProviderRef( 'paypal', (string) ( $r['id'] ?? '' ) );
					if ( $sub ) {
						$this->billing->setStatus( $sub['id'], 'canceled' );
						if ( ! $sub['cancel_at_period_end'] ) {
							$this->billing->notify( 'canceled', $sub );
						}
					}
					break;
			}
		} );
	}

	private function once( string $provider, string $eventId, callable $work ): int {
		if ( ! $this->db->claimEvent( $provider, $eventId ) ) {
			return 200;
		}
		try {
			$work();
		} catch ( \Throwable $e ) {
			$this->db->releaseEvent( $provider, $eventId );
			error_log( '[portale] webhook ' . $provider . ' ' . $eventId . ': ' . $e->getMessage() );
			return 500;
		}
		return 200;
	}
}
