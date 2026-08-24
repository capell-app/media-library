<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Data;

use Capell\MediaLibrary\Enums\MediaHealthIssue;
use Illuminate\Database\Eloquent\Model;

/**
 * Every health issue detected for one media record.
 *
 * The health report projects an independent flag per issue, so a record that is
 * simultaneously missing alt text, stale, and unused reports all three instead
 * of only the first match of a SQL case expression.
 */
final readonly class MediaHealthIssuesData
{
    /**
     * @param  list<MediaHealthIssue>  $issues
     */
    public function __construct(public array $issues) {}

    public static function fromRecord(Model $record): self
    {
        $issues = [];

        foreach (MediaHealthIssue::all() as $issue) {
            if (self::flagIsSet($record->getAttribute($issue->flagColumn()))) {
                $issues[] = $issue;
            }
        }

        return new self($issues);
    }

    public function isHealthy(): bool
    {
        return $this->issues === [];
    }

    public function has(MediaHealthIssue $issue): bool
    {
        return in_array($issue, $this->issues, true);
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(static fn (MediaHealthIssue $issue): string => $issue->value, $this->issues);
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        if ($this->isHealthy()) {
            return [(string) __('capell-media-library::package.media_health.issues.healthy')];
        }

        return array_map(static fn (MediaHealthIssue $issue): string => $issue->label(), $this->issues);
    }

    private static function flagIsSet(mixed $value): bool
    {
        return is_numeric($value) ? (int) $value === 1 : $value === true;
    }
}
