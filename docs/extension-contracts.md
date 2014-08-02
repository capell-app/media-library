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
