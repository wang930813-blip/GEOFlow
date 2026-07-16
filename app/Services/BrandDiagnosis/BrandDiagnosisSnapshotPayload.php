<?php

namespace App\Services\BrandDiagnosis;

use App\Models\BrandDiagnosisResult;

final class BrandDiagnosisSnapshotPayload
{
    /**
     * @return array<string,mixed>
     */
    public function captureIfMissing(BrandDiagnosisResult $result): array
    {
        $existing = (array) ($result->snapshot_payload ?? []);
        if ($existing !== []) {
            return $existing;
        }

        if ((string) $result->status !== 'success' || trim((string) ($result->answer ?? '')) === '') {
            return [];
        }

        $result->load([
            'run' => fn ($query) => $query
                ->withoutGlobalScopes()
                ->select(['id', 'brand_name']),
            'question' => fn ($query) => $query
                ->withoutGlobalScopes()
                ->select(['id', 'question']),
            'sources' => fn ($query) => $query
                ->withoutGlobalScopes()
                ->select(['id', 'result_id', 'title', 'url', 'domain'])
                ->orderBy('id'),
        ]);

        $payload = [
            'version' => 1,
            'brand' => (string) ($result->run?->brand_name ?? ''),
            'question' => (string) ($result->question?->question ?? ''),
            'answer' => (string) ($result->answer ?? ''),
            'platform' => (string) $result->platform,
            'status' => (string) $result->status,
            'checked_at' => $result->checked_at?->format('Y-m-d H:i:s') ?? '',
            'sources' => $result->sources
                ->filter(fn ($source): bool => $this->isHttpUrl((string) $source->url))
                ->map(static fn ($source): array => [
                    'title' => (string) ($source->title ?: $source->url),
                    'url' => (string) $source->url,
                    'domain' => (string) $source->domain,
                ])
                ->values()
                ->all(),
        ];

        $result->forceFill(['snapshot_payload' => $payload])->save();

        return $payload;
    }

    public function isHttpUrl(string $url): bool
    {
        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array($scheme, ['http', 'https'], true);
    }
}
