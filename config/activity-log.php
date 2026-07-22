<?php

declare(strict_types=1);

return [
    // Activity model for the read (timeline) path. Leave null to inherit
    // Spatie's `activitylog.activity_model` — the same model used for writes —
    // so a tenant-scoped Activity subclass applies to both. Set explicitly only
    // to override that.
    'activity_model' => null,


    'default_per_page' => 20,
    'pagination_buffer' => 2,
    'deduplicate_by_default' => true,

    'source_priorities' => [
        'activity_log' => 10,
        'related_activity_log' => 10,
        'related_model' => 20,
        'custom' => 30,
    ],

    'renderers' => [
        // 'email_sent' => \App\Timeline\Renderers\EmailSentRenderer::class,
    ],

    'cache' => [
        'store' => null,
        'ttl_seconds' => 0,
        'key_prefix' => 'activity-log',
    ],
];
