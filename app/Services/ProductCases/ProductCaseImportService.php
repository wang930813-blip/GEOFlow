<?php

namespace App\Services\ProductCases;

use App\Models\Admin;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\ProductCase;
use App\Models\Site;
use App\Services\GeoFlow\ExternalImageHostClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProductCaseImportService
{
    private const IMAGE_LIBRARY_NAME = 'GEO案例库封面图';

    public function __construct(
        private readonly ProductCaseSpreadsheetReader $reader,
        private readonly ExternalImageHostClient $imageHostClient,
        private readonly ProductCaseDemoDataService $demoData
    ) {}

    /**
     * @return array{
     *     source:string,
     *     admin_id:int,
     *     site_id:int,
     *     total:int,
     *     created:int,
     *     updated:int,
     *     images_uploaded:int,
     *     images_reused:int,
     *     diagnosis_runs:int,
     *     failed:int,
     *     errors:list<string>,
     *     dry_run:bool
     * }
     */
    public function import(
        string $sourcePath,
        ?int $adminId = null,
        ?int $siteId = null,
        bool $refreshImages = false,
        bool $dryRun = false
    ): array {
        $sourcePath = $this->resolveSourcePath($sourcePath);
        $admin = $this->resolveSuperAdmin($adminId);
        $site = $this->resolveDefaultSite($admin, $siteId);
        $rows = $this->reader->read($sourcePath);

        $stats = [
            'source' => $sourcePath,
            'admin_id' => (int) $admin->id,
            'site_id' => (int) $site->id,
            'total' => count($rows),
            'created' => 0,
            'updated' => 0,
            'images_uploaded' => 0,
            'images_reused' => 0,
            'diagnosis_runs' => 0,
            'failed' => 0,
            'errors' => [],
            'dry_run' => $dryRun,
        ];

        if ($dryRun) {
            return $stats;
        }

        $imageLibrary = $this->imageLibrary($admin, $site);
        foreach ($rows as $row) {
            try {
                $slug = $this->caseSlug((string) $row['brand_name']);
                $imageResult = $this->coverImage(
                    $row,
                    $slug,
                    $imageLibrary,
                    $admin,
                    $site,
                    $refreshImages
                );
                if ($imageResult['uploaded']) {
                    $stats['images_uploaded']++;
                } elseif ($imageResult['reused']) {
                    $stats['images_reused']++;
                }

                $caseWasExisting = ProductCase::query()
                    ->withTrashed()
                    ->where('slug', $slug)
                    ->exists();

                $case = DB::transaction(function () use ($row, $slug, $imageResult, $admin, $site): ProductCase {
                    $case = ProductCase::query()
                        ->withTrashed()
                        ->where('slug', $slug)
                        ->first();

                    if (! $case instanceof ProductCase) {
                        $case = new ProductCase;
                    } elseif ($case->trashed()) {
                        $case->restore();
                    }

                    $publishedAt = $case->published_at ?: now();
                    $case->fill([
                        'site_id' => (int) $site->id,
                        'owner_admin_id' => (int) $admin->id,
                        'title' => trim((string) $row['title']),
                        'slug' => $slug,
                        'company_name' => trim((string) $row['brand_name']),
                        'logo_url' => '',
                        'cover_url' => (string) ($imageResult['url'] ?? ''),
                        'industry' => trim((string) $row['industry']),
                        'region' => trim((string) $row['region']),
                        'business_mode' => '',
                        'module_tags' => ['品牌诊断', 'AI问题', 'AI信源', '竞品分析'],
                        'summary' => trim((string) $row['summary']),
                        'content' => $this->caseContent($row),
                        'customer_level' => '4',
                        'started_at' => now()->subDays($this->seedNumber((string) $row['brand_name']) % 540 + 30)->toDateString(),
                        'status' => ProductCase::STATUS_PUBLISHED,
                        'sort_order' => max(1, 1000 - (int) $row['row_number']),
                        'published_at' => $publishedAt,
                        'created_by_admin_id' => (int) $admin->id,
                        'updated_by_admin_id' => (int) $admin->id,
                    ]);
                    $case->save();

                    return $case;
                });

                $this->demoData->seed($case, $row, $sourcePath);
                $stats[$caseWasExisting ? 'updated' : 'created']++;
                $stats['diagnosis_runs']++;
            } catch (Throwable $exception) {
                $stats['failed']++;
                $stats['errors'][] = '第 '.(int) $row['row_number'].' 行（'.(string) $row['brand_name'].'）导入失败：'.$exception->getMessage();
            }
        }

        return $stats;
    }

    private function resolveSourcePath(string $sourcePath): string
    {
        $sourcePath = trim($sourcePath);
        if ($sourcePath === '') {
            throw new RuntimeException('案例库文件路径不能为空');
        }

        $path = $sourcePath;
        if (! is_file($path)) {
            $path = base_path($sourcePath);
        }

        if (! is_file($path)) {
            throw new RuntimeException('案例库文件不存在: '.$sourcePath);
        }

        return (string) (realpath($path) ?: $path);
    }

    private function resolveSuperAdmin(?int $adminId): Admin
    {
        $query = Admin::query()
            ->where('status', 'active')
            ->whereIn('role', ['super_admin', 'superadmin'])
            ->orderBy('id');

        if ($adminId !== null && $adminId > 0) {
            $admin = $query->whereKey($adminId)->first();
        } else {
            $admin = $query->first();
        }

        if (! $admin instanceof Admin) {
            throw new RuntimeException('未找到可用的超级管理员账号，请使用 --admin-id 指定');
        }

        return $admin;
    }

    private function resolveDefaultSite(Admin $admin, ?int $siteId): Site
    {
        $query = Site::query()
            ->where('owner_admin_id', (int) $admin->id)
            ->where('status', 'active');

        if ($siteId !== null && $siteId > 0) {
            $site = $query->whereKey($siteId)->first();
        } else {
            $site = $query
                ->orderByRaw("CASE WHEN name LIKE ? THEN 0 ELSE 1 END", ['%默认站点%'])
                ->orderBy('id')
                ->first();
        }

        if (! $site instanceof Site) {
            throw new RuntimeException('未找到超级管理员的默认站点，请先创建默认站点或使用 --site-id 指定');
        }

        return $site;
    }

    private function imageLibrary(Admin $admin, Site $site): ImageLibrary
    {
        $library = ImageLibrary::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->where('name', self::IMAGE_LIBRARY_NAME)
            ->first();

        if ($library instanceof ImageLibrary) {
            return $library;
        }

        return ImageLibrary::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->create([
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $admin->id,
                'name' => self::IMAGE_LIBRARY_NAME,
                'description' => '由 GEO 案例库导入脚本维护的案例封面图片。',
                'image_count' => 0,
                'used_task_count' => 0,
            ]);
    }

    /**
     * @param  array{row_number:int,industry:string,brand_name:string,region:string,title:string,summary:string,brand_introduction:string,image_binary:?string,image_mime_type:string,image_filename:string}  $row
     * @return array{url:string,uploaded:bool,reused:bool}
     */
    private function coverImage(
        array $row,
        string $slug,
        ImageLibrary $library,
        Admin $admin,
        Site $site,
        bool $refreshImages
    ): array {
        $tag = 'geo-case:'.$slug;
        $existing = Image::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('library_id', (int) $library->id)
            ->where('tags', 'like', '%'.$tag.'%')
            ->orderByDesc('id')
            ->first();

        $binary = $row['image_binary'];
        if ((! is_string($binary) || $binary === '') && $existing instanceof Image) {
            return [
                'url' => (string) $existing->file_path,
                'uploaded' => false,
                'reused' => true,
            ];
        }

        if (! is_string($binary) || $binary === '') {
            return [
                'url' => '',
                'uploaded' => false,
                'reused' => false,
            ];
        }

        if ($existing instanceof Image && ! $refreshImages) {
            return [
                'url' => (string) $existing->file_path,
                'uploaded' => false,
                'reused' => true,
            ];
        }

        $mimeType = trim((string) $row['image_mime_type']) ?: 'image/png';
        $originalName = trim((string) $row['image_filename']) ?: $slug.'.png';
        $uploaded = $this->imageHostClient->upload($binary, $mimeType, $originalName);
        $url = trim((string) ($uploaded['url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('图床没有返回图片地址');
        }

        $imageInfo = @getimagesizefromstring($binary) ?: [0, 0, 'mime' => $mimeType];
        $key = trim((string) ($uploaded['key'] ?? ''));
        $uploadedPath = parse_url($url, PHP_URL_PATH);
        $filename = basename($key !== '' ? $key : (is_string($uploadedPath) && $uploadedPath !== '' ? $uploadedPath : $originalName));
        $attributes = [
            'library_id' => (int) $library->id,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'filename' => $filename,
            'original_name' => $originalName,
            'file_name' => $filename,
            'file_path' => $url,
            'file_size' => (int) ($uploaded['size'] ?? strlen($binary)),
            'mime_type' => (string) ($uploaded['mime_type'] ?? $imageInfo['mime'] ?? $mimeType),
            'width' => (int) ($imageInfo[0] ?? 0),
            'height' => (int) ($imageInfo[1] ?? 0),
            'tags' => 'geo-case-import|'.$tag,
            'used_count' => 0,
            'usage_count' => 0,
        ];

        if ($existing instanceof Image) {
            $existing->update($attributes);
        } else {
            Image::query()
                ->withoutGlobalScopes(['current_site', 'admin_owner'])
                ->create($attributes);
        }

        $library->update([
            'image_count' => Image::query()
                ->withoutGlobalScopes(['current_site', 'admin_owner'])
                ->where('library_id', (int) $library->id)
                ->count(),
        ]);

        return [
            'url' => $url,
            'uploaded' => true,
            'reused' => false,
        ];
    }

    /**
     * @param  array{summary:string,brand_introduction:string}  $row
     */
    private function caseContent(array $row): string
    {
        $summary = trim((string) $row['summary']);
        $introduction = trim((string) $row['brand_introduction']);
        $sections = [];
        if ($introduction !== '') {
            $sections[] = "## 品牌介绍\n\n".$introduction;
        }
        if ($summary !== '') {
            $sections[] = "## 案例摘要\n\n".$summary;
        }
        $sections[] = "## 数据说明\n\n本案例由 GEO 案例库导入，品牌诊断明细为系统生成的演示数据。";

        return implode("\n\n", $sections);
    }

    private function caseSlug(string $brandName): string
    {
        $normalized = trim($brandName);
        $readable = Str::slug($normalized);
        $readable = trim($readable, '-');
        $suffix = substr(hash('sha256', $normalized), 0, 12);

        return 'geo-case-'.($readable !== '' ? $readable.'-' : '').$suffix;
    }

    private function seedNumber(string $brandName): int
    {
        return (int) hexdec(substr(hash('sha256', $brandName), 0, 8));
    }
}
