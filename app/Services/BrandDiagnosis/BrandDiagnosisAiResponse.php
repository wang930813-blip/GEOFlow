<?php

namespace App\Services\BrandDiagnosis;

class BrandDiagnosisAiResponse
{
    /**
     * @param  list<array{title:string,url:string,type:string,meta?:array<string,mixed>}>  $sources
     * @param  list<array{brand:string,mention_count:int,mention_rank:int,sentiment:string,evidence:string,source_count?:int,meta?:array<string,mixed>}>  $brandMentions
     * @param  array<string,mixed>  $rawResponse
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $sources,
        public readonly array $rawResponse,
        public readonly array $meta = [],
        public readonly array $brandMentions = [],
    ) {}
}
