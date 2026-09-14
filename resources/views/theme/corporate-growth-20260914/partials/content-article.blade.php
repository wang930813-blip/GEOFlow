<div id="mainContent" class="cg-shell cg-article-layout">
    <main class="cg-post">
        <nav class="cg-breadcrumb" aria-label="Breadcrumb">
            <a href="{{ route('site.home') }}">{{ __('front.nav.home') }}</a>
            @if($article->category)
                <span>/</span>
                <a href="{{ route('site.category', $article->category->slug) }}">{{ $article->category->name }}</a>
            @endif
        </nav>

        <article class="cg-post__body">
            <div class="cg-card-meta">
                @if($article->category)
                    <a href="{{ route('site.category', $article->category->slug) }}">{{ $article->category->name }}</a>
                @endif
                <time datetime="{{ ($article->published_at ?? $article->created_at)?->toAtomString() }}">
                    {{ ($article->published_at ?? $article->created_at)?->format('Y-m-d H:i') }}
                </time>
                @if($article->author)
                    <span>{{ $article->author->name }}</span>
                @endif
            </div>
            <h1>{{ $article->title }}</h1>
            @if($excerptPlain !== '')
                <p class="cg-post__excerpt">{{ $excerptPlain }}</p>
            @endif
            <div class="cg-prose">
                {!! $contentHtml !!}
            </div>
            @if(!empty($tags))
                <div class="cg-tags">
                    @foreach($tags as $tag)
                        <span>{{ $tag }}</span>
                    @endforeach
                </div>
            @endif
            @if($stickyAd)
                <section class="cg-cta cg-cta--article" data-ad-id="{{ $stickyAd['id'] }}">
                    <div>
                        @if($stickyAd['badge'] !== '')
                            <span class="cg-eyebrow">{{ $stickyAd['badge'] }}</span>
                        @endif
                        @if($stickyAd['title'] !== '')
                            <h2>{{ $stickyAd['title'] }}</h2>
                        @endif
                        <p>{{ $stickyAd['copy'] }}</p>
                    </div>
                    <a href="{{ $stickyAd['button_url'] }}" class="cg-button cg-button--primary">{{ $stickyAd['button_text'] }}</a>
                </section>
            @endif
        </article>
    </main>

    <aside class="cg-post-aside">
        <section class="cg-side-card">
            <span class="cg-eyebrow">{{ $siteTitle }}</span>
            <h2>{{ app()->getLocale() === 'zh_CN' ? '官网内容中心' : 'Official content hub' }}</h2>
            @if($siteDescription !== '')
                <p>{{ $siteDescription }}</p>
            @endif
            <a href="{{ route('site.contact') }}" class="cg-link">{{ app()->getLocale() === 'zh_CN' ? '联系咨询' : 'Contact us' }} <i data-lucide="arrow-right" aria-hidden="true"></i></a>
        </section>

        @if($relatedArticles->isNotEmpty())
            <section class="cg-side-card">
                <span class="cg-eyebrow">{{ __('site.article_related') }}</span>
                <div class="cg-related-list">
                    @foreach($relatedArticles as $related)
                        <a href="{{ route('site.article', $related->slug) }}">
                            <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <strong>{{ $related->title }}</strong>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </aside>
</div>
