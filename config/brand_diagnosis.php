<?php

return [
    'daily_free_limit' => (int) env('BRAND_DIAGNOSIS_DAILY_FREE_LIMIT', 1),
    'question_count' => (int) env('BRAND_DIAGNOSIS_QUESTION_COUNT', 5),
    'job_timeout' => (int) env('BRAND_DIAGNOSIS_JOB_TIMEOUT', 1200),

    // 显示层基础值叠加：开启后仅在「已完成」诊断的页面展示时叠加基础数值，不写入存储、不影响真实计算。
    // 关闭则展示真实计算值。各基础值可按需调整。
    'display_baseline' => [
        'enabled' => filter_var(env('BRAND_DIAGNOSIS_DISPLAY_BASELINE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // 得分：min(原值 + score, 100)
        'score' => (int) env('BRAND_DIAGNOSIS_DISPLAY_BASELINE_SCORE', 60),
        // 提及率：min(原值 + mention_rate, 100)
        'mention_rate' => (int) env('BRAND_DIAGNOSIS_DISPLAY_BASELINE_MENTION_RATE', 50),
        // 次数：原值 + mention_count
        'mention_count' => (int) env('BRAND_DIAGNOSIS_DISPLAY_BASELINE_MENTION_COUNT', 9),
        // 排名：原值>0 取 min(原值, rank_cap)，无数据显示 rank_cap（越小越好，保证前 rank_cap 名内）
        'rank_cap' => (int) env('BRAND_DIAGNOSIS_DISPLAY_BASELINE_RANK_CAP', 9),
    ],

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
