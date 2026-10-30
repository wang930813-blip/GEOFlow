@php
    $templateMode = $templateMode ?? false;
    $value = static fn (string $key, mixed $default = ''): mixed => $templateMode ? $default : ($module[$key] ?? $default);
    $field = static fn (string $key): string => 'homepage_modules['.$index.']['.$key.']';
@endphp
<div class="homepage-module-item rounded-lg border border-gray-200 bg-white p-4" data-homepage-module-index="{{ $index }}">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="text-sm font-semibold text-gray-900">{{ __('admin.site_settings.homepage.module_title', ['index' => is_numeric($index) ? ((int) $index + 1) : '__NUMBER__']) }}</div>
            <div class="mt-1 text-xs text-gray-500">{{ __('admin.site_settings.homepage.module_desc') }}</div>
        </div>
        <button type="button" class="remove-homepage-module inline-flex min-h-10 items-center rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
            <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
            {{ __('admin.button.delete') }}
        </button>
    </div>
    <input type="hidden" name="{{ $field('id') }}" value="{{ $value('id') }}">
    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-4">
        <div>
            <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_type') }}</label>
            <select name="{{ $field('type') }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                @foreach ($homepageModuleTypes as $type)<option value="{{ $type }}" @selected($value('type', 'rich_text') === $type)>{{ __('admin.site_settings.homepage.type_'.$type) }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_layout') }}</label>
            <select name="{{ $field('layout') }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                @foreach ($homepageModuleLayouts as $layout)<option value="{{ $layout }}" @selected($value('layout', 'single') === $layout)>{{ __('admin.site_settings.homepage.layout_'.$layout) }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_source') }}</label>
            <select name="{{ $field('data_source') }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                @foreach ($homepageArticleSources as $source)<option value="{{ $source }}" @selected($value('data_source', 'latest') === $source)>{{ __('admin.site_settings.homepage.source_'.$source) }}</option>@endforeach
            </select>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_limit') }}</label><input type="number" min="1" max="12" name="{{ $field('limit') }}" value="{{ $value('limit', 4) }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></div>
            <div><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_sort') }}</label><input type="number" min="0" max="10000" name="{{ $field('sort_order') }}" value="{{ $value('sort_order', is_numeric($index) ? (((int) $index + 1) * 10) : '__SORT__') }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></div>
        </div>
    </div>
    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_title') }}</label><input type="text" name="{{ $field('title') }}" value="{{ $value('title') }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></div>
        <div><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_subtitle') }}</label><input type="text" name="{{ $field('subtitle') }}" value="{{ $value('subtitle') }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></div>
    </div>
    <div class="mt-4"><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_body') }}</label><textarea name="{{ $field('body') }}" rows="3" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">{{ $value('body') }}</textarea></div>
    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_image_url') }}</label><input type="text" name="{{ $field('image_url') }}" value="{{ $value('image_url') }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></div>
        <div><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_link_text') }}</label><input type="text" name="{{ $field('link_text') }}" value="{{ $value('link_text') }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></div>
        <div><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_link_url') }}</label><input type="text" name="{{ $field('link_url') }}" value="{{ $value('link_url') }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></div>
    </div>
    <div class="mt-4"><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_lead_form') }}</label><select name="{{ $field('lead_form_slug') }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"><option value="">{{ __('admin.site_settings.homepage.lead_form_none') }}</option>@foreach ($leadForms as $leadForm)<option value="{{ $leadForm->slug }}" @selected($value('lead_form_slug') === $leadForm->slug)>{{ $leadForm->name }}</option>@endforeach</select></div>
    <div class="mt-4"><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_custom_html') }}</label><textarea name="{{ $field('custom_html') }}" rows="3" class="w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-xs">{{ $value('custom_html') }}</textarea></div>
    <details class="mt-4 rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
        <summary class="cursor-pointer text-sm font-semibold text-gray-700">{{ __('admin.site_settings.homepage.module_style_title') }}</summary>
        <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-5">
            <div><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_alignment') }}</label><select name="{{ $field('alignment') }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">@foreach ($homepageAlignments as $alignment)<option value="{{ $alignment }}" @selected($value('alignment', 'left') === $alignment)>{{ __('admin.site_settings.homepage.alignment_'.$alignment) }}</option>@endforeach</select></div>
            @foreach (['accent_color', 'surface_color', 'text_color', 'muted_color'] as $color)
                <div><label class="mb-2 block text-xs font-medium text-gray-600">{{ __('admin.site_settings.homepage.field_'.$color) }}</label><input type="text" name="{{ $field($color) }}" value="{{ $value($color) }}" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"></div>
            @endforeach
        </div>
    </details>
    <label class="mt-4 flex items-center gap-2 text-sm font-medium text-gray-700"><input type="checkbox" name="{{ $field('enabled') }}" value="1" @checked($templateMode || !empty($module['enabled'])) class="rounded border-gray-300 text-blue-600">{{ __('admin.site_settings.homepage.field_enabled') }}</label>
</div>
