<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BrandDiagnosisBrandMention;
use App\Models\BrandDiagnosisQuestion;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\ProductCase;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class ImportProductCasesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_cases_images_and_generated_diagnosis_data_idempotently(): void
    {
        [$admin, $site] = $this->createSuperAdminWithDefaultSite();
        $source = $this->makeWorkbook();
        Config::set('geoflow.image_host.upload_url', 'https://files.example.com/api/upload');
        Config::set('geoflow.image_host.token', 'secret-token');

        $uploadCount = 0;
        Http::fake(function () use (&$uploadCount) {
            $uploadCount++;

            return Http::response([
                'success' => true,
                'data' => [
                    'key' => 'geo-cases/case-'.$uploadCount.'.png',
                    'url' => 'https://cdn.example.com/geo-cases/case-'.$uploadCount.'.png',
                    'size' => 128,
                    'mimeType' => 'image/png',
                ],
            ]);
        });

        try {
            $this->artisan('geoflow:import-product-cases', ['--source' => $source])
                ->assertExitCode(0);

            $this->assertSame(2, ProductCase::query()->count());
            $this->assertSame(1, ImageLibrary::query()->count());
            $this->assertSame(2, Image::query()->count());
            $this->assertSame(2, BrandDiagnosisRun::query()->count());
            $this->assertGreaterThanOrEqual(12, BrandDiagnosisQuestion::query()->count());
            $this->assertLessThanOrEqual(18, BrandDiagnosisQuestion::query()->count());
            $this->assertSame(
                BrandDiagnosisQuestion::query()->count() * 4,
                BrandDiagnosisResult::query()->count()
            );
            $this->assertSame(0, BrandDiagnosisBrandMention::query()->where('is_target_brand', false)->count());
            $this->assertSame(2, $uploadCount);

            $seededRuns = BrandDiagnosisRun::query()
                ->where('billing_mode', 'product_case_seed')
                ->orderBy('id')
                ->get();
            $this->assertCount(2, $seededRuns);
            $this->assertTrue($seededRuns->every(
                static fn (BrandDiagnosisRun $run): bool => count((array) $run->platforms) === 4
            ));
            $this->assertTrue($seededRuns->every(
                static fn (BrandDiagnosisRun $run): bool => (int) $run->mention_rate < 100
            ));
            $this->assertNotSame(
                $seededRuns[0]->total_questions,
                $seededRuns[1]->total_questions
            );

            $this->assertDatabaseHas('product_cases', [
                'site_id' => $site->id,
                'owner_admin_id' => $admin->id,
                'company_name' => '恒风通风设备',
                'cover_url' => 'https://cdn.example.com/geo-cases/case-1.png',
                'status' => ProductCase::STATUS_PUBLISHED,
            ]);
            $this->assertDatabaseHas('brand_diagnosis_runs', [
                'site_id' => $site->id,
                'owner_admin_id' => $admin->id,
                'brand_name' => '恒风通风设备',
                'billing_mode' => 'product_case_seed',
                'status' => 'completed',
            ]);

            $case = ProductCase::query()->where('company_name', '恒风通风设备')->firstOrFail();
            $this->assertStringNotContainsString('数据说明', (string) $case->content);
            $competitors = BrandDiagnosisBrandMention::query()
                ->where('run_id', BrandDiagnosisRun::query()->where('brand_name', '恒风通风设备')->value('id'))
                ->where('is_target_brand', false)
                ->pluck('brand_name');
            $this->assertCount(0, $competitors);
            $this->get(route('product-cases.show', ['slug' => $case->slug]))
                ->assertOk()
                ->assertSee('恒风通风设备')
                ->assertSee('恒风通风设备的品牌定位')
                ->assertDontSee('竞品表现')
                ->assertDontSee('竞品提及')
                ->assertSee('AI 平台表现');

            $this->artisan('geoflow:import-product-cases', ['--source' => $source])
                ->assertExitCode(0);

            $this->assertSame(2, ProductCase::query()->count());
            $this->assertSame(1, ImageLibrary::query()->count());
            $this->assertSame(2, Image::query()->count());
            $this->assertSame(2, BrandDiagnosisRun::query()->count());
            $this->assertGreaterThanOrEqual(12, BrandDiagnosisQuestion::query()->count());
            $this->assertLessThanOrEqual(18, BrandDiagnosisQuestion::query()->count());
            $this->assertSame(
                BrandDiagnosisQuestion::query()->count() * 4,
                BrandDiagnosisResult::query()->count()
            );
            $this->assertSame(0, BrandDiagnosisBrandMention::query()->where('is_target_brand', false)->count());
            $this->assertSame(2, $uploadCount);
        } finally {
            @unlink($source);
        }
    }

    public function test_it_requires_an_active_super_admin_default_site(): void
    {
        $source = $this->makeWorkbook();

        try {
            $this->artisan('geoflow:import-product-cases', ['--source' => $source])
                ->assertExitCode(1);
        } finally {
            @unlink($source);
        }
    }

    /**
     * @return array{0:Admin,1:Site}
     */
    private function createSuperAdminWithDefaultSite(): array
    {
        $admin = Admin::query()->create([
            'username' => 'case_import_super_admin',
            'password' => 'secret-123',
            'email' => 'case-import@example.com',
            'display_name' => '案例导入超管',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => '案例导入超管 的默认站点',
            'domain' => '',
            'status' => 'active',
        ]);
        $site->members()->attach($admin->id, ['role' => 'owner']);

        return [$admin, $site];
    }

    private function makeWorkbook(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'geo-case-import-');
        $this->assertIsString($path);

        $zip = new ZipArchive;
        $this->assertSame(true, $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('xl/sharedStrings.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="11" uniqueCount="11">
    <si><t>行业类别</t></si><si><t>品牌名称</t></si><si><t>地区</t></si><si><t>案例标题</t></si>
    <si><t>摘要</t></si><si><t>品牌介绍</t></si><si><t>工业制造 / 通风设备</t></si>
    <si><t>恒风通风设备</t></si><si><t>德州</t></si><si><t>恒风通风设备-GEO增长案例</t></si>
    <si><t>恒风通风设备提供工业通风设备与配套解决方案。</t></si>
</sst>
XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>
        <row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c><c r="E1" t="s"><v>4</v></c><c r="F1" t="s"><v>5</v></c></row>
        <row r="2"><c r="A2" t="s"><v>6</v></c><c r="B2" t="s"><v>7</v></c><c r="C2" t="s"><v>8</v></c><c r="D2" t="s"><v>9</v></c><c r="E2" t="s"><v>10</v></c><c r="F2" t="inlineStr"><is><t>恒风通风设备的品牌定位与服务介绍。</t></is></c></row>
        <row r="3"><c r="A3" t="inlineStr"><is><t>家居家装 / 门窗</t></is></c><c r="B3" t="inlineStr"><is><t>森居系统门窗</t></is></c><c r="C3" t="inlineStr"><is><t>杭州</t></is></c><c r="D3" t="inlineStr"><is><t>森居系统门窗-GEO增长案例</t></is></c><c r="E3" t="inlineStr"><is><t>森居系统门窗提供门窗定制服务。</t></is></c><c r="F3" t="inlineStr"><is><t>森居系统门窗的品牌定位与服务介绍。</t></is></c></row>
    </sheetData>
</worksheet>
XML);
        $zip->addFromString('xl/drawings/drawing1.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <xdr:oneCellAnchor><xdr:from><xdr:col>6</xdr:col><xdr:row>1</xdr:row></xdr:from><xdr:pic><xdr:blipFill><a:blip r:embed="rId1"/></xdr:blipFill></xdr:pic></xdr:oneCellAnchor>
    <xdr:oneCellAnchor><xdr:from><xdr:col>6</xdr:col><xdr:row>2</xdr:row></xdr:from><xdr:pic><xdr:blipFill><a:blip r:embed="rId2"/></xdr:blipFill></xdr:pic></xdr:oneCellAnchor>
</xdr:wsDr>
XML);
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image.png"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image2.png"/>
</Relationships>
XML);
        $zip->addFromString('xl/media/image.png', 'case-image-one');
        $zip->addFromString('xl/media/image2.png', 'case-image-two');
        $zip->close();

        return $path;
    }
}
