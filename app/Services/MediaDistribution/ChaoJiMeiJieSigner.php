<?php

namespace App\Services\MediaDistribution;

class ChaoJiMeiJieSigner
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function sign(array $payload, string $secret): array
    {
        $algorithm = (string) ($payload['algorithm'] ?? 'sha256');
        $payload['signature'] = hash_hmac($algorithm, $this->flatten($payload), $secret);

        return $payload;
    }

    /**
     * @param  array<mixed>  $data
     */
    public function flatten(array $data, string $separator = ''): string
    {
        $segments = [];

        if (array_is_list($data)) {
            sort($data);
            foreach ($data as $item) {
                $segments[] = is_array($item) ? $this->flatten($item, $separator) : (string) $item;
            }

            return implode($separator, $segments);
        }

        ksort($data);
        foreach ($data as $key => $item) {
            if ((string) $key === 'signature') {
                continue;
            }
            $value = is_array($item) ? $this->flatten($item, $separator) : (string) $item;
            $segments[] = sprintf('%s=%s', (string) $key, $value);
        }

        return implode($separator, $segments);
    }
}
