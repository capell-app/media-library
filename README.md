# Media Library

<!-- prettier-ignore-start -->

## What This Extension Adds

Media Library is an **Available**, **No schema impact** Capell package in the **Capell Foundation** product group. It ships as `capell-app/media-library` and extends these surfaces: admin.

Make Curator the media backbone of your Capell site. One consistent media field everywhere, a media-health dashboard that surfaces missing alt text and unused assets, and a safe, idempotent migration from Spatie Media Library.

After install, admins get package-owned management or reporting surfaces inside Capell.

Status details:

- Status: Available
- Tier: free
- Bundle: foundation
- Composer package: `capell-app/media-library`
- Namespace: `Capell\MediaLibrary`
- Theme key: not applicable

## Why It Matters

**For developers:** The package gives developers package-owned service providers, Actions, Data objects, models, and Filament classes instead of pushing this behaviour into core or application code.

**For teams:** Make Curator the media backbone of your Capell site. One consistent media field everywhere, a media-health dashboard that surfaces missing alt text and unused assets, and a safe, idempotent migration from Spatie Media Library.

## Screens And Workflow

Screenshot contract: `docs/screenshots.json`.

- Media health page (admin, required).
- Media health table (admin, required).
- Curator media field inside a form (admin, required).
- Migration command output or report (console, required).

## Technical Shape

- Service providers: `Capell\MediaLibrary\MediaLibraryServiceProvider`.
- Config files: `packages/media-library/config/media-library.php`.
- Models: `CuratorMedia`.
- Filament classes: `CuratorMediaFieldFactory`, `MediaHealthPage`, `MediaHealthTable`.
- Events: `MediaMissingAltDetected`.
- Actions: `BuildDuplicateMediaQueryAction`, `BuildMediaHealthQueryAction`, `BuildMediaUsageDrilldownAction`, `BuildMissingAltMediaQueryAction`, `BuildMissingRightsMetadataQueryAction`, `BuildOrphanMediaQueryAction`, `DeleteOrphanMediaRecordsAction`, `DiscoverOwnerForeignKeysAction`, `ResolveOwnerForeignKeysAction`, `DispatchMissingAltMediaSignalsAction`, `MigrateSpatieMediaToCuratorAction`, `SanitizeSvgUploadAction`.
- Data objects: `MediaOwnerForeignKeyData`, `MediaUsageReferenceData`, `MigrateSpatieMediaInput`, `MigrateSpatieMediaResult`.
- Console command classes: `MigrateSpatieToCuratorCommand`.
- Manifest contributions: `admin-page: Capell\MediaLibrary\Manifest\MediaHealthPageContribution`, `configurator: Capell\MediaLibrary\Manifest\MediaMigrationCommandContribution`, `health-check: Capell\MediaLibrary\Manifest\MediaLibraryHealthContribution`, `model: Capell\MediaLibrary\Manifest\CuratorMediaModelContribution`.
- Health checks: `Capell\MediaLibrary\Health\MediaLibraryHealthCheck`.

## Media Handling Contract

Media Library wraps Awcodes Curator as the Capell media backend and does not generate responsive conversions. Curator-backed media exposes configured URLs and metadata; responsive variant generation must come from another package or host implementation.

Evidence and wording rules:

- The capture contract is [docs/screenshots.json](docs/screenshots.json).
- The committed screenshot captures remain runner evidence until they show populated Capell media workflows.
- Do not describe this package as generating responsive variants.
- Keep migration and media-health claims tied to the Curator model, health page, table, field factory, and migration command.

## Data Model

This package has no schema impact. It does not declare package-owned migrations or required tables.

Media usage, missing-alt, and orphan reports depend on Curator owner foreign-key references configured in `config/media-library.php` or discovered from conventional owner columns. `MediaUsageQueryExpressions` validates configured table and column names against the live schema, wraps identifiers through the active query grammar, and builds correlated count subqueries against the Curator media id. Package tests exercise the query contract with SQLite; host apps using MySQL or PostgreSQL should verify the configured owner-key list during rollout, especially after adding custom media owner tables or nonstandard foreign-key names.

## Install Impact

- Admin navigation: adds package-owned Filament classes when registered.
- Permissions: `View:MediaHealthPage`, `Delete:MediaHealthPage`.
- Public routes: none detected in package route files.
- Database changes: no package migrations declared.
- Settings: no package settings declared.
- Queues or schedules: none detected in standard package paths.
- Cache tags: none declared.
- Commands: console command classes detected: `MigrateSpatieToCuratorCommand`.

## Common Pitfalls

- Verify the package is installed before expecting its provider, views, or extension contributions to run.
- Keep `composer.json`, `composer.local.json`, `capell.json`, docs, screenshots, and tests aligned when the package surface changes.

## Troubleshooting

| Symptom | Likely cause | Check | Fix |
| --- | --- | --- | --- |
| Package surface is missing after install | Provider or manifest is not loaded | Confirm `capell.json`, package `composer.json`, and provider registration | Reinstall the package, refresh Composer autoload, and clear host caches |

## Quick Start

1. Install the package: `composer require capell-app/media-library`.
2. Run the required setup: no package migrations are declared; clear cached config and routes if the host app uses caches.
3. Open the related Capell admin surface and verify Media Library appears.

## Next Steps

- [Package docs](docs/README.md)
- [Overview](docs/overview.md)
- [Screenshot contract](docs/screenshots.json)
- [Marketplace assets](docs/assets/marketplace/)
- [Capell content language plan](../../docs/CONTENT_LANGUAGE_PLAN.md)
- [Capell documentation design system](../../docs/DESIGN_SYSTEM.md)
- [Capell and package ERD notes](../../docs/erd/capell-and-package-erds.md)
- Related packages: [Media Ai](../media-ai/README.md), [Seo Suite](../seo-suite/README.md).
- Focused tests: `vendor/bin/pest packages/media-library/tests --configuration=phpunit.xml`.

<!-- prettier-ignore-end -->
