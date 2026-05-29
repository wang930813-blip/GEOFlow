@php
    $currentAdmin = auth('admin')->user();
    $adminBrandName = $adminBrandName ?? \App\Support\AdminWeb::siteName();
    $isSuperAdmin = $currentAdmin && method_exists($currentAdmin, 'isSuperAdmin') && $currentAdmin->isSuperAdmin();
    $adminRoleLabel = $isSuperAdmin ? __('admin.header.super_admin') : __('admin.header.admin');
    $updateNotification = is_array($adminUpdateNotificationPayload ?? null) ? $adminUpdateNotificationPayload : [];
    $updateState = is_array($updateNotification['state'] ?? null) ? $updateNotification['state'] : [];
    $updateLinks = is_array($updateNotification['links'] ?? null) ? $updateNotification['links'] : [];
    $hasVersionUpdate = !empty($updateState['is_update_available']);
    $localeForChangelog = app()->getLocale() === 'en' ? 'en' : 'zh-CN';
    $updatePayload = is_array($updateState['payload'] ?? null) ? $updateState['payload'] : [];
    $updateSummary = (string) ($localeForChangelog === 'en'
        ? ($updatePayload['summary_en'] ?? '')
        : ($updatePayload['summary_zh'] ?? ''));
    $changelogLinks = is_array($updateLinks['changelog'] ?? null) ? $updateLinks['changelog'] : [];
    $notificationChangelogUrl = (string) ($changelogLinks[$localeForChangelog] ?? $changelogLinks['zh-CN'] ?? 'https://github.com/yaojingang/GEOFlow/blob/main/docs/CHANGELOG.md');
    $notificationGithubUrl = (string) ($updateLinks['github'] ?? 'https://github.com/yaojingang/GEOFlow');
    $currentSite = $currentSite ?? null;
    $availableSites = collect($availableSites ?? []);
    $menu = [
        'dashboard' => ['route' => 'admin.dashboard', 'name' => __('admin.nav.dashboard')],
        'geo_reports' => ['route' => 'admin.geo-reports.index', 'name' => 'GEO 报表'],
        'analytics' => ['route' => 'admin.analytics', 'name' => __('admin.nav.analytics')],
        'tasks' => ['route' => 'admin.tasks.index', 'name' => __('admin.nav.tasks')],
        'media_distribution' => ['route' => 'admin.media-distribution.resources.index', 'name' => '分发媒体'],
        'articles' => ['route' => 'admin.articles.index', 'name' => __('admin.nav.articles')],
        'materials' => ['route' => 'admin.materials.index', 'name' => __('admin.nav.materials')],
    ];
    $subMap = [
        'admin.geo-reports.index' => 'geo_reports',
        'admin.analytics' => 'analytics',
        'admin.media-distribution.resources.index' => 'media_distribution',
        'admin.media-distribution.resources.sync' => 'media_distribution',
        'admin.media-distribution.resources.price' => 'media_distribution',
        'admin.media-distribution.resources.site-price' => 'media_distribution',
        'admin.media-distribution.submissions.index' => 'media_distribution',
        'admin.media-distribution.submissions.export' => 'media_distribution',
        'admin.media-distribution.submissions.store' => 'media_distribution',
        'admin.media-distribution.submissions.bulk-store' => 'media_distribution',
        'admin.media-distribution.submissions.show' => 'media_distribution',
        'admin.media-distribution.submissions.sync' => 'media_distribution',
        'admin.media-distribution.submissions.cancel' => 'media_distribution',
        'admin.media-distribution.submissions.appeal' => 'media_distribution',
        'admin.media-distribution.credits.index' => 'media_distribution',
        'admin.media-distribution.credits.export' => 'media_distribution',
        'admin.media-distribution.credits.consumption-export' => 'media_distribution',
        'admin.media-distribution.credits.recharge' => 'media_distribution',
        'admin.media-distribution.credits.adjust' => 'media_distribution',
        'admin.media-distribution.settings.index' => 'media_distribution',
        'admin.media-distribution.settings.update' => 'media_distribution',
        'admin.media-distribution.reports.profit' => 'media_distribution',
        'admin.media-distribution.reports.profit-export' => 'media_distribution',
        'admin.tasks.create' => 'tasks',
        'admin.tasks.edit' => 'tasks',
        'admin.distribution.index' => 'distribution',
        'admin.distribution.create' => 'distribution',
        'admin.distribution.store' => 'distribution',
        'admin.distribution.edit' => 'distribution',
        'admin.distribution.update' => 'distribution',
        'admin.distribution.show' => 'distribution',
        'admin.distribution.jobs' => 'distribution',
        'admin.distribution.retry' => 'distribution',
        'admin.distribution.health' => 'distribution',
        'admin.distribution.pause' => 'distribution',
        'admin.distribution.activate' => 'distribution',
        'admin.distribution.rotate-secret' => 'distribution',
        'admin.articles.create' => 'articles',
        'admin.articles.edit' => 'articles',
        'admin.categories.index' => 'materials',
        'admin.categories.create' => 'materials',
        'admin.categories.edit' => 'materials',
        'admin.authors.index' => 'materials',
        'admin.authors.create' => 'materials',
        'admin.authors.edit' => 'materials',
        'admin.authors.detail' => 'materials',
        'admin.keyword-libraries.index' => 'materials',
        'admin.keyword-libraries.create' => 'materials',
        'admin.keyword-libraries.edit' => 'materials',
        'admin.keyword-libraries.detail' => 'materials',
        'admin.keyword-libraries.detail.update' => 'materials',
        'admin.keyword-libraries.keywords.store' => 'materials',
        'admin.keyword-libraries.keywords.delete' => 'materials',
        'admin.keyword-libraries.import' => 'materials',
        'admin.title-libraries.index' => 'materials',
        'admin.title-libraries.create' => 'materials',
        'admin.title-libraries.edit' => 'materials',
        'admin.title-libraries.detail' => 'materials',
        'admin.title-libraries.titles.store' => 'materials',
        'admin.title-libraries.titles.delete' => 'materials',
        'admin.title-libraries.import' => 'materials',
        'admin.title-libraries.ai-generate' => 'materials',
        'admin.title-libraries.ai-generate.submit' => 'materials',
        'admin.image-libraries.index' => 'materials',
        'admin.image-libraries.create' => 'materials',
        'admin.image-libraries.edit' => 'materials',
        'admin.image-libraries.detail' => 'materials',
        'admin.image-libraries.images.upload' => 'materials',
        'admin.image-libraries.images.delete' => 'materials',
        'admin.image-libraries.detail.update' => 'materials',
        'admin.knowledge-bases.index' => 'materials',
        'admin.knowledge-bases.create' => 'materials',
        'admin.knowledge-bases.edit' => 'materials',
        'admin.knowledge-bases.detail' => 'materials',
        'admin.knowledge-bases.upload' => 'materials',
        'admin.knowledge-bases.detail.update' => 'materials',
        'admin.url-import' => 'materials',
        'admin.ai-models.index' => 'ai_config',
        'admin.ai-prompts' => 'ai_config',
        'admin.site-settings.sensitive-words' => 'site_settings',
        'admin.site-settings.sensitive-words.store' => 'site_settings',
        'admin.site-settings.sensitive-words.delete' => 'site_settings',
        'admin.security-settings.index' => 'site_settings',
        'admin.security-settings.words.store' => 'site_settings',
        'admin.security-settings.words.delete' => 'site_settings',
        'admin.api-tokens.index' => 'admin_users',
        'admin.api-tokens.store' => 'admin_users',
        'admin.api-tokens.revoke' => 'admin_users',
        'admin.admin-activity-logs' => 'admin_users',
    ];
    $routeName = request()->route()?->getName();
    $resolvedActive = $activeMenu;
    if ($resolvedActive === '' && $routeName && isset($subMap[$routeName])) {
        $resolvedActive = $subMap[$routeName];
    }
