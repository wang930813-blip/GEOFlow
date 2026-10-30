<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    use BelongsToSite;

    protected $table = 'site_settings';

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'setting_key',
        'setting_value',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
        ];
    }

    /**
     * 读取当前站点可见的设置映射。
     *
     * 当前站点设置优先，兼容升级前保存在主站（site_id=NULL）的配置；写入仍然沿用
     * BelongsToSite 全局作用域，避免把主站配置误写入其它站点。
     *
     * @param  array<int,string>|null  $keys  可选的设置键过滤条件
     * @return array<string,string>
     */
    public static function visibleMap(?array $keys = null): array
    {
        $siteId = app(CurrentSite::class)->id();
        $query = static::query()->withoutGlobalScope('current_site');

        if ($keys !== null) {
            $query->whereIn('setting_key', $keys);
        }

        $query->where(function ($builder) use ($siteId): void {
            if ($siteId !== null && $siteId > 0) {
                $builder->where('site_id', $siteId)->orWhereNull('site_id');

                return;
            }

            $builder->whereNull('site_id');
        });

        $map = [];
        foreach ($query->get(['site_id', 'setting_key', 'setting_value']) as $setting) {
            $key = (string) $setting->setting_key;
            if (! array_key_exists($key, $map) || ($siteId !== null && (int) $setting->site_id === $siteId)) {
                $map[$key] = (string) $setting->setting_value;
            }
        }

        return $map;
    }
}
