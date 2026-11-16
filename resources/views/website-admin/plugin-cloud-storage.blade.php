@extends('website-admin.layout')

@section('content')
<div class='wa-page-heading'>
    <div>
        <span class='wa-eyebrow'>插件列表</span>
        <h1>云存储配置</h1>
        <p>为网站上传的图片选择一个云存储平台。每个网站同时只能启用一个平台。</p>
    </div>
</div>

<div class='wa-alert wa-alert-info' role='note'>更换平台后仅新上传的图片使用当前平台；已有图片地址和文件不会迁移。停用云存储后，新上传的图片使用本地存储。</div>

<section class='wa-panel wa-plugin-config'>
    <div class='wa-plugin-config-header'>
        <div>
            <h2>{{ $manifest['name'] }}</h2>
            <p>{{ $manifest['description'] }}</p>
        </div>
        <div class='wa-plugin-config-id'><span>插件标识</span><code>{{ $manifest['id'] }}</code></div>
    </div>

    <form method='GET' action='{{ route('website-admin.plugins.cloud-storage') }}' class='wa-plugin-provider-picker'>
        <label class='wa-field' for='wa-cloud-storage-provider'>
            <span>云存储平台</span>
            <select id='wa-cloud-storage-provider' name='provider' onchange='this.form.submit()'>
                @foreach ($providers as $id => $candidate)
                    <option value='{{ $id }}' @selected($providerId === $id)>{{ $candidate['name'] }}</option>
                @endforeach
            </select>
        </label>
        <p>{{ $provider['description'] }}</p>
    </form>

    @if ($context->allows('plugins.configure'))
        <form method='POST' action='{{ route('website-admin.plugins.update') }}' class='wa-plugin-form'>
            @csrf
            @method('PUT')
            <input type='hidden' name='provider' value='{{ $providerId }}'>
            <div class='wa-plugin-fields'>
                @foreach ($provider['fields'] as $fieldKey => $field)
                    @php
                        $savedSecret = $field['type'] === 'password' && ($savedSecrets[$fieldKey] ?? false);
                        $value = old('settings.'.$fieldKey, $settings[$fieldKey] ?? '');
                    @endphp
                    <label class='wa-field'>
                        <span>{{ $field['label'] }} @if ($field['required'] && ! $savedSecret)<b>*</b>@endif</span>
                        @if ($field['type'] === 'select')
                            <select name='settings[{{ $fieldKey }}]' {{ $field['required'] ? 'required' : '' }}>
                                <option value=''>请选择</option>
                                @foreach ($field['options'] as $optionValue => $optionLabel)
                                    <option value='{{ $optionValue }}' @selected($value === $optionValue)>{{ $optionLabel }}</option>
                                @endforeach
                            </select>
                        @else
                            <input
                                type='{{ $field['type'] }}'
                                name='settings[{{ $fieldKey }}]'
                                value='{{ $field['type'] === 'password' ? '' : $value }}'
                                maxlength='1024'
                                autocomplete='{{ $field['type'] === 'password' ? 'new-password' : 'off' }}'
                                @if ($field['type'] === 'password' && $savedSecret) placeholder='已保存，留空表示保持不变' @endif
                                {{ $field['required'] && ! $savedSecret ? 'required' : '' }}
                            >
                        @endif
                        @if (! empty($field['hint']))<small>{{ $field['hint'] }}</small>@endif
                    </label>
                @endforeach
            </div>
            <div class='wa-plugin-footer'>
                <label class='wa-plugin-enable'>
                    <input type='checkbox' name='enabled' value='1' @checked(old('enabled', $enabled))>
                    <span>启用云存储</span>
                </label>
                <div class='wa-plugin-form-actions'>
                    <a class='wa-button wa-button-secondary' href='{{ route('website-admin.plugins') }}'>返回插件列表</a>
                    <button class='wa-button wa-button-primary' type='submit'>保存配置</button>
                </div>
            </div>
        </form>
    @else
        <dl class='wa-plugin-readonly'>
            @foreach ($provider['fields'] as $fieldKey => $field)
                @if ($field['type'] === 'password')
                    <div><dt>{{ $field['label'] }}</dt><dd>{{ ($savedSecrets[$fieldKey] ?? false) ? '已配置' : '未配置' }}</dd></div>
                @elseif (isset($settings[$fieldKey]))
                    <div><dt>{{ $field['label'] }}</dt><dd>{{ $settings[$fieldKey] }}</dd></div>
                @endif
            @endforeach
            <div><dt>启用状态</dt><dd>{{ $enabled ? '已启用' : '未启用' }}</dd></div>
        </dl>
    @endif
</section>
@endsection