@endphp
<nav class="admin-topbar">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center gap-3 lg:gap-4 min-w-0">
            <a href="{{ route('admin.dashboard') }}" class="admin-brand-mark shrink-0 inline-flex items-center gap-2 text-lg sm:text-xl font-semibold">
                <span class="admin-brand-icon flex h-8 w-8 items-center justify-center rounded-md">
                    <i data-lucide="radar" class="h-4 w-4"></i>
                </span>
                <span>{{ $adminBrandName }}</span>
            </a>
            <nav class="hidden md:flex flex-1 min-w-0 items-center">
                <div class="flex w-full min-w-0 items-center gap-3 lg:gap-5 overflow-x-auto overscroll-x-contain py-2 -my-2 [scrollbar-width:thin]">
                    @foreach ($menu as $key => $item)
                        <a href="{{ route($item['route']) }}"
                           class="admin-nav-link @if($resolvedActive === $key) is-active font-medium @endif inline-flex shrink-0 items-center whitespace-nowrap px-3 py-2 text-sm transition-colors duration-200">
                            {{ $item['name'] }}
                        </a>
                    @endforeach
                </div>
            </nav>
            <div class="flex shrink-0 items-center gap-2 sm:gap-3 ml-auto">
                @if($hasVersionUpdate)
                    <div class="relative">
                        <button onclick="toggleAdminNotifications()" class="relative rounded-md p-2 hover:bg-slate-100 transition-colors duration-200" type="button" aria-label="{{ __('admin.header.notifications.label') }}" title="{{ __('admin.header.notifications.label') }}">
                            <i data-lucide="bell" class="w-5 h-5"></i>
                            <span data-update-indicator class="absolute right-1.5 top-1.5 h-2.5 w-2.5 rounded-full bg-red-500 ring-2 ring-white"></span>
                        </button>

                        <div id="admin-notification-menu" class="admin-menu-panel hidden absolute right-0 mt-3 w-80 overflow-hidden rounded-md border bg-white z-50">
                            <div class="border-b border-gray-100 px-4 py-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="text-sm font-semibold text-gray-900">{{ __('admin.header.notifications.title') }}</div>
                                    <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-600">{{ __('admin.header.notifications.badge_new') }}</span>
                                </div>
                            </div>
                            <div class="px-4 py-4">
                                <div class="text-sm font-semibold text-gray-900">
                                    {{ __('admin.header.notifications.update_available', ['version' => (string) ($updateState['latest_version'] ?? '')]) }}
                                </div>
                                <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('admin.header.notifications.update_desc') }}</p>
                                @if($updateSummary !== '')
                                    <p class="mt-2 text-sm leading-6 text-gray-600">{{ $updateSummary }}</p>
                                @endif
                                <div class="mt-4 space-y-1 rounded-xl bg-gray-50 px-3 py-3 text-xs text-gray-500">
                                    <div>{{ __('admin.header.notifications.current_version', ['version' => (string) ($updateState['current_version'] ?? config('geoflow.app_version', '2.0'))]) }}</div>
                                    @if(!empty($updateState['latest_version']))
                                        <div>{{ __('admin.header.notifications.latest_version', ['version' => (string) $updateState['latest_version']]) }}</div>
                                    @endif
                                    <div>{{ __('admin.header.notifications.daily_check') }}</div>
                                    @if(!empty($updateState['checked_at']))
                                        <div>{{ __('admin.header.notifications.checked_at', ['time' => (string) $updateState['checked_at']]) }}</div>
                                    @endif
                                </div>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <a href="{{ $notificationChangelogUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700">
                                        {{ __('admin.header.notifications.view_changelog') }}
                                    </a>
                                    <a href="{{ $notificationGithubUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                        {{ __('admin.header.notifications.open_github') }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
                <div class="hidden md:flex items-center rounded-md border border-slate-200 bg-white px-2 py-1 shadow-sm">
                    <i data-lucide="languages" class="w-4 h-4 mr-1.5"></i>
                    <select
                        class="admin-locale-select appearance-none bg-transparent pr-5 text-sm font-medium text-gray-700 outline-none cursor-pointer"
                        aria-label="{{ __('admin.header.language') }}"
                        onchange="if (this.value) window.location.href = this.value"
                    >
                        @foreach (\App\Support\AdminWeb::supportedLocales() as $localeCode => $localeLabel)
                            <option value="{{ route('admin.locale.switch', ['locale' => $localeCode]) }}" @selected(app()->getLocale() === $localeCode)>
                                {{ $localeLabel }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="relative">
                    <button onclick="toggleUserMenu()" class="flex items-center space-x-1 text-sm transition-colors duration-200" type="button">
                        <div class="admin-user-avatar w-8 h-8 rounded-md flex items-center justify-center">
                            <i data-lucide="user" class="w-4 h-4"></i>
                        </div>
                        <i data-lucide="chevron-down" class="w-4 h-4"></i>
                    </button>

                    <div id="user-menu" class="admin-menu-panel hidden absolute right-0 mt-2 w-56 bg-white rounded-md py-1 z-50">
                        <div class="px-4 py-2 border-b border-gray-100">
                            <div class="text-sm text-gray-700">{{ __('admin.header.welcome', ['name' => $currentAdmin->username ?? '']) }}</div>
                            <div class="text-xs text-gray-400">{{ $adminRoleLabel }}</div>
                        </div>
                        @if($currentSite)
                            <div class="border-b border-gray-100 px-4 py-3" data-site-switcher-menu>
                                <label class="mb-1.5 flex items-center gap-2 text-xs font-medium text-gray-500">
                                    <i data-lucide="globe-2" class="h-3.5 w-3.5"></i>
                                    <span>当前站点</span>
                                </label>
                                <form method="POST" action="{{ route('admin.sites.switch') }}">
                                    @csrf
                                    <select
                                        name="site_id"
                                        class="block h-9 w-full rounded-md border border-slate-200 bg-white px-2 text-sm font-medium text-gray-800 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                                        onchange="this.form.submit()"
                                    >
                                        @foreach ($availableSites as $site)
                                            <option value="{{ $site->id }}" @selected((int) $currentSite->id === (int) $site->id)>
                                                {{ $site->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </form>
                            </div>
                        @endif
                        <a href="{{ route('admin.dashboard') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i data-lucide="home" class="w-4 h-4 inline mr-2"></i>
                            {{ __('admin.nav.back_home') }}
                        </a>
                        <a href="{{ route('admin.ai.configurator') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i data-lucide="bot" class="w-4 h-4 inline mr-2"></i>
                            {{ __('admin.nav.ai_config') }}
                        </a>
                        <a href="{{ route('admin.site-settings.index') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i data-lucide="settings" class="w-4 h-4 inline mr-2"></i>
                            {{ __('admin.nav.system_settings') }}
                        </a>
                        @if ($isSuperAdmin)
                            <a href="{{ route('admin.sites.manage.index') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                <i data-lucide="globe-2" class="w-4 h-4 inline mr-2"></i>
                                站点管理
                            </a>
                            <a href="{{ route('admin.admin-users.index') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                <i data-lucide="users" class="w-4 h-4 inline mr-2"></i>
                                {{ __('admin.nav.admin_management') }}
                            </a>
                            <a href="{{ route('admin.admin-activity-logs') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                <i data-lucide="clipboard-list" class="w-4 h-4 inline mr-2"></i>
                                {{ __('admin.nav.activity_logs') }}
                            </a>
                        @endif
                        <a href="{{ route('admin.api-tokens.index') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i data-lucide="key-round" class="w-4 h-4 inline mr-2"></i>
                            {{ __('admin.nav.api_tokens') }}
                        </a>
                        <div class="border-t border-gray-100"></div>
                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button type="submit" class="w-full text-left block px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                                <i data-lucide="log-out" class="w-4 h-4 inline mr-2"></i>
                                {{ __('admin.button.logout') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="mobile-menu" class="hidden md:hidden">
        <div class="px-2 pt-2 pb-3 space-y-1 sm:px-3 border-t border-slate-200 bg-white">
            @foreach ($menu as $key => $item)
                <a href="{{ route($item['route']) }}"
                   class="admin-nav-link @if($resolvedActive === $key) is-active @endif block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                    {{ $item['name'] }}
                </a>
            @endforeach
        </div>
    </div>
</nav>
<div class="md:hidden fixed top-4 right-4 z-50">
    <button onclick="toggleMobileMenu()" class="admin-mobile-menu-button p-2 rounded-md shadow-md" type="button" aria-label="{{ __('admin.nav.dashboard') }}">
        <i data-lucide="menu" class="w-5 h-5 text-white"></i>
    </button>
</div>

<style>
    .admin-locale-select {
        background-image: linear-gradient(45deg, transparent 50%, rgba(201,209,220,0.86) 50%), linear-gradient(135deg, rgba(201,209,220,0.86) 50%, transparent 50%);
        background-position: calc(100% - 8px) 52%, calc(100% - 4px) 52%;
        background-size: 4px 4px, 4px 4px;
        background-repeat: no-repeat;
    }
</style>

<script>
    function toggleUserMenu() {
        const menu = document.getElementById('user-menu');
        if (menu) {
            menu.classList.toggle('hidden');
        }
    }

    @if($hasVersionUpdate)
        function toggleAdminNotifications() {
            const menu = document.getElementById('admin-notification-menu');
            if (menu) {
                menu.classList.toggle('hidden');
            }
        }
    @endif

    function toggleMobileMenu() {
        const menu = document.getElementById('mobile-menu');
        if (menu) {
            menu.classList.toggle('hidden');
        }
    }

    document.addEventListener('click', function (event) {
        const userMenu = document.getElementById('user-menu');
        const mobileMenu = document.getElementById('mobile-menu');
        @if($hasVersionUpdate)
            const notificationMenu = document.getElementById('admin-notification-menu');
        @endif
        if (userMenu && !event.target.closest('[onclick="toggleUserMenu()"]') && !userMenu.contains(event.target)) {
            userMenu.classList.add('hidden');
        }
        @if($hasVersionUpdate)
            if (notificationMenu && !event.target.closest('[onclick="toggleAdminNotifications()"]') && !notificationMenu.contains(event.target)) {
                notificationMenu.classList.add('hidden');
            }
        @endif
        if (mobileMenu && !event.target.closest('[onclick="toggleMobileMenu()"]') && !mobileMenu.contains(event.target)) {
            mobileMenu.classList.add('hidden');
        }
    });
</script>
