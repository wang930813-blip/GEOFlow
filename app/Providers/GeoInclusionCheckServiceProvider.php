<?php

namespace App\Providers;

use App\Services\GeoFlow\AiSearchPlatformChecker;
use App\Services\GeoFlow\ChatAiSearchPlatformChecker;
use Illuminate\Support\ServiceProvider;

class GeoInclusionCheckServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AiSearchPlatformChecker::class, ChatAiSearchPlatformChecker::class);
    }
}
