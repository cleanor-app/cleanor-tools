<?php
/**
 * Core: optimize Media Library attachments via the Cleanor API.
 *
 * @package Cleanor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cleanor_Optimizer {

	/** @var Cleanor_Settings */
	private $settings;
	/** @var Cleanor_API */
	private $api;
	/** @var Cleanor_Local */
	private $local;

	/** Source MIME types we can read + convert. */
	const READABLE = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' );

	public function __construct( Cleanor_Settings $settings, Cleanor_API $api, Cleanor_Local $local ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->local    = $local;
	}

	/**
	 * Encode bytes with the configured engine: local (server), the Cleanor API,
	 * or Auto (local first, API as fallback).
	 *
	 * @return array|WP_Error Same shape as Cleanor_API::optimize_bytes().
	 */
	private function encode( $bytes, $source_mime, $format, $quality, $width, $strip ) {
		$engine = $this->settings->get( 'engine' );

		if ( 'local' === $engine ) {
			return $this->local->optimize_bytes( $bytes, $source_mime, $format, $quality, $width, $strip );
		}
		if ( 'api' === $engine ) {
			return $this->api->optimize_bytes( $bytes, $source_mime, $format, $quality, $width, $strip );
		}

		// Auto: prefer the server when it can produce this format, else the API.
		if ( $this->local->can( $format ) ) {
			$result = $this->local->optimize_bytes( $bytes, $source_mime, $format, $quality, $width, $strip );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			// Local failed unexpectedly; fall back to the API.
		}
		return $this->api->optimize_bytes( $bytes, $source_mime, $format, $quality, $width, $strip );
	}

	public function hooks() {
		// Runs after WP has created all image sizes; we mutate + return metadata.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_upload' ), 20, 2 );
		// "Optimize" row action in the Media list table.
		add_filter( 'media_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'admin_action_cleanor_optimize', array( $this, 'handle_row_action' ) );
		// Native "Optimize with Cleanor" entry in the Media Library Bulk Actions dropdown.
		add_filter( 'bulk_actions-upload', array( $this, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
		// "Cleanor" savings column in the Media list table.
		add_filter( 'manage_media_columns', array( $this, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'media_column_value' ), 10, 2 );
		// Status filter dropdown above the Media list table.
		add_action( 'restrict_manage_posts', array( $this, 'media_filter_ui' ) );
		add_action( 'pre_get_posts', array( $this, 'media_filter_query' ) );
	}

	/**
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Possibly-updated metadata.
	 */
	public function on_upload( $metadata, $attachment_id ) {
		if ( ! $this->settings->get( 'optimize_on_upload' ) ) {
			return $metadata;
		}
		if ( ! is_array( $metadata ) ) {
			return $metadata;
		}
		$result = $this->process( $attachment_id, $metadata, false );
		if ( is_wp_error( $result ) ) {
			return $metadata; // Leave the original untouched on failure.
		}
		return $result['metadata'];
	}

	/**
	 * Public entry point for manual / bulk optimization (loads + saves metadata).
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $force         Re-optimize even if already done.
	 * @return array|WP_Error
	 */
	public function optimize_attachment( $attachment_id, $force = false ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}
		$result = $this->process( $attachment_id, $metadata, $force );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		wp_update_attachment_metadata( $attachment_id, $result['metadata'] );
		return $result['stats'];
	}

	/**
	 * The engine. Converts the full image (+ optional sizes), rewrites the file
	 * pointers and MIME type, records stats. Does NOT itself persist metadata
	 * when called from the generate filter (caller stores the returned array).
	 *
	 * @return array|WP_Error  { metadata, stats } on success.
	 */
	private function process( $attachment_id, $metadata, $force ) {
		if ( ! $force && get_post_meta( $attachment_id, '_cleanor_optimized', true ) ) {
			return new WP_Error( 'cleanor_already', __( 'Already optimized.', 'cleanor-tools' ) );
		}

		$full_path = get_attached_file( $attachment_id );
		if ( ! $full_path || ! file_exists( $full_path ) ) {
			return new WP_Error( 'cleanor_no_file', __( 'File not found.', 'cleanor-tools' ) );
		}

		$source_mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $source_mime, self::READABLE, true ) ) {
			return new WP_Error( 'cleanor_unsupported', __( 'Unsupported file type.', 'cleanor-tools' ) );
		}

		// Non-destructive delivery: keep the original file/URL, write sibling
		// WebP/AVIF derivatives that Cleanor_Serve swaps in via <picture>.
		if ( 'keep' === $this->settings->get( 'delivery' ) ) {
			return $this->process_keep( $attachment_id, $metadata, $full_path, $source_mime );
		}

		$target = $this->resolve_target( $source_mime );
		if ( null === $target ) {
			return new WP_Error( 'cleanor_skip', __( 'Nothing to do for this format.', 'cleanor-tools' ) );
		}

		$dir             = trailingslashit( dirname( $full_path ) );
		$total_original  = 0;
		$total_optimized = 0;
		$kept_backup     = false;

		// --- Full-size image (the only one we resize; thumbnails are already sized) ---
		$full = $this->convert_file( $full_path, $source_mime, $target, (int) $this->settings->get( 'quality' ), (int) $this->settings->get( 'max_width' ) );
		if ( is_wp_error( $full ) ) {
			return $full;
		}
		$total_original  += $full['original'];
		$total_optimized += $full['optimized'];
		if ( ! empty( $full['width'] ) && ! empty( $full['height'] ) ) {
			$metadata['width']  = (int) $full['width'];
			$metadata['height'] = (int) $full['height'];
		}

		if ( $full['new_path'] !== $full_path ) {
			if ( $this->settings->get( 'keep_original' ) ) {
				$kept_backup = true;
			}
			// Extension changed: repoint the attachment and update its MIME.
			$uploads = wp_get_upload_dir();
			$new_rel = ltrim( str_replace( $uploads['basedir'], '', $full['new_path'] ), '/\\' );
			update_post_meta( $attachment_id, '_wp_attached_file', $new_rel );
			$metadata['file'] = $new_rel;
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => $full['mime'],
				)
			);
			$this->retire_original( $full_path );
		}

		// --- Generated sizes ---
		if ( $this->settings->get( 'optimize_sizes' ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $name => $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$size_path = $dir . $size['file'];
				if ( ! file_exists( $size_path ) ) {
					continue;
				}
				$size_mime = ! empty( $size['mime-type'] ) ? $size['mime-type'] : $source_mime;
				$conv      = $this->convert_file( $size_path, $size_mime, $target, (int) $this->settings->get( 'quality' ) );
				if ( is_wp_error( $conv ) ) {
					continue; // Skip this size, keep going.
				}
				$total_original  += $conv['original'];
				$total_optimized += $conv['optimized'];
				if ( $conv['new_path'] !== $size_path ) {
					$metadata['sizes'][ $name ]['file']      = basename( $conv['new_path'] );
					$metadata['sizes'][ $name ]['mime-type'] = $conv['mime'];
					$this->retire_original( $size_path );
				}
			}
		}

		$saved_pct = $total_original > 0 ? (int) round( ( 1 - $total_optimized / $total_original ) * 100 ) : 0;
		$stats     = array(
			'original'  => $total_original,
			'optimized' => $total_optimized,
			'saved_pct' => $saved_pct,
			'format'    => $target,
		);

		update_post_meta( $attachment_id, '_cleanor_optimized', 1 );
		update_post_meta( $attachment_id, '_cleanor_original_bytes', $total_original );
		update_post_meta( $attachment_id, '_cleanor_optimized_bytes', $total_optimized );
		update_post_meta( $attachment_id, '_cleanor_saved_pct', $saved_pct );
		update_post_meta( $attachment_id, '_cleanor_format', $target );
		if ( $kept_backup ) {
			update_post_meta( $attachment_id, '_cleanor_has_backup', 1 );
		} else {
			delete_post_meta( $attachment_id, '_cleanor_has_backup' );
		}
		// This is a destructive replace: remove any keep-mode sibling copies and
		// drop the markers left from a previous non-destructive run so Restore
		// takes the correct path.
		$this->delete_recorded_derivatives( $attachment_id );
		delete_post_meta( $attachment_id, '_cleanor_delivery' );
		delete_post_meta( $attachment_id, '_cleanor_derivative_format' );
		delete_post_meta( $attachment_id, '_cleanor_derivatives' );

		$this->settings->add_savings( $total_original, $total_optimized );

		/** Fires after an attachment is optimized. Extension point for plugins. */
		do_action( 'cleanor_after_optimize', $attachment_id, $stats, $metadata );

		return array(
			'metadata' => $metadata,
			'stats'    => $stats,
		);
	}

	/**
	 * Non-destructive engine. Leaves the original file, MIME type and every URL
	 * untouched; writes a modern-format sibling ("photo.jpg.webp") for the full
	 * image and each thumbnail size. Cleanor_Serve swaps these in at render time
	 * via a <picture> tag. Reverting is just deleting the derivatives.
	 *
	 * @return array|WP_Error  { metadata, stats }
	 */
	private function process_keep( $attachment_id, $metadata, $full_path, $source_mime ) {
		$fmt = $this->delivery_format();

		// No point generating a derivative in the format the source already is.
		if ( 'image/' . $fmt === $source_mime ) {
			return new WP_Error( 'cleanor_skip', __( 'Already in the delivery format.', 'cleanor-tools' ) );
		}

		// Re-encoding (e.g. converting WebP -> AVIF) leaves the old copies behind;
		// remove any previously recorded derivatives before writing the new ones.
		$this->delete_recorded_derivatives( $attachment_id );

		$dir             = trailingslashit( dirname( $full_path ) );
		$total_original  = 0;
		$total_optimized = 0;
		$derivatives     = array();
		$quality         = (int) $this->settings->get( 'quality' );

		// --- Full-size derivative ---
		$dest = $this->derivative_path( $full_path, $fmt );
		$full = $this->convert_file( $full_path, $source_mime, $fmt, $quality, 0, $dest );
		if ( is_wp_error( $full ) ) {
			return $full;
		}
		$total_original     += $full['original'];
		$total_optimized    += $full['optimized'];
		$derivatives['full'] = basename( $full['new_path'] );

		// --- Thumbnail derivatives ---
		if ( $this->settings->get( 'optimize_sizes' ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $name => $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$size_path = $dir . $size['file'];
				if ( ! file_exists( $size_path ) ) {
					continue;
				}
				$size_mime = ! empty( $size['mime-type'] ) ? $size['mime-type'] : $source_mime;
				if ( 'image/' . $fmt === $size_mime ) {
					continue;
				}
				$sdest = $this->derivative_path( $size_path, $fmt );
				$conv  = $this->convert_file( $size_path, $size_mime, $fmt, $quality, 0, $sdest );
				if ( is_wp_error( $conv ) ) {
					continue; // Skip this size, keep going.
				}
				$total_original      += $conv['original'];
				$total_optimized     += $conv['optimized'];
				$derivatives[ $name ] = basename( $conv['new_path'] );
			}
		}

		$saved_pct = $total_original > 0 ? (int) round( ( 1 - $total_optimized / $total_original ) * 100 ) : 0;
		$stats     = array(
			'original'  => $total_original,
			'optimized' => $total_optimized,
			'saved_pct' => $saved_pct,
			'format'    => $fmt,
		);

		update_post_meta( $attachment_id, '_cleanor_optimized', 1 );
		update_post_meta( $attachment_id, '_cleanor_original_bytes', $total_original );
		update_post_meta( $attachment_id, '_cleanor_optimized_bytes', $total_optimized );
		update_post_meta( $attachment_id, '_cleanor_saved_pct', $saved_pct );
		update_post_meta( $attachment_id, '_cleanor_format', $fmt );
		update_post_meta( $attachment_id, '_cleanor_delivery', 'keep' );
		update_post_meta( $attachment_id, '_cleanor_derivative_format', 'image/' . $fmt );
		update_post_meta( $attachment_id, '_cleanor_derivatives', $derivatives );
		// Keep-mode reverts by deleting derivatives, not from a .bak.
		delete_post_meta( $attachment_id, '_cleanor_has_backup' );

		$this->settings->add_savings( $total_original, $total_optimized );

		/** Fires after an attachment is optimized. Extension point for plugins. */
		do_action( 'cleanor_after_optimize', $attachment_id, $stats, $metadata );

		return array(
			'metadata' => $metadata,
			'stats'    => $stats,
		);
	}

	/** The next-gen format used for non-destructive delivery (webp unless AVIF is chosen). */
	private function delivery_format() {
		return ( 'avif' === $this->settings->get( 'format' ) ) ? 'avif' : 'webp';
	}

	/** Sibling derivative path: "photo.jpg" + ".webp" = "photo.jpg.webp". */
	private function derivative_path( $abs_path, $fmt ) {
		$ext = 'jpeg' === $fmt ? 'jpg' : $fmt;
		return $abs_path . '.' . $ext;
	}

	/**
	 * Convert a single file. Writes a new file only if it is smaller (unless the
	 * `cleanor_replace_when_larger` filter says otherwise).
	 *
	 * @param string|null $dest_path Explicit output path. When null, the path is
	 *                               derived by swapping the extension (replace mode).
	 * @return array|WP_Error  { new_path, mime, original, optimized, saved_pct }
	 */
	private function convert_file( $abs_path, $source_mime, $target_format, $quality, $width = 0, $dest_path = null ) {
		$fs = $this->fs();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}
		$bytes = $fs->get_contents( $abs_path );
		if ( false === $bytes ) {
			return new WP_Error( 'cleanor_read', __( 'Could not read file.', 'cleanor-tools' ) );
		}

		/** Allow per-file overrides of the target format / quality. */
		$target_format = apply_filters( 'cleanor_target_format', $target_format, $abs_path, $source_mime );
		$quality       = (int) apply_filters( 'cleanor_quality', $quality, $abs_path, $source_mime );

		$strip  = (bool) $this->settings->get( 'strip_metadata' );
		$result = $this->encode( $bytes, $source_mime, $target_format, $quality, $width > 0 ? (int) $width : null, $strip );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$original       = strlen( $bytes );
		$optimized      = strlen( $result['bytes'] );
		$replace_larger = (bool) apply_filters( 'cleanor_replace_when_larger', false, $abs_path );
		if ( $optimized >= $original && ! $replace_larger ) {
			return new WP_Error( 'cleanor_no_gain', __( 'No size reduction.', 'cleanor-tools' ) );
		}

		$new_path = $dest_path ? $dest_path : $this->target_path( $abs_path, $target_format );
		$written  = $fs->put_contents( $new_path, $result['bytes'], FS_CHMOD_FILE );
		if ( ! $written ) {
			return new WP_Error( 'cleanor_write', __( 'Could not write optimized file.', 'cleanor-tools' ) );
		}

		$out = array(
			'new_path'  => $new_path,
			'mime'      => $result['mime'],
			'original'  => $original,
			'optimized' => $optimized,
			'saved_pct' => $result['saved_pct'],
		);

		// If we asked for a resize, read back the new dimensions so the caller can
		// keep WordPress's attachment metadata (width/height/srcset) accurate.
		if ( $width > 0 ) {
			$dims = wp_getimagesize( $new_path );
			if ( is_array( $dims ) && ! empty( $dims[0] ) && ! empty( $dims[1] ) ) {
				$out['width']  = (int) $dims[0];
				$out['height'] = (int) $dims[1];
			}
		}

		return $out;
	}

	/** Map settings + source MIME to a target format, or null to skip. */
	private function resolve_target( $source_mime ) {
		$format = $this->settings->get( 'format' );
		if ( 'webp' === $format || 'avif' === $format ) {
			return $format;
		}
		// 'keep' → recompress in the same family (only where the API can output it).
		$map = array(
			'image/jpeg' => 'jpeg',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		);
		return isset( $map[ $source_mime ] ) ? $map[ $source_mime ] : null;
	}

	/** Compute the output path for a target format (may equal the input path). */
	private function target_path( $abs_path, $target_format ) {
		$ext = 'jpeg' === $target_format ? 'jpg' : $target_format;
		return preg_replace( '/\.[^.\/]+$/', '.' . $ext, $abs_path );
	}

	/**
	 * Delete the sibling WebP/AVIF copies recorded in `_cleanor_derivatives`
	 * (used before re-encoding or when switching to replace mode).
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function delete_recorded_derivatives( $attachment_id ) {
		$derivs = get_post_meta( $attachment_id, '_cleanor_derivatives', true );
		if ( empty( $derivs ) || ! is_array( $derivs ) ) {
			return;
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return;
		}
		$dir = trailingslashit( dirname( $file ) );
		foreach ( $derivs as $basename ) {
			$basename = (string) $basename;
			if ( '' === $basename ) {
				continue;
			}
			$path = $dir . $basename;
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/** Delete or (if configured) keep a .bak of a superseded original. */
	private function retire_original( $abs_path ) {
		if ( ! file_exists( $abs_path ) ) {
			return;
		}
		if ( $this->settings->get( 'keep_original' ) ) {
			$fs = $this->fs();
			if ( ! is_wp_error( $fs ) ) {
				$fs->move( $abs_path, $abs_path . '.bak', true );
				return;
			}
		}
		wp_delete_file( $abs_path );
	}

	/**
	 * Lazily initialize WP_Filesystem (direct method on most hosts).
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

	// --- Media list-table savings column -------------------------------------

	public function media_column( $columns ) {
		$columns['cleanor'] = __( 'Cleanor', 'cleanor-tools' );
		return $columns;
	}

	public function media_column_value( $column_name, $attachment_id ) {
		if ( 'cleanor' !== $column_name ) {
			return;
		}
		if ( ! get_post_meta( $attachment_id, '_cleanor_optimized', true ) ) {
			echo '<span style="color:#a7aaad;">&mdash;</span>';
			return;
		}
		$pct = (int) get_post_meta( $attachment_id, '_cleanor_saved_pct', true );
		echo '<span style="color:#008a20;font-weight:600;">' . esc_html( sprintf( /* translators: %d: percent saved */ __( '-%d%%', 'cleanor-tools' ), $pct ) ) . '</span>';
	}

	// --- Media list-table status filter --------------------------------------

	/** Render the "Cleanor status" dropdown above the Media list table. */
	public function media_filter_ui( $post_type = '' ) {
		global $pagenow;
		if ( 'upload.php' !== $pagenow ) {
			return;
		}
		$current = isset( $_GET['cleanor_status'] ) ? sanitize_key( wp_unslash( $_GET['cleanor_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$options = array(
			''            => __( 'Cleanor: all images', 'cleanor-tools' ),
			'optimized'   => __( 'Cleanor: optimized', 'cleanor-tools' ),
			'unoptimized' => __( 'Cleanor: not optimized', 'cleanor-tools' ),
			'restorable'  => __( 'Cleanor: restorable', 'cleanor-tools' ),
		);
		echo '<label class="screen-reader-text" for="cleanor_status">' . esc_html__( 'Filter by Cleanor status', 'cleanor-tools' ) . '</label>';
		echo '<select name="cleanor_status" id="cleanor_status">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/** Apply the "Cleanor status" filter to the main Media list query. */
	public function media_filter_query( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'upload.php' !== $pagenow || ! $query->is_main_query() ) {
			return;
		}
		$status = isset( $_GET['cleanor_status'] ) ? sanitize_key( wp_unslash( $_GET['cleanor_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $status ) {
			return;
		}
		switch ( $status ) {
			case 'optimized':
				$meta = array(
					array(
						'key'     => '_cleanor_optimized',
						'compare' => 'EXISTS',
					),
				);
				break;
			case 'unoptimized':
				$meta = array(
					array(
						'key'     => '_cleanor_optimized',
						'compare' => 'NOT EXISTS',
					),
				);
				break;
			case 'restorable':
				$meta = array(
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
				break;
			default:
				return;
		}
		$query->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	// --- Media list-table row action -----------------------------------------

	public function row_action( $actions, $post ) {
		if ( strpos( (string) $post->post_mime_type, 'image/' ) !== 0 ) {
			return $actions;
		}
		$url                         = wp_nonce_url(
			admin_url( 'admin.php?action=cleanor_optimize&attachment=' . $post->ID ),
			'cleanor_optimize_' . $post->ID
		);
		$actions['cleanor_optimize'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Optimize (Cleanor)', 'cleanor-tools' ) . '</a>';
		return $actions;
	}

	public function handle_row_action() {
		$id = isset( $_GET['attachment'] ) ? (int) $_GET['attachment'] : 0;
		check_admin_referer( 'cleanor_optimize_' . $id );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cleanor-tools' ) );
		}
		$this->optimize_attachment( $id, true );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' ) );
		exit;
	}

	// --- Media list-table Bulk Actions ---------------------------------------

	/** Add "Optimize with Cleanor" to the Bulk Actions dropdown on upload.php. */
	public function register_bulk_action( $actions ) {
		$actions['cleanor_optimize'] = __( 'Optimize with Cleanor', 'cleanor-tools' );
		return $actions;
	}

	/**
	 * Run the bulk optimize. Loops the selected attachments, optimizing images
	 * only, and passes a result tally back through the redirect URL.
	 *
	 * @param string $redirect  The URL WordPress will redirect to.
	 * @param string $action    The chosen bulk action.
	 * @param int[]  $post_ids  Selected attachment IDs.
	 * @return string           The (augmented) redirect URL.
	 */
	public function handle_bulk_action( $redirect, $action, $post_ids ) {
		if ( 'cleanor_optimize' !== $action ) {
			return $redirect;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return $redirect;
		}

		$done    = 0;
		$skipped = 0;
		$failed  = 0;
		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( strpos( (string) get_post_mime_type( $post_id ), 'image/' ) !== 0 ) {
				continue;
			}
			$result = $this->optimize_attachment( $post_id, true );
			if ( is_wp_error( $result ) ) {
				$code = $result->get_error_code();
				if ( 'cleanor_no_gain' === $code || 'cleanor_skip' === $code || 'cleanor_already' === $code ) {
					++$skipped;
				} else {
					++$failed;
				}
				continue;
			}
			++$done;
		}

		return add_query_arg(
			array(
				'cleanor_done'    => $done,
				'cleanor_skipped' => $skipped,
				'cleanor_failed'  => $failed,
			),
			$redirect
		);
	}

	/** Show the outcome of a bulk optimize as an admin notice. */
	public function bulk_notice() {
		if ( ! isset( $_REQUEST['cleanor_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$done    = isset( $_REQUEST['cleanor_done'] ) ? (int) $_REQUEST['cleanor_done'] : 0;       // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = isset( $_REQUEST['cleanor_skipped'] ) ? (int) $_REQUEST['cleanor_skipped'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$failed  = isset( $_REQUEST['cleanor_failed'] ) ? (int) $_REQUEST['cleanor_failed'] : 0;   // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$parts   = array();
		$parts[] = sprintf( /* translators: %d: number of images */ _n( '%d image optimized', '%d images optimized', $done, 'cleanor-tools' ), $done );
		if ( $skipped ) {
			$parts[] = sprintf( /* translators: %d: number skipped */ _n( '%d skipped', '%d skipped', $skipped, 'cleanor-tools' ), $skipped );
		}
		if ( $failed ) {
			$parts[] = sprintf( /* translators: %d: number failed */ _n( '%d failed', '%d failed', $failed, 'cleanor-tools' ), $failed );
		}

		$class = $failed ? 'notice-warning' : 'notice-success';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s %3$s</p></div>',
			esc_attr( $class ),
			esc_html__( 'Cleanor:', 'cleanor-tools' ),
			esc_html( implode( ', ', $parts ) . '.' )
		);
	}
}
