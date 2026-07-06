<?php
/**
 * Thin HTTP client for the Cleanor REST API (/v1).
 *
 * @package Cleanor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cleanor_API {

	/** @var Cleanor_Settings */
	private $settings;

	public function __construct( Cleanor_Settings $settings ) {
		$this->settings = $settings;
	}

	public function hooks() {
		add_action( 'wp_ajax_cleanor_test_connection', array( $this, 'ajax_test_connection' ) );
	}

	/** @return array Request headers, incl. optional bearer auth. */
	private function auth_headers() {
		$headers = array();
		$key     = trim( (string) $this->settings->get( 'api_key' ) );
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
			$headers['X-API-Key']     = $key;
		}
		return $headers;
	}

	/**
	 * Fetch /v1/capabilities (used for the connection test + feature detection).
	 *
	 * @return array|WP_Error
	 */
	public function capabilities() {
		$res = wp_remote_get(
			$this->settings->endpoint() . '/v1/capabilities',
			array(
				'timeout' => 15,
				'headers' => $this->auth_headers(),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== (int) $code || ! is_array( $body ) ) {
			return new WP_Error( 'cleanor_bad_response', sprintf( 'HTTP %d', $code ) );
		}
		return $body;
	}

	/**
	 * Optimize raw image bytes.
	 *
	 * @param string      $bytes       Source image bytes.
	 * @param string      $source_mime Source MIME (used only for the request content-type).
	 * @param string      $format      Target format: webp|avif|jpeg.
	 * @param int         $quality     1-100.
	 * @param int|null    $width       Optional resize width.
	 * @return array|WP_Error  { bytes, original, optimized, saved_pct, mime }
	 */
	public function optimize_bytes( $bytes, $source_mime, $format, $quality, $width = null ) {
		$args = array(
			'format'  => $format,
			'quality' => (int) $quality,
			'json'    => '1',
		);
		if ( $width ) {
			$args['width'] = (int) $width;
		}
		$url = add_query_arg( $args, $this->settings->endpoint() . '/v1/optimize' );

		$res = wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => array_merge(
					$this->auth_headers(),
					array(
						'Content-Type' => $source_mime ? $source_mime : 'application/octet-stream',
						'Accept'       => 'application/json',
					)
				),
				'body'    => $bytes,
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 !== $code ) {
			$msg = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : sprintf( 'HTTP %d', $code );
			return new WP_Error( 'cleanor_optimize_failed', $msg );
		}
		if ( ! is_array( $body ) || empty( $body['data_base64'] ) ) {
			return new WP_Error( 'cleanor_optimize_failed', 'Malformed response.' );
		}

		$decoded = base64_decode( $body['data_base64'], true ); // phpcs:ignore
		if ( false === $decoded ) {
			return new WP_Error( 'cleanor_optimize_failed', 'Could not decode response.' );
		}

		return array(
			'bytes'     => $decoded,
			'original'  => isset( $body['original_bytes'] ) ? (int) $body['original_bytes'] : strlen( $bytes ),
			'optimized' => isset( $body['optimized_bytes'] ) ? (int) $body['optimized_bytes'] : strlen( $decoded ),
			'saved_pct' => isset( $body['saved_pct'] ) ? (int) $body['saved_pct'] : 0,
			'mime'      => isset( $body['mime'] ) ? $body['mime'] : ( 'image/' . $format ),
		);
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'cleanor_test' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'cleanor-tools' ) ) );
		}
		$caps = $this->capabilities();
		if ( is_wp_error( $caps ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed: ', 'cleanor-tools' ) . $caps->get_error_message() ) );
		}
		$tools = isset( $caps['tools'] ) ? count( $caps['tools'] ) : 0;
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: server version, 2: tool count, 3: auth mode */
					__( 'Connected. Cleanor v%1$s, %2$d tools, auth: %3$s.', 'cleanor-tools' ),
					isset( $caps['version'] ) ? $caps['version'] : '?',
					$tools,
					isset( $caps['auth'] ) ? $caps['auth'] : '?'
				),
			)
		);
	}
}
