@extends('theme.template01.layout')

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaArticles = is_object($articles ?? null) && method_exists($articles, 'getCollection')
            ? $articles->getCollection()
            : collect($articles ?? []);
        $schemaItems = [];

        foreach ($schemaArticles->take(10) as $schemaArticle) {
            $schemaItems[] = [
                $schemaAtType => 'ListItem',
                'position' => count($schemaItems) + 1,
                'url' => route('site.article', $schemaArticle->slug),
                'name' => $schemaArticle->title,
            ];
        }

        $collectionSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'CollectionPage',
            'name' => $pageTitle ?? $siteTitle ?? $siteName ?? config('app.name'),
            'description' => $pageDescription ?? $siteDescription ?? '',
            'url' => $canonicalUrl ?? route('site.home'),
            'mainEntity' => [
                $schemaAtType => 'ItemList',
                'itemListElement' => $schemaItems,
            ],
        ];
    @endphp
    <x-json-ld :data="$collectionSchema" />
@endpush

@section('body')
    @php
        $siteSettingsMap = \App\Support\Site\SiteSettingsBag::all();
        $homeArticles = is_object($articles ?? null) && method_exists($articles, 'getCollection')
            ? $articles->getCollection()
            : collect($articles ?? []);
        $featuredCollection = collect($featuredArticles ?? []);
        $hotCollection = collect($hotArticles ?? []);
        $navCategoryCollection = collect($navCategories ?? []);
        $projectArticles = $featuredCollection->merge($hotCollection)->merge($homeArticles)->unique('id')->take(2)->values();
        $insightArticles = $featuredCollection->merge($homeArticles)->unique('id')->take(3)->values();
        $serviceCategories = $navCategoryCollection->take(3)->values();
        $isDefaultTemplateHome = (bool) ($isDefaultHome ?? false);
        $siteTitleText = trim((string) ($siteTitle ?? $siteName ?? config('geoflow.site_name', config('app.name'))));
        $siteSubtitleText = trim((string) ($siteSubtitle ?? ''));
        $siteDescriptionText = trim((string) ($siteDescription ?? ''));
        $siteRemarkText = trim((string) ($siteRemark ?? ($siteSettingsMap['site_remark'] ?? '')));
        $heroPositioning = $siteSubtitleText !== '' ? $siteSubtitleText : 'AI-ready official website template';
        $heroLead = $siteDescriptionText !== ''
            ? $siteDescriptionText
            : 'Build a clear, credible and AI-ready official website experience for every visitor, search engine and answer engine.';
        $heroSlide = collect($homepageCarouselSlides ?? [])->first();
        $heroImage = trim((string) data_get($heroSlide, 'image_url', ''));
        $isAbsoluteAsset = static fn (string $path): bool => preg_match('/^(https?:)?\/\//', $path) === 1 || str_starts_with($path, '/');
        $publicAsset = static fn (string $path): string => $isAbsoluteAsset($path) ? $path : asset($path);
        $themeAsset = static fn (string $path): string => asset('themes/template01/'.$path);
        $heroImage = $heroImage !== '' ? $publicAsset($heroImage) : $themeAsset('assets/reference/hero-network.png');
        $contactInfoText = (string) ($contactInfo ?? ($siteSettingsMap['contact_info'] ?? ''));
        $contactLines = collect(preg_split('/\r\n|\r|\n/', $contactInfoText) ?: [])
            ->map(static fn (string $line): string => trim($line))
            ->filter()
            ->values();
        $companyAddressText = trim((string) ($companyAddress ?? ($siteSettingsMap['company_address'] ?? '')));
        $footerCopy = trim((string) ($footerCopyright ?? ($siteSettingsMap['copyright_info'] ?? '')));
        $processImages = [
            $themeAsset('assets/reference/process-discover.png'),
            $themeAsset('assets/reference/hero-orbit.png'),
            $themeAsset('assets/reference/case-team.jpg'),
            $themeAsset('assets/reference/process-deliver.png'),
        ];
        $fallbackServices = collect([
            ['title' => 'Brand Narrative', 'description' => 'Clarify who you are, what you provide and why the market should trust your answer.'],
            ['title' => 'Content Architecture', 'description' => 'Organize services, articles and proof points into a structure that is easy to browse and cite.'],
            ['title' => 'Conversion Path', 'description' => 'Connect homepage intent, contact information and content routes into a coherent journey.'],
        ]);
        $serviceCards = $serviceCategories->isNotEmpty()
            ? $serviceCategories->map(static fn ($category): array => [
                'title' => (string) $category->name,
                'description' => trim((string) $category->description) !== ''
                    ? (string) $category->description
                    : 'A focused content section connected to the site article library.',
            ])->values()
            : $fallbackServices;
    @endphp

    <div class="preloader" data-preloader aria-hidden="true">
        <div class="preloader-mark">
            <span class="brand-mark"><span></span><span></span><span></span></span>
            <span>{{ $siteTitleText }}</span>
        </div>
        <span class="preloader-line"></span>
    </div>
    <div class="site-cursor" data-cursor aria-hidden="true"><span data-cursor-text></span></div>
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <header class="site-header" data-header>
        <div class="container header-inner">
            <a class="brand" href="#top" aria-label="{{ $siteTitleText }} home">
                <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                <span>{{ $siteTitleText }}</span>
            </a>
            <nav class="desktop-nav" aria-label="Primary navigation">
                <a href="#services">Services</a>
                <a href="#projects">Projects</a>
                <a href="#process">Process</a>
                <a href="#about">About</a>
                <a href="#insights">Insights</a>
                <a href="#contact">Contact</a>
            </nav>
            <div class="header-actions">
                <button class="icon-button theme-toggle" type="button" data-theme-toggle aria-label="Toggle theme" title="Toggle theme">
                    <svg class="icon icon-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42"></path></svg>
                    <svg class="icon icon-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 15.2A8.6 8.6 0 0 1 8.8 3.5 8.7 8.7 0 1 0 20.5 15.2Z"></path></svg>
                </button>
                <span class="magnetic-wrap"><a class="button button-small button-outline header-cta" data-magnetic href="#services">Explore</a></span>
                <button class="icon-button menu-toggle" type="button" data-menu-toggle aria-expanded="false" aria-controls="mobile-menu" aria-label="Open menu" title="Open menu">
                    <svg class="icon menu-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
                    <svg class="icon menu-close" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
                </button>
            </div>
        </div>
    </header>

    <div class="menu-backdrop" data-menu-backdrop aria-hidden="true"></div>
    <nav class="mobile-nav" id="mobile-menu" data-mobile-menu aria-hidden="true" aria-label="Mobile navigation" tabindex="-1">
        <div class="mobile-nav-head">
            <span>Menu</span>
            <button class="icon-button mobile-close" type="button" data-menu-close aria-label="Close menu" title="Close menu">
                <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </div>
        <a href="#services">Services <span>01</span></a>
        <a href="#projects">Projects <span>02</span></a>
        <a href="#process">Process <span>03</span></a>
        <a href="#about">About <span>04</span></a>
        <a href="#insights">Insights <span>05</span></a>
        <a href="#contact">Contact <span>06</span></a>
        <a class="button button-primary" href="#contact">Start a conversation <span aria-hidden="true">↗</span></a>
    </nav>

    <main id="main-content">
        @if($isDefaultTemplateHome)
            <section class="hero section-dark" id="top" aria-labelledby="hero-title" data-hero>
                <div class="hero-grid container">
                    <div class="hero-copy reveal">
                        <p class="eyebrow"><span class="eyebrow-dot"></span>Brand strategy / Official website / AI visibility</p>
                        <h1 id="hero-title">
                            <span class="headline-line" data-text-animate>{{ $siteTitleText }}</span>
                            <em class="headline-line" data-text-animate>{{ $heroPositioning }}</em>
                        </h1>
                        <p class="hero-lead">{{ $heroLead }}</p>
                        <div class="hero-actions">
                            <span class="magnetic-wrap"><a class="button button-primary" data-magnetic href="#services">Explore services <span aria-hidden="true">↗</span></a></span>
                            <a class="text-link" href="#projects">View proof <span aria-hidden="true">↓</span></a>
                        </div>
                        <div class="hero-proof" aria-label="Brand proof">
                            <div><strong>01</strong><span>Clear narrative</span></div>
                            <div><strong>02</strong><span>Structured content</span></div>
                            <div><strong>03</strong><span>Reliable contact path</span></div>
                        </div>
                    </div>

                    <div class="hero-visual reveal reveal-delay-1" data-hero-visual>
                        <div class="hero-orbit" aria-hidden="true" data-parallax="0.12"></div>
                        <figure class="hero-image-frame" data-parallax="0.05">
                            <img src="{{ $heroImage }}" width="800" height="1200" alt="{{ $siteTitleText }} official website visual" fetchpriority="high">
                        </figure>
                        <div class="signal-card signal-card-top" data-parallax="0.22">
                            <span class="signal-icon" aria-hidden="true">↗</span>
                            <span><b>Core focus</b><small>{{ $serviceCards->first()['title'] ?? 'Brand system' }}</small></span>
                        </div>
                        <div class="signal-card signal-card-bottom" data-parallax="0.34">
                            <span class="signal-pulse" aria-hidden="true"></span>
                            <span><b>Content engine</b><small>{{ $homeArticles->count() }} published items</small></span>
                        </div>
                        <span class="visual-index" aria-hidden="true">TEMPLATE / 01</span>
                    </div>
                </div>
                <div class="hero-footer container">
                    <p>{{ $siteRemarkText !== '' ? $siteRemarkText : 'Start with a clear homepage and let every section support trust, discovery and conversion.' }}</p>
                    <a href="#services" class="round-link" aria-label="View services"><span aria-hidden="true">↓</span></a>
                </div>
            </section>

            <section class="marquee" aria-label="Business directions" data-marquee>
                <div class="marquee-track" data-marquee-track>
                    @for($group = 0; $group < 2; $group++)
                        <div class="marquee-group" @if($group === 1) aria-hidden="true" @endif>
                            @forelse($navCategoryCollection->take(5) as $navCategory)
                                <span>{{ $navCategory->name }}</span><i>✦</i>
                            @empty
                                <span>Professional services</span><i>✦</i><span>AI visibility</span><i>✦</i><span>Content strategy</span><i>✦</i><span>Project delivery</span><i>✦</i><span>Long-term support</span><i>✦</i>
                            @endforelse
                        </div>
                    @endfor
                </div>
            </section>

            <section class="section section-light" id="services" aria-labelledby="services-title">
                <div class="container">
                    <div class="section-heading reveal">
                        <p class="eyebrow eyebrow-dark"><span class="eyebrow-dot"></span>Services</p>
                        <h2 id="services-title"><span data-text-animate>Shape a clearer</span><br><span>official website story</span></h2>
                        <p>Use the homepage to explain the business, organize content and guide visitors to the next conversation.</p>
                    </div>
                    <div class="service-grid">
                        @foreach($serviceCards as $service)
                            <article class="service-card @if($loop->first) service-card-featured @endif reveal @if(!$loop->first) reveal-delay-{{ min($loop->index, 2) }} @endif" data-pointer-card>
                                <div class="card-number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} / {{ str_pad((string) $serviceCards->count(), 2, '0', STR_PAD_LEFT) }}</div>
                                <div class="service-icon service-icon-{{ ['blue', 'cyan', 'coral'][$loop->index % 3] }}" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</div>
                                <h3>{{ $service['title'] }}</h3>
                                <p>{{ $service['description'] }}</p>
                                <span class="panel-meta"><span class="status-dot"></span>Available on homepage</span>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="section section-ink projects-section" id="projects" aria-labelledby="projects-title">
                <div class="container">
                    <div class="section-heading split-heading split-heading-ink reveal">
                        <div>
                            <p class="eyebrow"><span class="eyebrow-dot"></span>Projects</p>
                            <h2 id="projects-title"><span data-text-animate>Show proof,</span><br><em>not just claims</em></h2>
                        </div>
                        <p>{{ $projectArticles->isNotEmpty() ? 'Featured content from the site is used as proof material.' : 'Template preview keeps visual project cards as a base style reference.' }}</p>
                    </div>
                    <div class="project-grid">
                        @forelse($projectArticles as $article)
                            <a class="project-card @if($loop->even) project-card-offset @endif reveal @if(!$loop->first) reveal-delay-1 @endif" href="{{ route('site.article', $article->slug) }}" data-cursor-label="Open" tabindex="0">
                                <div class="distort-image" aria-hidden="true">
                                    <img class="distort-image-back" src="{{ $themeAsset($loop->first ? 'assets/reference/case-dashboard.jpg' : 'assets/reference/case-team.jpg') }}" width="1000" height="700" loading="lazy" alt="">
                                    <img class="distort-image-front" src="{{ $themeAsset($loop->first ? 'assets/reference/case-dashboard.jpg' : 'assets/reference/case-team.jpg') }}" width="1000" height="700" loading="lazy" alt="">
                                </div>
                                <div class="project-meta"><span>{{ $article->category?->name ?? 'Insight' }}</span><span>{{ $article->title }}</span></div>
                            </a>
                        @empty
                            <article class="project-card reveal" data-cursor-label="Project" tabindex="0">
                                <div class="distort-image" aria-hidden="true">
                                    <img class="distort-image-back" src="{{ $themeAsset('assets/reference/case-dashboard.jpg') }}" width="1000" height="700" loading="lazy" alt="">
                                    <img class="distort-image-front" src="{{ $themeAsset('assets/reference/case-dashboard.jpg') }}" width="1000" height="700" loading="lazy" alt="">
                                </div>
                                <div class="project-meta"><span>Website system</span><span>Homepage strategy and content structure</span></div>
                            </article>
                            <article class="project-card project-card-offset reveal reveal-delay-1" data-cursor-label="Project" tabindex="0">
                                <div class="distort-image" aria-hidden="true">
                                    <img class="distort-image-back" src="{{ $themeAsset('assets/reference/case-team.jpg') }}" width="1000" height="700" loading="lazy" alt="">
                                    <img class="distort-image-front" src="{{ $themeAsset('assets/reference/case-team.jpg') }}" width="1000" height="700" loading="lazy" alt="">
                                </div>
                                <div class="project-meta"><span>Growth collaboration</span><span>Cross-team publishing and contact path</span></div>
                            </article>
                        @endforelse
                    </div>
                </div>
            </section>

            <section class="section section-light method-section" id="process" aria-labelledby="process-title">
                <div class="container">
                    <div class="section-heading method-heading reveal">
                        <p class="eyebrow eyebrow-dark"><span class="eyebrow-dot"></span>Process</p>
                        <h2 id="process-title"><span data-text-animate>Move from intent</span><br><span>to reliable delivery</span></h2>
                        <p>A practical homepage should make the cooperation path visible before the first conversation starts.</p>
                    </div>
                    <div class="method-layout">
                        <ol class="method-list" aria-label="Process steps">
                            @foreach([
                                ['Discovery', 'Clarify market, audience, value proposition and content baseline.'],
                                ['Architecture', 'Plan sections, navigation, article routes and conversion points.'],
                                ['Production', 'Publish pages and insights with stable visual hierarchy.'],
                                ['Iteration', 'Improve content based on search, AI visibility and user feedback.'],
                            ] as $step)
                                <li class="method-item @if($loop->first) is-active @endif reveal @if(!$loop->first) reveal-delay-{{ min($loop->index, 3) }} @endif">
                                    <button type="button" data-process-step data-image="{{ $processImages[$loop->index] }}" data-alt="{{ $step[0] }}" data-caption="{{ $step[0] }}" @if($loop->first) aria-current="step" @endif>
                                        <span class="method-count">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                        <span><b>{{ $step[0] }}</b><small>{{ $step[1] }}</small></span>
                                        <span class="method-arrow" aria-hidden="true">↗</span>
                                    </button>
                                </li>
                            @endforeach
                        </ol>
                        <figure class="method-visual reveal reveal-delay-1" data-process-panel data-process-visual>
                            <div class="method-image-wrap"><img data-process-image src="{{ $processImages[0] }}" width="800" height="800" loading="lazy" alt="Discovery"></div>
                            <figcaption><span data-process-index>PROCESS / 01</span><span data-process-caption>Discovery</span></figcaption>
                        </figure>
                    </div>
                </div>
            </section>

            <section class="section section-blue about-section" id="about" aria-labelledby="about-title">
                <div class="container about-grid">
                    <div class="about-label reveal"><span class="vertical-label">ABOUT / BRAND</span></div>
                    <div class="about-copy reveal reveal-delay-1">
                        <p class="eyebrow"><span class="eyebrow-dot"></span>About</p>
                        <h2 id="about-title"><span data-text-animate>Make the brand</span><br><em>easy to understand</em></h2>
                        <p>{{ $siteDescriptionText !== '' ? $siteDescriptionText : 'This template gives the official website a clear brand introduction area, ready for company background, service positioning and trust signals.' }}</p>
                        <div class="principle-row"><span>01</span><p>Brand profile</p><span>{{ $siteTitleText }}</span></div>
                        <div class="principle-row"><span>02</span><p>Content library</p><span>{{ $homeArticles->count() }} items</span></div>
                        <div class="principle-row"><span>03</span><p>Contact channel</p><span>{{ $contactLines->isNotEmpty() || $companyAddressText !== '' ? 'Configured' : 'Ready' }}</span></div>
                    </div>
                </div>
            </section>

            <section class="section section-ink articles-section" id="insights" aria-labelledby="insights-title">
                <div class="container">
                    <div class="section-heading split-heading split-heading-ink reveal">
                        <div>
                            <p class="eyebrow"><span class="eyebrow-dot"></span>Insights</p>
                            <h2 id="insights-title"><span data-text-animate>Keep publishing,</span><br><em>keep becoming citable</em></h2>
                        </div>
                        <p>{{ $insightArticles->isNotEmpty() ? 'Latest site articles are connected to this template automatically.' : 'Use the article library to turn website updates into structured insight pages.' }}</p>
                    </div>
                    @if($insightArticles->isNotEmpty())
                        <div class="service-grid">
                            @foreach($insightArticles as $article)
                                <article class="service-card reveal @if(!$loop->first) reveal-delay-{{ min($loop->index, 2) }} @endif" data-pointer-card>
                                    <div class="card-number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} / {{ str_pad((string) $insightArticles->count(), 2, '0', STR_PAD_LEFT) }}</div>
                                    <h3><a class="card-link" href="{{ route('site.article', $article->slug) }}">{{ $article->title }}</a></h3>
                                    <p>{{ $cardSummaries[$article->id] ?? $article->excerpt ?? str($article->content ?? '')->stripTags()->limit(120) }}</p>
                                    <a class="card-link" href="{{ route('site.article', $article->slug) }}">Read more <span aria-hidden="true">↗</span></a>
                                </article>
                            @endforeach
                        </div>
                    @else
                        <article class="content-empty reveal" data-pointer-card>
                            <div class="content-empty-visual" aria-hidden="true"><img src="{{ $themeAsset('assets/reference/hero-orbit.png') }}" width="800" height="800" loading="lazy" alt=""></div>
                            <div class="content-empty-copy">
                                <span class="panel-index">01</span>
                                <h3>Insight publishing area</h3>
                                <p>When articles are published, the homepage can surface selected views, news and evidence here.</p>
                            </div>
                        </article>
                    @endif
                </div>
            </section>

            <section class="section section-light faq-section" id="faq" aria-labelledby="faq-title">
                <div class="container faq-grid">
                    <div class="faq-intro reveal">
                        <p class="eyebrow eyebrow-dark"><span class="eyebrow-dot"></span>FAQ</p>
                        <h2 id="faq-title"><span data-text-animate>Questions visitors</span><br><span>often ask first</span></h2>
                        <p>Keep answers concise. The goal is to remove friction before a visitor reaches the contact section.</p>
                    </div>
                    <div class="faq-list reveal reveal-delay-1">
                        <details open>
                            <summary>What should this homepage explain?</summary>
                            <p>It should quickly explain the brand, services, evidence, process, content updates and contact path.</p>
                        </details>
                        <details>
                            <summary>Can the template use existing site articles?</summary>
                            <p>Yes. Published articles and categories are used as service, project and insight material where appropriate.</p>
                        </details>
                        <details>
                            <summary>Can it work as a preview template?</summary>
                            <p>Yes. When there is no business content yet, the static sample blocks remain useful for browsing the base visual style.</p>
                        </details>
                    </div>
                </div>
            </section>

            <section class="section section-cta" id="contact" aria-labelledby="contact-title">
                <span class="cta-ring cta-ring-one" aria-hidden="true"></span>
                <span class="cta-ring cta-ring-two" aria-hidden="true"></span>
                <div class="container cta-inner reveal">
                    <div>
                        <p class="eyebrow"><span class="eyebrow-dot"></span>Contact</p>
                        <h2 id="contact-title"><span data-text-animate>Start with</span><br><em>a clear conversation</em></h2>
                    </div>
                    <div class="cta-side">
                        <p>{{ $siteRemarkText !== '' ? $siteRemarkText : 'Share your context, goals and current website challenges. A strong official website starts with clear information.' }}</p>
                        @forelse($contactLines->take(3) as $line)
                            <span class="empty-state"><span class="status-dot"></span>{{ $line }}</span>
                        @empty
                            <span class="empty-state"><span class="status-dot"></span>Contact information can be configured in site settings.</span>
                        @endforelse
                        @if($companyAddressText !== '')
                            <span class="empty-state"><span class="status-dot status-dot-cyan"></span>{{ $companyAddressText }}</span>
                        @endif
                        <span class="magnetic-wrap"><a class="button button-light" data-magnetic href="#top">Back to top <span aria-hidden="true">↑</span></a></span>
                    </div>
                </div>
            </section>
        @else
            <section class="hero section-dark" id="top" aria-labelledby="results-title" data-hero>
                <div class="container hero-copy reveal">
                    <p class="eyebrow"><span class="eyebrow-dot"></span>{{ $search !== '' ? 'Search results' : ($category ? 'Category' : 'Resource library') }}</p>
                    <h1 id="results-title">{{ $viewTitle }}</h1>
                    @if(trim((string) ($pageDescription ?? '')) !== '')
                        <p class="hero-lead">{{ $pageDescription }}</p>
                    @endif
                    <form method="get" action="{{ route('site.home') }}" class="hero-actions" role="search">
                        <input type="search" name="search" value="{{ $search }}" placeholder="{{ __('site.search_placeholder') }}" aria-label="{{ __('site.search_placeholder') }}">
                        <button class="button button-primary" type="submit">{{ __('site.search_button') }}</button>
                    </form>
                </div>
            </section>

            <section class="section section-light" aria-labelledby="article-list-title">
                <div class="container">
                    <div class="section-heading reveal">
                        <p class="eyebrow eyebrow-dark"><span class="eyebrow-dot"></span>Articles</p>
                        <h2 id="article-list-title">{{ $viewTitle }}</h2>
                    </div>
                    @if($homeArticles->isEmpty())
                        <article class="service-card reveal" data-pointer-card>
                            <h3>{{ $search !== '' ? __('site.search_empty_title') : __('site.home_empty_title') }}</h3>
                            <p>{{ $search !== '' ? __('site.search_empty_desc') : __('site.home_empty_desc') }}</p>
                            <a class="card-link" href="{{ route('site.home') }}">{{ __('site.back_home') }} <span aria-hidden="true">↗</span></a>
                        </article>
                    @else
                        <div class="service-grid">
                            @foreach($homeArticles as $article)
                                <article class="service-card reveal" data-pointer-card>
                                    <div class="card-number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</div>
                                    <h3><a class="card-link" href="{{ route('site.article', $article->slug) }}">{{ $article->title }}</a></h3>
                                    <p>{{ $cardSummaries[$article->id] ?? $article->excerpt ?? str($article->content ?? '')->stripTags()->limit(120) }}</p>
                                    <a class="card-link" href="{{ route('site.article', $article->slug) }}">Read more <span aria-hidden="true">↗</span></a>
                                </article>
                            @endforeach
                        </div>
                        @if(is_object($articles ?? null) && method_exists($articles, 'hasPages') && $articles->hasPages())
                            <div class="section-heading">{{ $articles->onEachSide(1)->links() }}</div>
                        @endif
                    @endif
                </div>
            </section>
        @endif
    </main>

    <footer class="site-footer">
        <div class="container footer-main">
            <div class="footer-brand">
                <a class="brand" href="#top">
                    <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                    <span>{{ $siteTitleText }}</span>
                </a>
                <p>{{ $siteRemarkText !== '' ? $siteRemarkText : $heroLead }}</p>
            </div>
            <div class="footer-links">
                <div><span class="footer-label">Explore</span><a href="#services">Services</a><a href="#projects">Projects</a><a href="#process">Process</a></div>
                <div><span class="footer-label">Learn</span><a href="#about">About</a><a href="#insights">Insights</a><a href="#faq">FAQ</a></div>
                <div>
                    <span class="footer-label">Information</span>
                    @forelse($contactLines->take(2) as $line)
                        <span>{{ $line }}</span>
                    @empty
                        <span>{{ $siteTitleText }}</span>
                    @endforelse
                    @if($companyAddressText !== '')
                        <span>{{ $companyAddressText }}</span>
                    @endif
                </div>
            </div>
        </div>
        <div class="container footer-bottom">
            <span>© <span data-current-year>{{ now()->year }}</span> {{ $siteTitleText }}</span>
            <span>{{ $footerCopy !== '' ? $footerCopy : ($siteRemarkText !== '' ? $siteRemarkText : 'Official website template') }}</span>
            <a href="#top" aria-label="Back to top">Back to top ↑</a>
        </div>
    </footer>
    <button class="back-to-top" type="button" data-back-to-top aria-label="Back to top" title="Back to top"><span aria-hidden="true">↑</span></button>
@endsection
