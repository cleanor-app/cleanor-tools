=== Cleanor: Image Compressor & Converter ===
Contributors: cleanor
Tags: image optimization, webp, avif, compress images, performance
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free WebP & AVIF image optimizer for your Media Library. No account, no API key, no per-image credits. Faster Core Web Vitals.

== Description ==

Most WordPress image optimizers make you create an account, paste an API key, or buy per-image credits. **Cleanor Tools** doesn't. It shrinks the images in your Media Library and converts them to modern formats (WebP / AVIF) using the free [Cleanor Labs](https://cleanor.app/) endpoint — no signup, no key, no credits. Lighter images mean faster page loads, higher PageSpeed / Core Web Vitals scores, and less storage used.

Point-and-forget: turn it on and every new upload is optimized automatically, thumbnails included. Already have thousands of images? Run the one-click bulk optimizer.

= What makes it different =

* **Non-destructive by default.** Cleanor keeps your original files and URLs exactly as they are and stores a WebP/AVIF copy alongside them, served automatically through a `<picture>` tag. Nothing breaks, and any image reverts in one click. Prefer the classic approach? Switch to "Replace files" mode any time.
* **Optimizes on your own server.** With the default Auto engine, images are re-encoded locally with Imagick or GD and never leave your site; the free Cleanor API is only a fallback (for example AVIF on a host that cannot do it). Prefer total isolation? Choose "On this server only".
* **No account, no API key, no credits.** The default endpoint is free and key-free — you're optimizing within a minute of activating, with nothing to sign up for.
* **AVIF, not just WebP.** Convert to AVIF (the smallest mainstream format) as easily as WebP, straight from the Media Library.
* **Backed by open data.** Our savings figures come from published, reproducible benchmarks (open dataset with a citable DOI), not marketing claims.
* **Full-library bulk mode.** Optimize every existing image with a progress bar you can leave running, or use the native Media Library **Bulk Actions** dropdown to optimize (or restore) a hand-picked selection.
* **Resize on the way in.** Set a maximum width and oversized uploads are downscaled before they are stored, so a 6000px phone photo does not ship at full resolution. Thumbnails are untouched.
* **Strip metadata.** Remove EXIF, GPS and camera data on every conversion for smaller files and better privacy (on by default).
* **Keeps your originals.** A `.bak` of every source file is stored by default so you can revert any image, or the whole library, with one click.
* **Transparent.** A "Cleanor" column in the Media Library shows exactly how much each image shrank, plus a running total on the settings screen. Filter the library by optimized, not-optimized or restorable in one click.
* **Faster LCP for free.** Optionally preloads your featured image (in its modern format) so the hero starts loading sooner and your Largest Contentful Paint score improves, with no extra requests.
* **CleanUp tools.** Reclaim disk space by deleting kept .bak originals and orphaned WebP/AVIF copies whose source image is gone. Sibling copies are also removed automatically when you delete an image.
* **Clear in-plugin help.** A "How it works" screen explains, in plain language, how images are optimized and served, what happens to old images, and how to reclaim space.
* **Lightweight.** No giant framework, no tracking, no ads in your dashboard.

= What it does =

1. On upload (or on demand), the full-size image and every generated thumbnail are sent to the Cleanor endpoint.
2. Each is re-encoded to your chosen format and quality.
3. In the default **Keep originals, serve modern** mode, the optimized copies are stored next to your originals and served to supporting browsers via a `<picture>` tag. Your files, URLs and MIME types never change. (In **Replace files** mode, the optimized versions replace the originals and WordPress serves the smaller files everywhere instead.)
4. Images that would not get smaller are left untouched.

= Formats =

* **WebP**: best browser support; a safe default for almost every site.
* **AVIF**: smallest files; supported by all current major browsers.
* **Recompress (keep format)**: re-encode JPEGs smaller without changing the format or URL.

= Privacy =

