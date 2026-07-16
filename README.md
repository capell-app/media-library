# Media Library

<!-- prettier-ignore-start -->

## What This Plugin Adds

Media Library is an **Available**, **No schema impact** Capell package in the **Capell Foundation** product group. It ships as `capell-app/media-library` and extends these surfaces: admin.

Media Library provides a Curator-backed asset library with shared picking, folders, search, alt text, rights metadata, and media health checks.

Editors can upload, organize, find, describe, and reuse assets from one library. The media health page identifies missing alt text, stale assets, and unused records.

Evidence: [`src/MediaLibraryServiceProvider.php`](src/MediaLibraryServiceProvider.php), [`src/Models/CuratorMedia.php`](src/Models/CuratorMedia.php), [`src/Filament/Pages/MediaHealthPage.php`](src/Filament/Pages/MediaHealthPage.php), [`tests/Integration/CuratorBackendTest.php`](tests/Integration/CuratorBackendTest.php), [`docs/overview.admin.md`](docs/overview.admin.md), [`src/Actions/DashboardReports/BuildMediaHealthQueryAction.php`](src/Actions/DashboardReports/BuildMediaHealthQueryAction.php), [`src/Actions/DashboardReports/BuildDuplicateMediaQueryAction.php`](src/Actions/DashboardReports/BuildDuplicateMediaQueryAction.php), [`src/Actions/DashboardReports/BuildOrphanMediaQueryAction.php`](src/Actions/DashboardReports/BuildOrphanMediaQueryAction.php).

Status details:

- Status: Available
- Tier: free
- Bundle: foundation
- Composer package: `capell-app/media-library`
- Namespace: `Capell\MediaLibrary`
- Theme key: not applicable

## Why It Matters

**For developers:** The Curator model and query factory give packages a shared media boundary, with migration and SVG sanitization handled by dedicated Actions.

**For teams:** Teams can reuse approved assets, improve alt-text coverage, and find media records that need cleanup from one admin workflow.

Evidence: [`src/Models/CuratorMedia.php`](src/Models/CuratorMedia.php), [`src/Support/CuratorMediaQueryFactory.php`](src/Support/CuratorMediaQueryFactory.php), [`src/Actions/MigrateSpatieMediaToCuratorAction.php`](src/Actions/MigrateSpatieMediaToCuratorAction.php), [`src/Actions/SanitizeSvgUploadAction.php`](src/Actions/SanitizeSvgUploadAction.php), [`tests/Integration/MediaHealthTest.php`](tests/Integration/MediaHealthTest.php), [`tests/Integration/MediaRightsMetadataQueryTest.php`](tests/Integration/MediaRightsMetadataQueryTest.php), [`tests/Feature/FilamentSaveTest.php`](tests/Feature/FilamentSaveTest.php).

## Screens And Workflow

Screenshot contract: `docs/screenshots.json`.

![Media health page](docs/screenshots/media-health-page.png)

![Media health table](docs/screenshots/media-health-table.png)

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
- Actions: `BuildDuplicateMediaQueryAction`, `BuildMediaHealthQueryAction`, `BuildMediaUsageDrilldownAction`, `BuildMissingAltMediaQueryAction`, `BuildMissingRightsMetadataQueryAction`, `BuildOrphanMediaQueryAction`, `DeleteOrphanMediaRecordsAction`, `DiscoverOwnerForeignKeysAction`, `ResolveOwnerForeignKeysAction`, `DispatchMissingAltMediaSignalsAction`, `EnsureMediaLibraryPermissionsAction`, `MigrateSpatieMediaToCuratorAction`, `and 1 more`.
- Data objects: `MediaOwnerForeignKeyData`, `MediaUsageReferenceData`, `MigrateSpatieMediaInput`, `MigrateSpatieMediaResult`.
- Jobs: `CalculateMediaChecksumJob`.
- Manifest action API: `buildDuplicateMediaQuery: Capell\MediaLibrary\Actions\DashboardReports\BuildDuplicateMediaQueryAction`, `buildMediaHealthQuery: Capell\MediaLibrary\Actions\DashboardReports\BuildMediaHealthQueryAction`, `buildMediaUsageDrilldown: Capell\MediaLibrary\Actions\DashboardReports\BuildMediaUsageDrilldownAction`, `buildMissingAltMediaQuery: Capell\MediaLibrary\Actions\DashboardReports\BuildMissingAltMediaQueryAction`, `buildMissingRightsMetadataQuery: Capell\MediaLibrary\Actions\DashboardReports\BuildMissingRightsMetadataQueryAction`, `buildOrphanMediaQuery: Capell\MediaLibrary\Actions\DashboardReports\BuildOrphanMediaQueryAction`, `deleteOrphanMediaRecords: Capell\MediaLibrary\Actions\DashboardReports\DeleteOrphanMediaRecordsAction`, `dispatchMissingAltMediaSignals: Capell\MediaLibrary\Actions\DispatchMissingAltMediaSignalsAction`, `migrateSpatieMediaToCurator: Capell\MediaLibrary\Actions\MigrateSpatieMediaToCuratorAction`.
- Console command classes: `MigrateSpatieToCuratorCommand`.
- Manifest contributions: `admin-page: Capell\MediaLibrary\Manifest\MediaHealthPageContribution`, `configurator: Capell\MediaLibrary\Manifest\MediaMigrationCommandContribution`, `health-check: Capell\MediaLibrary\Manifest\MediaLibraryHealthContribution`, `model: Capell\MediaLibrary\Manifest\CuratorMediaModelContribution`.
- Health checks: `Capell\MediaLibrary\Health\MediaLibraryHealthCheck`.

