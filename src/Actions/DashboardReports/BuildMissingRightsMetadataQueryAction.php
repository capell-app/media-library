<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\CuratorMediaQueryFactory;
use Illuminate\Database\Eloquent\Builder;
use JsonException;
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
        $missingMediaIds = CuratorMedia::query()
            ->select(['id', 'exif'])
            ->get()
            ->filter(fn (CuratorMedia $media): bool => $this->isMissingRightsMetadata(
                $media->getAttribute('exif'),
                $normalizedMetadataKeys,
            ))
            ->pluck('id')
            ->map(static fn (mixed $mediaId): int => (int) $mediaId)
            ->values()
            ->all();

        if ($missingMediaIds === []) {
            return resolve(CuratorMediaQueryFactory::class)->emptyQuery();
        }

        return CuratorMedia::query()
            ->whereIn((new CuratorMedia)->getQualifiedKeyName(), $missingMediaIds)
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
     * @param  array<int, string>  $metadataKeys
     */
    private function isMissingRightsMetadata(mixed $exif, array $metadataKeys): bool
    {
        if (is_array($exif)) {
            return ! $this->containsRightsMetadataValue($exif, $metadataKeys);
        }

        if (! is_string($exif) || trim($exif) === '') {
            return true;
        }

        try {
            $decodedExif = json_decode($exif, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return true;
        }

        return ! $this->containsRightsMetadataValue($decodedExif, $metadataKeys);
    }

    /**
     * @param  array<int, string>  $metadataKeys
     */
    private function containsRightsMetadataValue(mixed $value, array $metadataKeys, ?string $currentKey = null): bool
    {
        if ($currentKey !== null && in_array(strtolower($currentKey), $metadataKeys, true)) {
            return $this->hasMeaningfulMetadataValue($value);
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $nestedKey => $nestedValue) {
            if ($this->containsRightsMetadataValue($nestedValue, $metadataKeys, is_string($nestedKey) ? $nestedKey : null)) {
                return true;
            }
        }

        return false;
    }

    private function hasMeaningfulMetadataValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            foreach ($value as $nestedValue) {
                if ($this->hasMeaningfulMetadataValue($nestedValue)) {
                    return true;
                }
            }

            return false;
        }

        return true;
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
