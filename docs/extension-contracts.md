# Worked extension examples

These developer-facing recipes are kept beside the package contract. Replace the example values with the site-specific records and data objects used by the calling workflow.

<!-- example: action buildDuplicateMediaQuery -->

```php
<?php
declare(strict_types=1);
$inputs = []; // Supply the arguments required by the action handle() method.
resolve(\Capell\MediaLibrary\Actions\DashboardReports\BuildDuplicateMediaQueryAction::class)->handle(...$inputs);
```

<!-- example: action buildMediaHealthQuery -->

```php
<?php
declare(strict_types=1);
$inputs = []; // Supply the arguments required by the action handle() method.
resolve(\Capell\MediaLibrary\Actions\DashboardReports\BuildMediaHealthQueryAction::class)->handle(...$inputs);
```

<!-- example: action buildMediaHealthTotals -->

```php
<?php
declare(strict_types=1);
$inputs = []; // Supply the arguments required by the action handle() method.
resolve(\Capell\MediaLibrary\Actions\DashboardReports\BuildMediaHealthTotalsAction::class)->handle(...$inputs);
```

<!-- example: action buildMediaUsageDrilldown -->

```php
<?php
declare(strict_types=1);
$inputs = []; // Supply the arguments required by the action handle() method.
resolve(\Capell\MediaLibrary\Actions\DashboardReports\BuildMediaUsageDrilldownAction::class)->handle(...$inputs);
```

<!-- example: action buildMissingAltMediaQuery -->

```php
<?php
declare(strict_types=1);
$inputs = []; // Supply the arguments required by the action handle() method.
resolve(\Capell\MediaLibrary\Actions\DashboardReports\BuildMissingAltMediaQueryAction::class)->handle(...$inputs);
```

<!-- example: action buildMissingRightsMetadataQuery -->

```php
<?php
declare(strict_types=1);
$inputs = []; // Supply the arguments required by the action handle() method.
resolve(\Capell\MediaLibrary\Actions\DashboardReports\BuildMissingRightsMetadataQueryAction::class)->handle(...$inputs);
```

<!-- example: action buildOrphanMediaQuery -->

```php
<?php
declare(strict_types=1);
$inputs = []; // Supply the arguments required by the action handle() method.
resolve(\Capell\MediaLibrary\Actions\DashboardReports\BuildOrphanMediaQueryAction::class)->handle(...$inputs);
```

<!-- example: action deleteOrphanMediaRecords -->

```php
<?php
declare(strict_types=1);
$inputs = []; // Supply the arguments required by the action handle() method.
resolve(\Capell\MediaLibrary\Actions\DashboardReports\DeleteOrphanMediaRecordsAction::class)->handle(...$inputs);
```

<!-- example: action dispatchMissingAltMediaSignals -->

```php
<?php
declare(strict_types=1);
$inputs = []; // Supply the arguments required by the action handle() method.
resolve(\Capell\MediaLibrary\Actions\DispatchMissingAltMediaSignalsAction::class)->handle(...$inputs);
```

<!-- example: action migrateSpatieMediaToCurator -->

```php
<?php
declare(strict_types=1);
$inputs = []; // Supply the arguments required by the action handle() method.
resolve(\Capell\MediaLibrary\Actions\MigrateSpatieMediaToCuratorAction::class)->handle(...$inputs);
```

<!-- example: contract Capell\MediaLibrary\Contracts\MediaUsageReferenceResolver -->

```php
<?php
declare(strict_types=1);
use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps media that a host-owned table still points at. Return every candidate
 * you cannot prove unused: a false positive only delays a clean-up, a false
 * negative deletes content that is still on the site.
 */
final class ExampleGalleryUsageResolver implements \Capell\MediaLibrary\Contracts\MediaUsageReferenceResolver
{
    /**
     * @param  Collection<int, CuratorMedia>  $media
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys
     * @return list<int>
     */
    public function referencedMediaIds(Collection $media, ?array $ownerForeignKeys = null): array
    {
        $candidateIds = $media->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        if ($candidateIds === []) {
            return [];
        }

        $references = [['table' => 'gallery_items', 'column' => 'media_id'], ...($ownerForeignKeys ?? [])];
        $referenced = [];

        foreach ($references as $reference) {
            $referenced = [
                ...$referenced,
                ...DB::table($reference['table'])
                    ->whereIn($reference['column'], $candidateIds)
                    ->distinct()
                    ->pluck($reference['column'])
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all(),
            ];
        }

        return array_values(array_unique($referenced));
    }
}

app()->singleton(ExampleGalleryUsageResolver::class);
// Media Library resolves the classes listed in config('capell.media_library.usage_reference_resolvers'), in order,
// so add ExampleGalleryUsageResolver::class to that list in config/capell.php.
```
