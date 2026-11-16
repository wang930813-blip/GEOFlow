@php
    $visualEditorData = data_get($themeData ?? null, 'visual_editor', []);
    $visualEditorPayload = [
        'sectionOrder' => data_get($visualEditorData, 'section_order', []),
        'hiddenSections' => data_get($visualEditorData, 'hidden_sections', []),
        'navigationOrder' => data_get($visualEditorData, 'navigation_order', []),
        'hiddenNavigation' => data_get($visualEditorData, 'hidden_navigation', []),
        'navigationLabels' => data_get($visualEditorData, 'navigation_labels', []),
        'fields' => data_get($visualEditorData, 'fields', []),
    ];
@endphp
<script type='application/json' id='website-visual-editor-config'>{!! json_encode($visualEditorPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
<script src='{{ asset('js/site-visual-editor.js') }}?v={{ filemtime(public_path('js/site-visual-editor.js')) }}' defer></script>
