# Media Library Overview

Status: **available** · Kind: **package** · Tier: **free** · Bundle: **foundation** · Contexts: **admin, console** · Product group: **Capell Foundation**

Media Library is Capell's Curator-backed media foundation. It replaces the default media backend with Awcodes Curator, gives packages a shared media field factory, and adds operational tools for media health and Spatie Media Library migration.

It is deliberately scoped as foundation infrastructure. Advanced DAM features such as folders, galleries, generated WebP/AVIF conversions, signed private URLs, and a visual focal/crop editor remain product gaps or premium candidates.

## Runtime Shape

- `MediaLibraryServiceProvider` merges `config/media-library.php`, sets `capell.media.backend` to `curator`, sets `capell.media.model` to `CuratorMedia`, and binds `MediaFieldFactory` to `CuratorMediaFieldFactory`.
- `CuratorMediaFieldFactory` returns an Awcodes `CuratorPicker` for Capell media FK fields.
- `CuratorMedia` adapts Curator records to Capell's media contract, including URL, alt/title/caption, dimensions, focal point, crop preset, and existing responsive metadata accessors.
- `MediaHealthPage` and `MediaHealthTable` expose the admin report for missing alt text, stale media, and unused assets.
- `BuildDuplicateMediaQueryAction` hashes readable storage files and reports byte-identical duplicate media even when the Curator paths differ.
- `BuildMissingAltMediaQueryAction` and `DispatchMissingAltMediaSignalsAction` expose prioritized missing-alt media candidates for automation packages such as Media AI.
- `BuildMissingRightsMetadataQueryAction` parses Curator `exif` JSON and reports media whose configured rights keys are missing, blank, or malformed.
- `MigrateSpatieToCuratorCommand` delegates migration work to `MigrateSpatieMediaToCuratorAction`.

## Configuration

The package has no migrations of its own. It relies on Curator's `curator` table and owner tables that store Curator media IDs.

The config is published with the `media-library-config` tag and merged under `capell.media_library`:

- `owner_foreign_keys`: exact table/column pairs that reference Curator media.
- `auto_discover_owner_foreign_keys`: allows report actions to find conventional media FK columns when explicit config is empty.
- `owner_foreign_key_columns`: column names considered during discovery.
- `default_visibility`: default visibility for uploads through `InteractsWithCuratorMedia`.
- `stale_after_days`: media-health stale threshold.
- `report_cache_ttl_seconds`: short TTL for computed media health and orphan report rows.
- `allowed_mime_types`, `allowed_extensions`, `max_upload_kb`: upload validation policy.

Owner FK entries are schema-checked before report SQL is generated. Missing tables, missing columns, and unsafe identifiers are ignored.

## Admin And Operations

The Media Health page is an admin-only Filament page registered through Capell Admin when the package is installed. It shows:

- byte-identical duplicate assets
- missing alt text
- stale assets based on `stale_after_days`
- unused assets based on configured or discovered owner FK references
- per-issue filtering
- selected orphan cleanup that re-validates records before deleting files and rows

The missing-alt signal actions are deliberately separate from the table UI. They return image candidates with null, empty, or whitespace-only alt text, include `usage_count`, and can dispatch `MediaMissingAltDetected` events for downstream automation.

Rights metadata reporting decodes Curator `exif` JSON before matching keys, so unrelated text values do not satisfy copyright/license requirements and empty metadata values remain visible.

The health and orphan report Actions cache their computed row ids and projected columns briefly, then rebuild normal Curator query builders from that cache. Destructive orphan cleanup bypasses the cache and revalidates live owner references.

The migration command is:

```bash
php artisan capell:media-migrate-to-curator
```

Use `--dry-run` before a real migration. Optional filters are `--collection=*`, `--owner-type=`, and `--chunk=`.

## Screenshots

The committed media set is:

- `docs/assets/marketplace/extension-card.jpg`
- `docs/screenshots/media-health-page.png`
- `docs/screenshots/media-health-page-dark.png`
- `docs/screenshots/media-health-table.png`
- `docs/screenshots/media-health-table-dark.png`
- `docs/screenshots/curator-media-field-inside-a-form.png`
- `docs/screenshots/curator-media-field-inside-a-form-dark.png`
- `docs/screenshots/migration-command-output-or-report.png`
- `docs/screenshots/migration-command-output-or-report-dark.png`

The screenshot capture contract is [screenshots.json](screenshots.json). It defines four required scenarios: the media health page, the media health table component, a host form using the Curator media field factory, and the migration command output. The manifest lists the marketplace card plus the shipped light and dark screenshot assets.

## Pitfalls

- Install and migrate Curator before relying on `CuratorMedia`.
- Publish and fill `owner_foreign_keys` for production schemas with nonstandard media columns.
- Keep upload validation aligned with the assets editors are allowed to upload.
- Back up legacy Spatie media before running a real migration.
- Do not describe this package as generating responsive variants; it only reads existing responsive metadata or Curator/Glide URLs.

## Verification

Run the focused metadata/docs coverage test from the repository root:

```bash
vendor/bin/pest packages/media-library/tests/Unit/MediaLibraryCoverageTest.php --configuration=phpunit.xml
```

Run the package suite when behavior changes go beyond docs or manifest metadata:

```bash
vendor/bin/pest packages/media-library/tests --configuration=phpunit.xml
```
