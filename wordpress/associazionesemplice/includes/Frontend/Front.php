<?php
namespace AssociazioneSemplice\Frontend;

defined( 'ABSPATH' ) || exit;

/** Avvio del front-end: shortcode, contenuti riservati, azioni dei soci, blocchi Gutenberg, widget Elementor. */
final class Front {

	public static function init(): void {
		Shortcodes::register();
		Restrict::register();
		Actions::register();
		if ( \AssociazioneSemplice\Edition::installed( 'payments' ) ) {
			PayReturn::register();
		}
		CardVerify::register();
		TicketVerify::register();
		Activation::register();
		FirstAccess::register();
		\AssociazioneSemplice\Calendar::register();
		Blocks::register();
		ElementorSupport::register();
	}
}
