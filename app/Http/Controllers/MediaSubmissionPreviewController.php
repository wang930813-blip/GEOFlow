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

        $decoded = $this->decodeHtmlEntities($content);
        if ($this->looksLikeRenderedHtml($decoded)) {
            return $decoded;
        }

        return ArticleHtmlPresenter::markdownToHtml($this->htmlLineBreaksToMarkdownNewlines($content));
    }

    private function looksLikeRenderedHtml(string $content): bool
    {
        return preg_match('/<\/?(?:p|h[1-6]|ul|ol|li|blockquote|pre|table|div|section|article|img|figure|strong|em)\b/i', $content) === 1;
    }

    private function looksLikeMarkdown(string $content): bool
    {
        $plain = $this->htmlLineBreaksToMarkdownNewlines(strip_tags($content, '<br>'));

        return preg_match('/(^|\n)\s{0,3}#{1,6}\s+\S/u', $plain) === 1
            || preg_match('/(^|\n)\s{0,3}(?:[-*+]\s+|\d+[.)]\s+)/u', $plain) === 1
            || preg_match('/(?:\*\*|__)[^\n]+(?:\*\*|__)/u', $plain) === 1
            || preg_match('/(^|\n)\s{0,3}>\s+\S/u', $plain) === 1
            || preg_match('/(^|\n)\s{0,3}```/u', $plain) === 1;
    }

    private function htmlLineBreaksToMarkdownNewlines(string $content): string
    {
        $content = preg_replace('/<br\s*\/?>\s*/i', "\n", $content) ?? $content;

        return $this->decodeHtmlEntities($content);
    }

    private function decodeHtmlEntities(string $content): string
    {
        return html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
