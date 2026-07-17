<?php

namespace App\Support\Site;

use App\Models\Article;
use App\Support\GeoFlow\ImageUrlNormalizer;
use DOMDocument;
use DOMElement;
use DOMNode;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * 文章正文 Markdown 渲染与摘要生成（对齐旧版前台展示习惯）。
 */
final class ArticleHtmlPresenter
{
    /**
     * 将 Markdown 转为 HTML（剥离不安全 HTML 输入）。
     */
    public static function markdownToHtml(string $markdown): string
    {
        $markdown = self::normalizeMarkdownSyntax(trim($markdown));
        $markdown = self::normalizeMarkdownImages($markdown);
        if ($markdown === '') {
            return '';
        }

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return self::decorateRenderedHtml($converter->convert($markdown)->getContent());
    }

    public static function normalizeMarkdownSyntax(string $markdown): string
    {
        if ($markdown === '') {
            return '';
        }

        $parts = preg_split('/(\r\n|\n|\r)/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (! is_array($parts)) {
            return $markdown;
        }

        $fenceCharacter = null;
        $fenceLength = 0;

        foreach ($parts as $index => $part) {
            if ($part === "\r\n" || $part === "\n" || $part === "\r") {
                continue;
            }

            if (preg_match('/^[ ]{0,3}(`{3,}|~{3,})(.*)$/u', $part, $matches) === 1) {
                $marker = (string) $matches[1];
                $markerCharacter = $marker[0];

                if ($fenceCharacter === null) {
                    $fenceCharacter = $markerCharacter;
                    $fenceLength = strlen($marker);
                } elseif (
                    $markerCharacter === $fenceCharacter
                    && strlen($marker) >= $fenceLength
                    && trim((string) ($matches[2] ?? '')) === ''
                ) {
                    $fenceCharacter = null;
                    $fenceLength = 0;
                }

                continue;
            }

            if ($fenceCharacter !== null) {
                continue;
            }

            $part = preg_replace('/^([ ]{0,3})(#{1,6})(?=[^#\s])/u', '$1$2 ', $part) ?? $part;
            $parts[$index] = preg_replace_callback(
                '/\*\*((?:(?!\*\*).)+?)\*\*(?<next>[\p{L}\p{N}])?/u',
                static function (array $matches): string {
                    $content = trim((string) $matches[1], " \t");
                    if ($content === '') {
                        return (string) $matches[0];
                    }

                    $next = (string) ($matches['next'] ?? '');

                    return '**'.$content.'**'.($next !== '' ? ' '.$next : '');
                },
                $part
            ) ?? $part;
        }

        return implode('', $parts);
    }

    /**
     * 从正文中去掉与标题一致的首行 H1，避免详情页重复大标题。
     */
    public static function stripLeadingTitleHeading(string $content, string $title): string
    {
        $content = (string) $content;
        $title = trim($title);
        if ($title === '') {
            return $content;
        }

        $pattern = '/^\s*#\s*'.preg_quote($title, '/').'\s*(?:\r?\n)+/u';

        return (string) preg_replace($pattern, '', $content, 1);
    }

    public static function excerptFromMarkdown(string $markdown, int $limit = 180): string
    {
        $html = self::markdownToHtml($markdown);
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="article-excerpt-root">'.$html.'</div></body></html>',
                LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        if (! $loaded) {
            return '';
        }

        $root = $document->getElementById('article-excerpt-root');
        if (! $root instanceof DOMElement) {
            return '';
        }

        foreach ($root->childNodes as $node) {
            if (! $node instanceof DOMElement || ! self::isSummaryHeading($node)) {
                continue;
            }

            $summary = self::sectionTextAfterHeading($node);
            if ($summary !== '') {
                return self::limitPlainText($summary, $limit);
            }
        }

        foreach ($root->childNodes as $node) {
            if (! $node instanceof DOMElement || ! self::isBodyTextElement($node)) {
                continue;
            }

            $plain = self::nodePlainText($node);
            if ($plain !== '') {
                return self::limitPlainText($plain, $limit);
            }
        }

        return '';
    }

