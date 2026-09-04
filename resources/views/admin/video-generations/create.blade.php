@extends('admin.layouts.app')

@section('content')
    @php
        $keywordLibraries = $keywordLibraries ?? collect();
        $knowledgeBases = $knowledgeBases ?? collect();
    @endphp

    <div class="mx-auto max-w-5xl space-y-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">创建生成视频</h1>
                <p class="mt-1 text-sm text-gray-600">先生成视频主题和脚本草稿，确认后提交进入原有视频生成流程。</p>
            </div>
            <a href="{{ route('admin.video-generations.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                返回列表
            </a>
        </div>

        <section
            id="video-draft-panel"
            class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm"
            data-topic-url="{{ route('admin.video-generations.topic-candidates', [], false) }}"
            data-script-url="{{ route('admin.video-generations.script-draft', [], false) }}"
        >
            <div class="flex flex-col gap-3 border-b border-slate-100 pb-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">自动生成视频主题</h2>
                    <p class="mt-1 text-sm text-slate-500">系统随机抽取关键词，并结合知识库生成 6 种抖音 GEO 短视频主题。</p>
                </div>
                <button type="button" id="generate-video-topics" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300">
                    <i data-lucide="sparkles" class="h-4 w-4"></i>
                    生成主题
                </button>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">关键词库</span>
                    <select name="keyword_library_id" id="video-draft-keyword-library" class="mt-1 block h-11 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="">请选择关键词库</option>
                        @foreach($keywordLibraries as $library)
                            @php
                                $count = (int) ($library->actual_keyword_count ?? $library->keyword_count ?? 0);
                            @endphp
                            <option value="{{ (int) $library->id }}">{{ $library->name }}{{ $count > 0 ? '（'.$count.'个关键词）' : '' }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">知识库</span>
                    <select name="knowledge_base_id" id="video-draft-knowledge-base" class="mt-1 block h-11 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="">不指定知识库</option>
                        @foreach($knowledgeBases as $knowledgeBase)
                            <option value="{{ (int) $knowledgeBase->id }}">{{ $knowledgeBase->name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div id="video-draft-message" class="mt-4 hidden rounded-md px-3 py-2 text-sm"></div>
            <div id="video-topic-candidates" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3"></div>
        </section>

        <form method="POST" action="{{ route('admin.video-generations.store') }}" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            @csrf
            <div class="grid grid-cols-1 gap-5">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">视频主题</span>
                    <input id="video-subject" name="subject" value="{{ old('subject') }}" required maxlength="500" class="mt-1 block h-11 w-full rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="例如：企业团险服务商怎么选才靠谱">
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">视频脚本</span>
                    <textarea id="video-script" name="script" rows="8" class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm leading-6 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="可选，可由上方自动生成后继续手动调整">{{ old('script') }}</textarea>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">封面图 URL</span>
                    <input name="cover_image" value="{{ old('cover_image') }}" maxlength="1000" class="mt-1 block h-11 w-full rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="发布到视频平台前可补充封面图 URL">
                </label>

                <div class="flex justify-end gap-3 border-t border-slate-100 pt-5">
                    <a href="{{ route('admin.video-generations.index') }}" class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">取消</a>
                    <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">
                        <i data-lucide="sparkles" class="h-4 w-4"></i>
                        提交生成
                    </button>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const panel = document.getElementById('video-draft-panel');
            if (!panel) return;

            const topicUrl = panel.dataset.topicUrl;
            const scriptUrl = panel.dataset.scriptUrl;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || @js(csrf_token());
            const keywordSelect = document.getElementById('video-draft-keyword-library');
            const knowledgeSelect = document.getElementById('video-draft-knowledge-base');
            const generateButton = document.getElementById('generate-video-topics');
            const messageBox = document.getElementById('video-draft-message');
            const candidatesBox = document.getElementById('video-topic-candidates');
            const subjectInput = document.getElementById('video-subject');
            const scriptInput = document.getElementById('video-script');
            let currentKeyword = '';

            const setMessage = (message, type = 'info') => {
                messageBox.textContent = message;
                messageBox.className = 'mt-4 rounded-md px-3 py-2 text-sm ' + (
                    type === 'error'
                        ? 'bg-red-50 text-red-700'
                        : 'bg-slate-50 text-slate-600'
                );
            };

            const clearMessage = () => {
                messageBox.textContent = '';
                messageBox.className = 'mt-4 hidden rounded-md px-3 py-2 text-sm';
            };

            const postJson = async (url, payload) => {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(payload),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || data.success === false) {
                    throw new Error(data.message || '自动生成失败，请稍后重试');
                }

                return data.data || {};
            };

            const renderCandidates = (data) => {
                currentKeyword = data.keyword || '';
                candidatesBox.innerHTML = '';
                (data.candidates || []).forEach((candidate) => {
                    const card = document.createElement('article');
                    card.className = 'flex min-h-36 flex-col justify-between rounded-lg border border-slate-200 bg-slate-50 p-4';

                    const body = document.createElement('div');
                    const badge = document.createElement('div');
                    badge.className = 'inline-flex rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600 ring-1 ring-slate-200';
                    badge.textContent = candidate.style_label || '主题';
                    const title = document.createElement('h3');
                    title.className = 'mt-3 text-base font-semibold leading-6 text-slate-900';
                    title.textContent = candidate.subject || '';
                    body.appendChild(badge);
                    body.appendChild(title);

                    const actions = document.createElement('div');
                    actions.className = 'mt-4 flex gap-2';
                    const useButton = document.createElement('button');
                    useButton.type = 'button';
                    useButton.className = 'use-topic inline-flex h-9 flex-1 items-center justify-center rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-white/80';
                    useButton.textContent = '使用主题';
                    const scriptButton = document.createElement('button');
                    scriptButton.type = 'button';
                    scriptButton.className = 'generate-script inline-flex h-9 flex-1 items-center justify-center rounded-md bg-indigo-600 px-3 text-sm font-medium text-white hover:bg-indigo-700';
                    scriptButton.textContent = '生成脚本';
                    actions.appendChild(useButton);
                    actions.appendChild(scriptButton);
                    card.appendChild(body);
                    card.appendChild(actions);

                    useButton.addEventListener('click', () => {
                        subjectInput.value = candidate.subject || '';
                        subjectInput.focus();
                    });
                    scriptButton.addEventListener('click', async (event) => {
                        const button = event.currentTarget;
                        button.disabled = true;
                        button.textContent = '生成中...';
                        clearMessage();
                        try {
                            subjectInput.value = candidate.subject || '';
                            const result = await postJson(scriptUrl, {
                                keyword_library_id: keywordSelect.value || null,
                                knowledge_base_id: knowledgeSelect.value || null,
                                keyword: currentKeyword,
                                style: candidate.style || 'question',
                                subject: candidate.subject || '',
                            });
                            subjectInput.value = result.subject || candidate.subject || '';
                            scriptInput.value = result.script || '';
                            scriptInput.focus();
                        } catch (error) {
                            setMessage(error.message || '视频脚本自动生成失败', 'error');
                        } finally {
                            button.disabled = false;
                            button.textContent = '生成脚本';
                        }
                    });
                    candidatesBox.appendChild(card);
                });
            };

            generateButton?.addEventListener('click', async () => {
                if (!keywordSelect.value) {
                    setMessage('请先选择关键词库', 'error');
                    return;
                }

                generateButton.disabled = true;
                generateButton.innerHTML = '<i data-lucide="loader-2" class="h-4 w-4"></i>生成中...';
                candidatesBox.innerHTML = '';
                clearMessage();

                try {
                    const data = await postJson(topicUrl, {
                        keyword_library_id: keywordSelect.value,
                        knowledge_base_id: knowledgeSelect.value || null,
                    });
                    renderCandidates(data);
                    setMessage(`已随机抽取关键词：${data.keyword || '-'}`);
                    window.lucide?.createIcons();
                } catch (error) {
                    setMessage(error.message || '视频主题自动生成失败', 'error');
                } finally {
                    generateButton.disabled = false;
                    generateButton.innerHTML = '<i data-lucide="sparkles" class="h-4 w-4"></i>生成主题';
                    window.lucide?.createIcons();
                }
            });
        })();
    </script>
@endpush
