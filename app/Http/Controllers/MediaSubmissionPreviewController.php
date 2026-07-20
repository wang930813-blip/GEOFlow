<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-18
 *
 * @Time: 15:43
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： MediaSubmissionPreviewController.php
 *
 * @Description: 校验媒体投稿预览令牌、状态与有效期，并输出统一清洗后的投稿快照。
 */

namespace App\Http\Controllers;

use App\Models\MediaSubmission;
use App\Services\MediaDistribution\MediaSubmissionHtmlSanitizer;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MediaSubmissionPreviewController extends Controller
{
    /** @var list<string> */
    private const PREVIEWABLE_STATUSES = ['submitting', 'submitted', 'publishing', 'published'];

    /**
     * show
     * 校验投稿预览凭证、业务状态和有效期限，仅输出经过统一允许列表清洗的正文。
     *
     * @Url [GET] /media-submission-preview/{submission}/{token}
     *      登录 否
     *
     *      分页参数：
     *      无
     *
     *      筛选参数：
     *      submission int    必选  投稿记录编号
     *      token      string 必选  随机预览令牌
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Return Response 安全投稿预览响应
     *
     * @Throws NotFoundHttpException 令牌错误、状态不可预览、时间基准缺失或预览已过期
     */
    public function show(
        int $submission,
        string $token,
        MediaSubmissionHtmlSanitizer $htmlSanitizer
    ): Response {
        if (trim($token) === '') {
            throw new NotFoundHttpException;
        }

        $mediaSubmission = MediaSubmission::withoutGlobalScope('current_site')
            ->whereKey($submission)
            ->where('preview_token', $token)
            ->whereIn('status', self::PREVIEWABLE_STATUSES)
            ->first();

        if (! $mediaSubmission instanceof MediaSubmission || $this->previewExpired($mediaSubmission)) {
            throw new NotFoundHttpException;
        }

        return response()
            ->view('media-submission-preview.show', [
                'submission' => $mediaSubmission,
                'sanitizedContentHtml' => $htmlSanitizer->sanitize((string) $mediaSubmission->content_snapshot),
            ])
            ->withHeaders([
                'Cache-Control' => 'no-store, private',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self' http: https:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }

    /**
     * @Name: previewExpired
     *
     * @Description: 优先以第三方受理时间计时，提交期间尚无受理时间时回退创建时间，确保抓取窗口有限且兼容现有同步抓取流程。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Param: MediaSubmission $submission 投稿记录
     *
     * @Return: bool 预览是否已过期或缺少时间基准
     */
    private function previewExpired(MediaSubmission $submission): bool
    {
        if ((string) $submission->status === 'published') {
            return false;
        }

        $referenceTime = $submission->submitted_at ?? $submission->created_at;
        if ($referenceTime === null) {
            return true;
        }

        $ttlMinutes = max(1, (int) config('media_distribution.preview_ttl_minutes', 1440));

        return now()->greaterThanOrEqualTo($referenceTime->copy()->addMinutes($ttlMinutes));
    }
}
