<?php

declare(strict_types=1);

return [
    'description' => 'Media library tools for Capell file management.',
    'commands' => [
        'dry_run' => '[Dry run] No data will be written.',
        'migration_summary' => 'Migration summary:label',
        'stat' => 'Stat',
        'count' => 'Count',
        'processed' => 'Processed',
        'created' => 'Curator rows created',
        'skipped' => 'Curator rows reused (idempotent)',
        'owners_updated' => 'Owner FKs populated',
        'warnings' => 'Warnings',
        'warnings_heading' => 'Warnings:',
        'warning_number' => '#',
        'warning_message' => 'Message',
    ],
    'health' => [
        'backend' => [
            'label' => 'Curator media backend registered',
            'passed' => 'Curator is registered as the Capell media backend, model, and field factory.',
            'failed' => 'The Capell media backend is not fully pointed at Curator.',
            'remediation' => 'Ensure MediaLibraryServiceProvider runs so capell.media.backend is "curator", capell.media.model is CuratorMedia, and MediaFieldFactory resolves to CuratorMediaFieldFactory.',
        ],
        'curator_table' => [
            'label' => 'Curator media table',
            'passed' => 'The "curator" media table is present.',
            'failed' => 'The "curator" media table is missing.',
            'remediation' => 'Run the Awcodes Curator migrations to create the "curator" table.',
        ],
        'owner_foreign_keys' => [
            'label' => 'Media usage owner foreign keys',
            'passed' => 'Owner foreign keys resolve against the live schema for usage and orphan reporting.',
            'failed' => 'No valid owner foreign keys are configured; usage and orphan reports will return nothing.',
            'remediation' => 'Set capell.media_library.owner_foreign_keys with each [table, column] referencing Curator media, and ensure each configured table and column exists.',
        ],
    ],
    'media_health' => [
        'delete_orphan_media' => 'Delete unused media',
        'delete_orphan_media_description' => 'Only selected media that are unused by configured owner records will be deleted. Missing-alt and stale media that are still referenced will be kept.',
        'delete_orphan_media_heading' => 'Delete selected unused media?',
        'issue' => 'Issue',
        'issues' => [
            'healthy' => 'Healthy',
            'missing_alt' => 'Missing alt text',
            'stale' => 'Stale asset',
            'unused' => 'Unused asset',
        ],
        'orphan_media_deleted' => 'Deleted :count unused media record(s).',
    ],
    'validation' => [
        'invalid_mime_type' => 'The media file type ":mime" is not allowed. Allowed types: :allowed.',
        'invalid_extension' => 'The media file extension ":extension" is not allowed. Allowed extensions: :allowed.',
        'invalid_svg' => 'The SVG could not be safely parsed.',
        'max_size' => 'This media file is :actual KB and may not be larger than :max KB.',
        'no_extension' => 'none',
        'none_configured' => 'none configured',
    ],
];