By default the Engine setting is Auto, which re-encodes images on your own server (using Imagick or GD) whenever it can. In that case your images never leave your site. The free Cleanor API is used only as a fallback, for example to produce AVIF on a host that cannot do it locally, or if the server has no image library. You can also force "On this server only" to guarantee nothing is ever sent externally, or "Cleanor API only".

When the API is used, images are transmitted to the endpoint you configure (by default `https://mcp.cleanor.app`) purely to be re-encoded, and only the optimized bytes are returned. Cleanor does not require an account and does not retain your images. You can also point the plugin at your own self-hosted endpoint. See our privacy policy at https://cleanor.app/privacy.

== Installation ==

1. In your dashboard go to **Plugins → Add New → Upload Plugin**, choose `cleanor-tools.zip`, and click **Install Now**. (Or copy the `cleanor-tools` folder into `wp-content/plugins/`.)
2. Click **Activate**.
3. Open the new **Cleanor** menu, go to **Settings**, click **Test connection**, pick a compression preset and format, and **Save changes**.
4. New uploads are now optimized automatically. To process your existing library, open **Cleanor → Bulk Optimize** and click **Start optimizing**.

== Frequently Asked Questions ==

= Do I need an account or API key? =
No. With the default Auto engine, images are optimized right on your server and nothing is sent anywhere. If the API fallback is used it is free and key-free (rate-limited per site). An API-key field is included for future plans and self-hosting.

= Does my image get sent to an external service? =
Not with the default Auto engine when your server can handle the format (WebP is supported almost everywhere via Imagick or GD). Images are then re-encoded locally and never leave your site. The Cleanor API is only contacted as a fallback, most often for AVIF on hosts without AVIF support. Choose "On this server only" in Settings to guarantee no external requests at all. Settings shows exactly what your server can do.

= Will this break my existing image URLs? =
No, not in the default **Keep originals, serve modern** mode: your files, extensions and URLs are never changed. Cleanor stores a WebP/AVIF copy beside each image and serves it through a `<picture>` tag, so supporting browsers get the smaller file while everything else falls back to your untouched original. If you switch to **Replace files** mode, the file extension changes and WordPress is updated to serve the new file everywhere it references the attachment (old direct hot-links to the previous extension would need updating).

= What is the difference between "Keep originals" and "Replace files"? =
**Keep originals, serve modern** (default) is non-destructive: originals stay on disk untouched and modern copies are served via `<picture>`. Safest for existing sites and fully reversible, at the cost of a little extra storage for the copies. **Replace files** rewrites the actual file to WebP/AVIF (smallest storage, but URLs/extensions change). You can switch modes at any time in **Cleanor → Settings → Delivery**; already-processed images keep whatever mode they were done in until you re-optimize or restore them.

= Can I keep my original files? =
Yes, and it is on by default. **Keep a .bak copy of the original file** preserves each replaced file as `filename.ext.bak`, which is what powers the Restore actions. Turn it off if you would rather save the disk space.

= Can I downscale huge images automatically? =
Yes. Set **Resize** to a maximum width (for example 2560) in settings and any image wider than that is downscaled before it is stored. Set it to 0 to keep original dimensions. Generated thumbnails are never resized by this setting.

= Does it remove EXIF / GPS metadata? =
Yes, when **Remove EXIF, GPS & camera metadata** is enabled (default on). This makes files a little smaller and keeps location and camera data out of your public uploads.

= Can I optimize just a few images from the Media Library? =
Yes. In the Media Library list view, tick the images you want, then pick **Optimize with Cleanor** (or **Restore original (Cleanor)**) from the **Bulk Actions** dropdown and click Apply. You can also narrow the list first with the **Cleanor status** dropdown (all / optimized / not optimized / restorable).

