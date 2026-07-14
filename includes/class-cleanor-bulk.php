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
		add_action( 'wp_ajax_cleanor_convert_list', array( $this, 'ajax_convert_list' ) );
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

	/** IDs of every image attachment (used by the bulk format converter). */
	private function all_image_ids() {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
			)
		);
	}

	/** @return int Total number of image attachments. */
	public function count_images() {
		$q = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);
		return (int) $q->found_posts;
	}

	/** @return int Attachments that can be restored (kept .bak, or keep-mode derivatives). */
	private function count_backups() {
		$q = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => '_cleanor_has_backup',
						'compare' => 'EXISTS',
					),
					array(
						'key'   => '_cleanor_delivery',
						'value' => 'keep',
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

	/** List every image, for a bulk convert-to-format pass. */
	public function ajax_convert_list() {
		check_ajax_referer( 'cleanor_bulk' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error();
		}
		wp_send_json_success( array( 'ids' => array_map( 'intval', $this->all_image_ids() ) ) );
	}

	public function ajax_one() {
		check_ajax_referer( 'cleanor_bulk' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error();
		}
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		// Bulk converter: force a specific output format for this request only.
		$force  = ! empty( $_POST['force'] );
		$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : '';
		$filter = null;
		if ( in_array( $format, array( 'webp', 'avif' ), true ) ) {
			$filter = static function () use ( $format ) {
				return $format;
			};
			add_filter( 'cleanor_setting_format', $filter );
			$force = true; // Converting always re-encodes, even if already optimized.
		}

		$result = $this->optimizer->optimize_attachment( $id, $force );

		if ( $filter ) {
			remove_filter( 'cleanor_setting_format', $filter );
		}

		if ( is_wp_error( $result ) ) {
			// For the plain optimize pass, mark skips so they don't reappear.
			// For a forced convert pass, leave the existing state untouched.
			if ( ! $force ) {
				update_post_meta( $id, '_cleanor_optimized', 1 );
			}
			wp_send_json_success(
				array(
					'id'      => $id,
					'skipped' => $result->get_error_message(),
				)
			);
		}
		wp_send_json_success(
			array(
				'id'        => $id,
				'saved_pct' => $result['saved_pct'],
			)
		);
	}

	public function render() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$pending = $this->count_pending();
		$backups = $this->count_backups();
		$images  = $this->count_images();
		$done    = max( 0, $images - $pending );
		$cov     = $images > 0 ? (int) round( $done / $images * 100 ) : 0;

		Cleanor_Admin::header( 'bulk', __( 'Cleanor', 'cleanor-tools' ) );

		// Progress overview.
		echo '<div class="cleanor-card cleanor-hero cleanor-hero--slim"><div class="cleanor-hero-main">';
		echo '<div class="cleanor-big">' . esc_html( number_format_i18n( $done ) ) . '<small>' . esc_html(
			sprintf(
				/* translators: %s: total images */
				__( 'of %s images processed', 'cleanor-tools' ),
				number_format_i18n( $images )
			)
		) . '</small></div>';
		if ( $images > 0 ) {
			echo '<div class="cleanor-cov"><div class="cleanor-cov-head"><span>' . esc_html(
				sprintf(
					/* translators: %s: pending count */
					_n( '%s image left to optimize', '%s images left to optimize', $pending, 'cleanor-tools' ),
					number_format_i18n( $pending )
				)
			) . '</span><span>' . esc_html( $cov ) . '%</span></div>';
			echo '<div class="cleanor-cov-bar"><span style="width:' . esc_attr( $cov ) . '%"></span></div></div>';
		}
		echo '</div></div>';
		?>

		<div class="cleanor-card">
			<h2><?php esc_html_e( 'Optimize existing images', 'cleanor-tools' ); ?></h2>
			<?php if ( $pending > 0 ) : ?>
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
				<p><button class="button button-primary cleanor-btn-blue" id="cleanor-bulk-start"><?php esc_html_e( 'Start optimizing', 'cleanor-tools' ); ?></button></p>
				<div class="cleanor-progress" id="cleanor-bulk-progress">
					<progress id="cleanor-bulk-bar" value="0" max="100" style="display:none;"></progress>
					<p class="cleanor-status" id="cleanor-bulk-status"></p>
				</div>
			<?php else : ?>
				<p class="cleanor-sub"><?php esc_html_e( 'Every image in your library is optimized. New uploads are handled automatically.', 'cleanor-tools' ); ?></p>
				<p><span class="cleanor-badge is-ok"><?php esc_html_e( 'All optimized', 'cleanor-tools' ); ?></span></p>
			<?php endif; ?>
		</div>

		<div class="cleanor-card">
			<h2><?php esc_html_e( 'Convert to a modern format', 'cleanor-tools' ); ?></h2>
			<p class="cleanor-sub">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of images */
						_n( 'Re-encode all %s image in your library to the format you pick, in one pass. It follows your current delivery mode (keep originals, or replace files).', 'Re-encode all %s images in your library to the format you pick, in one pass. It follows your current delivery mode (keep originals, or replace files).', $images, 'cleanor-tools' ),
						number_format_i18n( $images )
					)
				);
				?>
			</p>
			<p class="cleanor-note"><?php esc_html_e( 'This re-processes every image, including ones already optimized, so it may take a while on a large library.', 'cleanor-tools' ); ?></p>
			<p>
				<label class="screen-reader-text" for="cleanor-convert-format"><?php esc_html_e( 'Target format', 'cleanor-tools' ); ?></label>
				<select id="cleanor-convert-format">
					<option value="webp"><?php esc_html_e( 'WebP (best support)', 'cleanor-tools' ); ?></option>
					<option value="avif"><?php esc_html_e( 'AVIF (smallest)', 'cleanor-tools' ); ?></option>
				</select>
				<button class="button cleanor-btn-blue" id="cleanor-convert-start"<?php disabled( 0 === $images ); ?>><?php esc_html_e( 'Convert all images', 'cleanor-tools' ); ?></button>
			</p>
			<div class="cleanor-progress" id="cleanor-convert-progress">
				<progress id="cleanor-convert-bar" value="0" max="100" style="display:none;"></progress>
				<p class="cleanor-status" id="cleanor-convert-status"></p>
			</div>
		</div>

		<?php if ( $backups > 0 ) : ?>
		<div class="cleanor-card">
			<h2><?php esc_html_e( 'Restore originals', 'cleanor-tools' ); ?></h2>
			<p class="cleanor-sub">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of restorable images */
						_n( '%s optimized image can be restored to its original.', '%s optimized images can be restored to their originals.', $backups, 'cleanor-tools' ),
						number_format_i18n( $backups )
					)
				);
				?>
				<?php esc_html_e( 'You can also restore a single image from the Images tab.', 'cleanor-tools' ); ?>
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
