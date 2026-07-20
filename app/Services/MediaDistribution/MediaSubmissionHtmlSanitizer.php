<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-18
 *
 * @Time: 15:43
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： MediaSubmissionHtmlSanitizer.php
 *
 * @Description: 统一渲染并清洗媒体投稿 HTML，阻断存储型 XSS 与实体重复解码绕过。
 */

namespace App\Services\MediaDistribution;

use App\Support\Site\ArticleHtmlPresenter;
use DOMDocument;
use DOMElement;
use DOMNode;

final class MediaSubmissionHtmlSanitizer
{
    /** @var list<string> */
    private const ALLOWED_ELEMENTS = [
        'a',
        'article',
        'b',
        'blockquote',
        'br',
        'code',
        'del',
        'div',
        'em',
        'figcaption',
        'figure',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'hr',
        'i',
        'img',
        'li',
        'ol',
        'p',
        'pre',
        's',
        'section',
        'span',
        'strong',
        'sub',
        'sup',
        'table',
        'tbody',
        'td',
        'tfoot',
        'th',
        'thead',
        'tr',
        'u',
        'ul',
    ];

    /** @var list<string> */
    private const BLOCKED_ELEMENTS = [
        'base',
        'button',
        'embed',
        'form',
        'frame',
        'frameset',
        'iframe',
        'input',
        'link',
        'math',
        'meta',
        'noscript',
        'object',
        'option',
        'plaintext',
        'script',
        'select',
        'style',
        'svg',
        'template',
        'textarea',
        'xmp',
    ];

