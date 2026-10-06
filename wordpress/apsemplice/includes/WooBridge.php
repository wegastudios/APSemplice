<?php
namespace ApSemplice;

defined( 'ABSPATH' ) || defined( 'APSE_TESTS' ) || exit;

/**
 * Compatibilità con WooCommerce: i pagamenti dei soci passano dal carrello e dal checkout del negozio.
 *
 *  1. il socio sceglie le voci da pagare (come con Stripe/PayPal) e viene mandato al checkout;
 *  2. nel carrello ogni voce è un prodotto collegato (WooLinks) con il prezzo calcolato dal server (quota del livello, sconto familiare, mensilità…);
 *  3. quando l'ordine risulta pagato (in lavorazione o completato) l'incasso entra in prima nota, una sola volta, come per gli altri gateway.
 *
 * Gli ordini che non contengono voci APSemplice non vengono toccati.
 */
final class WooBridge {

	public static function active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' ) && function_exists( 'WC' );
	}

	/** La compatibilità è attiva se WooCommerce c'è e il gateway scelto nelle impostazioni è WooCommerce. */
	public static function enabled(): bool {
		return self::active() && PaymentConfig::WOOCOMMERCE === (string) Settings::get( 'payment_provider' );
	}

	public static function register(): void {
		if ( ! self::active() ) {
			return;
		}
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'copy_to_order' ), 10, 3 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_failed' ) );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'on_failed' ) );
	}

	/** Prodotti scelti come collegamento: tutti semplici. @return array<int,string> id => nome (prezzo) */
	public static function products(): array {
		if ( ! self::active() || ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$out = array();
		foreach ( wc_get_products( array( 'limit' => 300, 'status' => 'publish', 'type' => 'simple', 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
			$out[ (int) $p->get_id() ] = $p->get_name() . ( '' !== (string) $p->get_price() ? ' (' . wp_strip_all_tags( html_entity_decode( wc_price( (float) $p->get_price() ) ) ) . ')' : '' );
		}
		return $out;
	}

	/** @throws \InvalidArgumentException */
	public static function assert_product( int $product_id ): void {
		if ( ! self::active() ) {
			throw new \InvalidArgumentException( 'WooCommerce non è attivo su questo sito.' );
		}
		$p = wc_get_product( $product_id );
		if ( ! $p || 'publish' !== $p->get_status() ) {
			throw new \InvalidArgumentException( 'Il prodotto scelto non esiste o non è pubblicato.' );
		}
		if ( ! $p->is_type( 'simple' ) ) {
			throw new \InvalidArgumentException( 'Per le quote serve un prodotto semplice (non variabile).' );
		}
	}

	// ---------- Carrello ----------

	/**
	 * Mette le voci da pagare nel carrello (sostituendo quelle APSemplice già presenti) e restituisce l'indirizzo del checkout.
	 *
	 * @param array[] $items voci da pagare (PaymentItems), con importi calcolati dal server
	 * @throws \InvalidArgumentException
	 */
	public static function checkout_url( array $items, string $public_id ): string {
		if ( ! self::active() ) {
			throw new \InvalidArgumentException( 'Il negozio non è disponibile al momento.' );
		}
		$lines   = array();
		$missing = array();
		foreach ( $items as $i ) {
			$pid = WooLinks::product_for_item( $i );
			if ( $pid <= 0 || ! wc_get_product( $pid ) ) {
				$missing[] = PaymentItems::line_name( $i );
				continue;
			}
			$lines[] = array( $pid, $i );
		}
		if ( $missing ) {
			throw new \InvalidArgumentException( 'Queste voci non sono collegate a un prodotto del negozio: ' . implode( ', ', $missing ) . '. Contatta la segreteria.' );
		}
		if ( function_exists( 'wc_load_cart' ) && null === WC()->cart ) {
			wc_load_cart();
		}
		$cart = WC()->cart;
		if ( ! $cart ) {
			throw new \InvalidArgumentException( 'Il carrello del negozio non è disponibile al momento.' );
		}
		foreach ( $cart->get_cart() as $key => $v ) {
			if ( ! empty( $v['apse_pay'] ) ) {
				$cart->remove_cart_item( $key ); // voci di un pagamento precedente non concluso
			}
		}
		foreach ( $lines as $l ) {
			list( $pid, $i ) = $l;
			$added = $cart->add_to_cart( $pid, 1, 0, array(), array( 'apse_pay' => $public_id, 'apse_key' => (string) $i['key'], 'apse_cents' => (int) $i['amount_cents'], 'apse_label' => PaymentItems::line_name( $i ) ) );
			if ( false === $added ) {
				throw new \InvalidArgumentException( 'Non è stato possibile aggiungere «' . PaymentItems::line_name( $i ) . '» al carrello.' );
			}
		}
		return wc_get_checkout_url();
	}

	/** Il prezzo delle voci APSemplice è quello calcolato dal server, non quello del listino. */
	public static function apply_prices( $cart ): void {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $v ) {
			if ( isset( $v['apse_cents'] ) && isset( $v['data'] ) && is_object( $v['data'] ) ) {
				$v['data']->set_price( ( (int) $v['apse_cents'] ) / 100 );
			}
		}
	}

	public static function item_data( $data, $cart_item ) {
		if ( is_array( $data ) && ! empty( $cart_item['apse_label'] ) ) {
			$data[] = array( 'key' => 'Voce', 'value' => (string) $cart_item['apse_label'] );
		}
		return $data;
	}

	/** Dal carrello all'ordine: riferimenti al pagamento (nascosti, con il trattino basso). */
	public static function copy_to_order( $item, $cart_item_key, $values ): void {
		if ( empty( $values['apse_pay'] ) ) {
			return;
		}
		$item->add_meta_data( '_apse_pay', (string) $values['apse_pay'], true );
		$item->add_meta_data( '_apse_key', (string) ( $values['apse_key'] ?? '' ), true );
	}

	// ---------- Ordini ----------

	/** Importo (centesimi) pagato per le voci APSemplice di un ordine, per pagamento. @return array<string,int> */
	public static function paid_by_payment( $order ): array {
		$out = array();
		foreach ( $order->get_items() as $it ) {
			$pid = (string) $it->get_meta( '_apse_pay' );
			if ( '' === $pid ) {
				continue;
			}
			$out[ $pid ] = ( $out[ $pid ] ?? 0 ) + (int) round( ( (float) $it->get_total() + (float) $it->get_total_tax() ) * 100 );
		}
		return $out;
	}

	/** Ordine pagato: l'incasso entra in prima nota (una sola volta per pagamento). */
	public static function on_paid( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( self::paid_by_payment( $order ) as $public => $cents ) {
			Plugin::payments()->finalize_external( (string) $public, $cents, 'woo-' . (int) $order_id );
		}
	}

	/** Ordine annullato o fallito: il pagamento in attesa si chiude senza incasso. */
	public static function on_failed( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( array_keys( self::paid_by_payment( $order ) ) as $public ) {
			Plugin::payments()->cancel_external( (string) $public );
		}
	}
}
