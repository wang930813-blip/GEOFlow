<?php

namespace App\Services\MediaDistribution;

use App\Models\MediaResource;
use App\Models\MediaSubmission;
use Generator;

interface MediaPlatformClient
{
    public function platformId(): int;

    /**
     * @return Generator<int, array<int, array<string,mixed>>>
     */
    public function resourcePages(string $sourceType): Generator;

    /**
     * @return array<string,mixed>
     */
    public function submit(MediaSubmission $submission, MediaResource $resource, string $remark = ''): array;

    /**
     * @return array<string,mixed>
     */
    public function orderInfo(MediaSubmission|string $submission, ?string $orderNid = null): array;

    /**
     * @return array<string,mixed>
     */
    public function cancelOrder(MediaSubmission|string $submission, ?string $orderNid = null, ?string $reason = null): array;

    /**
     * @return array<string,mixed>
     */
    public function appeal(MediaSubmission $submission, string $content): array;
}
