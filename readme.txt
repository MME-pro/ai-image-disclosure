=== AI Image Disclosure ===
Contributors: aiimagedisclosure
Tags: ai, ai act, disclosure, labeling, compliance
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bake an AI-disclosure badge into your image files — survives downloads, hotlinking and theme changes. Manual, reversible, with full backups.

== Description ==

Article 50 of the EU AI Act requires visible disclosure when an image is AI-generated or AI-edited. A CSS overlay does not survive a right-click download, a hotlink, an RSS feed or a theme change — so this plugin composites a badge PNG **into the image file itself**, overwriting the file at its existing path so no URL, link or post content ever changes.

Labelling is always a deliberate, manual action: you select the images, choose the label type and badge corner, and confirm. Nothing is ever labelled automatically, in bulk, or behind your back.

**How it works**

1. Select one or more images in the Media Library and choose the *Add AI Label* bulk action.
2. Pick a label type, a corner, and either auto-contrast or a specific badge variant.
3. Each image is queued as its own background job, so a large batch never hits a PHP timeout and one failure cannot block the rest.
4. Per image, the plugin copies the original into a protected backup folder, samples the target corner's brightness to pick the readable badge variant, composites the badge, overwrites the file, regenerates the registered image sizes from the labelled original, appends a disclosure suffix to the alt text, and purges the caches it can reach.
5. A results screen shows success or failure per image, with the exact step that failed.

**Two kinds of label — image or text**

Every label type can either composite a badge PNG you upload, or render its own text label on the server: wording, text and background colour, opacity, corner radius and border, with separate colours for light and dark backgrounds. Text labels need no design work and no image file at all, so you can start disclosing immediately and swap in a designed badge later. They are rendered at high resolution and scaled down onto the image, so they stay crisp, and they get their own scale setting because a wide text strip needs more width than a square seal to stay readable.

**Re-labelling never stacks badges**

Changing a label — different type, different corner, edited text, new badge file — always restores the original from the backup first and applies the new label to those original bytes. The plugin will not composite onto a file it knows is already labelled: if the backup is missing it refuses and says so rather than baking a second badge into the image.

**Reversible by design**

*Restore original* copies the untouched backup back over the live file, rebuilds the image sizes and removes the alt-text suffix. Backups are never deleted automatically — not on restore, not on deactivation, not even on uninstall. Only the explicit *Delete unused backups* tool removes them, and it refuses to touch a backup whose image is still labelled.

**Features**

