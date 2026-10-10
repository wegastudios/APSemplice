<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Money;
use AssociazioneSemplice\PaymentCalc;

defined( 'ABSPATH' ) || exit;

/** Piccoli helper per scrivere le pagine di amministrazione. */
final class Ui {

	const MONTHS = array( 1 => 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre' );

	public static function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	public static function get_int( string $key, int $default = 0 ): int {
		return isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default; // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function get_str( string $key, string $default = '' ): string {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/** Link per scrivere a una persona: email (mailto) e WhatsApp (se ha il cellulare). */
	public static function contact_links( array $p ): string {
		$out = array();
		if ( ! empty( $p['email'] ) ) {
			$out[] = '<a href="' . esc_url( 'mailto:' . $p['email'] ) . '" title="' . esc_attr( (string) $p['email'] ) . '">✉ Email</a>';
		}
		$wa = ! empty( $p['phone'] ) ? \AssociazioneSemplice\Phone::whatsapp( (string) $p['phone'] ) : '';
		if ( '' !== $wa ) {
			$out[] = '<a href="' . esc_url( 'https://wa.me/' . $wa ) . '" target="_blank" rel="noopener" title="' . esc_attr( (string) $p['phone'] ) . '">💬 WhatsApp</a>';
		}
		return implode( ' · ', $out );
	}

	public static function header( string $title, string $actions_html = '' ): void {
		\AssociazioneSemplice\Texts::start_admin_buffer(); // testi personalizzati anche in amministrazione
		echo '<div class="wrap asem"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1> ' . $actions_html . '<hr class="wp-header-end">'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo Admin::tabs( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.EscapeOutput
		self::notices();
	}

	public static function footer(): void {
		echo '</div>';
	}

	public static function notices(): void {
		$msg = \AssociazioneSemplice\Flash::read( 'asem' ); // solo messaggi scritti dal sito (firmati)
		$ok  = $msg['ok'];
		$err = $msg['err'];
		if ( '' !== $ok ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $ok ) . '</p></div>';
		}
		if ( '' !== $err ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $err ) . '</p></div>';
		}
	}

	/** Apre un form che invia a admin-post.php con nonce e URL di ritorno. */
	public static function form_open( string $action, string $back_url, bool $multipart = false, string $class = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="_back" value="' . esc_url( $back_url ) . '">';
		wp_nonce_field( $action );
	}

	/**
	 * Pulsante che invia subito un'azione dopo una sola conferma del browser (per le operazioni che non toccano la prima nota).
	 *
	 * @param array<string,scalar> $fields campi nascosti
	 */
	public static function confirm_button( string $action, string $back_url, array $fields, string $label, string $confirm_text, string $style = '' ): string {
		$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm_text ) ) . ');">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="_back" value="' . esc_url( $back_url ) . '">'
			. wp_nonce_field( $action, '_wpnonce', true, false );
		foreach ( $fields as $k => $v ) {
			$html .= '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		return $html . '<button class="button button-small"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
	}

	public static function form_close(): void {
		echo '</form>';
	}

	/**
	 * Riga del modulo per l'IVA (solo se l'ente applica l'IVA): aliquota e se l'importo scritto è IVA compresa o esclusa.
	 *
	 * @param ?int   $rate       aliquota attuale (null = fuori campo IVA)
	 * @param string $mode       importo indicato: 'incl' o 'escl'
	 * @param string $what       a cosa si riferisce («Contributo», «Importo»…)
	 */
	public static function vat_row( ?int $rate, string $mode, string $what = 'Gli importi', bool $auto = false ): string {
		return \AssociazioneSemplice\Edition::has( 'vat' ) ? VatFields::row( $rate, $mode, $what, $auto ) : ''; // senza la funzione «IVA» i moduli non mostrano nulla di fiscale
	}

	/** Il modulo ha lasciato l'aliquota su «Automatica»? */
	public static function vat_is_auto( array $p ): bool {
		return 'auto' === (string) ( $p['vat_rate'] ?? '' );
	}

	/** Aliquota e modo letti da un modulo con {@see Ui::vat_row()}: [aliquota|null, 'incl'|'escl']. */
	public static function vat_input( array $p ): array {
		return \AssociazioneSemplice\Edition::has( 'vat' ) ? VatFields::input( $p ) : array( null, \AssociazioneSemplice\Fiscal::INCLUDED );
	}

	public static function hidden( string $name, $value ): string {
		return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
	}

	/** <option> per un array valore => etichetta. */
	public static function options( array $map, $selected = null, ?string $empty = null ): string {
		$html = '';
		if ( null !== $empty ) {
			$html .= '<option value="">' . esc_html( $empty ) . '</option>';
		}
		foreach ( $map as $v => $label ) {
			$html .= '<option value="' . esc_attr( (string) $v ) . '"' . selected( (string) $selected, (string) $v, false ) . '>' . esc_html( (string) $label ) . '</option>';
		}
		return $html;
	}

