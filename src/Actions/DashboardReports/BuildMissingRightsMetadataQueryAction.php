<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\CuratorMediaQueryFactory;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsAction;

final class BuildMissingRightsMetadataQueryAction
{
    use AsAction;

    /**
     * @param  array<int, string>|null  $metadataKeys
     * @return Builder<CuratorMedia>
     */
    public function handle(?array $metadataKeys = null): Builder
    {
        if (! resolve(RuntimeSchemaState::class)->hasTable('curator')) {
            return resolve(CuratorMediaQueryFactory::class)->emptyQuery();
        }

        $normalizedMetadataKeys = $this->normalizeMetadataKeys($metadataKeys ?? $this->defaultMetadataKeys());

        return CuratorMedia::query()
            ->select('curator.*')
            ->where(function (Builder $curatorQuery) use ($normalizedMetadataKeys): void {
                $curatorQuery
                    ->whereNull('exif')
                    ->orWhere('exif', '')
                    ->orWhere(function (Builder $metadataQuery) use ($normalizedMetadataKeys): void {
                        foreach ($normalizedMetadataKeys as $metadataKey) {
                            $metadataQuery->whereRaw("lower(coalesce(exif, '')) not like ?", ['%"' . $metadataKey . '"%']);
                        }
                    });
            })
            ->latest('curator.updated_at')
            ->orderByDesc('curator.id');
    }

    /**
     * @param  array<int, string>  $metadataKeys
     * @return array<int, string>
     */
    private function normalizeMetadataKeys(array $metadataKeys): array
    {
        return collect($metadataKeys)
            ->filter(static fn (string $metadataKey): bool => trim($metadataKey) !== '')
            ->map(static fn (string $metadataKey): string => strtolower(trim($metadataKey)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function defaultMetadataKeys(): array
    {
        return [
            'attribution',
            'copyright',
            'credit',
            'creator',
            'licence',
            'license',
            'rights',
            'source',
            'usage_terms',
        ];
    }
}
