<?php
namespace AssociazioneSemplice\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Widget Elementor ("AssociazioneSemplice" e "Contenuto riservato"). Le classi dei widget si caricano solo quando
 * Elementor le richiede, quindi senza Elementor non succede nulla.
 */
final class ElementorSupport {

	public static function register(): void {
		add_action(
			'elementor/elements/categories_registered',
			function ( $elements_manager ) {
				$elements_manager->add_category( 'associazionesemplice', array( 'title' => 'AssociazioneSemplice', 'icon' => 'eicon-user-circle-o' ) );
			}
		);
		add_action(
			'elementor/widgets/register',
			function ( $widgets_manager ) {
				try {
					$widgets_manager->register( new Elementor\ViewWidget() );
					$widgets_manager->register( new Elementor\ReservedWidget() );
				} catch ( \Throwable $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( 'AssociazioneSemplice: widget Elementor non registrati: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
					}
				}
			}
		);
	}
}
