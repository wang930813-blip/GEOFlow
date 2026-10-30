<div class="px-4 sm:px-0">
    <div class="mb-8">
        <a href="{{ route('admin.site-settings.index') }}" class="inline-flex min-h-10 items-center text-sm font-semibold text-gray-600 hover:text-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            <i data-lucide="arrow-left" class="mr-2 h-4 w-4" aria-hidden="true"></i>
            {{ __('admin.site_settings.homepage.back_to_settings') }}
        </a>
        <div class="mt-4">
            <div class="inline-flex items-center rounded-full bg-cyan-50 px-3 py-1 text-xs font-semibold text-cyan-800 ring-1 ring-cyan-100"><i data-lucide="panels-top-left" class="mr-1.5 h-3.5 w-3.5" aria-hidden="true"></i>{{ __('admin.site_settings.homepage.badge') }}</div>
            <h1 id="homepage-editor-title" class="mt-3 text-2xl font-bold text-gray-900">{{ __('admin.site_settings.homepage.page_title') }}</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600">{{ __('admin.site_settings.homepage.page_subtitle') }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.site-settings.homepage-modules') }}" id="homepage-module-form" class="mb-8 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        @csrf
        <input type="hidden" name="appearance_revision" value="{{ $appearanceRevision }}">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div><h2 class="text-lg font-semibold text-gray-900">{{ __('admin.site_settings.homepage.section_title') }}</h2><p class="mt-1 max-w-3xl text-sm leading-6 text-gray-600">{{ __('admin.site_settings.homepage.section_desc') }}</p></div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <button type="button" id="add-homepage-module" class="inline-flex min-h-10 items-center rounded-md border border-blue-200 bg-white px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50"><i data-lucide="plus" class="mr-2 h-4 w-4"></i>{{ __('admin.site_settings.homepage.add_module') }}</button>
                <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"><i data-lucide="save" class="mr-2 h-4 w-4"></i>{{ __('admin.site_settings.homepage.save') }}</button>
            </div>
        </div>
        <div class="mt-5 rounded-lg border border-gray-200 bg-gray-50 p-4">
            <h3 class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.homepage.style_title') }}</h3>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach (['accent_color', 'background_color', 'surface_color', 'text_color', 'muted_color'] as $styleField)
                    <div><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.style_'.$styleField) }}</label><input type="text" name="homepage_style[{{ $styleField }}]" value="{{ $homepageStyle[$styleField] ?? '' }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="#2563eb"></div>
                @endforeach
                <div><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.container_width') }}</label><select name="homepage_style[container_width]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">@foreach ($homepageContainerWidths as $option)<option value="{{ $option }}" @selected(($homepageStyle['container_width'] ?? 'default') === $option)>{{ __('admin.site_settings.homepage.container_width_'.$option) }}</option>@endforeach</select></div>
                <div><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.section_spacing') }}</label><select name="homepage_style[section_spacing]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">@foreach ($homepageSpacings as $option)<option value="{{ $option }}" @selected(($homepageStyle['section_spacing'] ?? 'normal') === $option)>{{ __('admin.site_settings.homepage.spacing_'.$option) }}</option>@endforeach</select></div>
                <div><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.radius') }}</label><select name="homepage_style[radius]" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">@foreach ($homepageRadii as $option)<option value="{{ $option }}" @selected(($homepageStyle['radius'] ?? 'soft') === $option)>{{ __('admin.site_settings.homepage.radius_'.$option) }}</option>@endforeach</select></div>
            </div>
        </div>
        <div id="homepage-module-list" class="mt-5 space-y-4">
            @foreach ($homepageModules as $index => $module)
                @include('admin.site-settings.partials.homepage-module-item', ['index' => $index, 'module' => $module, 'homepageModuleTypes' => $homepageModuleTypes, 'homepageModuleLayouts' => $homepageModuleLayouts, 'homepageArticleSources' => $homepageArticleSources, 'homepageAlignments' => $homepageAlignments, 'leadForms' => $leadForms])
            @endforeach
        </div>
        <div id="homepage-module-empty" class="{{ !empty($homepageModules) ? 'hidden ' : '' }}mt-5 rounded-lg border border-dashed border-gray-300 bg-gray-50 px-6 py-8 text-center"><div class="text-base font-medium text-gray-900">{{ __('admin.site_settings.homepage.empty_title') }}</div><div class="mt-2 text-sm text-gray-500">{{ __('admin.site_settings.homepage.empty_desc') }}</div></div>
    </form>
</div>

@push('scripts')
    <template id="homepage-module-template">@include('admin.site-settings.partials.homepage-module-item', ['index' => '__INDEX__', 'module' => [], 'homepageModuleTypes' => $homepageModuleTypes, 'homepageModuleLayouts' => $homepageModuleLayouts, 'homepageArticleSources' => $homepageArticleSources, 'homepageAlignments' => $homepageAlignments, 'leadForms' => $leadForms, 'templateMode' => true])</template>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const list = document.getElementById('homepage-module-list'), emptyState = document.getElementById('homepage-module-empty'), addButton = document.getElementById('add-homepage-module'), template = document.getElementById('homepage-module-template');
            if (!list || !emptyState || !addButton || !template) return;
            let index = list.querySelectorAll('.homepage-module-item').length;
            const refresh = () => { const count = list.querySelectorAll('.homepage-module-item').length; emptyState.classList.toggle('hidden', count > 0); addButton.disabled = count >= 30; };
            const bindRemove = (item) => item.querySelector('.remove-homepage-module')?.addEventListener('click', () => { item.remove(); refresh(); });
            list.querySelectorAll('.homepage-module-item').forEach(bindRemove);
            addButton.addEventListener('click', () => { if (list.querySelectorAll('.homepage-module-item').length >= 30) return; const wrapper = document.createElement('div'); wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(index)).replaceAll('__NUMBER__', String(index + 1)).replaceAll('__SORT__', String((index + 1) * 10)).trim(); const item = wrapper.firstElementChild; if (!item) return; list.appendChild(item); bindRemove(item); index += 1; refresh(); window.GeoFlowAdminUi?.refreshIcons ? window.GeoFlowAdminUi.refreshIcons(item) : window.lucide?.createIcons?.(); });
            refresh();
        });
    </script>
@endpush
