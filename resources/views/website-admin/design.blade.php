@extends('website-admin.layout')

@section('content')
<div class='wa-page-heading website-editor-heading'>
    <div><span class='wa-eyebrow'>官网首页</span><h1>网站装修</h1><p>编辑当前模板的首页区块与文案，保存后立即应用到官网。</p></div>
    <div class='website-editor-heading-actions'>
        <span class='website-editor-theme'><i data-lucide='layout-template' aria-hidden='true'></i><span><small>当前模板</small><strong>{{ $themeName }}</strong></span></span>
        <a class='wa-button wa-button-secondary' href='{{ route('site.home') }}' target='_blank' rel='noopener'><i data-lucide='external-link' aria-hidden='true'></i>打开官网</a>
    </div>
</div>

@if (!$editor['supported'])
    <section class='wa-panel website-editor-unsupported'>
        <span class='website-editor-unsupported-icon'><i data-lucide='panels-top-left' aria-hidden='true'></i></span>
        <div><h2>当前模板暂不支持首页可视化编辑</h2><p>现有文章、产品、单页和网站设置仍可在对应管理页面维护。模板选择继续由主控制台的“网站模板”管理。</p></div>
    </section>
@else
<form method='POST' action='{{ route('website-admin.design.save') }}' class='website-editor-form' data-website-editor>
    @csrf
    @method('PUT')
    <div class='website-editor-toolbar'>
        <div class='website-editor-page-label'><i data-lucide='house' aria-hidden='true'></i><strong>首页</strong><span class='website-editor-save-state' data-editor-dirty aria-live='polite' hidden>有未保存的修改</span></div>
        <div class='website-editor-toolbar-actions'>
            <div class='website-editor-device-switch' role='group' aria-label='预览尺寸'>
                <button type='button' class='is-active' data-editor-device='desktop' aria-pressed='true' title='桌面预览'><i data-lucide='monitor' aria-hidden='true'></i></button>
                <button type='button' data-editor-device='mobile' aria-pressed='false' title='手机预览'><i data-lucide='smartphone' aria-hidden='true'></i></button>
            </div>
            @if ($context->allows('site.design.edit'))
                <button class='wa-button wa-button-primary' type='submit'><i data-lucide='save' aria-hidden='true'></i>保存并发布</button>
            @endif
        </div>
    </div>
    <div class='website-editor-workspace'>
        <aside class='website-editor-panel' aria-label='首页编辑面板'>
            <section class='website-editor-sections'>
                <div class='website-editor-panel-heading'><div><h2>顶部导航</h2><p>调整栏目名称、顺序和显示状态</p></div></div>
                <ol class='website-editor-section-list' data-editor-navigation-list>
                    @foreach ($editor['navigation'] as $navigationItem)
                        <li class='website-editor-section-item @if(!$navigationItem['visible']) is-hidden @endif' data-editor-navigation-item='{{ $navigationItem['id'] }}'>
                            <span class='website-editor-navigation-name'><i data-lucide='grip-vertical' aria-hidden='true'></i><input name='navigation_labels[{{ $navigationItem['id'] }}]' value='{{ old('navigation_labels.'.$navigationItem['id'], $navigationItem['label']) }}' maxlength='40' data-editor-navigation-label='{{ $navigationItem['id'] }}' aria-label='{{ $navigationItem['label'] }}的导航名称' @readonly(!$context->allows('site.design.edit'))></span>
                            <div class='website-editor-section-controls'>
                                <button type='button' data-editor-navigation-move='up' aria-label='上移{{ $navigationItem['label'] }}' title='上移' @disabled(!$context->allows('site.design.edit'))><i data-lucide='chevron-up' aria-hidden='true'></i></button>
                                <button type='button' data-editor-navigation-move='down' aria-label='下移{{ $navigationItem['label'] }}' title='下移' @disabled(!$context->allows('site.design.edit'))><i data-lucide='chevron-down' aria-hidden='true'></i></button>
                                <label class='website-editor-visibility' title='显示或隐藏{{ $navigationItem['label'] }}'>
                                    <input type='checkbox' data-editor-navigation-visibility='{{ $navigationItem['id'] }}' @checked($navigationItem['visible']) aria-label='显示{{ $navigationItem['label'] }}' @disabled(!$context->allows('site.design.edit'))>
                                    <i data-lucide='eye' aria-hidden='true'></i>
                                </label>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>
            <section class='website-editor-sections'>
                <div class='website-editor-panel-heading'><div><h2>首页区块</h2><p>拖动或使用箭头调整顺序</p></div></div>
                <ol class='website-editor-section-list' data-editor-section-list>
                    @foreach ($editor['sections'] as $section)
                        <li class='website-editor-section-item @if(!$section['visible']) is-hidden @endif' draggable='{{ $context->allows('site.design.edit') ? 'true' : 'false' }}' data-editor-section-item='{{ $section['id'] }}'>
                            <button class='website-editor-section-select' type='button' data-editor-select-section='{{ $section['id'] }}' aria-pressed='false'>
                                <i class='website-editor-drag' data-lucide='grip-vertical' aria-hidden='true'></i><span>{{ $section['label'] }}</span>
                            </button>
                            <div class='website-editor-section-controls'>
                                <button type='button' data-editor-move='up' aria-label='上移{{ $section['label'] }}' title='上移' @disabled(!$context->allows('site.design.edit'))><i data-lucide='chevron-up' aria-hidden='true'></i></button>
                                <button type='button' data-editor-move='down' aria-label='下移{{ $section['label'] }}' title='下移' @disabled(!$context->allows('site.design.edit'))><i data-lucide='chevron-down' aria-hidden='true'></i></button>
                                <label class='website-editor-visibility' title='显示或隐藏{{ $section['label'] }}'>
                                    <input type='checkbox' data-editor-section-visibility='{{ $section['id'] }}' @checked($section['visible']) aria-label='显示{{ $section['label'] }}' @disabled(!$context->allows('site.design.edit'))>
                                    <i data-lucide='eye' aria-hidden='true'></i>
                                </label>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>

            <section class='website-editor-fields' aria-live='polite'>
                <div class='website-editor-panel-heading'><div><span class='wa-eyebrow'>区块内容</span><h2 data-editor-current-section>首屏</h2></div></div>
                @foreach ($editor['sections'] as $section)
                    <div data-editor-fields-for='{{ $section['id'] }}' @if (!$loop->first) hidden @endif>
                        @forelse ($section['fields'] as $field)
                            <label class='wa-field website-editor-field'>
                                <span>{{ $field['label'] }}</span>
                                @if ($field['multiline'])
                                    <textarea name='fields[{{ $field['id'] }}]' rows='5' maxlength='{{ $field['max'] }}' data-editor-field='{{ $field['id'] }}' @readonly(!$context->allows('site.design.edit'))>{{ old('fields.'.$field['id'], $editor['fields'][$field['id']] ?? '') }}</textarea>
                                @else
                                    <input name='fields[{{ $field['id'] }}]' value='{{ old('fields.'.$field['id'], $editor['fields'][$field['id']] ?? '') }}' maxlength='{{ $field['max'] }}' data-editor-field='{{ $field['id'] }}' @readonly(!$context->allows('site.design.edit'))>
                                @endif
                            </label>
                        @empty
                            <p class='website-editor-no-fields'>此区块暂无可编辑文案。</p>
                        @endforelse
                    </div>
                @endforeach
            </section>
        </aside>

        <section class='website-editor-preview' aria-label='官网实时预览'>
            <div class='website-editor-preview-bar'><span><i></i>{{ $context->site->domain ?: '当前官网' }}</span><span>预览</span></div>
            <div class='website-editor-frame-wrap' data-editor-frame-wrap>
                <iframe src='{{ $previewUrl }}' title='当前官网首页预览' data-editor-preview-frame></iframe>
            </div>
        </section>
    </div>
    <script type='application/json' data-editor-state>{!! json_encode($editor, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
</form>
@endif
@endsection

@push('scripts')
<script src='/js/website-visual-editor.js?v={{ filemtime(public_path('js/website-visual-editor.js')) }}' defer></script>
@endpush
