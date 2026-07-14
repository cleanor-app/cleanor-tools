# Does WordPress support AVIF?

Yes. WordPress core added AVIF support in version 6.5, so it can accept, store and serve `.avif` files, provided your server's image library (Imagick or GD) can handle the format. What core does not do is convert your existing JPEGs and PNGs to AVIF, and that is the gap [Cleanor: Image Compressor & Converter](https://wordpress.org/plugins/cleanor-tools/) fills.

AVIF is the smallest of the mainstream web image formats and it is supported by all current major browsers. The catch is server side: AVIF encoding is more demanding than WebP, and plenty of shared hosts ship a PHP image library without an AVIF delegate.

## First, check whether your host can encode AVIF

Open **Cleanor, Settings**. The Connection area prints the capabilities detected on your server: whether Imagick or GD is present, and whether it can write WebP and whether it can write AVIF. That is the honest answer for your specific host, and it is worth reading before you commit your library to AVIF.

If your server cannot do AVIF, you have two options and the plugin handles both:

- **Engine: Auto (the default).** Images are re-encoded locally whenever the server can do it, and the free Cleanor API is used as a fallback for the formats it cannot. This is exactly the case AVIF fallback exists for: you get AVIF even on a host without an AVIF encoder, with no account and no API key.
- **Engine: On this server only.** Nothing is ever sent externally. On a host without AVIF support this means you should choose WebP instead, which is supported almost everywhere via Imagick or GD.

## Turning on AVIF

1. Open **Cleanor, Settings**.
2. Set **Output format** to **AVIF (smallest)**.
3. Choose a preset. **Balanced** is quality 80 and is the recommended starting point; **Aggressive** is 62 if you want the smallest possible files; **Near-lossless** is 92.
4. Save. New uploads are now converted to AVIF, thumbnails included.
5. Run **Cleanor, Bulk Optimize**, or use **Convert to a modern format**, choose AVIF, and click **Convert all images** to re-encode the whole library in one pass.

## Use AVIF without risking your site

The default **Delivery** mode, **Keep originals, serve modern**, is the safe way to adopt AVIF. Your original JPEG or PNG stays exactly where it is, with the same file name, URL and MIME type. Cleanor writes an AVIF copy beside it (`photo.jpg.avif`) and wraps the image in a `<picture>` element with a `<source type="image/avif">`. Browsers that support AVIF take the smaller file; anything that does not, silently falls back to your original. Nothing breaks even in the worst case, and reverting an image is just deleting the copy.

The alternative, **Replace files**, rewrites the actual file to `.avif` and repoints WordPress to it. It saves the most disk space, but the extension and URL change. A `.bak` of each original is kept by default, so Restore still works.

## AVIF or WebP?

- **AVIF** produces the smallest files of the two at comparable quality, but encoding is slower and some hosts cannot do it locally.
- **WebP** has the widest support, is faster to encode, and virtually every host can produce it with Imagick or GD. It is the safe default for most sites.

Because the delivery is a `<picture>` element with a typed `<source>`, either choice degrades gracefully. If you are unsure, start with WebP, confirm the pipeline works end to end on the **Cleanor, Images** screen, then try AVIF on a few images and compare the before and after sizes there.

You can also mix the two per file with the `cleanor_target_format` filter, for example forcing AVIF only for a specific folder:

```php
add_filter( 'cleanor_target_format', function ( $format, $path ) {
    return str_contains( $path, '/gallery/' ) ? 'avif' : $format;
}, 10, 2 );
```

## What gets converted

Source images can be JPEG, PNG, WebP or AVIF. GIF and SVG are skipped for safety. Generated thumbnail sizes are converted alongside the full-size image when **Also convert generated thumbnail sizes** is enabled, which is the default and which is what makes the difference on real pages, since pages usually request a thumbnail rather than the full-size upload. Any image that would not actually get smaller is left untouched.

## Related

- [Convert WordPress images to WebP](convert-wordpress-images-to-webp.md)
- [Serve images in next-gen formats](serve-images-in-next-gen-formats.md)
- [Bulk optimize the WordPress Media Library](bulk-optimize-the-wordpress-media-library.md)
- Plugin home page: [cleanor.app/wordpress](https://cleanor.app/wordpress)
