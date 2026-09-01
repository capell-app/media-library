<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Enums;

/**
 * A single detected media health problem.
 *
 * A media record can carry more than one of these at the same time, so these
 * cases are deliberately independent rather than mutually exclusive states.
 */
enum MediaHealthIssue: string
{
    case MissingAlt = 'missing_alt';

    case Stale = 'stale';

    case Unused = 'unused';

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Projected query column carrying the 0/1 detection flag for this issue.
     */
    public function flagColumn(): string
    {
        return 'has_' . $this->value;
    }

    public function label(): string
    {
        return (string) __('capell-media-library::package.media_health.issues.' . $this->value);
    }

    public function reviewLabel(int $count): string
    {
        return match ($this) {
            self::MissingAlt => (string) __('capell-media-library::package.media_health.review.missing_alt', ['count' => $count]),
            self::Stale => (string) __('capell-media-library::package.media_health.review.stale', ['count' => $count]),
            self::Unused => (string) __('capell-media-library::package.media_health.review.unused', ['count' => $count]),
        };
    }
}
