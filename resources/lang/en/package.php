<?php

declare(strict_types=1);

return [
    'description' => 'Media library tools for Capell file management.',
    'media_health' => [
        'issue' => 'Issue',
        'issues' => [
            'healthy' => 'Healthy',
            'missing_alt' => 'Missing alt text',
            'stale' => 'Stale asset',
            'unused' => 'Unused asset',
        ],
    ],
    'validation' => [
        'invalid_mime_type' => 'This media file type is not allowed.',
        'invalid_extension' => 'This media file extension is not allowed.',
        'max_size' => 'This media file may not be larger than :max KB.',
    ],
];
