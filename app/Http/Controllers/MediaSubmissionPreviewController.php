<?php

namespace App\Http\Controllers;

use App\Models\MediaSubmission;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MediaSubmissionPreviewController extends Controller
{
    public function show(int $submission, string $token): View
    {
        $mediaSubmission = MediaSubmission::withoutGlobalScope('current_site')
            ->whereKey($submission)
            ->where('preview_token', $token)
            ->first();

        if (! $mediaSubmission instanceof MediaSubmission || trim($token) === '') {
            throw new NotFoundHttpException();
        }

        return view('media-submission-preview.show', [
            'submission' => $mediaSubmission,
            'contentHtml' => $this->contentHtml((string) $mediaSubmission->content_snapshot),
        ]);
    }

    private function contentHtml(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        return $this->looksLikeHtml($content)
            ? $content
            : ArticleHtmlPresenter::markdownToHtml($content);
    }

    private function looksLikeHtml(string $content): bool
    {
        return preg_match('/<\/?(?:p|h[1-6]|ul|ol|li|blockquote|pre|table|div|section|article|img|figure|strong|em|br)\b/i', $content) === 1;
    }
}
