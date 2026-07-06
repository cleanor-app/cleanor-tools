<?php
/**
 * Bulk-optimize existing Media Library images (AJAX, one at a time).
 *
 * @package Cleanor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cleanor_Bulk {

	/** @var Cleanor_Settings */
	private $settings;
	/** @var Cleanor_Optimizer */
	private $optimizer;
	/** @var string Bulk page hook suffix, set in menu(). */
	private $hook_suffix = '';

	public function __construct( Cleanor_Settings $settings, Cleanor_Optimizer $optimizer ) {
		$this->settings  = $settings;
		$this->optimizer = $optimizer;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_cleanor_bulk_list', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_cleanor_bulk_one', array( $this, 'ajax_one' ) );
	}

	public function menu() {
		$this->hook_suffix = add_media_page(
			__( 'Bulk Optimize (Cleanor)', 'cleanor-tools' ),
			__( 'Bulk Optimize', 'cleanor-tools' ),
			'upload_files',
			'cleanor-bulk',
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue the bulk-optimizer script.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}
		wp_enqueue_script(
			'cleanor-bulk',
			CLEANOR_TOOLS_URL . 'assets/bulk.js',
			array(),
			CLEANOR_TOOLS_VERSION,
			true
		);
		wp_localize_script(
			'cleanor-bulk',
			'CleanorBulk',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'cleanor_bulk' ),
				'collecting' => __( 'Collecting images…', 'cleanor-tools' ),
				'nothing'    => __( 'Nothing left to optimize. 🎉', 'cleanor-tools' ),
				'processed'  => __( 'images processed.', 'cleanor-tools' ),
				'optimized'  => __( 'optimized', 'cleanor-tools' ),
			)
		);
	}

	/** IDs of image attachments not yet optimized. */
	private function pending_ids() {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_cleanor_optimized',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
	}

	public function ajax_list() {
		check_ajax_referer( 'cleanor_bulk' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error();
		}
		wp_send_json_success( array( 'ids' => array_map( 'intval', $this->pending_ids() ) ) );
	}

	public function ajax_one() {
		check_ajax_referer( 'cleanor_bulk' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error();
		}
		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$result = $this->optimizer->optimize_attachment( $id, false );
		if ( is_wp_error( $result ) ) {
			// Mark skips so they don't reappear on the next run.
			update_post_meta( $id, '_cleanor_optimized', 1 );
			wp_send_json_success( array( 'id' => $id, 'skipped' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'id' => $id, 'saved_pct' => $result['saved_pct'] ) );
	}

	public function render() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Optimize (Cleanor)', 'cleanor-tools' ); ?></h1>
			<p><?php esc_html_e( 'Optimize every image that has not been processed yet. You can leave this page open while it runs.', 'cleanor-tools' ); ?></p>
			<p>
				<button class="button button-primary" id="cleanor-bulk-start"><?php esc_html_e( 'Start', 'cleanor-tools' ); ?></button>
			</p>
			<div id="cleanor-bulk-progress" style="max-width:520px;">
				<progress id="cleanor-bulk-bar" value="0" max="100" style="width:100%;height:22px;display:none;"></progress>
				<p id="cleanor-bulk-status"></p>
			</div>
		</div>
		<?php
	}
}
