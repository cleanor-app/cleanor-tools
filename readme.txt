=== Cleanor Tools ===
Contributors: cleanor
Tags: image optimization, webp, avif, compress images, performance
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The no-signup image optimizer: auto-convert your Media Library to WebP or AVIF with no account, no API key and no per-image credits. Faster Core Web Vitals.

== Description ==

Most WordPress image optimizers make you create an account, paste an API key, or buy per-image credits. **Cleanor Tools** doesn't. It shrinks the images in your Media Library and converts them to modern formats (WebP / AVIF) using the free [Cleanor Labs](https://cleanor.app/) endpoint — no signup, no key, no credits. Lighter images mean faster page loads, higher PageSpeed / Core Web Vitals scores, and less storage used.

Point-and-forget: turn it on and every new upload is optimized automatically, thumbnails included. Already have thousands of images? Run the one-click bulk optimizer.

= What makes it different =

* **No account, no API key, no credits.** The default endpoint is free and key-free — you're optimizing within a minute of activating, with nothing to sign up for.
* **AVIF, not just WebP.** Convert to AVIF (the smallest mainstream format) as easily as WebP, straight from the Media Library.
* **Backed by open data.** Our savings figures come from published, reproducible benchmarks (open dataset with a citable DOI), not marketing claims.
* **Full-library bulk mode.** Optimize every existing image with a progress bar you can leave running.
* **Keeps your originals (optional).** Store a `.bak` of every source file so you can revert.
* **Transparent.** A "Cleanor" column in the Media Library shows exactly how much each image shrank, plus a running total on the settings screen.
* **Lightweight.** No giant framework, no tracking, no ads in your dashboard.

= What it does =

1. On upload (or on demand), the full-size image and every generated thumbnail are sent to the Cleanor endpoint.
2. Each is re-encoded to your chosen format and quality.
3. The optimized versions replace the originals in your Media Library, and WordPress serves the smaller files everywhere they are used.
4. Images that would not get smaller are left untouched.

= Formats =

* **WebP**: best browser support; a safe default for almost every site.
* **AVIF**: smallest files; supported by all current major browsers.
* **Recompress (keep format)**: re-encode JPEGs smaller without changing the format or URL.

= Privacy =

Images are transmitted to the optimization endpoint you configure (by default `https://mcp.cleanor.app`) purely to be re-encoded, and only the optimized bytes are returned. Cleanor does not require an account and does not retain your images. You can point the plugin at your own self-hosted endpoint if you prefer. See our privacy policy at https://cleanor.app/privacy.

This plugin is a service integration: it relies on the external Cleanor API to perform optimization. Nothing else is sent.

== Installation ==

1. In your dashboard go to **Plugins → Add New → Upload Plugin**, choose `cleanor-tools.zip`, and click **Install Now**. (Or copy the `cleanor-tools` folder into `wp-content/plugins/`.)
2. Click **Activate**.
3. Go to **Settings → Cleanor Tools**, click **Test connection**, pick your format and quality, and **Save Changes**.
4. New uploads are now optimized automatically. To process your existing library, open **Media → Bulk Optimize** and click **Start**.

== Frequently Asked Questions ==

= Do I need an account or API key? =
No. The default endpoint is free and key-free (rate-limited per site). An API-key field is included for future plans and self-hosting.

= Will this break my existing image URLs? =
When converting to WebP/AVIF, the file extension changes and WordPress is updated to serve the new file everywhere it references the attachment. Old direct hot-links to the previous file extension would need updating; content that uses the Media Library normally is handled automatically. Choose "Recompress (keep format)" if you want URLs to stay identical.

= Can I keep my original files? =
Yes. Enable **Keep a .bak copy of the original file** in settings. Each replaced file is preserved as `filename.ext.bak`.

= Does it optimize thumbnails too? =
Yes, when **Also convert generated thumbnail sizes** is enabled (default on).

= Which image types are supported? =
JPEG, PNG, WebP and AVIF sources. GIF and SVG are skipped for safety.

= Can I use my own optimization server? =
Yes. Set the **API endpoint** to any server that implements the Cleanor `/v1/optimize` contract.

= Is it multisite compatible? =
Settings and stats are per-site.

== Screenshots ==

1. Settings screen with format, quality and behavior options, plus your running total saved.
2. Bulk Optimize screen processing the existing Media Library with a live progress bar.
3. The "Cleanor" savings column in the Media Library.

== Changelog ==

= 0.2.0 =
* Admin scripts are now properly enqueued (wp_enqueue_script / wp_localize_script) instead of inline.
* Removed load_plugin_textdomain() (not needed on WordPress 6.0+).

= 0.1.0 =
* Initial release: automatic optimization on upload, bulk optimizer, per-image action, WebP/AVIF/recompress, Media Library savings column, running savings total, optional original backups.

== Upgrade Notice ==

= 0.1.0 =
First public release.
