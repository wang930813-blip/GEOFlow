<?php

namespace App\Services\GeoFlow;

class AiArticleImagePlanner
{
    /**
     * @param  list<array<string,mixed>>  $blocks
     */
    public function insertGeneratedImages(string $content, array $blocks): string
    {
        $trimmed = trim($content);
        if ($trimmed === '' || $blocks === []) {
            return $content;
        }

        $paragraphs = preg_split("/\n{2,}/u", $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $paragraphCount = count($paragraphs);
        if ($paragraphCount <= 0) {
            return $content;
        }

        $grouped = [];
        foreach ($blocks as $block) {
            $url = trim((string) ($block['url'] ?? ''));
            if (! preg_match('#^https?://#i', $url)) {
                continue;
            }

            $position = $this->paragraphIndexForPosition($paragraphs, (int) ($block['paragraph_after'] ?? $paragraphCount));
            $grouped[$position][] = '!['.$this->sanitizeAlt((string) ($block['alt'] ?? '')).']('.$url.')';
        }

        if ($grouped === []) {
            return $content;
        }

        $parts = [];
        foreach ($paragraphs as $index => $paragraph) {
            $paragraphPosition = $index + 1;
            $parts[] = trim((string) $paragraph);

            foreach ($grouped[$paragraphPosition] ?? [] as $markdown) {
                $parts[] = $markdown;
            }
        }

        return implode("\n\n", array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    public function fallbackPlan(string $content, int $count, string $title, string $keyword): array
    {
        $paragraphs = preg_split("/\n{2,}/u", trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $paragraphCount = max(1, count($paragraphs));
        $count = max(0, min(5, $count));
        $blocks = [];

        for ($i = 1; $i <= $count; $i++) {
            $position = min($paragraphCount, max(1, (int) floor(($paragraphCount * $i) / ($count + 1))));
            $subject = trim($keyword) !== '' ? $keyword : $title;
            $blocks[] = [
                'paragraph_after' => $position,
                'alt' => $subject,
                'prompt' => 'Editorial web article image, clean realistic composition, no text, topic: '.$subject.', scene '.$i.' for article: '.$title,
            ];
        }

        return $blocks;
    }

    private function sanitizeAlt(string $alt): string
    {
        $alt = preg_replace('/[\[\]\(\)]/u', ' ', $alt) ?: '';
        $alt = trim(preg_replace('/\s+/u', ' ', $alt) ?: $alt);

        return $alt !== '' ? $alt : 'AI generated image';
    }

    /**
     * @param  list<string>  $paragraphs
     */
    private function paragraphIndexForPosition(array $paragraphs, int $requestedBodyPosition): int
    {
        $bodyIndexes = [];
        foreach ($paragraphs as $index => $paragraph) {
            $text = trim((string) $paragraph);
            if (preg_match('/^#{1,6}\s+\S/u', $text) === 1) {
                continue;
            }
            $bodyIndexes[] = $index + 1;
        }

        if ($bodyIndexes === []) {
            return max(1, min(count($paragraphs), $requestedBodyPosition));
        }

        $requestedBodyPosition = max(1, min(count($bodyIndexes), $requestedBodyPosition));

        return $bodyIndexes[$requestedBodyPosition - 1];
    }
}
