<?php

declare(strict_types=1);

use Capell\MediaLibrary\Filament\Resources\CuratorMediaResource;

it('labels the media resource from Capell translations without a registered Curator plugin', function (): void {
    expect(CuratorMediaResource::getModelLabel())->toBe(__('capell-media-library::package.curator_resource.model_label'))
        ->and(CuratorMediaResource::getPluralModelLabel())->toBe(__('capell-media-library::package.curator_resource.plural_model_label'))
        ->and(CuratorMediaResource::getNavigationLabel())->toBe(__('capell-media-library::package.curator_resource.navigation_label'))
        ->and(CuratorMediaResource::getNavigationLabel())->not->toBe('capell-media-library::package.curator_resource.navigation_label');
});
