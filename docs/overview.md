# Media Library

<!-- prettier-ignore-start -->

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

## Good to know

- Upload images once and reuse them anywhere, instead of re-uploading.
- Add **alt text** so images are accessible and better for search.
- Search the library before uploading, so you don't create duplicates.
- Use **System > Media health** to filter missing-alt, stale, and unused media. By default, an otherwise healthy asset becomes stale after 90 days without an update.
- Media health is a global report: it requires `View:MediaHealthPage` and is not available to site-scoped operators. Deleting selected unused records also requires `Delete:MediaHealthPage`.
- Orphan cleanup re-checks current references before it deletes a record, so a file that was attached after the report loaded is retained. It removes the underlying storage file only for records that are still unused.
- Media health depends on the known media-owner fields in the application. If owner-key discovery is disabled and no owner fields are configured, the unused-media report is intentionally empty rather than guessing.
- Diagnostics verifies that the Curator media backend and shared media field factory are registered; it also reports the health-report and Spatie migration capabilities.

---

For how to use Media Library, see the [admin guide](admin-guide.md).
For developers: see the [README](../README.md).

<!-- prettier-ignore-end -->
