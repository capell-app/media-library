<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support;

use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Override;

/**
 * Inert unless a Curator list or search query is running (see CuratorMediaListContext).
 *
 * @implements Scope<CuratorMedia>
 */
final class CuratorMediaSiteListScope implements Scope
{
    #[Override]
    public function apply(Builder $builder, Model $model): void
    {
        if (! resolve(CuratorMediaListContext::class)->active()) {
            return;
        }

        resolve(CuratorMediaSiteListConstraint::class)->apply($builder, auth()->user());
    }
}
