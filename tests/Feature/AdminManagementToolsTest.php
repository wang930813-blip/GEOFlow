<?php

namespace Tests\Feature;

use App\Jobs\ProcessProductCaseImportJob;
use App\Models\Admin;
use App\Models\ProductCase;
use App\Models\ProductCaseImport;
use App\Models\Site;
use App\Services\ProductCases\ProductCaseImportTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class AdminManagementToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_management_tools(): void
    {
        $this->get(route('admin.management-tools.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_super_admin_can_view_and_manage_cases_owned_by_the_site_owner(): void
    {
        [$owner, $site] = $this->createSiteWithOwner('management_owner', '管理工具站点');
        $operator = $owner;

        $case = ProductCase::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'title' => '待管理案例',
            'slug' => 'management-case',
            'company_name' => '管理案例品牌',
            'industry' => '',
            'region' => '',
            'summary' => '待运营编辑的案例',
            'content' => '案例正文',
            'status' => ProductCase::STATUS_DRAFT,
        ]);

        $task = ProductCaseImport::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'created_by_admin_id' => $operator->id,
            'original_filename' => 'cases.xlsx',
            'stored_path' => 'product-case-imports/cases.xlsx',
            'status' => 'completed',
            'total_rows' => 1,
            'processed_rows' => 1,
        ]);

        $this->actingAs($operator, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.management-tools.index'))
            ->assertOk()
            ->assertSee('cases.xlsx');

        $this->actingAs($operator, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.management-tools.product-cases.index'))
            ->assertOk()
            ->assertSee('待管理案例');

        $this->actingAs($operator, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->put(route('admin.management-tools.product-cases.update', ['productCase' => $case->id]), [
                'title' => '已编辑案例',
                'slug' => 'management-case-edited',
                'company_name' => '已编辑品牌',
                'industry' => '',
                'region' => '',
                'summary' => '已编辑摘要',
                'content' => '已编辑正文',
                'customer_level' => '',
                'started_at' => '',
                'status' => ProductCase::STATUS_PUBLISHED,
                'sort_order' => 10,
                'published_at' => '',
            ])
            ->assertRedirect(route('admin.management-tools.product-cases.index'));

        $this->assertDatabaseHas('product_cases', [
            'id' => $case->id,
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'title' => '已编辑案例',
            'status' => ProductCase::STATUS_PUBLISHED,
        ]);

        $this->actingAs($operator, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.management-tools.product-cases.toggle-status', ['productCase' => $case->id]))
            ->assertRedirect(route('admin.management-tools.product-cases.index'));

        $this->assertDatabaseHas('product_cases', [
            'id' => $case->id,
            'status' => ProductCase::STATUS_HIDDEN,
        ]);

        $this->actingAs($operator, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->delete(route('admin.management-tools.product-cases.destroy', ['productCase' => $case->id]))
            ->assertRedirect(route('admin.management-tools.product-cases.index'));

        $this->assertSoftDeleted('product_cases', ['id' => $case->id]);
        $this->assertDatabaseHas('product_case_imports', ['id' => $task->id]);
    }

    public function test_non_super_admin_cannot_open_or_mutate_management_tools(): void
    {
        [$owner, $site] = $this->createSiteWithOwner('management_permission_owner', '权限站点');
        $operator = $this->createAdmin('management_permission_operator', 'direct_admin', $owner);
        $site->members()->attach($operator->id, ['role' => 'member']);
        $case = $this->createCase($site, $owner, '受保护案例', 'protected-management-case');

        $this->actingAs($operator, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.management-tools.index'))
            ->assertForbidden();

        $this->actingAs($operator, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.management-tools.product-case-import.store'))
            ->assertForbidden();

        $this->actingAs($operator, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->put(route('admin.management-tools.product-cases.update', ['productCase' => $case->id]))
            ->assertForbidden();

        $this->actingAs($operator, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.management-tools.product-cases.toggle-status', ['productCase' => $case->id]))
            ->assertForbidden();

        $this->actingAs($operator, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->delete(route('admin.management-tools.product-cases.destroy', ['productCase' => $case->id]))
            ->assertForbidden();
    }

    public function test_super_admin_can_manage_cases_across_sites(): void
    {
        [$owner, $site] = $this->createSiteWithOwner('management_scope_owner', '当前站点');
        $operator = $owner;

        [$otherOwner, $otherSite] = $this->createSiteWithOwner('management_other_owner', '其他站点');
        $visibleCase = $this->createCase($site, $owner, '当前站点案例', 'current-site-management-case');
        $hiddenCase = $this->createCase($otherSite, $otherOwner, '其他站点案例', 'other-site-management-case');

        $visibleTask = $this->createImport($site, $owner, $operator, 'current.xlsx');
        $hiddenTask = $this->createImport($otherSite, $otherOwner, $otherOwner, 'other.xlsx');

        $this->actingAs($operator, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.management-tools.product-cases.index'))
            ->assertOk()
            ->assertSee($visibleCase->title)
            ->assertSee($hiddenCase->title);

        $this->actingAs($operator, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.management-tools.index'))
            ->assertOk()
            ->assertSee($visibleTask->original_filename)
            ->assertSee($hiddenTask->original_filename);

        $this->actingAs($operator, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.management-tools.product-cases.edit', ['productCase' => $hiddenCase->id]))
            ->assertOk();

        $this->actingAs($operator, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.management-tools.product-case-import.show', ['importId' => $hiddenTask->id]))
            ->assertOk();
    }

    public function test_case_upload_creates_a_queued_task_and_dispatches_geoflow_job(): void
    {
        [$owner, $site] = $this->createSiteWithOwner('management_upload_owner', '上传站点');
        $operator = $owner;
        Queue::fake();
        Storage::fake('local');

        $source = $this->makeWorkbook();
        $upload = UploadedFile::fake()->createWithContent(
            'product-cases.xlsx',
            (string) file_get_contents($source)
        );

        try {
            $response = $this->actingAs($operator, 'admin')
                ->withSession(['current_site_id' => $site->id])
                ->post(route('admin.management-tools.product-case-import.store'), [
                    'source' => $upload,
                ]);
            $task = ProductCaseImport::query()->latest('id')->firstOrFail();

            $response
                ->assertRedirect(route('admin.management-tools.product-case-import.show', ['importId' => $task->id]));
            $this->assertSame('queued', $task->status);
            $this->assertSame(0, $task->total_rows);
            $this->assertSame($owner->id, $task->owner_admin_id);
            $this->assertSame($operator->id, $task->created_by_admin_id);
            Queue::assertPushedOn('geoflow', ProcessProductCaseImportJob::class, function (ProcessProductCaseImportJob $job) use ($task): bool {
                return $job->importId === (int) $task->id;
            });
        } finally {
            @unlink($source);
        }
    }

    public function test_same_uploaded_file_reuses_existing_import_task(): void
    {
        [$owner, $site] = $this->createSiteWithOwner('management_duplicate_owner', '重复文件站点');
        Queue::fake();
        Storage::fake('local');

        $source = $this->makeWorkbook();

        try {
            $firstUpload = UploadedFile::fake()->createWithContent(
                'product-cases.xlsx',
                (string) file_get_contents($source)
            );
            $firstResponse = $this->actingAs($owner, 'admin')
                ->withSession(['current_site_id' => $site->id])
                ->post(route('admin.management-tools.product-case-import.store'), [
                    'source' => $firstUpload,
                ]);
            $task = ProductCaseImport::query()->latest('id')->firstOrFail();

            $secondUpload = UploadedFile::fake()->createWithContent(
                'product-cases-copy.xlsx',
                (string) file_get_contents($source)
            );
            $secondResponse = $this->actingAs($owner, 'admin')
                ->withSession(['current_site_id' => $site->id])
                ->post(route('admin.management-tools.product-case-import.store'), [
                    'source' => $secondUpload,
                ]);

            $firstResponse->assertRedirect(route('admin.management-tools.product-case-import.show', ['importId' => $task->id]));
            $secondResponse
                ->assertRedirect(route('admin.management-tools.product-case-import.show', ['importId' => $task->id]))
                ->assertSessionHas('message', '相同案例文件已经创建过导入任务，无需重复提交');
            $this->assertSame(1, ProductCaseImport::query()->count());
        } finally {
            @unlink($source);
        }
    }

    public function test_dispatched_job_processes_the_task_and_records_row_results(): void
    {
        [$owner, $site] = $this->createSiteWithOwner('management_job_owner', '异步处理站点');
        $operator = $owner;
        Storage::fake('local');

        $source = $this->makeWorkbook();
        $storedPath = 'product-case-imports/async-cases.xlsx';
        Storage::disk('local')->put($storedPath, (string) file_get_contents($source));

        try {
            $task = app(ProductCaseImportTaskService::class)->create(
                actor: $operator,
                site: $site,
                storedPath: $storedPath,
                originalFilename: 'async-cases.xlsx',
                rows: []
            );

            $job = new ProcessProductCaseImportJob((int) $task->id);
            $job->handle(app(ProductCaseImportTaskService::class));

            $task->refresh();
            $item = $task->items()->firstOrFail();

            $this->assertSame('completed', $task->status);
            $this->assertSame(1, $task->processed_rows);
            $this->assertSame('succeeded', $item->status);
            $this->assertNotNull($item->product_case_id);
            $this->assertDatabaseHas('product_cases', [
                'id' => $item->product_case_id,
                'site_id' => $site->id,
                'owner_admin_id' => $owner->id,
                'status' => ProductCase::STATUS_PUBLISHED,
            ]);
        } finally {
            @unlink($source);
        }
    }

    /**
     * @return array{0:Admin,1:Site}
     */
    private function createSiteWithOwner(string $username, string $siteName): array
    {
        $owner = $this->createAdmin($username, 'super_admin');
        $site = Site::query()->create([
            'owner_admin_id' => $owner->id,
            'name' => $siteName,
            'status' => 'active',
        ]);
        $site->members()->attach($owner->id, ['role' => 'owner']);

        return [$owner, $site];
    }

    private function createAdmin(string $username, string $role, ?Admin $creator = null): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
            'created_by' => $creator?->id,
        ]);
    }

    private function createCase(Site $site, Admin $owner, string $title, string $slug): ProductCase
    {
        return ProductCase::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'title' => $title,
            'slug' => $slug,
            'company_name' => $title.'品牌',
            'status' => ProductCase::STATUS_DRAFT,
        ]);
    }

    private function createImport(Site $site, Admin $owner, Admin $creator, string $filename): ProductCaseImport
    {
        return ProductCaseImport::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'created_by_admin_id' => $creator->id,
            'original_filename' => $filename,
            'stored_path' => 'product-case-imports/'.$filename,
            'status' => 'completed',
            'total_rows' => 1,
            'processed_rows' => 1,
        ]);
    }

    private function makeWorkbook(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'geo-management-case-');
        $this->assertIsString($path);

        $zip = new ZipArchive;
        $this->assertSame(true, $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>
        <row r="1">
            <c r="A1" t="inlineStr"><is><t>行业类别</t></is></c>
            <c r="B1" t="inlineStr"><is><t>品牌名称</t></is></c>
            <c r="C1" t="inlineStr"><is><t>地区</t></is></c>
            <c r="D1" t="inlineStr"><is><t>案例标题</t></is></c>
            <c r="E1" t="inlineStr"><is><t>摘要</t></is></c>
            <c r="F1" t="inlineStr"><is><t>品牌介绍</t></is></c>
        </row>
        <row r="2">
            <c r="A2" t="inlineStr"><is><t>其他</t></is></c>
            <c r="B2" t="inlineStr"><is><t>上传案例品牌</t></is></c>
            <c r="C2" t="inlineStr"><is><t>杭州</t></is></c>
            <c r="D2" t="inlineStr"><is><t>上传案例标题</t></is></c>
            <c r="E2" t="inlineStr"><is><t>上传案例摘要</t></is></c>
            <c r="F2" t="inlineStr"><is><t>上传案例介绍</t></is></c>
        </row>
    </sheetData>
</worksheet>
XML);
        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
</Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="/xl/workbook.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheets><sheet name="案例" sheetId="1" r:id="rId1"/></sheets>
</workbook>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML);
        $zip->close();

        return $path;
    }
}
