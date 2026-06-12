# Media Library

Media Library makes Awcodes Curator the media backend for Capell. It gives packages one consistent media field, one Curator-backed media model, a media-health admin page, and an idempotent migration path from Spatie Media Library.

This is a free Foundation package. It is the media plumbing other Capell packages can build on; it is not a full DAM layer with folders, galleries, generated WebP/AVIF conversions, or a visual focal-point editor.

## At A Glance

- Package: `capell-app/media-library`
- Namespace: `Capell\MediaLibrary\`
- Surfaces: Filament admin and console
- Capell dependencies: `capell-app/admin`, `capell-app/core`
- Third-party dependency: `awcodes/filament-curator`
- Database impact: no package-owned migrations; uses Curator's `curator` table and host-app owner FK columns

## What It Adds

- Registers `Capell\MediaLibrary\Models\CuratorMedia` as the Capell media model and sets `capell.media.backend` to `curator`.
- Binds `Capell\Core\Contracts\Media\MediaFieldFactory` to `CuratorMediaFieldFactory`, which renders an Awcodes `CuratorPicker`.
- Adds `MediaHealthPage` with `MediaHealthTable` for missing alt text, stale media, and unused asset review.
- Adds content-hash duplicate, missing-alt, missing-rights-metadata, usage, orphan, and media-health query actions for reports.
- Adds `DispatchMissingAltMediaSignalsAction` and `MediaMissingAltDetected` so media-ai and other packages can subscribe to prioritized missing-alt candidates.
- Adds selected orphan cleanup through `DeleteOrphanMediaRecordsAction`, including unshared file deletion before row deletion.
- Parses Curator `exif` JSON structurally for rights metadata reports instead of matching raw JSON text.
- Adds configurable upload validation for mime types, extensions, max file size, and default visibility.
- Adds `capell:media-migrate-to-curator` and `MigrateSpatieMediaToCuratorAction` for Spatie-to-Curator migrations.
- Stores and reads Curator focal point, crop preset, and responsive image metadata when that metadata already exists.
- Defers full DAM features, generated WebP/AVIF conversions, visual crop editing, folders, galleries, and where-used drill-down to a future Media Pro layer.

## Current Boundaries

- `CuratorMedia::getSrcset()` reads Curator responsive metadata or falls back to Glide thumbnail, medium, and large URLs. The package does not generate responsive conversions or modern image formats.
- Focal points and crop presets are available through the PHP API, but the package does not ship a dedicated visual editor for them.
- Usage and orphan reports depend on configured or auto-discovered owner FK columns. Publish the config for nonstandard schemas.
- Duplicate reporting hashes readable storage files and groups byte-identical assets across different paths.
- Rights metadata reporting treats null, blank, malformed JSON, missing keys, and empty configured metadata values as incomplete.
- Private upload visibility is supported, but signed temporary URL handling is not part of this package yet.

## Install And Setup

Install the package in the host Capell application:

```bash
composer require capell-app/media-library
```

In the host app, publish the config when the site needs exact usage/orphan reporting, private default uploads, upload policy changes, or a different stale-media threshold:

```bash
php artisan vendor:publish --tag=media-library-config
```

Configure `capell.media_library.owner_foreign_keys` with every table and column that points at Curator media. The reports can auto-discover common column names when this list is empty, but explicit config is safer for production schemas:

```php
'owner_foreign_keys' => [
    ['table' => 'pages', 'column' => 'hero_image_id'],
    ['table' => 'articles', 'column' => 'thumbnail_id'],
],
```

Upload policy is controlled by:

- `capell.media_library.allowed_mime_types`
- `capell.media_library.allowed_extensions`
- `capell.media_library.max_upload_kb`
- `capell.media_library.default_visibility`
- `capell.media_library.stale_after_days`
- `capell.media_library.report_cache_ttl_seconds`

## Admin Surface

- Page: `Capell\MediaLibrary\Filament\Pages\MediaHealthPage`
- Slug: `media-health`
- Table: `Capell\MediaLibrary\Filament\Pages\Tables\MediaHealthTable`
- Access: Filament Shield page permissions

The health table shows media name, size, usage count, primary issue, mime type, and last update time. It includes an issue filter and a bulk action for deleting selected orphan media after re-validating those rows through the orphan query.

Media health and orphan reports cache their computed result rows briefly through `report_cache_ttl_seconds`; orphan deletion bypasses that cache and revalidates live references before deleting files or rows.

## Missing Alt Signals

`BuildMissingAltMediaQueryAction` returns image media with null, empty, or whitespace-only alt text, including a `usage_count` projection from configured or auto-discovered owner foreign keys. `DispatchMissingAltMediaSignalsAction` dispatches `MediaMissingAltDetected` events for that ordered queue so packages such as Media AI can generate alt text without coupling to the admin health table.

## Command

Run the migration command from the host Capell app:

```bash
php artisan capell:media-migrate-to-curator \
    --dry-run \
    --collection=image \
    --owner-type="App\\Models\\Post" \
    --chunk=500
```

Options:

- `--dry-run`: report what would happen without writing.
- `--collection=*`: restrict migration to one or more Spatie collection names.
- `--owner-type=`: restrict migration to one owner model FQCN.
- `--chunk=200`: process Spatie media rows in batches.

The migration maps Spatie rows into Curator rows, preserves supported metadata, and populates per-collection owner FK columns when those columns exist.

## Screenshots

The marketplace card lives at `docs/assets/marketplace/extension-card.jpg`.

Committed screenshot assets live in `docs/screenshots/` and include light and dark captures for:

- Media health page
- Media health table
- Curator media field inside a form
- Migration command output or report

The capture contract is [docs/screenshots.json](docs/screenshots.json). It defines the page, component, field, and console targets that should be refreshed by the deployment screenshot runner.

## Code Map

| Area     | Path                       | Purpose                                                             |
| -------- | -------------------------- | ------------------------------------------------------------------- |
| Actions  | `src/Actions`              | Query builders, orphan cleanup, and migration operations.           |
| Config   | `config/media-library.php` | Owner FK discovery, upload policy, visibility, and stale threshold. |
| Data     | `src/Data`                 | Typed migration and owner-FK payloads.                              |
| Filament | `src/Filament`             | Curator field factory and media health admin page/table.            |
| Health   | `src/Health`               | Diagnostics for backend registration, schema, and owner FK config.  |
| Models   | `src/Models`               | Curator-backed media model adapter.                                 |
| Tests    | `tests`                    | Package-level Pest coverage.                                        |

## Testing

Run package tests from the repository root:

```bash
vendor/bin/pest packages/media-library/tests --configuration=phpunit.xml
```

For focused metadata/docs changes, start with:

```bash
vendor/bin/pest packages/media-library/tests/Unit/MediaLibraryCoverageTest.php --configuration=phpunit.xml
```

## Maintenance Notes

- Put behavior changes in Actions or support classes. UI classes and commands should delegate.
- Keep public render paths free of admin/editor metadata.
- Use typed Data objects at boundaries instead of passing anonymous arrays between layers.
- Do not query from public Blade. Hydrate media render data before views receive it.
