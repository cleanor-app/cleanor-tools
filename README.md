# Cleanor Tools — WordPress image optimizer (WebP / AVIF)

Automatically **compress and convert your WordPress Media Library images to WebP or AVIF** for faster pages, better Core Web Vitals, and less storage. Auto-optimizes every new upload, bulk-optimizes your existing library in one click, and shows exactly how much each image shrank. **No account, no API key required.**

[![License: GPL v2](https://img.shields.io/badge/license-GPLv2-blue.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net/)

Powered by the free [Cleanor Labs](https://cleanor.app/) image API.

> 🌐 **No WordPress? Use the same optimizer in your browser at [cleanor.app/tools](https://cleanor.app/tools)** — and see the open [compression benchmarks](https://cleanor.app/research) behind it.

![Bulk Optimize screen](.wordpress-org/screenshot-2.png)

## Contents

- [Features](#features)
- [Install](#install)
- [Developer reference](#developer-reference)
  - [Structure](#structure) · [How it works](#how-it-works) · [Extending](#extending) · [Packaging](#packaging-for-release) · [API contract](#api-contract)
- [Contributing](#contributing)
- [License](#license)

## Features

- **Auto-optimize on upload** — every new image and all its thumbnails are optimized on the fly. No cron, no queues.
- **One-click bulk mode** — reprocess a library of thousands with a live progress bar you can leave running.
- **WebP / AVIF / recompress** — convert to modern formats, or recompress JPEGs smaller while keeping the format and URL.
- **Real, visible savings** — a "Cleanor" column in the Media Library shows per-image savings, plus a running total.
- **Keeps originals (optional)** — store a `.bak` of every source file so you can revert.
- **Privacy-friendly** — no account, no tracking, no dashboard ads. Point it at your own self-hosted endpoint if you prefer.

WebP is typically 25–35% smaller than JPEG, and AVIF smaller still, at the same visual quality — measured in our open [Storage Lab benchmarks](https://github.com/cleanor-app/cleanor-storage-lab).

## Install

**From WordPress.org** (recommended, once approved): search "Cleanor Tools" in **Plugins → Add New**.

**From a release zip:** download `cleanor-tools.zip` from [Releases](../../releases), then **Plugins → Add New → Upload Plugin**.

**From source (git):**
```bash
cd wp-content/plugins
git clone https://github.com/cleanor-app/cleanor-tools.git
```

Then activate, open **Settings → Cleanor Tools**, click **Test connection**, pick your format/quality, and (optionally) run **Media → Bulk Optimize**.

---

## Developer reference

User-facing docs live in [`readme.txt`](readme.txt) (the WordPress.org listing). This section is for contributors.

### Structure

```
cleanor-tools.php                    Entry point: header, bootstrap, activation
includes/class-cleanor-settings.php  Options store, settings screen, running totals
includes/class-cleanor-api.php       HTTP client for /v1/optimize & /v1/capabilities
includes/class-cleanor-optimizer.php Core: upload hook, conversion, metadata rewrite, media column
includes/class-cleanor-bulk.php      Media → Bulk Optimize (AJAX, one at a time)
uninstall.php                        Removes plugin options
readme.txt                           WordPress.org listing
.wordpress-org/                      Banners, icons, screenshots for the wp.org listing
```

### How it works

On `wp_generate_attachment_metadata` (after WP creates all sizes) the optimizer sends the full-size image and each thumbnail to `POST {endpoint}/v1/optimize`, writes the returned bytes back, and (when the format changes) repoints `_wp_attached_file`, the attachment `post_mime_type`, and the metadata `sizes[]` so WordPress serves the optimized files everywhere. Files that would not shrink are left untouched.

All file I/O goes through `WP_Filesystem`. All admin actions are nonce-checked and capability-gated. All output is escaped; all input is sanitized.

### Extending

The plugin is built to be extended without forking.

**Filters**

| Filter | Args | Purpose |
| --- | --- | --- |
| `cleanor_setting_{key}` | `$value, $opts` | Override any setting at read time (e.g. inject the API key from `wp-config.php`). |
| `cleanor_target_format` | `$format, $path, $mime` | Change the output format per file. |
| `cleanor_quality` | `$quality, $path, $mime` | Change encode quality per file. |
| `cleanor_replace_when_larger` | `$bool, $path` | Keep the optimized file even if it is not smaller (default `false`). |

**Actions**

| Action | Args | Purpose |
| --- | --- | --- |
| `cleanor_after_optimize` | `$attachment_id, $stats, $metadata` | Runs after an attachment is optimized. |

Example — force AVIF at quality 60 for a gallery folder, and read the key from `wp-config.php`:

```php
add_filter( 'cleanor_setting_api_key', fn() => defined( 'CLEANOR_KEY' ) ? CLEANOR_KEY : '' );

add_filter( 'cleanor_target_format', function ( $format, $path ) {
    return str_contains( $path, '/gallery/' ) ? 'avif' : $format;
}, 10, 2 );
```

### Packaging for release

The plugin folder is the distributable. To build the zip WordPress expects (a single top-level `cleanor-tools/` directory):

```bash
git archive --format=zip --prefix=cleanor-tools/ -o cleanor-tools.zip HEAD \
  ':(exclude).wordpress-org/*' ':(exclude)README.md'
```

### API contract

```
GET  /v1/capabilities → { version, auth, tools[], rest.optimize{...} }
POST /v1/optimize      → multipart file (or JSON image_url, or raw body)
                         with format / quality / width; returns optimized bytes
                         (or ?json=1 for base64).
```

## Contributing

Issues and PRs welcome. This mirrors the plugin published on WordPress.org; please follow [WordPress coding standards](https://developer.wordpress.org/coding-standards/) — all output escaped, all input sanitized, admin actions nonce-checked and capability-gated. Test against a clean WordPress install before opening a PR.

## License

[GPL-2.0-or-later](LICENSE). © Cleanor Labs. More at [cleanor.app/tools](https://cleanor.app/tools) and [cleanor.app/research](https://cleanor.app/research).
