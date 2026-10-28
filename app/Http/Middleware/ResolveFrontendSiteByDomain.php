<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Support\CurrentSite;
use App\Support\SiteDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveFrontendSiteByDomain
{
    public function __construct(private readonly CurrentSite $currentSite) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = SiteDomain::normalize($request->getHost());
        $site = $host !== ''
            ? Site::query()
                ->select(['id', 'owner_admin_id', 'name', 'domain', 'custom_domain', 'custom_https_enabled', 'status', 'settings', 'created_at', 'updated_at'])
                ->where('status', 'active')
                ->where(function ($query) use ($host): void {
                    $query->where('domain', $host)->orWhere('custom_domain', $host);
                })
                ->first()
            : null;

        if ($site instanceof Site) {
            $this->currentSite->set($site);

            if ((bool) $site->custom_https_enabled
                && $site->custom_domain !== ''
                && $host === SiteDomain::normalize((string) $site->custom_domain)
                && ! $request->isSecure()) {
                return redirect()->to(
                    'https://'.$request->getHost().$request->getRequestUri(),
                    301
                );
            }

            return $next($request);
        }

        $this->currentSite->set(null);
        if ($this->hasBoundFrontendDomains()) {
            abort(404);
        }

        return $next($request);
    }

    private function hasBoundFrontendDomains(): bool
    {
        return Site::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->where('domain', '!=', '')->orWhere('custom_domain', '!=', '');
            })
            ->exists();
    }
}
