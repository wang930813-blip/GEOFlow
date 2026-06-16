<?php

return [
    'base_url' => rtrim((string) env('MEDIA_DISTRIBUTION_API_BASE_URL', 'http://8.138.187.158:8082'), '/'),
    'chaojimeijie_base_url' => rtrim((string) env('CHAOJIMEIJIE_API_BASE_URL', 'https://vip.chaojimeijie.com/api'), '/'),
    'timeout' => (int) env('MEDIA_DISTRIBUTION_HTTP_TIMEOUT', 90),
    'connect_timeout' => (int) env('MEDIA_DISTRIBUTION_HTTP_CONNECT_TIMEOUT', 30),
    'retry_times' => (int) env('MEDIA_DISTRIBUTION_HTTP_RETRY_TIMES', 3),
    'retry_sleep' => (int) env('MEDIA_DISTRIBUTION_HTTP_RETRY_SLEEP', 1000),
    'page_delay_ms' => (int) env('MEDIA_DISTRIBUTION_PAGE_DELAY_MS', 800),
    'page_size' => (int) env('MEDIA_DISTRIBUTION_PAGE_SIZE', 200),
    'max_pages' => (int) env('MEDIA_DISTRIBUTION_MAX_PAGES', 200),
    'package' => [
        'platform_id' => (int) env('MEDIA_DISTRIBUTION_PACKAGE_PLATFORM_ID', 2),
        'title' => (string) env('MEDIA_DISTRIBUTION_PACKAGE_TITLE', '100家特价媒体套餐'),
        'size' => (int) env('MEDIA_DISTRIBUTION_PACKAGE_SIZE', 100),
        'published_url_type' => (string) env('MEDIA_DISTRIBUTION_PACKAGE_PUBLISHED_URL_TYPE', 'docs 文档链接'),
    ],
];
