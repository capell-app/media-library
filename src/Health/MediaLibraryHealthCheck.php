<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Health;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Contracts\Media\MediaFieldFactory;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\MediaLibrary\Actions\DashboardReports\ResolveOwnerForeignKeysAction;
use Capell\MediaLibrary\Data\MediaOwnerForeignKeyData;
use Capell\MediaLibrary\Filament\Components\CuratorMediaFieldFactory;
use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class MediaLibraryHealthCheck implements ChecksExtensionHealth
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
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
            label: $this->translation('backend.label'),
            passed: $backendRegistered,
            message: $backendRegistered
                ? $this->translation('backend.passed')
                : $this->translation('backend.failed'),
            remediation: $backendRegistered
                ? null
                : $this->translation('backend.remediation'),
        );
    }

    /**
     * Asserts the Curator storage table exists.
     */
    public function curatorTableCheck(): DoctorCheckResultData
    {
        $tableExists = $this->curatorTableExists();

        return new DoctorCheckResultData(
            label: $this->translation('curator_table.label'),
            passed: $tableExists,
            message: $tableExists
                ? $this->translation('curator_table.passed')
                : $this->translation('curator_table.failed'),
            remediation: $tableExists
                ? null
                : $this->translation('curator_table.remediation'),
        );
    }

    /**
     * Warns when no owner foreign keys are configured or discovered, which
     * makes the usage and orphan reports silently inert.
     */
    public function ownerForeignKeysConfiguredCheck(): DoctorCheckResultData
    {
        $configured = $this->hasOwnerForeignKeysConfigured();

        return new DoctorCheckResultData(
            label: $this->translation('owner_foreign_keys.label'),
            passed: $configured,
            message: $configured
                ? $this->translation('owner_foreign_keys.passed')
                : $this->translation('owner_foreign_keys.failed'),
            remediation: $configured
                ? null
                : $this->translation('owner_foreign_keys.remediation'),
        );
    }

    public function isCuratorBackendRegistered(): bool
    {
        return config('capell.media.backend') === 'curator'
            && config('capell.media.model') === CuratorMedia::class
            && $this->hasCuratorFieldFactoryBinding();
    }

    public function curatorTableExists(): bool
    {
        return Schema::hasTable('curator');
    }

    public function hasOwnerForeignKeysConfigured(): bool
    {
        $ownerForeignKeys = config('capell.media_library.owner_foreign_keys', []);
        $validOwnerForeignKeys = $this->validOwnerForeignKeys();

        if (! is_array($ownerForeignKeys) || $ownerForeignKeys === []) {
            return $validOwnerForeignKeys !== [];
        }

        return count($validOwnerForeignKeys) === count($ownerForeignKeys);
    }

    public function hasCuratorFieldFactoryBinding(): bool
    {
        try {
            return $this->resolveMediaFieldFactory() instanceof CuratorMediaFieldFactory;
        } catch (BindingResolutionException) {
            return false;
        }
    }

    /**
     * @return array<int, MediaOwnerForeignKeyData>
     */
    public function validOwnerForeignKeys(): array
    {
        return ResolveOwnerForeignKeysAction::run();
    }

    private function resolveMediaFieldFactory(): object
    {
        return resolve(MediaFieldFactory::class);
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    private function translation(string $key, array $replace = []): string
    {
        return __('capell-media-library::package.health.' . $key, $replace);
    }
}
