<?php

return [
    'model' => env('AI_MODEL', 'claude-haiku-4-5-20251001'),

    // Hostnames the builder may crawl/verify even though they resolve to private
    // addresses (self-hosted intranet targets). Comma-separated; leave empty on hosted.
    'allowed_private_hosts' => array_filter(array_map('trim', explode(',', (string) env('AI_ALLOWED_PRIVATE_HOSTS', '')))),
];
