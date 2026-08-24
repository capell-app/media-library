<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Data;

use Capell\MediaLibrary\Enums\MediaHealthIssue;

/**
 * Per-issue totals for the media health report.
 *
 * Totals overlap on purpose: one record counts towards every issue it carries,
 * so the sum of the per-issue totals can exceed the record total.
 */
final readonly class MediaHealthTotalsData
{
    /**
     * @param  array<string, int>  $issueTotals
     */
    public function __construct(
        public array $issueTotals,
        public int $recordTotal,
    ) {}

    public static function empty(): self
    {
        $issueTotals = [];

        foreach (MediaHealthIssue::all() as $issue) {
            $issueTotals[$issue->value] = 0;
        }

        return new self($issueTotals, 0);
    }

    public function forIssue(MediaHealthIssue $issue): int
    {
        return $this->issueTotals[$issue->value] ?? 0;
    }

    public function hasIssue(MediaHealthIssue $issue): bool
    {
        return $this->forIssue($issue) > 0;
    }
}
