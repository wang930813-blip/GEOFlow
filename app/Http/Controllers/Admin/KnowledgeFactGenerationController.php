<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AiModelAccessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeFacts\KnowledgeFactGenerationRequest;
use App\Models\Admin;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\KnowledgeFactLibrary;
use App\Services\AiWorkspace\SystemKnowledgeBaseManager;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactGenerationCoordinator;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactLibraryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class KnowledgeFactGenerationController extends Controller
{
    public function __construct(private readonly SystemKnowledgeBaseManager $systemKnowledgeBases) {}

    public function store(
        KnowledgeFactGenerationRequest $request,
        int $knowledgeBaseId,
        KnowledgeFactGenerationCoordinator $coordinator,
        KnowledgeFactLibraryPresenter $presenter,
    ): JsonResponse|RedirectResponse {
        $data = $request->validated();
        try {
            $run = $coordinator->start(
                $this->library($knowledgeBaseId),
                (int) $data['ai_model_id'],
                $this->admin($request),
                $data['mode'],
                (int) $data['target_count'],
                $data['request_key'],
            );
        } catch (AiModelAccessException $exception) {
            return $request->expectsJson()
                ? response()->json(['error' => ['code' => $exception->getErrorCode()]], 404)
                : back()->withInput()->withErrors(['ai_model_id' => $exception->getErrorCode()]);
        }

        return $request->expectsJson()
            ? response()->json(['data' => ['run' => $presenter->generationRun($run, $knowledgeBaseId)]], 202)
            : back()->with('message', __('admin.knowledge_facts.message.generation_started'));
    }

    public function show(KnowledgeFactGenerationRequest $request, int $knowledgeBaseId, int $runId, KnowledgeFactLibraryPresenter $presenter): JsonResponse
    {
        return response()->json(['data' => ['run' => $presenter->generationRun($this->run($knowledgeBaseId, $runId), $knowledgeBaseId)]]);
    }

    public function cancel(KnowledgeFactGenerationRequest $request, int $knowledgeBaseId, int $runId, KnowledgeFactGenerationCoordinator $coordinator, KnowledgeFactLibraryPresenter $presenter): JsonResponse|RedirectResponse
    {
        $run = $this->run($knowledgeBaseId, $runId);
        $coordinator->cancel($run);

        return $request->expectsJson() ? response()->json(['data' => ['run' => $presenter->generationRun($run->fresh(), $knowledgeBaseId)]], 202) : back()->with('message', __('admin.knowledge_facts.message.generation_cancelled'));
    }

    public function resolve(KnowledgeFactGenerationRequest $request, int $knowledgeBaseId, int $runId, KnowledgeFactGenerationCoordinator $coordinator): JsonResponse|RedirectResponse
    {
        $run = $this->run($knowledgeBaseId, $runId);
        $data = $request->validated();
        $run = $coordinator->resolveConflict($run->id, $data['candidate_key'], $data['action'], $data['stable_key'] ?? null, $this->admin($request));

        return $request->expectsJson() ? response()->json(['data' => ['run' => $run]]) : back()->with('message', __('admin.knowledge_facts.message.conflict_resolved'));
    }

    private function library(int $knowledgeBaseId): KnowledgeFactLibrary
    {
        return $this->knowledgeBase($knowledgeBaseId)->factLibrary()->firstOrCreate([]);
    }

    private function run(int $knowledgeBaseId, int $runId): KnowledgeFactGenerationRun
    {
        return $this->knowledgeBase($knowledgeBaseId)->factLibrary()->firstOrFail()->generationRuns()->whereKey($runId)->firstOrFail();
    }

    private function knowledgeBase(int $knowledgeBaseId): KnowledgeBase
    {
        $knowledgeBase = $this->systemKnowledgeBases->findVisibleKnowledgeBase($knowledgeBaseId);
        abort_unless($knowledgeBase instanceof KnowledgeBase, 404);
        if ($knowledgeBase->isSystemManaged()) {
            $admin = request()->user('admin');
            abort_unless($admin instanceof Admin && $admin->canManageProtectedWorkflows(), 403);
        }

        return $knowledgeBase;
    }

    private function admin(KnowledgeFactGenerationRequest $request): Admin
    { /** @var Admin $admin */ $admin = $request->user('admin');

        return $admin;
    }
}
