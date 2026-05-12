<?php
/**
 * Plugin Name: Pressmind
 * Description: Generate Gutenberg-compatible blocks from prompts and current post context.
 * Version: 0.1.0
 * Author: Pressmind
 * Text Domain: pressmind
 *
 * @package Pressmind
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRESSMIND_VERSION', '0.1.0' );
define( 'PRESSMIND_PLUGIN_FILE', __FILE__ );
define( 'PRESSMIND_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRESSMIND_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PRESSMIND_PLUGIN_DIR . 'includes/class-settings.php';
require_once PRESSMIND_PLUGIN_DIR . 'includes/class-ai-provider.php';
require_once PRESSMIND_PLUGIN_DIR . 'includes/class-rest-controller.php';

/**
 * Register the editor utility block.
 */
function pressmind_register_block() {
	$block_path = PRESSMIND_PLUGIN_DIR . 'build/ai-prompt-block';

	if ( file_exists( $block_path . '/block.json' ) ) {
		register_block_type( $block_path );
	}

	register_block_type(
		'pressmind/sandbox',
		array(
			'api_version'     => 3,
			'title'           => __( 'AI Sandboxed Content', 'pressmind' ),
			'category'        => 'widgets',
			'attributes'      => array(
				'title'  => array(
					'type'    => 'string',
					'default' => __( 'AI generated interactive content', 'pressmind' ),
				),
				'html'   => array(
					'type'    => 'string',
					'default' => '',
				),
				'css'    => array(
					'type'    => 'string',
					'default' => '',
				),
				'js'     => array(
					'type'    => 'string',
					'default' => '',
				),
				'height' => array(
					'type'    => 'number',
					'default' => 640,
				),
			),
			'render_callback' => 'pressmind_render_sandbox_block',
			'supports'        => array(
				'html' => false,
			),
		)
	);
}
add_action( 'init', 'pressmind_register_block' );

/**
 * Render generated interactive content inside an isolated iframe.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function pressmind_render_sandbox_block( $attributes ) {
	$title  = isset( $attributes['title'] ) ? sanitize_text_field( $attributes['title'] ) : __( 'AI generated interactive content', 'pressmind' );
	$html   = isset( $attributes['html'] ) ? (string) $attributes['html'] : '';
	$css    = isset( $attributes['css'] ) ? (string) $attributes['css'] : '';
	$js     = isset( $attributes['js'] ) ? (string) $attributes['js'] : '';
	$height = isset( $attributes['height'] ) ? absint( $attributes['height'] ) : 640;
	$height = max( 240, min( 1200, $height ) );
	$srcdoc = pressmind_build_sandbox_srcdoc( $html, $css, $js );

	return sprintf(
		'<div class="wp-block-pressmind-sandbox"><iframe title="%1$s" sandbox="allow-scripts" referrerpolicy="no-referrer" loading="lazy" scrolling="no" style="display:block;width:100%%;height:%2$dpx;border:1px solid #ddd;border-radius:4px;background:#fff;overflow:hidden;" srcdoc="%3$s"></iframe></div>',
		esc_attr( $title ),
		$height,
		esc_attr( $srcdoc )
	);
}

/**
 * Build iframe document for sandboxed generated HTML/CSS/JS.
 *
 * @param string $html Body HTML.
 * @param string $css  Scoped CSS.
 * @param string $js   Inline JS.
 * @return string
 */
function pressmind_build_sandbox_srcdoc( $html, $css, $js ) {
	$js = str_ireplace( '</script', '<\/script', $js );

	return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>html,body{margin:0;padding:0;box-sizing:border-box;overflow:hidden;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}*,*:before,*:after{box-sizing:inherit;}' . $css . '</style></head><body>' . $html . '<script>' . $js . '</script></body></html>';
}

/**
 * Bootstrap plugin services.
 */
function pressmind_bootstrap() {
	$settings = new Pressmind_Settings();
	$settings->register();

	$provider = new Pressmind_AI_Provider( $settings );
	$rest     = new Pressmind_REST_Controller( $provider );
	$rest->register();
}
add_action( 'plugins_loaded', 'pressmind_bootstrap' );
