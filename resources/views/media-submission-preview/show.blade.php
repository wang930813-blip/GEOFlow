<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $submission->title_snapshot }}</title>
    <style>
        body { margin: 0; background: #f8fafc; color: #111827; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        main { width: min(860px, calc(100% - 32px)); margin: 40px auto; background: #fff; border: 1px solid #e5e7eb; padding: 32px; }
        h1 { margin: 0 0 24px; font-size: 28px; line-height: 1.35; }
        article { font-size: 16px; line-height: 1.8; }
        img { max-width: 100%; height: auto; }
        @media (max-width: 640px) { main { margin: 0; width: auto; min-height: 100vh; border: 0; padding: 24px 18px; } h1 { font-size: 22px; } }
    </style>
</head>
<body>
    <main>
        <h1>{{ $submission->title_snapshot }}</h1>
        <article>
            {!! $submission->content_snapshot !!}
        </article>
    </main>
</body>
</html>
