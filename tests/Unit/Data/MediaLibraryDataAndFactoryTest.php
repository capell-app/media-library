<?php

declare(strict_types=1);

use Awcodes\Curator\Components\Forms\CuratorPicker;
use Capell\MediaLibrary\Data\MigrateSpatieMediaInput;
use Capell\MediaLibrary\Data\MigrateSpatieMediaResult;
use Capell\MediaLibrary\Filament\Components\CuratorMediaFieldFactory;
use Capell\MediaLibrary\Health\MediaLibraryHealthCheck;

it('carries media migration input and result data', function (): void {
    $defaultInput = new MigrateSpatieMediaInput;
    $input = new MigrateSpatieMediaInput(
        dryRun: true,
        collections: ['hero', 'gallery'],
        chunkSize: 50,
        ownerType: 'App\\Models\\Page',
    );
    $result = new MigrateSpatieMediaResult(
        processed: 10,
        created: 6,
        skipped: 3,
        ownersUpdated: 2,
        warnings: ['Missing owner for media 9.'],
    );

    expect($defaultInput->dryRun)->toBeFalse()
        ->and($defaultInput->collections)->toBe([])
        ->and($defaultInput->chunkSize)->toBe(200)
        ->and($input->dryRun)->toBeTrue()
        ->and($input->collections)->toBe(['hero', 'gallery'])
        ->and($input->ownerType)->toBe('App\\Models\\Page')
        ->and($result->processed)->toBe(10)
        ->and($result->warnings)->toBe(['Missing owner for media 9.']);
});

it('builds curator media fields and declares package health compatibility', function (): void {
    $field = (new CuratorMediaFieldFactory)->make('featured_image_id');
    $imageField = (new CuratorMediaFieldFactory)->make('image');
    $socialImageField = (new CuratorMediaFieldFactory)->make('socialImage');

    expect($field)->toBeInstanceOf(CuratorPicker::class)
        ->and($field->getName())->toBe('featured_image_id')
        ->and($imageField->getName())->toBe('image_id')
        ->and($socialImageField->getName())->toBe('social_image_id')
        ->and(MediaLibraryHealthCheck::compatibleCapellApiVersion())->toBe('^0.0');
});
