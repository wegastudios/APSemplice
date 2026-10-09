<?php
namespace ApSemplice\Frontend;

use ApSemplice\Color;
use ApSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Stile e script del front-end: caricati solo quando serve (quando una vista o un riquadro viene stampato). */
final class Assets {

	/**
	 * Colore principale del sito, letto dal tema: prima quello di Elementor (colore «Primario» del kit), poi quello dei temi a blocchi
	 * (tavolozza «primary»). Stringa vuota se non si trova.
	 */
	public static function theme_accent(): string {
		try {
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
				$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
				if ( $kit ) {
					foreach ( (array) $kit->get_settings( 'system_colors' ) as $c ) {
						if ( is_array( $c ) && 'primary' === ( $c['_id'] ?? '' ) && '' !== Color::normalize( (string) ( $c['color'] ?? '' ) ) ) {
							return Color::normalize( (string) $c['color'] );
						}
					}
				}
			}
			if ( function_exists( 'wp_get_global_settings' ) ) {
				foreach ( (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) ) as $c ) {
					if ( is_array( $c ) && 'primary' === ( $c['slug'] ?? '' ) && '' !== Color::normalize( (string) ( $c['color'] ?? '' ) ) ) {
						return Color::normalize( (string) $c['color'] );
					}
				}
			}
		} catch ( \Throwable $e ) {
			return '';
		}
		return '';
	}

	/** Colore d'accento da usare: quello scelto nelle impostazioni, altrimenti quello del tema, altrimenti $fallback. */
	public static function accent( string $fallback = '' ): string {
		$own = Color::normalize( (string) Settings::get( 'accent_color' ) );
		if ( '' !== $own ) {
			return $own;
		}
		$theme = self::theme_accent();
		return '' !== $theme ? $theme : $fallback;
	}

	/** CSS generato dal colore d'accento (scelto o preso dal tema). Stringa vuota = resta il colore di riserva del foglio di stile. */
	public static function inline_css(): string {
		$accent = self::accent();
		if ( '' === $accent ) {
			return '';
		}
		return '.apsf{--apsf-accent:' . $accent . ';--apsf-accent-text:' . Color::text_on( $accent ) . ';}';
	}

	public static function enqueue(): void {
		if ( wp_style_is( 'apse-frontend', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'apse-frontend', APSE_URL . 'assets/frontend.css', array(), \ApSemplice\Plugin::asset_version( 'frontend.css' ) );
		$css = self::inline_css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'apse-frontend', $css );
		}
		wp_enqueue_script( 'apse-frontend', APSE_URL . 'assets/frontend.js', array(), \ApSemplice\Plugin::asset_version( 'frontend.js' ), true );
	}
}
