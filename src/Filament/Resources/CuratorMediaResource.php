<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Filament\Resources;

use Awcodes\Curator\Resources\Media\MediaResource;
use Capell\MediaLibrary\Support\CuratorMediaSiteListConstraint;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * Curator's library resource, listing only the media the signed-in editor may use on their sites.
 */
class CuratorMediaResource extends MediaResource
{
    #[Override]
    public static function getModelLabel(): string
    {
        return (string) __('capell-media-library::package.curator_resource.model_label');
    }

    #[Override]
    public static function getPluralModelLabel(): string
    {
        return (string) __('capell-media-library::package.curator_resource.plural_model_label');
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-media-library::package.curator_resource.navigation_label');
    }

    #[Override]
    public static function getSlug(?Panel $panel = null): string
    {
        return 'media-library/curator-media';
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        resolve(CuratorMediaSiteListConstraint::class)->apply($query, auth()->user());

        return $query;
    }
}
