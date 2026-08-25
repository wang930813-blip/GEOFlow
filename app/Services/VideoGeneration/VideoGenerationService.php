<?php

namespace App\Services\VideoGeneration;

use App\Jobs\StartVideoGenerationJob;
use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\VideoGenerationJob;
use App\Services\Billing\AdminResourceQuotaService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VideoGenerationService
{
    public function __construct(
        private readonly AdminResourceQuotaService $quotaService,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public function create(Admin $admin, Site $site, array $payload): VideoGenerationJob
    {
        if (! (bool) config('video-generation.enabled', true)) {
            throw new RuntimeException('视频生成服务未开启');
        }

        $videoCount = max(1, (int) ($payload['video_count'] ?? 1));
        if (! $admin->isSuperAdmin()) {
            $this->quotaService->assertCanUse(
                (int) $admin->id,
                (int) $site->id,
                PlatformPlan::RESOURCE_VIDEO_GENERATIONS,
                $videoCount,
                $admin
            );
        }

        $requestPayload = $this->moneyPrinterPayload($payload, $videoCount);
        $job = DB::transaction(function () use ($admin, $site, $payload, $videoCount, $requestPayload): VideoGenerationJob {
            $job = VideoGenerationJob::query()->create([
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $admin->id,
                'created_by_admin_id' => (int) $admin->id,
                'title' => $this->title((string) $payload['subject']),
                'subject' => trim((string) $payload['subject']),
                'script' => trim((string) ($payload['script'] ?? '')),
                'terms' => trim((string) ($payload['terms'] ?? '')),
                'negative_terms' => trim((string) ($payload['negative_terms'] ?? '')),
                'video_source' => trim((string) ($payload['video_source'] ?? 'pexels')) ?: 'pexels',
                'video_aspect' => trim((string) ($payload['video_aspect'] ?? '9:16')) ?: '9:16',
                'video_count' => $videoCount,
                'cover_image' => trim((string) ($payload['cover_image'] ?? '')),
                'status' => 'queued',
                'progress' => 0,
                'request_payload' => $requestPayload,
            ]);

            if (! $admin->isSuperAdmin()) {
                $ledger = $this->quotaService->consume(
                    (int) $admin->id,
                    (int) $site->id,
                    PlatformPlan::RESOURCE_VIDEO_GENERATIONS,
                    $videoCount,
                    [
                        'actor_admin_id' => (int) $admin->id,
                        'subject_type' => VideoGenerationJob::class,
                        'subject_id' => (int) $job->id,
                        'idempotency_key' => 'video-generation:'.$job->id,
                        'remark' => '生成视频',
                    ]
                );

                $job->forceFill(['quota_ledger_id' => (int) $ledger->id])->save();
            }

            return $job;
        });

        StartVideoGenerationJob::dispatch((int) $job->id);

        return $job;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function moneyPrinterPayload(array $payload, int $videoCount): array
    {
        return [
            'video_subject' => trim((string) $payload['subject']),
            'video_script' => trim((string) ($payload['script'] ?? '')),
            'video_terms' => $this->splitTerms((string) ($payload['terms'] ?? '')),
            'video_aspect' => trim((string) ($payload['video_aspect'] ?? '9:16')) ?: '9:16',
            'video_count' => $videoCount,
            'video_source' => trim((string) ($payload['video_source'] ?? 'pexels')) ?: 'pexels',
            'video_concat_mode' => 'random',
            'video_clip_duration' => 5,
            'voice_name' => trim((string) ($payload['voice_name'] ?? 'zh-CN-XiaoxiaoNeural')) ?: 'zh-CN-XiaoxiaoNeural',
            'voice_volume' => 1,
            'voice_rate' => 1,
            'bgm_type' => 'random',
            'bgm_volume' => 0.2,
            'subtitle_enabled' => true,
            'font_name' => 'Microsoft YaHei',
            'font_size' => 60,
            'n_threads' => 2,
            'paragraph_number' => 1,
            'video_negative_terms' => trim((string) ($payload['negative_terms'] ?? '')),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function splitTerms(string $terms): array
    {
        return collect(preg_split('/[,\x{FF0C}\r\n]+/u', $terms) ?: [])
            ->map(static fn (string $term): string => trim($term))
            ->filter(static fn (string $term): bool => $term !== '')
            ->values()
            ->all();
    }

    private function title(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '') {
            return '未命名视频';
        }

        return mb_substr($subject, 0, 80, 'UTF-8');
    }
}
