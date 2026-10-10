<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Color;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Stile e script del front-end: caricati solo quando serve (quando una vista o un riquadro viene stampato). */
final class Assets {

	/**
	 * Colore del sito con quel nome («primary» o «secondary»), letto dal tema: prima il colore globale di Elementor (kit attivo), poi la
	 * tavolozza dei temi a blocchi. Stringa vuota se non si trova.
	 */
	public static function theme_color( string $slug ): string {
		try {
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
				$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
				if ( $kit ) {
					foreach ( (array) $kit->get_settings( 'system_colors' ) as $c ) {
						if ( is_array( $c ) && $slug === ( $c['_id'] ?? '' ) && '' !== Color::normalize( (string) ( $c['color'] ?? '' ) ) ) {
							return Color::normalize( (string) $c['color'] );
						}
					}
				}
			}
			if ( function_exists( 'wp_get_global_settings' ) ) {
				foreach ( (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) ) as $c ) {
					if ( is_array( $c ) && $slug === ( $c['slug'] ?? '' ) && '' !== Color::normalize( (string) ( $c['color'] ?? '' ) ) ) {
						return Color::normalize( (string) $c['color'] );
					}
				}
			}
		} catch ( \Throwable $e ) {
			return '';
		}
		return '';
	}

	/** Colore principale del sito (vedi {@see Assets::theme_color()}). */
	public static function theme_accent(): string {
		return self::theme_color( 'primary' );
	}

	/** Colore principale da usare: quello scelto nell'Aspetto, altrimenti quello del sito, altrimenti $fallback. */
	public static function accent( string $fallback = '' ): string {
		$own = Color::normalize( (string) Settings::get( 'accent_color' ) );
		if ( '' !== $own ) {
			return $own;
		}
		$theme = self::theme_accent();
		return '' !== $theme ? $theme : $fallback;
	}

	/** Colore secondario da usare: quello scelto, altrimenti quello del sito, altrimenti $fallback. */
	public static function secondary( string $fallback = '' ): string {
		$own = Color::normalize( (string) Settings::get( 'secondary_color' ) );
		if ( '' !== $own ) {
			return $own;
		}
		$theme = self::theme_color( 'secondary' );
		return '' !== $theme ? $theme : $fallback;
	}

	/** Logo del sito (quello di Elementor, poi quello del tema, poi l'icona del sito): indirizzo dell'immagine o vuoto. */
	public static function site_logo_url(): string {
		try {
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
				$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
				$img = $kit ? (array) $kit->get_settings( 'site_logo' ) : array();
				if ( ! empty( $img['url'] ) ) {
					return esc_url_raw( (string) $img['url'] );
				}
			}
			$id = (int) get_theme_mod( 'custom_logo' );
			if ( $id > 0 ) {
				$u = wp_get_attachment_image_url( $id, 'medium' );
				if ( $u ) {
					return (string) $u;
				}
			}
			$icon = function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url( 192 ) : '';
			return $icon;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/** Logo da usare: quello scelto nell'Aspetto (libreria media), altrimenti quello del sito. Indirizzo dell'immagine o vuoto. */
	public static function logo_url(): string {
		$id = (int) Settings::get( 'logo_id' );
		if ( $id > 0 ) {
			$u = wp_get_attachment_image_url( $id, 'medium' );
			if ( $u ) {
				return (string) $u;
			}
		}
		return self::site_logo_url();
	}

	/** CSS generato dai colori (scelti o presi dal sito). Stringa vuota = restano i colori di riserva del foglio di stile. */
	public static function inline_css(): string {
		$accent = self::accent();
		$sec    = self::secondary();
		if ( '' === $accent && '' === $sec ) {
			return '';
		}
		$css  = '.asemf{';
		$css .= '' !== $accent ? '--asemf-accent:' . $accent . ';--asemf-accent-text:' . Color::text_on( $accent ) . ';' : '';
		$css .= '' !== $sec ? '--asemf-accent-2:' . $sec . ';' : '';
		return $css . '}';
	}

	public static function enqueue(): void {
		if ( wp_style_is( 'asem-frontend', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'asem-frontend', ASEM_URL . 'assets/frontend.css', array(), \AssociazioneSemplice\Plugin::asset_version( 'frontend.css' ) );
		$css = self::inline_css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'asem-frontend', $css );
		}
		wp_enqueue_script( 'asem-frontend', ASEM_URL . 'assets/frontend.js', array(), \AssociazioneSemplice\Plugin::asset_version( 'frontend.js' ), true );
	}
}
