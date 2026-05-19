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
			'pressmind_rendering',
			__( 'Rendering', 'pressmind' ),
			array( $this, 'render_rendering_section' ),
			'pressmind_settings'
		);

		add_settings_field(
			'seamless_mode',
			__( 'Seamless Mode', 'pressmind' ),
			array( $this, 'render_seamless_mode_field' ),
			'pressmind_settings',
			'pressmind_rendering'
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

			$connectors = $this->get_ai_connectors();

			if ( isset( $connectors[ $options['connector_id'] ] ) ) {
				$connector_endpoint = $this->get_connector_endpoint_default( $options['connector_id'], $connectors[ $options['connector_id'] ] );

				if ( $connector_endpoint ) {
					$options['api_endpoint'] = $connector_endpoint;
				}
			}
		} elseif ( ! empty( $options['api_key'] ) ) {
			$options['api_key_source'] = 'pressmind';
		} else {
			$options['api_key_source'] = '';
		}

		$options['response_format']      = isset( $options['response_format'] ) ? (string) $options['response_format'] : $this->get_defaults()['response_format'];
		$options['response_format_mode'] = 'manual' === ( $options['response_format_mode'] ?? '' ) ? 'manual' : 'auto';

		if ( ! in_array( $options['response_format'], array( 'none', 'json_object', 'json_schema' ), true ) ) {
			$options['response_format'] = 'json_object';
		}

		$options['resolved_response_format'] = $this->resolve_response_format( $options );
		$options['seamless_mode'] = ! $this->is_sandbox_generation_disallowed() && ! empty( $options['seamless_mode'] ) ? 1 : 0;

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
	 * Default chat-completions endpoint per AI connector.
	 *
	 * Used by the settings page JS so switching the connector auto-fills the
	 * endpoint with one that matches the chosen provider.
	 *
	 * @return array
	 */
	private function get_connector_endpoint_defaults() {
		return array(
			'openai'    => 'https://api.openai.com/v1/chat/completions',
			'anthropic' => 'https://api.anthropic.com/v1/chat/completions',
			'google'    => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
		);
	}

	/**
	 * Get the default endpoint for a connector.
	 *
	 * @param string $connector_id Connector ID.
	 * @param array  $connector    Connector config.
	 * @return string
	 */
	private function get_connector_endpoint_default( $connector_id, $connector = array() ) {
		$endpoint_defaults = $this->get_connector_endpoint_defaults();
		$connector_id      = sanitize_key( $connector_id );

		if ( isset( $endpoint_defaults[ $connector_id ] ) ) {
			return $endpoint_defaults[ $connector_id ];
		}

		$name   = strtolower( (string) ( $connector['name'] ?? '' ) );
		$lookup = strtolower( $connector_id . ' ' . $name );

		if ( false !== strpos( $lookup, 'gemini' ) || false !== strpos( $lookup, 'google' ) ) {
			return $endpoint_defaults['google'];
		}

		if ( false !== strpos( $lookup, 'anthropic' ) || false !== strpos( $lookup, 'claude' ) ) {
			return $endpoint_defaults['anthropic'];
		}

		if ( false !== strpos( $lookup, 'openai' ) ) {
			return $endpoint_defaults['openai'];
		}

		return '';
	}

	/**
	 * Resolve response_format from the selected model/provider.
	 *
	 * @param array $options Normalized settings.
	 * @return string
	 */
	private function resolve_response_format( array $options ) {
		if ( 'manual' === ( $options['response_format_mode'] ?? '' ) ) {
			return $options['response_format'];
		}

		return $this->infer_response_format(
			$options['model'] ?? '',
			$options['api_endpoint'] ?? '',
			$options['connector_id'] ?? ''
		);
	}

	/**
	 * Infer response_format from model, endpoint, and connector hints.
	 *
	 * @param string $model        Model name.
	 * @param string $api_endpoint API endpoint URL.
	 * @param string $connector_id Connector ID.
	 * @return string
	 */
	private function infer_response_format( $model, $api_endpoint, $connector_id ) {
		$lookup = strtolower( (string) $model . ' ' . (string) $api_endpoint . ' ' . (string) $connector_id );

		if ( false !== strpos( $lookup, 'anthropic' ) || false !== strpos( $lookup, 'claude' ) ) {
			return 'json_schema';
		}

		return 'json_object';
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
	 * Determine whether site policy disallows unfiltered generated HTML.
	 *
	 * @return bool
	 */
	private function is_sandbox_generation_disallowed() {
		if ( function_exists( 'pressmind_is_sandbox_generation_disallowed' ) ) {
			return pressmind_is_sandbox_generation_disallowed();
		}

		if ( ! defined( 'DISALLOW_UNFILTERED_HTML' ) ) {
			return false;
		}

		$value = constant( 'DISALLOW_UNFILTERED_HTML' );

		return true === $value || 1 === $value || '1' === (string) $value || 'true' === strtolower( (string) $value );
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

		$response_format_choice = isset( $options['response_format'] ) ? (string) $options['response_format'] : 'auto';
		$response_format_mode   = 'auto' === $response_format_choice ? 'auto' : 'manual';
		$response_format        = $response_format_choice;

		if ( ! in_array( $response_format, array( 'none', 'json_object', 'json_schema' ), true ) ) {
			$response_format = $this->infer_response_format(
				$options['model'] ?? $existing['model'],
				$options['api_endpoint'] ?? $existing['api_endpoint'],
				$options['connector_id'] ?? $existing['connector_id']
			);
		}

		return array(
			'credential_source'        => isset( $options['credential_source'] ) && 'connector' === $options['credential_source'] ? 'connector' : 'custom',
			'connector_id'             => isset( $options['connector_id'] ) ? sanitize_key( $options['connector_id'] ) : sanitize_key( $existing['connector_id'] ),
			'api_key'                 => isset( $options['api_key'] ) ? sanitize_text_field( $options['api_key'] ) : sanitize_text_field( $existing['api_key'] ),
			'api_endpoint'            => isset( $options['api_endpoint'] ) ? esc_url_raw( $options['api_endpoint'] ) : '',
			'model'                   => isset( $options['model'] ) ? sanitize_text_field( $options['model'] ) : '',
			'response_format_mode'    => $response_format_mode,
			'response_format'         => $response_format,
			'seamless_mode'           => ! $this->is_sandbox_generation_disallowed() && ! empty( $options['seamless_mode'] ) ? 1 : 0,
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
			'response_format_mode'    => 'auto',
			'response_format'         => 'json_object',
			'seamless_mode'           => 0,
			'enable_image_generation' => 0,
			'image_model'             => 'gpt-image-1',
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
			'<select id="pressmind-connector-id" name="%1$s[connector_id]">',
			esc_attr( self::OPTION_NAME )
		);

		foreach ( $connectors as $connector_id => $connector ) {
			$endpoint = $this->get_connector_endpoint_default( $connector_id, $connector );

			printf(
				'<option value="%1$s" data-endpoint="%2$s" %3$s>%4$s</option>',
				esc_attr( $connector_id ),
				esc_attr( $endpoint ),
				selected( $connector_id, $options['connector_id'], false ),
				esc_html( $connector['name'] ?? $connector_id )
			);
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The selected connector provides the API key. Choose the model name below.', 'pressmind' ) . '</p>';

		$current_connector = $connectors[ $options['connector_id'] ] ?? array();
		$current_endpoint  = $this->get_connector_endpoint_default( $options['connector_id'], $current_connector );

		if ( $current_endpoint ) {
			printf(
				'<p class="description" id="pressmind-connector-endpoint-description">%s <code id="pressmind-connector-endpoint">%s</code></p>',
				esc_html__( 'Requests will be sent to:', 'pressmind' ),
				esc_html( $current_endpoint )
			);
		} else {
			printf(
				'<p class="description" id="pressmind-connector-endpoint-description" hidden>%s <code id="pressmind-connector-endpoint"></code></p>',
				esc_html__( 'Requests will be sent to:', 'pressmind' )
			);
		}
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
			'<input type="url" id="pressmind-api-endpoint" name="%1$s[api_endpoint]" value="%2$s" class="regular-text" />',
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
			'<input type="text" id="pressmind-model" name="%1$s[model]" value="%2$s" class="regular-text" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $options['model'] )
		);
	}

	/**
	 * Render response_format select.
	 */
	public function render_response_format_field() {
		$options  = $this->get_options();
		$current  = 'auto' === ( $options['response_format_mode'] ?? 'auto' ) ? 'auto' : $options['response_format'];
		$choices  = array(
			'auto'        => __( 'Auto — choose from model/provider', 'pressmind' ),
			'none'        => __( 'None — rely on the system prompt to enforce JSON', 'pressmind' ),
			'json_object' => __( 'json_object — OpenAI / Gemini compat', 'pressmind' ),
			'json_schema' => __( 'json_schema — Anthropic compat, strict schema', 'pressmind' ),
		);

		printf( '<select id="pressmind-response-format" name="%1$s[response_format]">', esc_attr( self::OPTION_NAME ) );

		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
		printf(
			'<p class="description">%1$s <code id="pressmind-response-format-resolved">%2$s</code></p>',
			esc_html__( 'Automatic mode currently resolves to:', 'pressmind' ),
			esc_html( $options['resolved_response_format'] )
		);
		echo '<p class="description">' . esc_html__( 'Leave this on Auto unless a provider rejects the generated response format.', 'pressmind' ) . '</p>';
	}

	/**
	 * Render rendering section description.
	 */
	public function render_rendering_section() {
		echo '<p>' . esc_html__( 'Choose whether generated interactive HTML runs in an isolated iframe or directly in the page.', 'pressmind' ) . '</p>';
	}

	/**
	 * Render seamless mode consent toggle.
	 */
	public function render_seamless_mode_field() {
		$options     = $this->get_options();
		$is_disabled = $this->is_sandbox_generation_disallowed();

		printf(
			'<label><input type="checkbox" name="%1$s[seamless_mode]" value="1" %2$s %3$s /> %4$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( ! empty( $options['seamless_mode'] ), true, false ),
			disabled( $is_disabled, true, false ),
			esc_html__( 'I understand that seamless mode disables iframe sandboxing and injects generated HTML, CSS, and JavaScript directly into the page.', 'pressmind' )
		);

		if ( $is_disabled ) {
			echo '<p class="description">' . esc_html__( 'Seamless mode is unavailable because DISALLOW_UNFILTERED_HTML is enabled. Change that site setting before enabling direct code injection.', 'pressmind' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Keep this off to isolate generated scripts and styles in sandboxed iframes. Turn it on only for trusted editors and reviewed output.', 'pressmind' ) . '</p>';
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
	 * Render collapsed advanced settings.
	 */
	public function render_advanced_settings() {
		?>
		<details class="pressmind-advanced-settings">
			<summary><?php esc_html_e( 'Advanced settings', 'pressmind' ); ?></summary>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Response Format', 'pressmind' ); ?></th>
					<td><?php $this->render_response_format_field(); ?></td>
				</tr>
			</table>
		</details>
		<?php
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
				$this->render_advanced_settings();
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

				const connector = document.getElementById( 'pressmind-connector-id' );
				const endpoint = document.getElementById( 'pressmind-api-endpoint' );
				const endpointMessage = document.getElementById(
					'pressmind-connector-endpoint-description'
				);
				const endpointLabel = document.getElementById(
					'pressmind-connector-endpoint'
				);
				const model = document.getElementById( 'pressmind-model' );
				const responseFormat = document.getElementById(
					'pressmind-response-format'
				);
				const resolvedResponseFormat = document.getElementById(
					'pressmind-response-format-resolved'
				);

				const inferResponseFormat = () => {
					const selectedOption =
						connector && connector.options[ connector.selectedIndex ];
					const connectorValue =
						source.value === 'connector' && connector ? connector.value : '';
					const connectorEndpoint =
						source.value === 'connector' && selectedOption
							? selectedOption.getAttribute( 'data-endpoint' ) || ''
							: '';
					const lookup = [
						model ? model.value : '',
						connectorEndpoint || ( endpoint ? endpoint.value : '' ),
						connectorValue,
					]
						.join( ' ' )
						.toLowerCase();

					if (
						lookup.includes( 'anthropic' ) ||
						lookup.includes( 'claude' )
					) {
						return 'json_schema';
					}

					return 'json_object';
				};

				const syncResponseFormat = () => {
					if ( resolvedResponseFormat ) {
						resolvedResponseFormat.textContent = inferResponseFormat();
					}
				};

				const syncEndpointToConnector = () => {
					if ( ! connector || source.value !== 'connector' ) {
						syncResponseFormat();
						return;
					}

					const option = connector.options[ connector.selectedIndex ];
					const next = option ? option.getAttribute( 'data-endpoint' ) : '';

					if ( next && endpoint ) {
						endpoint.value = next;
					}

					if ( endpointLabel ) {
						endpointLabel.textContent = next;
					}

					if ( endpointMessage ) {
						endpointMessage.hidden = ! next;
					}

					syncResponseFormat();
				};

				source.addEventListener( 'change', () => {
					toggleRows();
					syncEndpointToConnector();
				} );

				if ( connector ) {
					connector.addEventListener( 'change', syncEndpointToConnector );
				}

				if ( endpoint ) {
					endpoint.addEventListener( 'input', syncResponseFormat );
				}

				if ( model ) {
					model.addEventListener( 'input', syncResponseFormat );
				}

				if ( responseFormat ) {
					responseFormat.addEventListener( 'change', syncResponseFormat );
				}

				toggleRows();
				syncEndpointToConnector();
				syncResponseFormat();
			}() );
		</script>
		<?php
	}
}
