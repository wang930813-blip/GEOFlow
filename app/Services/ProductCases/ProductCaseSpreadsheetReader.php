<?php

namespace App\Services\ProductCases;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class ProductCaseSpreadsheetReader
{
    private const MAX_ARCHIVE_ENTRIES = 2000;

    private const MAX_UNCOMPRESSED_BYTES = 209715200;

    private const MAX_ROWS = 10000;

    private const MAX_IMAGE_BYTES = 10485760;

    /**
     * @return list<array{
     *     row_number:int,
     *     industry:string,
     *     brand_name:string,
     *     region:string,
     *     title:string,
     *     summary:string,
     *     brand_introduction:string,
     *     image_binary:?string,
     *     image_mime_type:string,
     *     image_filename:string
     * }>
     */
    public function read(string $path): array
    {
        $path = trim($path);
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('案例库文件不存在: '.$path);
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('无法打开案例库文件: '.$path);
        }

        try {
            $this->validateArchive($zip);
            $sharedStrings = $this->sharedStrings($zip);
            $sheetRows = $this->sheetRows($zip, $sharedStrings);
            $images = $this->drawingImages($zip);
        } finally {
            $zip->close();
        }

        if ($sheetRows === []) {
            throw new RuntimeException('案例库文件没有可读取的工作表数据');
        }
        if (count($sheetRows) > self::MAX_ROWS + 1) {
            throw new RuntimeException('案例库文件行数超过限制，单次最多导入 '.self::MAX_ROWS.' 行');
        }

        $header = $sheetRows[0]['cells'];
        $columns = $this->columnMap($header);
        $cases = [];

        foreach (array_slice($sheetRows, 1) as $sheetRow) {
            $cells = $sheetRow['cells'];
            $brandName = $this->cell($cells, $columns['brand_name']);
            $title = $this->cell($cells, $columns['title']);

            if ($brandName === '' && $title === '') {
                continue;
            }

            if ($brandName === '' || $title === '') {
                throw new RuntimeException('第 '.$sheetRow['row_number'].' 行缺少品牌名称或案例标题');
            }

            $image = $images[$sheetRow['row_number']] ?? null;
            $cases[] = [
                'row_number' => $sheetRow['row_number'],
                'industry' => $this->cell($cells, $columns['industry']),
                'brand_name' => $brandName,
                'region' => $this->cell($cells, $columns['region']),
                'title' => $title,
                'summary' => $this->cell($cells, $columns['summary']),
                'brand_introduction' => $this->cell($cells, $columns['brand_introduction']),
                'image_binary' => is_array($image) ? $image['binary'] : null,
                'image_mime_type' => is_array($image) ? $image['mime_type'] : '',
                'image_filename' => is_array($image) ? $image['filename'] : '',
            ];
        }

