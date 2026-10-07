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

	/** Le quote sono in euro: se il negozio usa un'altra valuta gli importi non sarebbero quelli giusti. */
	public static function currency_is_euro(): bool {
		return function_exists( 'get_woocommerce_currency' ) && 'EUR' === strtoupper( (string) get_woocommerce_currency() );
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

	/**
	 * Crea un prodotto semplice, virtuale e non in vetrina (serve solo come voce del carrello: il prezzo vero lo calcola il plugin).
	 *
	 * @return int id del prodotto
	 * @throws \InvalidArgumentException
	 */
	public static function create_product( string $name, int $price_cents ): int {
		if ( ! self::active() || ! class_exists( 'WC_Product_Simple' ) ) {
			throw new \InvalidArgumentException( 'WooCommerce non è attivo su questo sito.' );
		}
		$p = new \WC_Product_Simple();
		$p->set_name( mb_substr( sanitize_text_field( $name ), 0, 120 ) );
		$p->set_status( 'publish' );
		$p->set_virtual( true );
		$p->set_catalog_visibility( 'hidden' );
		$p->set_regular_price( number_format( max( 0, $price_cents ) / 100, 2, '.', '' ) );
		$id = (int) $p->save();
		if ( $id <= 0 ) {
			throw new \InvalidArgumentException( 'Non è stato possibile creare il prodotto.' );
		}
		return $id;
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
		if ( ! self::currency_is_euro() ) {
			throw new \InvalidArgumentException( 'Il negozio non usa l\'euro: i pagamenti delle quote non si possono fare da qui. Contatta la segreteria.' );
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
		// Contrassegno: l'ordine nasce "in lavorazione" senza che sia arrivato un euro. Si registra solo quando viene completato (dopo l'incasso alla consegna).
		if ( 'cod' === (string) $order->get_payment_method() && ! $order->has_status( 'completed' ) ) {
			return;
		}
		$euro = 'EUR' === strtoupper( (string) $order->get_currency() );
		foreach ( self::paid_by_payment( $order ) as $public => $cents ) {
			Plugin::payments()->finalize_external( (string) $public, $euro ? $cents : 0, 'woo-' . (int) $order_id ); // altra valuta: da controllare, non si conta come euro
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
