<?php

namespace Tests\Unit;

use App\Services\MediaDistribution\MediaDistributionClient;
use App\Services\MediaDistribution\MediaDistributionException;
use App\Support\GeoFlow\ApiKeyCrypto;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class MediaDistributionClientTest extends TestCase
{
    public function test_it_decodes_double_encoded_json_response_body(): void
    {
        $client = new MediaDistributionClient($this->createStub(ApiKeyCrypto::class));
        $method = (new ReflectionClass($client))->getMethod('decodeResponseBody');

        $response = $method->invoke($client, json_encode(json_encode([
            'code' => 1,
            'msg' => 'success',
            'data' => ['order_nid' => 'double-json-order'],
        ])));

        $this->assertSame('double-json-order', $response['data']['order_nid']);
    }

    public function test_it_summarizes_html_error_response_without_markup(): void
    {
        $client = new MediaDistributionClient($this->createStub(ApiKeyCrypto::class));
        $method = (new ReflectionClass($client))->getMethod('decodeResponseBody');

        $this->expectException(MediaDistributionException::class);
        $this->expectExceptionMessage('宝塔WAF提醒您,from-data 请求异常');
        $this->expectExceptionMessageMatches('/^(?!.*<html>).*$/s');

        $method->invoke($client, '<html><title>from-data请求error</title><div>宝塔WAF提醒您,from-data 请求异常</div></html>');
    }
}
