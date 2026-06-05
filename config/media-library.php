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
    | Explicit entries must be an array with a "table" and "column" key, for example:
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
    | Auto-discover owner foreign keys
    |--------------------------------------------------------------------------
    |
    | When owner_foreign_keys is empty, the media health and orphan reports can
    | scan the schema for conventional Curator media columns. This keeps the
    | reports useful in fresh installs while still allowing host apps to publish
    | this config and provide an exact owner_foreign_keys list.
    |
    | @var bool
    */
    'auto_discover_owner_foreign_keys' => true,

    /*
    |--------------------------------------------------------------------------
    | Auto-discovery column names
    |--------------------------------------------------------------------------
    |
    | Column names considered Curator media owner foreign keys during schema
    | discovery. These follow InteractsWithCuratorMedia's collection-to-column
    | convention, where "heroImage" maps to "hero_image_id".
    |
    | @var list<string>
    */
    'owner_foreign_key_columns' => [
        'default_id',
        'media_id',
        'image_id',
        'thumbnail_id',
        'featured_image_id',
        'hero_image_id',
        'social_image_id',
        'og_image_id',
        'background_image_id',
        'logo_id',
        'icon_id',
        'favicon_id',
        'avatar_id',
        'file_id',
        'attachment_id',
        'document_id',
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Upload validation
    |--------------------------------------------------------------------------
    |
    | Files attached through InteractsWithCuratorMedia are validated before
    | storage. Keep this list broad enough for normal CMS assets, but explicit
    | enough that executable/script uploads are rejected by default.
    |
    | @var list<string>
    */
    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
    ],

    /*
    | @var list<string>
    */
    'allowed_extensions' => [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'svg',
        'pdf',
    ],

    'max_upload_kb' => 10240,
];
