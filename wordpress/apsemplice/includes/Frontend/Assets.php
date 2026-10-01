<?php
namespace ApSemplice\Frontend;

defined( 'ABSPATH' ) || exit;

/** Stile e script del front-end: caricati solo quando serve (quando una vista o un riquadro viene stampato). */
final class Assets {

	public static function enqueue(): void {
		if ( wp_style_is( 'aps-frontend', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'aps-frontend', APS_URL . 'assets/frontend.css', array(), APS_VERSION );
		wp_enqueue_script( 'aps-frontend', APS_URL . 'assets/frontend.js', array(), APS_VERSION, true );
	}
}
