<?php

namespace App\Http\Controllers;

use App\Models\MediaSubmission;
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
        ]);
    }
}
