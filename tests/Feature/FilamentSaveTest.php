<?php

declare(strict_types=1);

use Capell\Core\Contracts\Media\MediaFieldFactory;
use Capell\MediaLibrary\Filament\Components\CuratorMediaFieldFactory;

test('factory make returns the configured Curator field name', function (): void {
    $field = (new CuratorMediaFieldFactory)->make('image');

    expect($field->getName())->toBe('image_id');
});

test('MediaFieldFactory contract resolves to CuratorMediaFieldFactory when plugin registered', function (): void {
    expect(app()->get(MediaFieldFactory::class))->toBeInstanceOf(CuratorMediaFieldFactory::class);
});

test('capell media backend config key is set to curator', function (): void {
    expect(config('capell.media.backend'))->toBe('curator');
});
