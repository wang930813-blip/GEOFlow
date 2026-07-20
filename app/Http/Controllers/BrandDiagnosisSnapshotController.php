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
            ->whereHas('run', fn ($query) => $query->withoutGlobalScopes(['current_site', 'admin_owner']))
            ->where('snapshot_token', $token)
            ->first();

        if (! $result instanceof BrandDiagnosisResult) {
            return response()->view('admin.snapshot-voucher.show', [
                'voucher' => null,
                'missingMessage' => '快照不存在',
            ], 404);
        }

        $payload = $snapshots->captureIfMissing($result);
        if ($payload === []) {
            return response()
                ->view('admin.snapshot-voucher.show', [
                    'voucher' => null,
                    'missingMessage' => '快照不存在',
                ], 404)
                ->header('Cache-Control', 'no-store, max-age=0');
        }

        $platform = (string) ($payload['platform'] ?? $result->platform);
        $officialShareUrl = trim((string) ($result->official_share_url ?? ''));
        $platformUrl = BrandDiagnosisPlatform::isOfficialShareUrl($platform, $officialShareUrl)
            ? $officialShareUrl
            : BrandDiagnosisPlatform::chatUrl($platform);
        $voucher = [
            'id' => (int) $result->id,
            'platform_key' => $platform,
            'platform' => BrandDiagnosisPlatform::label($platform),
            'platform_icon' => BrandDiagnosisPlatform::logoUrl($platform),
            'platform_url' => $platformUrl,
            'question' => (string) ($payload['question'] ?? ''),
            'answer_html' => ArticleHtmlPresenter::markdownToHtml($snapshots->displayAnswer((string) ($payload['answer'] ?? ''))),
            'time' => (string) ($payload['checked_at'] ?? ''),
            'target' => (string) ($payload['brand'] ?? ''),
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
            ->view('admin.snapshot-voucher.show', [
                'voucher' => $voucher,
                'missingMessage' => '快照不存在',
            ])
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
