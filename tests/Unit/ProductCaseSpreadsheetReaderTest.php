<?php

namespace Tests\Unit;

use App\Services\ProductCases\ProductCaseSpreadsheetReader;
use Tests\TestCase;
use ZipArchive;

class ProductCaseSpreadsheetReaderTest extends TestCase
{
    public function test_it_reads_case_rows_and_maps_embedded_images_to_data_rows(): void
    {
        $path = $this->makeWorkbook();

        try {
            $rows = app(ProductCaseSpreadsheetReader::class)->read($path);
        } finally {
            @unlink($path);
        }

        $this->assertCount(2, $rows);
        $this->assertSame('工业制造 / 通风设备', $rows[0]['industry']);
        $this->assertSame('恒风通风设备', $rows[0]['brand_name']);
        $this->assertSame('德州', $rows[0]['region']);
        $this->assertSame('恒风通风设备-GEO增长案例', $rows[0]['title']);
        $this->assertSame('品牌介绍一', $rows[0]['brand_introduction']);
        $this->assertSame('image/png', $rows[0]['image_mime_type']);
        $this->assertSame('first-image', $rows[0]['image_binary']);

        $this->assertSame('家居家装 / 门窗', $rows[1]['industry']);
        $this->assertSame('森居系统门窗', $rows[1]['brand_name']);
        $this->assertSame('second-image', $rows[1]['image_binary']);
    }

    private function makeWorkbook(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'geo-case-reader-');
        $this->assertIsString($path);

        $zip = new ZipArchive;
        $this->assertSame(true, $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $zip->addFromString('xl/sharedStrings.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="12" uniqueCount="12">
    <si><t>行业类别</t></si>
    <si><t>品牌名称</t></si>
    <si><t>地区</t></si>
    <si><t>案例标题</t></si>
    <si><t>摘要</t></si>
    <si><t>品牌介绍</t></si>
    <si><t>工业制造 / 通风设备</t></si>
    <si><t>恒风通风设备</t></si>
    <si><t>德州</t></si>
    <si><t>恒风通风设备-GEO增长案例</t></si>
    <si><t>品牌介绍一</t></si>
    <si><t>家居家装 / 门窗</t></si>
</sst>
XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>
        <row r="1">
            <c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c>
            <c r="D1" t="s"><v>3</v></c><c r="E1" t="s"><v>4</v></c><c r="F1" t="s"><v>5</v></c>
        </row>
        <row r="2">
            <c r="A2" t="s"><v>6</v></c><c r="B2" t="s"><v>7</v></c><c r="C2" t="s"><v>8</v></c>
            <c r="D2" t="s"><v>9</v></c><c r="E2" t="inlineStr"><is><t>摘要一</t></is></c><c r="F2" t="s"><v>10</v></c>
        </row>
        <row r="3">
            <c r="A3" t="s"><v>11</v></c><c r="B3" t="inlineStr"><is><t>森居系统门窗</t></is></c>
            <c r="C3" t="inlineStr"><is><t>杭州</t></is></c><c r="D3" t="inlineStr"><is><t>案例二</t></is></c>
            <c r="E3" t="inlineStr"><is><t>摘要二</t></is></c><c r="F3" t="inlineStr"><is><t>品牌介绍二</t></is></c>
        </row>
    </sheetData>
</worksheet>
XML);
        $zip->addFromString('xl/drawings/drawing1.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"
    xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
    xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <xdr:oneCellAnchor><xdr:from><xdr:col>6</xdr:col><xdr:row>1</xdr:row></xdr:from>
        <xdr:pic><xdr:blipFill><a:blip r:embed="rId1"/></xdr:blipFill></xdr:pic>
    </xdr:oneCellAnchor>
    <xdr:oneCellAnchor><xdr:from><xdr:col>6</xdr:col><xdr:row>2</xdr:row></xdr:from>
        <xdr:pic><xdr:blipFill><a:blip r:embed="rId2"/></xdr:blipFill></xdr:pic>
    </xdr:oneCellAnchor>
</xdr:wsDr>
XML);
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image.png"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image2.png"/>
</Relationships>
XML);
        $zip->addFromString('xl/media/image.png', 'first-image');
        $zip->addFromString('xl/media/image2.png', 'second-image');
        $zip->close();

        return $path;
    }
}
