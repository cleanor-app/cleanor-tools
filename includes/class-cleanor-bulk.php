<?php
/**
 * Bulk-optimize existing Media Library images (AJAX, one at a time), plus the
 * branded Bulk screen that also hosts "Restore originals".
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

	public function __construct( Cleanor_Settings $settings, Cleanor_Optimizer $optimizer ) {
		$this->settings  = $settings;
		$this->optimizer = $optimizer;
	}

	public function hooks() {
		add_action( 'wp_ajax_cleanor_bulk_list', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_cleanor_bulk_one', array( $this, 'ajax_one' ) );
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

	/** @return int Total number of images still pending optimization. */
	public function count_pending() {
		$q = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_cleanor_optimized',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		return (int) $q->found_posts;
	}

	/** @return int Attachments that have a restorable backup. */
	private function count_backups() {
		$q = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_cleanor_has_backup',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return (int) $q->found_posts;
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
		$pending = $this->count_pending();
		$backups = $this->count_backups();

		Cleanor_Admin::header( 'bulk', __( 'Bulk Optimize', 'cleanor-tools' ) );
		?>
		<div class="cleanor-card">
			<h2><?php esc_html_e( 'Optimize existing images', 'cleanor-tools' ); ?></h2>
			<p class="cleanor-sub">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of pending images */
						_n( '%s image is waiting to be optimized. Leave this page open while it runs.', '%s images are waiting to be optimized. Leave this page open while it runs.', $pending, 'cleanor-tools' ),
						number_format_i18n( $pending )
					)
				);
				?>
			</p>
			<p><button class="button button-primary cleanor-btn-blue" id="cleanor-bulk-start"<?php disabled( 0 === $pending ); ?>><?php esc_html_e( 'Start optimizing', 'cleanor-tools' ); ?></button></p>
			<div class="cleanor-progress" id="cleanor-bulk-progress">
				<progress id="cleanor-bulk-bar" value="0" max="100" style="display:none;"></progress>
				<p class="cleanor-status" id="cleanor-bulk-status"></p>
			</div>
		</div>

		<?php if ( $backups > 0 ) : ?>
		<div class="cleanor-card">
			<h2><?php esc_html_e( 'Restore originals', 'cleanor-tools' ); ?></h2>
			<p class="cleanor-sub">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of backed-up images */
						_n( '%s image has a backed-up original you can restore.', '%s images have backed-up originals you can restore.', $backups, 'cleanor-tools' ),
						number_format_i18n( $backups )
					)
				);
				?>
			</p>
			<p><button class="button cleanor-btn-blue" id="cleanor-restore-start"><?php esc_html_e( 'Restore all originals', 'cleanor-tools' ); ?></button></p>
			<div class="cleanor-progress" id="cleanor-restore-progress">
				<progress id="cleanor-restore-bar" value="0" max="100" style="display:none;"></progress>
				<p class="cleanor-status" id="cleanor-restore-status"></p>
			</div>
		</div>
		<?php endif; ?>
		<?php
		Cleanor_Admin::footer();
	}
}
