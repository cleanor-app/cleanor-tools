# How do I fix "Serve images in next-gen formats" in WordPress?

"Serve images in next-gen formats" is a PageSpeed Insights and Lighthouse opportunity that fires when your pages ship JPEG or PNG images that would be meaningfully smaller as WebP or AVIF. To clear it, you have to actually deliver a next-gen format to the browser, which means re-encoding the images in your Media Library and then serving those copies. [Cleanor: Image Compressor & Converter](https://wordpress.org/plugins/cleanor-tools/) does both halves: it converts the library to WebP or AVIF and serves the result through a `<picture>` tag.

## Why the audit fires

Lighthouse compares the bytes of each image on the page against what the same image would weigh in a modern format. If the saving is large enough, the image is listed. Two things trip people up:

- **Converting is not enough.** If the optimized copy exists on disk but the page still requests the original JPEG, the audit keeps firing. Delivery is the part that gets scored.
- **Thumbnails count.** The image on the page is usually a generated size, not the full-size upload, so converting only the full-size file changes nothing that Lighthouse can see.

## The fix, step by step

1. Install and activate **Cleanor: Image Compressor & Converter** from **Plugins, Add New**.
2. Open **Cleanor, Settings**.
3. Set **Output format** to **WebP** (widest browser support) or **AVIF** (smallest files).
4. Keep **Also convert generated thumbnail sizes** enabled. This is on by default and it is what makes the audit pass, because the thumbnails are what your pages actually request.
5. Leave **Delivery** on **Keep originals, serve modern**. Cleanor writes a sibling copy (`photo.jpg.webp`) and wraps content images and template images in a `<picture>` element, so supporting browsers fetch the smaller file and everything else falls back to the original. Your URLs do not change.
6. Save, then run **Cleanor, Bulk Optimize** so the images already on your pages are covered, not just future uploads.
7. Re-run PageSpeed Insights.

If you would rather the file on disk itself be a WebP or AVIF, switch **Delivery** to **Replace files**. That also clears the audit, at the cost of changed file extensions and URLs.

## Confirm you are really serving the modern format

Open **Cleanor, Images**. It lists every processed image with its before and after size, the percentage saved, the format, and a direct link to the exact file being served. In keep mode the original attachment still shows its original size in the Media Library, which is expected and often mistaken for a failure, so this screen is the one that tells you the truth.

You can also check in the browser: view the page source and look for a `<picture>` element with a `<source type="image/webp">` (or `image/avif`) before the `<img>`, or open DevTools, Network, Img and look at the type of the file that was actually downloaded.

## Do not forget the other image audits

Fixing the format is usually the biggest single win, but PageSpeed Insights reports a few related things, and the plugin has direct answers for them:

- **"Properly size images"**: set a maximum width under **Resize** in Settings (2560 is a sensible value) and oversized uploads are downscaled before they are stored, so a 6000px phone photo does not ship at full resolution.
- **"Efficiently encode images"**: the compression preset drives this. Balanced is quality 80, Aggressive is 62, Near-lossless is 92.
- **Largest Contentful Paint**: enable **Preload the featured image** under **Settings, Performance**. On single posts and pages it emits a high priority `<link rel="preload">` for the post thumbnail, pointing at its WebP or AVIF version, with `fetchpriority="high"`. The browser starts fetching your hero image sooner and it adds no extra requests.

Lazy loading, async decoding and image width and height attributes are handled by WordPress core, so the plugin deliberately does not duplicate them.

## Measure it from the dashboard

The Cleanor Dashboard has a **Test my site on PageSpeed Insights** button with your address prefilled. If you add a free PageSpeed Insights API key in Settings, the mobile Performance score and the LCP value are shown on the Dashboard itself, so you can watch them move as you optimize.

## Related

- [Convert WordPress images to WebP](convert-wordpress-images-to-webp.md)
- [WordPress AVIF support](wordpress-avif-support.md)
- [Bulk optimize the WordPress Media Library](bulk-optimize-the-wordpress-media-library.md)
- Plugin home page: [cleanor.app/wordpress](https://cleanor.app/wordpress)
