<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Health;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class MediaLibraryHealthCheck implements ChecksExtensionHealth
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^4.0';
    }

    /**
     * @return Collection<int, DoctorCheckResultData>
     */
    public static function runDiagnostics(): Collection
    {
        $check = new self;

        return collect([
            $check->backendRegisteredCheck(),
            $check->curatorTableCheck(),
            $check->ownerForeignKeysConfiguredCheck(),
        ]);
    }

    public static function passed(): bool
    {
        return self::runDiagnostics()
            ->every(static fn (DoctorCheckResultData $result): bool => $result->passed);
    }

    /**
     * Asserts Curator is registered as the active Capell media backend and model.
     */
    public function backendRegisteredCheck(): DoctorCheckResultData
    {
        $backendRegistered = $this->isCuratorBackendRegistered();

        return new DoctorCheckResultData(
            label: 'Curator media backend registered',
            passed: $backendRegistered,
            message: $backendRegistered
                ? 'Curator is registered as the Capell media backend and model.'
                : 'The Capell media backend is not pointed at Curator.',
            remediation: $backendRegistered
                ? null
                : 'Ensure MediaLibraryServiceProvider runs so capell.media.backend is "curator" and capell.media.model is CuratorMedia.',
        );
    }

    /**
     * Asserts the Curator storage table exists.
     */
    public function curatorTableCheck(): DoctorCheckResultData
    {
        $tableExists = $this->curatorTableExists();

        return new DoctorCheckResultData(
            label: 'Curator media table',
            passed: $tableExists,
            message: $tableExists
                ? 'The "curator" media table is present.'
                : 'The "curator" media table is missing.',
            remediation: $tableExists
                ? null
                : 'Run the Awcodes Curator migrations to create the "curator" table.',
        );
    }

    /**
     * Warns when no owner foreign keys are configured, which makes the usage
     * and orphan reports silently inert.
     */
    public function ownerForeignKeysConfiguredCheck(): DoctorCheckResultData
    {
        $configured = $this->hasOwnerForeignKeysConfigured();

        return new DoctorCheckResultData(
            label: 'Media usage owner foreign keys',
            passed: $configured,
            message: $configured
                ? 'Owner foreign keys are configured for usage and orphan reporting.'
                : 'No owner foreign keys are configured; usage and orphan reports will return nothing.',
            remediation: $configured
                ? null
                : 'Set capell.media_library.owner_foreign_keys (publish config/media-library.php) with each [table, column] referencing Curator media.',
        );
    }

    public function isCuratorBackendRegistered(): bool
    {
        return config('capell.media.backend') === 'curator'
            && config('capell.media.model') === CuratorMedia::class;
    }

    public function curatorTableExists(): bool
    {
        return Schema::hasTable('curator');
    }

    public function hasOwnerForeignKeysConfigured(): bool
    {
        $ownerForeignKeys = config('capell.media_library.owner_foreign_keys', []);

        return is_array($ownerForeignKeys) && $ownerForeignKeys !== [];
    }
}
