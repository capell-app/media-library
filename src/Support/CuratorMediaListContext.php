<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support;

/**
 * Marks the span in which Curator runs its own list and search queries, so the site restriction in
 * CuratorMediaSiteListScope applies there and nowhere else. Picker hydration, render and lookup queries, and saving a
 * record that still references media shared with another site, all run outside it and keep resolving every item.
 */
final class CuratorMediaListContext
{
    private int $depth = 0;

    public function active(): bool
    {
        return $this->depth > 0;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function during(callable $callback): mixed
    {
        $this->depth++;

        try {
            return $callback();
        } finally {
            $this->depth--;
        }
    }
}