    /** @var array<string,list<string>> */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'target', 'title'],
        'div' => ['class'],
        'img' => ['alt', 'decoding', 'height', 'loading', 'src', 'title', 'width'],
        'ol' => ['start'],
        'table' => ['class'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
    ];

    /** @var list<string> */
    private const RENDERED_HTML_ELEMENTS = [
        'a',
        'article',
        'blockquote',
        'code',
        'div',
        'em',
        'figure',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'img',
        'li',
        'ol',
        'p',
        'pre',
        'section',
        'span',
        'strong',
        'table',
        'ul',
    ];

    /**
     * @Name: sanitize
     *
     * @Description: 将投稿正文识别为 HTML、转义 HTML 或 Markdown，并统一执行严格允许列表清洗；已渲染 HTML 不再解码实体，保证重复调用结果安全稳定。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Param: string $content 投稿正文或历史快照
     *
     * @Return: string 清洗后的 UTF-8 HTML
     */
    public function sanitize(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        return $this->sanitizeRenderedHtml($this->renderContentAsHtml($content));
    }

    /**
     * @Name: renderContentAsHtml
     *
     * @Description: 保留现有 HTML，兼容历史转义 HTML 与带 br 的 Markdown；实体最多在进入清洗器前解码一次，任何解码产生的标签仍需经过允许列表。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Param: string $content 待识别正文
     *
     * @Return: string 待清洗 HTML
     */
    private function renderContentAsHtml(string $content): string
    {
        if ($this->looksLikeRenderedHtml($content)) {
            return $content;
        }

        $decoded = html_entity_decode($content, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        if ($this->looksLikeRenderedHtml($decoded)) {
            return $decoded;
        }

        $markdown = preg_replace('/<br\s*\/?>\s*/i', "\n", $decoded) ?? $decoded;

        return ArticleHtmlPresenter::markdownToHtml($markdown);
    }

    /**
     * @Name: looksLikeRenderedHtml
     *
     * @Description: 仅使用允许展示的结构标签识别已渲染 HTML，避免仅含 br 的历史 Markdown 被误判。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Param: string $content 待识别正文
     *
     * @Return: bool 是否包含可展示 HTML 结构
     */
    private function looksLikeRenderedHtml(string $content): bool
    {
        $elements = implode('|', self::RENDERED_HTML_ELEMENTS);

        return preg_match('/<\/?(?:'.$elements.')\b/i', $content) === 1;
    }

    /**
     * @Name: sanitizeRenderedHtml
     *
     * @Description: 使用禁用网络加载的 DOM 解析器规范化畸形标签，并清洗整个文档树后仅输出 body 内容。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Param: string $html 待清洗 HTML
     *
     * @Return: string 严格允许列表 HTML
     */
    private function sanitizeRenderedHtml(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<!doctype html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>',
                LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        if (! $loaded) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return '';
        }

        $this->sanitizeChildren($body);

        $sanitized = '';
        foreach ($body->childNodes as $child) {
            $sanitized .= $document->saveHTML($child);
        }

        return trim($sanitized);
    }

    /**
     * @Name: sanitizeChildren
     *
     * @Description: 递归清洗节点；危险元素连同内容移除，未知元素解除包裹并保留其已清洗子节点，注释与处理指令直接删除。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Param: DOMNode $parent 当前父节点
     *
     * @Return: void
     */
    private function sanitizeChildren(DOMNode $parent): void
    {
        for ($node = $parent->firstChild; $node instanceof DOMNode;) {
            $next = $node->nextSibling;

            if ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                if (in_array($tag, self::BLOCKED_ELEMENTS, true)) {
                    $parent->removeChild($node);
                    $node = $next;

                    continue;
                }

                $this->sanitizeChildren($node);
                if (! in_array($tag, self::ALLOWED_ELEMENTS, true)) {
                    $this->unwrapElement($node);
                } else {
                    $this->sanitizeAttributes($node, $tag);
                }
            } elseif ($node->nodeType !== XML_TEXT_NODE) {
                $parent->removeChild($node);
            }

            $node = $next;
        }
    }

    /**
     * @Name: unwrapElement
     *
     * @Description: 移除非允许元素本身，同时按原顺序保留已完成清洗的文本和安全子元素。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Param: DOMElement $element 待解除包裹元素
     *
     * @Return: void
     */
    private function unwrapElement(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        while ($element->firstChild instanceof DOMNode) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /**
     * @Name: sanitizeAttributes
     *
     * @Description: 按标签属性允许列表删除 on 事件、style 与扩展属性，并对 URL、尺寸、枚举值和已知 class 分别做约束。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Param: DOMElement $element 待清洗元素
     *
     * @Param: string     $tag     小写标签名
     *
     * @Return: void
     */
    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];
        $attributeNames = [];
        foreach ($element->attributes as $attribute) {
            $attributeNames[] = $attribute->name;
        }

        foreach ($attributeNames as $attributeName) {
            $name = strtolower($attributeName);
            $value = trim($element->getAttribute($attributeName));

            if (str_starts_with($name, 'on') || ! in_array($name, $allowed, true)) {
                $element->removeAttribute($attributeName);

                continue;
            }

            if (($name === 'href' || $name === 'src') && ! $this->isSafeUrl($value, $name === 'href')) {
                $element->removeAttribute($attributeName);

                continue;
            }

            if (in_array($name, ['width', 'height', 'colspan', 'rowspan'], true)
                && preg_match('/^[1-9]\d{0,3}$/', $value) !== 1) {
                $element->removeAttribute($attributeName);

                continue;
            }

            if ($name === 'start' && preg_match('/^-?\d{1,6}$/', $value) !== 1) {
                $element->removeAttribute($attributeName);

                continue;
            }

            if ($name === 'loading' && ! in_array(strtolower($value), ['eager', 'lazy'], true)) {
                $element->removeAttribute($attributeName);

                continue;
            }

            if ($name === 'decoding' && ! in_array(strtolower($value), ['async', 'auto', 'sync'], true)) {
                $element->removeAttribute($attributeName);

                continue;
            }

            if ($name === 'scope' && ! in_array(strtolower($value), ['col', 'colgroup', 'row', 'rowgroup'], true)) {
                $element->removeAttribute($attributeName);

                continue;
            }

            if ($name === 'target' && ! in_array(strtolower($value), ['_blank', '_self'], true)) {
                $element->removeAttribute($attributeName);

                continue;
            }

            if ($name === 'class') {
                $this->sanitizeClassAttribute($element, $value);
            }
        }

        if ($tag === 'a' && strtolower($element->getAttribute('target')) === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * @Name: isSafeUrl
     *
     * @Description: 删除控制字符后识别协议，链接仅允许 http、https、mailto、tel，媒体地址仅允许 http、https，站内相对地址可正常抓取。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Param: string $value        URL 属性值
     *
     * @Param: bool   $allowContact 是否允许邮件和电话协议
     *
     * @Return: bool URL 是否安全
     */
    private function isSafeUrl(string $value, bool $allowContact): bool
    {
        if ($value === '') {
            return false;
        }

        $normalized = preg_replace('/[\x00-\x20\x7F]+/u', '', $value) ?? '';
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $normalized, $matches) !== 1) {
            return true;
        }

        $allowedSchemes = $allowContact ? ['http', 'https', 'mailto', 'tel'] : ['http', 'https'];

        return in_array(strtolower((string) $matches[1]), $allowedSchemes, true);
    }

    /**
     * @Name: sanitizeClassAttribute
     *
     * @Description: 仅保留正文渲染器生成的表格布局类，防止外部样式类污染预览文档。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Param: DOMElement $element 待处理元素
     *
     * @Param: string     $value   原 class 属性
     *
     * @Return: void
     */
    private function sanitizeClassAttribute(DOMElement $element, string $value): void
    {
        $classes = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $classes = array_values(array_intersect($classes, ['article-table', 'article-table-wrap']));

        if ($classes === []) {
            $element->removeAttribute('class');

            return;
        }

        $element->setAttribute('class', implode(' ', array_unique($classes)));
    }
}
