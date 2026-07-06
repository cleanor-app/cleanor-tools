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

	/** Source MIME types we can read + convert. */
	const READABLE = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' );

	public function __construct( Cleanor_Settings $settings, Cleanor_API $api ) {
		$this->settings = $settings;
		$this->api      = $api;
	}

	public function hooks() {
		// Runs after WP has created all image sizes; we mutate + return metadata.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_upload' ), 20, 2 );
		// "Optimize" row action in the Media list table.
		add_filter( 'media_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'admin_action_cleanor_optimize', array( $this, 'handle_row_action' ) );
		// "Cleanor" savings column in the Media list table.
		add_filter( 'manage_media_columns', array( $this, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'media_column_value' ), 10, 2 );
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

		$target = $this->resolve_target( $source_mime );
		if ( null === $target ) {
			return new WP_Error( 'cleanor_skip', __( 'Nothing to do for this format.', 'cleanor-tools' ) );
		}

		$dir              = trailingslashit( dirname( $full_path ) );
		$total_original   = 0;
		$total_optimized  = 0;

		// --- Full-size image ---
		$full = $this->convert_file( $full_path, $source_mime, $target, (int) $this->settings->get( 'quality' ) );
		if ( is_wp_error( $full ) ) {
			return $full;
		}
		$total_original  += $full['original'];
		$total_optimized += $full['optimized'];

		if ( $full['new_path'] !== $full_path ) {
			// Extension changed: repoint the attachment and update its MIME.
			$uploads   = wp_get_upload_dir();
			$new_rel   = ltrim( str_replace( $uploads['basedir'], '', $full['new_path'] ), '/\\' );
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

		$this->settings->add_savings( $total_original, $total_optimized );

		/** Fires after an attachment is optimized. Extension point for plugins. */
		do_action( 'cleanor_after_optimize', $attachment_id, $stats, $metadata );

		return array(
			'metadata' => $metadata,
			'stats'    => $stats,
		);
	}

	/**
	 * Convert a single file. Writes a new file only if it is smaller (unless the
	 * `cleanor_replace_when_larger` filter says otherwise).
	 *
	 * @return array|WP_Error  { new_path, mime, original, optimized, saved_pct }
	 */
	private function convert_file( $abs_path, $source_mime, $target_format, $quality ) {
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

		$result = $this->api->optimize_bytes( $bytes, $source_mime, $target_format, $quality );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$original  = strlen( $bytes );
		$optimized = strlen( $result['bytes'] );
		$replace_larger = (bool) apply_filters( 'cleanor_replace_when_larger', false, $abs_path );
		if ( $optimized >= $original && ! $replace_larger ) {
			return new WP_Error( 'cleanor_no_gain', __( 'No size reduction.', 'cleanor-tools' ) );
		}

		$new_path = $this->target_path( $abs_path, $target_format );
		$written  = $fs->put_contents( $new_path, $result['bytes'], FS_CHMOD_FILE );
		if ( ! $written ) {
			return new WP_Error( 'cleanor_write', __( 'Could not write optimized file.', 'cleanor-tools' ) );
		}

		return array(
			'new_path'  => $new_path,
			'mime'      => $result['mime'],
			'original'  => $original,
			'optimized' => $optimized,
			'saved_pct' => $result['saved_pct'],
		);
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

	// --- Media list-table row action -----------------------------------------

	public function row_action( $actions, $post ) {
		if ( strpos( (string) $post->post_mime_type, 'image/' ) !== 0 ) {
			return $actions;
		}
		$url = wp_nonce_url(
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
}
