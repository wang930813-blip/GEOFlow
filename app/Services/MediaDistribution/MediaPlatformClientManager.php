<?php

namespace App\Services\MediaDistribution;

use App\Support\MediaDistribution\MediaPlatform;
use InvalidArgumentException;

class MediaPlatformClientManager
{
    public function __construct(
        private readonly MediaDistributionClient $ceyingMedia1,
        private readonly ChaoJiMeiJieClient $ceyingMedia2,
    ) {}

    public function forPlatform(int|string|null $platformId): MediaPlatformClient
    {
        return match ((int) $platformId) {
            MediaPlatform::CEYING_MEDIA_1, 0 => $this->ceyingMedia1,
            MediaPlatform::CEYING_MEDIA_2 => $this->ceyingMedia2,
            default => throw new InvalidArgumentException('不支持的媒体平台：'.(string) $platformId),
        };
    }
}
