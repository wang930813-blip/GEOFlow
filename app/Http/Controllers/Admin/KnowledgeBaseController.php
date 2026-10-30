<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Data\Ai\SystemAiIdentity;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseRevision;
use App\Models\KnowledgeChunk;
use App\Models\Task;
use App\Services\AiWorkspace\AdminHelpFeatureRegistry;
use App\Services\AiWorkspace\SystemKnowledgeBaseManager;
use App\Services\GeoFlow\ArticleAiQualityInvalidationService;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactLibraryPresenter;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use App\Support\AdminActivityLogger;
use App\Support\AdminWeb;
use App\Support\AiConfigurationScope;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 知识库管理控制器。
 */
class KnowledgeBaseController extends Controller
{
    private const MAX_KNOWLEDGE_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly KnowledgeChunkSyncService $chunkSyncService,
        private readonly AiConfigurationScope $aiConfigurationScope,
        private readonly SystemKnowledgeBaseManager $systemKnowledgeBases,
        private readonly AdminHelpFeatureRegistry $adminHelpFeatures,
        private readonly KnowledgeFactLibraryPresenter $factLibraryPresenter,
        private readonly ArticleAiQualityInvalidationService $qualityInvalidationService,
        private readonly KnowledgeChunkSyncCoordinator $chunkSyncCoordinator,
    ) {}

    /**
     * 列表页。
     */
    public function index(): View
    {
        return view('admin.knowledge-bases.index', [
            'pageTitle' => __('admin.knowledge_bases.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'knowledgeBases' => $this->loadKnowledgeBases(),
            'stats' => $this->loadStats(),
            'hasDefaultEmbeddingModel' => $this->hasDefaultEmbeddingModel(),
            'canManageAiConfig' => $this->canManageAiConfig(),
        ]);
    }

    /**
     * 创建表单页。
     */
    public function create(): View
    {
        return view('admin.knowledge-bases.form', [
            'pageTitle' => __('admin.knowledge_bases.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'knowledgeBaseId' => 0,
            'knowledgeForm' => $this->emptyForm(),
        ]);
    }

    /**
     * 知识库详情页，展示切块与向量状态。
     */
    public function detail(int $knowledgeBaseId): View|RedirectResponse
    {
        $knowledgeBase = $this->findVisibleKnowledgeBase($knowledgeBaseId);
        $knowledgeBase->load([
            'systemBinding',
            'revisions.creator',
            'mediaAssets.creator',
            'factLibrary.activeRevision',
        ]);
        $admin = request()->user('admin');
        $isSystemKnowledge = $knowledgeBase->isSystemManaged();
        $canEditSystemKnowledge = $admin instanceof Admin && $admin->canManageProtectedWorkflows();
        $systemKnowledgeHealth = $isSystemKnowledge ? $this->systemKnowledgeBases->health($knowledgeBase) : null;
        $systemOfficialContent = null;
        if ($canEditSystemKnowledge && ($systemKnowledgeHealth['is_customized'] ?? false)) {
            $definition = $this->systemKnowledgeBases->definition((string) $knowledgeBase->systemBinding?->system_key);
            $systemOfficialContent = $this->systemKnowledgeBases->bundledContent($definition);
        }

        return view('admin.knowledge-bases.detail', [
            'pageTitle' => __('admin.knowledge_detail.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'knowledgeBase' => $knowledgeBase,
            'relatedTasks' => $this->loadRelatedTasks($knowledgeBaseId),
            'chunkStats' => $this->loadChunkStats($knowledgeBaseId),
            'chunkPreviewRows' => $this->loadChunkPreviewRows($knowledgeBaseId),
            'factSummary' => $knowledgeBase->factLibrary
                ? $this->factLibraryPresenter->summary($knowledgeBase->factLibrary)
                : null,
            'isSystemKnowledge' => $isSystemKnowledge,
            'systemKnowledgeHealth' => $systemKnowledgeHealth,
            'systemOfficialContent' => $systemOfficialContent,
            'canEditSystemKnowledge' => $canEditSystemKnowledge,
            'knowledgeMediaAssets' => $knowledgeBase->mediaAssets
                ->filter(fn ($asset): bool => $admin instanceof Admin
                    && $this->adminHelpFeatures->canAccessRoute($admin, (string) $asset->route_name))
                ->values(),
            'hasDefaultEmbeddingModel' => $this->hasDefaultEmbeddingModel(),
            'canManageAiConfig' => $this->canManageAiConfig(),
        ]);
    }

    public function chunks(int $knowledgeBaseId): View
    {
        $knowledgeBase = $this->findVisibleKnowledgeBase($knowledgeBaseId);
        $admin = request()->user('admin');

        return view('admin.knowledge-bases.chunks.index', [
            'pageTitle' => __('admin.knowledge_chunks.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'knowledgeBase' => $knowledgeBase,
            'chunkStats' => $this->loadChunkStats($knowledgeBaseId),
            'chunkRows' => $this->paginateChunkRows($knowledgeBaseId),
            'systemReadOnly' => $knowledgeBase->isSystemManaged()
                && ! ($admin instanceof Admin && $admin->canManageProtectedWorkflows()),
        ]);
    }

    /**
     * 详情页更新知识库内容并同步 chunk。
     */
    public function updateFromDetail(Request $request, int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = $this->findVisibleKnowledgeBase($knowledgeBaseId);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'file_type' => ['required', 'in:markdown,word,text,pdf,presentation'],
        ], [
            'name.required' => __('admin.knowledge_bases.error.name_required'),
            'content.required' => __('admin.knowledge_bases.error.content_required'),
        ]);

        $content = trim((string) $payload['content']);
        $this->assertKnowledgeContentSize($content);

        if ($knowledgeBase->isSystemManaged()) {
            $admin = $request->user('admin');
            abort_unless($admin instanceof Admin && $admin->canManageProtectedWorkflows(), 403);
            $contentChanged = ! hash_equals(hash('sha256', trim((string) $knowledgeBase->content)), hash('sha256', $content));

            try {
                $this->systemKnowledgeBases->update($knowledgeBase, $admin, $payload);
            } catch (\RuntimeException $exception) {
                throw ValidationException::withMessages(['content' => $exception->getMessage()]);
            }
            if ($contentChanged) {
                $this->qualityInvalidationService->invalidateKnowledgeBase($knowledgeBaseId, '系统知识正文已更新');
            }
            AdminActivityLogger::logFromRequest($request, $admin, 'system_knowledge.updated', [
                'knowledge_base_id' => $knowledgeBaseId,
                'content_hash' => hash('sha256', (string) $knowledgeBase->fresh()->content),
                'content_changed' => $contentChanged,
            ]);

            return redirect()->route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBaseId])
                ->with('message', __($contentChanged
                    ? 'admin.knowledge_bases.message.chunk_sync_queued'
                    : 'admin.knowledge_bases.message.saved_without_reindex'));
        }

        DB::transaction(function () use ($knowledgeBase, $payload, $content): void {
            $knowledgeBase->update([
                'name' => trim((string) $payload['name']),
                'description' => trim((string) ($payload['description'] ?? '')),
                'content' => $content,
                'file_type' => (string) $payload['file_type'],
                'character_count' => mb_strlen($content, 'UTF-8'),
                'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
            ]);

            $this->chunkSyncService->sync((int) $knowledgeBase->id, $content);
        });
        $this->qualityInvalidationService->invalidateKnowledgeBase($knowledgeBaseId, '知识库正文已更新');

        return redirect()->route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBaseId])->with(
            'message',
            __('admin.knowledge_bases.message.update_success', ['count' => $this->countChunks($knowledgeBaseId)])
        );
    }

    /**
     * 上传知识文档并写入知识库。
     */
    public function uploadFile(Request $request): RedirectResponse
    {
        $request->validate([
            'knowledge_file' => ['required', File::types(['txt', 'md', 'docx'])->max(10 * 1024)],
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'source_name' => ['nullable', 'string', 'max:150'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'source_type' => ['nullable', 'in:document,website,business,faq,other'],
            'business_line' => ['nullable', 'string', 'max:100'],
            'effective_date' => ['nullable', 'date'],
            'risk_level' => ['nullable', 'in:low,medium,high'],
            'review_status' => ['nullable', 'in:unreviewed,reviewed'],
        ], [
            'knowledge_file.required' => __('admin.knowledge_bases.error.file_required'),
        ]);

        /** @var UploadedFile|null $knowledgeFile */
        $knowledgeFile = $request->file('knowledge_file');
        if (! $knowledgeFile instanceof UploadedFile) {
            return back()->withErrors(__('admin.knowledge_bases.error.file_required'));
        }

        $storedRelativePath = '';
        try {
            $storedRelativePath = $this->storeUploadedKnowledgeFile($knowledgeFile);
            $parsed = $this->parseUploadedKnowledgeFile(Storage::disk('local')->path($storedRelativePath), $knowledgeFile->getClientOriginalName());
            $content = trim($parsed['content']);
            if ($content === '') {
                throw new \RuntimeException(__('admin.knowledge_bases.error.content_required'));
            }

            $knowledgeName = trim((string) $request->input('name', ''));
            if ($knowledgeName === '') {
                $knowledgeName = pathinfo((string) $knowledgeFile->getClientOriginalName(), PATHINFO_FILENAME);
            }

            $chunkCount = 0;
            DB::transaction(function () use (&$chunkCount, $knowledgeName, $request, $content, $parsed, $storedRelativePath): void {
                $knowledgeBase = KnowledgeBase::query()->create([
                    'name' => $knowledgeName,
                    'description' => trim((string) $request->input('description', '')),
                    'content' => $content,
                    'file_type' => $parsed['file_type'],
                    'character_count' => mb_strlen($content, 'UTF-8'),
                    'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
                    'usage_count' => 0,
                    'used_task_count' => 0,
                    'file_path' => $storedRelativePath,
                ] + $this->knowledgeMetadataPayload($request->only([
                    'source_name',
                    'source_url',
                    'source_type',
                    'business_line',
                    'effective_date',
                    'risk_level',
                    'review_status',
                ])));
                $chunkCount = $this->chunkSyncService->sync((int) $knowledgeBase->id, $content);
            });

            return redirect()->route('admin.knowledge-bases.index')->with('message', __('admin.knowledge_bases.message.upload_success', ['count' => $chunkCount]));
        } catch (\Throwable $exception) {
            $this->cleanupKnowledgeFile($storedRelativePath);

            return back()->withErrors(__('admin.knowledge_bases.message.upload_error', ['message' => $exception->getMessage()]));
        }
    }

    /**
     * 创建知识库并同步 chunks。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateKnowledgeForm($request);

        $content = trim((string) $payload['content']);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'content' => $content,
            'file_type' => (string) $payload['file_type'],
            'character_count' => mb_strlen($content, 'UTF-8'),
            'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
            'usage_count' => 0,
            'used_task_count' => 0,
            'file_path' => '',
        ] + $this->knowledgeMetadataPayload($payload));

        $chunkCount = $this->chunkSyncService->sync((int) $knowledgeBase->id, $content);

        return redirect()
            ->route('admin.knowledge-bases.index')
            ->with('message', __('admin.knowledge_bases.message.create_success', ['count' => $chunkCount]));
    }

    /**
     * 编辑表单页。
     */
    public function edit(int $knowledgeBaseId): View|RedirectResponse
    {
        $knowledgeBase = $this->findVisibleKnowledgeBase($knowledgeBaseId);
        $isSystemKnowledge = $knowledgeBase->isSystemManaged();
        $admin = request()->user('admin');

        return view('admin.knowledge-bases.form', [
            'pageTitle' => __('admin.knowledge_bases.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'knowledgeBaseId' => (int) $knowledgeBase->id,
            'knowledgeBase' => $knowledgeBase,
            'isSystemKnowledge' => $isSystemKnowledge,
            'systemKnowledgeHealth' => $isSystemKnowledge ? $this->systemKnowledgeBases->health($knowledgeBase) : null,
            'canEditSystemKnowledge' => $admin instanceof Admin && $admin->canManageProtectedWorkflows(),
            'knowledgeForm' => [
                'name' => (string) $knowledgeBase->name,
                'description' => (string) ($knowledgeBase->description ?? ''),
                'content' => (string) ($knowledgeBase->content ?? ''),
                'file_type' => (string) ($knowledgeBase->file_type ?? 'markdown'),
                'source_name' => (string) ($knowledgeBase->source_name ?? ''),
                'source_url' => (string) ($knowledgeBase->source_url ?? ''),
                'source_type' => (string) ($knowledgeBase->source_type ?? 'document'),
                'business_line' => (string) ($knowledgeBase->business_line ?? ''),
                'effective_date' => $knowledgeBase->effective_date?->toDateString() ?? '',
                'risk_level' => (string) ($knowledgeBase->risk_level ?? 'medium'),
                'review_status' => (string) ($knowledgeBase->review_status ?? 'unreviewed'),
            ],
            'chunkCount' => (int) $knowledgeBase->chunks()->count(),
        ]);
    }

    /**
     * 更新知识库并重建 chunks。
     */
    public function update(Request $request, int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = $this->findVisibleKnowledgeBase($knowledgeBaseId);

        $payload = $this->validateKnowledgeForm($request);
        $content = trim((string) $payload['content']);

        if ($knowledgeBase->isSystemManaged()) {
            $admin = $request->user('admin');
            abort_unless($admin instanceof Admin && $admin->canManageProtectedWorkflows(), 403);
            $contentChanged = ! hash_equals(hash('sha256', trim((string) $knowledgeBase->content)), hash('sha256', $content));

            try {
                $this->systemKnowledgeBases->update($knowledgeBase, $admin, $payload);
            } catch (\RuntimeException $exception) {
                throw ValidationException::withMessages(['content' => $exception->getMessage()]);
            }
            if ($contentChanged) {
                $this->qualityInvalidationService->invalidateKnowledgeBase($knowledgeBaseId, '系统知识正文已更新');
            }
            AdminActivityLogger::logFromRequest($request, $admin, 'system_knowledge.updated', [
                'knowledge_base_id' => $knowledgeBaseId,
                'content_hash' => hash('sha256', (string) $knowledgeBase->fresh()->content),
                'content_changed' => $contentChanged,
            ]);

            return redirect()->route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBaseId])
                ->with('message', __($contentChanged
                    ? 'admin.knowledge_bases.message.chunk_sync_queued'
                    : 'admin.knowledge_bases.message.saved_without_reindex'));
        }

        $knowledgeBase->update([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'content' => $content,
            'file_type' => (string) $payload['file_type'],
            'character_count' => mb_strlen($content, 'UTF-8'),
            'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
        ] + $this->knowledgeMetadataPayload($payload));
        $this->qualityInvalidationService->invalidateKnowledgeBase($knowledgeBaseId, '知识库正文已更新');

        $chunkCount = $this->chunkSyncService->sync((int) $knowledgeBase->id, $content);

        return redirect()
            ->route('admin.knowledge-bases.index')
            ->with('message', __('admin.knowledge_bases.message.update_success', ['count' => $chunkCount]));
    }

    /**
     * 删除知识库（存在任务引用时阻止）。
     */
    public function destroy(int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = $this->findVisibleKnowledgeBase($knowledgeBaseId);
        if ($knowledgeBase->isSystemManaged()) {
            return back()->withErrors(__('admin.knowledge_bases.error.system_delete_forbidden'));
        }

        $taskCount = Task::query()->where('knowledge_base_id', $knowledgeBaseId)->count();
        if ($taskCount > 0) {
            return back()->withErrors(__('admin.knowledge_bases.error.in_use', ['count' => $taskCount]));
        }

        $filePath = (string) ($knowledgeBase->file_path ?? '');
        $knowledgeBase->delete();
        $this->cleanupKnowledgeFile($filePath);

        return redirect()->route('admin.knowledge-bases.index')->with('message', __('admin.knowledge_bases.message.delete_success'));
    }

    public function refreshChunks(Request $request, int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = $this->findVisibleKnowledgeBase($knowledgeBaseId);
        $content = trim((string) ($knowledgeBase->content ?? ''));
        $redirectRoute = $this->refreshChunksRedirectRoute($request, $knowledgeBaseId);

        if ($knowledgeBase->isSystemManaged()) {
            $admin = $request->user('admin');
            abort_unless($admin instanceof Admin && $admin->canManageProtectedWorkflows(), 403);
            $this->chunkSyncCoordinator->request(
                (int) $knowledgeBase->id,
                SystemAiIdentity::knowledgeIndex(),
                requireRealEmbedding: false,
                force: true,
            );

            return redirect()->to($redirectRoute)->with('message', __('admin.knowledge_bases.message.chunks_refresh_queued'));
        }

        if ($content === '') {
            return redirect()
                ->to($redirectRoute)
                ->withErrors(__('admin.knowledge_bases.error.content_required'));
        }

        try {
            $chunkCount = $this->chunkSyncService->sync((int) $knowledgeBase->id, $content, true);
            $stats = $this->loadChunkStats((int) $knowledgeBase->id);
            $vectorizedCount = (int) ($stats['vectorized_count'] ?? 0);

            if ($chunkCount > 0 && $vectorizedCount < $chunkCount) {
                return redirect()
                    ->to($redirectRoute)
                    ->withErrors(__('admin.knowledge_bases.error.embedding_sync_partial', [
                        'chunks' => $chunkCount,
                        'vectorized' => $vectorizedCount,
                    ]));
            }

            return redirect()
                ->to($redirectRoute)
                ->with('message', __('admin.knowledge_bases.message.chunks_refreshed', [
                    'chunks' => $chunkCount,
                    'vectorized' => $vectorizedCount,
                ]));
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->to($redirectRoute)
                ->withErrors(__('admin.knowledge_bases.message.chunks_refresh_error', [
                    'message' => $exception->getMessage(),
                ]));
        }
    }

    public function restoreRevision(Request $request, int $knowledgeBaseId, int $revisionId): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $admin->canManageProtectedWorkflows(), 403);
        $knowledgeBase = $this->findVisibleKnowledgeBase($knowledgeBaseId);
        abort_unless($knowledgeBase->isSystemManaged(), 404);
        $revision = KnowledgeBaseRevision::query()->findOrFail($revisionId);

        try {
            $restored = $this->systemKnowledgeBases->restore($knowledgeBase, $revision, $admin);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['revision' => $exception->getMessage()]);
        }
        $this->qualityInvalidationService->invalidateKnowledgeBase($knowledgeBaseId, '系统知识修订已恢复');
        AdminActivityLogger::logFromRequest($request, $admin, 'system_knowledge.revision_restored', [
            'knowledge_base_id' => $knowledgeBaseId,
            'revision_id' => $restored->getKey(),
            'restored_from_revision_id' => $revisionId,
        ]);

        return redirect()->route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBaseId])
            ->with('message', __('admin.knowledge_bases.message.chunk_sync_queued'));
    }

    public function adoptOfficial(Request $request, int $knowledgeBaseId): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $admin->canManageProtectedWorkflows(), 403);
        $knowledgeBase = $this->findVisibleKnowledgeBase($knowledgeBaseId);
        abort_unless($knowledgeBase->isSystemManaged(), 404);
        $definition = $this->systemKnowledgeBases->definition((string) $knowledgeBase->systemBinding?->system_key);
        $contentChanged = ! hash_equals(hash('sha256', (string) $knowledgeBase->content), (string) $definition['content_hash']);
        $revision = $this->systemKnowledgeBases->adoptOfficial($knowledgeBase, $admin);
        if ($contentChanged) {
            $this->qualityInvalidationService->invalidateKnowledgeBase($knowledgeBaseId, '系统知识已采用当前官方版本');
        }
        AdminActivityLogger::logFromRequest($request, $admin, 'system_knowledge.official_adopted', [
            'knowledge_base_id' => $knowledgeBaseId,
            'revision_id' => $revision->getKey(),
            'content_changed' => $contentChanged,
        ]);

        return redirect()->route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBaseId])
            ->with('message', __($contentChanged
                ? 'admin.knowledge_bases.message.chunk_sync_queued'
                : 'admin.knowledge_bases.message.saved_without_reindex'));
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function loadKnowledgeBases(): array
    {
        $query = KnowledgeBase::query()
            ->select([
                'id',
                'name',
                'description',
                'file_type',
                'content',
                'chunk_sync_status',
                'chunk_sync_error',
                'chunk_source_hash',
                'chunk_synced_at',
                'word_count',
                'usage_count',
                'created_at',
                'updated_at',
            ])
            ->with('systemBinding')
            ->withCount('chunks as chunk_count')
            ->withCount([
                'chunks as vectorized_chunk_count' => fn ($query) => $query
                    ->whereNotNull('embedding_model_id')
                    ->where('embedding_dimensions', '>', 0),
            ])
            ->orderByDesc('created_at');

        $knowledgeBases = $query->get();
        $systemKnowledgeBases = KnowledgeBase::query()
            ->withoutGlobalScopes()
            ->whereHas('systemBinding')
            ->with('systemBinding')
            ->withCount('chunks as chunk_count')
            ->withCount([
                'chunks as vectorized_chunk_count' => fn ($query) => $query
                    ->whereNotNull('embedding_model_id')
                    ->where('embedding_dimensions', '>', 0),
            ])
            ->get();

        return $knowledgeBases->merge($systemKnowledgeBases)->unique('id')->sortByDesc('created_at')->values()->map(function (KnowledgeBase $knowledgeBase): array {
            $isSystemKnowledge = $knowledgeBase->isSystemManaged();

            return [
                'id' => (int) $knowledgeBase->id,
                'name' => (string) $knowledgeBase->name,
                'description' => (string) ($knowledgeBase->description ?? ''),
                'file_type' => (string) ($knowledgeBase->file_type ?? 'markdown'),
                'word_count' => (int) ($knowledgeBase->word_count ?? 0),
                'usage_count' => (int) ($knowledgeBase->usage_count ?? 0),
                'chunk_count' => (int) ($knowledgeBase->chunk_count ?? 0),
                'vectorized_chunk_count' => (int) ($knowledgeBase->vectorized_chunk_count ?? 0),
                'created_at' => $knowledgeBase->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $knowledgeBase->updated_at?->format('Y-m-d H:i:s'),
                'is_system' => $isSystemKnowledge,
                'system_health' => $isSystemKnowledge ? $this->systemKnowledgeBases->health($knowledgeBase) : null,
                'official_version' => (string) ($knowledgeBase->systemBinding?->official_version ?? ''),
            ];
        })->all();
    }

    /**
     * 判断是否存在可用的 embedding 模型，用于知识库列表按钮引导。
     */
    private function hasDefaultEmbeddingModel(): bool
    {
        return $this->aiConfigurationScope->applyCurrentConsumerScope(
            AiModel::query()->withoutGlobalScope('current_site'),
            'ai_models.owner_admin_id'
        )
            ->activeStatus()
            ->embeddingType()
            ->exists();
    }

    private function canManageAiConfig(): bool
    {
        $admin = request()->user('admin');

        return $admin instanceof Admin && $this->aiConfigurationScope->canManage($admin);
    }

    private function refreshChunksRedirectRoute(Request $request, int $knowledgeBaseId): string
    {
        $redirectTo = trim((string) $request->input('redirect_to', ''));
        if ($redirectTo === 'detail') {
            return route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBaseId]);
        }

        $allowedPaths = [
            route('admin.knowledge-bases.index', [], false),
            route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBaseId], false),
            route('admin.knowledge-bases.chunks.index', ['knowledgeBaseId' => $knowledgeBaseId], false),
        ];

        return in_array($redirectTo, $allowedPaths, true)
            ? url($redirectTo)
            : route('admin.knowledge-bases.index');
    }

    /**
     * @return array{total_knowledge:int,total_words:int,markdown_count:int,word_count:int}
     */
    private function loadStats(): array
    {
        $tenantKnowledgeBases = KnowledgeBase::query()->whereDoesntHave('systemBinding');
        $systemKnowledgeBases = KnowledgeBase::query()->withoutGlobalScopes()->whereHas('systemBinding');

        return [
            'total_knowledge' => (clone $tenantKnowledgeBases)->count() + (clone $systemKnowledgeBases)->count(),
            'total_words' => (int) ((clone $tenantKnowledgeBases)->sum('word_count') ?? 0)
                + (int) ((clone $systemKnowledgeBases)->sum('word_count') ?? 0),
            'markdown_count' => (clone $tenantKnowledgeBases)->where('file_type', 'markdown')->count()
                + (clone $systemKnowledgeBases)->where('file_type', 'markdown')->count(),
            'word_count' => (clone $tenantKnowledgeBases)->where('file_type', 'word')->count()
                + (clone $systemKnowledgeBases)->where('file_type', 'word')->count(),
        ];
    }

    /**
     * 校验知识库表单。
     *
     * @return array{name:string,description:?string,content:string,file_type:string,source_name:?string,source_url:?string,source_type:?string,business_line:?string,effective_date:?string,risk_level:string,review_status:string}
     */
    private function validateKnowledgeForm(Request $request): array
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'file_type' => ['required', 'in:markdown,word,text,pdf,presentation'],
            'source_name' => ['nullable', 'string', 'max:150'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'source_type' => ['nullable', 'in:document,website,business,faq,other'],
            'business_line' => ['nullable', 'string', 'max:100'],
            'effective_date' => ['nullable', 'date'],
            'risk_level' => ['nullable', 'in:low,medium,high'],
            'review_status' => ['nullable', 'in:unreviewed,reviewed'],
        ], [
            'name.required' => __('admin.knowledge_bases.error.name_required'),
            'content.required' => __('admin.knowledge_bases.error.content_required'),
        ]);

        $this->assertKnowledgeContentSize((string) $payload['content']);

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function knowledgeMetadataPayload(array $payload): array
    {
        return [
            'source_name' => trim((string) ($payload['source_name'] ?? '')) ?: null,
            'source_url' => trim((string) ($payload['source_url'] ?? '')) ?: null,
            'source_type' => (string) ($payload['source_type'] ?? 'document'),
            'business_line' => trim((string) ($payload['business_line'] ?? '')) ?: null,
            'effective_date' => $payload['effective_date'] ?? null,
            'risk_level' => (string) ($payload['risk_level'] ?? 'medium'),
            'review_status' => (string) ($payload['review_status'] ?? 'unreviewed'),
        ];
    }

    private function assertKnowledgeContentSize(string $content): void
    {
        if (strlen($content) <= self::MAX_KNOWLEDGE_BYTES) {
            return;
        }

        throw ValidationException::withMessages([
            'content' => __('admin.knowledge_bases.error.content_too_large'),
        ]);
    }

    /**
     * @return array{name:string,description:string,content:string,file_type:string}
     */
    private function emptyForm(): array
    {
        return [
            'name' => '',
            'description' => '',
            'content' => '',
            'file_type' => 'markdown',
        ];
    }

    /**
     * @return array{chunk_count:int,vectorized_count:int}
     */
    private function loadChunkStats(int $knowledgeBaseId): array
    {
        $chunkCount = KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->count();
        $vectorizedCount = KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->whereNotNull('embedding_model_id')
            ->where('embedding_dimensions', '>', 0)
            ->count();

        return ['chunk_count' => $chunkCount, 'vectorized_count' => $vectorizedCount];
    }

    /**
     * @return EloquentCollection<int, Task>
     */
    private function loadRelatedTasks(int $knowledgeBaseId): EloquentCollection
    {
        return Task::query()
            ->select(['id', 'name', 'status', 'updated_at'])
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function loadChunkPreviewRows(int $knowledgeBaseId): Collection
    {
        return KnowledgeChunk::query()
            ->select([
                'chunk_index',
                'content',
                'token_count',
                'embedding_model_id',
                'embedding_dimensions',
                'embedding_provider',
            ])
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->orderBy('chunk_index')
            ->limit(20)
            ->get()
            ->map(static function (KnowledgeChunk $chunk): array {
                $preview = mb_substr(trim((string) $chunk->content), 0, 160, 'UTF-8');

                return [
                    'chunk_index' => (int) $chunk->chunk_index,
                    'content_length' => mb_strlen((string) $chunk->content, 'UTF-8'),
                    'token_count' => (int) ($chunk->token_count ?? 0),
                    'embedding_model_id' => $chunk->embedding_model_id !== null ? (int) $chunk->embedding_model_id : null,
                    'embedding_dimensions' => (int) ($chunk->embedding_dimensions ?? 0),
                    'embedding_provider' => (string) ($chunk->embedding_provider ?? ''),
                    'content_preview' => $preview,
                ];
            });
    }

    private function findVisibleKnowledgeBase(int $knowledgeBaseId): KnowledgeBase
    {
        $knowledgeBase = $this->systemKnowledgeBases->findVisibleKnowledgeBase($knowledgeBaseId);
        abort_unless($knowledgeBase instanceof KnowledgeBase, 404);

        return $knowledgeBase;
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateChunkRows(int $knowledgeBaseId): LengthAwarePaginator
    {
        return KnowledgeChunk::query()
            ->select([
                'chunk_index',
                'content',
                'chunk_title',
                'section_path',
                'chunk_strategy',
                'token_count',
                'embedding_model_id',
                'embedding_dimensions',
                'embedding_provider',
            ])
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->orderBy('chunk_index')
            ->paginate(30)
            ->through(static fn (KnowledgeChunk $chunk): array => [
                'chunk_index' => (int) $chunk->chunk_index,
                'content_length' => mb_strlen((string) $chunk->content, 'UTF-8'),
                'token_count' => (int) ($chunk->token_count ?? 0),
                'embedding_model_id' => $chunk->embedding_model_id !== null ? (int) $chunk->embedding_model_id : null,
                'embedding_dimensions' => (int) ($chunk->embedding_dimensions ?? 0),
                'embedding_provider' => (string) ($chunk->embedding_provider ?? ''),
                'chunk_title' => (string) ($chunk->chunk_title ?? ''),
                'section_path' => (string) ($chunk->section_path ?? ''),
                'chunk_strategy' => (string) ($chunk->chunk_strategy ?? 'structured_rule'),
                'content_preview' => mb_substr(trim((string) $chunk->content), 0, 240, 'UTF-8'),
            ]);
    }

    /**
     * 统计指定知识库的 chunk 数，给提示文案使用。
     */
    private function countChunks(int $knowledgeBaseId): int
    {
        return KnowledgeChunk::query()->where('knowledge_base_id', $knowledgeBaseId)->count();
    }

    /**
     * 保存上传知识文件到本地路径。
     */
    private function storeUploadedKnowledgeFile(UploadedFile $file): string
    {
        $relativeDirectory = 'uploads/knowledge';
        $extension = strtolower($file->getClientOriginalExtension() ?: 'txt');
        $filename = uniqid('', true).'.'.$extension;
        $relativePath = Storage::disk('local')->putFileAs($relativeDirectory, $file, $filename);
        if (! is_string($relativePath) || $relativePath === '') {
            throw new \RuntimeException(__('admin.knowledge_bases.message.upload_failed'));
        }

        return $relativePath;
    }

    /**
     * @return array{content:string,file_type:string}
     */
    private function parseUploadedKnowledgeFile(string $absolutePath, string $originalName): array
    {
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === 'txt' || $extension === 'md') {
            $raw = @file_get_contents($absolutePath);
            if ($raw === false) {
                throw new \RuntimeException(__('admin.knowledge_bases.message.upload_failed'));
            }

            $content = $this->normalizeKnowledgeText($this->convertUploadedTextToUtf8($raw));
            if ($content === '') {
                throw new \RuntimeException(__('admin.knowledge_bases.error.content_required'));
            }

            return [
                'content' => $content,
                'file_type' => $extension === 'md' ? 'markdown' : 'text',
            ];
        }

        if ($extension === 'docx') {
            $content = $this->extractDocxContent($absolutePath);
            if ($content === '') {
                throw new \RuntimeException(__('admin.knowledge_bases.error.file_type_invalid'));
            }

            return [
                'content' => $content,
                'file_type' => 'word',
            ];
        }

        throw new \RuntimeException(__('admin.knowledge_bases.error.file_type_invalid'));
    }

    /**
     * 清理上传失败或删除后的知识文件。
     */
    private function cleanupKnowledgeFile(string $relativePath): void
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '') {
            return;
        }

        // 兼容新旧两种路径：优先 Laravel local 磁盘，相对旧数据再回退到项目根目录删除。
        if (Storage::disk('local')->exists($relativePath)) {
            Storage::disk('local')->delete($relativePath);

            return;
        }

        $absolutePath = base_path(ltrim($relativePath, '/'));
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    /**
     * 将文本转换为 UTF-8，兼容上传文件编码差异。
     */
    private function convertUploadedTextToUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $detectedEncoding = mb_detect_encoding($text, ['UTF-8', 'GB18030', 'GBK', 'BIG5', 'UTF-16LE', 'UTF-16BE'], true);
        if (! $detectedEncoding || strtoupper($detectedEncoding) === 'UTF-8') {
            return $text;
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', $detectedEncoding);

        return $converted === false ? $text : $converted;
    }

    /**
     * 统一知识文本换行与空白，提升分块稳定性。
     */
    private function normalizeKnowledgeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text);
        $text = preg_replace('/[ \t]{2,}/u', ' ', (string) $text);

        return trim((string) $text);
    }

    /**
     * 从 docx 提取正文（优先 ZipArchive，失败时降级为空字符串）。
     */
    private function extractDocxContent(string $absolutePath): string
    {
        if (! class_exists('ZipArchive')) {
            return '';
        }

        $zip = new \ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            return '';
        }

        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();
        if (! is_string($xmlContent) || $xmlContent === '') {
            return '';
        }

        $dom = new \DOMDocument;
        $loaded = @$dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (! $loaded) {
            return '';
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $parts = [];
        $nodes = $xpath->query('//w:t');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $value = trim((string) $node->textContent);
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return $this->normalizeKnowledgeText(implode("\n", $parts));
    }
}
