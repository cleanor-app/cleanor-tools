<?php
/**
 * Restore original images from the .bak copies kept when "Keep a .bak copy"
 * was enabled at optimize time. Reverses the file pointers, MIME types, size
 * metadata and running savings, so an attachment looks un-optimized again.
 *
 * Only attachments flagged with _cleanor_has_backup (a format conversion that
 * kept a backup) can be restored; in-place recompress has no original to undo.
 *
 * @package Cleanor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cleanor_Restore {

	/** @var Cleanor_Settings */
	private $settings;

	public function __construct( Cleanor_Settings $settings ) {
		$this->settings = $settings;
	}

	public function hooks() {
		add_action( 'wp_ajax_cleanor_restore_list', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_cleanor_restore_one', array( $this, 'ajax_one' ) );
		add_filter( 'media_row_actions', array( $this, 'row_action' ), 11, 2 );
		add_action( 'admin_action_cleanor_restore', array( $this, 'handle_row_action' ) );
		add_filter( 'bulk_actions-upload', array( $this, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
	}

	/** @return int Number of attachments that have a restorable backup. */
	public function count_backups() {
		$q = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => self::restorable_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		return (int) $q->found_posts;
	}

	/** @return int[] Attachment IDs with a restorable backup. */
	private function backup_ids() {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1000,
				'fields'         => 'ids',
				'meta_query'     => self::restorable_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
	}

	/**
	 * Meta query matching any restorable attachment: a kept .bak (replace mode)
	 * or a non-destructive keep-mode optimization.
	 *
	 * @return array
	 */
	private static function restorable_meta_query() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => '_cleanor_has_backup',
				'compare' => 'EXISTS',
			),
			array(
				'key'   => '_cleanor_delivery',
				'value' => 'keep',
			),
		);
	}

	public function ajax_list() {
		check_ajax_referer( 'cleanor_restore' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		wp_send_json_success( array( 'ids' => array_map( 'intval', $this->backup_ids() ) ) );
	}

	public function ajax_one() {
		check_ajax_referer( 'cleanor_restore' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$result = $this->restore_attachment( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_success(
				array(
					'id'      => $id,
					'skipped' => $result->get_error_message(),
				)
			);
		}
		wp_send_json_success(
			array(
				'id'       => $id,
				'restored' => true,
			)
		);
	}

	/**
	 * Restore one attachment from its backups.
	 *
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	public function restore_attachment( $id ) {
		// Non-destructive mode: just drop the derivatives; the original is intact.
		if ( 'keep' === get_post_meta( $id, '_cleanor_delivery', true ) ) {
			return $this->restore_keep( $id );
		}
		if ( ! get_post_meta( $id, '_cleanor_has_backup', true ) ) {
			return new WP_Error( 'cleanor_no_backup', __( 'No backup to restore.', 'cleanor-tools' ) );
		}
		$fs = $this->fs();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}
		$current = get_attached_file( $id );
		if ( ! $current ) {
			return new WP_Error( 'cleanor_no_file', __( 'Attachment file missing.', 'cleanor-tools' ) );
		}
		$dir  = trailingslashit( dirname( $current ) );
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		// --- Full-size ---
		$restored_base = $this->restore_file( $fs, $dir, basename( $current ) );
		if ( null === $restored_base ) {
			return new WP_Error( 'cleanor_bak_missing', __( 'Backup file not found on disk.', 'cleanor-tools' ) );
		}
		$uploads      = wp_get_upload_dir();
		$restored_abs = $dir . $restored_base;
		$new_rel      = ltrim( str_replace( $uploads['basedir'], '', $restored_abs ), '/\\' );
		update_post_meta( $id, '_wp_attached_file', $new_rel );
		$meta['file'] = $new_rel;
		$ft           = wp_check_filetype( $restored_base );
		if ( ! empty( $ft['type'] ) ) {
			wp_update_post(
				array(
					'ID'             => $id,
					'post_mime_type' => $ft['type'],
				)
			);
		}

		// --- Generated sizes ---
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $name => $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$rb = $this->restore_file( $fs, $dir, $size['file'] );
				if ( null !== $rb ) {
					$meta['sizes'][ $name ]['file'] = $rb;
					$sft                            = wp_check_filetype( $rb );
					if ( ! empty( $sft['type'] ) ) {
						$meta['sizes'][ $name ]['mime-type'] = $sft['type'];
					}
				}
			}
		}
		wp_update_attachment_metadata( $id, $meta );

		// --- Reverse the running savings + clear our meta ---
		$ob = (int) get_post_meta( $id, '_cleanor_original_bytes', true );
		$op = (int) get_post_meta( $id, '_cleanor_optimized_bytes', true );
		$this->settings->subtract_savings( $ob, $op );

		foreach ( array( '_cleanor_optimized', '_cleanor_original_bytes', '_cleanor_optimized_bytes', '_cleanor_saved_pct', '_cleanor_format', '_cleanor_has_backup' ) as $key ) {
			delete_post_meta( $id, $key );
		}

		/** Fires after an attachment is restored to its original. */
		do_action( 'cleanor_after_restore', $id );

		return true;
	}

	/**
	 * Revert a non-destructive ("keep") optimization: delete the WebP/AVIF
	 * derivatives and clear our meta. The original file was never touched.
	 *
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	private function restore_keep( $id ) {
		$current = get_attached_file( $id );
		if ( $current ) {
			$dir    = trailingslashit( dirname( $current ) );
			$derivs = get_post_meta( $id, '_cleanor_derivatives', true );
			if ( is_array( $derivs ) ) {
				foreach ( $derivs as $basename ) {
					$path = $dir . $basename;
					if ( '' !== (string) $basename && file_exists( $path ) ) {
						wp_delete_file( $path );
					}
				}
			}
		}

		$ob = (int) get_post_meta( $id, '_cleanor_original_bytes', true );
		$op = (int) get_post_meta( $id, '_cleanor_optimized_bytes', true );
		$this->settings->subtract_savings( $ob, $op );

		foreach ( array( '_cleanor_optimized', '_cleanor_original_bytes', '_cleanor_optimized_bytes', '_cleanor_saved_pct', '_cleanor_format', '_cleanor_delivery', '_cleanor_derivative_format', '_cleanor_derivatives', '_cleanor_has_backup' ) as $key ) {
			delete_post_meta( $id, $key );
		}

		/** Fires after an attachment is restored to its original. */
		do_action( 'cleanor_after_restore', $id );

		return true;
	}

	/**
	 * Move a single "<name>.<ext>.bak" back to "<name>.<ext>", removing the
	 * superseding optimized file. Returns the restored basename or null.
	 *
	 * @param WP_Filesystem_Base $fs               Filesystem.
	 * @param string             $dir              Directory (trailing slash).
	 * @param string             $current_basename The current (optimized) file name.
	 * @return string|null
	 */
	private function restore_file( $fs, $dir, $current_basename ) {
		$bak = $this->find_bak( $dir, $current_basename );
		if ( null === $bak ) {
			return null;
		}
		$restored = substr( $bak, 0, -4 ); // strip ".bak"
		if ( ! $fs->move( $dir . $bak, $dir . $restored, true ) ) {
			return null;
		}
		if ( $restored !== $current_basename && file_exists( $dir . $current_basename ) ) {
			wp_delete_file( $dir . $current_basename );
		}
		return $restored;
	}

	/**
	 * Find the "<stem>.<ext>.bak" sibling for a given current file name.
	 *
	 * @param string $dir              Directory (trailing slash).
	 * @param string $current_basename e.g. "photo.webp".
	 * @return string|null Basename of the .bak file, or null.
	 */
	private function find_bak( $dir, $current_basename ) {
		$stem  = preg_replace( '/\.[^.]+$/', '', $current_basename );
		$files = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dir may be unreadable.
		if ( ! is_array( $files ) ) {
			return null;
		}
		foreach ( $files as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			if ( 0 === strpos( $f, $stem . '.' ) && '.bak' === substr( $f, -4 ) ) {
				return $f;
			}
		}
		return null;
	}

	// --- Media list-table row action -----------------------------------------

	public function row_action( $actions, $post ) {
		$restorable = get_post_meta( $post->ID, '_cleanor_has_backup', true )
			|| 'keep' === get_post_meta( $post->ID, '_cleanor_delivery', true );
		if ( ! $restorable ) {
			return $actions;
		}
		$url                        = wp_nonce_url(
			admin_url( 'admin.php?action=cleanor_restore&attachment=' . $post->ID ),
			'cleanor_restore_' . $post->ID
		);
		$actions['cleanor_restore'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Restore original (Cleanor)', 'cleanor-tools' ) . '</a>';
		return $actions;
	}

	public function handle_row_action() {
		$id = isset( $_GET['attachment'] ) ? (int) $_GET['attachment'] : 0;
		check_admin_referer( 'cleanor_restore_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cleanor-tools' ) );
		}
		$this->restore_attachment( $id );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' ) );
		exit;
	}

	// --- Media list-table Bulk Actions ---------------------------------------

	/** Add "Restore original (Cleanor)" to the Bulk Actions dropdown. */
	public function register_bulk_action( $actions ) {
		$actions['cleanor_restore'] = __( 'Restore original (Cleanor)', 'cleanor-tools' );
		return $actions;
	}

	/**
	 * Bulk-restore the selected attachments from their backups.
	 *
	 * @param string $redirect  The URL WordPress will redirect to.
	 * @param string $action    The chosen bulk action.
	 * @param int[]  $post_ids  Selected attachment IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect, $action, $post_ids ) {
		if ( 'cleanor_restore' !== $action ) {
			return $redirect;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return $redirect;
		}

		$done    = 0;
		$skipped = 0;
		foreach ( (array) $post_ids as $post_id ) {
			$result = $this->restore_attachment( (int) $post_id );
			if ( is_wp_error( $result ) ) {
				++$skipped;
				continue;
			}
			++$done;
		}

		return add_query_arg(
			array(
				'cleanor_restored'        => $done,
				'cleanor_restore_skipped' => $skipped,
			),
			$redirect
		);
	}

	/** Show the outcome of a bulk restore as an admin notice. */
	public function bulk_notice() {
		if ( ! isset( $_REQUEST['cleanor_restored'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$done    = isset( $_REQUEST['cleanor_restored'] ) ? (int) $_REQUEST['cleanor_restored'] : 0;               // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = isset( $_REQUEST['cleanor_restore_skipped'] ) ? (int) $_REQUEST['cleanor_restore_skipped'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$parts   = array();
		$parts[] = sprintf( /* translators: %d: number of images */ _n( '%d image restored', '%d images restored', $done, 'cleanor-tools' ), $done );
		if ( $skipped ) {
			$parts[] = sprintf( /* translators: %d: number skipped */ _n( '%d had no backup', '%d had no backup', $skipped, 'cleanor-tools' ), $skipped );
		}
		printf(
			'<div class="notice notice-success is-dismissible"><p>%1$s %2$s</p></div>',
			esc_html__( 'Cleanor:', 'cleanor-tools' ),
			esc_html( implode( ', ', $parts ) . '.' )
		);
	}

	/**
	 * Lazily initialize WP_Filesystem.
	 *
	 * @return WP_Filesystem_Base|WP_Error
	 */
	private function fs() {
		global $wp_filesystem;
		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			return $wp_filesystem;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'cleanor_fs', __( 'Filesystem is not writable.', 'cleanor-tools' ) );
		}
		return $wp_filesystem;
	}
}
