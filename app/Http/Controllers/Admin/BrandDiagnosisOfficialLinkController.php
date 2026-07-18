<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBrandDiagnosisOfficialLinksRequest;
use App\Models\Admin;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BrandDiagnosisOfficialLinkController extends Controller
{
    public function edit(Request $request, int $run): View
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $admin->isSuperAdmin(), 403);

        $diagnosisRun = BrandDiagnosisRun::query()
            ->withoutGlobalScopes()
            ->with([
                'questions' => fn ($query) => $query
                    ->withoutGlobalScopes()
                    ->select(['id', 'run_id', 'question', 'sort_order'])
                    ->orderBy('sort_order')
                    ->with(['results' => fn ($resultQuery) => $resultQuery
                        ->withoutGlobalScopes()
                        ->where('status', 'success')
                        ->whereNotNull('answer')
                        ->where('answer', '<>', '')
                        ->select(['id', 'question_id', 'platform', 'status', 'snapshot_token', 'official_share_url'])
                        ->orderBy('id')]),
            ])
            ->whereKey($run)
            ->firstOrFail();

        $results = $diagnosisRun->questions
            ->flatMap(function ($question) {
                return $question->results->map(function ($result) use ($question): array {
                    $platformKey = strtolower(trim((string) $result->platform));

                    return [
                        'id' => (int) $result->id,
                        'question_order' => (int) $question->sort_order,
                        'question' => (string) $question->question,
                        'platform_key' => $platformKey,
                        'platform' => BrandDiagnosisPlatform::label($platformKey),
                        'platform_logo' => BrandDiagnosisPlatform::logoUrl($platformKey),
                        'status' => (string) $result->status,
                        'snapshot_url' => route('brand-diagnosis.snapshot', ['token' => $result->snapshot_token]),
                        'official_share_url' => (string) ($result->official_share_url ?? ''),
                        'official_domains' => implode(' / ', BrandDiagnosisPlatform::officialShareDomains($platformKey)),
                    ];
                });
            })
            ->values();
        $platformOptions = $results
            ->groupBy('platform_key')
            ->map(fn ($items, string $platformKey): array => [
                'key' => $platformKey,
                'label' => (string) $items->first()['platform'],
                'count' => $items->count(),
            ])
            ->sortBy('label')
            ->values();

        return view('admin.brand-diagnosis.official-links', [
            'pageTitle' => '品牌诊断官方链接管理',
            'activeMenu' => 'brand_diagnosis',
            'adminSiteName' => AdminWeb::siteName(),
            'run' => $diagnosisRun,
            'results' => $results,
            'platformOptions' => $platformOptions,
        ]);
    }

    public function update(UpdateBrandDiagnosisOfficialLinksRequest $request, int $run): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $admin->isSuperAdmin(), 403);

        BrandDiagnosisRun::query()
            ->withoutGlobalScopes()
            ->whereKey($run)
            ->firstOrFail();

        $submitted = collect((array) $request->validated('official_links'))
            ->mapWithKeys(static fn ($url, $resultId): array => [(int) $resultId => trim((string) ($url ?? ''))]);
        $resultIds = $submitted->keys()
            ->filter(static fn (int $resultId): bool => $resultId > 0)
            ->values();

        abort_if($resultIds->count() !== $submitted->count(), 422, '模型回答标识无效。');

        $results = BrandDiagnosisResult::query()
            ->withoutGlobalScopes()
            ->where('run_id', $run)
            ->whereIn('id', $resultIds)
            ->get()
            ->keyBy('id');

        abort_if($results->count() !== $resultIds->count(), 422, '提交内容包含不属于当前诊断的模型回答。');

        DB::transaction(function () use ($results, $submitted, $admin): void {
            foreach ($results as $resultId => $result) {
                $url = (string) $submitted->get((int) $resultId, '');
                $normalizedUrl = $url !== '' ? $url : null;
                if ($result->official_share_url === $normalizedUrl) {
                    continue;
                }

                $result->forceFill([
                    'official_share_url' => $normalizedUrl,
                    'official_share_updated_by' => (int) $admin->id,
                    'official_share_updated_at' => now(),
                ])->save();
            }
        });

        return redirect()
            ->route('admin.brand-diagnosis.official-links.edit', ['run' => $run])
            ->with('message', '官方链接已批量保存。');
    }
}
