<?php

namespace App\Providers;

use App\Http\ApiAuthContext;
use App\Models\Admin;
use App\Models\Site;
use App\Services\Admin\AdminUpdateMetadataService;
use App\Services\Admin\AdminWelcomeModalService;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Services\GeoFlow\HorizonMetricsAdapter;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use App\Support\AdminDisplaySettings;
use App\Support\CurrentSite;
use App\View\Composers\SiteLayoutComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(JobQueueService::class);
        $this->app->singleton(HorizonMetricsAdapter::class);
        $this->app->singleton(TaskMonitoringQueryService::class);
        $this->app->singleton(TaskLifecycleService::class);
        $this->app->singleton(ArticleGeoFlowService::class);
        $this->app->scoped(CurrentSite::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureApiRateLimiters();
        View::composer(['site.layout', 'theme.*.layout'], SiteLayoutComposer::class);

        View::composer('admin.layouts.app', function ($view): void {
            $payload = once(function (): array {
                $admin = auth('admin')->user();

                return [
                    'adminWelcomeModalPayload' => $admin instanceof Admin
                        ? app(AdminWelcomeModalService::class)->buildModalPayload($admin)
                        : null,
                    'adminUpdateNotificationPayload' => $admin instanceof Admin
                        ? app(AdminUpdateMetadataService::class)->buildNotificationPayload()
                        : null,
                    'currentSite' => app(CurrentSite::class)->get(),
                    'availableSites' => $admin instanceof Admin
                        ? $this->availableSitesForAdmin($admin)
                        : collect(),
                    'adminDisplaySettings' => AdminDisplaySettings::all(),
                ];
            });

            $view->with($payload);
        });
    }

    /**
     * @Name: configureApiRateLimiters
     *
     * @Description: 分别按来源 IP、API Token 和付费写操作限制机器接口频率，防止 Key 探测及自动投稿滥用。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 17:05:00
     *
     * @UpdateTime: 2026-07-18 17:05:00
     *
     * @Return: void
     */
    private function configureApiRateLimiters(): void
    {
        RateLimiter::for('machine-api', static fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('geoflow.machine_api_ip_rate_limit_per_minute', 300))
        )->by('machine-api-ip:'.($request->ip() ?? 'unknown')));

        RateLimiter::for('api-token', static function (Request $request): Limit {
            $context = $request->attributes->get('api_auth');
            $tokenId = $context instanceof ApiAuthContext ? (int) ($context->token['id'] ?? 0) : 0;

            return Limit::perMinute(max(1, (int) config('geoflow.api_token_rate_limit_per_minute', 120)))
                ->by('api-token:'.($tokenId > 0 ? $tokenId : ($request->ip() ?? 'unknown')));
        });

        RateLimiter::for('mcp-paid-write', static function (Request $request): Limit {
            $context = $request->attributes->get('api_auth');
            $tokenId = $context instanceof ApiAuthContext ? (int) ($context->token['id'] ?? 0) : 0;

            return Limit::perMinute(max(1, (int) config('geoflow.mcp_paid_write_rate_limit_per_minute', 10)))
                ->by('mcp-paid-write:'.($tokenId > 0 ? $tokenId : ($request->ip() ?? 'unknown')));
        });
    }

    private function availableSitesForAdmin(Admin $admin)
    {
        $columns = ['sites.id', 'sites.name', 'sites.status', 'sites.owner_admin_id'];

        return $admin->isSuperAdmin()
            ? Site::query()
                ->select($columns)
                ->with('owner:id,username,display_name')
                ->where('status', 'active')
                ->orderBy('id')
                ->get()
            : $admin->sites()
                ->select($columns)
                ->with('owner:id,username,display_name')
                ->where('sites.status', 'active')
                ->orderBy('sites.id')
                ->get();
    }
}
