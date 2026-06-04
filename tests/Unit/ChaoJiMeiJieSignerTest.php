<?php

namespace Tests\Unit;

use App\Services\MediaDistribution\ChaoJiMeiJieSigner;
use PHPUnit\Framework\TestCase;

class ChaoJiMeiJieSignerTest extends TestCase
{
    public function test_it_flattens_nested_payload_using_document_rules(): void
    {
        $signer = new ChaoJiMeiJieSigner();

        $this->assertSame(
            'algorithm=sha256appid=appmeta=x=1z=9tags=abtimestamp=123',
            $signer->flatten([
                'timestamp' => 123,
                'signature' => 'ignored',
                'appid' => 'app',
                'tags' => ['b', 'a'],
                'algorithm' => 'sha256',
                'meta' => ['z' => 9, 'x' => 1],
            ])
        );
    }

    public function test_it_signs_payload_with_hmac_secret(): void
    {
        $signer = new ChaoJiMeiJieSigner();
        $payload = [
            'appid' => 'app-id',
            'timestamp' => 123,
            'algorithm' => 'sha256',
            'other' => 'value',
        ];

        $signed = $signer->sign($payload, 'secret');

        $this->assertSame(hash_hmac('sha256', $signer->flatten($payload), 'secret'), $signed['signature']);
    }
}
