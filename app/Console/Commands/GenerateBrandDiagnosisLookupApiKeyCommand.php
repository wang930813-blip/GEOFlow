<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class GenerateBrandDiagnosisLookupApiKeyCommand extends Command
{
    protected $signature = 'geoflow:brand-diagnosis-lookup-key';

    protected $description = '生成品牌诊断查询 API 的共享 X-Api-Key';

    public function handle(): int
    {
        $this->line('BRAND_DIAGNOSIS_LOOKUP_API_KEY='.bin2hex(random_bytes(32)));

        return self::SUCCESS;
    }
}
