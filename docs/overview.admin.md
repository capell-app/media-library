## What it does for you

Media Library is where you keep your images and files in one place and reuse them anywhere on your site. You upload media, organise it into folders, search instead of re-uploading, add alt text for accessibility, and pick media when filling in a page or widget.

## Your screens

- **Media Library**: the grid of all your uploaded images and files.
- **The media picker**: the chooser that opens when a page or widget asks for an image.

## What you can do

- Upload images and files.
- Organise media into folders.
- Search the library to find media quickly.
- Add alt text to an image.
- Replace a file while keeping it in the same place.
- Pick existing media when editing a page.

## Where to find it

Go to **Media Library** in the admin, or open it from any image field while editing a page.

## Upload and visibility rules

- Capell media picker fields accept JPEG, PNG, GIF, WebP, SVG, and PDF files up to 10 MB by default. The host application can change both allow-lists and the size limit. A file can be rejected when its detected MIME type, extension, or size does not match those settings.
- SVG uploads are sanitised before use. Scriptable elements, event handlers, unsafe URLs, and external image references are removed. If an SVG stored through Curator cannot be sanitised, its blob is deleted; remove the resulting broken media record before trying a corrected file.
- Do not treat the library as private storage by default. Package-managed uploads default to public visibility, and neither the file nor its Curator metadata is encrypted by this package. Configure private visibility and a private disk before accepting sensitive assets.
- Private media is rendered through a five-minute temporary URL and does not expose a responsive `srcset`. If its disk cannot create temporary URLs, the package returns no public URL instead of falling back to the storage path. Verify the chosen disk before relying on private assets in a page.
- Creating a record through the Curator model queues SHA-256 checksum metadata outside the upload request. Keep a queue worker running if an integration depends on that metadata. A missing record or unreadable blob is skipped without failing the upload, and there is no operator retry control.

## Media health and cleanup

- **System > Media health** shows assets with missing alt text, an old `updated_at` timestamp, or no discovered owner reference. The column labelled **Last used** currently reflects the media record's last update, not a tracked page view or last-render time.
- Report rows are cached for 60 seconds by default. A correction may therefore take up to the configured cache period to disappear from the report.
- Media health is global rather than site-scoped. `View:MediaHealthPage` opens it; `Delete:MediaHealthPage` additionally reveals and authorises **Delete unused media**. Give destructive access only to operators who can assess usage across every site.
- Cleanup rechecks current references inside the delete transaction. It retains a record that became attached after the report loaded and keeps a blob that another Curator row shares. If the configured disk is unavailable or file deletion fails, the database row is still removed and the blob can remain for a storage administrator to reconcile.
- Usage is calculated only from configured owner foreign keys or recognised conventional columns. If discovery is disabled and no valid keys are configured, the unused report is deliberately empty and cleanup deletes nothing.

## Migrating from Spatie Media Library

The console command `capell:media-migrate-to-curator` is for an integrator moving existing Spatie records. Run it with `--dry-run` first and review every warning before running without that option. `--collection`, `--owner-type`, and `--chunk` can narrow a large migration.

The Curator table and each owner's `<collection>_id` column must already exist. The migration creates or reuses a Curator row by disk and path, preserves mapped metadata and source-disk visibility, and fills an owner foreign key only when it is currently empty. It does not move or delete the source file or Spatie row.

This backend represents one asset per owner collection, so it is not a gallery migration. Missing owner models or foreign-key columns are warned and skipped. Processing continues after row-level errors and the command still returns success with warnings, which means a run can be partial. Fix the reported cause and rerun: existing Curator rows are reused and already-filled owner fields are left unchanged.

## Good to know

- Upload images once and reuse them anywhere, instead of re-uploading.
- Add **alt text** so images are accessible and better for search.
- Search the library before uploading, so you don't create duplicates.
- By default, an otherwise healthy asset becomes stale after 90 days without an update.
- Diagnostics verifies that the Curator media backend and shared media field factory are registered; it also reports the health-report and Spatie migration capabilities.
