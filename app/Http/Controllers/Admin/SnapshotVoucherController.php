<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BrandDiagnosisBrandMention;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisSource;
use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use App\Services\BrandDiagnosis\BrandDiagnosisSnapshotPayload;
use App\Support\MonitoringCenter\VirtualSearchReportSnapshots;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SnapshotVoucherController extends Controller
{
    public function show(Request $request, BrandDiagnosisSnapshotPayload $snapshots): Response
    {
        $id = $request->integer('id');
        $result = $id > 0
            ? BrandDiagnosisResult::query()
                ->withoutGlobalScope('current_site')
                ->whereHas('run', fn ($query) => $query->withoutGlobalScopes(['current_site', 'admin_owner']))
                ->with([
                    'question:id,question',
                    'sources:id,result_id,title,url,domain',
                    'brandMentions' => fn ($query) => $query->where('is_target_brand', true)->orderBy('mention_rank'),
                ])
                ->whereKey($id)
                ->first()
            : null;
        $virtualVoucher = $id < 0 ? $this->virtualVoucher($id) : null;

        return response()->view('admin.snapshot-voucher.show', [
            'voucher' => $virtualVoucher ?? ($result instanceof BrandDiagnosisResult ? $this->voucher($result, $snapshots) : null),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function voucher(BrandDiagnosisResult $result, BrandDiagnosisSnapshotPayload $snapshots): array
    {
        $platformKey = $this->normalizePlatformKey((string) $result->platform);
        $platformName = $this->platformLabel($platformKey);
        $question = trim((string) ($result->question?->question ?? ''));
        $checkedAt = $result->checked_at ?? $result->created_at;
        $target = $this->targetBrandName($result);
        $answer = $snapshots->displayAnswer((string) $result->answer);

        return [
            'id' => (int) $result->id,
            'platform_key' => $platformKey,
            'platform' => $platformName,
            'platform_icon' => $this->platformIcon($platformKey),
            'platform_url' => $this->conversationUrl($result, $platformKey),
            'question' => $question !== '' ? $question : 'AI 搜索快照',
            'time' => $checkedAt?->format('Y-m-d H:i:s') ?? '',
            'target' => $target,
            'answer_html' => $answer !== ''
                ? ArticleHtmlPresenter::markdownToHtml($answer)
                : '<p>暂无 AI 对话详情</p>',
            'sources' => $result->sources
                ->map(fn (BrandDiagnosisSource $source): array => [
                    'title' => (string) $source->title,
                    'url' => (string) $source->url,
                    'domain' => (string) $source->domain,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function virtualVoucher(int $id): ?array
    {
        $snapshot = VirtualSearchReportSnapshots::find($id);
        if ($snapshot === null) {
            return null;
        }

        return [
            'id' => (int) $snapshot['id'],
            'platform_key' => 'wenxin',
            'platform' => $this->platformLabel('wenxin'),
            'platform_icon' => $this->platformIcon('wenxin'),
            'platform_url' => $this->platformUrl('wenxin'),
            'question' => (string) $snapshot['question'],
            'time' => now()->format('Y-m-d H:i:s'),
            'target' => '-',
            'answer_html' => ArticleHtmlPresenter::markdownToHtml((string) $snapshot['answer']),
            'sources' => collect($snapshot['sources'] ?? [])
                ->map(fn (array $source): array => [
                    'title' => (string) ($source['title'] ?? ''),
                    'url' => (string) ($source['url'] ?? ''),
                    'domain' => parse_url((string) ($source['url'] ?? ''), PHP_URL_HOST) ?: '',
                ])
                ->push([
                    'title' => '文心一言原始对话',
                    'url' => (string) $snapshot['url'],
                    'domain' => 'chat.baidu.com',
                ])
                ->values()
                ->all(),
        ];
    }

    private function targetBrandName(BrandDiagnosisResult $result): string
    {
        $mention = $result->brandMentions->first();

        if ($mention instanceof BrandDiagnosisBrandMention && trim((string) $mention->brand_name) !== '') {
            return (string) $mention->brand_name;
        }

        return '-';
    }

    private function conversationUrl(BrandDiagnosisResult $result, string $platformKey): string
    {
        $meta = (array) ($result->meta ?? []);
        $raw = (array) ($result->raw_response ?? []);

        foreach ([
            $result->official_share_url,
            data_get($meta, 'original_url'),
            data_get($meta, 'share_url'),
            data_get($meta, 'thread_url'),
            data_get($raw, 'original_url'),
            data_get($raw, 'share_url'),
            data_get($raw, 'thread_url'),
        ] as $url) {
            if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                return $url;
            }
        }

        return $this->platformUrl($platformKey);
    }

    private function platformUrl(string $platform): string
    {
        $platform = $this->normalizePlatformKey($platform);

        if (BrandDiagnosisPlatform::isSupported($platform)) {
            return BrandDiagnosisPlatform::chatUrl($platform);
        }

        return match ($platform) {
            'yuanbao' => 'https://yuanbao.tencent.com/',
            'kimi' => 'https://www.kimi.com/',
            default => '',
        };
    }

    private function platformIcon(string $platform): string
    {
        return match ($this->normalizePlatformKey($platform)) {
            'deepseek' => asset('assets/monitoring-center/assets/ai-platforms/deepseek.png'),
            'doubao' => asset('assets/monitoring-center/assets/ai-platforms/doubao.png'),
            'yuanbao' => asset('assets/monitoring-center/assets/ai-platforms/yuanbao.png'),
            'wenxin' => asset('assets/monitoring-center/assets/ai-platforms/wenxin.png'),
            'qianwen' => asset('assets/monitoring-center/assets/ai-platforms/qianwen.png'),
            default => '',
        };
    }

    private function platformLabel(string $platform): string
    {
        return match ($this->normalizePlatformKey($platform)) {
            'doubao' => '豆包',
            'deepseek' => 'DeepSeek',
            'yuanbao' => '腾讯元宝',
            'wenxin' => '文心一言',
            'qianwen' => '千问',
            'kimi' => 'Kimi',
            default => $platform,
        };
    }

    private function normalizePlatformKey(string $platform): string
    {
        return match (strtolower(trim($platform))) {
            'tencent_yuanbao' => 'yuanbao',
            'ernie' => 'wenxin',
            'tongyi' => 'qianwen',
            default => strtolower(trim($platform)),
        };
    }
}
