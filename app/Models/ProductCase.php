<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProductCase extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_HIDDEN = 'hidden';

    /**
     * @var list<string>
     */
    public const INDUSTRY_OPTIONS = [
        '服装',
        '化工',
        '玩具',
        '精细化学品',
        '食品、饮料',
        '机械及行业设备',
        '电子元器件',
        '礼品、工艺品、饰品',
        '通信产品',
        '其他',
        '二手设备',
        '五金、工具',
        '交通运输',
        '仪器仪表',
        '传媒、广电',
        '农业',
        '冶金矿产',
        '办公、文教',
        '包装',
        '医药、保养',
        '医药健康',
        '印刷',
        '商务服务',
        '安全、防护',
        '家居用品',
        '家用电器',
        '建筑、建材',
        '教育培训',
        '数码、电脑',
        '服装内衣',
        '服饰',
        '橡塑',
        '汽摩及配件',
        '照明工业',
        '环保',
        '电工电气',
        '纸业',
        '纺织、皮革',
        '能源',
        '航天航空',
        '运动、休闲',
        '鞋包配饰',
    ];

    /**
     * @var list<string>
     */
    public const REGION_OPTIONS = [
        '上海市',
        '苏州市',
        '深圳市',
        '成都市',
        '无锡市',
        '新乡市',
        '淄博市',
        '杭州市',
        '泉州市',
        '温州市',
        '福州市',
        '烟台市',
        '长春市',
        '北京市',
        '郑州市',
        '兰州市',
        '东莞市',
        '南京市',
        '贵阳市',
        '青岛市',
        '中山市',
        '广州市',
        '大连市',
        '常州市',
        '武汉市',
        '宁波市',
        '厦门市',
        '绵阳市',
        '南昌市',
        '济宁市',
        '佛山市',
        '临沂市',
        '威海市',
        '哈尔滨市',
        '金华市',
        '台州市',
        '合肥市',
        '其他市',
    ];

    protected $attributes = [
        'company_name' => '',
        'logo_url' => '',
        'cover_url' => '',
        'industry' => '',
        'region' => '',
        'business_mode' => '',
        'summary' => '',
        'content' => '',
        'customer_level' => '',
        'status' => self::STATUS_DRAFT,
        'sort_order' => 0,
        'view_count' => 0,
    ];

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'title',
        'slug',
        'company_name',
        'logo_url',
        'cover_url',
        'industry',
        'region',
        'business_mode',
        'module_tags',
        'summary',
        'content',
        'customer_level',
        'started_at',
        'status',
        'sort_order',
        'view_count',
        'published_at',
        'created_by_admin_id',
        'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'module_tags' => 'array',
            'started_at' => 'date',
            'sort_order' => 'integer',
            'view_count' => 'integer',
            'published_at' => 'datetime',
            'created_by_admin_id' => 'integer',
            'updated_by_admin_id' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }

    public static function uniqueSlug(string $title, ?self $ignore = null): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            $base = 'case-'.Str::lower(Str::random(8));
        }

        $slug = $base;
        $suffix = 2;

        while (self::query()
            ->withTrashed()
            ->when($ignore instanceof self, fn (Builder $query): Builder => $query->whereKeyNot($ignore->id))
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public function publicUrl(): string
    {
        return route('product-cases.show', ['slug' => $this->slug]);
    }

    /**
     * @return list<string>
     */
    public static function industryOptions(string $currentValue = ''): array
    {
        return self::withCurrentOption(self::INDUSTRY_OPTIONS, $currentValue);
    }

    /**
     * @return list<string>
     */
    public static function regionOptions(string $currentValue = ''): array
    {
        return self::withCurrentOption(self::REGION_OPTIONS, $currentValue);
    }

    /**
     * @param  list<string>  $options
     * @return list<string>
     */
    private static function withCurrentOption(array $options, string $currentValue): array
    {
        $currentValue = trim($currentValue);

        if ($currentValue !== '' && ! in_array($currentValue, $options, true)) {
            array_unshift($options, $currentValue);
        }

        return $options;
    }
}