* Badge is baked into the image bytes — verifiable by re-downloading the file.
* Filename and URL never change; no database rewriting, no broken links.
* Ships with one ready-to-use label type (AI-generated) and no hardcoded limit — add, rename or delete types freely, each with a light- and a dark-background variant.
* Per type: use an uploaded badge PNG, or a text label the plugin renders itself — no image required.
* Re-labelling always restores the original first, so a second badge can never be stacked onto an already-labelled image.
* Auto-contrast: the target corner's mean luminance decides which badge variant is used.
* Imagick preferred, GD fallback — works on both kinds of host. JPEG, PNG, GIF and WebP everywhere; AVIF and HEIC where the host supports them.
* Idempotent: re-running the label on an already-labelled image never stacks a second badge.
* Background processing via Action Scheduler when another plugin provides it, WP-Cron otherwise. No bundled library.
* Legibility floor: image sizes too small to carry a readable badge are rebuilt from the unlabelled original instead of showing a smudge.
* Automatic cache purging for WP Rocket, W3 Total Cache, LiteSpeed Cache and SiteGround Optimizer when active; optional Cloudflare purge-by-URL.
* Optional labelled WebP sidecar, served through a `<picture>` element. Off by default; the primary file always keeps its own format and MIME type.
* Persistent job log with the failed step per image, plus a dashboard widget for backup folder size.
* Own capability (`ai_label_manage`), granted to administrators, so an agency can hand labelling to an editor without full admin rights.
* Per-site settings and badge registry, so one multisite network can serve many clients.
* WP-CLI: `wp ai-label apply|restore|reapply|status|run-queue`.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/ai-image-disclosure` and activate it.
2. Activation creates one label type, *AI-generated*, already set to a generated text label — so you can label an image straight away without designing anything.
3. Optional: under **Media → AI Image Disclosure → Label types & badges**, adjust the wording and colours, or switch that type to an uploaded badge PNG (light-background and dark-background variants).
4. Review **Settings** — badge scale, edge padding, auto-contrast threshold and the width down to which image sizes keep the badge.
5. Select images in the Media Library and use the **Add AI Label** bulk action.

The plugin needs either the Imagick or the GD PHP extension, and a writable `wp-content/uploads` folder for the backup store.

== Frequently Asked Questions ==

= Does this decide for me which images need a label? =

No. Whether an image requires an Article 50 disclosure is a human, legal judgement. The plugin only carries out the disclosure you ask it to apply.

= Is my original image lost? =

No. The original bytes are copied into `wp-content/uploads/ai-label-backups/{attachment-id}/` before anything is written, and that copy is never overwritten or deleted automatically. *Restore original* puts it back exactly as it was.

= Will my image URLs change? =

No. The labelled file is written to the same path with the same filename, so post content, hotlinks and search-engine indexes keep working.

= Why do small thumbnails sometimes have no badge? =

A badge scaled down to a handful of pixels is illegible and looks broken. Sizes narrower than your configured threshold whose badge would fall below the 24px legibility floor are rebuilt from the unlabelled original, and the skip is recorded in the job log.

= What about an image optimiser like Smush or ShortPixel? =

Run the optimiser's bulk optimise after labelling. If an optimiser (or any thumbnail-regeneration tool) rebuilds the sizes of an already-labelled image, the Media Library flags it so you can re-apply the label.

= I don't have a badge image. Can I still label images? =

Yes. Open **Media → AI Image Disclosure → Label types & badges**, set that label type's badge source to *Generated text label*, type the wording and save. The plugin renders the label itself using a font on the server, so no upload and no design work is needed. If no font is found automatically, enter the path to a .ttf or .otf file under Settings → Font file.

= What happens if I label an image that is already labelled? =

The original is restored from its backup first, and your new label is applied to those original bytes — so the wording, corner or badge changes without a second badge appearing. If the backup is missing, the plugin refuses rather than compositing on top of the existing badge.

= I changed the label text. Do existing images update? =

No, and that is deliberate: editing a label type never rewrites images behind your back. Images labelled with the old wording are flagged in the Media Library, and *Re-apply with current badge* re-labels them from their originals when you choose to.

= Which image formats are supported? =

JPEG, PNG, static GIF and WebP on every host. AVIF is added when your PHP build can read and write it (Imagick with libavif, or GD on PHP 8.1+), and HEIC when ImageMagick supports it. Unsupported formats are refused with a clear message instead of being silently skipped.

= Does it work with animated GIFs or SVG? =

No. Animated images would lose their animation and SVG has no pixel buffer to composite onto, so both are refused with a clear error rather than silently mangled.

= Can I add my own CDN? =

Yes. Hook `ai_label_purge_cdn` (or the broader `ai_label_purge_attachment`), both of which receive the attachment ID and the list of changed URLs.

== External services ==

This plugin does not contact any external service by default.

If — and only if — you enter a Cloudflare zone ID and API token on the settings screen, the plugin calls the Cloudflare cache-purge API (`https://api.cloudflare.com/client/v4/zones/{zone}/purge_cache`) after an image is labelled or restored, so the CDN stops serving the previous version of that file. The only data sent is the public URLs of the image files that changed, together with your API token for authentication. No personal data and no image content is transmitted. The token is stored encrypted and is never displayed again.

Cloudflare terms of service: https://www.cloudflare.com/terms/
Cloudflare privacy policy: https://www.cloudflare.com/privacypolicy/

Remove the token from the settings screen (type `delete` into the field) to stop all outgoing requests.

== Screenshots ==

1. Choosing label type, badge corner and contrast handling for a selection of images.
2. Label types with their light and dark badge variants.
3. Batch progress and per-image results, including the step that failed.
4. Backup storage overview and the manual "delete unused backups" tool.

== Changelog ==

= 1.3.0 =
* Renamed the plugin to AI Image Disclosure. The plugin folder, main file, text domain and admin page slug changed to match; settings, label types, badge registry, job log, attachment metadata and backups are all untouched and keep working.

= 1.2.6 =
* Redesigned the tab bar: a white rail with an icon per section, the active tab filled with the accent gradient, and a live counter on Job log while background jobs are running. Collapses to icons only on small screens.
* New plugin mark — a framed image with a disclosure seal stamped into its corner, drawn as inline SVG — plus a version chip beside the title.
* Save and Delete for a label type now share one row, with the destructive control at the far end.
* Removed the two optional extra badge variants. Each label type carries a light- and a dark-background badge, which is what auto-contrast chooses between.

