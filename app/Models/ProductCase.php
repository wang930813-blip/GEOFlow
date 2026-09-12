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
        'Apparel',
        'Chemicals',
        'Toys',
        'Fine Chemicals',
        'Food & Beverage',
        'Machinery & Industrial Equipment',
        'Electronic Components',
        'Gifts, Crafts & Accessories',
        'Telecommunications',
        'Other',
        'Used Equipment',
        'Hardware & Tools',
        'Transportation',
        'Instruments & Meters',
        'Media & Broadcasting',
        'Agriculture',
        'Metallurgy & Minerals',
        'Office & Stationery',
        'Packaging',
        'Pharmaceuticals & Health Products',
        'Healthcare',
        'Printing',
        'Business Services',
        'Safety & Security',
        'Home & Living',
        'Home Appliances',
        'Building Materials',
        'Education & Training',
        'Digital & Computers',
        'Underwear & Intimate Apparel',
        'Fashion Accessories',
        'Rubber & Plastics',
        'Automotive & Parts',
        'Lighting',
        'Environmental Protection',
        'Electrical Equipment',
        'Paper & Pulp',
        'Textile & Leather',
        'Energy',
        'Aerospace',
        'Sports & Recreation',
        'Footwear & Bags',
    ];

    /**
     * @var list<string>
     */
    public const REGION_OPTIONS = [
        'Global',
        'Asia-Pacific',
        'Europe & Americas',
        'North America',
        'Europe',
        'Latin America',
        'Middle East',
        'Africa',
        'Oceania',
        'Southeast Asia',
        'East Asia',
        'South Asia',
        'Central Asia',
        'Western Europe',
        'Eastern Europe',
        'Northern Europe',
        'Southern Europe',
        'United States & Canada',
        'Greater China',
        'Japan & Korea',
        'GCC Countries',
    ];

    /**
     * @var array<string,string>
     */
    public const LEGACY_INDUSTRY_LABELS = [
        '服装' => 'Apparel',
        '化工' => 'Chemicals',
        '玩具' => 'Toys',
        '精细化学品' => 'Fine Chemicals',
        '食品、饮料' => 'Food & Beverage',
        '机械及行业设备' => 'Machinery & Industrial Equipment',
        '电子元器件' => 'Electronic Components',
        '礼品、工艺品、饰品' => 'Gifts, Crafts & Accessories',
        '通信产品' => 'Telecommunications',
        '其他' => 'Other',
        '二手设备' => 'Used Equipment',
        '五金、工具' => 'Hardware & Tools',
        '交通运输' => 'Transportation',
        '仪器仪表' => 'Instruments & Meters',
        '传媒、广电' => 'Media & Broadcasting',
        '农业' => 'Agriculture',
        '冶金矿产' => 'Metallurgy & Minerals',
        '办公、文教' => 'Office & Stationery',
        '包装' => 'Packaging',
        '医药、保养' => 'Pharmaceuticals & Health Products',
        '医药健康' => 'Healthcare',
        '印刷' => 'Printing',
        '商务服务' => 'Business Services',
        '安全、防护' => 'Safety & Security',
        '家居用品' => 'Home & Living',
        '家用电器' => 'Home Appliances',
        '建筑、建材' => 'Building Materials',
        '教育培训' => 'Education & Training',
        '数码、电脑' => 'Digital & Computers',
        '服装内衣' => 'Underwear & Intimate Apparel',
        '服饰' => 'Fashion Accessories',
        '橡塑' => 'Rubber & Plastics',
        '汽摩及配件' => 'Automotive & Parts',
        '照明工业' => 'Lighting',
        '环保' => 'Environmental Protection',
        '电工电气' => 'Electrical Equipment',
        '纸业' => 'Paper & Pulp',
        '纺织、皮革' => 'Textile & Leather',
        '能源' => 'Energy',
        '航天航空' => 'Aerospace',
        '运动、休闲' => 'Sports & Recreation',
        '鞋包配饰' => 'Footwear & Bags',
    ];

    /**
     * @var array<string,string>
     */
    public const LEGACY_REGION_LABELS = [
        '上海市' => 'Asia-Pacific',
        '苏州市' => 'Asia-Pacific',
        '深圳市' => 'Asia-Pacific',
        '成都市' => 'Asia-Pacific',
        '无锡市' => 'Asia-Pacific',
        '新乡市' => 'Asia-Pacific',
        '淄博市' => 'Asia-Pacific',
        '杭州市' => 'Asia-Pacific',
        '泉州市' => 'Asia-Pacific',
        '温州市' => 'Asia-Pacific',
        '福州市' => 'Asia-Pacific',
        '烟台市' => 'Asia-Pacific',
        '长春市' => 'Asia-Pacific',
        '北京市' => 'Asia-Pacific',
        '郑州市' => 'Asia-Pacific',
        '兰州市' => 'Asia-Pacific',
        '东莞市' => 'Asia-Pacific',
        '南京市' => 'Asia-Pacific',
        '贵阳市' => 'Asia-Pacific',
        '青岛市' => 'Asia-Pacific',
        '中山市' => 'Asia-Pacific',
        '广州市' => 'Asia-Pacific',
        '大连市' => 'Asia-Pacific',
        '常州市' => 'Asia-Pacific',
        '武汉市' => 'Asia-Pacific',
        '宁波市' => 'Asia-Pacific',
        '厦门市' => 'Asia-Pacific',
        '绵阳市' => 'Asia-Pacific',
        '南昌市' => 'Asia-Pacific',
        '济宁市' => 'Asia-Pacific',
        '佛山市' => 'Asia-Pacific',
        '临沂市' => 'Asia-Pacific',
        '威海市' => 'Asia-Pacific',
        '哈尔滨市' => 'Asia-Pacific',
        '金华市' => 'Asia-Pacific',
        '台州市' => 'Asia-Pacific',
        '合肥市' => 'Asia-Pacific',
        '其他市' => 'Global',
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
        return self::withCurrentOption(self::INDUSTRY_OPTIONS, self::normalizeIndustryLabel($currentValue));
    }

    /**
     * @return list<string>
     */
    public static function regionOptions(string $currentValue = ''): array
    {
        return self::withCurrentOption(self::REGION_OPTIONS, self::normalizeRegionLabel($currentValue));
    }

    public static function normalizeIndustryLabel(string $value): string
    {
        $value = trim($value);

        return self::LEGACY_INDUSTRY_LABELS[$value] ?? $value;
    }

    public static function normalizeRegionLabel(string $value): string
    {
        $value = trim($value);

        return self::LEGACY_REGION_LABELS[$value] ?? $value;
    }

    /**
     * @return list<string>
     */
    public static function industryStorageValues(string $label): array
    {
        return self::storageValuesForLabel(self::LEGACY_INDUSTRY_LABELS, self::normalizeIndustryLabel($label));
    }

    /**
     * @return list<string>
     */
    public static function regionStorageValues(string $label): array
    {
        return self::storageValuesForLabel(self::LEGACY_REGION_LABELS, self::normalizeRegionLabel($label));
    }

    public function displayIndustry(): string
    {
        return self::normalizeIndustryLabel((string) $this->industry);
    }

    public function displayRegion(): string
    {
        return self::normalizeRegionLabel((string) $this->region);
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

    /**
     * @param  array<string,string>  $legacyLabels
     * @return list<string>
     */
    private static function storageValuesForLabel(array $legacyLabels, string $label): array
    {
        $label = trim($label);
        if ($label === '') {
            return [];
        }

        $values = [$label];
        foreach ($legacyLabels as $legacy => $normalized) {
            if ($normalized === $label) {
                $values[] = $legacy;
            }
        }

        return array_values(array_unique($values));
    }
}
