<?php

namespace App\Services\BrandDiagnosis;

class BrandDiagnosisAiResponse
{
    /**
     * @param  list<array{title:string,url:string,type:string,meta?:array<string,mixed>}>  $sources
     * @param  array<string,mixed>  $rawResponse
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $sources,
        public readonly array $rawResponse,
        public readonly array $meta = [],
    ) {}
}