= 1.2.5 =
* The badge source is now a centred chooser with its own heading and icons, styled like the primary button so it reads as the decision it is.
* Label text, padding, corner radius and border width sit on one row.

= 1.2.4 =
* Renamed the "Display name" field to "Label type name".
* Simplified the label type card header to just the type name — the badge source, readiness, slug and usage count chips are gone.
* New text label defaults: padding 50, corner radius 50, border width 1. Label types still carrying the previous defaults are moved onto the new ones on update; any type whose shape was edited is left untouched, and no already-labelled image changes.
* The colour and opacity settings for each contrast variant are now one per row, label left and control right, instead of wrapping into a ragged grid.

= 1.2.3 =
* Removed the sort order field from the label type form and the add dialog. Types are listed alphabetically; the stored order is still respected if set programmatically.

= 1.2.2 =
* The environment summary (image engine, queue runner, text-label readiness, backup size) moved from the page header to a footer below the content.
* "Save label type" now sits below the badge settings in both modes, so the flow reads top to bottom whichever source is selected.
* Redesigned the "Add label type" button as a gradient pill with an inline SVG icon, so it no longer depends on the dashicons font rendering inside a styled button.

= 1.2.1 =
* Adding a label type now happens in a modal dialog opened from a button, instead of a form always sitting at the bottom of the screen.
* Each label type is a single form — display name, alt-text suffix, sort order, badge source — and only the settings for the selected badge source are shown: image variants for a badge PNG, wording and colours for generated text.
* Switching badge source without saving is flagged, so the visible settings are never mistaken for the saved state.
* Label type headers are one row: name, badge source, readiness, slug and how many images use it.
* Badge image slots are a tidy pair (light and dark) with the two optional extra variants collapsed.

= 1.2.0 =
* Redesigned the whole admin interface: card layout, live environment status in the header, pill tabs, a visual 2×2 badge-position picker, toggle switches, segmented badge-source control, colour swatches, stat tiles, filter chips, empty states, an animated progress bar and a selection gallery. Responsive, keyboard-friendly, reduced-motion aware, and it inherits the user's admin colour scheme.
* Text badge previews now update live as you type the wording, colours or spacing.
* A fresh install now creates one label type instead of two, set to a generated text label so the plugin works immediately with nothing to upload.
* Every label type explicitly offers both badge sources side by side — uploaded PNG or generated text — switchable at any time.
* Removed duplicate element ids from forms on screens carrying several forms, and labelled the per-image override selects for screen readers.

= 1.1.0 =
* New: generated text labels as an alternative to uploading a badge PNG, with per-variant colours, opacity, radius and border, and a server-side font with autodetection.
* New: separate scale and legibility floor for text labels, derived from the rendered text height.
* New: "Change AI label" row action, plus an explicit warning on the apply screen when a selection contains already-labelled images.
* Added a hard guard so a badge can never be composited onto a file the plugin knows is labelled; if the backup is missing the job fails loudly instead.
* Added AVIF support (and HEIC on ImageMagick) where the host can read and write it.
* Fixed: on Windows, ImageMagick held a memory-mapped handle on the source file, so every labelled image failed to move into place. Images are now read through a blob, and both backends fall back to an overwriting copy if rename is refused.

= 1.0.0 =
* Initial release: manual badge compositing into image files, backup and restore, background job queue, label type and badge registry, auto-contrast, sub-size regeneration with a legibility floor, alt-text disclosure, cache and Cloudflare purging, optional WebP sidecar, job log, WP-CLI commands.

== Upgrade notice ==

= 1.3.0 =
The plugin is now called AI Image Disclosure. No data or settings change.

= 1.2.6 =
Redesigned tab bar and plugin mark; badge variants reduced to light and dark.

= 1.2.5 =
Clearer badge source chooser and a tighter text label layout.

= 1.2.4 =
New text label shape defaults and a tidier colour settings layout. Existing labelled images are unaffected.

= 1.2.3 =
Removes the sort order field from the label type screens.

= 1.2.2 =
Layout polish on the label types screen and a redesigned add button. No processing changes.

= 1.2.1 =
Cleaner label-type screen: add types in a modal, and only the settings for the badge source you picked are shown.

= 1.2.0 =
Redesigned admin interface with live text-label previews. No change to how images are processed; existing labels and backups are untouched.

= 1.1.0 =
Adds text labels that need no badge image, AVIF support, and fixes labelling failing on Windows servers running ImageMagick.

= 1.0.0 =
First release.
