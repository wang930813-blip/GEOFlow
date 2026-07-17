<?php

namespace App\Providers;

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
