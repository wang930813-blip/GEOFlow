<?php

return [
    'base_url' => rtrim((string) env('MEDIA_DISTRIBUTION_API_BASE_URL', 'http://8.138.187.158:8082'), '/'),
    'timeout' => (int) env('MEDIA_DISTRIBUTION_HTTP_TIMEOUT', 30),
    'connect_timeout' => (int) env('MEDIA_DISTRIBUTION_HTTP_CONNECT_TIMEOUT', 10),
];
