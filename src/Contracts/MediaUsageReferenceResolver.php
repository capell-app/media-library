<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Contracts;

use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Support\Collection;

/**
 * Reports which candidate media are still referenced somewhere in the host.
 *
 * Resolvers err on the side of keeping media: a false positive only delays a
 * clean-up, a false negative destroys content that is still in use.
 */
interface MediaUsageReferenceResolver
{
    /**
     * @param  Collection<int, CuratorMedia>  $media  Candidate media rows.
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys  Owner keys passed by the caller, if any.
     * @return list<int> The ids of the candidates that are referenced.
     */
    public function referencedMediaIds(Collection $media, ?array $ownerForeignKeys = null): array;
}
