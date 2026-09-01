<?php

return [
    'daily_free_limit' => (int) env('BRAND_DIAGNOSIS_DAILY_FREE_LIMIT', 1),
    'question_count' => (int) env('BRAND_DIAGNOSIS_QUESTION_COUNT', 6),
    'job_timeout' => (int) env('BRAND_DIAGNOSIS_JOB_TIMEOUT', 1200),

    'open_api' => [
        'enabled' => filter_var(env('BRAND_DIAGNOSIS_OPEN_API_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'api_key' => trim((string) env('BRAND_DIAGNOSIS_OPEN_API_KEY', '')),
        'admin_id' => (int) env('BRAND_DIAGNOSIS_OPEN_API_ADMIN_ID', 0) ?: null,
    ],

    'public_default_platform' => (string) env('BRAND_DIAGNOSIS_PUBLIC_DEFAULT_PLATFORM', 'chatgpt'),

    'public_platforms' => [
        'chatgpt' => [
            'enabled' => filter_var(env('BRAND_DIAGNOSIS_CHATGPT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'label' => 'ChatGPT',
            'icon' => 'CG',
            'logo_path' => '',
            'chat_url' => 'https://chatgpt.com/',
            'official_share_domains' => ['chatgpt.com', 'openai.com'],
            'base_url' => rtrim((string) env('BRAND_DIAGNOSIS_CHATGPT_BASE_URL', 'https://api.openai.com/v1'), '/'),
            'api_key' => (string) env('BRAND_DIAGNOSIS_CHATGPT_API_KEY', ''),
            'model' => (string) env('BRAND_DIAGNOSIS_CHATGPT_MODEL', 'gpt-5.5'),
            'timeout' => (int) env('BRAND_DIAGNOSIS_CHATGPT_TIMEOUT', 60),
            'connect_timeout' => (int) env('BRAND_DIAGNOSIS_CHATGPT_CONNECT_TIMEOUT', 10),
            'max_keywords' => (int) env('BRAND_DIAGNOSIS_CHATGPT_WEB_SEARCH_MAX_KEYWORDS', 5),
            'supports_web_search' => filter_var(env('BRAND_DIAGNOSIS_CHATGPT_SUPPORTS_WEB_SEARCH', true), FILTER_VALIDATE_BOOLEAN),
            'request_style' => 'responses',
        ],
        'grok' => [
            'enabled' => filter_var(env('BRAND_DIAGNOSIS_GROK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'label' => 'Grok',
            'icon' => 'GK',
            'logo_path' => '',
            'chat_url' => 'https://grok.com/',
            'official_share_domains' => ['grok.com', 'x.ai', 'x.com'],
            'base_url' => rtrim((string) env('BRAND_DIAGNOSIS_GROK_BASE_URL', 'https://api.x.ai/v1'), '/'),
            'api_key' => (string) env('BRAND_DIAGNOSIS_GROK_API_KEY', ''),
            'model' => (string) env('BRAND_DIAGNOSIS_GROK_MODEL', 'grok-4-latest'),
            'timeout' => (int) env('BRAND_DIAGNOSIS_GROK_TIMEOUT', 60),
            'connect_timeout' => (int) env('BRAND_DIAGNOSIS_GROK_CONNECT_TIMEOUT', 10),
            'max_keywords' => (int) env('BRAND_DIAGNOSIS_GROK_WEB_SEARCH_MAX_KEYWORDS', 5),
            'supports_web_search' => filter_var(env('BRAND_DIAGNOSIS_GROK_SUPPORTS_WEB_SEARCH', true), FILTER_VALIDATE_BOOLEAN),
            'request_style' => 'responses',
        ],
        'gemini' => [
            'enabled' => filter_var(env('BRAND_DIAGNOSIS_GEMINI_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'label' => 'Gemini',
            'icon' => 'GE',
            'logo_path' => '',
            'chat_url' => 'https://gemini.google.com/',
            'official_share_domains' => ['gemini.google.com', 'google.com'],
            'base_url' => rtrim((string) env('BRAND_DIAGNOSIS_GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'),
            'api_key' => (string) env('BRAND_DIAGNOSIS_GEMINI_API_KEY', ''),
            'model' => (string) env('BRAND_DIAGNOSIS_GEMINI_MODEL', 'gemini-2.5-pro'),
            'timeout' => (int) env('BRAND_DIAGNOSIS_GEMINI_TIMEOUT', 60),
            'connect_timeout' => (int) env('BRAND_DIAGNOSIS_GEMINI_CONNECT_TIMEOUT', 10),
            'max_keywords' => (int) env('BRAND_DIAGNOSIS_GEMINI_WEB_SEARCH_MAX_KEYWORDS', 5),
            'supports_web_search' => filter_var(env('BRAND_DIAGNOSIS_GEMINI_SUPPORTS_WEB_SEARCH', true), FILTER_VALIDATE_BOOLEAN),
            'request_style' => 'generate_content',
        ],
    ],

    // Display baseline values are only used on completed diagnosis pages.
    'display_baseline' => [
        'enabled' => filter_var(env('BRAND_DIAGNOSIS_DISPLAY_BASELINE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'score' => (int) env('BRAND_DIAGNOSIS_DISPLAY_BASELINE_SCORE', 60),
        'mention_rate' => (int) env('BRAND_DIAGNOSIS_DISPLAY_BASELINE_MENTION_RATE', 50),
        'mention_count' => (int) env('BRAND_DIAGNOSIS_DISPLAY_BASELINE_MENTION_COUNT', 9),
        'rank_cap' => (int) env('BRAND_DIAGNOSIS_DISPLAY_BASELINE_RANK_CAP', 9),
    ],

    // Legacy registry kept for compatibility with existing internal and MCP flows.
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

    'qianwen' => [
        'enabled' => filter_var(env('BRAND_DIAGNOSIS_QIANWEN_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'base_url' => rtrim((string) env('BRAND_DIAGNOSIS_QIANWEN_BASE_URL', ''), '/'),
        'api_key' => (string) env('BRAND_DIAGNOSIS_QIANWEN_API_KEY', ''),
        'model' => (string) env('BRAND_DIAGNOSIS_QIANWEN_MODEL', ''),
        'timeout' => (int) env('BRAND_DIAGNOSIS_QIANWEN_TIMEOUT', 60),
        'connect_timeout' => (int) env('BRAND_DIAGNOSIS_QIANWEN_CONNECT_TIMEOUT', 10),
        'max_keywords' => (int) env('BRAND_DIAGNOSIS_QIANWEN_WEB_SEARCH_MAX_KEYWORDS', 5),
    ],

    'wenxin' => [
        'enabled' => filter_var(env('BRAND_DIAGNOSIS_WENXIN_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'base_url' => rtrim((string) env('BRAND_DIAGNOSIS_WENXIN_BASE_URL', ''), '/'),
        'api_key' => (string) env('BRAND_DIAGNOSIS_WENXIN_API_KEY', ''),
        'model' => (string) env('BRAND_DIAGNOSIS_WENXIN_MODEL', ''),
        'timeout' => (int) env('BRAND_DIAGNOSIS_WENXIN_TIMEOUT', 60),
        'connect_timeout' => (int) env('BRAND_DIAGNOSIS_WENXIN_CONNECT_TIMEOUT', 10),
        'max_keywords' => (int) env('BRAND_DIAGNOSIS_WENXIN_WEB_SEARCH_MAX_KEYWORDS', 5),
    ],
];
