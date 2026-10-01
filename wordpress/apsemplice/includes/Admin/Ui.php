<?php
namespace ApSemplice\Admin;

use ApSemplice\Money;
use ApSemplice\PaymentCalc;

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

	public static function header( string $title, string $actions_html = '' ): void {
		echo '<div class="wrap aps"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1> ' . $actions_html . '<hr class="wp-header-end">'; // phpcs:ignore WordPress.Security.EscapeOutput
		self::notices();
	}

	public static function footer(): void {
		echo '</div>';
	}

	public static function notices(): void {
		$ok  = self::get_str( 'aps_ok' );
		$err = self::get_str( 'aps_err' );
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

	public static function form_close(): void {
		echo '</form>';
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
		$cls = $cents < 0 ? ' class="aps-neg"' : '';
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
		if ( $summary['balance'] < 0 ) {
			return '<strong class="aps-neg">Da versare ' . esc_html( Money::format( -$summary['balance'] ) ) . '</strong>';
		}
		if ( $summary['balance'] > 0 ) {
			return '<strong class="aps-ok">In regola (credito ' . esc_html( Money::format( $summary['balance'] ) ) . ')</strong>';
		}
		return '<strong class="aps-ok">In regola</strong>';
	}

	public static function months_table( array $summary ): string {
		if ( ! $summary['months'] ) {
			return '<p class="description">Nessuna mensilità dovuta finora.</p>';
		}
		$labels = array( PaymentCalc::PAID => array( 'pagato', 'aps-ok' ), PaymentCalc::PARTIAL => array( 'parziale', 'aps-warn' ), PaymentCalc::UNPAID => array( 'da pagare', 'aps-neg' ), PaymentCalc::ADVANCE => array( 'versato fuori periodo', '' ) );
		$html   = '<table class="widefat striped aps-months"><thead><tr><th>Mese</th><th>Versato</th><th>Dovuto</th><th>Stato</th></tr></thead><tbody>';
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
		$id = $id ?: 'aps-sel-' . sanitize_key( $name );
		return '<span class="aps-filter-select"><input type="search" class="aps-filter" data-target="#' . esc_attr( $id ) . '" placeholder="Filtra…" autocomplete="off"> '
			. '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '">' . self::options( $map, $selected, $empty ) . '</select></span>';
	}

	public static function redirect( string $url, string $ok = '', string $err = '' ): void {
		$args = array();
		if ( '' !== $ok ) {
			$args['aps_ok'] = $ok;
		}
		if ( '' !== $err ) {
			$args['aps_err'] = $err;
		}
		wp_safe_redirect( add_query_arg( $args, remove_query_arg( array( 'aps_ok', 'aps_err' ), $url ) ) );
		exit;
	}
}
