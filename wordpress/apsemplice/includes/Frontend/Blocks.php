<?php
namespace ApSemplice\Frontend;

use ApSemplice\Plugin;
use ApSemplice\Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * Blocchi Gutenberg (dinamici, disegnati dal server):
 *  - apsemplice/vista      una vista dell'area soci o delle pagine pubbliche (scelta dalla barra laterale);
 *  - apsemplice/riservato  contenitore: i blocchi dentro li vedono solo gli aventi diritto.
 */
final class Blocks {

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
	}

	public static function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'apse-blocks',
			APSE_URL . 'assets/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ),
			APSE_VERSION,
			true
		);
		$activities = array();
		foreach ( Plugin::activities()->all_for_select() as $a ) {
			$activities[] = array( 'value' => (int) $a['id'], 'label' => $a['name'] . ' (' . $a['social_year'] . ')' );
		}
		wp_add_inline_script(
			'apse-blocks',
			'window.APSE_BLOCKS = ' . wp_json_encode( array( 'views' => Shortcodes::VIEWS, 'rules' => Visibility::labels(), 'activities' => $activities ) ) . ';',
			'before'
		);

		register_block_type(
			'apsemplice/vista',
			array(
				'api_version'     => 2,
				'title'           => 'APSemplice',
				'editor_script'   => 'apse-blocks',
				'attributes'      => array(
					'vista'   => array( 'type' => 'string', 'default' => 'area_soci' ),
					'sezioni' => array( 'type' => 'string', 'default' => '' ),
					'anno'    => array( 'type' => 'string', 'default' => '' ),
					'tipo'    => array( 'type' => 'string', 'default' => '' ),
					'id'      => array( 'type' => 'string', 'default' => '' ),
					'date'    => array( 'type' => 'string', 'default' => '' ),
					'limite'  => array( 'type' => 'string', 'default' => '' ),
				),
				'render_callback' => array( __CLASS__, 'render_view' ),
			)
		);
		register_block_type(
			'apsemplice/riservato',
			array(
				'api_version'     => 2,
				'title'           => 'Contenuto riservato',
				'editor_script'   => 'apse-blocks',
				'attributes'      => array(
					'accesso'   => array( 'type' => 'string', 'default' => Visibility::MEMBERS ),
					'attivita'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'default' => array() ),
					'messaggio' => array( 'type' => 'string', 'default' => '' ),
				),
				'render_callback' => array( __CLASS__, 'render_reserved' ),
			)
		);
	}

	public static function render_view( $attrs ): string {
		$view = isset( $attrs['vista'] ) && isset( Shortcodes::VIEWS[ $attrs['vista'] ] ) ? $attrs['vista'] : 'area_soci';
		unset( $attrs['vista'] );
		return Shortcodes::render_view( $view, array_filter( (array) $attrs, 'strlen' ) );
	}

	public static function render_reserved( $attrs, $content = '' ): string {
		$rule = isset( $attrs['accesso'] ) && Visibility::is_valid( (string) $attrs['accesso'] ) ? (string) $attrs['accesso'] : Visibility::MEMBERS;
		$ids  = array_values( array_filter( array_map( 'intval', (array) ( $attrs['attivita'] ?? array() ) ) ) );
		return Restrict::render_reserved( $rule, $ids, (string) $content, (string) ( $attrs['messaggio'] ?? '' ) );
	}
}
