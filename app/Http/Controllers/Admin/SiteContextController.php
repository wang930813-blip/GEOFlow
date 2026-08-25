<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Support\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SiteContextController extends Controller
{
    public function switch(Request $request, CurrentSite $currentSite): RedirectResponse|Response
    {
        $payload = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
        ]);

        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            abort(403);
        }

        $site = $currentSite->switchForAdmin($admin, (int) $payload['site_id'], $request);
        if ($site === null) {
            abort(403);
        }

        return redirect()->back()->with('message', '当前站点已切换为 '.$site->name);
    }
}
