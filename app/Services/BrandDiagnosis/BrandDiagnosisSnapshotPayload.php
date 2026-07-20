<?php

namespace App\Services\BrandDiagnosis;

use App\Models\BrandDiagnosisResult;

final class BrandDiagnosisSnapshotPayload
{
    private const INTERNAL_PAYLOAD_KEYS = [
        'brand_mentions',
        'brand_mentions_payload',
        'brands',
        'competitors',
        'competitor_brands',
        'citations',
        'references',
        'sources',
        'source_count',
        'mention_count',
        'mention_rank',
        'sentiment',
        'evidence',
        'meta',
        'metadata',
        'raw_response',
    ];

    /**
     * @return array<string,mixed>
     */
    public function captureIfMissing(BrandDiagnosisResult $result): array
    {
        $existing = (array) ($result->snapshot_payload ?? []);
        if ($existing !== []) {
            return $existing;
        }

        $answer = $this->displayAnswer((string) ($result->answer ?? ''));
        if ((string) $result->status !== 'success' || $answer === '') {
            return [];
        }

        $result->load([
            'run' => fn ($query) => $query
                ->withoutGlobalScopes(['current_site', 'admin_owner'])
                ->select(['id', 'brand_name']),
            'question' => fn ($query) => $query
                ->withoutGlobalScopes(['current_site', 'admin_owner'])
                ->select(['id', 'question']),
            'sources' => fn ($query) => $query
                ->withoutGlobalScopes(['current_site', 'admin_owner'])
                ->select(['id', 'result_id', 'title', 'url', 'domain'])
                ->orderBy('id'),
        ]);

        $payload = [
            'version' => 1,
            'brand' => (string) ($result->run?->brand_name ?? ''),
            'question' => (string) ($result->question?->question ?? ''),
            'answer' => $answer,
            'platform' => (string) $result->platform,
            'status' => (string) $result->status,
            'checked_at' => $result->checked_at?->format('Y-m-d H:i:s') ?? '',
            'sources' => $result->sources
                ->filter(fn ($source): bool => $this->isHttpUrl((string) $source->url))
                ->map(static fn ($source): array => [
                    'title' => (string) ($source->title ?: $source->url),
                    'url' => (string) $source->url,
                    'domain' => (string) $source->domain,
                ])
                ->values()
                ->all(),
        ];

        $result->forceFill(['snapshot_payload' => $payload])->save();

        return $payload;
    }

    public function displayAnswer(string $answer): string
    {
        $answer = trim($answer);
        if ($answer === '') {
            return '';
        }

        $json = $this->jsonFromMarkdownFence($answer);

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $decodedAnswer = trim((string) ($decoded['answer'] ?? data_get($decoded, 'content', '')));
            if ($decodedAnswer !== '') {
                return $this->normalizeDisplayAnswer($decodedAnswer);
            }
        }

        $looseAnswer = $this->extractLooseJsonAnswer($json);
        if ($looseAnswer !== '') {
            return $this->normalizeDisplayAnswer($looseAnswer);
        }

        return $this->normalizeDisplayAnswer($this->stripInternalStructuredPayload($answer));
    }

    public function isHttpUrl(string $url): bool
    {
        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array($scheme, ['http', 'https'], true);
    }

    private function jsonFromMarkdownFence(string $text): string
    {
        if (preg_match('/^```(?:json)?\s*(.*?)```\s*$/is', trim($text), $matches) !== 1) {
            return trim($text);
        }

        return trim((string) $matches[1]);
    }

    private function extractLooseJsonAnswer(string $text): string
    {
        $json = trim($text);
        if (! str_starts_with($json, '{') || mb_stripos($json, '"answer"', 0, 'UTF-8') === false) {
            return '';
        }

        if (preg_match('/"answer"\s*:\s*"/u', $json, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        $start = (int) $matches[0][1] + strlen((string) $matches[0][0]);
        $markers = collect(self::INTERNAL_PAYLOAD_KEYS)
            ->flatMap(static fn (string $key): array => [
                '","'.$key.'"',
                '" , "'.$key.'"',
            ])
            ->all();
        $end = false;
        foreach ($markers as $marker) {
            $position = strpos($json, $marker, $start);
            if ($position !== false && ($end === false || $position < $end)) {
                $end = $position;
            }
        }

        if ($end === false) {
            $end = strrpos($json, '"');
        }
        if ($end === false || $end <= $start) {
            return '';
        }

        return $this->unescapeJsonStringFragment(substr($json, $start, $end - $start));
    }

    private function stripInternalStructuredPayload(string $answer): string
    {
        $markerStart = $this->firstInternalPayloadFieldOffset($answer);
        if ($markerStart === null) {
            return $answer;
        }

        $cleaned = substr($answer, 0, $markerStart);
        $cleaned = trim($cleaned);
        $cleaned = preg_replace('/[,\s]*["\']?[,\s]*$/u', '', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned, " \t\n\r\0\x0B,{}");
        $cleaned = preg_replace('/^"answer"\s*:\s*"/u', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/^answer"\s*:\s*"/u', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/^"|"$/u', '', $cleaned) ?? $cleaned;

        return $this->unescapeJsonStringFragment($cleaned);
    }

    private function firstInternalPayloadFieldOffset(string $answer): ?int
    {
        $keys = implode('|', array_map(static fn (string $key): string => preg_quote($key, '/'), self::INTERNAL_PAYLOAD_KEYS));
        $pattern = '/"('.$keys.')"\s*:\s*(?:\[|\{|"|-?\d|true|false|null)/iu';
        if (preg_match_all($pattern, $answer, $matches, PREG_OFFSET_CAPTURE) <= 0) {
            return null;
        }

        $offsets = collect($matches[0])
            ->map(static fn (array $match): int => (int) $match[1])
            ->filter(fn (int $offset): bool => $this->looksLikeStructuredPayloadBoundary($answer, $offset))
            ->values();

        return $offsets->isEmpty() ? null : (int) $offsets->min();
    }

    private function looksLikeStructuredPayloadBoundary(string $answer, int $offset): bool
    {
        $prefix = substr($answer, 0, $offset);
        $trimmedPrefix = rtrim($prefix);
        if ($trimmedPrefix === '') {
            return true;
        }

        $previous = substr($trimmedPrefix, -1);

        return in_array($previous, [',', '{', '['], true)
            || str_ends_with($trimmedPrefix, '",')
            || str_ends_with($trimmedPrefix, '。",')
            || str_ends_with($trimmedPrefix, '！",')
            || str_ends_with($trimmedPrefix, '？",')
            || str_ends_with($trimmedPrefix, '.',)
            || str_ends_with($trimmedPrefix, '."',);
    }

    private function extractJsonArrayAt(string $text, int $start): string
    {
        $length = strlen($text);
        if ($start < 0 || $start >= $length || $text[$start] !== '[') {
            return '';
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        for ($index = $start; $index < $length; $index++) {
            $char = $text[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $index - $start + 1);
                }
            }
        }

        return '';
    }

    private function unescapeJsonStringFragment(string $text): string
    {
        return str_replace(
            ['\\n', '\\r', '\\t', '\\"', '\\/'],
            ["\n", "\r", "\t", '"', '/'],
            $text
        );
    }

    private function normalizeDisplayAnswer(string $answer): string
    {
        $answer = str_replace(["\r\n", "\r"], "\n", $answer);
        $answer = preg_replace("/\n{3,}/", "\n\n", $answer) ?? $answer;

        return trim($answer);
    }
}
