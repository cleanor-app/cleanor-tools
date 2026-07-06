<?php
/**
 * Settings store + admin settings screen.
 *
 * @package Cleanor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cleanor_Settings {

	const OPTION       = 'cleanor_tools_options';
	const STATS_OPTION = 'cleanor_tools_stats';

	/** @return array Default option values. */
	public static function defaults() {
		return array(
			'endpoint'          => CLEANOR_TOOLS_DEFAULT_ENDPOINT,
			'api_key'           => '',
			'format'            => 'webp', // webp | avif | keep
			'quality'           => 80,
			'optimize_on_upload' => 1,
			'optimize_sizes'    => 1,     // also convert generated thumbnail sizes
			'keep_original'     => 0,     // keep a .bak copy of the source file
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

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CLEANOR_TOOLS_FILE ), array( $this, 'action_links' ) );
	}

	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=cleanor-tools' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'cleanor-tools' ) . '</a>' );
		return $links;
	}

	public function menu() {
		add_options_page(
			__( 'Cleanor Tools', 'cleanor-tools' ),
			__( 'Cleanor Tools', 'cleanor-tools' ),
			'manage_options',
			'cleanor-tools',
			array( $this, 'render' )
		);
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
		$format          = isset( $input['format'] ) ? sanitize_key( $input['format'] ) : 'webp';
		$out['format']   = in_array( $format, array( 'webp', 'avif', 'keep' ), true ) ? $format : 'webp';
		$q               = isset( $input['quality'] ) ? (int) $input['quality'] : 80;
		$out['quality']  = max( 1, min( 100, $q ) );
		$out['optimize_on_upload'] = empty( $input['optimize_on_upload'] ) ? 0 : 1;
		$out['optimize_sizes']     = empty( $input['optimize_sizes'] ) ? 0 : 1;
		$out['keep_original']      = empty( $input['keep_original'] ) ? 0 : 1;

		return $out;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o     = $this->all();
		$stats = $this->stats();
		$saved = max( 0, (int) $stats['original'] - (int) $stats['optimized'] );
		$pct   = $stats['original'] > 0 ? (int) round( ( 1 - $stats['optimized'] / $stats['original'] ) * 100 ) : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cleanor Tools', 'cleanor-tools' ); ?></h1>
			<p><?php esc_html_e( 'Compress and convert Media Library images with Cleanor Labs. Images are sent to your configured endpoint for processing and the optimized versions are stored back in your Media Library.', 'cleanor-tools' ); ?></p>

			<?php if ( $stats['count'] > 0 ) : ?>
			<div class="notice notice-success inline" style="padding:12px 16px;">
				<strong><?php echo esc_html( size_format( $saved, 1 ) ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of images, 2: percent saved */
						__( 'saved across %1$d images (%2$d%% smaller overall).', 'cleanor-tools' ),
						(int) $stats['count'],
						$pct
					)
				);
				?>
			</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'cleanor_tools_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cleanor_endpoint"><?php esc_html_e( 'API endpoint', 'cleanor-tools' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[endpoint]" id="cleanor_endpoint" type="url" class="regular-text code" value="<?php echo esc_attr( $o['endpoint'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Base URL of the Cleanor API. Default: https://mcp.cleanor.app', 'cleanor-tools' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cleanor_api_key"><?php esc_html_e( 'API key', 'cleanor-tools' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[api_key]" id="cleanor_api_key" type="text" class="regular-text code" value="<?php echo esc_attr( $o['api_key'] ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Optional today (the endpoint is currently key-free). Paste your key here once you have one.', 'cleanor-tools' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cleanor_format"><?php esc_html_e( 'Output format', 'cleanor-tools' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION ); ?>[format]" id="cleanor_format">
								<option value="webp" <?php selected( $o['format'], 'webp' ); ?>><?php esc_html_e( 'WebP (best support)', 'cleanor-tools' ); ?></option>
								<option value="avif" <?php selected( $o['format'], 'avif' ); ?>><?php esc_html_e( 'AVIF (smallest)', 'cleanor-tools' ); ?></option>
								<option value="keep" <?php selected( $o['format'], 'keep' ); ?>><?php esc_html_e( 'Keep format, just recompress (JPEG only)', 'cleanor-tools' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cleanor_quality"><?php esc_html_e( 'Quality', 'cleanor-tools' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[quality]" id="cleanor_quality" type="number" min="1" max="100" value="<?php echo esc_attr( $o['quality'] ); ?>" /> <span class="description">1–100 (80 recommended)</span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Behavior', 'cleanor-tools' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[optimize_on_upload]" value="1" <?php checked( $o['optimize_on_upload'], 1 ); ?> /> <?php esc_html_e( 'Optimize new uploads automatically', 'cleanor-tools' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[optimize_sizes]" value="1" <?php checked( $o['optimize_sizes'], 1 ); ?> /> <?php esc_html_e( 'Also convert generated thumbnail sizes', 'cleanor-tools' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[keep_original]" value="1" <?php checked( $o['keep_original'], 1 ); ?> /> <?php esc_html_e( 'Keep a .bak copy of the original file', 'cleanor-tools' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Connection', 'cleanor-tools' ); ?></h2>
			<p>
				<button class="button" id="cleanor-test-conn"><?php esc_html_e( 'Test connection', 'cleanor-tools' ); ?></button>
				<span id="cleanor-test-result"></span>
			</p>
			<p class="description"><?php esc_html_e( 'Bulk-optimize existing images under Media → Bulk Optimize (Cleanor).', 'cleanor-tools' ); ?></p>
		</div>
		<script>
		( function () {
			var btn = document.getElementById( 'cleanor-test-conn' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var out = document.getElementById( 'cleanor-test-result' );
				out.textContent = <?php echo wp_json_encode( __( 'Testing…', 'cleanor-tools' ) ); ?>;
				var data = new FormData();
				data.append( 'action', 'cleanor_test_connection' );
				data.append( '_ajax_nonce', <?php echo wp_json_encode( wp_create_nonce( 'cleanor_test' ) ); ?> );
				fetch( ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) { out.textContent = j.data && j.data.message ? j.data.message : JSON.stringify( j ); } )
					.catch( function ( err ) { out.textContent = String( err ); } );
			} );
		}() );
		</script>
		<?php
	}
}
