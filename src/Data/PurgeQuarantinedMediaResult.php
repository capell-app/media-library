<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Data;

use Spatie\LaravelData\Data;

/**
 * Result DTO returned by PurgeQuarantinedMediaAction.
 *
 * @property list<int> $purgedIds Quarantined rows that were permanently removed.
 * @property list<int> $restoredIds Quarantined rows found to be referenced again and restored.
 * @property list<array{media_id: int, disk: string, path: string, error: string}> $failedBlobDeletes Blobs that could not be removed after their row was purged.
 */
final class PurgeQuarantinedMediaResult extends Data
{
    public function __construct(
        /** @var list<int> */
        public readonly array $purgedIds,
        /** @var list<int> */
        public readonly array $restoredIds,
        /** @var list<array{media_id: int, disk: string, path: string, error: string}> */
        public readonly array $failedBlobDeletes,
    ) {}

    public function hasFailures(): bool
    {
        return $this->failedBlobDeletes !== [];
    }
}