        return $cases;
    }

    /**
     * @param  array<int,string>  $header
     * @return array{industry:int,brand_name:int,region:int,title:int,summary:int,brand_introduction:int}
     */
    private function columnMap(array $header): array
    {
        $normalized = [];
        foreach ($header as $column => $value) {
            $normalized[$column] = $this->normalizeText($value);
        }

        return [
            'industry' => $this->findColumn($normalized, ['行业类别', '行业分类', '行业'], 1),
            'brand_name' => $this->findColumn($normalized, ['品牌名称', '品牌'], 2),
            'region' => $this->findColumn($normalized, ['地区', '区域'], 3),
            'title' => $this->findColumn($normalized, ['案例标题', '标题'], 4),
            'summary' => $this->findColumn($normalized, ['摘要', '案例摘要'], 5),
            'brand_introduction' => $this->findColumn($normalized, ['品牌介绍', '品牌简介'], 6),
        ];
    }

    /**
     * @param  array<int,string>  $header
     * @param  list<string>  $names
     */
    private function findColumn(array $header, array $names, int $fallback): int
    {
        foreach ($header as $column => $value) {
            if (in_array($value, $names, true)) {
                return (int) $column;
            }
        }

        return $fallback;
    }

    /**
     * @param  array<int,string>  $cells
     */
    private function cell(array $cells, int $column): string
    {
        return $this->normalizeText((string) ($cells[$column] ?? ''));
    }

    /**
     * @return array<int,string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xmlText = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xmlText) || trim($xmlText) === '') {
            return [];
        }

        $xml = $this->xml($xmlText, 'shared strings');
        $values = [];
        $items = $xml->xpath('//*[local-name()="si"]') ?: [];
        foreach ($items as $item) {
            $texts = $item->xpath('.//*[local-name()="t"]') ?: [];
            $value = $texts === []
                ? (string) $item
                : implode('', array_map(static fn (SimpleXMLElement $text): string => (string) $text, $texts));
            $values[] = $this->normalizeText($value);
        }

        return $values;
    }

    /**
     * @param  array<int,string>  $sharedStrings
     * @return list<array{row_number:int,cells:array<int,string>}>
     */
    private function sheetRows(ZipArchive $zip, array $sharedStrings): array
    {
        $xmlText = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (! is_string($xmlText) || trim($xmlText) === '') {
            throw new RuntimeException('案例库文件缺少第一个工作表');
        }

        $xml = $this->xml($xmlText, 'worksheet');
        $rows = [];

        $rowNodes = $xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];
        foreach ($rowNodes as $row) {
            $rowNumber = (int) ($row['r'] ?? 0);
            if ($rowNumber <= 0) {
                $rowNumber = count($rows) + 1;
            }

            $cells = [];
            $cellNodes = $row->xpath('./*[local-name()="c"]') ?: [];
            foreach ($cellNodes as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $columnLetters = preg_replace('/[0-9]+$/', '', $reference) ?: '';
                $column = $this->columnNumber($columnLetters);
                if ($column <= 0) {
                    continue;
                }

                $type = (string) ($cell['t'] ?? '');
                $valueNodes = $cell->xpath('./*[local-name()="v"]') ?: [];
                $rawValue = $valueNodes !== [] ? (string) $valueNodes[0] : '';
                $value = match ($type) {
                    's' => $sharedStrings[(int) $rawValue] ?? '',
                    'inlineStr' => $this->inlineString($cell),
                    default => $rawValue,
                };
                $cells[$column] = $this->normalizeText($value);
            }

            $rows[] = [
                'row_number' => $rowNumber,
                'cells' => $cells,
            ];
        }

        return $rows;
    }

    private function inlineString(SimpleXMLElement $cell): string
    {
        $texts = $cell->xpath('.//*[local-name()="t"]') ?: [];

        return $texts === []
            ? (string) ($cell->xpath('.//*[local-name()="is"]')[0] ?? '')
            : implode('', array_map(static fn (SimpleXMLElement $text): string => (string) $text, $texts));
    }

    /**
     * @return array<int,array{binary:string,mime_type:string,filename:string}>
     */
    private function drawingImages(ZipArchive $zip): array
    {
        $drawingName = $this->drawingName($zip);
        if ($drawingName === null) {
            return [];
        }

        $drawingXmlText = $zip->getFromName($drawingName);
        if (! is_string($drawingXmlText) || trim($drawingXmlText) === '') {
            return [];
        }

        $drawingRelsName = dirname($drawingName).'/_rels/'.basename($drawingName).'.rels';
        $drawingRelsText = $zip->getFromName($drawingRelsName);
        if (! is_string($drawingRelsText) || trim($drawingRelsText) === '') {
            return [];
        }

        $drawing = $this->xml($drawingXmlText, 'drawing');
        $relationships = $this->xml($drawingRelsText, 'drawing relationships');
        $relationshipMap = [];
        $relationshipNodes = $relationships->xpath('//*[local-name()="Relationship"]') ?: [];
        foreach ($relationshipNodes as $relationship) {
            $id = trim((string) ($relationship['Id'] ?? ''));
            $target = trim((string) ($relationship['Target'] ?? ''));
            if ($id !== '' && $target !== '') {
                $relationshipMap[$id] = $this->relationshipTargetPath($drawingName, $target);
            }
        }

        $images = [];
        $anchors = $drawing->xpath('//*[local-name()="oneCellAnchor" or local-name()="twoCellAnchor"]') ?: [];
        foreach ($anchors as $anchor) {
            $rowNodes = $anchor->xpath('./*[local-name()="from"]/*[local-name()="row"]') ?: [];
            $row = $rowNodes !== [] ? (int) $rowNodes[0] + 1 : 0;
            if ($row <= 0) {
                continue;
            }

            $blips = $anchor->xpath('.//*[local-name()="blip"]') ?: [];
            if ($blips === []) {
                continue;
            }

            $attributes = $blips[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relationshipId = trim((string) ($attributes['embed'] ?? ''));
            $target = $relationshipMap[$relationshipId] ?? null;
            if (! is_string($target)) {
                continue;
            }

            $binary = $zip->getFromName($target);
            if (! is_string($binary) || $binary === '') {
                continue;
            }
            if (strlen($binary) > self::MAX_IMAGE_BYTES) {
                throw new RuntimeException('案例库图片过大，单张图片不能超过 10MB');
            }

            $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            $images[$row] = [
                'binary' => $binary,
                'mime_type' => $this->mimeType($extension),
                'filename' => basename($target),
            ];
        }

        return $images;
    }

    private function drawingName(ZipArchive $zip): ?string
    {
        $relsText = $zip->getFromName('xl/worksheets/_rels/sheet1.xml.rels');
        if (is_string($relsText) && trim($relsText) !== '') {
            $rels = $this->xml($relsText, 'worksheet relationships');
            foreach ($rels->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $relationship) {
                $type = (string) ($relationship['Type'] ?? '');
                if (! str_ends_with($type, '/drawing')) {
                    continue;
                }

                $target = trim((string) ($relationship['Target'] ?? ''));
                if ($target !== '') {
                    return $this->normalizeZipPath('xl/worksheets/'.$target);
                }
            }
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (is_string($name) && preg_match('#^xl/drawings/[^/]+\.xml$#', $name) === 1) {
                return $name;
            }
        }

        return null;
    }

    private function normalizeZipPath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /**
     * 校验 XLSX 压缩包条目，允许合法目录项但拒绝绝对路径和路径穿越。
     *
     * @param  ZipArchive  $zip
     * @return void
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 19:48:31
     * @UpdateTime: 2026-09-21 19:48:31
     *
     * @Throws RuntimeException 压缩包包含异常条目或解压内容超过限制
     */
    private function validateArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles <= 0 || $zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw new RuntimeException('案例库文件内容数量异常');
        }

        $totalSize = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (! is_string($name) || trim($name) === '') {
                throw new RuntimeException('案例库文件包含异常条目');
            }

            $zipPath = str_replace('\\', '/', $name);
            $normalized = $this->normalizeZipPath($zipPath);
            $canonicalPath = rtrim($zipPath, '/');
            if ($canonicalPath === ''
                || $normalized !== $canonicalPath
                || str_starts_with($zipPath, '/')
            ) {
                throw new RuntimeException('案例库文件包含不安全路径');
            }

            $stat = $zip->statIndex($index);
            if (! is_array($stat)) {
                throw new RuntimeException('案例库文件条目信息异常');
            }

            $totalSize += (int) ($stat['size'] ?? 0);
            if ($totalSize > self::MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('案例库文件解压后内容过大');
            }
        }
    }

    private function relationshipTargetPath(string $sourceName, string $target): string
    {
        $target = str_replace('\\', '/', trim($target));

        if (str_starts_with($target, '/')) {
            return $this->normalizeZipPath(ltrim($target, '/'));
        }

        return $this->normalizeZipPath(dirname($sourceName).'/'.$target);
    }

    private function columnNumber(string $letters): int
    {
        $letters = strtoupper(trim($letters));
        $number = 0;
        for ($index = 0, $length = strlen($letters); $index < $length; $index++) {
            $value = ord($letters[$index]) - 64;
            if ($value < 1 || $value > 26) {
                return 0;
            }
            $number = ($number * 26) + $value;
        }

        return $number;
    }

    private function mimeType(string $extension): string
    {
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
    }

    private function xml(string $contents, string $label): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException('无法解析案例库'.$label);
        }

        return $xml;
    }

    private function normalizeText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", trim($value));

        return preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
    }
}