## Media Handling Contract

Media Library wraps Awcodes Curator as the Capell media backend and does not generate responsive conversions. Curator-backed media exposes configured URLs and metadata; responsive variant generation must come from another package or host implementation.

Evidence and wording rules:

- The capture contract is [docs/screenshots.json](docs/screenshots.json).
- The committed screenshot captures show seeded media-health, Curator field, and migration-report workflows from the package workbench.
- Do not describe this package as generating responsive variants.
- Keep migration and media-health claims tied to the Curator model, health page, table, field factory, and migration command.

## Data Model

This package has no schema impact. It extends Capell through `admin-page` contributions, `configurator` contributions, `health-check` contributions, and `model` contributions instead of declaring package-owned tables.

## Install Impact

- Required packages: `capell-app/admin`, `capell-app/core`.
- Admin navigation: declares `admin-page: MediaHealthPageContribution`; each Filament page or resource controls its own navigation visibility.
- Admin/editor extensions: `configurator: MediaMigrationCommandContribution`.
- Permissions: `View:MediaHealthPage`, `Delete:MediaHealthPage`.
- Public routes: none declared.
- Database changes: no package migrations declared.
- Config: `config/media-library.php`.
- Settings: no package settings declared.
- Queues or schedules: queue jobs `CalculateMediaChecksumJob`.
- Cache tags: none declared.
- Commands: console command classes detected: `MigrateSpatieToCuratorCommand`.

## Common Pitfalls

- Keep required Capell packages on compatible v4 releases: `capell-app/admin`, `capell-app/core`.
- Review package configuration before production-like verification: `config/media-library.php`.

## Troubleshooting

| Symptom | Likely cause | Check | Fix |
| --- | --- | --- | --- |
| Package surface is missing after install | Provider or manifest is not loaded | Confirm `capell.json`, package `composer.json`, and provider registration | Reinstall the package, refresh Composer autoload, and clear host caches |
| Background work does not run | Queue worker or declared schedule is not active | Check the jobs and scheduled commands listed in `Technical Shape` | Start the queue worker or host scheduler, then run the focused command or package test |

## Quick Start

1. Install the package: `composer require capell-app/media-library`.
2. Review `config/media-library.php` before enabling the package.
3. Open the Media health page and confirm the admin workflow loads.

## Next Steps

- [Package docs](docs/README.md)
- [Overview](docs/overview.md)
- [Admin guide](docs/admin-guide.md)
- Configuration files: [`config/media-library.php`](config/media-library.php).
- [Troubleshooting](#troubleshooting)
- [Screenshot contract](docs/screenshots.json)
- [Marketplace assets](docs/assets/marketplace/)
- [Capell content language plan](../../docs/CONTENT_LANGUAGE_PLAN.md)
- [Capell documentation design system](../../docs/DESIGN_SYSTEM.md)
- [Capell and package ERD notes](../../docs/erd/capell-and-package-erds.md)
- Related packages: [Media Ai](../media-ai/README.md), [Seo Suite](../seo-suite/README.md).
- Focused tests: `vendor/bin/pest packages/media-library/tests --configuration=phpunit.xml`.

<!-- prettier-ignore-end -->
