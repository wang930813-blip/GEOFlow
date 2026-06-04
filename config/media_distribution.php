<?php

return [
    'base_url' => rtrim((string) env('MEDIA_DISTRIBUTION_API_BASE_URL', 'http://8.138.187.158:8082'), '/'),
    'chaojimeijie_base_url' => rtrim((string) env('CHAOJIMEIJIE_API_BASE_URL', 'https://vip.chaojimeijie.com/api'), '/'),
    'timeout' => (int) env('MEDIA_DISTRIBUTION_HTTP_TIMEOUT', 90),
    'connect_timeout' => (int) env('MEDIA_DISTRIBUTION_HTTP_CONNECT_TIMEOUT', 30),
    'retry_times' => (int) env('MEDIA_DISTRIBUTION_HTTP_RETRY_TIMES', 3),
    'retry_sleep' => (int) env('MEDIA_DISTRIBUTION_HTTP_RETRY_SLEEP', 1000),
    'page_size' => (int) env('MEDIA_DISTRIBUTION_PAGE_SIZE', 200),
    'max_pages' => (int) env('MEDIA_DISTRIBUTION_MAX_PAGES', 200),
];
