<?php
namespace ApSemplice\Frontend\Elementor;

use ApSemplice\Frontend\Restrict;
use ApSemplice\Plugin;
use ApSemplice\Visibility;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/** Widget Elementor: un blocco di testo visibile solo a soci, volontari o iscritti a specifiche attività. */
class ReservedWidget extends Widget_Base {

	public function get_name() {
		return 'apsemplice_reserved';
	}

	public function get_title() {
		return 'Contenuto riservato (APSemplice)';
	}

	public function get_icon() {
		return 'eicon-lock-user';
	}

	public function get_categories() {
		return array( 'apsemplice' );
	}

	public function get_keywords() {
		return array( 'apsemplice', 'riservato', 'soci', 'privato' );
	}

	protected function register_controls() {
		$activities = array();
		foreach ( Plugin::activities()->all_for_select() as $a ) {
			$activities[ (int) $a['id'] ] = $a['name'] . ' (' . $a['social_year'] . ')';
		}
		$rules = Visibility::labels();
		unset( $rules[ Visibility::PUBLIC_ ] );

		$this->start_controls_section( 'content_section', array( 'label' => 'Contenuto riservato', 'tab' => Controls_Manager::TAB_CONTENT ) );
		$this->add_control( 'rule', array( 'label' => 'Chi può vederlo', 'type' => Controls_Manager::SELECT, 'options' => $rules, 'default' => Visibility::MEMBERS ) );
		$this->add_control(
			'activities',
			array(
				'label'       => 'Attività',
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => $activities,
				'label_block' => true,
				'condition'   => array( 'rule' => Visibility::ACTIVITY ),
			)
		);
		$this->add_control( 'message', array( 'label' => 'Messaggio per gli altri (facoltativo)', 'type' => Controls_Manager::TEXT ) );
		$this->add_control( 'body', array( 'label' => 'Contenuto', 'type' => Controls_Manager::WYSIWYG, 'default' => '<p>Contenuto riservato.</p>' ) );
		$this->end_controls_section();
	}

	protected function render() {
		$s    = $this->get_settings_for_display();
		$rule = Visibility::is_valid( (string) ( $s['rule'] ?? '' ) ) ? (string) $s['rule'] : Visibility::MEMBERS;
		$ids  = array_values( array_filter( array_map( 'intval', (array) ( $s['activities'] ?? array() ) ) ) );
		$body = Restrict::allowed( $rule, $ids ) ? do_shortcode( wp_kses_post( (string) ( $s['body'] ?? '' ) ) ) : ''; // gli shortcode non si eseguono per chi non può vedere
		echo Restrict::render_reserved( $rule, $ids, $body, (string) ( $s['message'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