= Where are the optimized copies, I do not see them in the Media Library? =
That is expected in the default "Keep originals, serve modern" mode. Cleanor does not change your original file, so its size in the Media Library stays the same. Instead it writes a smaller WebP or AVIF copy right next to the original on disk (same name plus `.webp` or `.avif`, for example `photo.jpg.webp`) and serves that copy to visitors through a `<picture>` tag. Because it is a sibling file and not a separate attachment, it does not show up as its own item in the Media Library. To see every processed image with its before and after size and a direct link to the exact file being served, open **Cleanor → Images**. If you would rather the file on disk itself get smaller, switch to "Replace files" mode in Settings.

= Can I convert my whole library to WebP or AVIF at once? =
Yes. Open **Cleanor → Bulk Optimize → Convert to a modern format**, pick WebP or AVIF, and click Convert all images. It re-encodes every image in one pass, following your current delivery mode.

= How do I reclaim disk space, or delete the originals? =
Open **Cleanor → CleanUp**. There you can delete the kept .bak originals (from Replace mode) and remove orphaned WebP/AVIF copies whose source image no longer exists. In the default keep mode your originals are deliberately kept as the fallback served to older browsers, so they are not deleted there; if you want that space back, switch Delivery to "Replace files" in Settings and re-run Bulk Optimize, then delete the resulting backups. When you delete an image from the Media Library, Cleanor now also removes its sibling copies automatically, so nothing is left behind.

= Is removing unused images safe? =
It is designed to be reversible. Cleanor does not erase images: it moves the ones that look unused to the WordPress Trash, where you can restore them for about 30 days, and the files stay on disk (so a wrongly flagged image keeps displaying) until you empty the Trash. Emptying the Trash is what frees the space. An image is treated as used if it is attached to a post, set as a featured image, or its file name or ID appears in any post content, custom field or option, so anything else is reported as "looks unused". This is still a best guess and can miss images used only inside some page builders, sliders or theme options, so review the results first and, ideally, keep a site backup.

= What does "Preload the featured image" do? =
On single posts and pages it adds a high-priority `<link rel="preload">` for the post thumbnail, pointing at its WebP/AVIF version when one exists. The browser fetches your hero image sooner, which usually improves the Largest Contentful Paint metric in Core Web Vitals. It adds no extra requests and can be turned off in **Settings → Performance**. WordPress itself already handles lazy-loading, async decoding and image width/height, so Cleanor does not duplicate those.

= Does it optimize thumbnails too? =
Yes, when **Also convert generated thumbnail sizes** is enabled (default on).

= Which image types are supported? =
JPEG, PNG, WebP and AVIF sources. GIF and SVG are skipped for safety.

= Can I use my own optimization server? =
Yes. Set the **API endpoint** to any server that implements the Cleanor `/v1/optimize` contract.

= Is it multisite compatible? =
Settings and stats are per-site.

== Screenshots ==

1. Optimize every image automatically: each new upload is shrunk on the fly.
2. Choose WebP or AVIF straight from the Media Library, with a quality slider.
3. Bulk-optimize your entire library in one click, with a live progress bar.
4. See exactly how much each image saved in the Cleanor column.
5. No account, no API key, no credits: connected and optimizing within a minute.
6. Backed by open, reproducible benchmarks with a citable dataset.

== Changelog ==

