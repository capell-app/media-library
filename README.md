# Media Curator

Status: **Available, no schema impact in this package** · Kind: **package** · Tier: **free** · Bundle: **foundation** · Contexts: **admin** · Product group: **Capell Foundation**

## What This Plugin Adds

Media Curator connects Capell to Awcodes Curator media, media health reporting, and Spatie Media migration support.

- Curator media model wrapper.
- Media health admin page and table.
- Curator media field factory.
- Migration command and action for moving Spatie media into Curator.
- InteractsWithCuratorMedia concern.

## Why It Matters

**For developers:** Centralises Curator integration behind actions, field factories, and model concerns so packages can use media fields consistently.

**For teams:** Helps site operators audit media records and move legacy media into the current Capell media foundation.

## Screens And Workflow

Screenshots are generated from [docs/screenshots.json](docs/screenshots.json) during package deployment.

- Media health page.
- Media health table.
- Curator media field inside a form.
- Migration command output or report.

## Technical Shape

- CapellMediaCuratorServiceProvider registers the package.
- Model: CuratorMedia.
- Command: MigrateSpatieToCuratorCommand.
- Action: MigrateSpatieMediaToCuratorAction.
- Page: MediaHealthPage.
- No migrations are present in this package.

## Data Model

- This package does not define its own migrations.
- It relies on Curator and existing media tables.
- Migration result data records counts and outcomes for Spatie-to-Curator moves.

## Install Impact

- Adds Curator media field integration.
- Adds media health admin page.
- Adds migration command.
- No package-owned database changes.

## Commands

- None proven in this package directory.

## Admin And Access

- MediaHealthPage (packages/media-curator/src/Filament/Pages/MediaHealthPage.php, slug `media-health`)

- Gate: MediaHealthPage: Filament Shield page permissions

## Common Pitfalls

- Install and migrate Curator before relying on CuratorMedia.
- Back up legacy Spatie media before migration.
- Check disk paths and conversions before bulk migration.

## Quick Start

1. Install the package with `composer require capell-app/media-curator`.
2. Register the package provider through Composer discovery and clear cached config if the host app uses config caching.
3. Open the new admin surface or integration point and verify the result.

## Next Steps

- [docs/overview.md](docs/overview.md)
- [../mosaic/README.md](../mosaic/README.md)
- [../backup/README.md](../backup/README.md)
