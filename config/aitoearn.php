<?php

return [
    'enabled' => (bool) env('AITOEARN_ENABLED', false),
    'base_url' => rtrim((string) env('AITOEARN_BASE_URL', 'https://aitoearn.cn'), '/'),
    'api_key' => trim((string) env('AITOEARN_API_KEY', '')),
    'timeout' => (int) env('AITOEARN_TIMEOUT', 60),
    'connect_timeout' => (int) env('AITOEARN_CONNECT_TIMEOUT', 10),
    'publish_delay_seconds' => (int) env('AITOEARN_PUBLISH_DELAY_SECONDS', 60),
    'status_poll_delay' => (int) env('AITOEARN_STATUS_POLL_DELAY', 30),
    'status_max_attempts' => (int) env('AITOEARN_STATUS_MAX_ATTEMPTS', 40),
    'default_bilibili_tid' => (int) env('AITOEARN_DEFAULT_BILIBILI_TID', 21),
];