= 0.6.0 =
* **Speed front and center.** The Dashboard now explains that Cleanor's job is faster pages, with a one-click "Test my site on PageSpeed Insights" button (your address prefilled). Add a free PageSpeed Insights API key in Settings to see your mobile Performance score and LCP right on the Dashboard.
* **Remove unused images (safely).** CleanUp can scan the Media Library for images that appear to be used nowhere and move them to the Trash, where they stay recoverable for about 30 days and keep displaying until you empty it. Detection is conservative (attached, featured, or referenced in content/meta/options counts as used) and the action requires an explicit confirmation.
* **On-server optimization (no API needed).** New Engine setting: Auto (re-encode locally with Imagick or GD, fall back to the Cleanor API only when needed), On this server only, or Cleanor API only. Auto is the default, so most sites optimize privately with no external requests. Settings shows the detected server capabilities (Imagick/GD, WebP, AVIF).
* **CleanUp screen.** Delete kept .bak originals and orphaned WebP/AVIF copies to reclaim disk space. Sibling copies (.webp/.avif and .bak) are now removed automatically when an attachment is deleted, so nothing is orphaned. Re-encoding to a different format and switching to Replace mode also clean up the previous copies.
* **How it works help screen.** Plain-language explanation of optimization, delivery modes, where files live, old images, and reclaiming space.
* **Optimized images table.** A new "Images" screen lists every processed image with its before and after size, percentage saved, format, and a direct link to the exact file that is served (the WebP/AVIF copy in keep mode), so it is clear where the optimized bytes live.
* **Bulk convert to a format.** "Convert to a modern format" on the Bulk screen re-encodes your whole library to WebP or AVIF in one pass, following your delivery mode.
* **Featured-image preload for better LCP.** Optionally adds a high-priority `<link rel="preload">` for the post thumbnail on single views, pointing at its WebP/AVIF derivative. No extra requests. Toggle in Settings → Performance.
* **Media Library status filter.** A new "Cleanor status" dropdown above the list lets you show all / optimized / not optimized / restorable images.
* Both features are fully client/WordPress-side and need no calls to the optimization API. WordPress core still owns lazy-loading, async decoding and width/height, so they are not duplicated.

= 0.5.0 =
* **Non-destructive delivery (new default).** "Keep originals, serve modern" stores a WebP/AVIF copy beside each image and serves it via a `<picture>` tag, without changing your original files, URLs or MIME types. Nothing breaks and any image reverts instantly.
* New **Delivery** setting to switch between "Keep originals, serve modern" and the classic "Replace files" behavior.
* Front-end `<picture>` wrapping for content images and template/featured images, with automatic browser fallback to the original.
* Restore now understands both modes: keep-mode reverts by simply deleting the generated copies.

= 0.4.0 =
* Resize on upload: set a maximum width and oversized images are downscaled before they are stored (thumbnails unaffected). Attachment width/height metadata is updated so srcset stays correct.
* Strip metadata: remove EXIF, GPS and camera data on every conversion for smaller, more private files (on by default).
* Native Media Library **Bulk Actions**: "Optimize with Cleanor" and "Restore original (Cleanor)" now appear in the standard dropdown, so you can process a selected subset without leaving the list view.
* "Keep a .bak copy of the original file" is now enabled by default, so Restore works out of the box.

= 0.3.0 =
* New branded "Cleanor" admin cabinet with its own top-level menu.
* Dashboard: total saved, average reduction, images optimized, pending and backed-up counts at a glance.
* Compression presets (Balanced, Aggressive, Near-lossless, Custom).
* Restore originals: one-click bulk restore, plus a per-image "Restore original" action (requires the .bak backup option).
* Redesigned, card-based Settings and Bulk Optimize screens.

= 0.2.0 =
* Admin scripts are now properly enqueued (wp_enqueue_script / wp_localize_script) instead of inline.
* Removed load_plugin_textdomain() (not needed on WordPress 6.0+).

= 0.1.0 =
* Initial release: automatic optimization on upload, bulk optimizer, per-image action, WebP/AVIF/recompress, Media Library savings column, running savings total, optional original backups.

== Upgrade Notice ==

= 0.6.0 =
Adds an optional featured-image preload for faster LCP and a Media Library status filter. Both work entirely on the WordPress side with no extra API calls.

= 0.5.0 =
Adds non-destructive delivery: originals and URLs stay untouched while modern WebP/AVIF copies are served via <picture>. This is now the default for new optimizations; switch to "Replace files" in Settings for the old behavior.

= 0.4.0 =
Adds resize-on-upload, EXIF/GPS metadata stripping, and native Media Library bulk Optimize/Restore actions. Keeping a .bak of originals is now on by default.

= 0.1.0 =
First public release.
