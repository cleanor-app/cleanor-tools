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

	public function __construct( Cleanor_Settings $settings, Cleanor_Optimizer $optimizer ) {
		$this->settings  = $settings;
		$this->optimizer = $optimizer;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'wp_ajax_cleanor_bulk_list', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_cleanor_bulk_one', array( $this, 'ajax_one' ) );
	}

	public function menu() {
		add_media_page(
			__( 'Bulk Optimize (Cleanor)', 'cleanor-tools' ),
			__( 'Bulk Optimize', 'cleanor-tools' ),
			'upload_files',
			'cleanor-bulk',
			array( $this, 'render' )
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
		$nonce = wp_create_nonce( 'cleanor_bulk' );
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
		<script>
		( function () {
			var start  = document.getElementById( 'cleanor-bulk-start' );
			var bar    = document.getElementById( 'cleanor-bulk-bar' );
			var status = document.getElementById( 'cleanor-bulk-status' );
			var nonce  = <?php echo wp_json_encode( $nonce ); ?>;

			function post( body ) {
				body.append( '_ajax_nonce', nonce );
				return fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } );
			}

			start.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				start.disabled = true;
				status.textContent = <?php echo wp_json_encode( __( 'Collecting images…', 'cleanor-tools' ) ); ?>;
				var list = new FormData();
				list.append( 'action', 'cleanor_bulk_list' );
				post( list ).then( function ( j ) {
					var ids = ( j && j.data && j.data.ids ) || [];
					if ( ! ids.length ) {
						status.textContent = <?php echo wp_json_encode( __( 'Nothing left to optimize. 🎉', 'cleanor-tools' ) ); ?>;
						start.disabled = false;
						return;
					}
					bar.style.display = 'block';
					bar.max = ids.length;
					var done = 0, saved = 0;
					function next() {
						if ( ! ids.length ) {
							status.textContent = done + <?php echo wp_json_encode( ' ' . __( 'images processed.', 'cleanor-tools' ) ); ?>;
							start.disabled = false;
							return;
						}
						var id = ids.shift();
						var one = new FormData();
						one.append( 'action', 'cleanor_bulk_one' );
						one.append( 'id', id );
						post( one ).then( function ( res ) {
							done++;
							bar.value = done;
							if ( res && res.data && typeof res.data.saved_pct !== 'undefined' ) { saved++; }
							status.textContent = done + ' / ' + bar.max + ', ' + saved + <?php echo wp_json_encode( ' ' . __( 'optimized', 'cleanor-tools' ) ); ?>;
							next();
						} ).catch( function () { done++; bar.value = done; next(); } );
					}
					next();
				} );
			} );
		}() );
		</script>
		<?php
	}
}
