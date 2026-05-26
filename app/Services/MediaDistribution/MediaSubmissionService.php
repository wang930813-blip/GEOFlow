<?php

namespace App\Services\MediaDistribution;

use App\Models\Admin;
use App\Models\Article;
use App\Models\MediaResource;
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

        $this->credits->ensureSufficient((int) $article->site_id, $resource->sale_price);

        $submission = DB::transaction(function () use ($article, $resource, $admin, $remark): MediaSubmission {
            return MediaSubmission::query()->create([
                'site_id' => (int) $article->site_id,
                'article_id' => (int) $article->id,
                'media_resource_id' => (int) $resource->id,
                'source_type' => (string) $resource->source_type,
                'title_snapshot' => (string) $article->title,
                'content_snapshot' => $this->contentAsHtml((string) $article->content),
                'cost_price_snapshot' => $resource->cost_price,
                'sale_price_snapshot' => $resource->sale_price,
                'points_amount' => $resource->sale_price,
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

        $response = $this->client->orderInfo((string) $submission->source_type, (string) $submission->external_order_nid);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $status = $this->normalizeStatus((string) ($data['status'] ?? $data['order_status'] ?? ''));
        $url = (string) ($data['url'] ?? $data['link'] ?? $data['published_url'] ?? '');

        $submission->forceFill([
            'status' => $status !== '' ? $status : $submission->status,
            'published_url' => $url !== '' ? $url : $submission->published_url,
            'last_error_message' => (string) ($data['reason'] ?? $data['error'] ?? $submission->last_error_message ?? ''),
            'last_synced_at' => now(),
            'raw_status_response' => $response,
        ])->save();

        return $submission;
    }

    private function contentAsHtml(string $content): string
    {
        return str_contains($content, '<') ? $content : nl2br(e($content));
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
}
