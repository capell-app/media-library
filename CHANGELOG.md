# Changelog

All notable changes to `capell-app/media-library` will be documented in this file.

## Unreleased

- Prepared package metadata and documentation for ongoing Capell 4.x package work.

## 2026-06-03

- Orphan cleanup now deletes the underlying storage file in addition to the database row, while leaving files still referenced by another Curator record on the same disk and path untouched.
- Upload visibility is now configurable: `addMediaFromUploadedFile()` accepts an explicit `public`/`private` visibility and otherwise defaults to `capell.media_library.default_visibility` (public unless overridden). Private uploads store to the `local` disk.
- The Spatie-to-Curator migration now preserves the source disk's configured visibility instead of forcing every migrated asset to public.
- Replaced the empty `MediaLibraryHealthCheck` stub with real diagnostics: Curator backend registration, presence of the `curator` table, and owner-foreign-key configuration.
- Shipped a publishable `config/media-library.php` (`owner_foreign_keys`, `default_visibility`, `stale_after_days`) merged under `capell.media_library`.
- Updated marketplace summary, package description, and screenshot manifest.
