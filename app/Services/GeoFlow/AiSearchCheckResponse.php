<?php

namespace App\Services\GeoFlow;

final readonly class AiSearchCheckResponse
{
    /**
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public string $platform,
        public string $question,
        public string $answer,
        public bool $keywordHit,
        public bool $brandHit,
        public string $status,
        public ?string $errorMessage = null,
        public array $meta = [],
    ) {}
}
