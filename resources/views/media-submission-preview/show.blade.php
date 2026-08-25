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
        article :where(h2, h3, h4) { margin: 28px 0 12px; line-height: 1.4; }
        article h2 { font-size: 22px; }
        article h3 { font-size: 19px; }
        article p { margin: 0 0 16px; }
        article ul, article ol { margin: 0 0 16px 1.4em; padding: 0; }
        article li { margin: 4px 0; }
        article blockquote { margin: 20px 0; border-left: 4px solid #e5e7eb; padding: 8px 16px; color: #4b5563; background: #f9fafb; }
        article code { border-radius: 4px; background: #f3f4f6; padding: 2px 4px; font-size: 0.92em; }
        article pre { overflow-x: auto; border-radius: 8px; background: #111827; color: #f9fafb; padding: 16px; line-height: 1.6; }
        article pre code { background: transparent; color: inherit; padding: 0; }
        .article-table-wrap { margin: 20px 0; overflow-x: auto; }
        .article-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .article-table th, .article-table td { border: 1px solid #e5e7eb; padding: 8px 10px; text-align: left; }
        .article-table th { background: #f9fafb; }
        img { max-width: 100%; height: auto; }
        @media (max-width: 640px) { main { margin: 0; width: auto; min-height: 100vh; border: 0; padding: 24px 18px; } h1 { font-size: 22px; } }
    </style>
</head>
<body>
    <main>
        <h1>{{ $submission->title_snapshot }}</h1>
        <article>
            {!! $sanitizedContentHtml !!}
        </article>
    </main>
</body>
</html>
