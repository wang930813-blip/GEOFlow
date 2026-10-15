@extends('theme.qcx-quantum-ai-005.layout')

@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars()))

@section('content')
<main class="page-shell">
    <header class="page-heading">
        <p class="eyebrow">{{ $themeData['about']['title'] }}</p>
        <h1>{{ $themeData['site']['brand_name'] }}</h1>
        <p class="lead">{{ $themeData['about']['summary'] }}</p>
    </header>
    <section class="section feature-band reveal">
        <div class="prose">{!! $themeData['about']['content'] !!}</div>
    </section>
</main>
@endsection
