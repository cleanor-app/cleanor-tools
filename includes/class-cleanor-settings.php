<?php
/**
 * Settings store + branded settings screen.
 *
 * @package Cleanor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cleanor_Settings {

	const OPTION       = 'cleanor_tools_options';
	const STATS_OPTION = 'cleanor_tools_stats';

	/** Compression presets → quality. 'custom' uses the quality field verbatim. */
	const PRESETS = array(
		'balanced'   => 80,
		'aggressive' => 62,
		'lossless'   => 92,
	);

	/** @return array Default option values. */
	public static function defaults() {
		return array(
			'endpoint'           => CLEANOR_TOOLS_DEFAULT_ENDPOINT,
			'api_key'            => '',
			'preset'             => 'balanced',
			'format'             => 'webp', // webp | avif | keep
			'quality'            => 80,
			'optimize_on_upload' => 1,
			'optimize_sizes'     => 1,     // also convert generated thumbnail sizes
			'keep_original'      => 0,     // keep a .bak copy of the source file
		);
	}

	public static function seed_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	/** @return array Current options merged over defaults. */
	public function all() {
		$opts = get_option( self::OPTION, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		return wp_parse_args( $opts, self::defaults() );
	}

	/**
	 * Read one option, allowing runtime override via filter.
	 *
	 * @param string $key Option key.
	 * @return mixed
	 */
	public function get( $key ) {
		$opts  = $this->all();
		$value = isset( $opts[ $key ] ) ? $opts[ $key ] : null;
		/** Filter any Cleanor setting at read time (e.g. inject an API key from wp-config). */
		return apply_filters( "cleanor_setting_{$key}", $value, $opts );
	}

	public function endpoint() {
		return untrailingslashit( (string) $this->get( 'endpoint' ) );
	}

	/** @return array Running totals { count, original, optimized }. */
	public function stats() {
		$stats = get_option( self::STATS_OPTION, array() );
		return wp_parse_args(
			is_array( $stats ) ? $stats : array(),
			array(
				'count'     => 0,
				'original'  => 0,
				'optimized' => 0,
			)
		);
	}

	/** Accumulate savings after an attachment is optimized. */
	public function add_savings( $original, $optimized ) {
		$s = $this->stats();
		update_option(
			self::STATS_OPTION,
			array(
				'count'     => (int) $s['count'] + 1,
				'original'  => (int) $s['original'] + (int) $original,
				'optimized' => (int) $s['optimized'] + (int) $optimized,
			),
			false
		);
	}

	/** Reverse the running totals when an attachment is restored. */
	public function subtract_savings( $original, $optimized ) {
		$s = $this->stats();
		update_option(
			self::STATS_OPTION,
			array(
				'count'     => max( 0, (int) $s['count'] - 1 ),
				'original'  => max( 0, (int) $s['original'] - (int) $original ),
				'optimized' => max( 0, (int) $s['optimized'] - (int) $optimized ),
			),
			false
		);
	}

	public function hooks() {
		add_action( 'admin_init', array( $this, 'register' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CLEANOR_TOOLS_FILE ), array( $this, 'action_links' ) );
	}

	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=cleanor-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'cleanor-tools' ) . '</a>' );
		return $links;
	}

	public function register() {
		register_setting(
			'cleanor_tools_group',
			self::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$out = self::defaults();

		if ( isset( $input['endpoint'] ) ) {
			$out['endpoint'] = esc_url_raw( trim( (string) $input['endpoint'] ) );
		}
		if ( isset( $input['api_key'] ) ) {
			$out['api_key'] = sanitize_text_field( $input['api_key'] );
		}

		$format        = isset( $input['format'] ) ? sanitize_key( $input['format'] ) : 'webp';
		$out['format'] = in_array( $format, array( 'webp', 'avif', 'keep' ), true ) ? $format : 'webp';

		// Preset drives quality unless 'custom'.
		$preset = isset( $input['preset'] ) ? sanitize_key( $input['preset'] ) : 'balanced';
		if ( isset( self::PRESETS[ $preset ] ) ) {
			$out['preset']  = $preset;
			$out['quality'] = (int) self::PRESETS[ $preset ];
		} else {
			$out['preset']  = 'custom';
			$q              = isset( $input['quality'] ) ? (int) $input['quality'] : 80;
			$out['quality'] = max( 1, min( 100, $q ) );
		}

		$out['optimize_on_upload'] = empty( $input['optimize_on_upload'] ) ? 0 : 1;
		$out['optimize_sizes']     = empty( $input['optimize_sizes'] ) ? 0 : 1;
		$out['keep_original']      = empty( $input['keep_original'] ) ? 0 : 1;

		return $out;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = $this->all();
		$n = self::OPTION;

		Cleanor_Admin::header( 'settings', __( 'Cleanor Tools', 'cleanor-tools' ) );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'cleanor_tools_group' ); ?>

			<div class="cleanor-card">
				<h2><?php esc_html_e( 'Compression', 'cleanor-tools' ); ?></h2>
				<p class="cleanor-sub"><?php esc_html_e( 'Pick a preset, or choose Custom to set the quality yourself.', 'cleanor-tools' ); ?></p>

				<div class="cleanor-presets" id="cleanor-presets">
					<?php
					$presets = array(
						'balanced'   => array( __( 'Balanced', 'cleanor-tools' ), __( 'Best size-to-quality (q80). Recommended.', 'cleanor-tools' ) ),
						'aggressive' => array( __( 'Aggressive', 'cleanor-tools' ), __( 'Smallest files (q62).', 'cleanor-tools' ) ),
						'lossless'   => array( __( 'Near-lossless', 'cleanor-tools' ), __( 'Highest quality (q92).', 'cleanor-tools' ) ),
						'custom'     => array( __( 'Custom', 'cleanor-tools' ), __( 'Set your own quality.', 'cleanor-tools' ) ),
					);
					foreach ( $presets as $key => $p ) :
						$on = ( $o['preset'] === $key ) ? ' is-on' : '';
						?>
						<label class="cleanor-preset<?php echo esc_attr( $on ); ?>" data-preset="<?php echo esc_attr( $key ); ?>">
							<input type="radio" name="<?php echo esc_attr( $n ); ?>[preset]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $o['preset'], $key ); ?> />
							<span class="cleanor-preset-t"><?php echo esc_html( $p[0] ); ?></span>
							<span class="cleanor-preset-d"><?php echo esc_html( $p[1] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<div class="cleanor-field" style="margin-top:16px;">
					<label class="cleanor-field-lbl" for="cleanor_quality"><?php esc_html_e( 'Quality', 'cleanor-tools' ); ?></label>
					<div>
						<input name="<?php echo esc_attr( $n ); ?>[quality]" id="cleanor_quality" type="number" min="1" max="100" value="<?php echo esc_attr( $o['quality'] ); ?>" <?php disabled( 'custom' !== $o['preset'] ); ?> />
						<p class="description"><?php esc_html_e( '1–100. Editable only with the Custom preset.', 'cleanor-tools' ); ?></p>
					</div>
				</div>

				<div class="cleanor-field">
					<label class="cleanor-field-lbl" for="cleanor_format"><?php esc_html_e( 'Output format', 'cleanor-tools' ); ?></label>
					<div>
						<select name="<?php echo esc_attr( $n ); ?>[format]" id="cleanor_format">
							<option value="webp" <?php selected( $o['format'], 'webp' ); ?>><?php esc_html_e( 'WebP (best support)', 'cleanor-tools' ); ?></option>
							<option value="avif" <?php selected( $o['format'], 'avif' ); ?>><?php esc_html_e( 'AVIF (smallest)', 'cleanor-tools' ); ?></option>
							<option value="keep" <?php selected( $o['format'], 'keep' ); ?>><?php esc_html_e( 'Recompress, keep format (JPEG)', 'cleanor-tools' ); ?></option>
						</select>
					</div>
				</div>
			</div>

			<div class="cleanor-card">
				<h2><?php esc_html_e( 'Behavior', 'cleanor-tools' ); ?></h2>
				<div class="cleanor-field">
					<span class="cleanor-field-lbl"><?php esc_html_e( 'Automation', 'cleanor-tools' ); ?></span>
					<div>
						<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[optimize_on_upload]" value="1" <?php checked( $o['optimize_on_upload'], 1 ); ?> /> <?php esc_html_e( 'Optimize new uploads automatically', 'cleanor-tools' ); ?></label><br />
						<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[optimize_sizes]" value="1" <?php checked( $o['optimize_sizes'], 1 ); ?> /> <?php esc_html_e( 'Also convert generated thumbnail sizes', 'cleanor-tools' ); ?></label><br />
						<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[keep_original]" value="1" <?php checked( $o['keep_original'], 1 ); ?> /> <?php esc_html_e( 'Keep a .bak copy of the original (enables Restore)', 'cleanor-tools' ); ?></label>
					</div>
				</div>
			</div>

			<div class="cleanor-card">
				<h2><?php esc_html_e( 'Connection', 'cleanor-tools' ); ?></h2>
				<p class="cleanor-sub"><?php esc_html_e( 'The default endpoint is free and needs no account or key.', 'cleanor-tools' ); ?></p>
				<div class="cleanor-field">
					<label class="cleanor-field-lbl" for="cleanor_endpoint"><?php esc_html_e( 'API endpoint', 'cleanor-tools' ); ?></label>
					<div>
						<input name="<?php echo esc_attr( $n ); ?>[endpoint]" id="cleanor_endpoint" type="url" class="regular-text code" value="<?php echo esc_attr( $o['endpoint'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Default: https://mcp.cleanor.app', 'cleanor-tools' ); ?></p>
					</div>
				</div>
				<div class="cleanor-field">
					<label class="cleanor-field-lbl" for="cleanor_api_key"><?php esc_html_e( 'API key', 'cleanor-tools' ); ?></label>
					<div>
						<input name="<?php echo esc_attr( $n ); ?>[api_key]" id="cleanor_api_key" type="text" class="regular-text code" value="<?php echo esc_attr( $o['api_key'] ); ?>" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Optional today. Paste a key here once you have one.', 'cleanor-tools' ); ?></p>
					</div>
				</div>
				<p>
					<button class="button cleanor-btn-blue" id="cleanor-test-conn"><?php esc_html_e( 'Test connection', 'cleanor-tools' ); ?></button>
					<span id="cleanor-test-result" class="cleanor-muted" style="margin-left:8px;"></span>
				</p>
			</div>

			<?php submit_button( __( 'Save changes', 'cleanor-tools' ), 'primary cleanor-btn-blue' ); ?>
		</form>
		<?php
		Cleanor_Admin::footer();
	}
}
