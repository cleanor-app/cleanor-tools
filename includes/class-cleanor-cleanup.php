<?php
/**
 * CleanUp: reclaim disk space left behind by optimization.
 *
 * - Deletes our sibling files (WebP/AVIF copies and .bak originals) when an
 *   attachment is deleted, so nothing is orphaned.
 * - "Delete backups": permanently drop the .bak originals kept in Replace mode.
 * - "Delete orphaned copies": remove WebP/AVIF siblings whose source image is
 *   gone (deleted attachments, or leftovers from a previous format).
 *
 * @package Cleanor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cleanor_Cleanup {

	/** @var Cleanor_Settings */
	private $settings;

	/** Raster extensions we generate derivatives from (used to spot our copies). */
	const RASTER = array( 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff', 'webp', 'avif' );

	public function __construct( Cleanor_Settings $settings ) {
		$this->settings = $settings;
	}

	public function hooks() {
		add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ) );
		add_action( 'wp_ajax_cleanor_cleanup_analyze', array( $this, 'ajax_analyze' ) );
		add_action( 'wp_ajax_cleanor_cleanup_backups', array( $this, 'ajax_delete_backups' ) );
		add_action( 'wp_ajax_cleanor_cleanup_orphans', array( $this, 'ajax_delete_orphans' ) );
		add_action( 'wp_ajax_cleanor_cleanup_scaled', array( $this, 'ajax_delete_scaled' ) );
		add_action( 'wp_ajax_cleanor_cleanup_scan_unused', array( $this, 'ajax_scan_unused' ) );
		add_action( 'wp_ajax_cleanor_cleanup_del_unused', array( $this, 'ajax_delete_unused' ) );
		add_action( 'wp_ajax_cleanor_cleanup_reset', array( $this, 'ajax_reset' ) );
		add_action( 'wp_ajax_cleanor_regen_list', array( $this, 'ajax_regen_list' ) );
		add_action( 'wp_ajax_cleanor_regen_one', array( $this, 'ajax_regen_one' ) );
	}

	// --- Maintenance: reset tracking data + regenerate thumbnails -------------

	/** Forget every optimization: clear all _cleanor_* post meta and the stats. */
	public function ajax_reset() {
		check_ajax_referer( 'cleanor_cleanup' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		global $wpdb;
		$deleted = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( '_cleanor_' ) . '%'
			)
		);
		$this->settings->reset_stats();
		wp_send_json_success( array( 'deleted' => $deleted ) );
	}

	/** List every image attachment, for the thumbnail regenerator. */
	public function ajax_regen_list() {
		check_ajax_referer( 'cleanor_cleanup' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ),
				'posts_per_page' => 5000,
				'fields'         => 'ids',
			)
		);
		wp_send_json_success( array( 'ids' => array_map( 'intval', $ids ) ) );
	}

	/** Rebuild all image sub-sizes for one attachment from its original file. */
	public function ajax_regen_one() {
		check_ajax_referer( 'cleanor_cleanup' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_success(
				array(
					'id'      => 0,
					'skipped' => 1,
				)
			);
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $id ) : get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			$file = get_attached_file( $id );
		}
		if ( ! $file || ! file_exists( $file ) ) {
			wp_send_json_success(
				array(
					'id'      => $id,
					'skipped' => 1,
				)
			);
		}
		$meta = wp_generate_attachment_metadata( $id, $file );
		if ( is_array( $meta ) ) {
			wp_update_attachment_metadata( $id, $meta );
		}
		wp_send_json_success(
			array(
				'id'   => $id,
				'done' => 1,
			)
		);
	}

	/** Measure (without deleting) what CleanUp could reclaim. */
	public function ajax_analyze() {
		check_ajax_referer( 'cleanor_cleanup' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$orphans = $this->scan_orphans( false, 0 );
		$scaled  = $this->scaled_originals( false, 0 );
		wp_send_json_success(
			array(
				'backups' => $this->measure_backups(),
				'orphans' => array(
					'count' => $orphans['count'],
					'bytes' => $orphans['bytes'],
				),
				'scaled'  => array(
					'count' => $scaled['count'],
					'bytes' => $scaled['bytes'],
				),
			)
		);
	}

	// --- Keep the library tidy when images are deleted ------------------------

	/**
	 * Remove Cleanor's sibling files when WordPress deletes an attachment, so no
	 * .webp/.avif copies or .bak originals are left orphaned on disk.
	 *
	 * @param int $post_id Attachment ID being deleted.
	 */
	public function on_delete_attachment( $post_id ) {
		$file = get_attached_file( $post_id );
		if ( ! $file ) {
			return;
		}
		$dir = trailingslashit( dirname( $file ) );

		// Exact keep-mode derivatives recorded in meta.
		$derivs = get_post_meta( $post_id, '_cleanor_derivatives', true );
		if ( is_array( $derivs ) ) {
			foreach ( $derivs as $basename ) {
				$this->maybe_unlink( $dir . (string) $basename );
			}
		}

		// Every file for this attachment (full + generated sizes).
		$names = array( basename( $file ) );
		$meta  = wp_get_attachment_metadata( $post_id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$names[] = $size['file'];
				}
			}
		}
		// Sibling WebP/AVIF copies (double-extension naming).
		foreach ( $names as $n ) {
			$this->maybe_unlink( $dir . $n . '.webp' );
			$this->maybe_unlink( $dir . $n . '.avif' );
		}
		// Backups: "<stem>.<origext>.bak" for each file's stem.
		$stems = array();
		foreach ( $names as $n ) {
			$stems[ preg_replace( '/\.[^.]+$/', '', $n ) ] = true;
		}
		foreach ( $this->bak_files( $dir, array_keys( $stems ) ) as $bak ) {
			$this->maybe_unlink( $bak );
		}
	}

	// --- Bulk delete backups (.bak originals) --------------------------------

	/** @return int Attachments that still keep a .bak original. */
	public function count_backups() {
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

	public function ajax_delete_backups() {
		check_ajax_referer( 'cleanor_cleanup' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 25,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_cleanor_has_backup',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$freed = 0;
		foreach ( $ids as $id ) {
			foreach ( $this->attachment_bak_files( $id ) as $bak ) {
				$freed += (int) @filesize( $bak ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$this->maybe_unlink( $bak );
			}
			delete_post_meta( $id, '_cleanor_has_backup' );
		}

		wp_send_json_success(
			array(
				'done'      => count( $ids ),
				'freed'     => $freed,
				'remaining' => $this->count_backups(),
			)
		);
	}

	// --- Bulk delete orphaned WebP/AVIF copies -------------------------------

	public function ajax_delete_orphans() {
		check_ajax_referer( 'cleanor_cleanup' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$r = $this->scan_orphans( true, 100 );
		wp_send_json_success(
			array(
				'deleted' => $r['deleted'],
				'freed'   => $r['bytes'],
			)
		);
	}

	/**
	 * Walk the uploads tree looking for our WebP/AVIF sibling copies whose source
	 * image is gone. Optionally deletes them.
	 *
	 * @param bool $delete  When true, delete orphans (up to $max_del).
	 * @param int  $max_del Stop after deleting this many (0 = count only).
	 * @return array { count, bytes, deleted } — bytes is the freed/reclaimable total.
	 */
	private function scan_orphans( $delete = false, $max_del = 100 ) {
		$out = array(
			'count'   => 0,
			'bytes'   => 0,
			'deleted' => 0,
		);

		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';
		if ( ! $base || ! is_dir( $base ) ) {
			return $out;
		}

		$examined = 0;
		$max_scan = 40000; // Hard cap on files examined per request.

		try {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $fileinfo ) {
				if ( ++$examined > $max_scan ) {
					break;
				}
				if ( ! $fileinfo->isFile() ) {
					continue;
				}
				$path = $fileinfo->getPathname();
				// Our copies look like "<name>.<rasterext>.<webp|avif>".
				if ( ! preg_match( '/\.([a-z0-9]+)\.(webp|avif)$/i', $path, $m ) ) {
					continue;
				}
				if ( ! in_array( strtolower( $m[1] ), self::RASTER, true ) ) {
					continue;
				}
				$source = substr( $path, 0, - ( strlen( $m[2] ) + 1 ) ); // strip ".webp"/".avif"
				if ( file_exists( $source ) ) {
					continue; // Source still there: this copy is in use, keep it.
				}
				++$out['count'];
				$out['bytes'] += (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $delete ) {
					$this->maybe_unlink( $path );
					if ( ++$out['deleted'] >= $max_del ) {
						break;
					}
				}
			}
		} catch ( Exception $e ) {
			return $out;
		}

		return $out;
	}

	// --- Delete unused Media Library images (irreversible) -------------------

	/** Scan the library and return the IDs (+ total bytes) that look unused. */
	public function ajax_scan_unused() {
		check_ajax_referer( 'cleanor_cleanup' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$ids    = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif' ),
				'posts_per_page' => 1500,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$unused = array();
		$bytes  = 0;
		foreach ( $ids as $id ) {
			if ( $this->is_media_used( (int) $id ) ) {
				continue;
			}
			$unused[] = (int) $id;
			$bytes   += $this->attachment_total_bytes( (int) $id );
		}
		wp_send_json_success(
			array(
				'ids'    => $unused,
				'count'  => count( $unused ),
				'bytes'  => $bytes,
				'capped' => ( count( $ids ) >= 1500 ),
			)
		);
	}

	/** Permanently delete one attachment (used by the unused-media cleaner). */
	public function ajax_delete_unused() {
		check_ajax_referer( 'cleanor_cleanup' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_success(
				array(
					'deleted' => 0,
					'freed'   => 0,
				)
			);
		}
		// Re-verify it is still unused right before acting (defensive).
		if ( 'attachment' !== get_post_type( $id ) || $this->is_media_used( $id ) ) {
			wp_send_json_success(
				array(
					'deleted' => 0,
					'freed'   => 0,
					'skipped' => 1,
				)
			);
		}
		// Move to Trash rather than erase: recoverable for ~30 days, and the file
		// stays on disk (so a false positive keeps displaying) until Trash is
		// emptied. Space is reclaimed when the user empties the Trash.
		$freed = $this->attachment_total_bytes( $id );
		wp_trash_post( $id );
		wp_send_json_success(
			array(
				'deleted' => 1,
				'freed'   => $freed,
			)
		);
	}

	/** Conservative "is this image referenced anywhere" check. */
	private function is_media_used( $id ) {
		global $wpdb;

		// Attached to a post that still exists.
		$post = get_post( $id );
		if ( $post && $post->post_parent && get_post( $post->post_parent ) ) {
			return true;
		}
		// Set as a featured image.
		$thumb = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 1", $id ) ); // phpcs:ignore WordPress.DB
		if ( $thumb ) {
			return true;
		}
		// Referenced by the "wp-image-<id>" class in any content.
		$id_like = '%' . $wpdb->esc_like( 'wp-image-' . $id ) . '%';
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s LIMIT 1", $id_like ) ) ) { // phpcs:ignore WordPress.DB
			return true;
		}
		// Referenced by file name (catches resized variants) in content, meta or options.
		$file = get_post_meta( $id, '_wp_attached_file', true );
		$stem = $file ? preg_replace( '/\.[^.]+$/', '', wp_basename( $file ) ) : '';
		if ( '' !== $stem && strlen( $stem ) >= 3 ) {
			$like = '%' . $wpdb->esc_like( $stem ) . '%';
			// Exclude the attachment's own post row and its own meta (WordPress
			// stores the file name in _wp_attached_file / _wp_attachment_metadata,
			// which would otherwise make every image look "used" by itself).
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID != %d AND post_status NOT IN ('trash','auto-draft') AND post_content LIKE %s LIMIT 1", $id, $like ) ) ) { // phpcs:ignore WordPress.DB
				return true;
			}
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id != %d AND meta_value LIKE %s LIMIT 1", $id, $like ) ) ) { // phpcs:ignore WordPress.DB
				return true;
			}
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 1", $like ) ) ) { // phpcs:ignore WordPress.DB
				return true;
			}
		}
		return false;
	}

	/** @return int Total bytes on disk for an attachment (full + generated sizes). */
	private function attachment_total_bytes( $id ) {
		$file = get_attached_file( $id );
		if ( ! $file ) {
			return 0;
		}
		$bytes = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$dir   = trailingslashit( dirname( $file ) );
		$meta  = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$bytes += (int) @filesize( $dir . $size['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}
		return $bytes;
	}

	// --- Remove full-size originals kept by WordPress image scaling ----------

	public function ajax_delete_scaled() {
		check_ajax_referer( 'cleanor_cleanup' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$r = $this->scaled_originals( true, 50 );
		wp_send_json_success(
			array(
				'deleted' => $r['deleted'],
				'freed'   => $r['bytes'],
			)
		);
	}

	/**
	 * WordPress keeps the untouched full-size upload ("photo.jpg") alongside the
	 * scaled version it actually serves ("photo-scaled.jpg") for images over the
	 * big-image threshold. Those pristine originals are large and rarely needed.
	 * This measures or removes them (and drops the metadata reference).
	 *
	 * @param bool $delete  When true, delete originals (up to $max_del).
	 * @param int  $max_del Stop after deleting this many (0 = count only).
	 * @return array { count, bytes, deleted }
	 */
	private function scaled_originals( $delete = false, $max_del = 50 ) {
		$out = array(
			'count'   => 0,
			'bytes'   => 0,
			'deleted' => 0,
		);

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ),
				'posts_per_page' => 5000,
				'fields'         => 'ids',
			)
		);

		foreach ( $ids as $id ) {
			$original = wp_get_original_image_path( $id );
			$current  = get_attached_file( $id );
			// Only images that were scaled have a separate, larger original file.
			if ( ! $original || ! $current || $original === $current || ! file_exists( $original ) ) {
				continue;
			}
			++$out['count'];
			$out['bytes'] += (int) @filesize( $original ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( $delete ) {
				$this->maybe_unlink( $original );
				$meta = wp_get_attachment_metadata( $id );
				if ( is_array( $meta ) && isset( $meta['original_image'] ) ) {
					unset( $meta['original_image'] );
					wp_update_attachment_metadata( $id, $meta );
				}
				if ( ++$out['deleted'] >= $max_del ) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Count backup attachments and estimate the disk space their .bak files use.
	 *
	 * @return array { count, bytes, capped }
	 */
	private function measure_backups() {
		$count = $this->count_backups();
		$ids   = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1000,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_cleanor_has_backup',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		$bytes = 0;
		foreach ( $ids as $id ) {
			foreach ( $this->attachment_bak_files( $id ) as $bak ) {
				$bytes += (int) @filesize( $bak ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		return array(
			'count'  => $count,
			'bytes'  => $bytes,
			'capped' => ( $count > count( $ids ) ),
		);
	}

	/** @return string[] Absolute paths of the .bak files kept for one attachment. */
	private function attachment_bak_files( $id ) {
		$file = get_attached_file( $id );
		if ( ! $file ) {
			return array();
		}
		$dir   = trailingslashit( dirname( $file ) );
		$names = array( basename( $file ) );
		$meta  = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$names[] = $size['file'];
				}
			}
		}
		$stems = array();
		foreach ( $names as $n ) {
			$stems[ preg_replace( '/\.[^.]+$/', '', $n ) ] = true;
		}
		return $this->bak_files( $dir, array_keys( $stems ) );
	}

	// --- Screen ---------------------------------------------------------------

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		Cleanor_Admin::header( 'cleanup', __( 'Cleanor', 'cleanor-tools' ) );
		?>
		<div class="cleanor-card cleanor-hero cleanor-hero--slim">
			<div class="cleanor-hero-main">
				<div class="cleanor-big" id="cleanor-reclaim"><span class="cleanor-dim">&hellip;</span></div>
				<div class="cleanor-hero-sub"><?php esc_html_e( 'Reclaimable disk space. Nothing is deleted until you choose an action below.', 'cleanor-tools' ); ?></div>
			</div>
		</div>

		<div class="cleanor-tiles cleanor-tiles--3">
			<div class="cleanor-tile">
				<div class="cleanor-tile-num" id="cleanor-cnt-backups"><span class="cleanor-dim">&hellip;</span></div>
				<div class="cleanor-tile-lbl"><?php esc_html_e( 'Original backups (.bak)', 'cleanor-tools' ); ?></div>
			</div>
			<div class="cleanor-tile">
				<div class="cleanor-tile-num" id="cleanor-cnt-orphans"><span class="cleanor-dim">&hellip;</span></div>
				<div class="cleanor-tile-lbl"><?php esc_html_e( 'Orphaned copies', 'cleanor-tools' ); ?></div>
			</div>
			<div class="cleanor-tile">
				<div class="cleanor-tile-num" id="cleanor-cnt-scaled"><span class="cleanor-dim">&hellip;</span></div>
				<div class="cleanor-tile-lbl"><?php esc_html_e( 'Full-size originals', 'cleanor-tools' ); ?></div>
			</div>
		</div>

		<div class="cleanor-card cleanor-callout">
			<h2><?php esc_html_e( 'How to free up hosting space', 'cleanor-tools' ); ?></h2>
			<p class="cleanor-note"><?php esc_html_e( 'To actually shrink what images use on disk: 1) set Delivery to "Replace files" in Settings so optimized files replace the originals, then run Bulk Optimize; 2) turn on Resize to cap oversized uploads; 3) below, delete the .bak backups, orphaned copies and the full-size originals of scaled images; 4) remove images you no longer use. In the default "keep originals" mode the saving is in what visitors download, not on disk.', 'cleanor-tools' ); ?></p>
		</div>

		<div class="cleanor-card">
			<h2><?php esc_html_e( 'Delete original backups', 'cleanor-tools' ); ?></h2>
			<p class="cleanor-sub"><?php esc_html_e( 'The .bak copies kept when you optimize in Replace mode. Removing them reclaims disk space but permanently disables Restore for those images.', 'cleanor-tools' ); ?></p>
			<p><button class="button cleanor-btn-blue" id="cleanor-del-backups" disabled><?php esc_html_e( 'Delete backups', 'cleanor-tools' ); ?> <span id="cleanor-del-backups-size"></span></button></p>
			<div class="cleanor-progress"><progress id="cleanor-del-backups-bar" value="0" max="100" style="display:none;"></progress><p class="cleanor-status" id="cleanor-del-backups-status"></p></div>
		</div>

		<div class="cleanor-card">
			<h2><?php esc_html_e( 'Delete orphaned copies', 'cleanor-tools' ); ?></h2>
			<p class="cleanor-sub"><?php esc_html_e( 'WebP and AVIF copies whose source image no longer exists (an image you deleted, or a leftover after switching format). These are always safe to remove.', 'cleanor-tools' ); ?></p>
			<p><button class="button cleanor-btn-blue" id="cleanor-del-orphans" disabled><?php esc_html_e( 'Delete orphaned copies', 'cleanor-tools' ); ?> <span id="cleanor-del-orphans-size"></span></button></p>
			<div class="cleanor-progress"><progress id="cleanor-del-orphans-bar" value="0" max="100" style="display:none;"></progress><p class="cleanor-status" id="cleanor-del-orphans-status"></p></div>
		</div>

		<div class="cleanor-card">
			<h2><?php esc_html_e( 'Delete full-size originals of scaled images', 'cleanor-tools' ); ?></h2>
			<p class="cleanor-sub"><?php esc_html_e( 'For large uploads WordPress serves a scaled-down version and keeps the untouched full-size original on disk. Removing those originals reclaims a lot of space. Your images keep working; you only lose the ability to re-crop or restore from the full resolution later.', 'cleanor-tools' ); ?></p>
			<p><button class="button cleanor-btn-blue" id="cleanor-del-scaled" disabled><?php esc_html_e( 'Delete full-size originals', 'cleanor-tools' ); ?> <span id="cleanor-del-scaled-size"></span></button></p>
			<div class="cleanor-progress"><progress id="cleanor-del-scaled-bar" value="0" max="100" style="display:none;"></progress><p class="cleanor-status" id="cleanor-del-scaled-status"></p></div>
		</div>

		<div class="cleanor-card cleanor-danger">
			<h2><?php esc_html_e( 'Remove unused images from the Media Library', 'cleanor-tools' ); ?></h2>
			<p class="cleanor-warn-note"><?php esc_html_e( 'This moves images that appear to be used nowhere to the Trash. It does not erase them: you can restore them from the Media Library Trash for about 30 days, and they keep displaying until the Trash is emptied. Detection is a best guess and can miss images used by page builders, sliders, widgets, theme options or custom fields, so review the results and, ideally, back up your site first. Emptying the Trash is what actually frees the disk space.', 'cleanor-tools' ); ?></p>
			<p class="cleanor-sub"><?php esc_html_e( 'Scan first to see how many images look unused and how much space they would free. An image counts as used if it is attached to a post, set as a featured image, or its file name or ID appears in any content, custom field or option.', 'cleanor-tools' ); ?></p>
			<p>
				<button class="button cleanor-btn-blue" id="cleanor-scan-unused"><?php esc_html_e( 'Scan for unused images', 'cleanor-tools' ); ?></button>
				<span id="cleanor-unused-summary" class="cleanor-muted"></span>
			</p>
			<p id="cleanor-unused-confirm-row" style="display:none;">
				<label><input type="checkbox" id="cleanor-unused-confirm" /> <?php esc_html_e( 'I have reviewed the results and want to move these images to the Trash.', 'cleanor-tools' ); ?></label>
			</p>
			<p><button class="button" id="cleanor-del-unused" disabled><?php esc_html_e( 'Move unused images to Trash', 'cleanor-tools' ); ?></button></p>
			<div class="cleanor-progress"><progress id="cleanor-del-unused-bar" value="0" max="100" style="display:none;"></progress><p class="cleanor-status" id="cleanor-del-unused-status"></p></div>
		</div>

		<div class="cleanor-card cleanor-callout">
			<h2><?php esc_html_e( 'What about the originals in keep mode?', 'cleanor-tools' ); ?></h2>
			<p class="cleanor-note"><?php esc_html_e( 'In the default "Keep originals, serve modern" mode your original files are kept on purpose: they are the fallback served to browsers that do not support WebP or AVIF, and the Media Library still points at them. Deleting them would break those images, so they are not offered here. To reclaim that space, switch Delivery to "Replace files" in Settings and re-run Bulk Optimize; the originals then become .bak backups you can remove above.', 'cleanor-tools' ); ?></p>
		</div>

		<h2 class="cleanor-h2"><?php esc_html_e( 'Maintenance', 'cleanor-tools' ); ?></h2>

		<div class="cleanor-card">
			<h2><?php esc_html_e( 'Regenerate thumbnails', 'cleanor-tools' ); ?></h2>
			<p class="cleanor-sub"><?php esc_html_e( 'Rebuild every image size from the original file. Useful after you change your theme or add image sizes, or if some thumbnails are missing. New sizes are optimized automatically.', 'cleanor-tools' ); ?></p>
			<p><button class="button cleanor-btn-blue" id="cleanor-regen-start"><?php esc_html_e( 'Regenerate thumbnails', 'cleanor-tools' ); ?></button></p>
			<div class="cleanor-progress"><progress id="cleanor-regen-bar" value="0" max="100" style="display:none;"></progress><p class="cleanor-status" id="cleanor-regen-status"></p></div>
		</div>

		<div class="cleanor-card">
			<h2><?php esc_html_e( 'Reset Cleanor data', 'cleanor-tools' ); ?></h2>
			<p class="cleanor-sub"><?php esc_html_e( 'Clears everything Cleanor has recorded (optimization status, savings totals and Restore markers) so the plugin treats your library as untouched. It does not delete or change any image file. Use it to start fresh, or before deactivating the plugin. After a reset you can run Bulk Optimize again from scratch.', 'cleanor-tools' ); ?></p>
			<p><button class="button" id="cleanor-reset-start"><?php esc_html_e( 'Reset all Cleanor data', 'cleanor-tools' ); ?></button></p>
			<p class="cleanor-status" id="cleanor-reset-status"></p>
		</div>
		<?php
		Cleanor_Admin::footer();
	}

	// --- Helpers --------------------------------------------------------------

	/**
	 * Find "<stem>.<something>.bak" files in a directory for the given stems.
	 *
	 * @param string   $dir   Directory (trailing slash).
	 * @param string[] $stems File-name stems (no extension).
	 * @return string[] Absolute paths of matching .bak files.
	 */
	private function bak_files( $dir, $stems ) {
		$out   = array();
		$files = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $files ) ) {
			return $out;
		}
		foreach ( $files as $f ) {
			if ( '.bak' !== substr( $f, -4 ) ) {
				continue;
			}
			foreach ( $stems as $stem ) {
				if ( '' !== $stem && 0 === strpos( $f, $stem . '.' ) ) {
					$out[] = $dir . $f;
					break;
				}
			}
		}
		return $out;
	}

	/** Delete a file if it exists. */
	private function maybe_unlink( $path ) {
		if ( '' !== (string) $path && file_exists( $path ) && ! is_dir( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
