@php
    $embedded = (bool) ($embedded ?? false);
    $leadFormTitle = trim((string) ($title ?? ($leadForm->name ?? 'Contact us')));
    $leadFormDescription = trim((string) ($description ?? ($leadForm->description ?? '')));
@endphp

<div class="geo-lead-form rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
    @if($leadFormTitle !== '' || $leadFormDescription !== '')
        <div class="mb-5">
            @if($leadFormTitle !== '')
                <h2 class="{{ $embedded ? 'text-xl' : 'text-2xl' }} font-semibold text-gray-900">{{ $leadFormTitle }}</h2>
            @endif
            @if($leadFormDescription !== '')
                <p class="mt-2 text-sm leading-6 text-gray-600">{{ $leadFormDescription }}</p>
            @endif
        </div>
    @endif

    <form method="GET" action="{{ route('site.contact') }}" class="space-y-4">
        <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">
                {{ app()->getLocale() === 'zh_CN' ? '您的姓名' : 'Name' }}
            </label>
            <input type="text" disabled class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm">
        </div>
        <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">
                {{ app()->getLocale() === 'zh_CN' ? '联系方式' : 'Contact' }}
            </label>
            <input type="text" disabled class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm">
        </div>
        <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-gray-800">
            {{ app()->getLocale() === 'zh_CN' ? '前往联系页' : 'Open contact page' }}
        </button>
    </form>
</div>
