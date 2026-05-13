<?php
/**
 * Plugin settings.
 *
 * @package Pressmind
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and reads AI provider settings.
 */
class Pressmind_Settings {
	const OPTION_NAME = 'pressmind_options';

	/**
	 * Register WordPress hooks.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_options_page' ) );
	}

	/**
	 * Register persisted settings.
	 */
	public function register_settings() {
		register_setting(
			'pressmind_settings',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => $this->get_defaults(),
			)
		);

		add_settings_section(
			'pressmind_provider',
			__( 'AI Provider', 'pressmind' ),
			array( $this, 'render_provider_section' ),
			'pressmind_settings'
		);

		add_settings_field(
			'api_key',
			__( 'API Key', 'pressmind' ),
			array( $this, 'render_api_key_field' ),
			'pressmind_settings',
			'pressmind_provider'
		);

		add_settings_field(
			'api_endpoint',
			__( 'API Endpoint', 'pressmind' ),
			array( $this, 'render_api_endpoint_field' ),
			'pressmind_settings',
			'pressmind_provider'
		);

		add_settings_field(
			'model',
			__( 'Model', 'pressmind' ),
			array( $this, 'render_model_field' ),
			'pressmind_settings',
			'pressmind_provider'
		);

		add_settings_section(
			'pressmind_images',
			__( 'Image Generation', 'pressmind' ),
			array( $this, 'render_images_section' ),
			'pressmind_settings'
		);

		add_settings_field(
			'enable_image_generation',
			__( 'Enable Image Generation', 'pressmind' ),
			array( $this, 'render_enable_image_generation_field' ),
			'pressmind_settings',
			'pressmind_images'
		);

		add_settings_field(
			'image_model',
			__( 'Image Model', 'pressmind' ),
			array( $this, 'render_image_model_field' ),
			'pressmind_settings',
			'pressmind_images'
		);