    /**
     * 列表卡片摘要：优先 excerpt，否则从正文抽纯文本片段。
     */
    public static function cardSummary(Article $article, int $limit = 120): string
    {
        $excerpt = trim((string) $article->excerpt);
        if ($excerpt !== '') {
            $excerpt = self::stripLeadingTitleHeading($excerpt, (string) $article->title);
            $excerpt = preg_replace('/!\[[^\]]*\]\([^)]+\)/u', '', $excerpt) ?? $excerpt;
            $plain = self::toPlainLine($excerpt);

            return mb_strlen($plain) > $limit ? mb_substr($plain, 0, $limit).'…' : $plain;
        }

        $body = self::stripLeadingTitleHeading((string) $article->content, (string) $article->title);
        $body = preg_replace('/!\[[^\]]*\]\([^)]+\)/u', '', $body) ?? $body;
        $plain = self::toPlainLine($body);

        return mb_strlen($plain) > $limit ? mb_substr($plain, 0, $limit).'…' : $plain;
    }

    private static function toPlainLine(string $text): string
    {
        $text = preg_replace('/[#*_`>\[\]()]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private static function isSummaryHeading(DOMElement $element): bool
    {
        if (preg_match('/^h[1-6]$/i', $element->tagName) !== 1) {
            return false;
        }

        $heading = preg_replace('/[\s:：\-—]+/u', '', self::nodePlainText($element)) ?? '';

        return in_array($heading, ['核心摘要', '文章摘要', '内容摘要', '摘要'], true);
    }

    private static function sectionTextAfterHeading(DOMElement $heading): string
    {
        $parts = [];
        for ($node = $heading->nextSibling; $node instanceof DOMNode; $node = $node->nextSibling) {
            if ($node instanceof DOMElement && preg_match('/^h[1-6]$/i', $node->tagName) === 1) {
                break;
            }
            if (! $node instanceof DOMElement || ! self::isBodyTextElement($node)) {
                continue;
            }

            $plain = self::nodePlainText($node);
            if ($plain !== '') {
                $parts[] = $plain;
            }
        }

        return trim(implode(' ', $parts));
    }

    private static function isBodyTextElement(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), ['p', 'blockquote', 'ul', 'ol', 'table'], true);
    }

    private static function nodePlainText(DOMNode $node): string
    {
        $text = html_entity_decode((string) $node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private static function limitPlainText(string $text, int $limit): string
    {
        $text = trim($text);
        if ($limit <= 0) {
            return $text;
        }

        $limit = max(1, $limit);
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        $candidate = mb_substr($text, 0, $limit, 'UTF-8');
        $sentence = self::truncateAtSentenceBoundary($candidate, $limit);
        if ($sentence !== '') {
            return $sentence;
        }

        return rtrim($candidate, " \t\n\r\0\x0B，,、：:；;（(");
    }

    private static function truncateAtSentenceBoundary(string $text, int $limit): string
    {
        $bestBoundary = 0;
        $length = mb_strlen($text, 'UTF-8');
        for ($index = 0; $index < $length; $index++) {
            $char = mb_substr($text, $index, 1, 'UTF-8');
            if (preg_match('/[。！？!?；;]/u', $char) === 1) {
                $bestBoundary = $index + 1;
            }
        }

        $minimumBoundary = min($length, max(40, (int) floor($limit * 0.35)));
        if ($bestBoundary < $minimumBoundary) {
            return '';
        }

        return trim(mb_substr($text, 0, $bestBoundary, 'UTF-8'));
    }

    private static function normalizeMarkdownImages(string $markdown): string
    {
        return preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)(?:\s+(".*?"|\'.*?\'))?\)/u',
            static function (array $matches): string {
                $alt = ImageUrlNormalizer::readableAlt((string) ($matches[1] ?? ''));
                $url = ImageUrlNormalizer::toPublicUrl((string) ($matches[2] ?? ''));
                $title = trim((string) ($matches[3] ?? ''));

                return '!['.$alt.']('.$url.($title !== '' ? ' '.$title : '').')';
            },
            $markdown
        ) ?? $markdown;
    }

    private static function decorateRenderedHtml(string $html): string
    {
        $html = preg_replace('/<table>/u', '<div class="article-table-wrap"><table class="article-table">', $html) ?? $html;
        $html = preg_replace('/<\/table>/u', '</table></div>', $html) ?? $html;
        $html = preg_replace('/<p>\s*(<img\b[^>]*>)\s*<\/p>/u', '$1', $html) ?? $html;
        $html = preg_replace('/<img\b(?![^>]*\bloading=)/u', '<img loading="lazy"', $html) ?? $html;
        $html = preg_replace('/<img\b(?![^>]*\bdecoding=)/u', '<img decoding="async"', $html) ?? $html;

        return $html;
    }
}
