<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformPlan extends Model
{
    public const RESOURCE_CREDITS = 'credits';
    public const RESOURCE_ARTICLE_GENERATIONS = 'article_generations';
    public const RESOURCE_BRAND_DIAGNOSES = 'brand_diagnoses';
    public const RESOURCE_AI_TITLE_GENERATIONS = 'ai_title_generations';
    public const RESOURCE_URL_IMPORTS = 'url_imports';
    public const RESOURCE_KEYWORD_QUESTION_GENERATIONS = 'keyword_question_generations';
    public const RESOURCE_INCLUSION_CHECKS = 'inclusion_checks';
    public const RESOURCE_AI_IMAGE_GENERATIONS = 'ai_image_generations';
    public const RESOURCE_TEAM_MEMBERS = 'team_members';
    public const RESOURCE_API_TOKENS = 'api_tokens';
    public const RESOURCE_VIDEO_GENERATIONS = 'video_generations';
    public const RESOURCE_CREBEE_PUBLISHES = 'crebee_publishes';

    /**
     * @return array<string,array{label:string,unit:string}>
     */
    public static function resourceCatalog(): array
    {
        return [
            self::RESOURCE_CREDITS => ['label' => '积分', 'unit' => 'points'],
            self::RESOURCE_ARTICLE_GENERATIONS => ['label' => 'AI 文章生成次数', 'unit' => 'times'],
            self::RESOURCE_BRAND_DIAGNOSES => ['label' => '品牌诊断次数', 'unit' => 'times'],
            self::RESOURCE_AI_TITLE_GENERATIONS => ['label' => 'AI 爆款标题生成次数', 'unit' => 'times'],
            self::RESOURCE_URL_IMPORTS => ['label' => 'URL 采集次数', 'unit' => 'times'],
            self::RESOURCE_KEYWORD_QUESTION_GENERATIONS => ['label' => '关键词问题生成次数', 'unit' => 'times'],
            self::RESOURCE_INCLUSION_CHECKS => ['label' => 'GEO 收录/引用检测次数', 'unit' => 'times'],
            self::RESOURCE_AI_IMAGE_GENERATIONS => ['label' => 'AI 配图次数', 'unit' => 'times'],
            self::RESOURCE_TEAM_MEMBERS => ['label' => '子账号数量', 'unit' => 'accounts'],
            self::RESOURCE_API_TOKENS => ['label' => 'API Token 数量', 'unit' => 'tokens'],
            self::RESOURCE_VIDEO_GENERATIONS => ['label' => '生成视频次数', 'unit' => 'times'],
            self::RESOURCE_CREBEE_PUBLISHES => ['label' => 'CreBee 发布次数', 'unit' => 'times'],
        ];
    }

    protected $fillable = [
        'name',
        'code',
        'audience',
        'duration_days',
        'price',
        'market_price',
        'description',
        'status',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'price' => 'decimal:2',
            'market_price' => 'decimal:2',
            'sort_order' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(PlatformPlanEntitlement::class, 'plan_id');
    }
}
