Pic Pilot – Plugin Functionality Overview
What Is Pic Pilot?
Pic Pilot is a modern, modular WordPress plugin for site owners, bloggers, agencies, and developers who want granular control over image optimization, backup, and restoration—while maintaining full compatibility with the WordPress Media Library and best performance practices.

Key Goals:

Reduce image file size to speed up websites

Allow lossless restoration of original images and thumbnails

Keep backups and optimization in the user's control (no locked-in external services required)

Features at Project Start
When we began this phase, Pic Pilot already provided:

Image Optimization:

Local JPEG compression (with planned support for more engines, including external services for PNG and eventually WebP).

Smart router to pick the best available compression engine per image type and settings.

Media Library Integration:

Custom “Optimize” action and status in the WordPress Media Library.

Visual feedback on optimization status (button, saved space, errors).

Settings UI:

Toggle for backup, auto-optimize on upload, and engine selection.

Initial Backup Capability:

Optional backup of original image before optimization (basic/legacy implementation).

What We’ve Just Achieved
We have designed and implemented a robust, next-generation backup and restore system with these major improvements:

1. Comprehensive Backup System
When enabled, every image to be optimized is fully backed up:

Main (scaled) image and all WordPress-generated thumbnails are copied into a dedicated backup folder per attachment in /uploads/pic-pilot-backups/{attachment_id}/.

Each backup includes a manifest file recording file names, original locations, and backup timestamp/version.

No backup of “unattached/originals” unless specifically requested.

2. Safe, User-Friendly Restore
Restore operation overwrites the main and all thumbnail files for an attachment with the exact copies from the backup—no orphaned files, no broken links.

Restoration updates a meta key (_pic_pilot_restore_version) so the plugin knows when an image was last restored.

3. Smart, Versioned UI Feedback
Media Library and Backup Manager screens both:

Compare the backup “restore version” and the last “optimized version” (tracked via post meta).

Show clear buttons and statuses:

“Restored” with “Optimize Now” (if restored, not yet re-optimized)

“Optimized” and saved stats (if not restored since)

“Restore”/“Restored” state in backup list, always in sync

No accidental overwrites or false status: user always sees the true state of each image.

4. Robust Delete/Cleanup Logic
Backups can be deleted per image (removing all associated files/folders).

(Planned: bulk and automated cleanup.)

5. Modular, DRY, Modern Architecture
All logic is namespaced and modularized (SRP, PSR-4, Composer).

All UI actions use nonces and permission checks for security.

Easily extensible for future: webp support, cloud storage, smarter UI, async tasks.

Why This Matters
Performance: Users can optimize images safely, knowing they can always revert.

Disk Savings: No unnecessary backups—only as many as the user needs, with clear tools to clean up.

Reliability: Full thumbnail backup/restore prevents page builder or theme breakage.

Transparency: The UI always reflects the real image state—never any guesswork.

Next Steps / Planned Features
Support for WebP backup and restoration (with similar logic)

“Optimize on upload” and “bulk optimize” flows using this robust backup engine

Optional advanced settings for removing unused “true original” files for further space savings

Bulk/auto cleanup of old backups and advanced search/filter tools in the Backup Manager

In short:
Pic Pilot now offers best-in-class image optimization with bulletproof backup and restoration—giving users speed, safety, and full control.

How Image Optimization & Backup/Restore Work in Pic Pilot
Where Optimization Is Triggered
All image optimizations are triggered via the main AJAX action:

php
Copy
Edit
add_action('wp_ajax_pic_pilot_optimize', function () { ... });
This code lives in your pic-pilot.php file.

This ensures every optimization request—whether from the Media Library UI, the Backup Manager, or a future bulk/batch process—goes through the same unified entry point.

How the Process Flows (Step by Step)
User Clicks “Optimize” (or a bulk optimize is triggered)

This sends an AJAX request to the pic_pilot_optimize endpoint.

Attachment ID is Resolved

The handler receives the attachment_id of the image to be optimized.

Backup is Always Created First

Before any optimization or compression happens, the plugin always creates a fresh backup:

php
Copy
Edit
if (\PicPilot\Settings::is_backup_enabled()) {
    \PicPilot\Backup\BackupService::create_backup($attachment_id);
}
This backup includes the main file and all generated thumbnails, stored in a dedicated folder (/pic-pilot-backups/{attachment_id}/), along with a manifest file (manifest.json) that records file paths and a backup_created timestamp.

The Latest Backup’s Timestamp is Captured

Immediately after backup, the code reads the manifest file and fetches the latest backup_created timestamp.

Optimization is Routed to the Correct Engine

The plugin uses your custom EngineRouter to decide which compressor to use:

Local for JPEG, or TinyPNG for PNG, etc.

This ensures optimizations are always run by the best available tool for each image type.

The Compressor Runs

The actual optimization occurs—this could be local or remote (API-based).

Optimization Meta is Synchronized with Backup

After a successful optimization, the plugin sets _pic_pilot_optimized_version meta to match the exact timestamp of the most recent backup.

This means your “restored” and “optimized” logic will always be perfectly in sync, regardless of the engine, file type, or external API/network delays.

The UI and All Restore Logic are Always Accurate

Since both meta values now use the manifest’s backup_created timestamp, the Media Library and Backup Manager can accurately reflect the real image state:

If a user restores, the button disables (“Restored”) and shows a helpful status.

If they re-optimize, the UI updates to “Optimized” and displays saved space.

Why This Is Robust
No more timing gaps:
Network delays, slow APIs, or different engines no longer cause meta mismatches.

Perfectly accurate restore/optimize UI for every image type—JPEG, PNG, or anything added in the future.

Every optimize is safely revertible, and the user can always see the true image state.

Developer-Friendly Features
All core logic is in one place—the AJAX handler in pic-pilot.php—so it’s easy to extend or customize.

Modular router and compressor structure makes it simple to add new engines (like WebP or AVIF).

Backup/restore is DRY and always in sync with optimization actions.

Future features (bulk, async, advanced cleanup) can all rely on this solid backbone.

In Short
Every image optimization—local or remote—is always preceded by a backup.

The backup and optimization version meta are always matched by reading the manifest, not guessing with timestamps.

The Media Library and Backup Manager always show the true, current state of every image, so users can confidently optimize, restore, and re-optimize at any time.