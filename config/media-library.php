<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Owner foreign keys
    |--------------------------------------------------------------------------
    |
    | Curator stores one media row per collection via a single foreign key
    | column on each owner table (see InteractsWithCuratorMedia). The usage and
    | orphan reports need to know which (table, column) pairs reference Curator
    | media so they can count references and identify unused assets.
    |
    | Each entry must be an array with a "table" and "column" key, for example:
    |
    |   ['table' => 'pages', 'column' => 'hero_image_id'],
    |
    | Identifiers are validated against the live schema before use; unknown
    | tables or columns are ignored.
    |
    | @var array<int, array{table: string, column: string}>
    */
    'owner_foreign_keys' => [],

    /*
    |--------------------------------------------------------------------------
    | Default upload visibility
    |--------------------------------------------------------------------------
    |
    | Visibility applied to files stored via addMediaFromUploadedFile() when no
    | explicit visibility is passed. Defaults to "public" to preserve historic
    | behaviour. Set to "private" for disks that must not be world-readable.
    |
    | @var 'public'|'private'
    */
    'default_visibility' => 'public',

    /*
    |--------------------------------------------------------------------------
    | Stale media threshold
    |--------------------------------------------------------------------------
    |
    | Number of days after which media counts as "stale" in the media health
    | report.
    */
    'stale_after_days' => 90,
];
