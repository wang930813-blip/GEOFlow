<div id="mainContent" class="cg-shell cg-page">
    <section class="cg-page-hero">
        <span class="cg-eyebrow">{{ app()->getLocale() === 'zh_CN' ? '栏目' : 'Category' }}</span>
        <h1>{{ $category->name }}</h1>
        <p>{{ trim((string) $category->description) !== '' ? $category->description : $pageDescription }}</p>
    </section>

    <section class="cg-section">
        <div class="cg-list-stack">
            @forelse($articles as $article)
                @include('theme.corporate-growth-20260914.partials.article-card', ['article' => $article])
            @empty
                <div class="cg-empty">{{ __('site.home_empty_title') }}</div>
            @endforelse
        </div>
        <div class="cg-pagination">{{ $articles->links() }}</div>
    </section>
</div>
