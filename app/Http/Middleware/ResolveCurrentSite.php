<?php

namespace App\Http\Middleware;

use App\Models\HostedSiteProfile;
use App\Models\Site;
use App\Services\Site\HostedSiteResolver;
use App\Support\Site\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentSite
{
    public function __construct(
        private readonly HostedSiteResolver $resolver,
        private readonly CurrentSite $currentSite,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $hostname = (string) $request->attributes->get('normalized_host', '');
        if ($this->resolver->isPrimaryHost($hostname) || $this->isDomesticSiteHost($hostname)) {
            $this->currentSite->setPrimary($hostname);
            $request->attributes->set('current_site', $this->currentSite);

            return $next($request);
        }

        $profile = $this->resolver->findHostedProfile($hostname);
        if (! $profile instanceof HostedSiteProfile) {
            abort(404);
        }

        $this->currentSite->setHosted($profile);
        $request->attributes->set('current_site', $this->currentSite);

        return $next($request);
    }

    private function isDomesticSiteHost(string $hostname): bool
    {
        if ($hostname === '') {
            return false;
        }

        try {
            return Site::query()
                ->where('status', 'active')
                ->where(function ($query) use ($hostname): void {
                    $query->where('domain', $hostname)->orWhere('custom_domain', $hostname);
                })
                ->exists();
        } catch (\Throwable) {
            // 安装或迁移阶段数据库尚未就绪时，继续使用上游托管站点解析流程。
            return false;
        }
    }
}
