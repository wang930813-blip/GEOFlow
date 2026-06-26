<?php

namespace App\Services\GeoFlow;

use App\Models\Keyword;
use App\Models\KeywordLibrary;

interface AiSearchPlatformChecker
{
    public function check(
        string $platform,
        string $question,
        KeywordLibrary $library,
        Keyword $keyword,
        ?int $aiOwnerAdminId = null
    ): AiSearchCheckResponse;
}