		add_settings_field(
			'image_size',
			__( 'Image Size', 'pressmind' ),
			array( $this, 'render_image_size_field' ),
			'pressmind_settings',
			'pressmind_images'
		);
	}

	/**
	 * Add settings page.
	 */
	public function register_options_page() {
		add_options_page(
			__( 'Pressmind', 'pressmind' ),
			__( 'Pressmind', 'pressmind' ),
			'manage_options',
			'pressmind-settings',
			array( $this, 'render_options_page' )
		);
	}

	/**
	 * Get normalized settings.
	 *
	 * @return array
	 */
	public function get_options() {
		$options = wp_parse_args(
			get_option( self::OPTION_NAME, array() ),
			$this->get_defaults()
		);

		$connector_api_key = $this->get_openai_connector_api_key();

		if ( $connector_api_key ) {
			$options['api_key']        = $connector_api_key;
			$options['api_key_source'] = 'connector';
		} elseif ( ! empty( $options['api_key'] ) ) {
			$options['api_key_source'] = 'pressmind';
		} else {
			$options['api_key_source'] = '';
		}

		return $options;
	}

	/**
	 * Check whether WordPress exposes the Connectors API.
	 *
	 * @return bool
	 */
	private function has_connectors_api() {
		return function_exists( 'wp_is_connector_registered' ) && function_exists( 'wp_get_connector' );
	}

	/**
	 * Get the OpenAI API key from WordPress 7.0 Connectors API when available.
	 *
	 * @return string
	 */
	private function get_openai_connector_api_key() {
		if ( ! $this->has_connectors_api() || ! wp_is_connector_registered( 'openai' ) ) {
			return '';
		}

		$connector    = wp_get_connector( 'openai' );
		$setting_name = $connector['authentication']['setting_name'] ?? 'connectors_ai_openai_api_key';

		return sanitize_text_field( (string) get_option( $setting_name, '' ) );
	}

	/**
	 * Sanitize settings before persistence.
	 *
	 * @param array $options Raw options.
	 * @return array
	 */
	public function sanitize_options( $options ) {
		$options = is_array( $options ) ? $options : array();

		return array(
			'api_key'                 => isset( $options['api_key'] ) ? sanitize_text_field( $options['api_key'] ) : '',
			'api_endpoint'            => isset( $options['api_endpoint'] ) ? esc_url_raw( $options['api_endpoint'] ) : '',
			'model'                   => isset( $options['model'] ) ? sanitize_text_field( $options['model'] ) : '',
			'enable_image_generation' => ! empty( $options['enable_image_generation'] ) ? 1 : 0,
			'image_model'             => isset( $options['image_model'] ) ? sanitize_text_field( $options['image_model'] ) : '',
			'image_size'              => isset( $options['image_size'] ) ? sanitize_text_field( $options['image_size'] ) : '',
		);
	}

	/**
	 * Defaults for provider settings.
	 *
	 * @return array
	 */
	private function get_defaults() {
		return array(
			'api_key'                 => '',
			'api_endpoint'            => 'https://api.openai.com/v1/chat/completions',
			'model'                   => 'gpt-4.1-mini',
			'enable_image_generation' => 0,
			'image_model'             => 'gpt-image-2',
			'image_size'              => '1024x1024',
		);
	}

	/**
	 * Render section description.
	 */
	public function render_provider_section() {
		echo '<p>' . esc_html__( 'Configure an OpenAI-compatible chat completions endpoint. API keys are used only server-side and never sent to the block editor.', 'pressmind' ) . '</p>';

		if ( $this->has_connectors_api() && wp_is_connector_registered( 'openai' ) ) {
			echo '<p>' . esc_html__( 'WordPress Connectors API is available. If an OpenAI key is configured in Settings > Connectors, Pressmind will use it before falling back to the key below.', 'pressmind' ) . '</p>';
		}
	}

	/**
	 * Render API key input.
	 */
	public function render_api_key_field() {
		$options = $this->get_options();

		printf(
			'<input type="password" name="%1$s[api_key]" value="%2$s" class="regular-text" autocomplete="off" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( 'connector' === $options['api_key_source'] ? '' : $options['api_key'] )
		);

		if ( 'connector' === $options['api_key_source'] ) {
			echo '<p class="description">' . esc_html__( 'Using the OpenAI key from Settings > Connectors. This field remains available as a fallback for older WordPress versions or sites without a configured connector.', 'pressmind' ) . '</p>';
		}
	}

	/**
	 * Render endpoint input.
	 */
	public function render_api_endpoint_field() {
		$options = $this->get_options();

		printf(
			'<input type="url" name="%1$s[api_endpoint]" value="%2$s" class="regular-text" />',
			esc_attr( self::OPTION_NAME ),
			esc_url( $options['api_endpoint'] )
		);
	}

	/**
	 * Render model input.
	 */
	public function render_model_field() {
		$options = $this->get_options();

		printf(
			'<input type="text" name="%1$s[model]" value="%2$s" class="regular-text" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $options['model'] )
		);
	}

	/**
	 * Render image section description.
	 */
	public function render_images_section() {
		echo '<p>' . esc_html__( 'When enabled, AI can request generated images. PHP will call OpenAI Images, save the result into the Media Library, and insert a core/image block.', 'pressmind' ) . '</p>';
	}

	/**
	 * Render image generation toggle.
	 */
	public function render_enable_image_generation_field() {
		$options = $this->get_options();

		printf(
			'<label><input type="checkbox" name="%1$s[enable_image_generation]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( ! empty( $options['enable_image_generation'] ), true, false ),
			esc_html__( 'Allow AI to generate images through the OpenAI Images API.', 'pressmind' )
		);
	}

	/**
	 * Render image model input.
	 */
	public function render_image_model_field() {
		$options = $this->get_options();

		printf(
			'<input type="text" name="%1$s[image_model]" value="%2$s" class="regular-text" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $options['image_model'] )
		);
	}

	/**
	 * Render image size input.
	 */
	public function render_image_size_field() {
		$options = $this->get_options();

		printf(
			'<input type="text" name="%1$s[image_size]" value="%2$s" class="regular-text" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $options['image_size'] )
		);
		echo '<p class="description">' . esc_html__( 'Example: 1024x1024. Availability depends on the selected image model.', 'pressmind' ) . '</p>';
	}

	/**
	 * Render settings page.
	 */
	public function render_options_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pressmind', 'pressmind' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'pressmind_settings' );
				do_settings_sections( 'pressmind_settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
