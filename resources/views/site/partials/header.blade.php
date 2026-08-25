@php
    $activeNav = request()->routeIs('site.news', 'site.category', 'site.article')
        ? 'news'
        : (request()->routeIs('site.about')
            ? 'about'
            : (request()->routeIs('site.contact') ? 'contact' : 'home'));

    $navItems = [
        ['key' => 'home', 'label' => '首页', 'url' => route('site.home')],
        ['key' => 'news', 'label' => '资讯', 'url' => route('site.news')],
        ['key' => 'about', 'label' => '关于我们', 'url' => route('site.about')],
        ['key' => 'contact', 'label' => '联系我们', 'url' => route('site.contact')],
    ];
@endphp
<header class="site-header bg-white border-b border-gray-100 sticky top-0 z-50">
    <div class="site-container px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between gap-4">
            <a href="{{ route('site.home') }}" class="flex items-center min-w-0">
                @if(!empty($siteLogo))
                    <img src="{{ $siteLogo }}" alt="{{ $siteName }}" class="h-9 w-auto max-w-48 object-contain">
                @else
                    <span class="text-lg sm:text-xl font-bold text-gray-900 truncate">{{ $siteName }}</span>
                @endif
            </a>

            <nav class="hidden md:flex items-center space-x-2">
                @foreach($navItems as $item)
                    <a
                        href="{{ $item['url'] }}"
                        class="flex items-center rounded-lg px-4 py-2 text-sm font-medium transition-colors duration-200 {{ $activeNav === $item['key'] ? 'text-gray-900 bg-gray-50' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}"
                    >
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>

            <button type="button" class="mobile-menu-toggle md:hidden flex h-11 w-11 items-center justify-center rounded-xl text-gray-600 hover:bg-gray-50 hover:text-gray-900" onclick="toggleMobileMenu()" aria-controls="mobileMenu" aria-expanded="false" aria-label="菜单">
                <i data-lucide="menu" class="w-6 h-6"></i>
            </button>
        </div>

        <div id="mobileMenu" class="mobile-panel md:hidden hidden border-t border-gray-100 py-4">
            <nav class="flex flex-col space-y-3">
                @foreach($navItems as $item)
                    <a
                        href="{{ $item['url'] }}"
                        class="mobile-nav-link flex items-center text-sm font-medium {{ $activeNav === $item['key'] ? 'text-gray-900' : 'text-gray-600 hover:text-gray-900' }}"
                    >
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
        </div>
    </div>
</header>

<script>
function toggleMobileMenu() {
    const menu = document.getElementById('mobileMenu');
    if (menu) {
        menu.classList.toggle('hidden');
    }
}
</script>
