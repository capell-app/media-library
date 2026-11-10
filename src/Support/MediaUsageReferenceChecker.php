<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support;

use Capell\MediaLibrary\Contracts\MediaUsageReferenceResolver;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\UsageResolvers\ContentGraphUsageResolver;
use Capell\MediaLibrary\Support\UsageResolvers\OwnerForeignKeyUsageResolver;
use Capell\MediaLibrary\Support\UsageResolvers\StructuredColumnUsageResolver;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Runs every configured usage resolver and unions their answers. A resolver
 * that is misconfigured throws, so destructive callers fail closed.
 */
final class MediaUsageReferenceChecker
{
    /**
     * @param  Collection<int, CuratorMedia>  $media
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys
     * @return list<int>
     */
    public function referencedMediaIds(Collection $media, ?array $ownerForeignKeys = null): array
    {
        if ($media->isEmpty()) {
            return [];
        }

        $referenced = [];

        foreach ($this->resolvers() as $resolver) {
            foreach ($resolver->referencedMediaIds($media, $ownerForeignKeys) as $mediaId) {
                $referenced[$mediaId] = $mediaId;
            }
        }

        return array_values($referenced);
    }

    /**
     * @return list<MediaUsageReferenceResolver>
     */
    public function resolvers(): array
    {
        $configured = config('capell.media_library.usage_reference_resolvers');

        if (! is_array($configured)) {
            $configured = [
                OwnerForeignKeyUsageResolver::class,
                ContentGraphUsageResolver::class,
                StructuredColumnUsageResolver::class,
            ];
        }

        $resolvers = [];

        foreach ($configured as $class) {
            $resolver = is_string($class) ? resolve($class) : $class;

            throw_unless(
                $resolver instanceof MediaUsageReferenceResolver,
                InvalidArgumentException::class,
                'Media usage reference resolvers must implement ' . MediaUsageReferenceResolver::class . '.',
            );

            $resolvers[] = $resolver;
        }

        return $resolvers;
    }
}
