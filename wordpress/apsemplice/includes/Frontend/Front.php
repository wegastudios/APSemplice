<?php
namespace ApSemplice\Frontend;

defined( 'ABSPATH' ) || exit;

/** Avvio del front-end: shortcode, contenuti riservati, azioni dei soci, blocchi Gutenberg, widget Elementor. */
final class Front {

	public static function init(): void {
		Shortcodes::register();
		Restrict::register();
		Actions::register();
		PayReturn::register();
		CardVerify::register();
		Blocks::register();
		ElementorSupport::register();
	}
}
