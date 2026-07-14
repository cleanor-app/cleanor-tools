<?php
/**
 * Branded admin cabinet: top-level menu, dashboard, shared header/nav chrome,
 * and centralized asset enqueuing for all Cleanor screens.
 *
 * @package Cleanor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cleanor_Admin {

	/** @var Cleanor_Settings */
	private $settings;
	/** @var Cleanor_Bulk */
	private $bulk;
	/** @var Cleanor_Restore */
	private $restore;
	/** @var Cleanor_Cleanup */
	private $cleanup;

	/** @var array Map of screen key => page hook suffix. */
	private $hooks_map = array();

	public function __construct( Cleanor_Settings $settings, Cleanor_Bulk $bulk, Cleanor_Restore $restore, Cleanor_Cleanup $cleanup ) {
		$this->settings = $settings;
		$this->bulk     = $bulk;
		$this->restore  = $restore;
		$this->cleanup  = $cleanup;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_cleanor_psi', array( $this, 'ajax_psi' ) );
	}

	/**
	 * Fetch the site's PageSpeed Insights performance score (mobile) via the
	 * Google API, using the key saved in Settings. Shown inline on the Dashboard.
	 */
	public function ajax_psi() {
		check_ajax_referer( 'cleanor_psi' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'cleanor-tools' ) ) );
		}
		$key = trim( (string) $this->settings->get( 'psi_api_key' ) );
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Add a PageSpeed Insights API key in Settings first.', 'cleanor-tools' ) ) );
		}
		$api = add_query_arg(
			array(
				'url'      => rawurlencode( home_url( '/' ) ),
				'key'      => rawurlencode( $key ),
				'strategy' => 'mobile',
				'category' => 'performance',
			),
			'https://www.googleapis.com/pagespeedonline/v5/runPagespeed'
		);
		$res = wp_remote_get( $api, array( 'timeout' => 60 ) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : sprintf( 'HTTP %d', $code );
			wp_send_json_error( array( 'message' => $msg ) );
		}
		$score = isset( $body['lighthouseResult']['categories']['performance']['score'] )
			? (int) round( $body['lighthouseResult']['categories']['performance']['score'] * 100 )
			: null;
		$lcp   = isset( $body['lighthouseResult']['audits']['largest-contentful-paint']['displayValue'] )
			? $body['lighthouseResult']['audits']['largest-contentful-paint']['displayValue']
			: '';
		wp_send_json_success(
			array(
				'score' => $score,
				'lcp'   => $lcp,
			)
		);
	}

	/** Register the top-level menu and its sub-pages. */
	public function menu() {
		$this->hooks_map['dashboard'] = add_menu_page(
			__( 'Cleanor', 'cleanor-tools' ),
			__( 'Cleanor', 'cleanor-tools' ),
			'manage_options',
			'cleanor',
			array( $this, 'render_dashboard' ),
			self::menu_icon(),
			58
		);
		add_submenu_page( 'cleanor', __( 'Dashboard', 'cleanor-tools' ), __( 'Dashboard', 'cleanor-tools' ), 'manage_options', 'cleanor', array( $this, 'render_dashboard' ) );
		$this->hooks_map['bulk']     = add_submenu_page( 'cleanor', __( 'Bulk Optimize', 'cleanor-tools' ), __( 'Bulk Optimize', 'cleanor-tools' ), 'manage_options', 'cleanor-bulk', array( $this->bulk, 'render' ) );
		$this->hooks_map['images']   = add_submenu_page( 'cleanor', __( 'Optimized images', 'cleanor-tools' ), __( 'Images', 'cleanor-tools' ), 'manage_options', 'cleanor-images', array( $this, 'render_images' ) );
		$this->hooks_map['cleanup']  = add_submenu_page( 'cleanor', __( 'CleanUp', 'cleanor-tools' ), __( 'CleanUp', 'cleanor-tools' ), 'manage_options', 'cleanor-cleanup', array( $this->cleanup, 'render' ) );
		$this->hooks_map['settings'] = add_submenu_page( 'cleanor', __( 'Settings', 'cleanor-tools' ), __( 'Settings', 'cleanor-tools' ), 'manage_options', 'cleanor-settings', array( $this->settings, 'render' ) );
		$this->hooks_map['help']     = add_submenu_page( 'cleanor', __( 'How it works', 'cleanor-tools' ), __( 'Help', 'cleanor-tools' ), 'manage_options', 'cleanor-help', array( $this, 'render_help' ) );
	}

	/**
	 * Enqueue the shared stylesheet on every Cleanor screen, plus the per-screen
	 * scripts. Centralized here so the individual screen classes stay lean.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( ! in_array( $hook, $this->hooks_map, true ) ) {
			return;
		}
		wp_enqueue_style( 'cleanor-admin', CLEANOR_TOOLS_URL . 'assets/admin.css', array(), CLEANOR_TOOLS_VERSION );

		if ( isset( $this->hooks_map['dashboard'] ) && $hook === $this->hooks_map['dashboard'] ) {
			wp_enqueue_script( 'cleanor-dash', CLEANOR_TOOLS_URL . 'assets/dashboard.js', array(), CLEANOR_TOOLS_VERSION, true );
			wp_localize_script(
				'cleanor-dash',
				'CleanorDash',
				array(
					'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
					'nonce'      => wp_create_nonce( 'cleanor_psi' ),
					'testing'    => __( 'Measuring… this can take up to a minute.', 'cleanor-tools' ),
					/* translators: %s: PageSpeed performance score 0-100. */
					'scoreLabel' => __( 'Mobile performance: %s / 100', 'cleanor-tools' ),
					'error'      => __( 'Could not fetch the score. Check the API key in Settings.', 'cleanor-tools' ),
				)
			);
		}

		if ( isset( $this->hooks_map['settings'] ) && $hook === $this->hooks_map['settings'] ) {
			wp_enqueue_script( 'cleanor-settings', CLEANOR_TOOLS_URL . 'assets/settings.js', array(), CLEANOR_TOOLS_VERSION, true );
			wp_localize_script(
				'cleanor-settings',
				'CleanorSettings',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'cleanor_test' ),
					'testing' => __( 'Testing…', 'cleanor-tools' ),
				)
			);
		}

		if ( isset( $this->hooks_map['cleanup'] ) && $hook === $this->hooks_map['cleanup'] ) {
			wp_enqueue_script( 'cleanor-cleanup', CLEANOR_TOOLS_URL . 'assets/cleanup.js', array(), CLEANOR_TOOLS_VERSION, true );
			wp_localize_script(
				'cleanor-cleanup',
				'CleanorCleanup',
				array(
					'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
					'nonce'           => wp_create_nonce( 'cleanor_cleanup' ),
					'confirmBackups'  => __( 'Delete all kept .bak originals? This cannot be undone and disables Restore for those images.', 'cleanor-tools' ),
					'confirmScaled'   => __( 'Delete the full-size originals of scaled images? Your images keep working, but you will not be able to re-crop or restore them at full resolution.', 'cleanor-tools' ),
					'working'         => __( 'Deleting backups…', 'cleanor-tools' ),
					'scanning'        => __( 'Scanning for orphaned copies…', 'cleanor-tools' ),
					'removedWord'     => __( 'removed', 'cleanor-tools' ),
					'freedWord'       => __( 'freed', 'cleanor-tools' ),
					'noneWord'        => __( 'Nothing to clean up.', 'cleanor-tools' ),
					'tidy'            => __( 'All tidy', 'cleanor-tools' ),
					'regenerating'    => __( 'Regenerating thumbnails…', 'cleanor-tools' ),
					'regenerated'     => __( 'images regenerated', 'cleanor-tools' ),
					'scanningUnused'  => __( 'Scanning the Media Library…', 'cleanor-tools' ),
					'unusedWord'      => __( 'look unused', 'cleanor-tools' ),
					'trashedWord'     => __( 'moved to Trash', 'cleanor-tools' ),
					'reclaimableWord' => __( 'reclaimable once you empty the Trash', 'cleanor-tools' ),
					'confirmReset'    => __( 'Reset all Cleanor data? This clears optimization status and savings totals for every image. No files are deleted, but Restore markers are removed. You can re-run Bulk Optimize afterwards.', 'cleanor-tools' ),
					'resetting'       => __( 'Resetting…', 'cleanor-tools' ),
					'resetDone'       => __( 'Cleanor data cleared. Run Bulk Optimize to start again.', 'cleanor-tools' ),
					'error'           => __( 'Something went wrong. Please try again.', 'cleanor-tools' ),
				)
			);
		}

		if ( isset( $this->hooks_map['bulk'] ) && $hook === $this->hooks_map['bulk'] ) {
			wp_enqueue_script( 'cleanor-bulk', CLEANOR_TOOLS_URL . 'assets/bulk.js', array(), CLEANOR_TOOLS_VERSION, true );
			wp_localize_script(
				'cleanor-bulk',
				'CleanorBulk',
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'nonce'        => wp_create_nonce( 'cleanor_bulk' ),
					'restoreNonce' => wp_create_nonce( 'cleanor_restore' ),
					'collecting'   => __( 'Collecting images…', 'cleanor-tools' ),
					'nothing'      => __( 'Nothing left to optimize.', 'cleanor-tools' ),
					'processed'    => __( 'images processed', 'cleanor-tools' ),
					'optimized'    => __( 'optimized', 'cleanor-tools' ),
					'converted'    => __( 'converted', 'cleanor-tools' ),
					'restoring'    => __( 'Restoring originals…', 'cleanor-tools' ),
					'restored'     => __( 'restored', 'cleanor-tools' ),
					'restoreDone'  => __( 'Originals restored.', 'cleanor-tools' ),
					'noBackups'    => __( 'No backed-up originals to restore.', 'cleanor-tools' ),
				)
			);
		}
	}

	// --- Shared chrome (used by every screen) --------------------------------

	/**
	 * Open a branded page: opening .wrap + header + tab nav.
	 *
	 * @param string $active One of dashboard|bulk|settings.
	 * @param string $title  Screen title.
	 */
	public static function header( $active, $title ) {
		$tabs = array(
			'dashboard' => array( __( 'Dashboard', 'cleanor-tools' ), 'cleanor' ),
			'bulk'      => array( __( 'Bulk Optimize', 'cleanor-tools' ), 'cleanor-bulk' ),
			'images'    => array( __( 'Images', 'cleanor-tools' ), 'cleanor-images' ),
			'cleanup'   => array( __( 'CleanUp', 'cleanor-tools' ), 'cleanor-cleanup' ),
			'settings'  => array( __( 'Settings', 'cleanor-tools' ), 'cleanor-settings' ),
			'help'      => array( __( 'Help', 'cleanor-tools' ), 'cleanor-help' ),
		);
		echo '<div class="wrap cleanor-wrap">';
		echo '<div class="cleanor-head">';
		echo '<span class="cleanor-logo">' . self::brush_svg( '#ffffff' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static trusted SVG.
		echo '<div><h1>' . esc_html( $title ) . '<span class="cleanor-ver">v' . esc_html( CLEANOR_TOOLS_VERSION ) . '</span></h1>';
		echo '<p class="cleanor-tag">' . esc_html__( 'Free WebP & AVIF image optimizer by Cleanor Labs.', 'cleanor-tools' ) . '</p></div>';
		echo '</div>';
		echo '<nav class="cleanor-nav">';
		foreach ( $tabs as $key => $tab ) {
			$cls = $key === $active ? ' class="is-active"' : '';
			printf(
				'<a href="%s"%s>%s</a>',
				esc_url( admin_url( 'admin.php?page=' . $tab[1] ) ),
				$cls, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal.
				esc_html( $tab[0] )
			);
		}
		echo '</nav>';
	}

	public static function footer() {
		echo '</div>'; // .wrap
	}

	// --- Dashboard -----------------------------------------------------------

	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o       = $this->settings->all();
		$stats   = $this->settings->stats();
		$saved   = max( 0, (int) $stats['original'] - (int) $stats['optimized'] );
		$pct     = $stats['original'] > 0 ? (int) round( ( 1 - $stats['optimized'] / $stats['original'] ) * 100 ) : 0;
		$pending = $this->bulk->count_pending();
		$backups = $this->restore->count_backups();
		$count   = (int) $stats['count'];
		$keep    = ( 'keep' === $o['delivery'] );
		$total   = $this->bulk->count_images();
		$covered = $total > 0 ? (int) round( min( $count, $total ) / $total * 100 ) : 0;

		self::header( 'dashboard', __( 'Cleanor', 'cleanor-tools' ) );

		// Hero.
		if ( $count > 0 ) {
			echo '<div class="cleanor-card cleanor-hero">';
			echo '<div class="cleanor-hero-main">';
			$saved_word = $keep ? __( 'saved in transfer', 'cleanor-tools' ) : __( 'saved', 'cleanor-tools' );
			echo '<div class="cleanor-big">' . esc_html( size_format( $saved, 1 ) ) . '<small>' . esc_html( $saved_word ) . '</small></div>';
			if ( $keep ) {
				echo '<div class="cleanor-hero-sub">' . esc_html(
					sprintf(
						/* translators: %d: number of images */
						__( 'across %d images. Originals stay on disk unchanged; visitors are served the smaller WebP/AVIF copy.', 'cleanor-tools' ),
						$count
					)
				) . '</div>';
			} else {
				echo '<div class="cleanor-hero-sub">' . esc_html(
					sprintf(
						/* translators: %d: number of images */
						__( 'across %d images optimized so far.', 'cleanor-tools' ),
						$count
					)
				) . '</div>';
			}
			// Coverage bar: how much of the library is optimized.
			if ( $total > 0 ) {
				echo '<div class="cleanor-cov">';
				echo '<div class="cleanor-cov-head"><span>' . esc_html(
					sprintf(
						/* translators: 1: optimized count, 2: total images */
						__( '%1$s of %2$s images optimized', 'cleanor-tools' ),
						number_format_i18n( min( $count, $total ) ),
						number_format_i18n( $total )
					)
				) . '</span><span>' . esc_html( $covered ) . '%</span></div>';
				echo '<div class="cleanor-cov-bar"><span style="width:' . esc_attr( $covered ) . '%"></span></div>';
				echo '</div>';
			}
			if ( $pending > 0 ) {
				echo '<a class="button button-primary cleanor-btn" href="' . esc_url( admin_url( 'admin.php?page=cleanor-bulk' ) ) . '">' . esc_html(
					sprintf(
						/* translators: %d: pending image count */
						_n( 'Optimize %d pending image', 'Optimize %d pending images', $pending, 'cleanor-tools' ),
						$pending
					)
				) . '</a>';
			}
			echo '</div>';
			echo '<div class="cleanor-ring" style="--pct:' . esc_attr( $pct ) . '"><span>' . esc_html( $pct ) . '%<em>' . esc_html__( 'smaller', 'cleanor-tools' ) . '</em></span></div>';
			echo '</div>';
		} else {
			echo '<div class="cleanor-card cleanor-hero">';
			echo '<div class="cleanor-hero-main">';
			echo '<div class="cleanor-welcome">' . esc_html__( 'Welcome to Cleanor', 'cleanor-tools' ) . '</div>';
			echo '<div class="cleanor-hero-sub">' . esc_html__( 'Pick a format and quality, then optimize your Media Library. No account or API key required.', 'cleanor-tools' ) . '</div>';
			echo '<a class="button button-primary cleanor-btn" href="' . esc_url( admin_url( 'admin.php?page=cleanor-settings' ) ) . '">' . esc_html__( 'Get started', 'cleanor-tools' ) . '</a>';
			echo '</div></div>';
		}

		// Stat tiles.
		echo '<div class="cleanor-tiles">';
		self::tile( number_format_i18n( $count ), __( 'Images optimized', 'cleanor-tools' ), 'is-accent' );
		self::tile( number_format_i18n( $pending ), __( 'Pending', 'cleanor-tools' ), '' );
		self::tile( $pct . '%', __( 'Average reduction', 'cleanor-tools' ), 'is-green' );
		self::tile( number_format_i18n( $backups ), __( 'Restorable images', 'cleanor-tools' ), '' );
		echo '</div>';

		// Speed: the plugin's whole point, plus a live PageSpeed check.
		$psi     = 'https://pagespeed.web.dev/analysis?url=' . rawurlencode( home_url( '/' ) ) . '&form_factor=mobile';
		$psi_key = trim( (string) $o['psi_api_key'] );
		echo '<div class="cleanor-card">';
		echo '<h2>' . esc_html__( 'Faster pages are the whole point', 'cleanor-tools' ) . '</h2>';
		echo '<p class="cleanor-note">' . esc_html__( 'Cleanor exists to speed up your site. Images are usually the heaviest thing on a page, so shrinking them to modern formats (WebP/AVIF) at the right dimensions makes pages load faster, improves your Google PageSpeed and Core Web Vitals scores, and lightens what visitors download. Cleanor also preloads your hero image so it appears sooner.', 'cleanor-tools' ) . '</p>';
		echo '<p class="cleanor-actions">';
		echo '<a class="button cleanor-btn-blue" href="' . esc_url( $psi ) . '" target="_blank" rel="noreferrer noopener">' . esc_html__( 'Test my site on PageSpeed Insights', 'cleanor-tools' ) . '</a>';
		if ( '' !== $psi_key ) {
			echo '<button class="button" id="cleanor-psi-run">' . esc_html__( 'Check my score here', 'cleanor-tools' ) . '</button>';
			echo '<span id="cleanor-psi-result" class="cleanor-muted"></span>';
		} else {
			echo '<span class="cleanor-muted">' . esc_html__( 'Add a PageSpeed API key in Settings to see your score inline.', 'cleanor-tools' ) . '</span>';
		}
		echo '</p>';
		echo '</div>';

		// How your images are delivered (visual explainer of the active mode).
		$fmt_up = strtoupper( in_array( $o['format'], array( 'webp', 'avif' ), true ) ? $o['format'] : ( $keep ? 'webp' : $o['format'] ) );
		echo '<div class="cleanor-card">';
		echo '<h2>' . esc_html__( 'How your images are delivered', 'cleanor-tools' ) . '</h2>';

		echo '<div class="cleanor-flow">';
		if ( $keep ) {
			echo '<div class="cleanor-flow-step"><div class="cleanor-flow-cap">' . esc_html__( 'Original, kept unchanged', 'cleanor-tools' ) . '</div><div class="cleanor-flow-file">photo.jpg</div></div>';
			echo '<div class="cleanor-flow-arrow">&rarr;</div>';
			echo '<div class="cleanor-flow-step is-served"><div class="cleanor-flow-cap">' . esc_html__( 'Served to visitors', 'cleanor-tools' ) . '</div><div class="cleanor-flow-file">photo.jpg.' . esc_html( strtolower( $fmt_up ) ) . '</div></div>';
		} else {
			echo '<div class="cleanor-flow-step"><div class="cleanor-flow-cap">' . esc_html__( 'Original file', 'cleanor-tools' ) . '</div><div class="cleanor-flow-file">photo.jpg</div></div>';
			echo '<div class="cleanor-flow-arrow">&rarr;</div>';
			echo '<div class="cleanor-flow-step is-served"><div class="cleanor-flow-cap">' . esc_html__( 'Replaces it, URL changes', 'cleanor-tools' ) . '</div><div class="cleanor-flow-file">photo.' . esc_html( strtolower( $fmt_up ) ) . '</div></div>';
		}
		echo '</div>';

		$engine_labels = array(
			'auto'  => __( 'Engine: server first', 'cleanor-tools' ),
			'local' => __( 'Engine: on this server', 'cleanor-tools' ),
			'api'   => __( 'Engine: Cleanor API', 'cleanor-tools' ),
		);
		$engine_lbl    = isset( $engine_labels[ $o['engine'] ] ) ? $engine_labels[ $o['engine'] ] : $engine_labels['auto'];
		echo '<div class="cleanor-strip">';
		echo '<span class="cleanor-badge is-info">' . esc_html( $keep ? __( 'Keep originals', 'cleanor-tools' ) : __( 'Replace files', 'cleanor-tools' ) ) . '</span>';
		echo '<span class="cleanor-badge is-info">' . esc_html( sprintf( /* translators: 1: format, 2: quality */ __( '%1$s, quality %2$d', 'cleanor-tools' ), $fmt_up, (int) $o['quality'] ) ) . '</span>';
		echo '<span class="cleanor-badge is-info">' . esc_html( $engine_lbl ) . '</span>';
		echo '<span class="cleanor-badge ' . ( $o['optimize_on_upload'] ? 'is-ok' : 'is-idle' ) . '">' . esc_html( $o['optimize_on_upload'] ? __( 'Auto-optimize on', 'cleanor-tools' ) : __( 'Auto-optimize off', 'cleanor-tools' ) ) . '</span>';
		echo '</div>';

		echo '<p class="cleanor-note">' . esc_html(
			$keep
				? __( 'In keep mode your files and their sizes stay exactly the same on disk and in the Media Library. The smaller copy is served automatically, so the saving is in what visitors download, not in disk space.', 'cleanor-tools' )
				: __( 'In replace mode each image is converted in place to a smaller file, so the size on disk and in the Media Library goes down too.', 'cleanor-tools' )
		) . '</p>';

		echo '<p class="cleanor-links">';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=cleanor-settings' ) ) . '">' . esc_html__( 'Edit settings', 'cleanor-tools' ) . '</a>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=cleanor-bulk' ) ) . '">' . esc_html__( 'Bulk optimize', 'cleanor-tools' ) . '</a>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=cleanor-images' ) ) . '">' . esc_html__( 'View optimized images', 'cleanor-tools' ) . '</a>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=cleanor-help' ) ) . '">' . esc_html__( 'How it works', 'cleanor-tools' ) . '</a>';
		echo '</p>';
		echo '</div>';

		self::footer();
	}

	private static function tile( $num, $label, $mod ) {
		echo '<div class="cleanor-tile ' . esc_attr( $mod ) . '">';
		echo '<div class="cleanor-tile-num">' . esc_html( $num ) . '</div>';
		echo '<div class="cleanor-tile-lbl">' . esc_html( $label ) . '</div>';
		echo '</div>';
	}

	// --- Optimized images table ----------------------------------------------

	/**
	 * A paginated table of every optimized attachment: preview, before/after
	 * size, saving, and a direct link to the file that is actually served
	 * (the WebP/AVIF copy in keep mode, or the converted file in replace mode),
	 * so it is obvious where the optimized bytes live.
	 */
	public function render_images() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::header( 'images', __( 'Cleanor', 'cleanor-tools' ) );

		$per_page = 40;
		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$q        = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ),
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_cleanor_original_bytes',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		echo '<div class="cleanor-card">';
		echo '<h2>' . esc_html__( 'Optimized images', 'cleanor-tools' ) . '</h2>';
		echo '<p class="cleanor-sub">' . esc_html__( 'Every image Cleanor has processed, with a link to the exact file that is delivered. In "keep originals" mode the smaller copy lives on disk right next to the original (same name plus .webp or .avif) and is served through a <picture> tag, so it does not appear as a separate item in the Media Library.', 'cleanor-tools' ) . '</p>';

		if ( ! $q->have_posts() ) {
			echo '<p>' . esc_html__( 'Nothing optimized yet. Upload an image or run Bulk Optimize.', 'cleanor-tools' ) . '</p>';
			echo '</div>';
			self::footer();
			return;
		}

		echo '<div class="cleanor-tablewrap"><table class="cleanor-table"><thead><tr>';
		foreach ( array(
			__( 'Image', 'cleanor-tools' ),
			__( 'Original', 'cleanor-tools' ),
			__( 'Optimized', 'cleanor-tools' ),
			__( 'Saved', 'cleanor-tools' ),
			__( 'Format', 'cleanor-tools' ),
			__( 'Served copy', 'cleanor-tools' ),
			__( 'Actions', 'cleanor-tools' ),
		) as $th ) {
			echo '<th>' . esc_html( $th ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $q->posts as $post ) {
			$id        = $post->ID;
			$orig      = (int) get_post_meta( $id, '_cleanor_original_bytes', true );
			$opt       = (int) get_post_meta( $id, '_cleanor_optimized_bytes', true );
			$pct       = (int) get_post_meta( $id, '_cleanor_saved_pct', true );
			$format    = (string) get_post_meta( $id, '_cleanor_format', true );
			$delivery  = (string) get_post_meta( $id, '_cleanor_delivery', true );
			$full_url  = wp_get_attachment_url( $id );
			$edit_link = get_edit_post_link( $id );

			// Where the delivered bytes actually live.
			if ( 'keep' === $delivery ) {
				$type       = (string) get_post_meta( $id, '_cleanor_derivative_format', true );
				$ext        = ( 'image/avif' === $type ) ? 'avif' : 'webp';
				$served_url = $full_url ? $full_url . '.' . $ext : '';
				$served_lbl = strtoupper( $ext ) . ' ' . __( 'copy', 'cleanor-tools' );
			} else {
				$served_url = $full_url;
				$served_lbl = __( 'converted file', 'cleanor-tools' );
			}

			echo '<tr>';
			// Image (thumb + title).
			echo '<td class="cl-img"><span class="cl-thumb">' . wp_get_attachment_image( $id, array( 44, 44 ), true ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP-escaped img.
			$title = get_the_title( $id );
			if ( $edit_link ) {
				echo '<a href="' . esc_url( $edit_link ) . '">' . esc_html( $title ) . '</a>';
			} else {
				echo esc_html( $title );
			}
			echo '</td>';
			echo '<td>' . esc_html( $orig ? size_format( $orig, 1 ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $opt ? size_format( $opt, 1 ) : '—' ) . '</td>';
			echo '<td><span class="cl-pct">' . esc_html( sprintf( /* translators: %d: percent */ __( '-%d%%', 'cleanor-tools' ), $pct ) ) . '</span></td>';
			echo '<td>' . esc_html( $format ? strtoupper( $format ) : '—' ) . '</td>';
			echo '<td>';
			if ( $served_url ) {
				echo '<a href="' . esc_url( $served_url ) . '" target="_blank" rel="noreferrer">' . esc_html( $served_lbl ) . '</a>';
			} else {
				echo '—';
			}
			echo '</td>';
			// Actions: restore when possible.
			echo '<td>';
			$restorable = get_post_meta( $id, '_cleanor_has_backup', true ) || 'keep' === $delivery;
			if ( $restorable ) {
				$r = wp_nonce_url( admin_url( 'admin.php?action=cleanor_restore&attachment=' . $id ), 'cleanor_restore_' . $id );
				echo '<a href="' . esc_url( $r ) . '">' . esc_html__( 'Restore', 'cleanor-tools' ) . '</a>';
			} else {
				echo '<span class="cleanor-muted">—</span>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';

		// Pagination.
		$total_pages = (int) $q->max_num_pages;
		if ( $total_pages > 1 ) {
			echo '<p class="cleanor-pager">';
			$base = admin_url( 'admin.php?page=cleanor-images' );
			if ( $paged > 1 ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged - 1, $base ) ) . '">' . esc_html__( 'Previous', 'cleanor-tools' ) . '</a> ';
			}
			echo '<span class="cleanor-muted">' . esc_html( sprintf( /* translators: 1: current page, 2: total pages */ __( 'Page %1$d of %2$d', 'cleanor-tools' ), $paged, $total_pages ) ) . '</span> ';
			if ( $paged < $total_pages ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged + 1, $base ) ) . '">' . esc_html__( 'Next', 'cleanor-tools' ) . '</a>';
			}
			echo '</p>';
		}

		echo '</div>';
		wp_reset_postdata();
		self::footer();
	}

	// --- Help / how it works -------------------------------------------------

	/** A plain-language explanation of what the plugin does and how. */
	public function render_help() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o       = $this->settings->all();
		$keep    = ( 'keep' === $o['delivery'] );
		$stats   = $this->settings->stats();
		$count   = (int) $stats['count'];
		$pending = $this->bulk->count_pending();
		$total   = $this->bulk->count_images();
		$psi     = 'https://pagespeed.web.dev/analysis?url=' . rawurlencode( home_url( '/' ) ) . '&form_factor=mobile';

		self::header( 'help', __( 'Cleanor', 'cleanor-tools' ) );

		// --- Getting-started onboarding (actionable, reflects real state) ---
		echo '<div class="cleanor-card">';
		echo '<h2>' . esc_html__( 'Get set up in 3 steps', 'cleanor-tools' ) . '</h2>';
		echo '<p class="cleanor-sub">' . esc_html__( 'New to Cleanor? Follow these to make your images lighter and prove the speed gain.', 'cleanor-tools' ) . '</p>';
		echo '<div class="cleanor-steps">';

		// Step 1 — settings (defaults are sensible, so treat as done, but invite a review).
		echo '<div class="cleanor-step is-done"><div class="cleanor-step-n">&#10003;</div><div class="cleanor-step-body">';
		echo '<h3>' . esc_html__( '1. Choose format, quality and delivery', 'cleanor-tools' ) . '</h3>';
		echo '<p>' . esc_html__( 'Sensible defaults are already set (WebP, quality 80, keep originals). Adjust them any time in Settings.', 'cleanor-tools' ) . '</p>';
		echo '<div class="cleanor-actions"><a class="button cleanor-btn-blue" href="' . esc_url( admin_url( 'admin.php?page=cleanor-settings' ) ) . '">' . esc_html__( 'Review settings', 'cleanor-tools' ) . '</a>';
		echo '<span class="cleanor-badge is-info">' . esc_html( $keep ? __( 'Keep originals', 'cleanor-tools' ) : __( 'Replace files', 'cleanor-tools' ) ) . '</span></div>';
		echo '</div></div>';

		// Step 2 — optimize existing library.
		$step2_done = ( $total > 0 && 0 === $pending );
		echo '<div class="cleanor-step' . ( $step2_done ? ' is-done' : '' ) . '"><div class="cleanor-step-n">' . ( $step2_done ? '&#10003;' : '2' ) . '</div><div class="cleanor-step-body">';
		echo '<h3>' . esc_html__( '2. Optimize the images you already have', 'cleanor-tools' ) . '</h3>';
		if ( 0 === $total ) {
			echo '<p>' . esc_html__( 'Your Media Library is empty. Upload an image and Cleanor optimizes it automatically; then come back here.', 'cleanor-tools' ) . '</p>';
			echo '<div class="cleanor-actions"><a class="button cleanor-btn-blue" href="' . esc_url( admin_url( 'media-new.php' ) ) . '">' . esc_html__( 'Upload images', 'cleanor-tools' ) . '</a></div>';
		} elseif ( $step2_done ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: number of images */
					_n( 'Done: all %s image is optimized. New uploads are handled automatically.', 'Done: all %s images are optimized. New uploads are handled automatically.', $count, 'cleanor-tools' ),
					number_format_i18n( $count )
				)
			) . '</p>';
			echo '<div class="cleanor-actions"><span class="cleanor-check">' . esc_html__( 'Nothing left to do here', 'cleanor-tools' ) . '</span></div>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: number of pending images */
					_n( '%s image is not optimized yet. Run Bulk Optimize once to process your whole library.', '%s images are not optimized yet. Run Bulk Optimize once to process your whole library.', $pending, 'cleanor-tools' ),
					number_format_i18n( $pending )
				)
			) . '</p>';
			echo '<div class="cleanor-actions"><a class="button button-primary cleanor-btn-blue" href="' . esc_url( admin_url( 'admin.php?page=cleanor-bulk' ) ) . '">' . esc_html__( 'Optimize now', 'cleanor-tools' ) . '</a></div>';
		}
		echo '</div></div>';

		// Step 3 — measure the result with PageSpeed Insights.
		echo '<div class="cleanor-step"><div class="cleanor-step-n">3</div><div class="cleanor-step-body">';
		echo '<h3>' . esc_html__( '3. Measure the speed gain', 'cleanor-tools' ) . '</h3>';
		echo '<p>' . esc_html__( 'Run Google PageSpeed Insights on your site to see the effect on Core Web Vitals. The button below opens it with your address already filled in.', 'cleanor-tools' ) . '</p>';
		echo '<div class="cleanor-actions"><a class="button cleanor-btn-blue" href="' . esc_url( $psi ) . '" target="_blank" rel="noreferrer noopener">' . esc_html__( 'Test my site on PageSpeed Insights', 'cleanor-tools' ) . '</a>';
		echo '<span class="cleanor-muted">' . esc_html( home_url( '/' ) ) . '</span></div>';
		echo '</div></div>';

		echo '</div></div>';

		echo '<h2 class="cleanor-h2">' . esc_html__( 'How it works', 'cleanor-tools' ) . '</h2>';

		$cards = array(
			array(
				__( 'What Cleanor does, and why', 'cleanor-tools' ),
				__( 'Cleanor exists to make your pages faster. Images are usually the heaviest thing on a page, so shrinking them lifts your load time and your Google PageSpeed and Core Web Vitals scores. When you upload an image (or run Bulk Optimize), the full-size image and each thumbnail are re-encoded to a modern format (WebP or AVIF) at your chosen quality. Images that would not get smaller are left alone. Use the "Test my site on PageSpeed Insights" button on the Dashboard to measure the difference.', 'cleanor-tools' ),
			),
			array(
				__( 'On your server, and private', 'cleanor-tools' ),
				__( 'With the default Auto engine, images are re-encoded right on your own server using its built-in image tools (Imagick or GD) and never leave your site. The free Cleanor API is only a fallback, mainly for AVIF on hosts that cannot produce it locally. Choose "On this server only" in Settings to guarantee no external requests at all. Settings shows exactly what your server can do (WebP is supported almost everywhere).', 'cleanor-tools' ),
			),
			array(
				__( 'Two delivery modes', 'cleanor-tools' ),
				__( 'Keep originals, serve modern (the default) never changes your files or their URLs. Cleanor stores a smaller copy next to each image and serves it automatically to browsers that support it, falling back to your original everywhere else. Replace files instead converts the actual file to WebP or AVIF, so the file on disk and its URL change, but you reclaim the storage.', 'cleanor-tools' ),
			),
			array(
				__( 'Where the optimized files live', 'cleanor-tools' ),
				__( 'In keep mode the smaller copy sits on disk right next to the original with the same name plus .webp or .avif (for example photo.jpg.webp). It is a sibling file, not a separate Media Library item, which is why you will not see it listed there. Cleanor swaps it in at render time through a <picture> tag, and also preloads your featured image in that format for a faster Largest Contentful Paint. Open the Images tab to see every processed image with a direct link to the exact file being served. In replace mode the Media Library file itself is the optimized one.', 'cleanor-tools' ),
			),
			array(
				__( 'What about images already in my blog?', 'cleanor-tools' ),
				__( 'Existing posts do not need editing. In keep mode the same original URLs keep working and the modern copy is served automatically wherever those images appear. Run Bulk Optimize once to process everything already in your library, and use Convert to a modern format if you want to move the whole library to WebP or AVIF.', 'cleanor-tools' ),
			),
			array(
				__( 'Reclaiming disk space and cleaning up', 'cleanor-tools' ),
				__( 'By default (keep mode) the saving is in what visitors download, not on disk. To actually free hosting space, switch Delivery to Replace files, run Bulk Optimize, and turn on Resize to cap oversized uploads. The CleanUp tab then removes clutter: kept .bak originals, orphaned WebP/AVIF copies, and the untouched full-size originals WordPress keeps for scaled uploads. It can also scan for images that appear to be used nowhere and delete them, which is permanent, so back up first. You can undo an optimization from the Images tab or Bulk Optimize using Restore while a backup or the original still exists.', 'cleanor-tools' ),
			),
		);

		foreach ( $cards as $card ) {
			echo '<div class="cleanor-card">';
			echo '<h2>' . esc_html( $card[0] ) . '</h2>';
			echo '<p class="cleanor-note">' . esc_html( $card[1] ) . '</p>';
			echo '</div>';
		}

		echo '<div class="cleanor-card"><p class="cleanor-links">';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=cleanor-settings' ) ) . '">' . esc_html( $keep ? __( 'You are in keep-originals mode. Change it in Settings', 'cleanor-tools' ) : __( 'You are in replace-files mode. Change it in Settings', 'cleanor-tools' ) ) . '</a>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=cleanor-bulk' ) ) . '">' . esc_html__( 'Bulk optimize', 'cleanor-tools' ) . '</a>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=cleanor-cleanup' ) ) . '">' . esc_html__( 'CleanUp', 'cleanor-tools' ) . '</a>';
		echo '</p></div>';

		self::footer();
	}

	// --- Brand marks ---------------------------------------------------------

	/**
	 * Inline brand brush glyph (the exact path from the Cleanor app icon).
	 *
	 * @param string $color Fill color.
	 * @return string SVG markup.
	 */
	public static function brush_svg( $color = '#4576fd' ) {
		$c = esc_attr( $color );
		return '<svg viewBox="0 0 10240 10240" fill="' . $c . '" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
			. '<path d="M5117 2071l0 745c0,160 -130,290 -290,290l-2181 0c-136,0 -248,112 -248,249l0 1037c0,137 112,249 248,249l5605 0c137,0 249,-112 249,-249l0 -1037c0,-137 -112,-249 -249,-249l-2181 0c-159,0 -290,-130 -290,-290l0 -745c0,-183 -148,-331 -331,-331 -183,0 -332,148 -332,331zm-2719 4384c0,918 -206,1395 -571,1687 -54,36 -87,97 -87,163 0,108 87,195 195,195 5,0 9,0 14,0 4,0 8,0 12,0l162 0c834,0 1388,-395 1545,-1482 5,-35 41,-30 41,3 0,595 -79,1047 -229,1382 -17,65 21,97 74,97l753 0c599,0 996,-282 1111,-1058 4,-24 31,-26 31,2 0,416 -54,728 -157,967 -14,33 -7,89 67,89l1655 0c930,0 1486,-524 1486,-2045l0 -1011c-7,-180 -150,-321 -324,-324l-5455 0c-173,3 -316,144 -323,324l0 1011z"/>'
			. '</svg>';
	}

	/** Base64 data-URI brush for the admin menu (native grey). @return string */
	private static function menu_icon() {
		return 'data:image/svg+xml;base64,' . base64_encode( self::brush_svg( '#a7aaad' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI.
	}
}
