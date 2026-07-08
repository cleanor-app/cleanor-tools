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

	/** @var array Map of screen key => page hook suffix. */
	private $hooks_map = array();

	public function __construct( Cleanor_Settings $settings, Cleanor_Bulk $bulk, Cleanor_Restore $restore ) {
		$this->settings = $settings;
		$this->bulk     = $bulk;
		$this->restore  = $restore;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
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
		$this->hooks_map['settings'] = add_submenu_page( 'cleanor', __( 'Settings', 'cleanor-tools' ), __( 'Settings', 'cleanor-tools' ), 'manage_options', 'cleanor-settings', array( $this->settings, 'render' ) );
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

		if ( isset( $this->hooks_map['bulk'] ) && $hook === $this->hooks_map['bulk'] ) {
			wp_enqueue_script( 'cleanor-bulk', CLEANOR_TOOLS_URL . 'assets/bulk.js', array(), CLEANOR_TOOLS_VERSION, true );
			wp_localize_script(
				'cleanor-bulk',
				'CleanorBulk',
				array(
					'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
					'nonce'         => wp_create_nonce( 'cleanor_bulk' ),
					'restoreNonce'  => wp_create_nonce( 'cleanor_restore' ),
					'collecting'    => __( 'Collecting images…', 'cleanor-tools' ),
					'nothing'       => __( 'Nothing left to optimize. 🎉', 'cleanor-tools' ),
					'processed'     => __( 'images processed', 'cleanor-tools' ),
					'optimized'     => __( 'optimized', 'cleanor-tools' ),
					'restoring'     => __( 'Restoring originals…', 'cleanor-tools' ),
					'restored'      => __( 'restored', 'cleanor-tools' ),
					'restoreDone'   => __( 'Originals restored.', 'cleanor-tools' ),
					'noBackups'     => __( 'No backed-up originals to restore.', 'cleanor-tools' ),
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
			'settings'  => array( __( 'Settings', 'cleanor-tools' ), 'cleanor-settings' ),
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
		$o        = $this->settings->all();
		$stats    = $this->settings->stats();
		$saved    = max( 0, (int) $stats['original'] - (int) $stats['optimized'] );
		$pct      = $stats['original'] > 0 ? (int) round( ( 1 - $stats['optimized'] / $stats['original'] ) * 100 ) : 0;
		$pending  = $this->bulk->count_pending();
		$backups  = $this->restore->count_backups();
		$count    = (int) $stats['count'];

		self::header( 'dashboard', __( 'Cleanor Tools', 'cleanor-tools' ) );

		// Hero.
		if ( $count > 0 ) {
			echo '<div class="cleanor-card cleanor-hero">';
			echo '<div class="cleanor-hero-main">';
			echo '<div class="cleanor-big">' . esc_html( size_format( $saved, 1 ) ) . '<small>' . esc_html__( 'saved', 'cleanor-tools' ) . '</small></div>';
			echo '<div class="cleanor-hero-sub">' . esc_html(
				sprintf(
					/* translators: %d: number of images */
					__( 'across %d images optimized so far.', 'cleanor-tools' ),
					$count
				)
			) . '</div>';
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
			echo '<div class="cleanor-big">' . esc_html__( 'Welcome 👋', 'cleanor-tools' ) . '</div>';
			echo '<div class="cleanor-hero-sub">' . esc_html__( 'Pick a format and quality, then optimize your Media Library. No account or API key required.', 'cleanor-tools' ) . '</div>';
			echo '<a class="button button-primary cleanor-btn" href="' . esc_url( admin_url( 'admin.php?page=cleanor-settings' ) ) . '">' . esc_html__( 'Get started', 'cleanor-tools' ) . '</a>';
			echo '</div></div>';
		}

		// Stat tiles.
		echo '<div class="cleanor-tiles">';
		self::tile( number_format_i18n( $count ), __( 'Images optimized', 'cleanor-tools' ), 'is-accent' );
		self::tile( number_format_i18n( $pending ), __( 'Pending', 'cleanor-tools' ), '' );
		self::tile( $pct . '%', __( 'Average reduction', 'cleanor-tools' ), 'is-green' );
		self::tile( number_format_i18n( $backups ), __( 'Originals backed up', 'cleanor-tools' ), '' );
		echo '</div>';

		// Configuration summary.
		$fmt_labels = array(
			'webp' => __( 'WebP', 'cleanor-tools' ),
			'avif' => __( 'AVIF', 'cleanor-tools' ),
			'keep' => __( 'Recompress (keep format)', 'cleanor-tools' ),
		);
		$fmt = isset( $fmt_labels[ $o['format'] ] ) ? $fmt_labels[ $o['format'] ] : $o['format'];
		echo '<div class="cleanor-card">';
		echo '<h2>' . esc_html__( 'Configuration', 'cleanor-tools' ) . '</h2>';
		echo '<p class="cleanor-sub">' . esc_html(
			sprintf(
				/* translators: 1: output format, 2: quality, 3: on/off auto */
				__( 'Output %1$s at quality %2$d. Auto-optimize new uploads is %3$s.', 'cleanor-tools' ),
				$fmt,
				(int) $o['quality'],
				$o['optimize_on_upload'] ? __( 'on', 'cleanor-tools' ) : __( 'off', 'cleanor-tools' )
			)
		) . '</p>';
		echo '<p><span class="cleanor-badge ' . ( $o['optimize_on_upload'] ? 'is-ok' : 'is-idle' ) . '">' . esc_html( $o['optimize_on_upload'] ? __( 'Auto-optimize active', 'cleanor-tools' ) : __( 'Auto-optimize off', 'cleanor-tools' ) ) . '</span></p>';
		echo '<p class="cleanor-links">';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=cleanor-settings' ) ) . '">' . esc_html__( 'Edit settings', 'cleanor-tools' ) . '</a>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=cleanor-bulk' ) ) . '">' . esc_html__( 'Bulk optimize', 'cleanor-tools' ) . '</a>';
		echo '<a href="' . esc_url( admin_url( 'upload.php' ) ) . '">' . esc_html__( 'Open Media Library', 'cleanor-tools' ) . '</a>';
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
