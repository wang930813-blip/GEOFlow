<?php

namespace App\Http\Controllers;

use App\Models\BrandDiagnosisResult;
use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use App\Services\BrandDiagnosis\BrandDiagnosisSnapshotPayload;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Http\Response;

class BrandDiagnosisSnapshotController extends Controller
{
    public function show(string $token, BrandDiagnosisSnapshotPayload $snapshots): Response
    {
        $result = BrandDiagnosisResult::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('snapshot_token', $token)
            ->first();

        if (! $result instanceof BrandDiagnosisResult) {
            return response()->view('brand-diagnosis.snapshot', [
                'snapshot' => null,
            ], 404);
        }

        $payload = $snapshots->captureIfMissing($result);
        if ($payload === []) {
            return response()
                ->view('brand-diagnosis.snapshot', ['snapshot' => null], 404)
                ->header('Cache-Control', 'no-store, max-age=0');
        }

        $platform = (string) ($payload['platform'] ?? $result->platform);
        $officialShareUrl = trim((string) ($result->official_share_url ?? ''));
        $snapshot = [
            'brand' => (string) ($payload['brand'] ?? ''),
            'question' => (string) ($payload['question'] ?? ''),
            'answer_html' => ArticleHtmlPresenter::markdownToHtml((string) ($payload['answer'] ?? '')),
            'platform' => BrandDiagnosisPlatform::label($platform),
            'platform_logo' => BrandDiagnosisPlatform::logoUrl($platform),
            'status' => (string) ($payload['status'] ?? ''),
            'checked_at' => (string) ($payload['checked_at'] ?? ''),
            'official_share_url' => BrandDiagnosisPlatform::isOfficialShareUrl($platform, $officialShareUrl)
                ? $officialShareUrl
                : '',
            'sources' => collect((array) ($payload['sources'] ?? []))
                ->filter(fn ($source): bool => is_array($source) && $snapshots->isHttpUrl((string) ($source['url'] ?? '')))
                ->map(static fn (array $source): array => [
                    'title' => (string) (($source['title'] ?? '') ?: ($source['url'] ?? '')),
                    'url' => (string) ($source['url'] ?? ''),
                    'domain' => (string) ($source['domain'] ?? ''),
                ])
                ->values()
                ->all(),
        ];

        return response()
            ->view('brand-diagnosis.snapshot', ['snapshot' => $snapshot])
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
