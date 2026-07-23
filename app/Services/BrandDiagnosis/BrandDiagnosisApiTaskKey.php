<?php

namespace App\Services\BrandDiagnosis;

use Illuminate\Support\Str;

class BrandDiagnosisApiTaskKey
{
    public function generate(int $runId): string
    {
        $secret = (string) config('app.key');
        $payload = $runId.'|'.Str::ulid()->toString().'|'.Str::random(16);

        return 'bdg_'.substr(hash_hmac('sha256', $payload, $secret), 0, 32);
    }

    public function isValid(string $key): bool
    {
        return preg_match('/^bdg_[a-f0-9]{32}$/', trim($key)) === 1;
    }
}