	public static function money( int $cents ): string {
		$cls = $cents < 0 ? ' class="asem-neg"' : '';
		return '<span' . $cls . '>' . esc_html( Money::format( $cents ) ) . '</span>';
	}

	public static function date( ?string $ymd ): string {
		return $ymd ? esc_html( ( new \DateTimeImmutable( $ymd ) )->format( 'd/m/Y' ) ) : '—';
	}

	public static function month( string $ym ): string {
		return self::MONTHS[ (int) substr( $ym, 5, 2 ) ] . ' ' . substr( $ym, 0, 4 );
	}

	/** @param string[] $months "YYYY-MM" */
	public static function month_options( array $months, ?string $selected ): string {
		$map = array();
		foreach ( $months as $m ) {
			$map[ $m ] = self::month( $m );
		}
		return self::options( $map, $selected );
	}

	public static function person_label( array $p ): string {
		return ( ! empty( $p['card_number'] ) ? 'n.' . $p['card_number'] . ' · ' : '' ) . trim( $p['first_name'] . ' ' . $p['last_name'] );
	}

	public static function pay_status( array $summary ): string {
		$up = ! empty( $summary['upcoming'] ) ? ' <span class="description">· ' . esc_html( self::month( $summary['upcoming']['month'] ) ) . ' dovuto dalla lezione del ' . esc_html( self::date( $summary['upcoming']['date'] ) ) . '</span>' : '';
		if ( $summary['balance'] < 0 ) {
			return '<strong class="asem-neg">Da versare ' . esc_html( Money::format( -$summary['balance'] ) ) . '</strong>' . $up;
		}
		if ( $summary['balance'] > 0 ) {
			return '<strong class="asem-ok">In regola (credito ' . esc_html( Money::format( $summary['balance'] ) ) . ')</strong>' . $up;
		}
		return '<strong class="asem-ok">In regola</strong>' . $up;
	}

	public static function months_table( array $summary ): string {
		if ( ! $summary['months'] ) {
			return '<p class="description">Nessuna mensilità dovuta finora.</p>';
		}
		$labels = array( PaymentCalc::PAID => array( 'pagato', 'asem-ok' ), PaymentCalc::PARTIAL => array( 'parziale', 'asem-warn' ), PaymentCalc::UNPAID => array( 'da pagare', 'asem-neg' ), PaymentCalc::ADVANCE => array( 'pagato in anticipo', '' ) );
		$html   = '<table class="widefat striped asem-months"><thead><tr><th>Mese</th><th>Versato</th><th>Dovuto</th><th>Stato</th></tr></thead><tbody>';
		foreach ( $summary['months'] as $m ) {
			$l     = $labels[ $m['state'] ];
			$html .= '<tr><td>' . esc_html( self::month( $m['month'] ) ) . '</td><td>' . esc_html( Money::format( $m['paid'] ) ) . '</td><td>' . esc_html( Money::format( $m['due'] ) )
				. '</td><td class="' . esc_attr( $l[1] ) . '">' . esc_html( $l[0] ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	/** Campo di scelta con filtro di testo, per elenchi lunghi di persone. */
	public static function person_select( string $name, array $people, $selected = null, ?string $empty = null, string $id = '' ): string {
		$map = array();
		foreach ( $people as $p ) {
			$map[ $p['id'] ] = self::person_label( $p );
		}
		$id = $id ?: 'asem-sel-' . sanitize_key( $name );
		return '<span class="asem-filter-select"><input type="search" class="asem-filter" data-target="#' . esc_attr( $id ) . '" placeholder="Filtra…" autocomplete="off"> '
			. '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '">' . self::options( $map, $selected, $empty ) . '</select></span>';
	}

	/** Stato del contributo di una prenotazione. */
	public static function booking_state( array $b ): string {
		switch ( $b['state'] ) {
			case 'free':
				return '<span class="asem-ok">gratuito</span>';
			case 'paid':
				return '<span class="asem-ok">pagato</span>';
			case 'partial':
				return '<span class="asem-warn">parziale · resta ' . esc_html( Money::format( (int) $b['remaining'] ) ) . '</span>';
			default:
				return '<span class="asem-neg">da pagare ' . esc_html( Money::format( (int) $b['remaining'] ) ) . '</span>';
		}
	}

	public static function redirect( string $url, string $ok = '', string $err = '' ): void {
		wp_safe_redirect( \AssociazioneSemplice\Flash::url( $url, 'asem', $ok, $err ) );
		exit;
	}
}
