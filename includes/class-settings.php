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
			'credential_source',
			__( 'API Credentials', 'pressmind' ),
			array( $this, 'render_credential_source_field' ),
			'pressmind_settings',
			'pressmind_provider'
		);

		add_settings_field(
			'connector_id',
			__( 'Connector', 'pressmind' ),
			array( $this, 'render_connector_id_field' ),
			'pressmind_settings',
			'pressmind_provider',
			array( 'class' => 'pressmind-connector-field' )
		);

		add_settings_field(
			'api_key',
			__( 'API Key', 'pressmind' ),
			array( $this, 'render_api_key_field' ),
			'pressmind_settings',
			'pressmind_provider',
			array( 'class' => 'pressmind-custom-credential-field' )
		);

		add_settings_field(
			'api_endpoint',
			__( 'API Endpoint', 'pressmind' ),
			array( $this, 'render_api_endpoint_field' ),
			'pressmind_settings',
			'pressmind_provider',
			array( 'class' => 'pressmind-custom-credential-field' )
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

		$options['credential_source'] = 'connector' === $options['credential_source'] ? 'connector' : 'custom';
		$options['connector_id']       = sanitize_key( $options['connector_id'] );

		if ( ! $this->has_connectors_api() ) {
			$options['credential_source'] = 'custom';
		}

		if ( 'connector' === $options['credential_source'] ) {
			$connector_api_key = $this->get_connector_api_key( $options['connector_id'] );

			$options['api_key']        = $connector_api_key;
			$options['api_key_source'] = $connector_api_key ? 'connector' : 'connector_missing';
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
	 * Get the source selected in saved settings without resolving credentials.
	 *
	 * @return string
	 */
	private function get_configured_credential_source() {
		$options = wp_parse_args(
			get_option( self::OPTION_NAME, array() ),
			$this->get_defaults()
		);

		return 'connector' === $options['credential_source'] ? 'connector' : 'custom';
	}

	/**
	 * Get registered AI connectors.
	 *
	 * @return array
	 */
	private function get_ai_connectors() {
		if ( ! $this->has_connectors_api() || ! function_exists( 'wp_get_connectors' ) ) {
			return array();
		}

		return array_filter(
			wp_get_connectors(),
			function ( $connector ) {
				return 'ai_provider' === ( $connector['type'] ?? '' ) && 'api_key' === ( $connector['authentication']['method'] ?? '' );
			}
		);
	}

	/**
	 * Get an API key from a selected WordPress Connector.
	 *
	 * @param string $connector_id Connector ID.
	 * @return string
	 */
	private function get_connector_api_key( $connector_id ) {
		$connector_id = sanitize_key( $connector_id );

		if ( ! $this->has_connectors_api() || ! $connector_id || ! wp_is_connector_registered( $connector_id ) ) {
			return '';
		}

		$connector      = wp_get_connector( $connector_id );
		$auth           = $connector['authentication'] ?? array();
		$env_var_name   = $auth['env_var_name'] ?? strtoupper( str_replace( '-', '_', $connector_id ) ) . '_API_KEY';
		$constant_name  = $auth['constant_name'] ?? $env_var_name;
		$setting_name   = $auth['setting_name'] ?? 'connectors_ai_' . $connector_id . '_api_key';
		$connector_key  = getenv( $env_var_name );
		$connector_key  = $connector_key ? $connector_key : ( defined( $constant_name ) ? constant( $constant_name ) : '' );
		$connector_key  = $connector_key ? $connector_key : get_option( $setting_name, '' );

		return sanitize_text_field( (string) $connector_key );
	}

	/**
	 * Sanitize settings before persistence.
	 *
	 * @param array $options Raw options.
	 * @return array
	 */
	public function sanitize_options( $options ) {
		$options  = is_array( $options ) ? $options : array();
		$existing = wp_parse_args(
			get_option( self::OPTION_NAME, array() ),
			$this->get_defaults()
		);

		return array(
			'credential_source'        => isset( $options['credential_source'] ) && 'connector' === $options['credential_source'] ? 'connector' : 'custom',
			'connector_id'             => isset( $options['connector_id'] ) ? sanitize_key( $options['connector_id'] ) : sanitize_key( $existing['connector_id'] ),
			'api_key'                 => isset( $options['api_key'] ) ? sanitize_text_field( $options['api_key'] ) : sanitize_text_field( $existing['api_key'] ),
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
			'credential_source'        => 'custom',
			'connector_id'             => 'openai',
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
	}

	/**
	 * Render credential source controls.
	 */
	public function render_credential_source_field() {
		$options = $this->get_options();

		printf(
			'<select id="pressmind-credential-source" name="%1$s[credential_source]">',
			esc_attr( self::OPTION_NAME )
		);

		printf(
			'<option value="custom" %1$s>%2$s</option>',
			selected( 'custom', $options['credential_source'], false ),
			esc_html__( 'Custom API key', 'pressmind' )
		);

		printf(
			'<option value="connector" %1$s %2$s>%3$s</option>',
			selected( 'connector', $options['credential_source'], false ),
			disabled( ! $this->has_connectors_api(), true, false ),
			esc_html__( 'WordPress Connector', 'pressmind' )
		);

		echo '</select>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Switching this setting shows only the relevant credential controls. Save changes to persist it.', 'pressmind' )
		);

		if ( ! $this->has_connectors_api() ) {
			echo '<p class="description">' . esc_html__( 'Connectors API is not available on this WordPress version. Pressmind will use custom settings.', 'pressmind' ) . '</p>';
		}
	}

	/**
	 * Render connector select field.
	 */
	public function render_connector_id_field() {
		$options    = $this->get_options();
		$connectors = $this->get_ai_connectors();

		if ( empty( $connectors ) ) {
			echo '<p class="description">' . esc_html__( 'No API-key AI connectors are currently registered.', 'pressmind' ) . '</p>';
			return;
		}

		printf(
			'<select name="%1$s[connector_id]">',
			esc_attr( self::OPTION_NAME )
		);

		foreach ( $connectors as $connector_id => $connector ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $connector_id ),
				selected( $connector_id, $options['connector_id'], false ),
				esc_html( $connector['name'] ?? $connector_id )
			);
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The selected connector provides the API key. Choose the model name below.', 'pressmind' ) . '</p>';
	}

	/**
	 * Render API key input.
	 */
	public function render_api_key_field() {
		$options       = $this->get_options();
		$saved_options = wp_parse_args(
			get_option( self::OPTION_NAME, array() ),
			$this->get_defaults()
		);

		printf(
			'<input type="password" name="%1$s[api_key]" value="%2$s" class="regular-text" autocomplete="off" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $saved_options['api_key'] )
		);

		if ( 'connector' === $options['api_key_source'] ) {
			echo '<p class="description">' . esc_html__( 'Currently using the selected WordPress Connector key. This custom key is saved separately and is only used when Custom settings is selected.', 'pressmind' ) . '</p>';
		} elseif ( 'connector_missing' === $options['api_key_source'] ) {
			echo '<p class="description">' . esc_html__( 'The selected connector does not have an available key yet. Configure it in Settings > Connectors or choose Custom settings.', 'pressmind' ) . '</p>';
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
		<script>
			( function () {
				const source = document.getElementById( 'pressmind-credential-source' );

				if ( ! source ) {
					return;
				}

				const toggleRows = () => {
					const isConnector = source.value === 'connector';

					document
						.querySelectorAll( '.pressmind-connector-field' )
						.forEach( ( row ) => {
							row.style.display = isConnector ? '' : 'none';
						} );

					document
						.querySelectorAll( '.pressmind-custom-credential-field' )
						.forEach( ( row ) => {
							row.style.display = isConnector ? 'none' : '';
						} );
				};

				source.addEventListener( 'change', toggleRows );
				toggleRows();
			}() );
		</script>
		<?php
	}
}
