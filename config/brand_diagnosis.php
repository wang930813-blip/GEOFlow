<?php

return [
    'daily_free_limit' => (int) env('BRAND_DIAGNOSIS_DAILY_FREE_LIMIT', 1),
    'question_count' => (int) env('BRAND_DIAGNOSIS_QUESTION_COUNT', 5),

    'doubao' => [
        'enabled' => filter_var(env('BRAND_DIAGNOSIS_DOUBAO_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'base_url' => rtrim((string) env('BRAND_DIAGNOSIS_DOUBAO_BASE_URL', 'https://ark.cn-beijing.volces.com/api/v3'), '/'),
        'api_key' => (string) env('BRAND_DIAGNOSIS_DOUBAO_API_KEY', ''),
        'model' => (string) env('BRAND_DIAGNOSIS_DOUBAO_MODEL', ''),
        'timeout' => (int) env('BRAND_DIAGNOSIS_DOUBAO_TIMEOUT', 60),
        'connect_timeout' => (int) env('BRAND_DIAGNOSIS_DOUBAO_CONNECT_TIMEOUT', 10),
        'max_keywords' => (int) env('BRAND_DIAGNOSIS_DOUBAO_WEB_SEARCH_MAX_KEYWORDS', 5),
    ],

    'deepseek' => [
        'enabled' => filter_var(env('BRAND_DIAGNOSIS_DEEPSEEK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'base_url' => rtrim((string) env('BRAND_DIAGNOSIS_DEEPSEEK_BASE_URL', env('BRAND_DIAGNOSIS_DOUBAO_BASE_URL', 'https://ark.cn-beijing.volces.com/api/v3')), '/'),
        'api_key' => (string) env('BRAND_DIAGNOSIS_DEEPSEEK_API_KEY', env('BRAND_DIAGNOSIS_DOUBAO_API_KEY', '')),
        'model' => (string) env('BRAND_DIAGNOSIS_DEEPSEEK_MODEL', 'deepseek-v4-flash-260425'),
        'timeout' => (int) env('BRAND_DIAGNOSIS_DEEPSEEK_TIMEOUT', env('BRAND_DIAGNOSIS_DOUBAO_TIMEOUT', 60)),
        'connect_timeout' => (int) env('BRAND_DIAGNOSIS_DEEPSEEK_CONNECT_TIMEOUT', env('BRAND_DIAGNOSIS_DOUBAO_CONNECT_TIMEOUT', 10)),
        'max_keywords' => (int) env('BRAND_DIAGNOSIS_DEEPSEEK_WEB_SEARCH_MAX_KEYWORDS', env('BRAND_DIAGNOSIS_DOUBAO_WEB_SEARCH_MAX_KEYWORDS', 5)),
    ],
];
