<?php

return [
    'enabled' => (bool) env('VIDEO_GENERATION_ENABLED', true),
    'base_url' => env('VIDEO_GENERATION_BASE_URL', 'http://127.0.0.1:8080'),
    'api_key' => env('VIDEO_GENERATION_API_KEY', ''),
    'timeout' => (int) env('VIDEO_GENERATION_TIMEOUT', 30),
    'connect_timeout' => (int) env('VIDEO_GENERATION_CONNECT_TIMEOUT', 10),
    'poll_interval' => (int) env('VIDEO_GENERATION_POLL_INTERVAL', 10),
    'max_poll_minutes' => (int) env('VIDEO_GENERATION_MAX_POLL_MINUTES', 60),
];
