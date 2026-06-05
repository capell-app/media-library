<?php

declare(strict_types=1);

return [
    'description' => 'Media library tools for Capell file management.',
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
        'max_size' => 'This media file is :actual KB and may not be larger than :max KB.',
        'no_extension' => 'none',
        'none_configured' => 'none configured',
    ],
];
