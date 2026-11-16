<!doctype html>
<html lang='zh-CN'>
<head>
    <meta charset='utf-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1'>
    <meta name='robots' content='noindex,nofollow'>
    <title>{{ $pageTitle }} - 网站管理</title>
    <link rel='stylesheet' href='/assets/css/website-admin.css?v={{ filemtime(public_path('assets/css/website-admin.css')) }}'>
    <link rel='stylesheet' href='/assets/css/website-admin-extra.css?v={{ filemtime(public_path('assets/css/website-admin-extra.css')) }}'>
    <link rel='stylesheet' href='/assets/css/website-admin-reference.css?v={{ filemtime(public_path('assets/css/website-admin-reference.css')) }}'>
    @if (request()->routeIs('website-admin.design'))<link rel='stylesheet' href='/assets/css/website-editor.css?v={{ filemtime(public_path('assets/css/website-editor.css')) }}'>@endif
</head>
<body class='wa-app'>
    <aside class='wa-sidebar' id='wa-sidebar'>
        <a class='wa-sidebar-brand' href='{{ route('website-admin.dashboard') }}'><span class='wa-brand-mark' aria-hidden='true'><i data-lucide='panels-top-left'></i></span><span><strong>企业网站</strong><small>管理后台</small></span></a>
        <div class='wa-site-switcher'><span class='wa-site-avatar'>{{ mb_substr($context->site->name, 0, 1) }}</span><span><strong>{{ $context->site->name }}</strong><small>{{ $context->site->domain ?: '尚未分配系统域名' }}</small></span></div>
        <nav class='wa-nav' aria-label='网站管理导航'>
            @if ($context->allows('dashboard.read'))<a class='{{ request()->routeIs('website-admin.dashboard') ? 'active' : '' }}' href='{{ route('website-admin.dashboard') }}' @if(request()->routeIs('website-admin.dashboard')) aria-current='page' @endif><i data-lucide='layout-dashboard' aria-hidden='true'></i>概览</a>@endif
            <span class='wa-nav-caption'>内容</span>
            @if ($context->allows('categories.read'))<a class='{{ request()->routeIs('website-admin.categories*') ? 'active' : '' }}' href='{{ route('website-admin.categories') }}' @if(request()->routeIs('website-admin.categories*')) aria-current='page' @endif><i data-lucide='folder-tree' aria-hidden='true'></i>分类管理</a>@endif
            @if ($context->allows('articles.read'))<a class='{{ request()->routeIs('website-admin.articles*') ? 'active' : '' }}' href='{{ route('website-admin.articles') }}' @if(request()->routeIs('website-admin.articles*')) aria-current='page' @endif><i data-lucide='newspaper' aria-hidden='true'></i>文章管理</a>@endif
            @if ($context->allows('products.read'))<a class='{{ request()->routeIs('website-admin.products*') ? 'active' : '' }}' href='{{ route('website-admin.products') }}' @if(request()->routeIs('website-admin.products*')) aria-current='page' @endif><i data-lucide='package' aria-hidden='true'></i>产品管理</a>@endif
            @if ($context->allows('pages.read'))<a class='{{ request()->routeIs('website-admin.pages*') ? 'active' : '' }}' href='{{ route('website-admin.pages') }}' @if(request()->routeIs('website-admin.pages*')) aria-current='page' @endif><i data-lucide='files' aria-hidden='true'></i>单页管理</a>@endif
            <span class='wa-nav-caption'>系统管理</span>
            @if ($context->allows('site.design.read'))<a class='{{ request()->routeIs('website-admin.design*') ? 'active' : '' }}' href='{{ route('website-admin.design') }}' @if(request()->routeIs('website-admin.design*')) aria-current='page' @endif><i data-lucide='panels-top-left' aria-hidden='true'></i>网站装修</a>@endif
            @if ($context->allows('site.settings.read'))<a class='{{ request()->routeIs('website-admin.settings*') ? 'active' : '' }}' href='{{ route('website-admin.settings') }}' @if(request()->routeIs('website-admin.settings*')) aria-current='page' @endif><i data-lucide='settings-2' aria-hidden='true'></i>网站设置</a>@endif
            @if ($context->allows('plugins.read') || $context->allows('plugins.configure'))<a class='{{ request()->routeIs('website-admin.plugins*') ? 'active' : '' }}' href='{{ route('website-admin.plugins') }}' @if(request()->routeIs('website-admin.plugins*')) aria-current='page' @endif><i data-lucide='plug-zap' aria-hidden='true'></i>插件列表</a>@endif
            @if ($context->allows('roles.read'))<a class='{{ request()->routeIs('website-admin.team', 'website-admin.team.roles') ? 'active' : '' }}' href='{{ route('website-admin.team.roles') }}' @if(request()->routeIs('website-admin.team', 'website-admin.team.roles')) aria-current='page' @endif><i data-lucide='shield-check' aria-hidden='true'></i>权限角色</a>@endif
            @if ($context->allows('users.read'))<a class='{{ request()->routeIs('website-admin.team.users') ? 'active' : '' }}' href='{{ route('website-admin.team.users') }}' @if(request()->routeIs('website-admin.team.users')) aria-current='page' @endif><i data-lucide='users-round' aria-hidden='true'></i>管理员</a>@endif
        </nav>
        <div class='wa-sidebar-bottom'><span class='wa-account-label'>当前账号</span><strong>{{ $context->displayName }}</strong><small>{{ $context->isOwner ? '站点主账号' : ($context->user?->role?->name ?? '网站管理员') }}</small></div>
    </aside>
    <div class='wa-main'>
        <header class='wa-topbar'>
            <button class='wa-menu-toggle' type='button' aria-label='打开导航' aria-expanded='false' aria-controls='wa-sidebar' data-wa-menu-toggle><i data-lucide='menu' aria-hidden='true'></i></button>
            <div class='wa-breadcrumb'><span>网站管理</span><b>/</b><strong>{{ $pageTitle }}</strong></div>
            <div class='wa-top-actions'>
                @if ($context->site->domain)<a class='wa-button wa-button-secondary' href='{{ route('site.home') }}' target='_blank' rel='noopener' title='打开官网'><i data-lucide='external-link' aria-hidden='true'></i>打开官网</a>@endif
                <form method='POST' action='{{ route('website-admin.logout') }}'>@csrf<button class='wa-button wa-button-quiet' type='submit' title='退出登录'><i data-lucide='log-out' aria-hidden='true'></i>退出</button></form>
            </div>
        </header>
        <main class='wa-content'>
            @if (session('status'))<div class='wa-alert wa-alert-success' role='status'>{{ session('status') }}</div>@endif
            @if ($errors->any())<div class='wa-alert wa-alert-danger' role='alert'>{{ $errors->first() }}</div>@endif
            @yield('content')
        </main>
    </div>
    <div class='wa-mobile-overlay' data-wa-menu-close></div>
    <script src='/js/lucide.min.js'></script>
    <script>
        const menuToggle = document.querySelector('[data-wa-menu-toggle]');
        const closeMenu = () => {
            document.body.classList.remove('wa-nav-open');
            menuToggle?.setAttribute('aria-expanded', 'false');
        };
        menuToggle?.addEventListener('click', () => {
            const isOpen = document.body.classList.toggle('wa-nav-open');
            menuToggle.setAttribute('aria-expanded', String(isOpen));
        });
        document.querySelector('[data-wa-menu-close]')?.addEventListener('click', closeMenu);
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') closeMenu();
        });
        document.querySelectorAll('[data-wa-confirm]').forEach(form => {
            form.addEventListener('submit', event => {
                if (!window.confirm(form.dataset.waConfirm || '确定继续此操作吗？')) event.preventDefault();
            });
        });
        if (window.lucide) window.lucide.createIcons();
    </script>
    @stack('scripts')
</body>
</html>
