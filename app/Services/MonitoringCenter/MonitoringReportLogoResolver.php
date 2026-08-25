<?php

namespace App\Services\MonitoringCenter;

use App\Models\Site;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\File;

class MonitoringReportLogoResolver
{
    public const SETTING_KEY = 'monitoring_report_logo';

    public function defaultLogoUrl(): string
    {
        $assetBase = rtrim(asset('assets/monitoring-center'), '/');
        $logoPath = public_path('assets/monitoring-center/ceying-ai-logo1.png');
        $logoHash = File::exists($logoPath) ? hash_file('sha256', $logoPath) : false;

        return $assetBase.'/ceying-ai-logo1.png'.(
            is_string($logoHash) ? '?v='.substr($logoHash, 0, 12) : ''
        );
    }

    public function logoUrlForSite(?Site $site): string
    {
        return $this->logoUrlForSiteId($site instanceof Site ? (int) $site->id : null);
    }

    public function logoUrlForSiteId(?int $siteId): string
    {
        $customLogoUrl = $this->customLogoUrlForSiteId($siteId);

        return $customLogoUrl !== '' ? $customLogoUrl : $this->defaultLogoUrl();
    }

    public function customLogoUrlForSiteId(?int $siteId): string
    {
        if ($siteId === null || $siteId <= 0) {
            return '';
        }

        $value = SiteSetting::withoutGlobalScope('current_site')
            ->where('site_id', $siteId)
            ->where('setting_key', self::SETTING_KEY)
            ->value('setting_value');

        return $this->normalizeLogoUrl((string) $value);
    }

    public function normalizeLogoUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : '';
    }
}
