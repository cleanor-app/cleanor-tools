# How do I convert WordPress images to WebP?

Install a plugin that re-encodes your Media Library to WebP, then run it over the images you already have and let it handle every new upload. With [Cleanor: Image Compressor & Converter](https://wordpress.org/plugins/cleanor-tools/), that is two screens: set the output format to WebP in **Cleanor, Settings**, then run **Cleanor, Bulk Optimize**. WordPress itself will happily store and serve `.webp` files, it just does not convert your existing JPEGs and PNGs for you.

## The short version

1. Install and activate the plugin from **Plugins, Add New** (search for "Cleanor: Image Compressor & Converter").
2. Open **Cleanor, Settings**. Set **Output format** to **WebP (best support)**.
3. Pick a compression preset: **Balanced** (quality 80, recommended), **Aggressive** (quality 62), **Near-lossless** (quality 92), or **Custom** to set the number yourself.
4. Leave **Delivery** on **Keep originals, serve modern** unless you have a reason to change it (see below).
5. Save. Every new upload is now converted to WebP automatically, thumbnails included.
6. Open **Cleanor, Bulk Optimize** and click **Start optimizing** to process the images already in your library.

## Where the conversion happens

By default the **Engine** setting is **Auto**, which re-encodes images on your own server using Imagick or GD. Almost every WordPress host can produce WebP with one of those, so in practice your images never leave your site. The free Cleanor API is only a fallback for the cases where the server cannot produce the format.

If you want a guarantee, set the engine to **On this server only** and no external request is ever made. The Settings screen prints the capabilities it detected on your host, so you can see whether Imagick or GD is present and whether it can write WebP and AVIF.

## Keep your originals, or replace the files

This is the decision that matters most, and it is the **Delivery** setting.

**Keep originals, serve modern** is the default and it is non-destructive. Your JPEG stays a JPEG, at the same path, the same URL and the same MIME type. Cleanor writes a WebP copy next to it (`photo.jpg.webp`) and wraps the image in a `<picture>` element, so a browser that supports WebP downloads the smaller file while anything else falls back to your untouched original. Nothing can break, and undoing an image is just deleting the copy.

**Replace files** is the classic approach: the file on disk really becomes `photo.webp`, and WordPress is repointed to it everywhere it references the attachment. That saves the most disk space, but the extension and the URL change, so any hard-coded hot-link to the old `.jpg` would need updating. A `.bak` of each original is kept by default, so you can restore.

You can switch modes at any time in **Cleanor, Settings, Delivery**. Images already processed keep the mode they were done in until you re-optimize or restore them.

## Converting a library you already have

**Cleanor, Bulk Optimize** walks the library with a progress bar you can leave running. If you specifically want a format conversion pass over everything, use **Convert to a modern format** on the same screen, choose WebP, and click **Convert all images**: it re-encodes every image in one pass, following your current delivery mode.

To convert only a handful of images, go to the Media Library list view, tick the ones you want, and choose **Optimize with Cleanor** from the **Bulk Actions** dropdown. The **Cleanor status** filter above the list (all, optimized, not optimized, restorable) helps you find the ones that have not been done yet.

## Checking it worked

The Media Library gains a **Cleanor** column showing the percentage each image saved. For the full picture, open **Cleanor, Images**: it lists every processed image with its before and after size, the percentage saved, the format, and a direct link to the exact file being served. That link is the honest answer to "is my site actually serving WebP", because in keep mode the original attachment still looks the same size.

## Which images are converted

Source images can be JPEG, PNG, WebP or AVIF. GIF and SVG are skipped for safety. Generated thumbnail sizes are converted too when **Also convert generated thumbnail sizes** is on, which is the default. An image that would not actually get smaller is left alone.

## Related

- [Serve images in next-gen formats](serve-images-in-next-gen-formats.md), the PageSpeed Insights audit this fixes
- [WordPress AVIF support](wordpress-avif-support.md), if you want smaller files than WebP
- [Bulk optimize the WordPress Media Library](bulk-optimize-the-wordpress-media-library.md)
- Plugin home page: [cleanor.app/wordpress](https://cleanor.app/wordpress)
