<?php

namespace App\Services\MediaDistribution;

use App\Models\Admin;
use App\Models\Article;
use App\Models\MediaResource;
use App\Models\MediaResourceSitePrice;
use App\Models\MediaSubmission;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MediaSubmissionService
{
    public function __construct(
        private readonly MediaDistributionClient $client,
        private readonly SiteCreditService $credits,
    ) {}

    public function submit(Article $article, MediaResource $resource, Admin $admin, string $remark = ''): MediaSubmission
    {
        if ($article->site_id === null || (int) $article->site_id <= 0) {
            throw new RuntimeException('文章缺少站点归属');
        }
        if ($resource->status !== 'active') {
            throw new RuntimeException('媒体资源不可投稿');
        }
        if (trim((string) $article->content) === '') {
            throw new RuntimeException('文章内容不能为空');
        }

        $salePrice = $this->salePriceForSite($resource, (int) $article->site_id);
        $this->credits->ensureSufficient((int) $article->site_id, $salePrice);

        $submission = DB::transaction(function () use ($article, $resource, $admin, $remark, $salePrice): MediaSubmission {
            return MediaSubmission::query()->create([
                'site_id' => (int) $article->site_id,
                'article_id' => (int) $article->id,
                'media_resource_id' => (int) $resource->id,
                'source_type' => (string) $resource->source_type,
                'title_snapshot' => (string) $article->title,
                'content_snapshot' => $this->contentAsHtml((string) $article->content),
                'cost_price_snapshot' => $resource->cost_price,
                'sale_price_snapshot' => $salePrice,
                'points_amount' => $salePrice,
                'status' => 'submitting',
                'remark' => $remark,
                'submitted_by_admin_id' => (int) $admin->id,
            ]);
        });

        $deducted = false;
        try {
            $this->credits->deductForSubmission($submission, (int) $admin->id);
            $deducted = true;
            $response = $this->client->send($submission->source_type, [
                'resource_id' => (string) $resource->external_resource_id,
                'title' => (string) $submission->title_snapshot,
                'content' => (string) $submission->content_snapshot,
                'remark' => $remark,
                'third_id' => 'geoflow-'.$submission->site_id.'-'.$submission->id,
            ]);

            $orderNid = (string) data_get($response, 'data.order_nid', '');
            $submission->forceFill([
                'external_order_nid' => $orderNid,
                'status' => $orderNid !== '' ? 'submitted' : 'failed',
                'submitted_at' => now(),
                'raw_submit_response' => $response,
                'last_error_message' => $orderNid !== '' ? null : '投稿接口未返回订单号',
            ])->save();
        } catch (\Throwable $e) {
            if ($deducted) {
                $this->credits->refundForSubmission($submission, (int) $admin->id);
            }
            $submission->forceFill([
                'status' => 'failed',
                'last_error_message' => $e->getMessage(),
            ])->save();

            throw $e;
        }

        return $submission;
    }

    public function syncStatus(MediaSubmission $submission): MediaSubmission
    {
        if ((string) $submission->external_order_nid === '') {
            throw new RuntimeException('投稿订单缺少第三方订单号');
        }

        $orderNid = (string) $submission->external_order_nid;
        $response = $this->client->orderInfo((string) $submission->source_type, $orderNid);
        $data = $this->orderInfoData($response, $orderNid);
        $status = $this->normalizeStatus((string) ($data['status'] ?? $data['order_status'] ?? ''));
        $url = (string) ($data['url'] ?? $data['link'] ?? $data['published_url'] ?? '');

        $previousStatus = (string) $submission->status;
        $submission->forceFill([
            'status' => $status !== '' ? $status : $submission->status,
            'published_url' => $url !== '' ? $url : $submission->published_url,
            'last_error_message' => (string) ($data['reason'] ?? $data['error'] ?? ''),
            'last_synced_at' => now(),
            'raw_status_response' => $response,
        ])->save();

        if (in_array((string) $submission->status, ['rejected', 'cancelled'], true)
            && ! in_array($previousStatus, ['rejected', 'cancelled'], true)) {
            $this->credits->refundForSubmission($submission, null, '媒体订单'.$submission->status.'自动退回');
        }

        return $submission;
    }

    /**
     * @return array{synced:int,failed:int}
     */
    public function syncPending(int $limit = 100): array
    {
        $synced = 0;
        $failed = 0;

        MediaSubmission::withoutGlobalScope('current_site')
            ->whereIn('status', ['submitting', 'submitted', 'publishing', 'appealing'])
            ->where('external_order_nid', '<>', '')
            ->orderBy('last_synced_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->get()
            ->each(function (MediaSubmission $submission) use (&$synced, &$failed): void {
                try {
                    $this->syncStatus($submission);
                    $synced++;
                } catch (\Throwable $e) {
                    $failed++;
                    $submission->forceFill([
                        'last_error_message' => $e->getMessage(),
                        'last_synced_at' => now(),
                    ])->save();
                }
            });

        return ['synced' => $synced, 'failed' => $failed];
    }

    public function cancel(MediaSubmission $submission, string $reason): MediaSubmission
    {
        if ((string) $submission->external_order_nid === '') {
            throw new RuntimeException('投稿订单缺少第三方订单号');
        }
        if (in_array($submission->status, ['published', 'cancelled'], true)) {
            throw new RuntimeException('当前订单状态不能取消');
        }

        $response = $this->client->cancelOrder(
            (string) $submission->source_type,
            (string) $submission->external_order_nid,
            $reason
        );

        $submission->forceFill([
            'status' => 'cancelled',
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
            'raw_cancel_response' => $response,
        ])->save();
        $this->credits->refundForSubmission($submission, null, '媒体订单取消退回');

        return $submission;
    }

    public function appeal(MediaSubmission $submission, string $content): MediaSubmission
    {
        if ((string) $submission->external_order_nid === '') {
            throw new RuntimeException('投稿订单缺少第三方订单号');
        }
        if (! in_array($submission->status, ['rejected', 'failed'], true)) {
            throw new RuntimeException('仅失败或拒稿订单可以申诉');
        }

        $response = $this->client->rejection(
            (string) $submission->source_type,
            (string) $submission->external_order_nid,
            $content
        );

        $submission->forceFill([
            'status' => 'appealing',
            'appeal_content' => $content,
            'appealed_at' => now(),
            'raw_appeal_response' => $response,
        ])->save();

        return $submission;
    }

    private function contentAsHtml(string $content): string
    {
        return str_contains($content, '<') ? $content : nl2br(e($content));
    }

    private function salePriceForSite(MediaResource $resource, int $siteId): string
    {
        $sitePrice = MediaResourceSitePrice::query()
            ->where('media_resource_id', (int) $resource->id)
            ->where('site_id', $siteId)
            ->value('sale_price');

        return number_format((float) ($sitePrice ?? $resource->sale_price), 2, '.', '');
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'published', 'success', 'done', '1' => 'published',
            'rejected', 'reject', 'failed' => 'rejected',
            'cancelled', 'canceled' => 'cancelled',
            'publishing', 'processing' => 'publishing',
            'submitted', 'pending' => 'submitted',
            default => '',
        };
    }

    /**
     * @param  array<string,mixed>  $response
     * @return array<string,mixed>
     */
    private function orderInfoData(array $response, string $orderNid): array
    {
        $data = $response['data'] ?? [];
        if (! is_array($data)) {
            return [];
        }

        if (isset($data['status']) || isset($data['order_status'])) {
            return $data;
        }

        if (isset($data[$orderNid]) && is_array($data[$orderNid])) {
            return $data[$orderNid];
        }

        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowOrderNid = (string) ($row['order_nid'] ?? $row['nid'] ?? $row['order_id'] ?? $row['id'] ?? '');
            if ($rowOrderNid === $orderNid) {
                return $row;
            }
        }

        $first = reset($data);

        return is_array($first) ? $first : [];
    }
}
