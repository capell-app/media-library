<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support;

use Capell\MediaLibrary\Models\CuratorMedia;

/**
 * Works out which storage blobs belong to a media row: the original file plus
 * any derived or conversion files recorded in its metadata.
 */
final class MediaBlobPaths
{
    /**
     * Original path plus any derived/conversion blob paths recorded on the
     * media row (responsive_images / generated_conversions). De-duplicated.
     *
     * @return list<string>
     */
    public function forMedia(CuratorMedia $media, string $originalPath): array
    {
        $paths = [$originalPath];

        foreach ($this->derivedPathCandidates($media) as $derivedPath) {
            $paths[] = $derivedPath;
        }

        return array_values(array_unique(array_filter(
            $paths,
            static fn (string $path): bool => $path !== '',
        )));
    }

    /**
     * @return list<string>
     */
    private function derivedPathCandidates(CuratorMedia $media): array
    {
        $candidates = [];
        $attributes = $media->getAttributes();

        foreach (['responsive_images', 'generated_conversions'] as $metadataKey) {
            $value = $attributes[$metadataKey] ?? null;

            if (is_string($value) && $value !== '') {
                $value = json_decode($value, true);
            }

            if (is_array($value)) {
                $this->collectStringPaths($value, $candidates);
            }
        }

        return $candidates;
    }

    /**
     * Recursively collect file-path-looking strings from nested conversion
     * metadata, ignoring full URLs and srcset width descriptors.
     *
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $candidates
     */
    private function collectStringPaths(array $value, array &$candidates): void
    {
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $this->collectStringPaths($entry, $candidates);

                continue;
            }

            if (! is_string($entry) || $entry === '') {
                continue;
            }

            foreach (preg_split('/[\s,]+/', $entry) ?: [] as $token) {
                $token = trim($token);

                // Skip width descriptors (e.g. "1024w") and absolute URLs; only
                // keep relative storage paths that the disk can resolve.
                if ($token === '' || preg_match('/^\d+w$/', $token) === 1) {
                    continue;
                }

                if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $token) === 1 || str_starts_with($token, '//')) {
                    continue;
                }

                $candidates[] = ltrim($token, '/');
            }
        }
    }
}
