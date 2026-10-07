<?php
namespace ApSemplice\Frontend\Elementor;

use ApSemplice\ActivityKind;
use ApSemplice\Frontend\Shortcodes;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/** Widget Elementor: sceglie una vista di APSemplice (area soci, tessera, attività, prossimi eventi…). */
class ViewWidget extends Widget_Base {

	public function get_name() {
		return 'apsemplice_view';
	}

	public function get_title() {
		return 'APSemplice';
	}

	public function get_icon() {
		return 'eicon-user-circle-o';
	}

	public function get_categories() {
		return array( 'apsemplice' );
	}

	public function get_keywords() {
		return array( 'apsemplice', 'soci', 'tessera', 'attività', 'eventi', 'area riservata' );
	}

	protected function register_controls() {
		$this->start_controls_section( 'content_section', array( 'label' => 'Vista', 'tab' => Controls_Manager::TAB_CONTENT ) );
		$this->add_control( 'view', array( 'label' => 'Cosa mostrare', 'type' => Controls_Manager::SELECT, 'options' => \ApSemplice\Areas::options(), 'default' => 'area_soci' ) );
		$this->add_control( 'anno', array( 'label' => 'Anno sociale (es. 2025/2026; vuoto = in corso)', 'type' => Controls_Manager::TEXT, 'condition' => array( 'view' => 'attivita' ) ) );
		$this->add_control(
			'tipo',
			array(
				'label'     => 'Tipo di attività',
				'type'      => Controls_Manager::SELECT,
				'options'   => array_merge( array( '' => 'Tutte' ), ActivityKind::labels() ),
				'default'   => '',
				'condition' => array( 'view' => 'attivita' ),
			)
		);
		$this->add_control( 'id', array( 'label' => 'Solo questa attività (ID, facoltativo)', 'type' => Controls_Manager::NUMBER, 'condition' => array( 'view' => 'attivita' ) ) );
		$this->add_control( 'date', array( 'label' => 'Date da mostrare per attività', 'type' => Controls_Manager::NUMBER, 'default' => 5, 'condition' => array( 'view' => 'attivita' ) ) );
		$this->add_control( 'limite', array( 'label' => 'Numero di eventi', 'type' => Controls_Manager::NUMBER, 'default' => 5, 'condition' => array( 'view' => 'prossimi_eventi' ) ) );
		$this->end_controls_section();
	}

	protected function render() {
		$s    = $this->get_settings_for_display();
		$atts = array();
		foreach ( array( 'anno', 'tipo', 'id', 'date', 'limite' ) as $k ) {
			if ( isset( $s[ $k ] ) && '' !== (string) $s[ $k ] ) {
				$atts[ $k ] = (string) $s[ $k ];
			}
		}
		echo Shortcodes::render_view( (string) ( $s['view'] ?? 'area_soci' ), $atts ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
