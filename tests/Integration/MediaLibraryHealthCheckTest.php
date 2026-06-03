<?php

declare(strict_types=1);

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\MediaLibrary\Health\MediaLibraryHealthCheck;
use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

test('reports a compatible capell api version', function (): void {
    expect(MediaLibraryHealthCheck::compatibleCapellApiVersion())->toBe('^4.0');
});

test('runs real diagnostics returning check results', function (): void {
    $results = MediaLibraryHealthCheck::runDiagnostics();

    expect($results)->toHaveCount(3)
        ->and($results->every(static fn (mixed $result): bool => $result instanceof DoctorCheckResultData))->toBeTrue();
});

test('passes when curator backend, table and owner foreign keys are present', function (): void {
    Config::set('capell.media.backend', 'curator');
    Config::set('capell.media.model', CuratorMedia::class);
    Config::set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);

    $check = new MediaLibraryHealthCheck;

    expect($check->isCuratorBackendRegistered())->toBeTrue()
        ->and($check->curatorTableExists())->toBeTrue()
        ->and($check->hasOwnerForeignKeysConfigured())->toBeTrue()
        ->and(MediaLibraryHealthCheck::passed())->toBeTrue();
});

test('fails the backend check when curator is not the registered backend', function (): void {
    Config::set('capell.media.backend', 'spatie');

    $check = new MediaLibraryHealthCheck;

    expect($check->isCuratorBackendRegistered())->toBeFalse()
        ->and($check->backendRegisteredCheck()->passed)->toBeFalse()
        ->and(MediaLibraryHealthCheck::passed())->toBeFalse();
});

test('fails the curator table check when the table is missing', function (): void {
    Schema::dropIfExists('curator');

    $check = new MediaLibraryHealthCheck;

    expect($check->curatorTableExists())->toBeFalse()
        ->and($check->curatorTableCheck()->passed)->toBeFalse()
        ->and(MediaLibraryHealthCheck::passed())->toBeFalse();
});

test('fails the owner foreign keys check when none are configured', function (): void {
    Config::set('capell.media_library.owner_foreign_keys', []);

    $check = new MediaLibraryHealthCheck;

    expect($check->hasOwnerForeignKeysConfigured())->toBeFalse()
        ->and($check->ownerForeignKeysConfiguredCheck()->passed)->toBeFalse();
});
