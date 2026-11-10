# Media Library reference

<!-- prettier-ignore-start -->

Developer reference for `capell-app/media-library`. Start with the [README](../README.md) for what the extension does and how to install it.

## Package Details

Media Library is an **Available**, **Schema-owning** Capell package in the **Capell Essentials** product group. It ships as `capell-app/media-library` and extends these surfaces: admin.

- Status: Available
- Tier: free
- Bundle: foundation
- Composer package: `capell-app/media-library`
- Namespace: `Capell\MediaLibrary`
- Theme key: not applicable

## Technical Shape

### Service providers

- `Capell\MediaLibrary\MediaLibraryServiceProvider`

### Config files

- `packages/media-library/config/media-library.php`

### Migrations

- `packages/media-library/database/migrations/2026_10_15_000001_create_curator_table.php`
- `packages/media-library/database/migrations/2026_11_01_000001_add_deleted_at_to_curator_table.php`

### Models

- `CuratorMedia`

### Filament classes

- `CuratorMultiUploadAction`
- `CuratorMediaFieldFactory`
- `CuratorUploader`
- `MediaHealthPage`
- `MediaHealthTable`
- `CuratorMediaForm`

### Livewire components

- `CuratorPanel`

### Policies

- `CuratorMediaPolicy`

### Extension contracts

- `MediaUsageReferenceResolver`

### Events

- `MediaMissingAltDetected`

### Actions

- `BuildMediaRepairPreviewAction`
- `BuildDuplicateMediaQueryAction`
- `BuildMediaHealthQueryAction`
- `BuildMediaHealthTotalsAction`
- `BuildMediaUsageDrilldownAction`
- `BuildMissingAltMediaQueryAction`
- `BuildMissingRightsMetadataQueryAction`
- `BuildOrphanMediaQueryAction`
- `DeleteOrphanMediaRecordsAction`
- `DiscoverOwnerForeignKeysAction`
- `ResolveOwnerForeignKeysAction`
- `DispatchMissingAltMediaSignalsAction`
- `EnsureMediaLibraryPermissionsAction`
- `MigrateSpatieMediaToCuratorAction`
- `PurgeQuarantinedMediaAction`
- `RestrictMediaWriteAction`
- `SanitizeSvgUploadAction`
- `UpdateMediaAltTextAction`

### Data objects

- `MediaHealthIssuesData`
- `MediaHealthTotalsData`
- `MediaOwnerForeignKeyData`
- `MediaRepairPreviewData`
- `MediaUsageReferenceData`
- `MigrateSpatieMediaInput`
- `MigrateSpatieMediaResult`
- `PurgeQuarantinedMediaResult`

### Jobs

- `CalculateMediaChecksumJob`

### Manifest action API

- `buildDuplicateMediaQuery: Capell\MediaLibrary\Actions\DashboardReports\BuildDuplicateMediaQueryAction`
- `buildMediaHealthQuery: Capell\MediaLibrary\Actions\DashboardReports\BuildMediaHealthQueryAction`
- `buildMediaHealthTotals: Capell\MediaLibrary\Actions\DashboardReports\BuildMediaHealthTotalsAction`
- `buildMediaUsageDrilldown: Capell\MediaLibrary\Actions\DashboardReports\BuildMediaUsageDrilldownAction`
- `buildMissingAltMediaQuery: Capell\MediaLibrary\Actions\DashboardReports\BuildMissingAltMediaQueryAction`
- `buildMissingRightsMetadataQuery: Capell\MediaLibrary\Actions\DashboardReports\BuildMissingRightsMetadataQueryAction`
- `buildOrphanMediaQuery: Capell\MediaLibrary\Actions\DashboardReports\BuildOrphanMediaQueryAction`
- `deleteOrphanMediaRecords: Capell\MediaLibrary\Actions\DashboardReports\DeleteOrphanMediaRecordsAction`
- `dispatchMissingAltMediaSignals: Capell\MediaLibrary\Actions\DispatchMissingAltMediaSignalsAction`
- `migrateSpatieMediaToCurator: Capell\MediaLibrary\Actions\MigrateSpatieMediaToCuratorAction`

### Console command classes

- `MigrateSpatieToCuratorCommand`
- `PurgeQuarantinedMediaCommand`

### Manifest contributions

- `admin-page: Capell\MediaLibrary\Manifest\MediaHealthPageContribution`
- `configurator: Capell\MediaLibrary\Manifest\MediaMigrationCommandContribution`
- `health-check: Capell\MediaLibrary\Manifest\MediaLibraryHealthContribution`
- `model: Capell\MediaLibrary\Manifest\CuratorMediaModelContribution`

### Health checks

- `Capell\MediaLibrary\Health\MediaLibraryHealthCheck`

### Blade views

- `packages/media-library/resources/views/admin/media-repair-preview.blade.php`


## Data Model

- Required tables: `curator`.
- Models: `CuratorMedia`.
- Migration files: `2026_10_15_000001_create_curator_table.php`, `2026_11_01_000001_add_deleted_at_to_curator_table.php`.
- Migration impact: run host migrations through the package install flow before opening package surfaces.
- Deletion/retention behaviour: Docs gap: migrations and manifest contributions do not prove a cascade, pruning command, or timed retention policy.

<!-- prettier-ignore-end -->
