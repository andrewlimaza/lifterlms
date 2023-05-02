<?php
/**
 * LifterLMS Shortcodes Blocks
 *
 * @package LifterLMS/Classes/Shortcodes
 *
 * @since [version]
 * @version [version]
 */

defined( 'ABSPATH' ) || exit;

/**
 * LLMS_Shortcodes_Blocks class.
 *
 * @since [version]
 */
class LLMS_Shortcodes_Blocks {

	/**
	 * Available shortcode blocks.
	 *
	 * @var array
	 */
	private $shortcodes = array(
		'access-plan-button'   => array( LLMS_Shortcodes::class, 'access_plan_button' ),
		'checkout'             => array( LLMS_Shortcodes::class, 'checkout' ),
		'courses'              => array( LLMS_Shortcode_Courses::class, 'output' ),
		'course-author'        => array( LLMS_Shortcode_Course_Author::class, 'output' ),
		'course-continue'      => array( LLMS_Shortcode_Course_Continue::class, 'output' ),
		'course-meta-info'     => array( LLMS_Shortcode_Course_Meta_Info::class, 'output' ),
		'course-outline'       => array( LLMS_Shortcode_Course_Outline::class, 'output' ),
		'course-prerequisites' => array( LLMS_Shortcode_Course_Prerequisites::class, 'output' ),
		'course-reviews'       => array( LLMS_Shortcode_Course_Reviews::class, 'output' ),
		'course-syllabus'      => array( LLMS_Shortcode_Course_Syllabus::class, 'output' ),
		'login'                => array( LLMS_Shortcodes::class, 'login' ),
		'memberships'          => array( LLMS_Shortcodes::class, 'memberships' ),
		'my-account'           => array( LLMS_Shortcodes::class, 'my_account' ),
		'my-achievements'      => array( LLMS_Shortcode_My_Achievements::class, 'output' ),
		'registration'         => array( LLMS_Shortcode_Registration::class, 'output' ),
	);

	/**
	 * Constructor.
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'after_setup_theme', array( $this, 'add_editor_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_styles' ) );
		add_filter( 'llms_hide_registration_form', array( $this, 'show_form_preview' ) );
		add_filter( 'llms_hide_login_form', array( $this, 'show_form_preview' ) );
	}

	/**
	 * Registers shortcode blocks.
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		$shortcodes = array_keys( $this->shortcodes );

		foreach ( $shortcodes as $shortcode ) {
			$block_dir = LLMS_PLUGIN_DIR . "blocks/$shortcode";

			if ( file_exists( "$block_dir/block.json" ) ) {
				register_block_type(
					$block_dir,
					array(
						'render_callback' => array( $this, 'render_block' ),
					)
				);
			}
		}
	}

	/**
	 * Loads front end CSS in the editor.
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function add_editor_styles(): void {
		$plugins_dir = basename( WP_PLUGIN_DIR );
		$plugin_dir  = basename( LLMS_PLUGIN_DIR );
		$path        = "../../$plugins_dir/$plugin_dir/assets/css/lifterlms.min.css";

		add_editor_style( $path );
	}

	/**
	 * Enqueues editor styles.
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function enqueue_editor_styles(): void {
		if ( ! llms_is_block_editor() ) {
			return;
		}

		$path = '/assets/css/editor.min.css';

		if ( ! file_exists( LLMS()->plugin_path() . $path ) ) {
			return;
		}

		wp_enqueue_style(
			'llms-editor',
			LLMS()->plugin_url() . $path,
			array(),
			filemtime( LLMS()->plugin_path() . $path )
		);
	}

	/**
	 * Shows the registration and login form in editor preview.
	 *
	 * @since [version]
	 *
	 * @param bool $hide Whether to hide the registration form.
	 * @return bool
	 */
	public function show_form_preview( bool $hide ): bool {
		if ( ! defined( 'REST_REQUEST' ) || ! is_user_logged_in() ) {
			return $hide;
		}

		global $wp;

		if ( ! $wp instanceof WP || empty( $wp->query_vars['rest_route'] ) ) {
			return $hide;
		}

		$route = $wp->query_vars['rest_route'];

		if ( false !== strpos( $route, '/block-renderer/' ) ) {
			$hide = false;
		}

		return $hide;
	}

	/**
	 * Renders a shortcode block.
	 *
	 * @since   [version]
	 * @version [version]
	 *
	 * @param array    $attributes The block attributes.
	 * @param string   $content    The block default content.
	 * @param WP_Block $block      The block instance.
	 *
	 * @return string
	 */
	public function render_block( array $attributes, string $content, WP_Block $block ): string {
		if ( ! property_exists( $block, 'name' ) ) {
			return '';
		}

		$name   = str_replace( 'llms/', '', $block->name );
		$class  = $this->shortcodes[ $name ][0];
		$method = $this->shortcodes[ $name ][1];

		if ( method_exists( $class, 'instance' ) ) {
			$class = $class::instance();
		} else {
			return '';
		}

		$content = $attributes['text'] ?? '';

		if ( $content ) {
			$shortcode = $class->$method( $attributes, $content );
		} else {
			$shortcode = $class->$method( $attributes );
		}

		// This allows emptyResponsePlaceholder to be used when no content is returned.
		if ( ! $shortcode ) {
			return '';
		}

		// Use emptyResponsePlaceholder for Courses block instead of shortcode message.
		if ( false !== strpos( $shortcode, __( 'No products were found matching your selection.', 'lifterlms' ) ) ) {
			return '';
		}

		$html  = '<div ' . get_block_wrapper_attributes() . '>';
		$html .= trim( $shortcode );
		$html .= '</div>';

		return $html;
	}
}

return new LLMS_Shortcodes_Blocks();
