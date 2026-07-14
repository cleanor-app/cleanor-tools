# How do I bulk optimize the WordPress Media Library?

Use a plugin with a bulk mode that walks every attachment and re-encodes it, because WordPress has no built-in way to compress images you have already uploaded. In [Cleanor: Image Compressor & Converter](https://wordpress.org/plugins/cleanor-tools/) it is one screen: **Cleanor, Bulk Optimize**, then **Start optimizing**, and a progress bar you can leave running while it works through the library.

## Before you start

Set your options once, because bulk mode follows them:

- **Output format**: WebP, AVIF, or "Recompress, keep format" to shrink JPEGs without changing the extension.
- **Compression preset**: Balanced (quality 80, recommended), Aggressive (62), Near-lossless (92), or Custom.
- **Delivery**: **Keep originals, serve modern** (default, non-destructive: your files and URLs never change and a modern copy is served via `<picture>`), or **Replace files** (rewrites the file on disk, smallest storage, but extensions and URLs change).
- **Also convert generated thumbnail sizes**: leave it on. Pages usually request a thumbnail, not the full-size upload, so this is what actually makes your pages lighter.
- **Keep a .bak copy of the original file**: on by default, and it is what powers Restore in Replace mode.

A site backup before your first full run is never a bad idea, even though keep mode does not modify your originals.

## Run the bulk optimizer

Open **Cleanor, Bulk Optimize** and click **Start optimizing**. It processes images one at a time over AJAX, so it does not need cron and does not depend on a long-running PHP request. The screen shows how many images are pending and counts them off as it goes. Leave the tab open while it runs.

Images that are already optimized are skipped, so you can stop and restart the run without redoing work.

## Convert the whole library to one format

If what you want is specifically a format conversion, use **Convert to a modern format** on the same screen: pick WebP or AVIF, click **Convert all images**, and it re-encodes every image in one pass, following your current delivery mode. Unlike the standard bulk run, this pass does not skip images that were already processed, which is how you migrate a library that is already WebP over to AVIF.

## Optimize only a selection

You do not have to do the whole library at once.

- In the Media Library list view, tick the images you want and choose **Optimize with Cleanor** from the native **Bulk Actions** dropdown, then Apply. **Restore original (Cleanor)** is in the same dropdown.
- Use the **Cleanor status** filter above the list (all, optimized, not optimized, restorable) to narrow the list first, for example to see only the images that have not been processed.
- A single image can be done from its **Optimize (Cleanor)** row action.

## See what it saved

The Media Library gains a **Cleanor** column with the percentage each image shrank, and the plugin keeps a running total. For the detail, open **Cleanor, Images**: every processed image, its before and after size, the percentage saved, the format, and a direct link to the exact file being served.

That last link matters in keep mode. Because the original file is deliberately left alone, its size in the Media Library does not change, and the smaller file lives beside it as a sibling (`photo.jpg.webp`). The Images screen is where you confirm the optimized bytes exist and are being used.

## Undo a bulk run

Restore is built in. Restore a single image from its row action, or the whole library in one click from the restore action. In keep mode, restoring simply deletes the generated copies, since the original was never modified. In Replace mode, it restores from the `.bak` file, which is why keeping backups is on by default.

## Reclaim the disk space afterwards

A bulk run leaves files behind by design, and **Cleanor, CleanUp** is where you get that space back:

- Delete kept **.bak originals** from Replace mode (this disables Restore for those images).
- Remove **orphaned WebP and AVIF copies** whose source image no longer exists. Sibling copies are also removed automatically when you delete an attachment, so new orphans should not accumulate.
- Delete the **full-size originals of scaled images**.
- Scan for images that appear to be **used nowhere** and move them to the Trash, where they remain recoverable for about 30 days and keep displaying until you empty it. Detection is deliberately conservative (attached, featured, or referenced in content, custom fields or options all count as used), but it is still a best guess and can miss images used only inside some page builders, so review the list before you empty the Trash.

## Related

- [Convert WordPress images to WebP](convert-wordpress-images-to-webp.md)
- [Serve images in next-gen formats](serve-images-in-next-gen-formats.md)
- [WordPress AVIF support](wordpress-avif-support.md)
- Plugin home page: [cleanor.app/wordpress](https://cleanor.app/wordpress)
